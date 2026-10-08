<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Value;

final class ShareDescriptor implements \JsonSerializable
{
    public function __construct(
        public readonly string $url,
        public readonly string $access = 'public',
        public readonly ?int $expiresAt = null,
        public readonly bool $qrCode = true,
    ) {
        if ($url === '' || !in_array($access, ['public', 'login_required', 'restricted'], true)) {
            throw new \InvalidArgumentException('Share URL and access policy are invalid.');
        }
    }

    /** @return array{url:string,access:string,expiresAt:?int,qrCode:bool} */
    public function jsonSerialize(): array
    {
        return ['url' => $this->url, 'access' => $this->access, 'expiresAt' => $this->expiresAt, 'qrCode' => $this->qrCode];
    }
}
