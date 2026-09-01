<?php

declare(strict_types=1);

namespace Tests\Services;

use Lsr\Inertia\Lifecycle\InertiaLifecycleHookInterface;
use Lsr\Inertia\Lifecycle\InertiaLifecycleScopeInterface;
use Throwable;

final class RecordingInertiaLifecycleHook implements InertiaLifecycleHookInterface
{
    /** @var list<array{component: string, inputPropCount: int, inertiaRequest: bool}> */
    public array $begins = [];
    /** @var list<array{resolvedPropCount: int, deferredPropCount: int, rescuedPropCount: int}> */
    public array $completions = [];
    /** @var list<Throwable> */
    public array $exceptions = [];

    public function begin(
        string $component,
        int $inputPropCount,
        bool $inertiaRequest,
    ): InertiaLifecycleScopeInterface {
        $this->begins[] = compact('component', 'inputPropCount', 'inertiaRequest');
        return new RecordingInertiaLifecycleScope($this);
    }
}
