<?php

declare(strict_types=1);

namespace Tests\Services;

use Lsr\Inertia\Lifecycle\InertiaLifecycleScopeInterface;
use Throwable;

final readonly class RecordingInertiaLifecycleScope implements InertiaLifecycleScopeInterface
{
    public function __construct(private RecordingInertiaLifecycleHook $hook) {
    }

    public function recordException(Throwable $exception): void {
        $this->hook->exceptions[] = $exception;
    }

    public function complete(int $resolvedPropCount, int $deferredPropCount, int $rescuedPropCount): void {
        $this->hook->completions[] = compact(
            'resolvedPropCount',
            'deferredPropCount',
            'rescuedPropCount',
        );
    }
}
