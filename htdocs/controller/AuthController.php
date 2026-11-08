<?php

declare(strict_types=1);

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use PragmaRX\Google2FA\Google2FA;

final class AuthController
{
    private Google2FA $google2fa;

    public function __construct(private PDO $pdo)
    {
        $this->google2fa = new Google2FA();
    }

    // Register akun baru dan membuat QR Google Authenticator.
    public function register(): void
    {
        $body = $this->requestBody();

        $name = trim((string) ($body['name'] ?? ''));
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $password = (string) ($body['password'] ?? '');

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
            fail('Nama, email valid, dan password minimal 8 karakter wajib diisi.');
        }

        $check = $this->pdo->prepare('SELECT id FROM users WHERE email = :email');
        $check->execute([':email' => $email]);

        if ($check->fetch()) {
            fail('Email sudah terdaftar.', 409);
        }

        $secret = $this->google2fa->generateSecretKey();

        $statement = $this->pdo->prepare(
            'INSERT INTO users (name, email, password_hash, google2fa_secret) VALUES (:name, :email, :password, :secret)'
        );

        $statement->execute([
            ':name' => $name,
            ':email' => $email,
            ':password' => password_hash($password, PASSWORD_DEFAULT),
            ':secret' => $secret,
        ]);

        $otpAuthUrl = $this->google2fa->getQRCodeUrl('Delira', $email, $secret);
        $writer = new Writer(new ImageRenderer(new RendererStyle(256), new SvgImageBackEnd()));

        respond([
            'registered' => true,
            'message' => 'Akun berhasil dibuat. Scan QR menggunakan Google Authenticator.',
            'user_id' => (int) $this->pdo->lastInsertId(),
            'account' => $email,
            'secret' => $secret,
            'otp_auth_url' => $otpAuthUrl,
            'qr_code' => $writer->writeString($otpAuthUrl),
        ], 201);
    }

    // Verifikasi OTP pertama setelah register.
    public function verifyRegister(): void
    {
        $body = $this->requestBody();

        $userId = (int) ($body['user_id'] ?? 0);
        $code = (string) ($body['code'] ?? '');

        if ($userId <= 0 || !preg_match('/^\d{6}$/', $code)) {
            fail('User ID dan kode OTP 6 digit wajib diisi.');
        }

        $user = $this->findUser($userId);

        if (!$this->google2fa->verifyKey($user['google2fa_secret'], $code, 1)) {
            fail('Kode Google Authenticator tidak valid.', 401);
        }

        $statement = $this->pdo->prepare(
            'UPDATE users SET totp_verified = 1 WHERE id = :id'
        );
        $statement->execute([':id' => $userId]);

        respond([
            'verified' => true,
            'message' => 'Google Authenticator berhasil diaktifkan.',
        ]);
    }

    // Memeriksa email dan password sebelum OTP.
    public function login(): void
    {
        $body = $this->requestBody();

        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $password = (string) ($body['password'] ?? '');

        $statement = $this->pdo->prepare(
            'SELECT id, password_hash, totp_verified FROM users WHERE email = :email'
        );
        $statement->execute([':email' => $email]);

        $user = $statement->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            fail('Email atau password salah.', 401);
        }

        if ((int) $user['totp_verified'] !== 1) {
            fail('Google Authenticator belum diaktifkan.', 403);
        }

        respond([
            'password_verified' => true,
            'user_id' => (int) $user['id'],
            'message' => 'Password benar. Silakan masukkan kode Google Authenticator.',
        ]);
    }

    
public function verifyLogin(): void
{
    $body = $this->requestBody();

    $userId = (int) ($body['user_id'] ?? 0);
    $code = (string) ($body['code'] ?? '');

    if ($userId <= 0 || !preg_match('/^\d{6}$/', $code)) {
        fail('User ID dan kode OTP 6 digit wajib diisi.');
    }

    $user = $this->findUser($userId);

    if ((int) $user['totp_verified'] !== 1) {
        fail('Google Authenticator belum diaktifkan.', 403);
    }

    if (!$this->google2fa->verifyKey($user['google2fa_secret'], $code, 1)) {
        fail('Kode Google Authenticator tidak valid.', 401);
    }

    // Membuat token login acak.
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expiresAt = date('Y-m-d H:i:s', time() + 86400);

    // Menyimpan hash token ke database.
    $statement = $this->pdo->prepare(
        'INSERT INTO api_tokens (user_id, token_hash, expires_at)
         VALUES (:user_id, :token_hash, :expires_at)'
    );

    $statement->execute([
        ':user_id' => $userId,
        ':token_hash' => $tokenHash,
        ':expires_at' => $expiresAt,
    ]);

    respond([
        'authenticated' => true,
        'token_type' => 'Bearer',
        'access_token' => $token,
        'expires_at' => $expiresAt,
        'user_id' => (int) $user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'message' => 'Authentication successful.',
    ]);
}
    
public function logout(): void
{
    // Pastikan token yang digunakan masih valid.
    $this->requireAuthentication();

    $headers = function_exists('getallheaders') ? getallheaders() : [];

    $authorization = $headers['Authorization']
        ?? $headers['authorization']
        ?? $_SERVER['HTTP_AUTHORIZATION']
        ?? '';

    preg_match('/^Bearer\s+([a-f0-9]{64})$/i', trim($authorization), $matches);

    // Hapus token dari database.
    $statement = $this->pdo->prepare(
        'DELETE FROM api_tokens WHERE token_hash = :token_hash'
    );

    $statement->execute([
        ':token_hash' => hash('sha256', $matches[1]),
    ]);

    respond([
        'logged_out' => true,
        'message' => 'Logout successful.',
    ]);
}

public function requireAuthentication(): array
{
    // Membaca Bearer Token dari header request.
    $headers = function_exists('getallheaders') ? getallheaders() : [];

    $authorization = $headers['Authorization']
        ?? $headers['authorization']
        ?? $_SERVER['HTTP_AUTHORIZATION']
        ?? '';

    if (!preg_match('/^Bearer\s+([a-f0-9]{64})$/i', trim($authorization), $matches)) {
        fail('Bearer token required.', 401);
    }

    // Mencocokkan hash token dengan database.
    $statement = $this->pdo->prepare(
        'SELECT users.id, users.name, users.email
         FROM api_tokens
         JOIN users ON users.id = api_tokens.user_id
         WHERE api_tokens.token_hash = :token_hash
         AND api_tokens.expires_at > NOW()
         LIMIT 1'
    );

    $statement->execute([
        ':token_hash' => hash('sha256', $matches[1]),
    ]);

    $user = $statement->fetch();

    if (!$user) {
        fail('Invalid or expired token.', 401);
    }

    return $user;
}


    private function findUser(int $id): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM users WHERE id = :id'
        );
        $statement->execute([':id' => $id]);

        $user = $statement->fetch();

        if (!$user) {
            fail('User not found.', 404);
        }

        return $user;
    }

    private function requestBody(): array
    {
        $body = json_decode(file_get_contents('php://input'), true);

        if (!is_array($body)) {
            fail('Request body must be valid JSON.');
        }

        return $body;
    }
}
