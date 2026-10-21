<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/** Renders plain PHP templates. Data arrives in a single $data array (no extract). */
final class View
{
    /** @param array<string, mixed> $shared data available to every template */
    public function __construct(private readonly string $dir, private readonly array $shared = [])
    {
    }

    /** Renders $template inside the layout (the body goes in $data['content']). */
    public function render(string $template, array $data = [], string $layout = 'layout'): string
    {
        $data += $this->shared;
        $data['content'] = $this->include($template, $data);

        return $this->include($layout, $data);
    }

    private function include(string $name, array $data): string
    {
        $file = "{$this->dir}/{$name}.php";
        if (!is_file($file)) {
            throw new RuntimeException("Template not found: $name");
        }

        return (static function (string $__file, array $data): string {
            ob_start();
            require $__file;

            return (string) ob_get_clean();
        })($file, $data);
    }
}
