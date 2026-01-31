<?php
declare(strict_types=1);

namespace Lsr\Inertia\DI;

use Lsr\Inertia\Factory\InertiaFactory;
use Lsr\Inertia\Factory\InertiaFactoryInterface;
use Lsr\Inertia\Middleware\InertiaMiddleware;
use Nette\DI\CompilerExtension;

/**
 * @property object{} $config
 */
class InertiaExtension extends CompilerExtension
{

    public function loadConfiguration(): void
    {
        parent::loadConfiguration();

        $builder = $this->getContainerBuilder();

        $builder->addDefinition($this->prefix('factory'))
            ->setType(InertiaFactoryInterface::class)
            ->setFactory(InertiaFactory::class)
            ->setAutowired()
            ->setTags(['lsr', 'inertia']);

        $builder->addDefinition($this->prefix('middleware'))
            ->setType(InertiaMiddleware::class)
            ->setFactory(InertiaMiddleware::class)
            ->setAutowired()
            ->setTags(['lsr', 'inertia', 'middleware']);
    }

}