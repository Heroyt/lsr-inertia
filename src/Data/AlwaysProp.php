<?php

declare(strict_types=1);

namespace Lsr\Inertia\Data;

final class AlwaysProp implements InertiaPropInterface
{
    public function __construct(
        private readonly mixed $value,
    ) {
    }

    public function resolve(): mixed {
        return is_callable($this->value) ? call_user_func($this->value) : $this->value;
    }
}
