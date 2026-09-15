<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Contract;

use SohoPHP\SoFinder\Value\TrashItem;

/** Allows integrations to retain recycle-bin items required by external records. */
interface TrashPurgeGuardInterface
{
    /** Return null when the item may be purged, otherwise a user-readable reason. */
    public function blockingReason(TrashItem $item): ?string;
}
