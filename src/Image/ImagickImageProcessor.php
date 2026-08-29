<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Image;

use SohoPHP\SoFinder\Contract\ImageProcessorInterface;
use SohoPHP\SoFinder\Contract\ImageEffectsProcessorInterface;
use SohoPHP\SoFinder\Exception\SoFinderException;
use SohoPHP\SoFinder\Value\ImageProcessingLimits;

/** Optional processor that only permits raster coders declared by ImageFormatRegistry. */
final readonly class ImagickImageProcessor implements ImageProcessorInterface, ImageEffectsProcessorInterface
{
    public function __construct(
        private ImageFormatRegistry $formats = new ImageFormatRegistry(),
        private ImageProcessingLimits $limits = new ImageProcessingLimits(),
        private ?string $watermarkFont = null,
        private ?WatermarkFontResolver $watermarkFontResolver = null,
    ) {
    }

    public function supports(string $mimeType): bool
    {
        $format = $this->formats->formatForMime($mimeType);
        $coder = $format === null ? null : $this->formats->coder($format);

        return $coder !== null && extension_loaded('imagick') && \Imagick::queryFormats($coder) !== [];
    }

    /** Verifies the encoder and delegate with a bounded in-memory round trip. */
    public function canEncode(string $mimeType): bool
    {
        $format = $this->formats->formatForMime($mimeType);
        $coder = $format === null ? null : $this->formats->coder($format);
        if ($coder === null || !$this->supports($mimeType)) {
            return false;
        }
        $version = \Imagick::getVersion();
        $key = $coder . '|' . (is_array($version) ? json_encode($version) : (string) $version);
        /** @var array<string, bool> $cache */
        static $cache = [];
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        return $cache[$key] = $this->withResourceLimits(function () use ($coder): bool {
            $temporary = tempnam(sys_get_temp_dir(), 'sofinder-codec-probe-');
            if ($temporary === false) {
                return false;
            }
            $image = new \Imagick();
            try {
                $image->newImage(128, 64, new \ImagickPixel('rgba(30,100,180,0.5)'), $coder);
                $image->setImageFormat($coder);
                if (!$image->writeImage($temporary)) {
                    return false;
                }
                clearstatcache(true, $temporary);
                if (!is_file($temporary) || filesize($temporary) === 0) {
                    return false;
                }
                $decoded = new \Imagick();
                try {
                    $decoded->readImage($this->sourceSpecifier($coder, $temporary, true));
                    $decoded->getImageSignature();

                    return $decoded->getImageWidth() === 128 && $decoded->getImageHeight() === 64;
                } finally {
                    $this->release($decoded);
                }
            } catch (\ImagickException) {
                return false;
            } finally {
                $this->release($image);
                @unlink($temporary);
            }
        });
    }

    public function dimensions(string $source): array
    {
        return $this->withResourceLimits(function () use ($source): array {
            $probe = $this->probe($source);
            $image = $this->read($source, $probe['coder'], true);
            try {
                $this->orient($image);

                return ['width' => $image->getImageWidth(), 'height' => $image->getImageHeight()];
            } finally {
                $this->release($image);
            }
        });
    }

    public function validate(string $source): array
    {
        return $this->withResourceLimits(function () use ($source): array {
            $probe = $this->probe($source);
            $image = $this->read($source, $probe['coder']);
            try {
                $width = 0;
                $height = 0;
                foreach ($image as $frame) {
                    $this->orient($frame);
                    $this->assertDimensions($frame->getImageWidth(), $frame->getImageHeight());
                    $frame->getImageSignature();
                    $width = max($width, $frame->getImageWidth());
                    $height = max($height, $frame->getImageHeight());
                }

                return ['width' => $width, 'height' => $height];
            } catch (\ImagickException $exception) {
                throw new SoFinderException('Unable to decode the image.', 'invalid_image', 415, $exception);
            } finally {
                $this->release($image);
            }
        });
    }

    public function isAnimated(string $source): bool
    {
        return $this->withResourceLimits(fn (): bool => $this->probe($source)['frames'] > 1);
    }

    public function thumbnail(string $source, string $destination, int $width, int $height): void
    {
        if ($width < 1 || $height < 1 || $width > 4096 || $height > 4096) {
            throw new SoFinderException('Image dimensions must be between 1 and 4096 pixels.', 'invalid_image_dimensions', 422);
        }
        $this->withResourceLimits(function () use ($source, $destination, $width, $height): void {
            $probe = $this->probe($source);
            $image = $this->read($source, $probe['coder'], true);
            try {
                $this->orient($image);
                $image->thumbnailImage($width, $height, true, false);
                $image->setImagePage(0, 0, 0, 0);
                $image->setImageFormat('PNG');
                $image->stripImage();
                $this->write($image, $destination);
            } finally {
                $this->release($image);
            }
        });
    }

    public function transform(string $source, string $destination, int $rotation, int $width, int $height, int $quality = 88): void
    {
        if (!in_array($rotation, [0, 90, 180, 270], true)) {
            throw new SoFinderException('Image rotation must be 0, 90, 180 or 270 degrees.', 'invalid_image_operation', 422);
        }
        $this->assertQuality($quality);
        $this->withResourceLimits(function () use ($source, $destination, $rotation, $width, $height, $quality): void {
            $probe = $this->probe($source);
            $this->assertEditableFrames($probe['frames']);
            $image = $this->read($source, $probe['coder'], true);
            try {
                $format = $image->getImageFormat();
                $this->orient($image);
                if ($rotation !== 0) {
                    $image->rotateImage(new \ImagickPixel('transparent'), $rotation);
                }
                $this->resize($image, $width, $height);
                $image->setImageFormat($format);
                $image->setImageCompressionQuality($quality);
                $image->stripImage();
                $this->write($image, $destination);
            } finally {
                $this->release($image);
            }
        });
    }

    public function crop(string $source, string $destination, int $x, int $y, int $width, int $height, int $quality = 88): void
    {
        $this->assertQuality($quality);
        $this->withResourceLimits(function () use ($source, $destination, $x, $y, $width, $height, $quality): void {
            $probe = $this->probe($source);
            $this->assertEditableFrames($probe['frames']);
            $image = $this->read($source, $probe['coder'], true);
            try {
                $format = $image->getImageFormat();
                $this->orient($image);
                if ($x < 0 || $y < 0 || $width < 1 || $height < 1 || $width > 4096 || $height > 4096 || $x + $width > $image->getImageWidth() || $y + $height > $image->getImageHeight()) {
                    throw new SoFinderException('The crop rectangle is outside the image.', 'invalid_crop', 422);
                }
                $image->cropImage($width, $height, $x, $y);
                $image->setImagePage(0, 0, 0, 0);
                $image->setImageFormat($format);
                $image->setImageCompressionQuality($quality);
                $image->stripImage();
                $this->write($image, $destination);
            } finally {
                $this->release($image);
            }
        });
    }

    public function optimize(string $source, string $destination, string $mimeType, int $quality): void
    {
        $this->assertQuality($quality);
        $format = $this->formats->formatForMime($mimeType);
        $coder = $format === null ? null : $this->formats->coder($format);
        if ($coder === null || !$this->canEncode($mimeType)) {
            throw new SoFinderException('The requested output image format is unavailable.', 'unsupported_image_output_format', 415);
        }
        $this->withResourceLimits(function () use ($source, $destination, $coder, $quality): void {
            $probe = $this->probe($source);
            $this->assertEditableFrames($probe['frames']);
            $image = $this->read($source, $probe['coder'], true);
            try {
                $this->orient($image);
                $image->setImageFormat($coder);
                $image->setImageCompressionQuality($quality);
                $image->stripImage();
                $this->write($image, $destination);
            } finally {
                $this->release($image);
            }
        });
    }

    public function textWatermark(string $source, string $destination, string $text, string $position, int $opacity, int $scale, string $color, int $quality, ?int $x = null, ?int $y = null, string $font = 'interface'): void
    {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > 200) {
            throw new SoFinderException('Watermark text must contain between 1 and 200 characters.', 'invalid_watermark_text', 422);
        }
        if (preg_match('/^#[0-9a-fA-F]{6}$/D', $color) !== 1) {
            throw new SoFinderException('Watermark color must be a six-digit hexadecimal color.', 'invalid_watermark_color', 422);
        }
        $this->assertWatermarkSettings($position, $opacity, $scale, $x, $y);
        $this->assertQuality($quality);
        $this->withResourceLimits(function () use ($source, $destination, $text, $position, $opacity, $scale, $color, $quality, $x, $y, $font): void {
            $probe = $this->probe($source);
            $this->assertEditableFrames($probe['frames']);
            $image = $this->read($source, $probe['coder'], true);
            $draw = new \ImagickDraw();
            try {
                $format = $image->getImageFormat();
                $this->orient($image);
                $draw->setFillColor(new \ImagickPixel($color));
                $draw->setFillOpacity($opacity / 100);
                $watermarkFont = $this->watermarkFontResolver?->resolve($font) ?? $this->watermarkFont;
                if ($watermarkFont !== null && is_readable($watermarkFont)) {
                    $draw->setFont($watermarkFont);
                } elseif (preg_match('/[^\x20-\x7E]/', $text) === 1) {
                    throw new SoFinderException('A configured TrueType font is required for Unicode watermark text.', 'watermark_font_unavailable', 503);
                }
                $draw->setFontSize(max(10, (int) round(min($image->getImageWidth(), $image->getImageHeight()) * $scale / 500)));
                $metrics = $image->queryFontMetrics($draw, $text);
                $markWidth = max(1, (int) ceil((float) ($metrics['textWidth'] ?? 1)));
                $markHeight = max(1, (int) ceil((float) ($metrics['textHeight'] ?? 1)));
                [$left, $top] = $this->watermarkCoordinates($image->getImageWidth(), $image->getImageHeight(), $markWidth, $markHeight, $position, $x, $y);
                $image->annotateImage($draw, $left, $top + $markHeight, 0, $text);
                $image->setImageFormat($format);
                $image->setImageCompressionQuality($quality);
                $image->stripImage();
                $this->write($image, $destination);
            } catch (\ImagickException $exception) {
                throw new SoFinderException('Unable to draw the text watermark.', 'image_processing_failed', 500, $exception);
            } finally {
                $draw->clear();
                $this->release($image);
            }
        });
    }

    public function imageWatermark(string $source, string $watermark, string $destination, string $position, int $opacity, int $scale, int $quality, ?int $x = null, ?int $y = null): void
    {
        $this->assertWatermarkSettings($position, $opacity, $scale, $x, $y);
        $this->assertQuality($quality);
        $this->withResourceLimits(function () use ($source, $watermark, $destination, $position, $opacity, $scale, $quality, $x, $y): void {
            $probe = $this->probe($source);
            $markProbe = $this->probe($watermark);
            $this->assertEditableFrames($probe['frames']);
            $this->assertEditableFrames($markProbe['frames']);
            $image = $this->read($source, $probe['coder'], true);
            $mark = $this->read($watermark, $markProbe['coder'], true);
            try {
                $format = $image->getImageFormat();
                $this->orient($image);
                $this->orient($mark);
                $targetWidth = max(1, (int) round($image->getImageWidth() * $scale / 100));
                $targetHeight = max(1, (int) round($mark->getImageHeight() * $targetWidth / $mark->getImageWidth()));
                if ($targetHeight > $image->getImageHeight()) {
                    $targetHeight = $image->getImageHeight();
                    $targetWidth = max(1, (int) round($mark->getImageWidth() * $targetHeight / $mark->getImageHeight()));
                }
                $mark->resizeImage($targetWidth, $targetHeight, \Imagick::FILTER_LANCZOS, 1);
                $mark->setImageAlphaChannel(\Imagick::ALPHACHANNEL_ACTIVATE);
                $mark->evaluateImage(\Imagick::EVALUATE_MULTIPLY, $opacity / 100, \Imagick::CHANNEL_ALPHA);
                [$left, $top] = $this->watermarkCoordinates($image->getImageWidth(), $image->getImageHeight(), $targetWidth, $targetHeight, $position, $x, $y);
                $image->compositeImage($mark, \Imagick::COMPOSITE_OVER, $left, $top);
                $image->setImageFormat($format);
                $image->setImageCompressionQuality($quality);
                $image->stripImage();
                $this->write($image, $destination);
            } catch (\ImagickException $exception) {
                throw new SoFinderException('Unable to composite the image watermark.', 'image_processing_failed', 500, $exception);
            } finally {
                $this->release($mark);
                $this->release($image);
            }
        });
    }

    private function assertWatermarkSettings(string $position, int $opacity, int $scale, ?int $x, ?int $y): void
    {
        if (!in_array($position, ['top-left', 'top-right', 'center', 'bottom-left', 'bottom-right', 'custom'], true)) {
            throw new SoFinderException('The watermark position is invalid.', 'invalid_watermark_position', 422);
        }
        if ($position === 'custom' && ($x === null || $y === null || $x < 0 || $x > 100 || $y < 0 || $y > 100)) {
            throw new SoFinderException('Custom watermark coordinates must be percentages between 0 and 100.', 'invalid_watermark_position', 422);
        }
        if ($opacity < 1 || $opacity > 100 || $scale < 5 || $scale > 80) {
            throw new SoFinderException('Watermark opacity or scale is outside the allowed range.', 'invalid_watermark_settings', 422);
        }
    }

    /** @return array{int,int} */
    private function watermarkCoordinates(int $width, int $height, int $markWidth, int $markHeight, string $position, ?int $x, ?int $y): array
    {
        $margin = max(4, (int) round(min($width, $height) * 0.02));
        return match ($position) {
            'custom' => [(int) round(max(0, $width - $markWidth) * (int) $x / 100), (int) round(max(0, $height - $markHeight) * (int) $y / 100)],
            'top-left' => [$margin, $margin],
            'top-right' => [max(0, $width - $markWidth - $margin), $margin],
            'bottom-left' => [$margin, max(0, $height - $markHeight - $margin)],
            'bottom-right' => [max(0, $width - $markWidth - $margin), max(0, $height - $markHeight - $margin)],
            default => [max(0, intdiv($width - $markWidth, 2)), max(0, intdiv($height - $markHeight, 2))],
        };
    }

    /** @return array{format:string,coder:string,frames:int,totalPixels:int} */
    private function probe(string $source): array
    {
        if (!extension_loaded('imagick')) {
            throw new SoFinderException('Imagick is not installed.', 'unsupported_image', 415);
        }
        $format = $this->formats->detectFormat($source);
        $coder = $format === null ? null : $this->formats->coder($format);
        $mime = $format === null ? null : $this->formats->canonicalMime($format);
        if ($format === null || $coder === null || $mime === null || !$this->supports($mime)) {
            throw new SoFinderException('The image format is not supported.', 'unsupported_image', 415);
        }

        $probe = new \Imagick();
        try {
            $probe->pingImage($this->sourceSpecifier($coder, $source));
            $frames = $probe->getNumberImages();
            if ($frames < 1 || $frames > $this->limits->maxFrames) {
                throw new SoFinderException('The image contains too many frames.', 'image_frame_limit_exceeded', 413);
            }
            $totalPixels = 0;
            foreach ($probe as $frame) {
                $width = $frame->getImageWidth();
                $height = $frame->getImageHeight();
                $this->assertDimensions($width, $height);
                $totalPixels += $width * $height;
                if ($totalPixels > $this->limits->maxTotalPixels) {
                    throw new SoFinderException('The image exceeds the total decoded pixel limit.', 'image_pixel_limit_exceeded', 413);
                }
            }

            return ['format' => $format, 'coder' => $coder, 'frames' => $frames, 'totalPixels' => $totalPixels];
        } catch (SoFinderException $exception) {
            throw $exception;
        } catch (\ImagickException $exception) {
            throw new SoFinderException('Unable to inspect the image.', 'invalid_image', 415, $exception);
        } finally {
            $this->release($probe);
        }
    }

    private function read(string $source, string $coder, bool $firstFrameOnly = false): \Imagick
    {
        try {
            $image = new \Imagick();
            $image->readImage($this->sourceSpecifier($coder, $source, $firstFrameOnly));

            return $image;
        } catch (\ImagickException $exception) {
            throw new SoFinderException('Unable to decode the image.', 'invalid_image', 415, $exception);
        }
    }

    private function sourceSpecifier(string $coder, string $source, bool $firstFrameOnly = false): string
    {
        return $coder . ':' . $source . ($firstFrameOnly ? '[0]' : '');
    }

    private function resize(\Imagick $image, int $width, int $height): void
    {
        if ($width < 0 || $height < 0 || $width > 4096 || $height > 4096) {
            throw new SoFinderException('Image dimensions must be between 1 and 4096 pixels.', 'invalid_image_dimensions', 422);
        }
        if ($width === 0 && $height === 0) {
            return;
        }
        $scale = min($width > 0 ? $width / $image->getImageWidth() : INF, $height > 0 ? $height / $image->getImageHeight() : INF, 1.0);
        $image->resizeImage(max(1, (int) round($image->getImageWidth() * $scale)), max(1, (int) round($image->getImageHeight() * $scale)), \Imagick::FILTER_LANCZOS, 1);
    }

    private function orient(\Imagick $image): void
    {
        $orientation = $image->getImageOrientation();
        if ($orientation === 2) {
            $image->flopImage();
        } elseif ($orientation === 3) {
            $image->rotateImage(new \ImagickPixel('transparent'), 180);
        } elseif ($orientation === 4) {
            $image->flipImage();
        } elseif ($orientation === 5) {
            $image->rotateImage(new \ImagickPixel('transparent'), 90);
            $image->flopImage();
        } elseif ($orientation === 6) {
            $image->rotateImage(new \ImagickPixel('transparent'), 90);
        } elseif ($orientation === 7) {
            $image->rotateImage(new \ImagickPixel('transparent'), 90);
            $image->flipImage();
        } elseif ($orientation === 8) {
            $image->rotateImage(new \ImagickPixel('transparent'), -90);
        }
        $image->setImagePage(0, 0, 0, 0);
    }

    private function write(\Imagick $image, string $destination): void
    {
        try {
            if (!$image->writeImage($destination)) {
                throw new SoFinderException('Unable to save the processed image.', 'image_processing_failed', 500);
            }
        } catch (\ImagickException $exception) {
            throw new SoFinderException('Unable to save the processed image.', 'image_processing_failed', 500, $exception);
        }
    }

    private function assertDimensions(int $width, int $height): void
    {
        if ($width < 1 || $height < 1 || $width > $this->limits->maxWidth || $height > $this->limits->maxHeight || $width * $height > $this->limits->maxSingleFramePixels) {
            throw new SoFinderException('The image format or dimensions are not supported.', 'unsupported_image', 415);
        }
    }

    private function assertEditableFrames(int $frames): void
    {
        if ($frames > 1) {
            throw new SoFinderException('Animated or multi-page images cannot be edited without losing content.', 'animated_image_edit_unsupported', 415);
        }
    }

    private function assertQuality(int $quality): void
    {
        if ($quality < 1 || $quality > 100) {
            throw new SoFinderException('Image quality must be between 1 and 100.', 'invalid_image_quality', 422);
        }
    }

    /**
     * @template T
     * @param callable():T $operation
     * @return T
     */
    private function withResourceLimits(callable $operation): mixed
    {
        if (!extension_loaded('imagick')) {
            throw new SoFinderException('Imagick is not installed.', 'unsupported_image', 415);
        }
        $limits = [
            \Imagick::RESOURCETYPE_MEMORY => $this->limits->memoryBytes,
            \Imagick::RESOURCETYPE_MAP => $this->limits->mapBytes,
            \Imagick::RESOURCETYPE_DISK => $this->limits->diskBytes,
            \Imagick::RESOURCETYPE_THREAD => $this->limits->threads,
            \Imagick::RESOURCETYPE_TIME => $this->limits->timeoutSeconds,
        ];
        $previous = [];
        foreach ($limits as $type => $value) {
            $original = \Imagick::getResourceLimit($type);
            $maximumRestorable = $type === \Imagick::RESOURCETYPE_TIME ? 2_147_483_647 : PHP_INT_MAX;
            $previous[$type] = $this->restorableLimit($original, $maximumRestorable);
            \Imagick::setResourceLimit($type, $value);
        }
        try {
            return $operation();
        } finally {
            foreach ($previous as $type => $value) {
                \Imagick::setResourceLimit($type, $value);
            }
        }
    }

    private function release(\Imagick $image): void
    {
        $image->clear();
        $image->destroy();
    }

    private function restorableLimit(mixed $value, int $maximum): int
    {
        if ((is_float($value) || is_int($value)) && $value >= $maximum) {
            return $maximum;
        }

        return is_numeric($value) ? max(0, (int) $value) : $maximum;
    }
}
