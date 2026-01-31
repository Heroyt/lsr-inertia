<?php
declare(strict_types=1);

namespace Lsr\Inertia\Data;

final class LazyProp
{

    /** @var callable */
    private $callback;

    public function __construct(
        callable $callback,
    )
    {
        $this->callback = $callback;
    }

    public function __invoke(): mixed
    {
        return call_user_func($this->callback);
    }

}