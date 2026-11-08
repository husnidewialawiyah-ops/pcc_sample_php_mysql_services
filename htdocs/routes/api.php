<?php

declare(strict_types=1);

function registerApiRoutes(ProductController $controller, AuthController $authController): void
{
    $method = $_SERVER['REQUEST_METHOD'];
    $path = trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/');
    $segments = $path === '' ? [] : explode('/', $path);

    // POST /register - membuat akun dan QR Google Authenticator.
    if ($segments === ['register'] && $method === 'POST') {
        $authController->register();
    }

    // POST /register/verify - mengaktifkan Google Authenticator.
    if ($segments === ['register', 'verify'] && $method === 'POST') {
        $authController->verifyRegister();
    }

    // POST /login - memeriksa email dan password.
    if ($segments === ['login'] && $method === 'POST') {
        $authController->login();
    }

    // POST /logout - mengakhiri sesi login.
    if ($segments === ['logout'] && $method === 'POST') {
    $authController->logout();
    }

    // POST /login/verify - memeriksa kode OTP saat login.
    if ($segments === ['login', 'verify'] && $method === 'POST') {
        $authController->verifyLogin();
    }


    // GET / - halaman utama API.
    if ($segments === [] && $method === 'GET') {
        header('Content-Type: text/html; charset=utf-8');
        require __DIR__ . '/../views/LoginView.php';
        return;
    }

    // GET /health - health check.
    if ($segments === ['health'] && $method === 'GET') {
        respond(['status' => 'ok', 'service' => 'products-api']);
    }

    // Route autentikasi yang tidak sesuai method.
    if (in_array($segments[0] ?? '', ['login', 'register'], true)) {
        fail('Method not allowed.', 405);
    }

    if (($segments[0] ?? '') !== 'products') {
        fail('Route not found.', 404);
    }

    // Semua endpoint produk wajib menggunakan Bearer Token.
    $authController->requireAuthentication();

    if ($method === 'GET' && ($segments[1] ?? '') === 'categories') {
        $controller->categories();
    }

    if ($method === 'GET' && ($segments[1] ?? '') === 'category-list') {
        $controller->categoryList();
    }

    if ($method === 'GET' && ($segments[1] ?? '') === 'search') {
        $controller->index();
        return;
    }

    if ($method === 'GET' && ($segments[1] ?? '') === 'category' && isset($segments[2])) {
        $_GET['category'] = $segments[2];
        $controller->index();
    }

    if ($method === 'GET' && isset($segments[1]) && ctype_digit($segments[1])) {
        $controller->show((int) $segments[1]);
    }

    if ($method === 'GET' && count($segments) === 1) {
        $controller->index();
    }

    if ($method === 'POST' && (($segments[1] ?? '') === 'add' || !isset($segments[1]))) {
        $controller->store();
    }

    if (in_array($method, ['PUT', 'PATCH'], true) && isset($segments[1]) && ctype_digit($segments[1])) {
        $controller->update((int) $segments[1]);
    }

    if ($method === 'DELETE' && isset($segments[1]) && ctype_digit($segments[1])) {
        $controller->destroy((int) $segments[1]);
    }

    fail('Route not found.', 404);
}
