<?php
declare(strict_types=1);

namespace SohoPHP\SoFinder\Image;

/** Framework-independent configuration for the basic GD action pipeline. */
final class ImageActionOptions
{
    /** @var list<string> */ public readonly array $formats;
    /** @var list<string> */ public readonly array $editableFormats;
    /** @var list<string> */ public readonly array $actions;
    public readonly bool $autoOrientation;
    public readonly string $animatedEditing;
    /** @var positive-int */ public readonly int $maxWidth;
    /** @var positive-int */ public readonly int $maxHeight;
    public readonly int $quality;
    public readonly int $resizeQuality;

    /** @param array<string,mixed> $configuration */
    public function __construct(array $configuration = [])
    {
        $defaults = ['formats' => ['jpeg', 'png', 'gif', 'webp', 'avif', 'bmp'], 'editable_formats' => null,
            'actions' => ['crop', 'rotate', 'resize'], 'auto_orientation' => true, 'animated_editing' => 'reject',
            'max_width' => 4096, 'max_height' => 4096, 'quality' => 88, 'resize_quality' => 88];
        if (array_diff_key($configuration, $defaults) !== []) throw new \InvalidArgumentException('Unknown image action pipeline option.');
        $c = array_replace($defaults, $configuration);
        $known = ['jpeg', 'png', 'gif', 'webp', 'avif', 'bmp'];
        $this->formats = self::names($c['formats'], $known);
        if ($this->formats === []) throw new \InvalidArgumentException('Image action formats cannot be empty.');
        $this->editableFormats = self::names($c['editable_formats'] ?? $this->formats, $this->formats);
        $this->actions = self::names($c['actions'], ['crop', 'rotate', 'resize']);
        if (!is_bool($c['auto_orientation']) || !in_array($c['animated_editing'], ['reject', 'first_frame'], true)) {
            throw new \InvalidArgumentException('Invalid image orientation or animation policy.');
        }
        $this->autoOrientation = $c['auto_orientation'];
        $this->animatedEditing = $c['animated_editing'];
        $this->maxWidth = self::positive($c['max_width'], 2147483647);
        $this->maxHeight = self::positive($c['max_height'], 2147483647);
        $this->quality = self::positive($c['quality'], 100);
        $this->resizeQuality = self::positive($c['resize_quality'], 100);
    }

    /**
     * @param list<string> $allowed
     * @return list<string>
     */
    public static function names(mixed $value, array $allowed): array
    {
        if (!is_array($value) || !array_is_list($value)) throw new \InvalidArgumentException('Image policy lists must be arrays of names.');
        $result = [];
        foreach ($value as $name) {
            if (!is_string($name) || !in_array($name, $allowed, true) || in_array($name, $result, true)) throw new \InvalidArgumentException('Unknown or duplicate image policy name.');
            $result[] = $name;
        }
        return $result;
    }

    /** @return positive-int */
    public static function positive(mixed $value, int $maximum): int
    {
        if (!is_int($value) || $value < 1 || $value > $maximum) throw new \InvalidArgumentException('Image policy value is out of range.');
        return $value;
    }
}
