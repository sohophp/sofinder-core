<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Contract;

use SohoPHP\SoFinder\Value\AssetRecord;

/** Optional additive capability for catalogs that persist language-specific alt text. */
interface LocalizedAssetMetadataCatalogInterface
{
    /**
     * @param list<string> $tags
     * @param array<string,string> $altTranslations
     */
    public function updateLocalizedMetadata(string $assetId, ?string $alt, ?string $title, array $tags, int $expectedVersion, array $altTranslations): AssetRecord;
}
