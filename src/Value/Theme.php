<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Value;

final readonly class Theme
{
    private const COLOR_KEYS = ['accent', 'background', 'panel', 'text', 'muted', 'danger'];

    /** @param array<string, mixed> $values */
    public function __construct(private array $values)
    {
        foreach (self::COLOR_KEYS as $key) {
            $value = $values[$key] ?? null;
            if (!is_string($value) || preg_match('/^#[0-9a-fA-F]{3}(?:[0-9a-fA-F]{3})?$/', $value) !== 1) {
                throw new \InvalidArgumentException(sprintf('SoFinder theme value "%s" must be a 3 or 6 digit hexadecimal color.', $key));
            }
        }
        $radius = $values['radius'] ?? null;
        if (!is_string($radius) || preg_match('/^(?:0|[1-9]|[12][0-9]|3[0-2])px$/', $radius) !== 1) {
            throw new \InvalidArgumentException('SoFinder theme radius must be between 0px and 32px.');
        }
    }

    /** @return array{accent: string, background: string, panel: string, text: string, muted: string, danger: string, radius: string} */
    public function values(): array
    {
        /** @var array{accent: string, background: string, panel: string, text: string, muted: string, danger: string, radius: string} */
        return $this->values;
    }
}
