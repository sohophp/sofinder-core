<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Feature;

use SohoPHP\SoFinder\Exception\SoFinderException;

/** Host-controlled upper bound for optional browser and HTTP capabilities. */
final class FeaturePolicy
{
    private const FEATURES = [
        'folder_tree', 'recent', 'favorites', 'quick_access', 'quick_access_files', 'tags', 'archive', 'trash',
        'batch_rename', 'image_editing', 'image_processing', 'document_preview', 'security_status',
        'folder_upload', 'text_preview', 'checksum', 'qr_code',
    ];

    /** @param array<string,bool> $features */
    public function __construct(private readonly array $features = [])
    {
    }

    public function enabled(string $feature): bool
    {
        if (!in_array($feature, self::FEATURES, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown SoFinder feature "%s".', $feature));
        }

        return $this->features[$feature] ?? true;
    }

    public function assertEnabled(string $feature): void
    {
        if (!$this->enabled($feature)) {
            throw new SoFinderException('This SoFinder feature is disabled by the host application.', 'feature_disabled', 404);
        }
    }

    /** @return array{folderTree:bool,recent:bool,favorites:bool,quickAccess:bool,quickAccessFiles:bool,tags:bool,archive:bool,trash:bool,batchRename:bool,imageEditing:bool,imageProcessing:bool,documentPreview:bool,securityStatus:bool,folderUpload:bool,textPreview:bool,checksum:bool,qrCode:bool} */
    public function browserAvailability(): array
    {
        return [
            'folderTree' => $this->enabled('folder_tree'),
            'recent' => $this->enabled('recent'),
            'favorites' => $this->enabled('favorites'),
            'quickAccess' => $this->enabled('quick_access'),
            'quickAccessFiles' => false,
            'tags' => $this->enabled('tags'),
            'archive' => $this->enabled('archive'),
            'trash' => $this->enabled('trash'),
            'batchRename' => $this->enabled('batch_rename'),
            'imageEditing' => $this->enabled('image_editing'),
            'imageProcessing' => $this->enabled('image_processing'),
            'documentPreview' => $this->enabled('document_preview'),
            'securityStatus' => $this->enabled('security_status'),
            'folderUpload' => $this->enabled('folder_upload'),
            'textPreview' => $this->enabled('text_preview'),
            'checksum' => $this->enabled('checksum'),
            'qrCode' => $this->enabled('qr_code'),
        ];
    }
}
