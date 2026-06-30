<?php

declare(strict_types=1);

namespace Lsr\Inertia\Data;

final class LazyProp implements InertiaPropInterface
{
    /** @var callable */
    private $callback;

    public function __construct(
        callable $callback,
    ) {
        $this->callback = $callback;
    }

    public function __invoke(): mixed {
        return $this->resolve();
    }

    public function resolve(): mixed {
        return call_user_func($this->callback);
    }
}
