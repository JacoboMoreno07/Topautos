<?php
// Actualiza estas constantes con los datos reales de tu servidor MySQL.
const DB_HOST = '127.0.0.1';
const DB_NAME = 'topautos';
const DB_USER = 'root';
const DB_PASS = '';

$dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME);
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (PDOException $e) {
    // Si la conexion falla dejamos $pdo como null para que el sitio haga fallback a los datos mock.
    $pdo = null;
}

// ─────────────────────────────────────────────────────────
// CREDENCIALES DEL PORTAL (unicas, no se permite registro)
// Para cambiar la clave, genera un nuevo hash con:
//   php -r "echo password_hash('TU_NUEVA_CLAVE', PASSWORD_BCRYPT, ['cost' => 12]);"
// y reemplaza el valor de PORTAL_PASS_HASH.
// ─────────────────────────────────────────────────────────
const PORTAL_USERNAME  = 'TopAutos2026';
const PORTAL_PASS_HASH = '$2y$12$BCeMEyAX89yUi9mz4McevOgyc.uh4Whkopf5TP0XHOUqqDZwKtYI2';

// Proteccion contra fuerza bruta
const PORTAL_MAX_ATTEMPTS    = 5;     // Intentos maximos antes de bloqueo
const PORTAL_LOCKOUT_SECONDS = 900;   // 15 minutos de bloqueo

/**
 * Genera un token CSRF y lo guarda en la sesion.
 */
function csrf_token(): string
{
    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf_token'];
}

/**
 * Verifica que el token CSRF enviado coincida con el de la sesion.
 */
function csrf_verify(string $token): bool
{
    return !empty($_SESSION['_csrf_token']) && hash_equals($_SESSION['_csrf_token'], $token);
}

/**
 * Regenera el token CSRF (usar despues de una accion exitosa).
 */
function csrf_regenerate(): void
{
    $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
}

/**
 * Revisa si la IP esta bloqueada por demasiados intentos fallidos.
 */
function is_login_locked(): bool
{
    $attempts = $_SESSION['login_attempts'] ?? 0;
    $lockUntil = $_SESSION['login_lock_until'] ?? 0;

    if ($lockUntil > 0 && time() < $lockUntil) {
        return true;
    }

    // Si ya paso el bloqueo, resetear
    if ($lockUntil > 0 && time() >= $lockUntil) {
        $_SESSION['login_attempts'] = 0;
        $_SESSION['login_lock_until'] = 0;
    }

    return false;
}

/**
 * Registra un intento fallido de login.
 */
function record_failed_login(): void
{
    $attempts = ($_SESSION['login_attempts'] ?? 0) + 1;
    $_SESSION['login_attempts'] = $attempts;

    if ($attempts >= PORTAL_MAX_ATTEMPTS) {
        $_SESSION['login_lock_until'] = time() + PORTAL_LOCKOUT_SECONDS;
    }
}

/**
 * Resetea los intentos de login tras un acceso exitoso.
 */
function reset_login_attempts(): void
{
    $_SESSION['login_attempts'] = 0;
    $_SESSION['login_lock_until'] = 0;
}

/**
 * Valida las credenciales del portal contra las constantes.
 */
function portal_authenticate(string $username, string $password): bool
{
    // Comparacion timing-safe del usuario
    $userOk = hash_equals(PORTAL_USERNAME, $username);
    // Verificacion bcrypt de la clave
    $passOk = password_verify($password, PORTAL_PASS_HASH);

    return $userOk && $passOk;
}
