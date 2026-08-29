<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Value;

final readonly class CapabilityCatalog implements \JsonSerializable
{
    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'apiVersion' => '1.0',
            'entryOperations' => ['read', 'list', 'upload', 'overwrite', 'create_folder', 'rename', 'copy', 'move', 'delete', 'download', 'select'],
            'storageCapabilities' => ['search', 'sort', 'cursorPagination', 'atomicMove', 'nativeCopy', 'recoverableDelete', 'publicUrl'],
            'optionalFeatures' => ['folderTree', 'recent', 'favorites', 'quickAccess', 'quickAccessFiles', 'tags', 'archive', 'trash', 'batchRename', 'imageEditing', 'imageProcessing', 'documentPreview', 'securityStatus', 'folderUpload', 'textPreview', 'checksum'],
            'pluginSlots' => ['utility', 'toolbar', 'context', 'details'],
            'pluginSelections' => ['none', 'any', 'file', 'image'],
            'pickerKinds' => ['any', 'file', 'image'],
            'optionalPluginContracts' => ['assetVersions', 'assetEnrichment'],
        ];
    }
}
