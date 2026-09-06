<?php

declare(strict_types=1);

namespace Tests\DI;

use Lsr\Interfaces\TemplateParametersInterface;
use Tests\Fixtures\StringViewFactory;

final class RenderingViewFactory extends StringViewFactory
{
    public function viewToString(string $template, array|TemplateParametersInterface $params = []): string {
        return '<html><head>' . $params['inertiaHead'] . '</head><body>' . $params['inertiaBody'] . '</body></html>';
    }
}
