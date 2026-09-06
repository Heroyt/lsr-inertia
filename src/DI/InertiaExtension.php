<?php

declare(strict_types=1);

namespace Lsr\Inertia\DI;

use Lsr\Inertia\Factory\InertiaFactory;
use Lsr\Inertia\Factory\InertiaFactoryInterface;
use Lsr\Inertia\Middleware\InertiaMiddleware;
use Lsr\Inertia\Services\InertiaOptions;
use Lsr\Inertia\Ssr\HttpRenderer;
use Nette\DI\CompilerExtension;
use Nette\DI\Definitions\Reference;
use Nette\DI\Definitions\Statement;
use Nette\Schema\Expect;
use Nette\Schema\Schema;

/**
 * @property object{
 *     rootId: string,
 *     normalizationContext: array<string, mixed>,
 *     jsonEncodeOptions: int,
 *     ssr: object{
 *         enabled: bool,
 *         url: string,
 *         client: Statement|Reference|string|null,
 *         throwOnError: bool
 *     }
 * } $config
 */
class InertiaExtension extends CompilerExtension
{
    public function getConfigSchema(): Schema {
        return Expect::structure([
            'rootId' => Expect::string('app'),
            'normalizationContext' => Expect::arrayOf('mixed', 'string')->default([]),
            'jsonEncodeOptions' => Expect::int(InertiaOptions::DEFAULT_JSON_OPTIONS),
            'ssr' => Expect::structure([
                'enabled' => Expect::bool(false),
                'url' => Expect::string('http://127.0.0.1:13714/render'),
                'client' => Expect::anyOf(
                    Expect::type(Statement::class),
                    Expect::type(Reference::class),
                    Expect::string()->pattern('@.+'),
                )->nullable()->default(null),
                'throwOnError' => Expect::bool(false),
            ]),
        ]);
    }

    public function loadConfiguration(): void {
        parent::loadConfiguration();

        $builder = $this->getContainerBuilder();
        $config = $this->config;

        $options = $builder->addDefinition($this->prefix('options'))
            ->setFactory(InertiaOptions::class, [
                'rootId' => $config->rootId,
                'normalizationContext' => $config->normalizationContext,
                'jsonEncodeOptions' => $config->jsonEncodeOptions,
                'throwOnSsrError' => $config->ssr->throwOnError,
            ])
            ->setAutowired(false);

        $renderer = null;
        if ($config->ssr->enabled) {
            $arguments = ['url' => $config->ssr->url];
            if ($config->ssr->client !== null) {
                $arguments['client'] = $config->ssr->client;
            }
            $renderer = $builder->addDefinition($this->prefix('ssr.renderer'))
                ->setFactory(HttpRenderer::class, $arguments)
                ->setAutowired(false);
        }

        $builder->addDefinition($this->prefix('factory'))
            ->setType(InertiaFactoryInterface::class)
            ->setFactory(InertiaFactory::class, [
                'ssr' => $renderer,
                'options' => $options,
            ])
            ->setAutowired()
            ->setTags(['lsr', 'inertia']);

        $builder->addDefinition($this->prefix('middleware'))
            ->setType(InertiaMiddleware::class)
            ->setFactory(InertiaMiddleware::class)
            ->setAutowired()
            ->setTags(['lsr', 'inertia', 'middleware']);
    }
}
