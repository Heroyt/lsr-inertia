<?php

declare(strict_types=1);

namespace Lsr\Inertia\Data;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;

final class OnceProp implements InertiaPropInterface
{
    private bool $fresh = false;
    private ?int $expiresAt = null;

    public function __construct(
        private readonly mixed $value,
        private readonly ?string $key = null,
    ) {
    }

    public function fresh(bool $fresh = true): self {
        $this->fresh = $fresh;

        return $this;
    }

    public function until(DateTimeInterface|DateInterval|int|null $expiresAt): self {
        $this->expiresAt = match (true) {
            $expiresAt instanceof DateTimeInterface => $this->toMilliseconds($expiresAt),
            $expiresAt instanceof DateInterval => $this->toMilliseconds((new DateTimeImmutable())->add($expiresAt)),
            is_int($expiresAt) => $this->toMilliseconds(
                (new DateTimeImmutable())->modify('+' . $expiresAt . ' seconds'),
            ),
            default => null,
        };

        return $this;
    }

    public function getKey(string $prop): string {
        return $this->key ?? $prop;
    }

    public function shouldFresh(): bool {
        return $this->fresh;
    }

    public function isExpired(): bool {
        return $this->expiresAt !== null && $this->expiresAt <= $this->toMilliseconds(new DateTimeImmutable());
    }

    public function getExpiresAt(): ?int {
        return $this->expiresAt;
    }

    public function resolve(): mixed {
        return is_callable($this->value) ? call_user_func($this->value) : $this->value;
    }

    private function toMilliseconds(DateTimeInterface $dateTime): int {
        return ((int) $dateTime->format('U')) * 1000 + ((int) $dateTime->format('v'));
    }
}
