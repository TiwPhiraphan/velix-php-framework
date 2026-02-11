# Velix PHP Framework

Velix is a lightweight and flexible PHP micro-framework designed to make web development fast, expressive, and intuitive. It focuses on simplicity while still providing modern features such as middleware, CORS handling, and flexible route handlers.

Velix is ideal for building small APIs, backend services, or lightweight web applications without the overhead of a full-stack framework.

## ✨ Features

- Simple Routing  
  Define routes for GET, POST, PUT, PATCH, DELETE, and more with a clean API.

- Flexible Route Handlers  
  Route handlers can accept parameters in any order:
  - Request
  - Response
  - Route parameters (e.g. {id})

- Middleware System  
  Global middleware support for CORS, authentication, logging, etc.

- Request Handling  
  Easily access:
  - Query parameters
  - Form data
  - JSON payloads
  - Headers
  - Route parameters

- Response Control  
  Fluent API for:
  - Headers
  - Cookies
  - Status codes
  - JSON / text responses
  - Redirects

- CORS Support  
  Built-in and configurable CORS handling with sensible defaults.

- UTF-8 Ready  
  Full UTF-8 support for multilingual URLs and payloads.

- Static File Serving  
  Automatically serves static files from the public directory.

- Lightweight  
  No dependencies. One PHP file. Easy to extend.

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

- Place Velix.php in the project root
- Ensure the public directory contains app.php
- Configure your web server to route all requests to public/app.php

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

Create public/app.php:

```php
<?php

require_once __DIR__ . '/../Velix.php';

$app = new Velix();

/* Simple GET route */
$app->get('/', function () {
    return ['message' => 'Welcome to Velix!'];
});

/* Dynamic route with parameters */
$app->get('/user/{id}', function ($id, Request $req) {
    return [
        'user_id' => $id,
        'query' => $req->query('name')
    ];
});

/* POST route with JSON handling */
$app->post('/data', function (Request $req, Response $res) {
    $res->status(201)->json([
        'received' => $req->json
    ]);
});

$app->dispatch();
```
Start your server and Velix will handle the rest!

## 🔀 Flexible Route Handlers

Velix intelligently injects arguments into route handlers.

### Supported Arguments

- Request / req  
  The Request object

- Response / res  
  The Response object

- Route Parameters  
  Automatically injected when names match placeholders

- Optional Arguments  
  Missing parameters are passed as null

### Example

```php
$app->get('/post/{id}', function ($id, Request $req) {
    return [
        'post_id' => $id,
        'category' => $req->query('category', 'general')
    ];
});
```

Using Response:

```php
$app->get('/cookie', function (Response $res) {
    $res->cookie('theme', 'dark', [
        'expire' => time() + 3600,
        'path' => '/'
    ])->json([
        'message' => 'Cookie set!'
    ]);
});
```

## 🌐 CORS Configuration

Velix provides built-in CORS support via Response::allowCors().

### Supported Options

- origin → string
- credentials → boolean
- headers → string | array
- methods → string | array

### Default Configuration

```txt
origin: *
credentials: false
headers: Content-Type, Authorization, X-Requested-With
methods: GET, POST, PUT, PATCH, DELETE, OPTIONS
```

### Usage Example

```php
$app->use(function (Request $req, Response $res, $next) {
    $res->allowCors([
        'origin' => 'https://frontend.com',
        'credentials' => true
    ]);

    if ($req->method === 'OPTIONS') {
        $res->status(204)->send();
    }

    return $next();
});
```

## 📥 Request Object

The Request object provides easy access to incoming data:

- $req->method — HTTP method
- $req->uri — Request URI
- $req->params — Route parameters
- $req->query($key, $default)
- $req->input($key, $default)
- $req->header($key, $default)
- $req->json — Parsed JSON body

### Example

```php
$app->post('/submit', function (Request $req) {
    return [
        'name' => $req->input('name', 'Guest'),
        'token' => $req->header('Authorization'),
        'page' => $req->query('page', 1)
    ];
});
```

## 📤 Response Object

The Response object supports a fluent, chainable API:

- $res->header($name, $value)
- $res->cookie($name, $value, $options)
- $res->status($code)
- $res->json($data)
- $res->text($string)
- $res->redirect($url)
- $res->send($body)

### Example

```php
$app->get('/api', function (Response $res) {
    $res->header('X-Version', '1.0')
        ->status(200)
        ->json(['status' => 'ok']);
});
```

## 📂 Static Files

Velix automatically serves static files from the public directory.  
If no route matches, it will attempt to serve public/index.html before returning 404 Not Found.

This makes Velix ideal for SPAs built with React, Vue, or Angular.

## ❌ Error Handling

- Unhandled exceptions are caught and returned as JSON responses
- Missing routes return:
  - public/index.html (if exists)
  - Otherwise 404 Not Found

## 🤔 Why Velix?

- Minimal setup — One file, instant productivity
- Developer-friendly — Express-like routing in PHP
- Lightweight — No heavy abstractions
- Extensible — Easy to add middleware and features

## 📄 License

Velix is open-source software licensed under the MIT License.
