<?php
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (!empty($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

$csp = "default-src 'self'; img-src 'self' data: https:; media-src 'self' https:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://assets.juicer.io; font-src 'self' https://fonts.gstatic.com data:; script-src 'self' 'unsafe-inline'; connect-src 'self' https:; frame-src https://www.google.com; base-uri 'self'; form-action 'self'; upgrade-insecure-requests";
header("Content-Security-Policy: {$csp}");
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
header('X-XSS-Protection: 0');
if ($isHttps) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
}
$pdo = null;
$configPath = __DIR__ . '/config.php';
if (file_exists($configPath)) {
    require_once $configPath;
}

$loginError = '';
$flashSuccess = '';
$flashError = '';

if (isset($_POST['logout'])) {
    session_destroy();
    header('Location: dashboard.php');
    exit;
}

if (isset($_POST['login'])) {
    if (is_login_locked()) {
        $remaining = max(1, (int) ceil((($_SESSION['login_lock_until'] ?? 0) - time()) / 60));
        $loginError = "Demasiados intentos fallidos. Intenta en {$remaining} minuto(s).";
    } else {
        $user = trim($_POST['user'] ?? '');
        $pass = $_POST['pass'] ?? '';
        if (portal_authenticate($user, $pass)) {
            reset_login_attempts();
            session_regenerate_id(true);
            $_SESSION['ta_logged'] = true;
        } else {
            record_failed_login();
            usleep(random_int(300000, 800000));
            $loginError = 'Credenciales no validas';
        }
    }
}

if (!empty($_SESSION['ta_logged']) && isset($_POST['create_car'])) {
    if (!$pdo) {
        $flashError = 'Configura la base de datos en config.php antes de crear vehiculos.';
    } else {
        $brand = trim($_POST['brand'] ?? '');
        $model = trim($_POST['model'] ?? '');
        $price = trim($_POST['price'] ?? '');
        $tagline = trim($_POST['tagline'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $year = trim($_POST['year'] ?? '');
        $km = trim($_POST['km'] ?? '');
        $transmission = trim($_POST['transmission'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $image = trim($_POST['image'] ?? '');

        if ($brand && $model && $price && $category && $year && $image) {
            try {
                $stmt = $pdo->prepare('INSERT INTO cars (brand, model, price, tagline, category, year, km, transmission, description, image_url) VALUES (?,?,?,?,?,?,?,?,?,?)');
                $stmt->execute([$brand, $model, $price, $tagline, $category, $year, $km, $transmission, $description, $image]);
                $flashSuccess = 'Vehiculo agregado correctamente.';
            } catch (Throwable $t) {
                $flashError = 'Error al guardar: ' . $t->getMessage();
            }
        } else {
            $flashError = 'Los campos Basicos (marca, modelo, precio, categoria, ano, imagen) son obligatorios.';
        }
    }
}

$cars = [];
if ($pdo) {
    try {
        $cars = $pdo->query('SELECT * FROM cars ORDER BY created_at DESC')->fetchAll();
    } catch (Throwable $t) {
        $flashError = 'No fue posible leer el inventario (' . $t->getMessage() . ')';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Dashboard Top Autos</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600&family=Unbounded:wght@600&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #04050c;
            --panel: #0c0f1f;
            --card: rgba(13, 15, 28, 0.85);
            --text: #fdfbf5;
            --muted: #a5abc3;
            --accent: #f7c948;
            --accent-strong: #ff8c32;
            --stroke: rgba(255,255,255,0.08);
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: 'Space Grotesk', sans-serif;
            background: radial-gradient(circle at top, #11163a 0%, var(--bg) 55%);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }
        .shell {
            width: min(1200px, 100%);
            background: var(--panel);
            padding: 2rem;
            border-radius: 24px;
            border: 1px solid var(--stroke);
            box-shadow: 0 30px 80px rgba(0,0,0,0.45);
        }
        h1 { font-family: 'Unbounded', cursive; margin-top: 0; text-transform: uppercase; }
        form.login {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            max-width: 400px;
            margin: 0 auto;
        }
        input, textarea, select {
            width: 100%;
            padding: 0.85rem 1rem;
            border-radius: 14px;
            border: 1px solid var(--stroke);
            background: rgba(255,255,255,0.04);
            color: var(--text);
            font-family: inherit;
        }
        textarea { min-height: 110px; }
        button {
            border: none;
            cursor: pointer;
            border-radius: 999px;
            padding: 0.95rem 1.6rem;
            font-weight: 600;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            background: linear-gradient(120deg, var(--accent), var(--accent-strong));
            color: #080808;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        button:hover { transform: translateY(-3px); box-shadow: 0 10px 25px rgba(0,0,0,0.4); }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
        }
        .card {
            background: var(--card);
            border: 1px solid var(--stroke);
            border-radius: 18px;
            padding: 1.5rem;
        }
        .inventory table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }
        .inventory th, .inventory td {
            padding: 0.7rem 0.4rem;
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }
        .inventory th { text-align: left; color: var(--muted); font-weight: 500; }
        .alert {
            padding: 0.8rem 1rem;
            border-radius: 12px;
            margin-bottom: 1rem;
        }
        .alert.success { background: rgba(57, 172, 104, 0.12); color: #7ef3b5; }
        .alert.error { background: rgba(255, 76, 76, 0.12); color: #ffb3b3; }
        .dashboard-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
        }
        .dashboard-head form { margin: 0; }
        @media (max-width: 640px) {
            body { padding: 1rem; }
            .shell { padding: 1.5rem; }
        }
    </style>
</head>
<body>
    <div class="shell">
        <?php if (empty($_SESSION['ta_logged'])): ?>
            <h1>Acceso exclusivo</h1>
            <?php if ($loginError): ?><div class="alert error"><?php echo htmlspecialchars($loginError); ?></div><?php endif; ?>
            <form method="post" class="login">
                <input type="text" name="user" placeholder="Usuario" required>
                <input type="password" name="pass" placeholder="Clave" required>
                <button type="submit" name="login">Entrar</button>
            </form>
        <?php else: ?>
            <div class="dashboard-head">
                <h1>Panel Top Autos</h1>
                <form method="post">
                    <button type="submit" name="logout">Cerrar sesion</button>
                </form>
            </div>
            <?php if ($flashSuccess): ?><div class="alert success"><?php echo htmlspecialchars($flashSuccess); ?></div><?php endif; ?>
            <?php if ($flashError): ?><div class="alert error"><?php echo htmlspecialchars($flashError); ?></div><?php endif; ?>
            <div class="grid">
                <div class="card">
                    <h2>Crear vehiculo</h2>
                    <form method="post">
                        <input type="text" name="brand" placeholder="Marca" required>
                        <input type="text" name="model" placeholder="Modelo" required>
                        <input type="text" name="price" placeholder="Precio (ej: $81.900.000)" required>
                        <input type="text" name="tagline" placeholder="Tagline / frase corta">
                        <select name="category" required>
                            <option value="">Categoria</option>
                            <option value="suv">SUV</option>
                            <option value="sedan">Sedan</option>
                            <option value="pickup">Pickup</option>
                            <option value="deportivo">Deportivo</option>
                        </select>
                        <input type="text" name="year" placeholder="Año" required>
                        <input type="text" name="km" placeholder="Kilometraje">
                        <input type="text" name="transmission" placeholder="Transmision">
                        <textarea name="description" placeholder="Descripcion / highlights"></textarea>
                        <input type="url" name="image" placeholder="URL de la imagen principal" required>
                        <button type="submit" name="create_car">Guardar vehiculo</button>
                    </form>
                </div>
                <div class="card inventory">
                    <h2>Inventario reciente</h2>
                    <?php if (!$pdo): ?>
                        <p>Sin conexion a base de datos. Configura tus credenciales en <code>config.php</code>.</p>
                    <?php elseif (!$cars): ?>
                        <p>Todavia no hay vehiculos cargados.</p>
                    <?php else: ?>
                        <div style="max-height:420px; overflow:auto;">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Marca</th>
                                        <th>Modelo</th>
                                        <th>Categoria</th>
                                        <th>Precio</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($cars as $car): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($car['brand']); ?></td>
                                            <td><?php echo htmlspecialchars($car['model']); ?></td>
                                            <td><?php echo htmlspecialchars($car['category']); ?></td>
                                            <td><?php echo htmlspecialchars($car['price']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
