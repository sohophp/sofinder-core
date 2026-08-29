<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Value;

final readonly class SecurityAuditResult
{
    /** @param list<array{severity:string,scope:string,message:string}> $findings */
    public function __construct(public array $findings)
    {
    }

    public function criticalCount(): int
    {
        return count(array_filter(
            $this->findings,
            static fn (array $finding): bool => $finding['severity'] === 'critical',
        ));
    }

    public function warningCount(): int
    {
        return count($this->findings) - $this->criticalCount();
    }

    public function status(): string
    {
        if ($this->criticalCount() > 0) {
            return 'critical';
        }

        return $this->findings === [] ? 'ready' : 'warning';
    }

    /** @return array{status:string,critical:int,warnings:int,findings:list<array{severity:string,scope:string,message:string}>} */
    public function toArray(): array
    {
        return [
            'status' => $this->status(),
            'critical' => $this->criticalCount(),
            'warnings' => $this->warningCount(),
            'findings' => $this->findings,
        ];
    }
}
