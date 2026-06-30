<?php

declare(strict_types=1);

namespace Lsr\Inertia\Services;

use Lsr\Exceptions\TemplateDoesNotExistException;
use Lsr\Inertia\Data\AlwaysProp;
use Lsr\Inertia\Data\DeepMergeProp;
use Lsr\Inertia\Data\DeferredProp;
use Lsr\Inertia\Data\LazyProp;
use Lsr\Inertia\Data\MergeProp;
use Lsr\Inertia\Data\OnceProp;
use Lsr\Inertia\Resolver\PropResolver;
use Lsr\Interfaces\TemplateParametersInterface;
use Lsr\Interfaces\ViewFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UriInterface;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Symfony\Component\Serializer\SerializerInterface;

class Inertia
{
    public ?string $version = null;

    public function __construct(
        private readonly ServerRequestInterface   $request,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface   $streamFactory,
        private readonly ViewFactoryInterface     $viewFactory,
        private readonly SerializerInterface      $serializer,
    ) {
    }

    public function lazy(callable $callback): LazyProp {
        return new LazyProp($callback);
    }

    public function always(mixed $value): AlwaysProp {
        return new AlwaysProp($value);
    }

    /**
     * @param non-empty-string $group
     */
    public function defer(callable $callback, string $group = 'default', bool $rescue = false): DeferredProp {
        return new DeferredProp($callback, $group, $rescue);
    }

    public function merge(mixed $value): MergeProp {
        return new MergeProp($value);
    }

    public function deepMerge(mixed $value): DeepMergeProp {
        return new DeepMergeProp($value);
    }

    public function once(callable $callback, ?string $key = null): OnceProp {
        return new OnceProp($callback, $key);
    }

    /**
     * @param array<string, mixed>|TemplateParametersInterface $parameters
     * @param non-empty-string $template
     *
     * @throws ExceptionInterface
     * @throws TemplateDoesNotExistException
     */
    public function render(
        string                            $component,
        array|TemplateParametersInterface $parameters = [],
        string|UriInterface|null          $url = null,
        string                            $template = 'pages/index',
    ): ResponseInterface {

        /** @var array<string,mixed> $props */
        $props = $parameters instanceof TemplateParametersInterface
            ? $parameters->getProps()
            : $parameters;

        $resolvedProps = (new PropResolver($this->request))->resolve($component, $props);

        $page = array_merge([
            'component' => $component,
            'props' => $resolvedProps->props,
            'url' => $url ? (string)$url : (string)$this->request->getUri(),
            'version' => $this->version,
        ], $resolvedProps->getPageMetadata());

        if ($this->request->hasHeader('X-Inertia')) {
            $json = $this->serializer->serialize($page, 'json');
            return $this->responseFactory->createResponse()
                ->withBody($this->streamFactory->createStream($json))
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('X-Inertia', 'true');
        }

        // Pass inertia data to the template
        if ($parameters instanceof TemplateParametersInterface) {
            $parameters['inertiaPage'] = $page;
            $templateParameters = $parameters;
        } else {
            $templateParameters = $parameters;
            $templateParameters['inertiaPage'] = $page;
        }

        $html = $this->viewFactory->viewToString($template, $templateParameters);


        return $this->responseFactory->createResponse()
            ->withBody($this->streamFactory->createStream($html))
            ->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }
}
