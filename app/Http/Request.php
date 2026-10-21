<?php
declare(strict_types=1);

namespace App\Http;

/** Immutable HTTP request. */
final readonly class Request
{
    /**
     * @param array<string, string> $headers names in lower case
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
        // On IIS, REQUEST_URI may be missing; URL Rewrite passes the original URL in HTTP_X_ORIGINAL_URL.
        $original = $_SERVER['REQUEST_URI'] ?? $_SERVER['HTTP_X_ORIGINAL_URL'] ?? $_SERVER['HTTP_X_REWRITE_URL'] ?? '/';
        $uri = (string) parse_url($original, PHP_URL_PATH);
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
            !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off', // IIS sends "off"
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** Decoded JSON body (empty array if invalid). */
    public function json(): array
    {
        $data = json_decode($this->rawBody, true);

        return is_array($data) ? $data : [];
    }

    /** URL relative to the application root. */
    public function url(string $path = ''): string
    {
        return $this->basePath . '/' . ltrim($path, '/');
    }
}
