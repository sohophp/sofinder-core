<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Image;

use SohoPHP\SoFinder\Contract\ImageProcessorInterface;
use SohoPHP\SoFinder\Contract\ImageCapabilityProviderInterface;
use SohoPHP\SoFinder\Contract\ImageEffectsProcessorInterface;
use SohoPHP\SoFinder\Exception\SoFinderException;
use SohoPHP\SoFinder\FileManager;
use SohoPHP\SoFinder\Value\Entry;

final readonly class ImageManager
{
    public function __construct(
        private FileManager $files,
        private ImageProcessorInterface $processor,
        private string $cacheDirectory,
        /** @var array<string, array{width:int,height:int,quality:int}> */
        private array $presets = [],
        private ImageFormatRegistry $formats = new ImageFormatRegistry(),
        private int $directoryMode = 0775,
        private int $fileMode = 0664,
        private bool $variantsEnabled = false,
        /** @var list<int> */ private array $variantWidths = [320, 640, 960, 1280, 1920],
        /** @var list<string> */ private array $variantFormats = ['original', 'webp'],
        private int $variantQuality = 82,
        private int $variantCacheTtl = 2_592_000,
    ) {
    }

    /** @return array{path:string,mimeType:string,width:int,height:int} */
    public function variant(string $resource, string $path, int $width, string $format = 'original'): array
    {
        if (!$this->variantsEnabled) throw new SoFinderException('Responsive image variants are disabled.', 'image_variants_disabled', 404);
        if (!in_array($width, $this->variantWidths, true) || !in_array($format, $this->variantFormats, true)) throw new SoFinderException('The requested image variant is not allowed.', 'invalid_image_variant', 422);
        $entry = $this->imageEntry($resource, $path); $dimensions = $this->info($resource, $path);
        if ($width > $dimensions['width']) throw new SoFinderException('Image variants cannot enlarge the original image.', 'image_variant_upscale', 422);
        $height = max(1, (int) round($dimensions['height'] * ($width / $dimensions['width'])));
        $mimeType = $format === 'original' ? ($entry->mimeType ?? 'image/jpeg') : $this->formats->canonicalMime($format);
        if ($mimeType === null) throw new SoFinderException('The requested image variant format is unavailable.', 'unsupported_image_output_format', 415);
        $extension = $this->formats->extensionsForMime($mimeType)[0] ?? 'img';
        $directory = rtrim($this->cacheDirectory, DIRECTORY_SEPARATOR) . '/variants';
        if (!is_dir($directory) && !@mkdir($directory, $this->directoryMode, true) && !is_dir($directory)) throw new SoFinderException('Unable to create the image variant cache.', 'image_processing_failed', 500);
        $this->maintainCache($directory, $this->variantCacheTtl);
        $processorVersion = $this->processor instanceof ImageCapabilityProviderInterface ? $this->processor->cacheVersion() : get_debug_type($this->processor);
        $cachePath = $directory . '/' . hash('sha256', implode('|', [$processorVersion, $resource, $entry->path, $entry->modifiedAt, $entry->size, $width, $format, $this->variantQuality])) . '.' . $extension;
        $lock = fopen($cachePath . '.lock', 'c+'); if ($lock === false || !flock($lock, LOCK_EX)) throw new SoFinderException('Unable to lock the image variant cache.', 'image_processing_failed', 500);
        try {
            if (!is_file($cachePath)) {
                $temporary = tempnam($directory, '.sofinder-variant-'); if ($temporary === false) throw new SoFinderException('Unable to create an image variant work file.', 'image_processing_failed', 500);
                try {
                    $this->process($resource, $path, function (ImageProcessorInterface $processor, string $source, string $destination) use ($width, $height, $format, $mimeType, $directory): void {
                        if ($format === 'original') { $processor->transform($source, $destination, 0, $width, $height, $this->variantQuality); return; }
                        $resized = tempnam($directory, '.sofinder-resized-'); if ($resized === false) throw new SoFinderException('Unable to create an image variant work file.', 'image_processing_failed', 500);
                        try { $processor->transform($source, $resized, 0, $width, $height, $this->variantQuality); $this->effectsProcessor()->optimize($resized, $destination, $mimeType, $this->variantQuality); } finally { @unlink($resized); }
                    }, $temporary);
                    if (!@rename($temporary, $cachePath) && !is_file($cachePath)) throw new SoFinderException('Unable to store the image variant.', 'image_processing_failed', 500);
                    @chmod($cachePath, $this->fileMode);
                } finally { @unlink($temporary); }
            }
        } finally { flock($lock, LOCK_UN); fclose($lock); @unlink($cachePath . '.lock'); }
        return ['path' => $cachePath, 'mimeType' => $mimeType, 'width' => $width, 'height' => $height];
    }

    /** @param list<string> $configured */
    public function preferredVariantFormat(string $originalMimeType, array $configured): string
    {
        foreach ($configured as $format) {
            if ($format === 'original') continue;
            $mime = $this->formats->canonicalMime($format);
            if ($mime === null || !$this->processor->supports($mime)) continue;
            try { $this->effectsProcessor(); return $format; } catch (SoFinderException) { continue; }
        }
        return 'original';
    }

    /** @return array{path:string,mimeType:string} */
    public function thumbnail(string $resource, string $path, int $width, int $height): array
    {
        $entry = $this->imageEntry($resource, $path);
        $width = max(32, min($width, 512));
        $height = max(32, min($height, 512));
        $directory = rtrim($this->cacheDirectory, DIRECTORY_SEPARATOR) . '/thumbnails';
        if (!is_dir($directory) && !@mkdir($directory, $this->directoryMode, true) && !is_dir($directory)) {
            throw new SoFinderException('Unable to create the thumbnail cache.', 'image_processing_failed', 500);
        }
        @chmod($directory, $this->directoryMode);
        $this->maintainCache($directory);
        $processorVersion = $this->processor instanceof ImageCapabilityProviderInterface ? $this->processor->cacheVersion() : get_debug_type($this->processor);
        $cachePath = $directory . '/' . hash('sha256', implode('|', [$processorVersion, $resource, $entry->path, $entry->modifiedAt, $entry->size, $width, $height])) . '.png';
        if (!is_file($cachePath)) {
            $temporary = tempnam($directory, '.sofinder-thumbnail-');
            if ($temporary === false) {
                throw new SoFinderException('Unable to create a thumbnail work file.', 'image_processing_failed', 500);
            }
            try {
                $this->process($resource, $path, static fn (ImageProcessorInterface $processor, string $source, string $destination) => $processor->thumbnail($source, $destination, $width, $height), $temporary);
                if (!@rename($temporary, $cachePath) && !is_file($cachePath)) {
                    throw new SoFinderException('Unable to store the thumbnail.', 'image_processing_failed', 500);
                }
                @chmod($cachePath, $this->fileMode);
            } finally {
                @unlink($temporary);
            }
        }

        return ['path' => $cachePath, 'mimeType' => 'image/png'];
    }

    public function edit(string $resource, string $path, int $rotation, int $width, int $height): Entry
    {
        $entry = $this->imageEntry($resource, $path);
        $destination = tempnam(sys_get_temp_dir(), 'sofinder-image-output-');
        if ($destination === false) {
            throw new SoFinderException('Unable to create an image work file.', 'image_processing_failed', 500);
        }
        try {
            $this->process($resource, $path, static fn (ImageProcessorInterface $processor, string $source, string $output) => $processor->transform($source, $output, $rotation, $width, $height), $destination);
            $stream = fopen($destination, 'rb');
            if ($stream === false) {
                throw new SoFinderException('Unable to read the processed image.', 'image_processing_failed', 500);
            }
            try {
                $directory = dirname($entry->path);

                return $this->files->upload($resource, $directory === '.' ? '' : $directory, $entry->name, (int) filesize($destination), $stream, true);
            } finally {
                fclose($stream);
            }
        } finally {
            @unlink($destination);
        }
    }

    /** @return array{width: int, height: int} */
    public function info(string $resource, string $path): array
    {
        $this->imageEntry($resource, $path);

        return $this->withSource(
            $resource,
            $path,
            fn (string $source): array => $this->processor->dimensions($source),
        );
    }

    public function crop(string $resource, string $path, int $x, int $y, int $width, int $height): Entry
    {
        return $this->rewrite(
            $resource,
            $path,
            static fn (ImageProcessorInterface $processor, string $source, string $output) => $processor->crop($source, $output, $x, $y, $width, $height),
        );
    }

    /**
     * @param list<array<string, mixed>> $actions
     * @param array{mode?:string,name?:string} $save
     * @return array{entry:Entry,original:array{width:int,height:int,size:int},result:array{width:int,height:int,size:int}}
     */
    public function applyActions(string $resource, string $path, array $actions, array $save = []): array
    {
        if ($actions === [] || count($actions) > 10) {
            throw new SoFinderException('An image edit requires between 1 and 10 actions.', 'invalid_image_actions', 422);
        }
        $entry = $this->imageEntry($resource, $path);
        $mode = (string) ($save['mode'] ?? 'copy');
        if (!in_array($mode, ['copy', 'overwrite'], true)) {
            throw new SoFinderException('The image save mode must be copy or overwrite.', 'invalid_save_mode', 422);
        }
        $source = tempnam(sys_get_temp_dir(), 'sofinder-image-source-');
        if ($source === false) {
            throw new SoFinderException('Unable to create an image work file.', 'image_processing_failed', 500);
        }
        $working = $source;
        $outputMimeType = $entry->mimeType;
        try {
            $this->copySource($resource, $path, $source);
            if ($this->processor->isAnimated($source)) {
                throw new SoFinderException('Animated or multi-page images cannot be edited without losing content.', 'animated_image_edit_unsupported', 415);
            }
            $originalDimensions = $this->processor->dimensions($source);
            $lastActionIndex = array_key_last($actions);
            foreach ($actions as $actionIndex => $action) {
                $output = tempnam(sys_get_temp_dir(), 'sofinder-image-action-');
                if ($output === false) {
                    throw new SoFinderException('Unable to create an image action file.', 'image_processing_failed', 500);
                }
                try {
                    $type = (string) ($action['type'] ?? '');
                    // Intermediate JPEG/WebP files are decoded again by the next action.
                    // Keep those encodes at maximum quality and apply the requested quality once, at the end.
                    $quality = $actionIndex === $lastActionIndex ? (int) ($action['quality'] ?? 88) : 100;
                    if ($type === 'crop') {
                        $this->processor->crop($working, $output, (int) ($action['x'] ?? -1), (int) ($action['y'] ?? -1), (int) ($action['width'] ?? 0), (int) ($action['height'] ?? 0), $quality);
                    } elseif ($type === 'rotate') {
                        $this->processor->transform($working, $output, (int) ($action['degrees'] ?? 0), 0, 0, $quality);
                    } elseif ($type === 'resize') {
                        $this->processor->transform($working, $output, 0, (int) ($action['width'] ?? 0), (int) ($action['height'] ?? 0), $quality);
                    } elseif ($type === 'preset') {
                        $name = (string) ($action['name'] ?? '');
                        $preset = $this->presets[$name] ?? null;
                        if ($preset === null) {
                            throw new SoFinderException('The requested image preset does not exist.', 'unknown_image_preset', 404);
                        }
                        $presetQuality = $actionIndex === $lastActionIndex ? (int) ($action['quality'] ?? $preset['quality']) : 100;
                        $this->processor->transform($working, $output, 0, $preset['width'], $preset['height'], $presetQuality);
                    } elseif ($type === 'optimize') {
                        $effects = $this->effectsProcessor();
                        $format = strtolower((string) ($action['format'] ?? 'original'));
                        $outputMimeType = $format === 'original' ? $outputMimeType : $this->formats->canonicalMime($format);
                        if ($outputMimeType === null) {
                            throw new SoFinderException('The requested output image format is invalid.', 'unsupported_image_output_format', 415);
                        }
                        $effects->optimize($working, $output, $outputMimeType, $quality);
                    } elseif ($type === 'watermarkText') {
                        $font = (string) ($action['font'] ?? 'interface');
                        if (!in_array($font, ['interface', 'sans', 'serif'], true)) {
                            throw new SoFinderException('The watermark font is invalid.', 'invalid_watermark_font', 422);
                        }
                        $this->effectsProcessor()->textWatermark(
                            $working,
                            $output,
                            (string) ($action['text'] ?? ''),
                            (string) ($action['position'] ?? 'bottom-right'),
                            (int) ($action['opacity'] ?? 60),
                            (int) ($action['scale'] ?? 25),
                            (string) ($action['color'] ?? '#ffffff'),
                            100,
                            isset($action['x']) ? (int) $action['x'] : null,
                            isset($action['y']) ? (int) $action['y'] : null,
                            $font,
                        );
                    } elseif ($type === 'watermarkImage') {
                        $watermarkResource = (string) ($action['resource'] ?? $resource);
                        $watermarkPath = (string) ($action['path'] ?? '');
                        $this->imageEntry($watermarkResource, $watermarkPath);
                        $watermark = tempnam(sys_get_temp_dir(), 'sofinder-watermark-');
                        if ($watermark === false) {
                            throw new SoFinderException('Unable to create a watermark work file.', 'image_processing_failed', 500);
                        }
                        try {
                            $this->copySource($watermarkResource, $watermarkPath, $watermark);
                            if ($this->processor->isAnimated($watermark)) {
                                throw new SoFinderException('Animated images cannot be used as watermarks.', 'animated_watermark_unsupported', 415);
                            }
                            $this->effectsProcessor()->imageWatermark(
                                $working,
                                $watermark,
                                $output,
                                (string) ($action['position'] ?? 'bottom-right'),
                                (int) ($action['opacity'] ?? 60),
                                (int) ($action['scale'] ?? 25),
                                100,
                                isset($action['x']) ? (int) $action['x'] : null,
                                isset($action['y']) ? (int) $action['y'] : null,
                            );
                        } finally {
                            @unlink($watermark);
                        }
                    } else {
                        throw new SoFinderException('The image action is not supported.', 'invalid_image_action', 422);
                    }
                } catch (\Throwable $exception) {
                    @unlink($output);
                    throw $exception;
                }
                if ($working !== $source) {
                    @unlink($working);
                }
                $working = $output;
            }

            $resultDimensions = $this->processor->dimensions($working);
            $directory = dirname($entry->path);
            $directory = $directory === '.' ? '' : $directory;
            $extension = $this->outputExtension($entry, $outputMimeType);
            $originalExtension = strtolower((string) pathinfo($entry->name, PATHINFO_EXTENSION));
            if ($mode === 'overwrite' && $extension !== '' && !in_array($originalExtension, $this->formats->extensionsForMime($outputMimeType ?? ''), true)) {
                throw new SoFinderException('Changing image format requires saving a copy.', 'image_format_overwrite_not_allowed', 422);
            }
            $name = $mode === 'overwrite'
                ? $entry->name
                : trim((string) ($save['name'] ?? ''));
            if ($mode === 'copy' && $name === '') {
                $name = $this->suggestCopyName($resource, $directory, $entry, $extension);
            } elseif ($mode === 'copy' && pathinfo($name, PATHINFO_EXTENSION) === '') {
                if ($extension !== '') {
                    $name .= '.' . $extension;
                }
            } elseif ($mode === 'copy') {
                $requestedExtension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
                $formatChanged = $outputMimeType !== $entry->mimeType;
                $extensionMatches = $formatChanged
                    ? in_array($requestedExtension, $this->formats->extensionsForMime($outputMimeType ?? ''), true)
                    : (string) pathinfo($name, PATHINFO_EXTENSION) === $extension;
                if ($extension !== '' && !$extensionMatches) {
                    throw new SoFinderException(
                        sprintf('The edited image name must use a .%s extension.', $extension),
                        $formatChanged ? 'image_extension_mismatch' : 'image_extension_change_not_allowed',
                        422,
                    );
                }
            }
            $stream = fopen($working, 'rb');
            if ($stream === false) {
                throw new SoFinderException('Unable to read the processed image.', 'image_processing_failed', 500);
            }
            try {
                $result = $this->files->upload($resource, $directory, $name, (int) filesize($working), $stream, $mode === 'overwrite');
            } finally {
                fclose($stream);
            }

            return [
                'entry' => $result,
                'original' => $originalDimensions + ['size' => $entry->size],
                'result' => $resultDimensions + ['size' => $result->size],
            ];
        } finally {
            if ($working !== $source) {
                @unlink($working);
            }
            @unlink($source);
        }
    }

    /** @return array<string, array{width:int,height:int,quality:int}> */
    public function presets(): array
    {
        return $this->presets;
    }

    /**
     * @param list<string> $paths
     * @param list<array<string, mixed>> $actions
     * @param array{mode?:string} $save
     * @return array{total:int,succeeded:int,failed:int,items:list<array{path:string,success:bool,entry?:Entry,error?:array{code:string,message:string}}>}
     */
    public function applyBatch(string $resource, array $paths, array $actions, array $save = []): array
    {
        $paths = array_values(array_unique(array_filter($paths, static fn (mixed $path): bool => is_string($path) && $path !== '')));
        if ($paths === [] || count($paths) > 100) {
            throw new SoFinderException('A batch image operation requires between 1 and 100 paths.', 'invalid_image_batch', 422);
        }
        $items = [];
        $succeeded = 0;
        foreach ($paths as $path) {
            try {
                $result = $this->applyActions($resource, $path, $actions, $save);
                $items[] = ['path' => $path, 'success' => true, 'entry' => $result['entry']];
                ++$succeeded;
            } catch (SoFinderException $exception) {
                $items[] = ['path' => $path, 'success' => false, 'error' => ['code' => $exception->errorCode, 'message' => $exception->getMessage()]];
            }
        }

        return ['total' => count($paths), 'succeeded' => $succeeded, 'failed' => count($paths) - $succeeded, 'items' => $items];
    }

    private function imageEntry(string $resource, string $path): Entry
    {
        $entry = $this->files->entry($resource, $path);
        if ($entry->directory || $entry->mimeType === null || !$this->processor->supports($entry->mimeType)) {
            throw new SoFinderException('The selected entry is not a supported image.', 'unsupported_image', 415);
        }

        return $entry;
    }

    /** @param callable(ImageProcessorInterface, string, string):void $operation */
    private function rewrite(string $resource, string $path, callable $operation): Entry
    {
        $entry = $this->imageEntry($resource, $path);
        $destination = tempnam(sys_get_temp_dir(), 'sofinder-image-output-');
        if ($destination === false) {
            throw new SoFinderException('Unable to create an image work file.', 'image_processing_failed', 500);
        }
        try {
            $this->process($resource, $path, $operation, $destination);
            $stream = fopen($destination, 'rb');
            if ($stream === false) {
                throw new SoFinderException('Unable to read the processed image.', 'image_processing_failed', 500);
            }
            try {
                $directory = dirname($entry->path);

                return $this->files->upload($resource, $directory === '.' ? '' : $directory, $entry->name, (int) filesize($destination), $stream, true);
            } finally {
                fclose($stream);
            }
        } finally {
            @unlink($destination);
        }
    }

    /** @param callable(ImageProcessorInterface, string, string):void $operation */
    private function process(string $resource, string $path, callable $operation, string $destination): void
    {
        $this->withSource($resource, $path, function (string $source) use ($operation, $destination): void {
            $operation($this->processor, $source, $destination);
        });
    }

    /**
     * @template T
     * @param \Closure(string): T $operation
     * @return T
     */
    private function withSource(string $resource, string $path, \Closure $operation): mixed
    {
        $source = tempnam(sys_get_temp_dir(), 'sofinder-image-source-');
        if ($source === false) {
            throw new SoFinderException('Unable to create an image work file.', 'image_processing_failed', 500);
        }
        $input = null;
        try {
            $input = $this->files->read($resource, $path);
            $output = fopen($source, 'wb');
            if ($output === false) {
                throw new SoFinderException('Unable to prepare the image.', 'image_processing_failed', 500);
            }
            try {
                if (stream_copy_to_stream($input, $output) === false) {
                    throw new SoFinderException('Unable to prepare the image.', 'image_processing_failed', 500);
                }
            } finally {
                fclose($output);
            }
            return $operation($source);
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
            @unlink($source);
        }
    }

    private function copySource(string $resource, string $path, string $destination): void
    {
        $input = $this->files->read($resource, $path);
        $output = @fopen($destination, 'wb');
        if ($output === false) {
            fclose($input);
            throw new SoFinderException('Unable to prepare the image.', 'image_processing_failed', 500);
        }
        try {
            if (stream_copy_to_stream($input, $output) === false) {
                throw new SoFinderException('Unable to prepare the image.', 'image_processing_failed', 500);
            }
        } finally {
            fclose($input);
            fclose($output);
        }
    }

    private function suggestCopyName(string $resource, string $directory, Entry $entry, ?string $extension = null): string
    {
        $extension ??= $this->outputExtension($entry);
        $originalExtension = (string) pathinfo($entry->name, PATHINFO_EXTENSION);
        $stem = $originalExtension === '' ? $entry->name : substr($entry->name, 0, -(strlen($originalExtension) + 1));
        for ($index = 0; $index <= 999; ++$index) {
            $candidate = $stem . '-edited' . ($index === 0 ? '' : '-' . $index) . ($extension === '' ? '' : '.' . $extension);
            $path = ($directory === '' ? '' : $directory . '/') . $candidate;
            try {
                $this->files->entry($resource, $path);
            } catch (\SohoPHP\SoFinder\Exception\NotFoundException) {
                return $candidate;
            }
        }

        throw new SoFinderException('Unable to find an available edited image name.', 'conflict', 409);
    }

    private function outputExtension(Entry $entry, ?string $mimeType = null): string
    {
        $mimeType ??= $entry->mimeType;
        $extension = (string) pathinfo($entry->name, PATHINFO_EXTENSION);
        $mimeFormat = $mimeType === null ? null : $this->formats->formatForMime($mimeType);
        if ($extension !== '' && $this->formats->formatForExtension(strtolower($extension)) === $mimeFormat) {
            return $extension;
        }

        return $mimeType === null ? '' : (string) $this->formats->preferredExtensionForMime($mimeType);
    }

    private function effectsProcessor(): ImageEffectsProcessorInterface
    {
        if (!$this->processor instanceof ImageEffectsProcessorInterface) {
            throw new SoFinderException('Image optimization and watermarking are unavailable.', 'image_effects_unavailable', 501);
        }

        return $this->processor;
    }

    private function maintainCache(string $directory, int $ttl = 2_592_000): void
    {
        static $maintained = [];
        if (isset($maintained[$directory])) {
            return;
        }
        $maintained[$directory] = true;
        $files = [];
        $expiry = time() - $ttl;
        foreach (new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO) as $file) {
            if (!$file instanceof \SplFileInfo) {
                continue;
            }
            if (!$file->isFile() || $file->isLink()) {
                continue;
            }
            if ($file->getMTime() < $expiry) {
                @unlink($file->getPathname());
                continue;
            }
            $files[] = [$file->getMTime(), $file->getPathname()];
        }
        if (count($files) <= 5_000) {
            return;
        }
        usort($files, static fn (array $left, array $right): int => $left[0] <=> $right[0]);
        foreach (array_slice($files, 0, count($files) - 5_000) as [, $file]) {
            @unlink($file);
        }
    }
}
