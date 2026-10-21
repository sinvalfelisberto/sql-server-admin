<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/** Renderiza templates PHP simples. Os dados chegam em um único array $data (sem extract). */
final class View
{
    /** @param array<string, mixed> $shared dados disponíveis em todos os templates */
    public function __construct(private readonly string $dir, private readonly array $shared = [])
    {
    }

    /** Renderiza $template dentro do layout (o corpo fica em $data['content']). */
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
            throw new RuntimeException("Template não encontrado: $name");
        }

        return (static function (string $__file, array $data): string {
            ob_start();
            require $__file;

            return (string) ob_get_clean();
        })($file, $data);
    }
}
