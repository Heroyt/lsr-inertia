<?php

declare(strict_types=1);

namespace Lsr\Inertia\Http;

use Lsr\Exceptions\TemplateDoesNotExistException;
use Lsr\Inertia\Data\AlwaysProp;
use Lsr\Inertia\Data\DeepMergeProp;
use Lsr\Inertia\Data\DeferredProp;
use Lsr\Inertia\Data\LazyProp;
use Lsr\Inertia\Data\MergeProp;
use Lsr\Inertia\Data\OnceProp;
use Lsr\Inertia\Factory\InertiaFactoryInterface;
use Lsr\Inertia\Services\Inertia;
use Lsr\Interfaces\TemplateParametersInterface;
use Nette\DI\Attributes\Inject;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Symfony\Component\Serializer\Exception\ExceptionInterface;

/**
 * @property ServerRequestInterface $request Request property from controller
 * @property array<string,mixed>|TemplateParametersInterface $params Params property from controller
 */
trait WithInertia
{
    #[Inject]
    public InertiaFactoryInterface $inertiaFactory;

    /**
     * @param non-empty-string $component
     *
     * @throws ExceptionInterface
     * @throws TemplateDoesNotExistException
     */
    protected function inertia(
        string $component,
        array|TemplateParametersInterface|null $parameters = null,
        string|UriInterface|null $url = null,
        string $template = 'pages/index',
    ): ResponseInterface {
        return $this->inertiaService()
            ->render($component, $parameters ?? $this->params, $url, $template);
    }

    protected function inertiaLazy(callable $callback): LazyProp {
        return $this->inertiaService()->lazy($callback);
    }

    protected function inertiaAlways(mixed $value): AlwaysProp {
        return $this->inertiaService()->always($value);
    }

    /**
     * @param non-empty-string $group
     */
    protected function inertiaDefer(callable $callback, string $group = 'default', bool $rescue = false): DeferredProp {
        return $this->inertiaService()->defer($callback, $group, $rescue);
    }

    protected function inertiaMerge(mixed $value): MergeProp {
        return $this->inertiaService()->merge($value);
    }

    protected function inertiaDeepMerge(mixed $value): DeepMergeProp {
        return $this->inertiaService()->deepMerge($value);
    }

    protected function inertiaOnce(callable $callback, ?string $key = null): OnceProp {
        return $this->inertiaService()->once($callback, $key);
    }

    protected function inertiaService(): Inertia {
        return $this->inertiaFactory->fromRequest($this->request);
    }
}
