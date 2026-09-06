<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Latte\Engine;
use Latte\Loaders\StringLoader;
use Lsr\Interfaces\TemplateParametersInterface;
use Lsr\Interfaces\ViewFactoryInterface;

final class InertiaViewFactory implements ViewFactoryInterface
{
    public function view(string $template, array|TemplateParametersInterface $params = []): void {
        echo $this->viewToString($template, $params);
    }

    public function viewToString(string $template, array|TemplateParametersInterface $params = []): string {
        $latte = new Engine();
        $latte->setLoader(new StringLoader());
        return $latte->renderToString(
            '<!doctype html><html><head>{$inertiaHead|noescape}</head>'
            . '<body>{$inertiaBody|noescape}</body></html>',
            [
                'inertiaHead' => $params['inertiaHead'],
                'inertiaBody' => $params['inertiaBody'],
            ],
        );
    }

    public function setLocale(?string $locale): static {
        return $this;
    }
}
