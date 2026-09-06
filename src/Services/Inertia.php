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
use Lsr\Inertia\Lifecycle\InertiaLifecycleHookInterface;
use Lsr\Inertia\Lifecycle\InertiaLifecycleScopeInterface;
use Lsr\Inertia\Resolver\PropResolver;
use Lsr\Inertia\Ssr\HttpRenderer;
use Lsr\Inertia\Ssr\SsrException;
use Lsr\Interfaces\TemplateParametersInterface;
use Lsr\Interfaces\ViewFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UriInterface;
use Symfony\Component\Serializer\Exception\ExceptionInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Throwable;
use UnexpectedValueException;

class Inertia
{
    public const string SSR_ENABLED_ATTRIBUTE = 'inertia.ssr.enabled';

    public ?string $version = null;
    private ?InertiaLifecycleHookInterface $lifecycleHook = null;

    public function __construct(
        private readonly ServerRequestInterface   $request,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface   $streamFactory,
        private readonly ViewFactoryInterface     $viewFactory,
        private readonly NormalizerInterface      $normalizer,
        private readonly ?HttpRenderer            $ssr = null,
        private readonly InertiaOptions           $options = new InertiaOptions(),
    ) {
    }

    public function setLifecycleHook(InertiaLifecycleHookInterface $hook): static {
        $this->lifecycleHook = $hook;
        return $this;
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

        $scope = $this->beginLifecycle(
            $component,
            count($props),
            $this->request->hasHeader('X-Inertia'),
        );
        $resolvedPropCount = 0;
        $deferredPropCount = 0;
        $rescuedPropCount = 0;

        try {
            $resolvedProps = (new PropResolver($this->request))->resolve($component, $props);
            $resolvedPropCount = count($resolvedProps->props);
            foreach ($resolvedProps->deferredProps as $deferredProps) {
                $deferredPropCount += count($deferredProps);
            }
            $rescuedPropCount = count($resolvedProps->rescuedProps);

            $page = array_merge([
                'component' => $component,
                'props' => $resolvedProps->props,
                'url' => $url ? (string)$url : (string)$this->request->getUri(),
                'version' => $this->version,
            ], $resolvedProps->getPageMetadata());
            $page = $this->normalizer->normalize($page, 'json', $this->options->normalizationContext);
            if (!is_array($page)) {
                throw new UnexpectedValueException('The normalized Inertia page must be an array.');
            }

            // One encoding for JSON responses, SSR transport, and the V3 script fallback.
            // Escaped slashes prevent a prop containing </script> from closing that element.
            $json = json_encode(
                $page,
                JSON_THROW_ON_ERROR | ($this->options->jsonEncodeOptions & ~JSON_UNESCAPED_SLASHES),
            );

            if ($this->request->hasHeader('X-Inertia')) {
                return $this->responseFactory->createResponse()
                    ->withBody($this->streamFactory->createStream($json))
                    ->withHeader('Content-Type', 'application/json')
                    ->withHeader('X-Inertia', 'true')
                    ->withHeader('Vary', 'X-Inertia');
            }

            $rendered = null;
            if (
                $this->ssr !== null
                && $this->request->getMethod() === 'GET'
                && $this->request->getAttribute(self::SSR_ENABLED_ATTRIBUTE) !== false
            ) {
                try {
                    $rendered = $this->ssr->render($json);
                } catch (SsrException $exception) {
                    if ($this->options->throwOnSsrError) {
                        throw $exception;
                    }
                    try {
                        $scope?->recordException($exception);
                    } catch (Throwable) {
                        // Diagnostics must not prevent client-rendered fallback.
                    }
                }
            }

            $templateParameters = $parameters;
            $templateParameters['inertiaPage'] = $page;
            $templateParameters['inertiaHead'] = $rendered === null ? '' : implode("\n", $rendered->head);
            if ($rendered !== null) {
                // The renderer's body already contains the V3 page script and populated root.
                $templateParameters['inertiaBody'] = $rendered->body;
            } else {
                $rootId = htmlspecialchars($this->options->rootId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $templateParameters['inertiaBody'] = '<script data-page="' . $rootId
                    . '" type="application/json">' . $json . '</script><div id="' . $rootId . '"></div>';
            }

            $html = $this->viewFactory->viewToString($template, $templateParameters);

            return $this->responseFactory->createResponse()
                ->withBody($this->streamFactory->createStream($html))
                ->withHeader('Content-Type', 'text/html; charset=UTF-8')
                ->withHeader('Vary', 'X-Inertia');
        } catch (Throwable $exception) {
            try {
                $scope?->recordException($exception);
            } catch (Throwable) {
                // Lifecycle hooks must never affect Inertia rendering.
            }
            throw $exception;
        } finally {
            try {
                $scope?->complete($resolvedPropCount, $deferredPropCount, $rescuedPropCount);
            } catch (Throwable) {
                // Lifecycle hooks must never affect Inertia rendering.
            }
        }
    }

    private function beginLifecycle(
        string $component,
        int $inputPropCount,
        bool $inertiaRequest,
    ): ?InertiaLifecycleScopeInterface {
        try {
            return $this->lifecycleHook?->begin($component, $inputPropCount, $inertiaRequest);
        } catch (Throwable) {
            return null;
        }
    }
}
