<?php

declare(strict_types=1);

namespace Lsr\Inertia\Data;

interface InertiaPropInterface
{
    public function resolve(): mixed;
}
