<?php

declare(strict_types=1);

namespace Lsr\Inertia\Lifecycle;

interface InertiaLifecycleHookInterface
{
    public function begin(
        string $component,
        int $inputPropCount,
        bool $inertiaRequest,
    ): InertiaLifecycleScopeInterface;
}
