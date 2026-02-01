<?php
declare(strict_types=1);

namespace Lsr\Inertia\Http;

use Lsr\Exceptions\TemplateDoesNotExistException;
use Lsr\Inertia\Factory\InertiaFactoryInterface;
use Lsr\Interfaces\TemplateParametersInterface;
use Nette\DI\Attributes\Inject;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Serializer\Exception\ExceptionInterface;

/**
 * @property ServerRequestInterface $request Request property from controller
 * @property array<string,mixed>|TemplateParametersInterface $params Params property from controller
 */
trait WithInertia
{

    #[Inject]
    protected InertiaFactoryInterface $inertiaFactory;

    /**
     * @param non-empty-string $component
     *
     * @throws ExceptionInterface
     * @throws TemplateDoesNotExistException
     */
    protected function inertia(string $component): ResponseInterface
    {
        return $this->inertiaFactory
            ->fromRequest($this->request)
            ->render($component, $this->params);
    }

}