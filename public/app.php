<?php

$velixPath = realpath(__DIR__ . '/../Velix.php');
if (!$velixPath) {
    http_response_code(500);
    echo json_encode(['error' => true, 'message' => 'Velix.php not found']);
    exit;
}
require $velixPath;

$app = new Velix();

/* =========================
 * Global Middleware
 * ========================= */
$app->use(function (Request $req, Response $res, callable $next): void {
    $res->allowCors([
        'origin'      => '*',
        'credentials' => false,
        'maxAge'      => 86400,
    ]);
    $next();
});

$app->use(Velix::rateLimit(100, 60));

$app->use(function (Request $req, Response $res, callable $next): void {
    $start = microtime(true);
    $next();
    $duration = round((microtime(true) - $start) * 1000, 2);
    header("X-Response-Time: {$duration}ms");
});

/* =========================
 * Error & 404 Handlers
 * ========================= */
$app->onError(function (\Throwable $e, Request $req, Response $res): void {
    $debug = (getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? 'production')) === 'development';
    $res->status(500)->json([
        'success' => false,
        'error'   => true,
        'message' => $e->getMessage(),
        'trace'   => $debug ? $e->getTraceAsString() : null,
    ]);
});

$app->onNotFound(function (Request $req, Response $res): void {
    $res->status(404)->json([
        'success' => false,
        'error'   => true,
        'message' => "Route [{$req->method}] /{$req->uri} not found",
    ]);
});

/* =========================
 * Route Middleware
 * ========================= */
$auth = function (Request $req, Response $res, callable $next): void {
    $token    = $req->bearerToken();
    $expected = getenv('API_TOKEN') ?: ($_ENV['API_TOKEN'] ?? 'secret');
    if (!$token || $token !== $expected) {
        $res->status(401)->error('Unauthorized', 401);
        return;
    }
    $next();
};

/* =========================
 * Routes
 * ========================= */
$app->get('/', function (Response $res): void {
    $res->success(['version' => '2.1'], 'Welcome to Velix API Framework!');
});

$app->group('/api/v1', function (Router $r) use ($auth): void {

    $r->get('/users', function (Request $req, Response $res): void {
        $users = [
            ['id' => 1, 'name' => 'Alice', 'role' => 'admin'],
            ['id' => 2, 'name' => 'Bob',   'role' => 'member'],
        ];
        $res->paginate($users, 2, 1, 10);
    });

    $r->get('/users/{id:\d+}', function (int $id, Request $req, Response $res): void {
        $res->success([
            'id'   => $id,
            'name' => 'User #' . $id,
            'ip'   => $req->ip(),
        ]);
    });

    $r->post('/users', function (Request $req, Response $res): void {
        $data = $req->validate([
            'name'  => 'required|string|min:2|max:50',
            'email' => 'required|email',
            'role'  => 'nullable|in:admin,member',
        ]);

        $res->created($data, 'User registered successfully');
    });

    $r->put('/users/{id:\d+}', function (int $id, Request $req, Response $res): void {
        $data = $req->validate([
            'name'  => 'string|min:2',
            'email' => 'email',
        ]);

        $res->success(['id' => $id, 'updated' => $data], "User #{$id} updated");
    });

    $r->delete('/users/{id:\d+}', function (int $id, Response $res): void {
        $res->success(null, "User #{$id} deleted successfully");
    })->middleware($auth);

    $r->post('/upload', function (Request $req, Response $res): void {
        $file = $req->file('avatar');
        if (!$file || !$file->isValid()) {
            $res->error('Valid avatar file is required', 400);
            return;
        }

        $savedPath = $file->store(__DIR__ . '/uploads');
        if (!$savedPath) {
            $res->error('Failed to save uploaded file', 500);
            return;
        }

        $res->created([
            'filename'  => basename($savedPath),
            'extension' => $file->getExtension(),
            'size'      => $file->size,
        ], 'File uploaded successfully');
    });

});

$app->group('/api/v1/secure', function (Router $r): void {
    $r->get('/profile', function (Request $req, Response $res): void {
        $res->success([
            'ip'     => $req->ip(),
            'secure' => $req->isSecure(),
            'agent'  => $req->userAgent(),
        ], 'Authenticated profile');
    });
}, $auth);

$app->get('/set-cookie', function (Response $res): void {
    $res->cookie('theme', 'dark', [
            'expires'  => time() + 3600,
            'httponly' => true,
            'samesite' => 'Lax',
        ])
        ->success(['theme' => 'dark'], 'Cookie set successfully');
});

$app->get('/download/{file}', function (string $file, Response $res): void {
    $file     = basename($file);
    $filePath = __DIR__ . '/files/' . $file;
    $res->download($filePath);
});

/* =========================
 * Run
 * ========================= */
$app->dispatch();
