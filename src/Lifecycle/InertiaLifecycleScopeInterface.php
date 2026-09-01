<?php

declare(strict_types=1);

namespace Lsr\Inertia\Lifecycle;

use Throwable;

interface InertiaLifecycleScopeInterface
{
    public function recordException(Throwable $exception): void;

    public function complete(int $resolvedPropCount, int $deferredPropCount, int $rescuedPropCount): void;
}
