<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Image;

/** Resolves selectable CJK watermark fonts without storing them in the application tree. */
final class WatermarkFontResolver
{
    public const DEFAULT_FONT = 'interface';
    private const MAXIMUM_BYTES = 30_000_000;

    /** @var array<string, array{url:string,hash:string,size:int,file:string,system:list<string>}> */
    private const FONTS = [
        'interface' => [
            'url' => 'https://raw.githubusercontent.com/notofonts/noto-cjk/Sans2.004/Sans/OTF/SimplifiedChinese/NotoSansCJKsc-Bold.otf',
            'hash' => 'b5f0d1a190a7f9b43c310a8850630af12553df32c4c050543f9059732d9b4c0a',
            'size' => 17_002_248,
            'file' => 'NotoSansCJKsc-Bold.otf',
            'system' => [
                '/usr/share/fonts/opentype/noto/NotoSansCJK-Bold.ttc',
                '/usr/share/fonts/opentype/noto/NotoSansCJKsc-Bold.otf',
                '/usr/share/fonts/truetype/noto/NotoSansCJK-Bold.ttc',
                '/usr/share/fonts/noto-cjk/NotoSansCJK-Bold.ttc',
                '/System/Library/Fonts/PingFang.ttc',
                'C:\\Windows\\Fonts\\msyhbd.ttc',
            ],
        ],
        'sans' => [
            'url' => 'https://raw.githubusercontent.com/notofonts/noto-cjk/Sans2.004/Sans/OTF/SimplifiedChinese/NotoSansCJKsc-Regular.otf',
            'hash' => '2c76254f6fc379fddfce0a7e84fb5385bb135d3e399294f6eeb6680d0365b74b',
            'size' => 16_437_364,
            'file' => 'NotoSansCJKsc-Regular.otf',
            'system' => [
                '/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc',
                '/usr/share/fonts/opentype/noto/NotoSansCJKsc-Regular.otf',
                '/usr/share/fonts/truetype/noto/NotoSansCJK-Regular.ttc',
                '/usr/share/fonts/noto-cjk/NotoSansCJK-Regular.ttc',
                '/System/Library/Fonts/PingFang.ttc',
                'C:\\Windows\\Fonts\\msyh.ttc',
            ],
        ],
        'serif' => [
            'url' => 'https://raw.githubusercontent.com/notofonts/noto-cjk/Serif2.003/Serif/OTF/SimplifiedChinese/NotoSerifCJKsc-SemiBold.otf',
            'hash' => 'd627b53dbcde61e07de1498d2623a8b287f78585ffbc90cc0618d0caaa2ed6b0',
            'size' => 24_700_256,
            'file' => 'NotoSerifCJKsc-SemiBold.otf',
            'system' => [
                '/usr/share/fonts/opentype/noto/NotoSerifCJK-SemiBold.ttc',
                '/usr/share/fonts/opentype/noto/NotoSerifCJKsc-SemiBold.otf',
                '/usr/share/fonts/truetype/noto/NotoSerifCJK-SemiBold.ttc',
                '/System/Library/Fonts/Songti.ttc',
                'C:\\Windows\\Fonts\\simsun.ttc',
            ],
        ],
    ];

    /** @var array<string, string|null> */
    private array $resolved = [];

    /** @param array<string, list<string>> $systemFonts */
    public function __construct(
        private readonly ?string $configuredFont,
        private readonly string $cacheDirectory,
        private readonly bool $autoDownload = true,
        private readonly ?\Closure $downloader = null,
        private readonly array $systemFonts = [],
    ) {
    }

    public function resolve(string $font = self::DEFAULT_FONT): ?string
    {
        $font = array_key_exists($font, self::FONTS) ? $font : self::DEFAULT_FONT;
        if (array_key_exists($font, $this->resolved)) {
            return $this->resolved[$font];
        }
        $definition = self::FONTS[$font];
        $configured = $font === self::DEFAULT_FONT ? $this->configuredFont : null;
        $system = $this->systemFonts[$font] ?? $definition['system'];
        foreach (array_filter([$configured, ...$system]) as $candidate) {
            if (is_string($candidate) && is_file($candidate) && is_readable($candidate)) {
                return $this->resolved[$font] = $candidate;
            }
        }
        if (!$this->autoDownload) {
            return $this->resolved[$font] = null;
        }

        $directory = rtrim($this->cacheDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'fonts';
        $destination = $directory . DIRECTORY_SEPARATOR . $definition['file'];
        if ($this->validCachedFont($destination, $definition)) {
            return $this->resolved[$font] = $destination;
        }
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return null;
        }
        $lock = @fopen($directory . DIRECTORY_SEPARATOR . '.watermark-font-' . $font . '.lock', 'c+');
        if (!is_resource($lock)) {
            return null;
        }
        try {
            if (!flock($lock, LOCK_EX)) {
                return null;
            }
            if ($this->validCachedFont($destination, $definition)) {
                return $this->resolved[$font] = $destination;
            }
            $contents = $this->downloader !== null ? ($this->downloader)($definition['url'], self::MAXIMUM_BYTES) : $this->download($definition['url']);
            if (!is_string($contents) || !$this->validDownload($contents, $definition)) {
                return null;
            }
            $temporary = tempnam($directory, '.font-');
            if ($temporary === false) {
                return null;
            }
            try {
                if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)) {
                    return null;
                }
                @chmod($temporary, 0664);
                if (is_file($destination) && !@unlink($destination)) {
                    return null;
                }
                if (!@rename($temporary, $destination)) {
                    return null;
                }
            } finally {
                if (is_file($temporary)) {
                    @unlink($temporary);
                }
            }

            return $this->resolved[$font] = $destination;
        } catch (\Throwable) {
            return null;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function download(string $url): ?string
    {
        if (function_exists('curl_init')) {
            $contents = '';
            $curl = curl_init($url);
            if ($curl === false) {
                return null;
            }
            curl_setopt_array($curl, [
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_TIMEOUT => 25,
                CURLOPT_USERAGENT => 'SoFinder-watermark-font/1.0',
                CURLOPT_WRITEFUNCTION => static function (mixed $handle, string $chunk) use (&$contents): int {
                    if (strlen($contents) + strlen($chunk) > self::MAXIMUM_BYTES) {
                        return 0;
                    }
                    $contents .= $chunk;

                    return strlen($chunk);
                },
            ]);
            try {
                if (curl_exec($curl) !== true || curl_getinfo($curl, CURLINFO_RESPONSE_CODE) !== 200) {
                    return null;
                }
            } finally {
                curl_close($curl);
            }

            return $contents;
        }

        $context = stream_context_create(['http' => [
            'follow_location' => 0,
            'ignore_errors' => false,
            'timeout' => 20,
            'user_agent' => 'SoFinder-watermark-font/1.0',
        ], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $stream = @fopen($url, 'rb', false, $context);
        if (!is_resource($stream)) {
            return null;
        }
        $contents = '';
        try {
            while (!feof($stream)) {
                $chunk = fread($stream, 65_536);
                if ($chunk === false) {
                    return null;
                }
                $contents .= $chunk;
                if (strlen($contents) > self::MAXIMUM_BYTES) {
                    return null;
                }
            }
        } finally {
            fclose($stream);
        }

        return $contents;
    }

    /**
     * @param array{url:string,hash:string,size:int,file:string,system:list<string>} $definition
     * @phpstan-impure The cache may be populated by another process while this request waits for the lock.
     */
    private function validCachedFont(string $path, array $definition): bool
    {
        if (!is_file($path) || !is_readable($path) || filesize($path) !== $definition['size']) {
            return false;
        }

        return hash_file('sha256', $path) === $definition['hash'];
    }

    /** @param array{url:string,hash:string,size:int,file:string,system:list<string>} $definition */
    private function validDownload(string $contents, array $definition): bool
    {
        if (strlen($contents) !== $definition['size'] || strlen($contents) > self::MAXIMUM_BYTES || !in_array(substr($contents, 0, 4), ["OTTO", "\x00\x01\x00\x00", 'ttcf'], true)) {
            return false;
        }

        return hash('sha256', $contents) === $definition['hash'];
    }
}
