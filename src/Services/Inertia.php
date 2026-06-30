<?php

declare(strict_types=1);

namespace Lsr\Inertia\Services;

use Closure;
use Lsr\Exceptions\TemplateDoesNotExistException;
use Lsr\Inertia\Data\LazyProp;
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


        if ($this->isPartialRequestFor($component)) {
            if ($this->request->hasHeader('X-Inertia-Partial-Data')) {
                $props = array_intersect_key(
                    $props,
                    array_flip($this->getPartialHeaderValues('X-Inertia-Partial-Data')),
                );
            } elseif ($this->request->hasHeader('X-Inertia-Partial-Except')) {
                $props = array_diff_key(
                    $props,
                    array_flip($this->getPartialHeaderValues('X-Inertia-Partial-Except')),
                );
            }
        } else {
            $props = array_filter($props, function ($prop) {
                return !$prop instanceof LazyProp;
            });
        }

        // Filter-out framework props that might cause infinite recursion or are not needed in the frontend
        if (isset($props['page'])) {
            unset($props['page']);
        }
        if (isset($props['app'])) {
            unset($props['app']);
        }
        if (isset($props['request'])) {
            unset($props['request']);
        }

        array_walk_recursive($props, function (&$prop) {
            if ($prop instanceof Closure || $prop instanceof LazyProp) {
                $prop = $prop();
            }
        });

        $page = [
            'component' => $component,
            'props' => $props,
            'url' => $url ? (string)$url : (string)$this->request->getUri(),
            'version' => $this->version,
        ];

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

    private function isPartialRequestFor(string $component): bool {
        return $this->request->getHeaderLine('X-Inertia-Partial-Component') === $component
            && (
                $this->request->hasHeader('X-Inertia-Partial-Data')
                || $this->request->hasHeader('X-Inertia-Partial-Except')
            );
    }

    /**
     * @return non-empty-list<string>
     */
    private function getPartialHeaderValues(string $header): array {
        $values = array_values(
            array_filter(
                array_map('trim', explode(',', $this->request->getHeaderLine($header))),
                static fn(string $value): bool => $value !== '',
            ),
        );

        return $values === [] ? [''] : $values;
    }
}
