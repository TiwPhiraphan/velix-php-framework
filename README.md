# Velix PHP Framework

Velix is a lightweight and flexible PHP micro-framework designed to make web development fast, expressive, and intuitive. It focuses on simplicity while still providing modern features such as middleware, CORS handling, input validation, rate limiting, and flexible route handlers.

Velix is ideal for building RESTful APIs, microservices, or lightweight web applications without the overhead of a full-stack framework.

## ✨ Features

- **Simple & Expressive Routing**  
  Define routes for GET, POST, PUT, PATCH, DELETE, and Resource CRUD with regex constraints.

- **Request Validation**  
  Built-in input validation with automatic 422 Unprocessable Entity responses.

- **Standard API Responses**  
  Clean helpers for standardized JSON: `success()`, `created()`, `error()`, `paginate()`, and `noContent()`.

- **Built-in Rate Limiting**  
  Zero-dependency rate limiter with standard `X-RateLimit-*` and `Retry-After` headers.

- **File Uploads**  
  Fluent `UploadedFile` wrapper for safe storage and validation.

- **Flexible Route Handlers**  
  Route handlers can accept parameters in any order:
  - Request
  - Response
  - Route parameters (e.g. `{id:\d+}`)

- **Middleware System**  
  Global, group, and route-level middleware supporting before & after execution.

- **CORS Support**  
  Built-in, fully W3C-compliant CORS handling supporting single or multiple origins.

- **Lightweight**  
  Zero dependencies. One core PHP file. Ready for production.

## 📦 Installation

### 1. Clone or create the project

```bash
git clone https://github.com/TiwPhiraphan/velix-php-framework.git app-name
```

or using Bun:

```bash
bun create TiwPhiraphan/velix-php-framework app-name
```

### 2. Project setup

- Place `Velix.php` in the project root
- Ensure the `public/` directory contains `app.php`
- Configure your web server to route requests to `public/app.php`

## 📁 Project Structure

```
.
├── Velix.php
├── .htaccess
└── public/
    ├── app.php
    └── index.html
```

## 🚀 Quick Start

Create `public/app.php`:

```php
<?php

require_once __DIR__ . '/../Velix.php';

$app = new Velix();

// Global rate limiting
$app->use(Velix::rateLimit(100, 60));

// Simple GET route
$app->get('/', function (Response $res) {
    $res->success(['version' => '2.1'], 'Welcome to Velix!');
});

// Route with regex constraint
$app->get('/users/{id:\d+}', function (int $id, Response $res) {
    $res->success(['id' => $id, 'name' => "User #{$id}"]);
});

// POST route with validation
$app->post('/users', function (Request $req, Response $res) {
    $data = $req->validate([
        'name'  => 'required|string|min:2',
        'email' => 'required|email',
        'role'  => 'nullable|in:admin,member',
    ]);

    $res->created($data, 'User registered successfully');
});

$app->dispatch();
```

## 🛡️ Input Validation

Velix includes a built-in validator. When validation fails, it throws a `ValidationException` that Velix automatically converts into a `422 Unprocessable Entity` JSON response.

```php
$data = $req->validate([
    'title'    => 'required|string|min:3|max:100',
    'email'    => 'required|email',
    'age'      => 'nullable|integer|min:18',
    'status'   => 'in:draft,published,archived',
]);
```

### Supported Validation Rules

| Rule | Description |
| --- | --- |
| `required` | Value must be present and not empty |
| `nullable` | Allows `null` or empty values |
| `string` | Must be a string |
| `numeric` | Must be a number or numeric string |
| `integer` / `int` | Must be a valid integer |
| `bool` / `boolean` | Must be a boolean (`true`, `false`, `1`, `0`) |
| `email` | Must be a valid email address |
| `array` | Must be an array |
| `min:value` | Minimum string length or numeric value |
| `max:value` | Maximum string length or numeric value |
| `in:foo,bar` | Value must be in the specified list |

## 📤 Standard API Responses

The `Response` object provides helpers for consistent API structures:

```php
// 200 OK: { success: true, message: "...", data: [...] }
$res->success($data, 'Data retrieved');

// 201 Created: { success: true, message: "...", data: [...] }
$res->created($newItem, 'Created successfully');

// 400 Bad Request (or custom status): { success: false, error: true, message: "...", errors: [...] }
$res->error('Invalid credentials', 401);

// 204 No Content
$res->noContent();

// Paginated response
$res->paginate($users, $totalCount, $page, $perPage);
```

## ⏱️ Built-in Rate Limiting

Protect your API endpoints from abuse with zero dependencies:

```php
// Global: 100 requests per 60 seconds per IP
$app->use(Velix::rateLimit(100, 60));

// Specific route: 5 attempts per minute
$app->post('/login', $loginHandler)->middleware(Velix::rateLimit(5, 60));
```

Automatically sends:
- `X-RateLimit-Limit`
- `X-RateLimit-Remaining`
- `X-RateLimit-Reset`
- `Retry-After` (when status `429 Too Many Requests` is reached)

## 🔀 Route Constraints & Regex

You can enforce parameter types directly in the URL:

```php
// Only matches numbers
$app->get('/users/{id:\d+}', $handler);

// Only matches slug formats
$app->get('/posts/{slug:[a-z0-9-]+}', $handler);

// Matches standard UUIDs
$app->get('/orders/{uuid:[0-9a-fA-F-]{36}}', $handler);
```

## 📁 File Uploads

Upload files easily with the `UploadedFile` wrapper:

```php
$app->post('/upload', function (Request $req, Response $res) {
    $file = $req->file('avatar');

    if (!$file || !$file->isValid()) {
        $res->error('No valid file uploaded', 400);
        return;
    }

    $savedPath = $file->store(__DIR__ . '/uploads');
    $res->created([
        'filename'  => basename($savedPath),
        'mime'      => $file->getMimeType(),
        'size'      => $file->size,
    ]);
});
```

## 🌐 CORS Configuration

Velix provides built-in CORS support via `Response::allowCors()`:

```php
$app->use(function (Request $req, Response $res, $next) {
    $res->allowCors([
        'origin'      => ['https://my-app.com', 'https://admin.my-app.com'],
        'credentials' => true,
        'maxAge'      => 86400,
    ]);

    return $next();
});
```

OPTIONS preflight requests are automatically handled and answered with appropriate CORS headers and status `204 No Content`.

## 📥 Request Helpers

- `$req->method` — HTTP method
- `$req->uri` — Request URI
- `$req->params` — Route parameters
- `$req->query($key, $default)` — Query parameter
- `$req->input($key, $default)` — Body, JSON, or Query input
- `$req->all()` — All inputs merged
- `$req->header($key, $default)` — Request header
- `$req->bearerToken()` — Extracted Bearer token
- `$req->ip()` — Real client IP (supports Cloudflare & reverse proxies)
- `$req->isSecure()` — Check if HTTPS
- `$req->userAgent()` — Client User-Agent
- `$req->fullUrl()` — Full URL string
- `$req->file($key)` — UploadedFile instance
- `$req->validate($rules)` — Validate inputs

## 📄 License

Velix is open-source software licensed under the MIT License.
