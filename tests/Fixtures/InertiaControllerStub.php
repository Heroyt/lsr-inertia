<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Lsr\Inertia\Data\AlwaysProp;
use Lsr\Inertia\Data\DeepMergeProp;
use Lsr\Inertia\Data\DeferredProp;
use Lsr\Inertia\Data\LazyProp;
use Lsr\Inertia\Data\MergeProp;
use Lsr\Inertia\Data\OnceProp;
use Lsr\Inertia\Factory\InertiaFactoryInterface;
use Lsr\Inertia\Http\WithInertia;
use Lsr\Interfaces\TemplateParametersInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class InertiaControllerStub
{
    use WithInertia;

    /**
     * @param array<string, mixed>|TemplateParametersInterface $params
     */
    public function __construct(
        InertiaFactoryInterface $inertiaFactory,
        public ServerRequestInterface $request,
        public array|TemplateParametersInterface $params = [],
    ) {
        $this->inertiaFactory = $inertiaFactory;
    }

    public function render(string $component): ResponseInterface {
        return $this->inertia($component);
    }

    /**
     * @param array<string, mixed>|TemplateParametersInterface $parameters
     */
    public function renderWithParameters(
        string $component,
        array|TemplateParametersInterface $parameters,
    ): ResponseInterface {
        return $this->inertia($component, $parameters);
    }

    public function lazy(callable $callback): LazyProp {
        return $this->inertiaLazy($callback);
    }

    public function always(mixed $value): AlwaysProp {
        return $this->inertiaAlways($value);
    }

    public function defer(callable $callback, string $group = 'default', bool $rescue = false): DeferredProp {
        return $this->inertiaDefer($callback, $group, $rescue);
    }

    public function merge(mixed $value): MergeProp {
        return $this->inertiaMerge($value);
    }

    public function deepMerge(mixed $value): DeepMergeProp {
        return $this->inertiaDeepMerge($value);
    }

    public function once(callable $callback, ?string $key = null): OnceProp {
        return $this->inertiaOnce($callback, $key);
    }
}
