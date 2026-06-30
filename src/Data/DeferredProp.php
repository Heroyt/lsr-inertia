<?php

declare(strict_types=1);

namespace Lsr\Inertia\Data;

final class DeferredProp implements InertiaPropInterface
{
    /**
     * @param non-empty-string $group
     */
    public function __construct(
        private readonly mixed $value,
        private readonly string $group = 'default',
        private readonly bool $rescue = false,
    ) {
    }

    /**
     * @return non-empty-string
     */
    public function getGroup(): string {
        return $this->group;
    }

    public function shouldRescue(): bool {
        return $this->rescue;
    }

    public function resolve(): mixed {
        return is_callable($this->value) ? call_user_func($this->value) : $this->value;
    }
}
