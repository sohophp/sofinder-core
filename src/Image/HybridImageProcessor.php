<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Image;

use SohoPHP\SoFinder\Contract\ImageProcessorInterface;
use SohoPHP\SoFinder\Contract\ImageCapabilityProviderInterface;
use SohoPHP\SoFinder\Contract\ImageEffectsProcessorInterface;
use SohoPHP\SoFinder\Exception\SoFinderException;

final readonly class HybridImageProcessor implements ImageProcessorInterface, ImageCapabilityProviderInterface, ImageEffectsProcessorInterface
{
    public function __construct(
        private ImageFormatRegistry $formats,
        private GdImageProcessor $gd,
        private ImagickImageProcessor $imagick,
        private string $driver = 'auto',
    ) {
        if (!in_array($driver, ['auto', 'gd', 'imagick'], true)) {
            throw new \InvalidArgumentException('Image processor driver must be auto, gd or imagick.');
        }
        if ($driver === 'gd' && !extension_loaded('gd')) {
            throw new \RuntimeException('The configured GD image processor is not installed.');
        }
        if ($driver === 'imagick' && !extension_loaded('imagick')) {
            throw new \RuntimeException('The configured Imagick image processor is not installed.');
        }
    }

    public function supports(string $mimeType): bool
    {
        return $this->processorForMime($mimeType) !== null;
    }

    public function dimensions(string $source): array
    {
        return $this->processorForSource($source)->dimensions($source);
    }

    public function validate(string $source): array
    {
        return $this->processorForSource($source)->validate($source);
    }

    public function isAnimated(string $source): bool
    {
        return $this->processorForSource($source)->isAnimated($source);
    }

    public function thumbnail(string $source, string $destination, int $width, int $height): void
    {
        $this->processorForSource($source)->thumbnail($source, $destination, $width, $height);
    }

    public function transform(string $source, string $destination, int $rotation, int $width, int $height, int $quality = 88): void
    {
        $this->processorForSource($source)->transform($source, $destination, $rotation, $width, $height, $quality);
    }

    public function crop(string $source, string $destination, int $x, int $y, int $width, int $height, int $quality = 88): void
    {
        $this->processorForSource($source)->crop($source, $destination, $x, $y, $width, $height, $quality);
    }

    public function optimize(string $source, string $destination, string $mimeType, int $quality): void
    {
        $sourceFormat = $this->formats->detectFormat($source);
        $sourceMime = $sourceFormat === null ? null : $this->formats->canonicalMime($sourceFormat);
        $processor = match ($this->driver) {
            'gd' => $sourceMime !== null && $this->gd->supports($sourceMime) && $this->gd->supports($mimeType) ? $this->gd : null,
            'imagick' => $sourceMime !== null && $this->imagick->supports($sourceMime) && $this->imagick->canEncode($mimeType) ? $this->imagick : null,
            default => $sourceMime !== null && $this->gd->supports($sourceMime) && $this->gd->supports($mimeType)
                ? $this->gd
                : ($sourceMime !== null && $this->imagick->supports($sourceMime) && $this->imagick->canEncode($mimeType) ? $this->imagick : null),
        };
        if (!$processor instanceof ImageEffectsProcessorInterface) {
            throw new SoFinderException('The requested source/output image format combination is unavailable.', 'unsupported_image_output_format', 415);
        }
        $processor->optimize($source, $destination, $mimeType, $quality);
    }

    public function textWatermark(string $source, string $destination, string $text, string $position, int $opacity, int $scale, string $color, int $quality, ?int $x = null, ?int $y = null, string $font = 'interface'): void
    {
        $processor = $this->processorForSource($source);
        if (!$processor instanceof ImageEffectsProcessorInterface) {
            throw new SoFinderException('Image watermarking is unavailable.', 'image_effects_unavailable', 501);
        }
        $processor->textWatermark($source, $destination, $text, $position, $opacity, $scale, $color, $quality, $x, $y, $font);
    }

    public function imageWatermark(string $source, string $watermark, string $destination, string $position, int $opacity, int $scale, int $quality, ?int $x = null, ?int $y = null): void
    {
        $processor = $this->processorForSource($source);
        if (!$processor instanceof ImageEffectsProcessorInterface) {
            throw new SoFinderException('Image watermarking is unavailable.', 'image_effects_unavailable', 501);
        }
        $processor->imageWatermark($source, $watermark, $destination, $position, $opacity, $scale, $quality, $x, $y);
    }

    /** @return list<array{format:string,extensions:list<string>,mimes:list<string>,processor:string,read:bool,edit:bool,thumbnail:bool,webEmbeddable:bool}> */
    public function capabilities(): array
    {
        $result = [];
        foreach ($this->formats->definitions() as $format => $definition) {
            $mime = $definition['mimes'][0];
            $processor = $this->processorForMime($mime);
            $available = $processor !== null;
            $editable = $available && $definition['editable']
                && (!$processor instanceof ImagickImageProcessor || $processor->canEncode($mime));
            $result[] = [
                'format' => $format,
                'extensions' => $definition['extensions'],
                'mimes' => $definition['mimes'],
                'processor' => $processor instanceof GdImageProcessor ? 'gd' : ($processor instanceof ImagickImageProcessor ? 'imagick' : ''),
                'read' => $available,
                'edit' => $editable,
                'thumbnail' => $available,
                'webEmbeddable' => $available && $definition['web'],
            ];
        }

        return $result;
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function isWebEmbeddable(string $mimeType): bool
    {
        return $this->supports($mimeType) && $this->formats->isWebEmbeddableMime($mimeType);
    }

    public function supportsExtension(string $extension): bool
    {
        $format = $this->formats->formatForExtension($extension);
        $mime = $format === null ? null : $this->formats->canonicalMime($format);

        return $mime !== null && $this->supports($mime);
    }

    public function cacheVersion(): string
    {
        return substr(hash('sha256', $this->driver . '|' . json_encode($this->capabilities(), JSON_THROW_ON_ERROR)), 0, 16);
    }

    private function processorForSource(string $source): ImageProcessorInterface
    {
        $format = $this->formats->detectFormat($source);
        $mime = $format === null ? null : $this->formats->canonicalMime($format);
        $processor = $mime === null ? null : $this->processorForMime($mime);
        if ($processor === null) {
            throw new SoFinderException('The image format is not supported by the configured processor.', 'unsupported_image', 415);
        }

        return $processor;
    }

    private function processorForMime(string $mimeType): ?ImageProcessorInterface
    {
        $processor = match ($this->driver) {
            'gd' => $this->gd->supports($mimeType) ? $this->gd : null,
            'imagick' => $this->imagick->supports($mimeType) ? $this->imagick : null,
            default => $this->gd->supports($mimeType) ? $this->gd : ($this->imagick->supports($mimeType) ? $this->imagick : null),
        };
        return $processor;
    }
}
