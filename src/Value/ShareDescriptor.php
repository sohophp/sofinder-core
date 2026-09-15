<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Value;

final readonly class ShareDescriptor implements \JsonSerializable
{
    public function __construct(
        public string $url,
        public string $access = 'public',
        public ?int $expiresAt = null,
        public bool $qrCode = true,
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
