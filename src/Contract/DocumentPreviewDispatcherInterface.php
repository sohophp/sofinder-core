<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Contract;

use SohoPHP\SoFinder\Preview\DocumentPreviewMessage;

interface DocumentPreviewDispatcherInterface
{
    public function available(): bool;

    public function dispatch(DocumentPreviewMessage $message): void;
}
