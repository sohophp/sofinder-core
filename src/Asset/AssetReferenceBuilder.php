<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Asset;

use SohoPHP\SoFinder\Contract\AssetCatalogInterface;
use SohoPHP\SoFinder\Contract\EndpointUrlGeneratorInterface;
use SohoPHP\SoFinder\Image\ImageManager;
use SohoPHP\SoFinder\Value\Entry;
use SohoPHP\SoFinder\Value\RequestContext;
use SohoPHP\SoFinder\Workspace\WorkspaceProvider;

final readonly class AssetReferenceBuilder
{
    /** @param list<int> $variantWidths
     * @param list<string> $variantFormats
     */
    public function __construct(
        private EndpointUrlGeneratorInterface $urls,
        private WorkspaceProvider $workspaces,
        private ?AssetCatalogInterface $catalog = null,
        private ?ImageManager $images = null,
        private bool $catalogEnabled = false,
        private bool $variantsEnabled = false,
        private array $variantWidths = [],
        private array $variantFormats = [],
    ) {
    }

    /** @param array{width:int,height:int}|null $dimensions
     * @return array<string,mixed>
     */
    public function create(string $resource, Entry $entry, ?array $dimensions = null, ?RequestContext $context = null): array
    {
        if ($entry->directory) {
            throw new \InvalidArgumentException('Asset references can only be created for files.');
        }
        $workspace = $this->workspaces->assertResource($resource, $context);
        $record = $this->catalogEnabled ? $this->catalog?->register($workspace->id, $resource, $entry) : null;
        if ($dimensions === null && $entry->mimeType !== null && str_starts_with($entry->mimeType, 'image/') && $this->images !== null) {
            try {
                $dimensions = $this->images->info($resource, $entry->path);
            } catch (\Throwable) {
                $dimensions = null;
            }
        }
        $embeddable = $entry->url !== null && $entry->url !== '';
        $capabilities = $entry->capabilities + [];
        $capabilities['embeddable'] = $embeddable;
        $capabilities['responsiveImages'] = $embeddable && $this->variantsEnabled && $dimensions !== null && $entry->mimeType !== null && str_starts_with($entry->mimeType, 'image/');
        $capabilities['assetMetadata'] = $record !== null && ($entry->capabilities['metadata.update'] ?? false);
        $variants = [];
        if ($embeddable && $this->variantsEnabled && $dimensions !== null && $entry->mimeType !== null && str_starts_with($entry->mimeType, 'image/')) {
            $format = $this->images?->preferredVariantFormat((string) $entry->mimeType, $this->variantFormats) ?? 'original';
            $mime = $format === 'original' ? $entry->mimeType : 'image/' . $format;
            foreach ($this->variantWidths as $width) {
                if ($width > (int) $dimensions['width']) {
                    continue;
                }
                $height = max(1, (int) round((int) $dimensions['height'] * ($width / (int) $dimensions['width'])));
                $variants[] = [
                    'width' => $width,
                    'height' => $height,
                    'mimeType' => $mime,
                    'url' => $this->urls->generate('sofinder_image_variant', ['resource' => $resource, 'path' => $entry->path, 'width' => $width, 'format' => $format, 'v' => $entry->modifiedAt . '-' . $entry->size]),
                ];
            }
        }

        return [
            'schemaVersion' => '1.0', 'assetId' => $record?->id, 'resource' => $resource,
            'path' => $entry->path, 'name' => $entry->name, 'directory' => false,
            'mimeType' => $entry->mimeType, 'size' => $entry->size, 'modifiedAt' => $entry->modifiedAt,
            'version' => $entry->modifiedAt . '-' . $entry->size, 'url' => $entry->url ?? '',
            'downloadUrl' => $this->urls->generate('sofinder_api_download', ['resource' => $resource, 'path' => $entry->path]),
            'width' => isset($dimensions['width']) ? (int) $dimensions['width'] : null,
            'height' => isset($dimensions['height']) ? (int) $dimensions['height'] : null,
            'alt' => $record?->alt, 'altTranslations' => $record->altTranslations ?? [], 'variants' => $variants, 'capabilities' => $capabilities,
        ];
    }
}
