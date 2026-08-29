<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Maintenance;

enum MaintenanceTask: string
{
    case Uploads = 'uploads';
    case Trash = 'trash';
    case Usage = 'usage';
}
