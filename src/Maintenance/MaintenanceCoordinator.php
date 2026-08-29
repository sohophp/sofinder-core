<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Maintenance;

use SohoPHP\SoFinder\Contract\AtomicStateStoreInterface;
use SohoPHP\SoFinder\Contract\MaintenanceDispatcherInterface;

final readonly class MaintenanceCoordinator
{
    public function __construct(
        private string $directory,
        private string $mode,
        private int $minimumInterval,
        private int $maximumItems,
        private MaintenanceRunner $runner,
        private ?MaintenanceDispatcherInterface $dispatcher = null,
        private ?AtomicStateStoreInterface $state = null,
    ) {
    }

    public function trigger(MaintenanceTask $task): void
    {
        if ($this->mode === 'external' || $this->mode === 'disabled' || !$this->claimInterval($task)) return;
        if ($this->mode === 'messenger') {
            if ($this->dispatcher === null) throw new \LogicException('SoFinder messenger maintenance requires a maintenance dispatcher.');
            $this->runner->queued($task);
            $this->dispatcher->dispatch($task);
            return;
        }
        $this->runner->run($task, $this->maximumItems);
    }

    private function claimInterval(MaintenanceTask $task): bool
    {
        if ($this->state !== null) {
            $claimed = false;
            $this->state->mutate('maintenance-interval', $task->value, function (array $state) use (&$claimed): array {
                $now = time();
                if ((int) ($state['claimedAt'] ?? 0) > 0 && $now - (int) $state['claimedAt'] < $this->minimumInterval) return $state;
                $claimed = true;

                return ['claimedAt' => $now];
            });

            return $claimed;
        }
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0770, true) && !is_dir($this->directory)) return false;
        $file = $this->directory . '/' . $task->value . '.interval';
        $handle = @fopen($file, 'c+b');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) fclose($handle);
            return false;
        }
        try {
            $previous = (int) stream_get_contents($handle);
            $now = time();
            if ($previous > 0 && $now - $previous < $this->minimumInterval) return false;
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, (string) $now);
            fflush($handle);
            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
