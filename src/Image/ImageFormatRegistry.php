<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Image;

final class ImageFormatRegistry
{
    /**
     * @var array<string, array{extensions:list<string>,mimes:list<string>,coder:string,web:bool,editable:bool}>
     */
    private const FORMATS = [
        'avif' => ['extensions' => ['avif'], 'mimes' => ['image/avif'], 'coder' => 'AVIF', 'web' => true, 'editable' => true],
        'bmp' => ['extensions' => ['bmp'], 'mimes' => ['image/bmp', 'image/x-bmp'], 'coder' => 'BMP', 'web' => true, 'editable' => true],
        'gif' => ['extensions' => ['gif'], 'mimes' => ['image/gif'], 'coder' => 'GIF', 'web' => true, 'editable' => true],
        'ico' => ['extensions' => ['ico'], 'mimes' => ['image/vnd.microsoft.icon', 'image/x-icon'], 'coder' => 'ICO', 'web' => true, 'editable' => true],
        'jpeg' => ['extensions' => ['jpg', 'jpeg'], 'mimes' => ['image/jpeg'], 'coder' => 'JPEG', 'web' => true, 'editable' => true],
        'png' => ['extensions' => ['png'], 'mimes' => ['image/png'], 'coder' => 'PNG', 'web' => true, 'editable' => true],
        'webp' => ['extensions' => ['webp'], 'mimes' => ['image/webp'], 'coder' => 'WEBP', 'web' => true, 'editable' => true],
    ];

    public function formatForExtension(string $extension): ?string
    {
        $extension = strtolower(ltrim($extension, '.'));
        foreach (self::FORMATS as $format => $definition) {
            if (in_array($extension, $definition['extensions'], true)) {
                return $format;
            }
        }

        return null;
    }

    public function formatForMime(string $mime): ?string
    {
        $mime = strtolower(trim($mime));
        foreach (self::FORMATS as $format => $definition) {
            if (in_array($mime, $definition['mimes'], true)) {
                return $format;
            }
        }

        return null;
    }

    public function preferredExtensionForMime(string $mime): ?string
    {
        $format = $this->formatForMime($mime);

        return $format === null ? null : (self::FORMATS[$format]['extensions'][0] ?? null);
    }

    public function detectFormat(string $path): ?string
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);

        return is_string($mime) ? $this->formatForMime($mime) : null;
    }

    public function canonicalMime(string $format): ?string
    {
        return self::FORMATS[$format]['mimes'][0] ?? null;
    }

    /** @return list<string> */
    public function extensionsForMime(string $mime): array
    {
        $format = $this->formatForMime($mime);

        return $format === null ? [] : self::FORMATS[$format]['extensions'];
    }

    public function coder(string $format): ?string
    {
        return self::FORMATS[$format]['coder'] ?? null;
    }

    public function mimeMatches(string $format, string $mime): bool
    {
        return in_array(strtolower($mime), self::FORMATS[$format]['mimes'] ?? [], true);
    }

    public function isWebEmbeddableMime(string $mime): bool
    {
        return $this->formatForMime($mime) !== null;
    }

    /** @return array<string, array{extensions:list<string>,mimes:list<string>,coder:string,web:bool,editable:bool}> */
    public function definitions(): array
    {
        return self::FORMATS;
    }
}
