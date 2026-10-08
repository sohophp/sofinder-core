<?php
declare(strict_types=1);

namespace SohoPHP\SoFinder\Image;

use SohoPHP\SoFinder\Exception\SoFinderException;

final class ImageOrientation
{
    private static function value(string $source, string $mime, bool $enabled): int
    {
        if (!$enabled || $mime !== 'image/jpeg' || !function_exists('exif_read_data')) return 1;
        $exif = @exif_read_data($source);
        return is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;
    }

    /** @return array{width:int,height:int} */
    public static function dimensions(int $width, int $height, string $source, string $mime, bool $enabled = true): array
    {
        if (in_array(self::value($source, $mime, $enabled), [5, 6, 7, 8], true)) [$width, $height] = [$height, $width];
        return ['width' => $width, 'height' => $height];
    }

    public static function apply(\GdImage $image, string $source, string $mime, bool $enabled = true): \GdImage
    {
        $orientation = self::value($source, $mime, $enabled);
        if (in_array($orientation, [2, 4, 5, 7], true)) imageflip($image, in_array($orientation, [2, 5], true) ? IMG_FLIP_HORIZONTAL : IMG_FLIP_VERTICAL);
        $angle = match ($orientation) { 3 => 180, 5, 6 => -90, 7, 8 => 90, default => 0 };
        if ($angle === 0) return $image;
        $result = imagerotate($image, $angle, 0);
        if (!$result instanceof \GdImage) throw new SoFinderException('Unable to apply image orientation.', 'image_processing_failed', 500);
        return $result;
    }
}
