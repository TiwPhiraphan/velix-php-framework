<?php

/* =========================
 * Exceptions
 * ========================= */
class ValidationException extends \Exception {
    private array $errors;

    public function __construct(array $errors) {
        parent::__construct('Validation failed');
        $this->errors = $errors;
    }

    public function getErrors(): array {
        return $this->errors;
    }
}

/* =========================
 * UploadedFile
 * ========================= */
class UploadedFile {
    public string $name;
    public string $tmpName;
    public int    $size;
    public int    $error;
    public string $type;

    public function __construct(array $file) {
        $this->name    = $file['name'] ?? '';
        $this->tmpName = $file['tmp_name'] ?? '';
        $this->size    = (int) ($file['size'] ?? 0);
        $this->error   = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        $this->type    = $file['type'] ?? '';
    }

    public function isValid(): bool {
        return $this->error === UPLOAD_ERR_OK && is_uploaded_file($this->tmpName);
    }

    public function getExtension(): string {
        return strtolower(pathinfo($this->name, PATHINFO_EXTENSION));
    }

    public function getMimeType(): string {
        if ($this->isValid() && function_exists('mime_content_type')) {
            return mime_content_type($this->tmpName) ?: $this->type;
        }
        return $this->type;
    }

    public function store(string $dir, string $filename = ''): string|false {
        if (!$this->isValid()) return false;
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $name = $filename ?: bin2hex(random_bytes(16)) . '.' . $this->getExtension();
        $destination = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $name;
        return move_uploaded_file($this->tmpName, $destination) ? $destination : false;
    }
}

/* =========================
 * Request
 * ========================= */
class Request {
    public string $method;
    public string $uri;
    public array  $params;
    public array  $query;
    public array  $body;
    public array  $headers;
    public array  $json;
    public array  $files;

    public function __construct(string $method, string $uri, array $params, array $query, array $body) {
        $this->method  = $method;
        $this->uri     = $uri;
        $this->params  = $params;
        $this->query   = $query;
        $this->body    = $body;
        $this->files   = $_FILES ?? [];
        $this->headers = function_exists('getallheaders') ? getallheaders() : $this->_parseHeaders();

        $raw = file_get_contents('php://input');

        if (empty($this->body) && in_array($method, ['PUT', 'PATCH', 'DELETE'], true)) {
            $ct = $this->header('Content-Type', '');
            if (str_contains($ct, 'application/x-www-form-urlencoded')) {
                parse_str($raw, $parsedBody);
                $this->body = is_array($parsedBody) ? $parsedBody : [];
            }
        }

        $decoded    = json_decode($raw, true);
        $this->json = is_array($decoded) ? $decoded : [];
    }

    private function _parseHeaders(): array {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', substr($key, 5));
                $headers[$name] = $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                $name = str_replace('_', '-', $key);
                $headers[$name] = $value;
            }
        }
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $headers['Authorization'] = $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers['Authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        return $headers;
    }

    public function input(string $key, mixed $default = null): mixed {
        if (array_key_exists($key, $this->body)) return $this->body[$key];
        if (array_key_exists($key, $this->json)) return $this->json[$key];
        if (array_key_exists($key, $this->query)) return $this->query[$key];
        return $default;
    }

    public function all(): array {
        return array_merge($this->query, $this->body, $this->json);
    }

    public function query(string $key, mixed $default = null): mixed {
        return $this->query[$key] ?? $default;
    }

    public function param(string $key, mixed $default = null): mixed {
        return $this->params[$key] ?? $default;
    }

    public function header(string $key, mixed $default = null): mixed {
        $key = strtolower($key);
        foreach ($this->headers as $k => $v) {
            if (strtolower($k) === $key) return $v;
        }
        return $default;
    }

    public function has(string|array ...$keys): bool {
        $flatKeys = [];
        foreach ($keys as $k) {
            if (is_array($k)) {
                $flatKeys = array_merge($flatKeys, $k);
            } else {
                $flatKeys[] = $k;
            }
        }

        foreach ($flatKeys as $k) {
            if (
                !array_key_exists($k, $this->body) &&
                !array_key_exists($k, $this->json) &&
                !array_key_exists($k, $this->query)
            ) {
                return false;
            }
        }
        return true;
    }

    public function only(string|array ...$keys): array {
        $flatKeys = [];
        foreach ($keys as $k) {
            if (is_array($k)) {
                $flatKeys = array_merge($flatKeys, $k);
            } else {
                $flatKeys[] = $k;
            }
        }

        $data = [];
        foreach ($flatKeys as $k) {
            if (array_key_exists($k, $this->body)) {
                $data[$k] = $this->body[$k];
            } elseif (array_key_exists($k, $this->json)) {
                $data[$k] = $this->json[$k];
            } elseif (array_key_exists($k, $this->query)) {
                $data[$k] = $this->query[$k];
            }
        }
        return $data;
    }

    public function validate(array $rules): array {
        $errors = [];
        $data   = [];

        foreach ($rules as $field => $ruleString) {
            $fieldRules = is_array($ruleString) ? $ruleString : explode('|', $ruleString);
            $val        = $this->input($field);
            $hasVal     = $val !== null && $val !== '';
            $isNullable = in_array('nullable', $fieldRules, true);

            if (!$hasVal && !$isNullable && in_array('required', $fieldRules, true)) {
                $errors[$field][] = "The {$field} field is required.";
                continue;
            }

            if (!$hasVal) {
                if ($isNullable) {
                    $data[$field] = null;
                }
                continue;
            }

            foreach ($fieldRules as $rule) {
                if ($rule === 'required' || $rule === 'nullable') continue;

                $ruleName  = $rule;
                $ruleParam = null;
                if (str_contains($rule, ':')) {
                    [$ruleName, $ruleParam] = explode(':', $rule, 2);
                }

                switch ($ruleName) {
                    case 'string':
                        if (!is_string($val)) $errors[$field][] = "The {$field} must be a string.";
                        break;
                    case 'numeric':
                        if (!is_numeric($val)) $errors[$field][] = "The {$field} must be numeric.";
                        break;
                    case 'integer':
                    case 'int':
                        if (filter_var($val, FILTER_VALIDATE_INT) === false) $errors[$field][] = "The {$field} must be an integer.";
                        else $val = (int) $val;
                        break;
                    case 'bool':
                    case 'boolean':
                        if (filter_var($val, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === null) $errors[$field][] = "The {$field} must be a boolean.";
                        break;
                    case 'email':
                        if (!filter_var($val, FILTER_VALIDATE_EMAIL)) $errors[$field][] = "The {$field} must be a valid email address.";
                        break;
                    case 'array':
                        if (!is_array($val)) $errors[$field][] = "The {$field} must be an array.";
                        break;
                    case 'min':
                        $min = (float) $ruleParam;
                        if (is_numeric($val) && $val < $min) $errors[$field][] = "The {$field} must be at least {$min}.";
                        elseif (is_string($val) && mb_strlen($val) < $min) $errors[$field][] = "The {$field} must be at least {$min} characters.";
                        break;
                    case 'max':
                        $max = (float) $ruleParam;
                        if (is_numeric($val) && $val > $max) $errors[$field][] = "The {$field} must not exceed {$max}.";
                        elseif (is_string($val) && mb_strlen($val) > $max) $errors[$field][] = "The {$field} must not exceed {$max} characters.";
                        break;
                    case 'in':
                        $allowed = explode(',', (string) $ruleParam);
                        if (!in_array((string) $val, $allowed, true)) $errors[$field][] = "The {$field} must be one of: " . implode(', ', $allowed);
                        break;
                }
            }

            if (!isset($errors[$field])) {
                $data[$field] = $val;
            }
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        return $data;
    }

    public function file(string $key): ?UploadedFile {
        if (!isset($_FILES[$key]) || !is_array($_FILES[$key]) || empty($_FILES[$key]['name'])) {
            return null;
        }
        if (is_array($_FILES[$key]['name'])) {
            return null;
        }
        return new UploadedFile($_FILES[$key]);
    }

    public function ip(): string {
        $keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
        foreach ($keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ips = explode(',', $_SERVER[$key]);
                return trim($ips[0]);
            }
        }
        return '127.0.0.1';
    }

    public function isSecure(): bool {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
            || ($_SERVER['SERVER_PORT'] ?? null) == 443;
    }

    public function userAgent(): string {
        return $_SERVER['HTTP_USER_AGENT'] ?? '';
    }

    public function fullUrl(): string {
        $scheme = $this->isSecure() ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $uri    = $_SERVER['REQUEST_URI'] ?? '/';
        return "{$scheme}://{$host}{$uri}";
    }

    public function wantsJson(): bool {
        $accept = $this->header('Accept', '');
        return str_contains($accept, 'application/json');
    }

    public function isJson(): bool {
        $ct = $this->header('Content-Type', '');
        return str_contains($ct, 'application/json');
    }

    public function bearerToken(): ?string {
        $auth = $this->header('Authorization')
            ?? $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';

        if (preg_match('/^Bearer\s+(.+)$/i', trim($auth), $matches)) {
            return $matches[1];
        }
        return null;
    }
}

/* =========================
 * Response
 * ========================= */
class Response {
    private array $headers = [];
    private array $cookies = [];
    private int   $status  = 200;
    private bool  $sent    = false;

    public function status(int $code): static {
        $this->status = $code;
        return $this;
    }

    public function getStatus(): int {
        return $this->status;
    }

    public function isSent(): bool {
        return $this->sent;
    }

    public function header(string $name, string $value): static {
        $this->headers[$name] = $value;
        return $this;
    }

    public function cookie(string $name, string $value, array $options = []): static {
        $expires = $options['expires'] ?? $options['expire'] ?? 0;
        unset($options['expire']);

        $this->cookies[] = [
            'name'    => $name,
            'value'   => $value,
            'options' => array_merge([
                'expires'  => $expires,
                'path'     => '/',
                'domain'   => '',
                'secure'   => false,
                'httponly' => true,
                'samesite' => 'Lax',
            ], $options),
        ];
        return $this;
    }

    public function allowCors(array $config = []): static {
        $defaults = [
            'origin'      => '*',
            'credentials' => false,
            'headers'     => ['Content-Type', 'Authorization', 'X-Requested-With'],
            'methods'     => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
            'maxAge'      => null,
        ];
        $config = array_merge($defaults, $config);

        $origin = $config['origin'];
        if (is_array($origin)) {
            $reqOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
            if (in_array($reqOrigin, $origin, true)) {
                $origin = $reqOrigin;
            } else {
                $origin = $origin[0] ?? '*';
            }
            $this->header('Vary', 'Origin');
        }

        $this->header('Access-Control-Allow-Origin', $origin);
        $this->header('Access-Control-Allow-Headers',
            is_array($config['headers']) ? implode(', ', $config['headers']) : $config['headers']
        );
        $this->header('Access-Control-Allow-Methods',
            is_array($config['methods']) ? implode(', ', $config['methods']) : $config['methods']
        );

        if ($config['credentials']) {
            $this->header('Access-Control-Allow-Credentials', 'true');
        }
        if ($config['maxAge'] !== null) {
            $this->header('Access-Control-Max-Age', (string) $config['maxAge']);
        }

        return $this;
    }

    public function json(mixed $data, int $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES): void {
        $this->header('Content-Type', 'application/json; charset=utf-8');
        $encoded = json_encode($data, $flags);
        if ($encoded === false) {
            $this->status(500);
            $encoded = json_encode(['error' => true, 'message' => 'JSON encode error: ' . json_last_error_msg()]);
        }
        $this->send($encoded);
    }

    public function success(mixed $data = null, string $message = 'Success', int $status = 200): void {
        $this->status($status)->json([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ]);
    }

    public function created(mixed $data = null, string $message = 'Resource created'): void {
        $this->success($data, $message, 201);
    }

    public function error(string $message = 'Error', int $status = 400, mixed $errors = null): void {
        $payload = [
            'success' => false,
            'error'   => true,
            'message' => $message,
        ];
        if ($errors !== null) {
            $payload['errors'] = $errors;
        }
        $this->status($status)->json($payload);
    }

    public function noContent(): void {
        $this->status(204)->send();
    }

    public function paginate(array $items, int $total, int $page = 1, int $perPage = 15): void {
        $lastPage = max(1, (int) ceil($total / max(1, $perPage)));
        $this->json([
            'success'    => true,
            'data'       => $items,
            'pagination' => [
                'total'        => $total,
                'per_page'     => $perPage,
                'current_page' => $page,
                'last_page'    => $lastPage,
                'has_more'     => $page < $lastPage,
            ],
        ]);
    }

    public function text(string $text): void {
        $this->header('Content-Type', 'text/plain; charset=utf-8');
        $this->send($text);
    }

    public function html(string $html): void {
        $this->header('Content-Type', 'text/html; charset=utf-8');
        $this->send($html);
    }

    public function download(string $filePath, string $fileName = ''): void {
        if (!file_exists($filePath)) {
            $this->status(404)->json(['error' => true, 'message' => 'File not found']);
            return;
        }
        $name = $fileName ?: basename($filePath);
        $mime = function_exists('mime_content_type') ? mime_content_type($filePath) : 'application/octet-stream';
        $this->header('Content-Type', $mime ?: 'application/octet-stream');
        $this->header('Content-Disposition', "attachment; filename=\"$name\"");
        $this->header('Content-Length', (string) filesize($filePath));
        $this->_flush();
        if (ob_get_level()) ob_clean();
        readfile($filePath);
        exit;
    }

    public function redirect(string $url, int $status = 302): void {
        $this->status($status)->header('Location', $url)->send();
    }

    public function send(string $body = ''): void {
        if ($this->sent) return;
        $this->sent = true;
        $this->_flush();
        echo $body;
    }

    private function _flush(): void {
        http_response_code($this->status);
        foreach ($this->headers as $k => $v) {
            header("$k: $v");
        }
        foreach ($this->cookies as $c) {
            setcookie($c['name'], $c['value'], $c['options']);
        }
    }
}

/* =========================
 * Router
 * ========================= */
class Router {
    protected array  $routes           = [];
    protected array  $lastAddedIndices = [];
    protected string $prefix           = '';

    public function get(string $path, callable $handler): static    { return $this->addSingleRoute('GET', $path, $handler); }
    public function post(string $path, callable $handler): static   { return $this->addSingleRoute('POST', $path, $handler); }
    public function put(string $path, callable $handler): static    { return $this->addSingleRoute('PUT', $path, $handler); }
    public function patch(string $path, callable $handler): static  { return $this->addSingleRoute('PATCH', $path, $handler); }
    public function delete(string $path, callable $handler): static { return $this->addSingleRoute('DELETE', $path, $handler); }

    public function any(string $path, callable $handler): static {
        $this->lastAddedIndices = [];
        foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $this->addRoute($method, $path, $handler);
        }
        return $this;
    }

    public function resource(string $path, string|object $controller): static {
        $prefix   = '/' . trim($path, '/');
        $instance = is_string($controller) ? new $controller() : $controller;

        if (method_exists($instance, 'index'))   $this->get($prefix, [$instance, 'index']);
        if (method_exists($instance, 'store'))   $this->post($prefix, [$instance, 'store']);
        if (method_exists($instance, 'show'))    $this->get($prefix . '/{id}', [$instance, 'show']);
        if (method_exists($instance, 'update'))  $this->put($prefix . '/{id}', [$instance, 'update']);
        if (method_exists($instance, 'destroy')) $this->delete($prefix . '/{id}', [$instance, 'destroy']);

        return $this;
    }

    protected function addSingleRoute(string $method, string $path, callable $handler): static {
        $this->lastAddedIndices = [];
        $this->addRoute($method, $path, $handler);
        return $this;
    }

    protected function addRoute(string $method, string $path, callable $handler): void {
        $full  = $this->prefix . '/' . trim($path, '/');
        $full  = '/' . trim($full, '/');
        $route = trim($full, '/');

        $pattern = preg_replace_callback('/\{(\w+)(?::([^}]+))?\}/', function ($m) {
            $name  = $m[1];
            $regex = isset($m[2]) ? $m[2] : '[^/]+';
            return "(?P<$name>$regex)";
        }, $route);

        $this->routes[] = [
            'method'      => $method,
            'pattern'     => "#^$pattern$#u",
            'handler'     => $handler,
            'middlewares' => [],
        ];
        $this->lastAddedIndices[] = array_key_last($this->routes);
    }

    public function middleware(callable ...$mws): static {
        foreach ($this->lastAddedIndices as $index) {
            if (isset($this->routes[$index])) {
                foreach ($mws as $mw) {
                    $this->routes[$index]['middlewares'][] = $mw;
                }
            }
        }
        return $this;
    }

    public function group(string $prefix, callable $callback, callable ...$middlewares): static {
        $router         = new Router();
        $router->prefix = '/' . trim($this->prefix . '/' . trim($prefix, '/'), '/');
        $callback($router);

        foreach ($router->getRoutes() as $route) {
            $route['middlewares'] = array_merge($middlewares, $route['middlewares']);
            $this->routes[]       = $route;
        }
        return $this;
    }

    public function getRoutes(): array {
        return $this->routes;
    }
}

/* =========================
 * Velix Core
 * ========================= */
class Velix extends Router {
    private array $globalMiddlewares = [];
    private mixed $errorHandler      = null;
    private mixed $notFoundHandler   = null;

    public function use(callable $middleware): static {
        $this->globalMiddlewares[] = $middleware;
        return $this;
    }

    public function onError(callable $handler): static {
        $this->errorHandler = $handler;
        return $this;
    }

    public function onNotFound(callable $handler): static {
        $this->notFoundHandler = $handler;
        return $this;
    }

    public static function rateLimit(int $maxRequests = 60, int $windowSeconds = 60): callable {
        return function (Request $req, Response $res, callable $next) use ($maxRequests, $windowSeconds): void {
            $ip        = $req->ip();
            $cacheDir  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'velix_cache';
            if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
            $cacheFile = $cacheDir . DIRECTORY_SEPARATOR . 'rate_' . md5($ip);

            $now  = time();
            $data = ['count' => 0, 'reset' => $now + $windowSeconds];

            if (file_exists($cacheFile)) {
                $content = @file_get_contents($cacheFile);
                $decoded = json_decode((string) $content, true);
                if (is_array($decoded) && isset($decoded['reset']) && $decoded['reset'] > $now) {
                    $data = $decoded;
                }
            }

            $data['count']++;
            @file_put_contents($cacheFile, json_encode($data), LOCK_EX);

            $remaining = max(0, $maxRequests - $data['count']);
            $res->header('X-RateLimit-Limit', (string) $maxRequests);
            $res->header('X-RateLimit-Remaining', (string) $remaining);
            $res->header('X-RateLimit-Reset', (string) $data['reset']);

            if ($data['count'] > $maxRequests) {
                $retryAfter = max(1, $data['reset'] - $now);
                $res->status(429)
                    ->header('Retry-After', (string) $retryAfter)
                    ->error('Too many requests. Please try again later.', 429);
                return;
            }

            $next();
        };
    }

    public function dispatch(): void {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $rawUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $uri    = trim(rawurldecode($rawUri), '/');

        if ($method === 'OPTIONS') {
            $req = new Request($method, $uri, [], $_GET, $_POST);
            $res = new Response();
            $pipeline = array_reduce(
                array_reverse($this->globalMiddlewares),
                function (callable $next, callable $mw) use ($req, $res): callable {
                    return function () use ($mw, $req, $res, $next): void {
                        $mw($req, $res, $next);
                    };
                },
                function () use ($res): void {
                    $res->status(204)->send();
                }
            );
            $pipeline();
            return;
        }

        $matchedMethods = [];

        foreach ($this->routes as $route) {
            if (!preg_match($route['pattern'], $uri, $matches)) continue;

            $matchedMethods[] = $route['method'];
            if ($route['method'] !== $method) continue;

            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
            $req    = new Request($method, $uri, $params, $_GET, $_POST);
            $res    = new Response();

            $allMiddlewares = array_merge($this->globalMiddlewares, $route['middlewares']);

            $pipeline = array_reduce(
                array_reverse($allMiddlewares),
                function (callable $next, callable $mw) use ($req, $res): callable {
                    return function () use ($mw, $req, $res, $next): void {
                        $mw($req, $res, $next);
                    };
                },
                function () use ($route, $req, $res): void {
                    $result = $this->_callHandler($route['handler'], $req, $res, $route);
                    if ($result !== null && !$res->isSent()) {
                        $res->json($result);
                    }
                }
            );

            try {
                $pipeline();
            } catch (\Throwable $e) {
                $this->_handleError($e, $req, $res);
            }
            return;
        }

        $req = new Request($method, $uri, [], $_GET, $_POST);
        $res = new Response();

        if (!empty($matchedMethods)) {
            $allowed = implode(', ', array_unique($matchedMethods));
            $res->status(405)
                ->header('Allow', $allowed)
                ->json(['error' => true, 'message' => "Method [$method] not allowed. Allowed: $allowed"]);
            return;
        }

        $this->_handleNotFound($req, $res);
    }

    private function _callHandler(callable $handler, Request $req, Response $res, array $route): mixed {
        $ref    = $this->_reflect($handler);
        $params = $ref->getParameters();

        if (empty($params)) {
            return $handler();
        }

        $args = [];
        foreach ($params as $param) {
            $refType = $param->getType();
            $type    = ($refType instanceof \ReflectionNamedType) ? $refType->getName() : null;
            $name    = $param->getName();

            if ($type === Request::class || ($type === null && $name === 'req')) {
                $args[] = $req;
            } elseif ($type === Response::class || ($type === null && $name === 'res')) {
                $args[] = $res;
            } elseif (array_key_exists($name, $req->params)) {
                $val = $req->params[$name];
                if ($type === 'int')   $val = (int) $val;
                if ($type === 'float') $val = (float) $val;
                if ($type === 'bool')  $val = filter_var($val, FILTER_VALIDATE_BOOLEAN);
                $args[] = $val;
            } else {
                $args[] = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;
            }
        }

        return $handler(...$args);
    }

    private function _reflect(callable $handler): \ReflectionFunctionAbstract {
        if (is_array($handler)) {
            return new \ReflectionMethod($handler[0], $handler[1]);
        }
        return new \ReflectionFunction(\Closure::fromCallable($handler));
    }

    private function _handleError(\Throwable $e, Request $req, Response $res): void {
        if ($e instanceof ValidationException) {
            $res->status(422)->error('Validation failed', 422, $e->getErrors());
            return;
        }

        if ($this->errorHandler) {
            ($this->errorHandler)($e, $req, $res);
            return;
        }

        $res->status(500)->json([
            'success' => false,
            'error'   => true,
            'message' => $e->getMessage(),
        ]);
    }

    private function _handleNotFound(Request $req, Response $res): void {
        if ($this->notFoundHandler) {
            ($this->notFoundHandler)($req, $res);
            return;
        }

        $isApi     = str_starts_with($req->uri, 'api/') || $req->wantsJson();
        $indexFile = __DIR__ . '/public/index.html';

        if (!$isApi && $req->method === 'GET' && file_exists($indexFile)) {
            $res->status(200)->html(file_get_contents($indexFile));
            return;
        }

        $res->status(404)->json([
            'error'   => true,
            'message' => "Route [{$req->method}] /{$req->uri} not found",
        ]);
    }
}
