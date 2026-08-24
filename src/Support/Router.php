<?php
declare(strict_types=1);

namespace CourseForge\Support;

/**
 * A tiny segment router.
 *
 * Routes are declared once in api/index.php, which makes the complete API
 * surface readable at a glance. `{name}` captures one segment; a path that
 * matches with the wrong verb produces a proper 405 with an Allow header
 * instead of a misleading 404.
 */
final class Router
{
    /** @var array<int,array{methods:string[],segments:string[],handler:callable,auth:bool,admin:bool}> */
    private array $routes = [];

    /**
     * @param string $methods Space separated verbs, e.g. 'GET PUT DELETE'.
     * @param bool   $auth    false for the handful of endpoints reachable while signed out.
     * @param bool   $admin   true for the routes only an administrator may call. The
     *                        handler checks this again for itself; a route table is a
     *                        convenience, never the only thing standing in the way.
     */
    public function add(string $methods, string $pattern, callable $handler, bool $auth = true, bool $admin = false): void
    {
        $this->routes[] = [
            'methods' => array_values(array_filter(explode(' ', strtoupper($methods)))),
            'segments' => self::split($pattern),
            'handler' => $handler,
            'auth' => $auth || $admin,
            'admin' => $admin,
        ];
    }

    /**
     * @return array{handler:callable,request:Request,auth:bool,admin:bool}
     * @throws HttpException 404 when nothing matches, 405 when only the verb is wrong.
     */
    public function match(Request $request): array
    {
        $segments = self::split($request->path);
        $allowed = [];

        foreach ($this->routes as $route) {
            $params = self::bind($route['segments'], $segments);
            if ($params === null) {
                continue;
            }
            if (!in_array($request->method, $route['methods'], true)) {
                $allowed = [...$allowed, ...$route['methods']];
                continue;
            }
            return [
                'handler' => $route['handler'],
                'request' => $request->withParams($params),
                'auth' => $route['auth'],
                'admin' => $route['admin'],
            ];
        }

        if ($allowed !== []) {
            throw HttpException::methodNotAllowed(array_values(array_unique($allowed)));
        }
        throw HttpException::notFound('Unknown endpoint: ' . ($request->path === '' ? '(root)' : $request->path));
    }

    /** @return string[] */
    private static function split(string $path): array
    {
        return array_values(array_filter(explode('/', trim($path, '/')), static fn(string $s): bool => $s !== ''));
    }

    /**
     * @param string[] $pattern
     * @param string[] $actual
     * @return array<string,string>|null Captured params, or null when the shape differs.
     */
    private static function bind(array $pattern, array $actual): ?array
    {
        if (count($pattern) !== count($actual)) {
            return null;
        }
        $params = [];
        foreach ($pattern as $i => $segment) {
            if (str_starts_with($segment, '{') && str_ends_with($segment, '}')) {
                $params[substr($segment, 1, -1)] = $actual[$i];
                continue;
            }
            if ($segment !== $actual[$i]) {
                return null;
            }
        }
        return $params;
    }
}
