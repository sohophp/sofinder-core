<?php
declare(strict_types=1);

namespace SohoPHP\SoFinder\Security;

use SohoPHP\SoFinder\Contract\FileInspectorInterface;
use SohoPHP\SoFinder\Contract\ImageProcessorInterface;
use SohoPHP\SoFinder\Exception\SoFinderException;
use SohoPHP\SoFinder\Image\ImageFormatRegistry;
use SohoPHP\SoFinder\Image\ImageUploadResizeOptions;
use SohoPHP\SoFinder\Value\InspectedFile;
use SohoPHP\SoFinder\Value\ResourceType;

/** Opt-in upload resizing inside the shared private quarantine. */
final class ResizingFileInspector implements FileInspectorInterface
{
    public function __construct(private readonly FileInspectorInterface $inspector,
        private readonly ImageProcessorInterface $images, private readonly ImageUploadResizeOptions $options) {}

    public function inspect(string $path, string $fileName, ResourceType $resource): InspectedFile
    {
        $reason = null;
        try { $inspection = $this->inspector->inspect($path, $fileName, $resource); }
        catch (SoFinderException $reason) {
            if ($reason->errorCode !== 'image_too_large') throw $reason;
            // Validate an oversized original without relaxing size, MIME, pixels or animation.
            $source = get_object_vars($resource);
            $source['maxImageWidth'] = ResourceType::MAX_IMAGE_DIMENSION;
            $source['maxImageHeight'] = ResourceType::MAX_IMAGE_DIMENSION;
            $inspection = $this->inspector->inspect($path, $fileName, new ResourceType(...$source));
        }
        $oversized = $inspection->imageWidth !== null && $inspection->imageHeight !== null
            && ($inspection->imageWidth > $this->options->maxWidth || $inspection->imageHeight > $this->options->maxHeight);
        if (!in_array((new ImageFormatRegistry())->formatForExtension(pathinfo($fileName, PATHINFO_EXTENSION)), $this->options->formats, true)) {
            if ($this->options->otherFormats === 'preserve' || (!$oversized && $reason === null)) return $inspection;
            throw $reason ?? new SoFinderException('The image exceeds the upload resize policy.', 'image_too_large', 413);
        }
        if (!$oversized) {
            if ($reason !== null) throw $reason;
            return $inspection;
        }
        if ($this->images->isAnimated($path)
            && !($this->images instanceof \SohoPHP\SoFinder\Contract\ImageActionProcessorInterface && $this->images->allowsAnimatedEditing($path))) {
            throw new SoFinderException('Animated upload resizing requires explicit processor consent.', 'animated_resize_unverified', 415);
        }
        $temporary = tempnam(dirname($path), '.sofinder-image-');
        if ($temporary === false) throw new SoFinderException('Cannot prepare the image upload.', 'image_unavailable', 503);
        try {
            chmod($temporary, 0600);
            $resize = $this->images;
            if (!$resize->supports($inspection->mimeType)) {
                throw new SoFinderException('The image processor cannot resize this format.', 'image_format_unverified', 415);
            }
            $resize->transform($path, $temporary, 0, $this->options->maxWidth, $this->options->maxHeight, $this->options->quality);
            $result = $this->inspector->inspect($temporary, $fileName, $resource);
            if (!rename($temporary, $path)) throw new SoFinderException('Cannot stage the image upload.', 'image_unavailable', 503);
            clearstatcache(true, $path);
            return $result;
        } finally {
            if (is_file($temporary)) unlink($temporary);
        }
    }
}
