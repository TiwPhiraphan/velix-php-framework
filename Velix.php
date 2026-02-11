<?php

/* =========================
 * Request
 * ========================= */
class Request {
    public $method;
    public $uri;
    public $params;
    public $query;
    public $body;
    public $headers;
    public $json;

    public function __construct($method, $uri, $params, $query, $body) {
        $this->method  = $method;
        $this->uri     = $uri;
        $this->params  = $params;
        $this->query   = $query;
        $this->body    = $body;
        $this->headers = function_exists('getallheaders') ? getallheaders() : [];

        $raw = file_get_contents('php://input');
        $this->json = json_decode($raw, true);
        if (!is_array($this->json)) {
            $this->json = [];
        }
    }

    public function input($key, $default = null) {
        if (isset($this->body[$key])) return $this->body[$key];
        if (isset($this->json[$key])) return $this->json[$key];
        return $default;
    }

    public function query($key, $default = null) {
        return $this->query[$key] ?? $default;
    }

    public function param($key, $default = null) {
        return $this->params[$key] ?? $default;
    }

    public function header($key, $default = null) {
        $key = strtolower($key);
        foreach ($this->headers as $k => $v) {
            if (strtolower($k) === $key) return $v;
        }
        return $default;
    }

    public function has(array $keys) {
        foreach ($keys as $k) {
            if (
                !isset($this->body[$k]) &&
                !isset($this->json[$k]) &&
                !isset($this->query[$k])
            ) {
                return false;
            }
        }
        return true;
    }

    public function only(array $keys) {
        $data = [];
        foreach ($keys as $k) {
            $val = $this->input($k);
            if ($val !== null) $data[$k] = $val;
        }
        return $data;
    }
}

/* =========================
 * Response
 * ========================= */
class Response {
    private $headers = [];
    private $cookies = [];
    private $status  = 200;

    public function status($code) {
        $this->status = $code;
        return $this;
    }

    public function header($name, $value) {
        $this->headers[$name] = $value;
        return $this;
    }

    public function allowCors($config = []) {
        $defaults = [
            'origin' => '*',
            'credentials' => false,
            'headers' => ['Content-Type', 'Authorization'],
            'methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']
        ];
        $config = array_merge($defaults, $config);

        $this->header('Access-Control-Allow-Origin', $config['origin']);
        $this->header(
            'Access-Control-Allow-Headers',
            is_array($config['headers']) ? implode(', ', $config['headers']) : $config['headers']
        );
        $this->header(
            'Access-Control-Allow-Methods',
            is_array($config['methods']) ? implode(', ', $config['methods']) : $config['methods']
        );

        if ($config['credentials']) {
            $this->header('Access-Control-Allow-Credentials', 'true');
        }

        return $this;
    }

    public function json($data) {
        $this->header('Content-Type', 'application/json; charset=utf-8');
        $this->send(json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    public function text($text) {
        $this->header('Content-Type', 'text/plain; charset=utf-8');
        $this->send($text);
    }

    public function redirect($url, $status = 302) {
        $this->status($status)->header('Location', $url)->send();
    }

    public function send($body = '') {
        http_response_code($this->status);
        foreach ($this->headers as $k => $v) {
            header("$k: $v");
        }
        echo $body;
        exit;
    }
}

/* =========================
 * Velix Core
 * ========================= */
class Velix {
    private $routes = [];
    private $middlewares = [];

    public function use(callable $middleware) {
        $this->middlewares[] = $middleware;
    }

    public function get($path, $handler)    { $this->addRoute('GET', $path, $handler); }
    public function post($path, $handler)   { $this->addRoute('POST', $path, $handler); }
    public function put($path, $handler)    { $this->addRoute('PUT', $path, $handler); }
    public function patch($path, $handler)  { $this->addRoute('PATCH', $path, $handler); }
    public function delete($path, $handler) { $this->addRoute('DELETE', $path, $handler); }

    private function addRoute($method, $path, $handler) {
        $route = trim($path, '/');
        $pattern = preg_replace('/\{(\w+)\}/', '(?P<$1>[^/]+)', $route);
        $this->routes[$method][] = [
            'pattern' => "#^$pattern$#u",
            'handler' => $handler
        ];
    }

    public function dispatch() {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');

        foreach ($this->routes[$method] ?? [] as $route) {
            if (preg_match($route['pattern'], $uri, $matches)) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

                $req = new Request($method, $uri, $params, $_GET, $_POST);
                $res = new Response();

                $pipeline = array_reduce(
                    array_reverse($this->middlewares),
                    function ($next, $mw) use ($req, $res) {
                        return function () use ($mw, $req, $res, $next) {
                            return $mw($req, $res, $next);
                        };
                    },
                    function () use ($route, $req, $res) {
                        try {
                            $result = ($route['handler'])($req, $res);
                            if ($result !== null) {
                                $res->json($result);
                            }
                        } catch (\Throwable $e) {
                            $res->status(500)->json([
                                'error' => true,
                                'message' => $e->getMessage()
                            ]);
                        }
                    }
                );

                return $pipeline();
            }
        }

        http_response_code(404);
        echo '404 Not Found';
    }
}
