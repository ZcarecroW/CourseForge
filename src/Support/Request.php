<?php
declare(strict_types=1);

namespace CourseForge\Support;

/**
 * The incoming request, parsed once and validated on demand.
 *
 * Every accessor either returns a value of the promised type or throws a 422 –
 * a controller never has to guard against arrays arriving where a string was
 * expected.
 */
final class Request
{
    /** @param array<string,mixed> $body @param array<string,string> $params */
    private function __construct(
        public readonly string $method,
        public readonly string $path,
        private array $body,
        private array $params = [],
    ) {
    }

    public static function capture(): self
    {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        // Three deployment shapes are supported, in this order:
        //   /api/<route>            mod_rewrite or an equivalent rule
        //   /api/index.php/<route>  PATH_INFO, no rewrite rules at all
        //   ?r=<route>              the fallback the SPA itself uses
        $uriPath = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
        $path = '';
        if (preg_match('#/api/(.*)$#', $uriPath, $m) === 1) {
            $path = trim($m[1], '/');
        }
        if (str_starts_with($path, 'index.php/')) {
            $path = trim(substr($path, strlen('index.php/')), '/');
        }
        if ($path === '' || $path === 'index.php') {
            $path = trim((string)($_GET['r'] ?? ''), '/');
        }

        $raw = (string)file_get_contents('php://input');
        $body = [];
        if (trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                throw HttpException::badRequest('Request body must be a JSON object.');
            }
            $body = $decoded;
        }

        return new self($method, $path, $body);
    }

    /** @param array<string,string> $params */
    public function withParams(array $params): self
    {
        $clone = clone $this;
        $clone->params = $params;
        return $clone;
    }

    public function header(string $name): string
    {
        return (string)($_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $name))] ?? '');
    }

    /* -------------------------------------------------------- route params */

    public function id(string $name): int
    {
        $value = $this->params[$name] ?? '';
        if (!ctype_digit($value) || (int)$value <= 0) {
            throw HttpException::notFound('Unknown route.');
        }
        return (int)$value;
    }

    public function param(string $name): string
    {
        return (string)($this->params[$name] ?? '');
    }

    /* --------------------------------------------------------- body fields */

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->body);
    }

    /** @return array<string,mixed> */
    public function all(): array
    {
        return $this->body;
    }

    /** Raw scalar, whitespace preserved – for passwords and Markdown bodies. */
    public function raw(string $key, string $default = ''): string
    {
        $value = $this->body[$key] ?? null;
        if ($value === null) {
            return $default;
        }
        if (!is_scalar($value)) {
            throw HttpException::unprocessable('Field "' . $key . '" must be a string, number or boolean.');
        }
        return (string)$value;
    }

    public function str(string $key, string $default = ''): string
    {
        return trim($this->raw($key, $default));
    }

    public function requiredStr(string $key, string $label): string
    {
        $value = $this->str($key);
        if ($value === '') {
            throw HttpException::unprocessable($label . ' is required.');
        }
        return $value;
    }

    public function intOrNull(string $key): ?int
    {
        $value = trim($this->raw($key));
        if ($value === '') {
            return null;
        }
        if (preg_match('/^-?\d+$/', $value) !== 1) {
            throw HttpException::unprocessable('Field "' . $key . '" must be a whole number.');
        }
        return (int)$value;
    }

    public function requiredId(string $key, string $label): int
    {
        $value = $this->intOrNull($key);
        if ($value === null || $value <= 0) {
            throw HttpException::unprocessable($label . ' is required.');
        }
        return $value;
    }

    public function bool(string $key, bool $default = false): bool
    {
        if (!$this->has($key)) {
            return $default;
        }
        return filter_var($this->body[$key], FILTER_VALIDATE_BOOLEAN);
    }

    /** @return array<string,mixed> */
    public function arr(string $key): array
    {
        $value = $this->body[$key] ?? [];
        if (!is_array($value)) {
            throw HttpException::unprocessable('Field "' . $key . '" must be an object.');
        }
        return $value;
    }

    /** @param string[] $allowed */
    public function enum(string $key, array $allowed, string $default): string
    {
        $value = strtolower($this->str($key, $default));
        if ($value === '') {
            $value = $default;
        }
        if (!in_array($value, $allowed, true)) {
            throw HttpException::unprocessable(
                'Field "' . $key . '" must be one of: ' . implode(', ', $allowed) . '.'
            );
        }
        return $value;
    }
}
