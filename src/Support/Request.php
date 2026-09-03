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

        // A body is read whole and decoded, so its size is bounded here rather
        // than by memory_limit. Sixteen megabytes is more than the largest
        // legitimate body - a course outline, a page - by a wide margin.
        $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($length > self::MAX_BODY_BYTES) {
            throw new HttpException('The request body is too large.', 413);
        }

        return new self($method, $path, self::decodeBody((string)file_get_contents('php://input', false, null, 0, self::MAX_BODY_BYTES + 1)));
    }

    /** The largest request body that is read at all. */
    public const MAX_BODY_BYTES = 16 * 1024 * 1024;

    /**
     * Turns a raw request body into the field map the accessors read.
     *
     * Separate from capture() because capture() can only be reached through a
     * live web request - it reads php://input and $_SERVER - and this rule is
     * worth being able to test on its own.
     *
     * @return array<string,mixed>
     */
    public static function decodeBody(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }
        if (strlen($raw) > self::MAX_BODY_BYTES) {
            throw new HttpException('The request body is too large.', 413);
        }

        $decoded = json_decode($raw, true);

        // Malformed JSON and well-formed-but-wrong-shaped JSON are different
        // mistakes and deserve different sentences. A body encoded in latin-1
        // used to be told "Request body must be a JSON object", which is a
        // reply about structure to a caller whose structure was fine - it sends
        // them reading their own braces instead of their Content-Type. PHP
        // knows exactly what went wrong, so say it.
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw HttpException::badRequest('The request body is not valid JSON: ' . json_last_error_msg() . '.');
        }

        // is_array() alone does not enforce what the message promises: PHP
        // decodes a JSON array into a PHP array just as it does an object, so a
        // body of [1,2,3] used to sail through and then read as a set of absent
        // fields. POST /projects answered that with a cheerful "Untitled course"
        // rather than a 400 - a client sending the wrong shape got a success and
        // a junk row instead of being told.
        //
        // An empty body decodes to [] whichever brace it was written with, and
        // {} is a legitimate "use every default", so [] stays allowed. Only a
        // populated list is refused. Note this is the REST parser only; JSON-RPC
        // batches arrive at api/mcp.php, which parses its own body and must keep
        // accepting a top-level array.
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw HttpException::badRequest('Request body must be a JSON object.');
        }

        return $decoded;
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

    /* --------------------------------------------------------- query string */

    /**
     * A value from the query string.
     *
     * GET routes have no body to read, and a filter such as "whose courses" or
     * "which kind of audit entry" belongs in the URL anyway - it is part of what
     * is being asked for, not a change being made.
     */
    public function query(string $name, string $default = ''): string
    {
        $value = $_GET[$name] ?? null;
        return is_scalar($value) ? trim((string)$value) : $default;
    }

    public function queryBool(string $name, bool $default = false): bool
    {
        $value = $_GET[$name] ?? null;
        return $value === null ? $default : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public function queryInt(string $name, int $default = 0): int
    {
        $value = $this->query($name);
        return preg_match('/^-?\d+$/', $value) === 1 ? (int)$value : $default;
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
