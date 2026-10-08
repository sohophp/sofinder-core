<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Contract;

/** Host-owned upload policy applied to the runtime's default inspector. */
interface FileInspectorDecoratorInterface
{
    public function decorate(FileInspectorInterface $inspector, ImageProcessorInterface $images): FileInspectorInterface;
}
