<?php
// core/Core/Router.php
namespace Core;

class Router
{
    private array $routes = [];

    public function get(string $path, callable $handler): void  { $this->routes['GET'][$path] = $handler; }
    public function post(string $path, callable $handler): void { $this->routes['POST'][$path] = $handler; }

    // Ścina base_path z config.php (np. '/ridemore' na dev, '' na prod).
    // Jedyne miejsce w aplikacji, które zna ten prefiks.
    //
    // OD 2026-09-16 ŚCINA TEŻ PREFIKS JĘZYKA (`/en/…`) — z tego samego powodu:
    // trasy, kontrolery i API nie wiedzą, że wersje językowe istnieją.
    // `/en/wydarzenia/x` trafia w tę samą trasę co `/wydarzenia/x`, a język
    // żądania ustawił wcześniej bootstrap (Core\Lang::initFromRequest).
    public static function stripBasePath(string $uri): string
    {
        $uri  = parse_url($uri, PHP_URL_PATH);
        $base = APP_CONFIG['base_path'] ?? '';
        if ($base !== '' && str_starts_with($uri, $base)) {
            $uri = substr($uri, strlen($base));
        }
        if ($uri === '') {
            return '/';
        }
        $uri = Lang::splitPath($uri)[1];
        // Apache potrafi doklejać końcowy slash (np. katalog admin/ bez
        // index.php wymuszający canonical redirect /admin -> /admin/) —
        // trasy rejestrujemy bez niego, więc normalizujemy tu raz.
        return strlen($uri) > 1 ? rtrim($uri, '/') : $uri;
    }

    public function dispatch(string $method, string $uri): void
    {
        $uri = self::stripBasePath($uri);
        // HEAD to GET bez treści (SEO, 2026-09-14): roboty i narzędzia
        // sprawdzające linki pytają HEAD-em i dostawały 404 na KAŻDĄ stronę.
        // Treść odpowiedzi na HEAD serwer i tak odrzuca.
        if ($method === 'HEAD') {
            $method = 'GET';
        }
        foreach ($this->routes[$method] ?? [] as $pattern => $handler) {
            $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
            if (preg_match($regex, $uri, $matches)) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                $handler(...array_values($params));
                return;
            }
        }
        http_response_code(404);
        header('Content-Type: application/json');
        $response = ['error' => 'Nie znaleziono trasy'];
        if (defined('APP_ENV') && APP_ENV === 'dev') {
            $response['stage']            = 'routing';
            $response['requestedUri']     = $uri;
            $response['requestedMethod']  = $method;
            $response['registeredRoutes'] = array_keys($this->routes[$method] ?? []);
        }
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
    }
}
