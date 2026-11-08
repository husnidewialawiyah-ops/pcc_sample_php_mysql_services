<?php

declare(strict_types=1);

require_once __DIR__ . '/Rest.php';
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/controller/AuthController.php';
require_once __DIR__ . '/controller/ProductController.php';
require_once __DIR__ . '/routes/api.php';

try {
    $pdo = Config::database();

    $authController = new AuthController($pdo);
    $controller = new ProductController($pdo);

    $controller->initialiseDatabase();

    registerApiRoutes($controller, $authController);
} catch (Throwable $exception) {
    error_log($exception->getMessage());
    $status = $exception->getCode() === 503 ? 503 : 500;
    fail($status === 503 ? 'Database connection failed.' : 'Internal server error.', $status);
}