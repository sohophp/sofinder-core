<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Security;

use SohoPHP\SoFinder\Contract\FileInspectorInterface;
use SohoPHP\SoFinder\Contract\ImageProcessorInterface;
use SohoPHP\SoFinder\Exception\SoFinderException;
use SohoPHP\SoFinder\Image\ImageFormatRegistry;
use SohoPHP\SoFinder\Value\InspectedFile;
use SohoPHP\SoFinder\Value\ResourceType;

final readonly class DefaultFileInspector implements FileInspectorInterface
{
    public function __construct(
        private ImageProcessorInterface $images,
        private ImageFormatRegistry $formats = new ImageFormatRegistry(),
    ) {
    }

    public function inspect(string $path, string $fileName, ResourceType $resource): InspectedFile
    {
        $size = filesize($path);
        if ($size === false || $size > $resource->maxSize) {
            throw new SoFinderException('The uploaded file exceeds the configured size limit.', 'file_too_large', 413);
        }
        $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
        $resource->assertMimeAllowed($mimeType);

        $extension = strtolower((string) pathinfo($fileName, PATHINFO_EXTENSION));
        $format = $this->formats->formatForExtension($extension);
        if ($format === null) {
            $this->assertNoActiveContent($path);
            return new InspectedFile((int) $size, $mimeType);
        }
        if (!$this->formats->mimeMatches($format, strtolower($mimeType))) {
            throw new SoFinderException('The image extension does not match its content.', 'invalid_image', 415);
        }
        $headerDimensions = $this->images->dimensions($path);
        $this->assertImageLimits($headerDimensions, $resource);
        $dimensions = $this->images->validate($path);
        $this->assertImageLimits($dimensions, $resource);
        if ($resource->animatedImagePolicy === 'reject' && $this->images->isAnimated($path)) {
            throw new SoFinderException('Animated images are not allowed in this resource.', 'animated_image_not_allowed', 415);
        }

        return new InspectedFile((int) $size, $mimeType, $dimensions['width'], $dimensions['height']);
    }

    /** @param array{width:int,height:int} $dimensions */
    private function assertImageLimits(array $dimensions, ResourceType $resource): void
    {
        if ($dimensions['width'] * $dimensions['height'] > $resource->maxImagePixels || $dimensions['width'] > $resource->maxImageWidth || $dimensions['height'] > $resource->maxImageHeight) {
            throw new SoFinderException('The uploaded image exceeds the configured dimension or pixel limit.', 'image_too_large', 413);
        }
    }

    private function assertNoActiveContent(string $path): void
    {
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            throw new SoFinderException('Unable to inspect the uploaded file.', 'invalid_upload', 400);
        }
        $overlap = '';
        try {
            while (!feof($stream)) {
                $chunk = fread($stream, 65_536);
                if ($chunk === false) {
                    throw new SoFinderException('Unable to inspect the uploaded file.', 'invalid_upload', 400);
                }
                $sample = $overlap . $chunk;
                if (preg_match('/<\?(?:php|=)|<script\b/i', $sample) === 1) {
                    throw new SoFinderException('The uploaded file contains active script content.', 'unsafe_file_content', 415);
                }
                $overlap = substr($sample, -32);
            }
        } finally {
            fclose($stream);
        }
    }
}
