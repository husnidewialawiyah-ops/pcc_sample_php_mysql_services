<?php

declare(strict_types=1);

function registerApiRoutes(ProductController $controller, AuthController $authController): void
{
    $method = $_SERVER['REQUEST_METHOD'];
    $path = trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/');
    $segments = $path === '' ? [] : explode('/', $path);

    // GET /login - detail konfigurasi TOTP dan QR code untuk Google Authenticator.
    if ($segments === ['login'] && $method === 'GET') {
        $authController->setup();
    }
    // POST /login - verifikasi kode 6 digit Google Authenticator.
    if ($segments === ['login'] && $method === 'POST') {
        $authController->login();
    }
    if ($segments === ['login']) {
        fail('Method not allowed.', 405);
    }

    // GET / - halaman login.
    if ($segments === [] && $method === 'GET') {
        header('Content-Type: text/html; charset=utf-8');
        require __DIR__ . '/../views/LoginView.php';
        return;
    }
    // GET /health - health check service.
    if ($segments === ['health']) {
        respond(['status' => 'ok', 'service' => 'products-api']);
    }
    if (($segments[0] ?? '') !== 'products') {
        fail('Route not found.', 404);
    }

    // GET /products/categories - daftar kategori dengan slug dan URL.
    if ($method === 'GET' && ($segments[1] ?? '') === 'categories') {
        $controller->categories();
    }
    // GET /products/category-list - daftar nilai kategori.
    if ($method === 'GET' && ($segments[1] ?? '') === 'category-list') {
        $controller->categoryList();
    }
        // GET /products/search?q=phone - pencarian produk.
    if ($method === 'GET' && ($segments[1] ?? '') === 'search') {
        $controller->index();
        return;
    }
    // GET /products/category/{category} - produk berdasarkan kategori.
    if ($method === 'GET' && ($segments[1] ?? '') === 'category' && isset($segments[2])) {
        $_GET['category'] = $segments[2];
        $controller->index();
    }
    // GET /products/{id} - detail satu produk.
    if ($method === 'GET' && isset($segments[1]) && ctype_digit($segments[1])) {
        $controller->show((int) $segments[1]);
    }
    // GET /products - daftar produk dengan filter, sorting, dan pagination.
    if ($method === 'GET' && count($segments) === 1) {
        $controller->index();
    }
    // POST /products atau POST /products/add - tambah produk.
    if ($method === 'POST' && (($segments[1] ?? '') === 'add' || !isset($segments[1]))) {
        $controller->store();
    }
    // PUT/PATCH /products/{id} - ubah produk.
    if (in_array($method, ['PUT', 'PATCH'], true) && isset($segments[1]) && ctype_digit($segments[1])) {
        $controller->update((int) $segments[1]);
    }
    // DELETE /products/{id} - hapus produk.
    if ($method === 'DELETE' && isset($segments[1]) && ctype_digit($segments[1])) {
        $controller->destroy((int) $segments[1]);
    }

    fail('Route not found.', 404);
}