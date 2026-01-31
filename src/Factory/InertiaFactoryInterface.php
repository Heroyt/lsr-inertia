<?php
declare(strict_types=1);

namespace Lsr\Inertia\Factory;

use Lsr\Inertia\Services\Inertia;
use Psr\Http\Message\ServerRequestInterface;

interface InertiaFactoryInterface
{

    public function fromRequest(ServerRequestInterface $request): Inertia;

}