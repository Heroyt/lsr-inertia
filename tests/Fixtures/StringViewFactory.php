<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Lsr\Interfaces\TemplateParametersInterface;
use Lsr\Interfaces\ViewFactoryInterface;

class StringViewFactory implements ViewFactoryInterface
{
    /**
     * @param non-empty-string $template
     * @param array<string, mixed>|TemplateParametersInterface $params
     */
    public function view(string $template, array|TemplateParametersInterface $params = []): void {
    }

    /**
     * @param non-empty-string $template
     * @param array<string, mixed>|TemplateParametersInterface $params
     */
    public function viewToString(string $template, array|TemplateParametersInterface $params = []): string {
        return '<html></html>';
    }

    public function setLocale(?string $locale): static {
        return $this;
    }
}
