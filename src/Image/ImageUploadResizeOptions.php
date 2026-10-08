<?php
declare(strict_types=1);

namespace SohoPHP\SoFinder\Image;

/** Opt-in upload transformation policy; disabled unless configured by the host. */
final class ImageUploadResizeOptions
{
    /** @var list<string> */ public readonly array $formats;
    /** @var positive-int */ public readonly int $maxWidth;
    /** @var positive-int */ public readonly int $maxHeight;
    public readonly int $quality;
    public readonly string $otherFormats;

    /** @param array<string,mixed> $configuration */
    public function __construct(array $configuration = [])
    {
        $defaults = ['formats' => ['jpeg', 'png', 'gif', 'webp', 'avif', 'bmp'],
            'max_width' => 4096, 'max_height' => 4096, 'quality' => 88, 'other_formats' => 'reject'];
        if (array_diff_key($configuration, $defaults) !== []) throw new \InvalidArgumentException('Unknown image upload resize option.');
        $c = array_replace($defaults, $configuration);
        $this->formats = ImageActionOptions::names($c['formats'], array_keys((new ImageFormatRegistry())->definitions()));
        $this->maxWidth = ImageActionOptions::positive($c['max_width'], 2147483647);
        $this->maxHeight = ImageActionOptions::positive($c['max_height'], 2147483647);
        $this->quality = ImageActionOptions::positive($c['quality'], 100);
        if (!in_array($c['other_formats'], ['preserve', 'reject'], true)) throw new \InvalidArgumentException('Invalid image upload resize fallback.');
        $this->otherFormats = $c['other_formats'];
    }
}
