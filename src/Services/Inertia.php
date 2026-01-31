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
    )
    {
    }

    /**
     * @throws ExceptionInterface
     * @throws TemplateDoesNotExistException
     */
    public function render(
        string                            $component,
        array|TemplateParametersInterface $parameters = [],
        string|UriInterface|null          $url = null,
        string                            $template = 'pages/index',
    ): ResponseInterface
    {

        /** @var array<string,mixed> $props */
        $props = $parameters instanceof TemplateParametersInterface
            ? $parameters->getProps()
            : $parameters;


        if ($this->request->hasHeader('X-Inertia-Partial-Data')) {
            $only = explode(',', $this->request->getHeaderLine('X-Inertia-Partial-Data'));
            $props = ($only && $this->request->getHeaderLine('X-Inertia-Partial-Component') === $component)
                ? array_intersect_key($props, array_flip($only))
                : $props;
        } else {
            $props = array_filter($props, function ($prop) {
                return !$prop instanceof LazyProp;
            });
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
                ->withHeader('Content-Type', 'application/json');
        }

        // Pass inertia data to the template
        $parameters['inertiaPage'] = $page;

        $html = $this->viewFactory->viewToString($template, $parameters);


        return $this->responseFactory->createResponse()
            ->withBody($this->streamFactory->createStream($html))
            ->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }

}