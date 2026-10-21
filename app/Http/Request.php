<?php
declare(strict_types=1);

namespace App\Http;

/** Requisição HTTP imutável. */
final readonly class Request
{
    /**
     * @param array<string, string> $headers nomes em minúsculas
     */
    public function __construct(
        public string $method,
        public string $path,
        public string $basePath,
        public array $post,
        public array $headers,
        public string $rawBody,
        public bool $secure,
    ) {
    }

    public static function fromGlobals(): self
    {
        $uri = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        $relative = ($base !== '' && str_starts_with($uri, $base)) ? substr($uri, strlen($base)) : $uri;

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            }
        }

        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            '/' . trim($relative, '/'),
            $base,
            $_POST,
            $headers,
            (string) file_get_contents('php://input'),
            !empty($_SERVER['HTTPS']),
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** Corpo JSON decodificado (array vazio se inválido). */
    public function json(): array
    {
        $data = json_decode($this->rawBody, true);

        return is_array($data) ? $data : [];
    }

    /** URL relativa à raiz da aplicação. */
    public function url(string $path = ''): string
    {
        return $this->basePath . '/' . ltrim($path, '/');
    }
}
