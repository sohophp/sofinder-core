<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Image;

use SohoPHP\SoFinder\Contract\ImageProcessorInterface;
use SohoPHP\SoFinder\Contract\ImageEffectsProcessorInterface;
use SohoPHP\SoFinder\Exception\SoFinderException;

final class GdImageProcessor implements ImageProcessorInterface, ImageEffectsProcessorInterface
{
    public function __construct(
        private readonly int $maximumPixels = 50_000_000,
        private readonly ImageFormatRegistry $formats = new ImageFormatRegistry(),
        private readonly ?string $watermarkFont = null,
        private readonly ?WatermarkFontResolver $watermarkFontResolver = null,
    )
    {
    }

    public function supports(string $mimeType): bool
    {
        $format = $this->formats->formatForMime($mimeType);
        if (!extension_loaded('gd') || $format === null) {
            return false;
        }

        return match ($format) {
            'avif' => $this->supportsAvif(),
            'webp' => function_exists('imagecreatefromwebp') && function_exists('imagewebp'),
            'bmp' => function_exists('imagecreatefrombmp') && function_exists('imagebmp'),
            'jpeg', 'png', 'gif' => true,
            default => false,
        };
    }

    private function supportsAvif(): bool
    {
        static $supported;
        if (is_bool($supported)) {
            return $supported;
        }
        if (!function_exists('imagecreatefromavif') || !function_exists('imageavif')) {
            return $supported = false;
        }

        $path = tempnam(sys_get_temp_dir(), 'sofinder-avif-probe-');
        $source = imagecreatetruecolor(8, 4);
        if ($path === false || $source === false) {
            if (is_string($path)) {
                @unlink($path);
            }

            return $supported = false;
        }

        try {
            $encoded = @imageavif($source, $path, 80);
            $decoded = $encoded ? @imagecreatefromavif($path) : false;
            $info = $encoded ? @getimagesize($path) : false;
            $supported = $decoded instanceof \GdImage
                && imagesx($decoded) === 8
                && imagesy($decoded) === 4
                && is_array($info)
                && [$info[0], $info[1]] === [8, 4];
            unset($decoded);

            return $supported;
        } finally {
            unset($source);
            @unlink($path);
        }
    }

    public function dimensions(string $source): array
    {
        $info = @getimagesize($source);
        if (!is_array($info)) {
            throw new SoFinderException('The selected file is not a valid image.', 'invalid_image', 415);
        }
        $width = (int) $info[0];
        $height = (int) $info[1];
        $mimeType = strtolower((string) $info['mime']);
        if (!$this->supports($mimeType) || $width < 1 || $height < 1 || $width * $height > $this->maximumPixels) {
            throw new SoFinderException('The image format or dimensions are not supported.', 'unsupported_image', 415);
        }
        if ($mimeType === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data($source);
            $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
            if (in_array($orientation, [5, 6, 7, 8], true)) {
                [$width, $height] = [$height, $width];
            }
        }

        return ['width' => $width, 'height' => $height];
    }

    public function validate(string $source): array
    {
        [$image] = $this->load($source);
        try {
            return ['width' => imagesx($image), 'height' => imagesy($image)];
        } finally {
            unset($image);
        }
    }

    public function isAnimated(string $source): bool
    {
        $contents = @file_get_contents($source);
        if ($contents === false) {
            throw new SoFinderException('Unable to inspect the image animation.', 'invalid_image', 415);
        }
        $info = @getimagesize($source);
        if (!is_array($info)) {
            throw new SoFinderException('The selected file is not a valid image.', 'invalid_image', 415);
        }
        $mime = strtolower((string) $info['mime']);
        if ($mime === 'image/gif') {
            return preg_match_all('/\x00\x21\xF9\x04/s', $contents) > 1;
        }
        if ($mime === 'image/webp') {
            return str_contains($contents, 'ANIM') || substr_count($contents, 'ANMF') > 1;
        }
        if ($mime === 'image/avif') {
            return str_contains(substr($contents, 0, 128), 'avis');
        }

        return false;
    }

    public function thumbnail(string $source, string $destination, int $width, int $height): void
    {
        [$image, $mimeType] = $this->load($source);
        try {
            $this->resizeAndSave($image, 'image/png', $destination, $width, $height, false);
        } finally {
            unset($image);
        }
    }

    public function transform(string $source, string $destination, int $rotation, int $width, int $height, int $quality = 88): void
    {
        if (!in_array($rotation, [0, 90, 180, 270], true)) {
            throw new SoFinderException('Image rotation must be 0, 90, 180 or 270 degrees.', 'invalid_image_operation', 422);
        }
        [$image, $mimeType] = $this->load($source);
        try {
            if ($rotation !== 0) {
                $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
                if ($transparent === false) {
                    throw new SoFinderException('Unable to allocate the image background.', 'image_processing_failed', 500);
                }
                $rotated = imagerotate($image, -$rotation, $transparent);
                if (!$rotated instanceof \GdImage) {
                    throw new SoFinderException('Unable to rotate the image.', 'image_processing_failed', 500);
                }
                unset($image);
                $image = $rotated;
            }
            $this->resizeAndSave($image, $mimeType, $destination, $width, $height, true, $quality);
        } finally {
            unset($image);
        }
    }

    public function crop(string $source, string $destination, int $x, int $y, int $width, int $height, int $quality = 88): void
    {
        [$image, $mimeType] = $this->load($source);
        try {
            if ($x < 0 || $y < 0 || $width < 1 || $height < 1 || $width > 4096 || $height > 4096 || $x + $width > imagesx($image) || $y + $height > imagesy($image)) {
                throw new SoFinderException('The crop rectangle is outside the image.', 'invalid_crop', 422);
            }
            $cropped = imagecrop($image, ['x' => $x, 'y' => $y, 'width' => $width, 'height' => $height]);
            if (!$cropped instanceof \GdImage) {
                throw new SoFinderException('Unable to crop the image.', 'image_processing_failed', 500);
            }
            try {
                $this->resizeAndSave($cropped, $mimeType, $destination, 0, 0, true, $quality);
            } finally {
                unset($cropped);
            }
        } finally {
            unset($image);
        }
    }

    public function optimize(string $source, string $destination, string $mimeType, int $quality): void
    {
        [$image] = $this->load($source);
        try {
            if (!$this->supports($mimeType)) {
                throw new SoFinderException('The requested output image format is unavailable.', 'unsupported_image_output_format', 415);
            }
            $this->save($image, $mimeType, $destination, $quality);
        } finally {
            unset($image);
        }
    }

    public function textWatermark(string $source, string $destination, string $text, string $position, int $opacity, int $scale, string $color, int $quality, ?int $x = null, ?int $y = null, string $font = 'interface'): void
    {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > 200) {
            throw new SoFinderException('Watermark text must contain between 1 and 200 characters.', 'invalid_watermark_text', 422);
        }
        [$image, $mimeType] = $this->load($source);
        try {
            [$opacity, $scale] = $this->watermarkSettings($position, $opacity, $scale, $x, $y);
            if (preg_match('/^#[0-9a-fA-F]{6}$/D', $color) !== 1) {
                throw new SoFinderException('Watermark color must be a six-digit hexadecimal color.', 'invalid_watermark_color', 422);
            }
            $alpha = max(0, min(127, 127 - (int) round($opacity * 127 / 100)));
            $red = max(0, min(255, (int) hexdec(substr($color, 1, 2))));
            $green = max(0, min(255, (int) hexdec(substr($color, 3, 2))));
            $blue = max(0, min(255, (int) hexdec(substr($color, 5, 2))));
            $ink = imagecolorallocatealpha($image, $red, $green, $blue, $alpha);
            if ($ink === false) {
                throw new SoFinderException('Unable to draw the text watermark.', 'image_processing_failed', 500);
            }
            $watermarkFont = $this->watermarkFontResolver?->resolve($font) ?? $this->watermarkFont;
            if ($watermarkFont !== null && is_readable($watermarkFont) && function_exists('imagettfbbox') && function_exists('imagettftext')) {
                $fontSize = max(10, (int) round(min(imagesx($image), imagesy($image)) * $scale / 500));
                $box = imagettfbbox($fontSize, 0, $watermarkFont, $text);
                if (!is_array($box)) {
                    throw new SoFinderException('Unable to measure the text watermark.', 'image_processing_failed', 500);
                }
                $textWidth = abs($box[4] - $box[0]);
                $textHeight = abs($box[5] - $box[1]);
                [$left, $top] = $this->watermarkCoordinates(imagesx($image), imagesy($image), $textWidth, $textHeight, $position, $x, $y);
                if (imagettftext($image, $fontSize, 0, $left, $top + $textHeight, $ink, $watermarkFont, $text) === false) {
                    throw new SoFinderException('Unable to draw the text watermark.', 'image_processing_failed', 500);
                }
            } else {
                if (preg_match('/[^\x20-\x7E]/', $text) === 1) {
                    throw new SoFinderException('A configured TrueType font is required for Unicode watermark text.', 'watermark_font_unavailable', 503);
                }
                $font = max(1, min(5, (int) round($scale / 20)));
                $textWidth = imagefontwidth($font) * strlen($text);
                $textHeight = imagefontheight($font);
                [$left, $top] = $this->watermarkCoordinates(imagesx($image), imagesy($image), $textWidth, $textHeight, $position, $x, $y);
                if (!imagestring($image, $font, $left, $top, $text, $ink)) {
                    throw new SoFinderException('Unable to draw the text watermark.', 'image_processing_failed', 500);
                }
            }
            $this->save($image, $mimeType, $destination, $quality);
        } finally {
            unset($image);
        }
    }

    public function imageWatermark(string $source, string $watermark, string $destination, string $position, int $opacity, int $scale, int $quality, ?int $x = null, ?int $y = null): void
    {
        [$image, $mimeType] = $this->load($source);
        [$mark] = $this->load($watermark);
        try {
            [$opacity, $scale] = $this->watermarkSettings($position, $opacity, $scale, $x, $y);
            $targetWidth = max(1, (int) round(imagesx($image) * $scale / 100));
            $targetHeight = max(1, (int) round(imagesy($mark) * $targetWidth / imagesx($mark)));
            if ($targetHeight > imagesy($image)) {
                $targetHeight = imagesy($image);
                $targetWidth = max(1, (int) round(imagesx($mark) * $targetHeight / imagesy($mark)));
            }
            $resized = imagecreatetruecolor($targetWidth, $targetHeight);
            if (!$resized instanceof \GdImage) {
                throw new SoFinderException('Unable to allocate the watermark image.', 'image_processing_failed', 500);
            }
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
            if ($transparent === false) {
                throw new SoFinderException('Unable to allocate the watermark background.', 'image_processing_failed', 500);
            }
            imagefill($resized, 0, 0, $transparent);
            imagecopyresampled($resized, $mark, 0, 0, 0, 0, $targetWidth, $targetHeight, imagesx($mark), imagesy($mark));
            imagefilter($resized, IMG_FILTER_COLORIZE, 0, 0, 0, 127 - (int) round($opacity * 127 / 100));
            [$left, $top] = $this->watermarkCoordinates(imagesx($image), imagesy($image), $targetWidth, $targetHeight, $position, $x, $y);
            imagealphablending($image, true);
            if (!imagecopy($image, $resized, $left, $top, 0, 0, $targetWidth, $targetHeight)) {
                throw new SoFinderException('Unable to composite the image watermark.', 'image_processing_failed', 500);
            }
            $this->save($image, $mimeType, $destination, $quality);
        } finally {
            unset($resized, $mark, $image);
        }
    }

    /** @return array{\GdImage, string} */
    private function load(string $source): array
    {
        $this->dimensions($source);
        $info = @getimagesize($source);
        if (!is_array($info)) {
            throw new SoFinderException('The selected file is not a valid image.', 'invalid_image', 415);
        }
        $mimeType = strtolower((string) $info['mime']);
        $image = match ($mimeType) {
            'image/jpeg' => @imagecreatefromjpeg($source),
            'image/png' => @imagecreatefrompng($source),
            'image/gif' => @imagecreatefromgif($source),
            'image/webp' => @imagecreatefromwebp($source),
            'image/avif' => @imagecreatefromavif($source),
            'image/bmp' => @imagecreatefrombmp($source),
            default => false,
        };
        if (!$image instanceof \GdImage) {
            throw new SoFinderException('Unable to decode the image.', 'invalid_image', 415);
        }

        return [$this->orient($image, $source, $mimeType), $mimeType];
    }

    private function orient(\GdImage $image, string $source, string $mimeType): \GdImage
    {
        if ($mimeType !== 'image/jpeg' || !function_exists('exif_read_data')) {
            return $image;
        }
        $exif = @exif_read_data($source);
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, in_array($orientation, [2, 5], true) ? IMG_FLIP_HORIZONTAL : IMG_FLIP_VERTICAL);
        }
        $angle = match ($orientation) {
            3 => 180,
            5, 6 => -90,
            7, 8 => 90,
            default => 0,
        };
        if ($angle === 0) {
            return $image;
        }
        $rotated = imagerotate($image, $angle, 0);
        if (!$rotated instanceof \GdImage) {
            throw new SoFinderException('Unable to apply the image orientation.', 'image_processing_failed', 500);
        }
        unset($image);

        return $rotated;
    }

    private function resizeAndSave(\GdImage $source, string $mimeType, string $destination, int $width, int $height, bool $allowOriginalSize, int $quality = 88): void
    {
        if ($quality < 1 || $quality > 100) {
            throw new SoFinderException('Image quality must be between 1 and 100.', 'invalid_image_quality', 422);
        }
        if ($width < 0 || $height < 0 || $width > 4096 || $height > 4096 || ($width === 0 && $height === 0 && !$allowOriginalSize)) {
            throw new SoFinderException('Image dimensions must be between 1 and 4096 pixels.', 'invalid_image_dimensions', 422);
        }
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $scale = min($width > 0 ? $width / $sourceWidth : INF, $height > 0 ? $height / $sourceHeight : INF);
        if (!is_finite($scale)) {
            $scale = 1.0;
        }
        $scale = min(1.0, $scale);
        $targetWidth = max(1, (int) round($sourceWidth * $scale));
        $targetHeight = max(1, (int) round($sourceHeight * $scale));
        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        if (!$target instanceof \GdImage) {
            throw new SoFinderException('Unable to allocate the resized image.', 'image_processing_failed', 500);
        }
        try {
            if (in_array($mimeType, ['image/png', 'image/gif', 'image/webp', 'image/avif'], true)) {
                imagealphablending($target, false);
                imagesavealpha($target, true);
                $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
                if ($transparent === false) {
                    throw new SoFinderException('Unable to allocate the image background.', 'image_processing_failed', 500);
                }
                imagefill($target, 0, 0, $transparent);
            }
            imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);
            $this->save($target, $mimeType, $destination, $quality);
        } finally {
            unset($target);
        }
    }

    private function save(\GdImage $image, string $mimeType, string $destination, int $quality): void
    {
        if ($quality < 1 || $quality > 100) {
            throw new SoFinderException('Image quality must be between 1 and 100.', 'invalid_image_quality', 422);
        }
        if ($mimeType === 'image/jpeg') {
            $background = imagecreatetruecolor(imagesx($image), imagesy($image));
            if (!$background instanceof \GdImage) {
                throw new SoFinderException('Unable to allocate the image background.', 'image_processing_failed', 500);
            }
            $white = imagecolorallocate($background, 255, 255, 255);
            if ($white === false) {
                throw new SoFinderException('Unable to allocate the image background color.', 'image_processing_failed', 500);
            }
            imagefill($background, 0, 0, $white);
            imagecopy($background, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
            $image = $background;
        }
        $saved = match ($mimeType) {
            'image/jpeg' => imagejpeg($image, $destination, $quality),
            'image/png' => imagepng($image, $destination, (int) round((100 - $quality) * 9 / 99)),
            'image/gif' => imagegif($image, $destination),
            'image/webp' => imagewebp($image, $destination, $quality),
            'image/avif' => imageavif($image, $destination, $quality),
            'image/bmp' => imagebmp($image, $destination),
            default => false,
        };
        if (!$saved) {
            throw new SoFinderException('Unable to save the processed image.', 'image_processing_failed', 500);
        }
    }

    /** @return array{int,int} */
    private function watermarkSettings(string $position, int $opacity, int $scale, ?int $x, ?int $y): array
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
        return [$opacity, $scale];
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
}
