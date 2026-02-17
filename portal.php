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

$messages = [
    'errors' => [],
    'success' => []
];

if (!empty($_SESSION['portal_flash_success'])) {
    $messages['success'][] = $_SESSION['portal_flash_success'];
    unset($_SESSION['portal_flash_success']);
}

$portalUser = $_SESSION['portal_user'] ?? null;

function columnExists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
        $stmt->execute([$column]);
        return (bool) $stmt->fetch();
    } catch (Throwable $th) {
        return false;
    }
}

function ensureUploadsDir(): string
{
    $dir = __DIR__ . '/uploads';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    return $dir;
}

function uploadImage(array $file): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($file['tmp_name'])) {
        return null;
    }

    $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/jpg'];
    $mime = function_exists('mime_content_type') ? mime_content_type($file['tmp_name']) : null;
    if ($mime && !in_array($mime, $allowed, true)) {
        return null;
    }

    $dir = ensureUploadsDir();
    $extension = strtolower(pathinfo($file['name'] ?? 'jpg', PATHINFO_EXTENSION)) ?: 'jpg';
    $filename = uniqid('car_', true) . '.' . $extension;
    $destination = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        return null;
    }

    return 'uploads/' . $filename;
}

function seedCarsIfEmpty(PDO $pdo, array $seedCars, bool $hasLocation, bool $hasGallery): void
{
    try {
        $count = (int) $pdo->query('SELECT COUNT(*) FROM cars')->fetchColumn();
        if ($count > 0) {
            return;
        }
    } catch (Throwable $th) {
        return;
    }

    $baseColumns = ['brand', 'model', 'price', 'tagline', 'category', 'year', 'km', 'transmission', 'description', 'image_url'];
    if ($hasLocation) {
        $baseColumns[] = 'location';
    }
    if ($hasGallery) {
        $baseColumns[] = 'gallery';
    }
    $placeholders = implode(',', array_fill(0, count($baseColumns), '?'));
    $columnsSql = implode(',', $baseColumns);
    $sql = "INSERT INTO cars ({$columnsSql}) VALUES ({$placeholders})";

    foreach ($seedCars as $car) {
        $values = [
            $car['brand'],
            $car['model'],
            $car['price'],
            $car['tagline'],
            $car['category'],
            $car['year'],
            $car['km'],
            $car['transmission'],
            $car['description'],
            $car['image'],
        ];
        if ($hasLocation) {
            $values[] = $car['location'] ?? 'El Poblado, Medellin';
        }
        if ($hasGallery) {
            $galleryJson = !empty($car['gallery']) ? json_encode($car['gallery']) : null;
            $values[] = $galleryJson;
        }
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($values);
        } catch (Throwable $th) {
            // Si falla uno, continuamos con los demas sin romper la pagina.
            continue;
        }
    }
}

if (isset($_POST['logout'])) {
    unset($_SESSION['portal_user']);
    $_SESSION['portal_flash_success'] = 'Sesion cerrada correctamente.';
    $_SESSION['portal_logout_success'] = true;
    session_regenerate_id(true);
    header('Location: portal.php');
    exit;
}

if (isset($_POST['login'])) {
    // Verificar CSRF
    $csrfOk = csrf_verify($_POST['_csrf'] ?? '');
    if (!$csrfOk) {
        $messages['errors'][] = 'Token de seguridad invalido. Recarga la pagina e intenta de nuevo.';
    } elseif (is_login_locked()) {
        $remaining = ($_SESSION['login_lock_until'] ?? 0) - time();
        $minutes = max(1, (int) ceil($remaining / 60));
        $messages['errors'][] = "Demasiados intentos fallidos. Intenta de nuevo en {$minutes} minuto(s).";
    } else {
        $username = trim($_POST['login_user'] ?? '');
        $password = $_POST['login_password'] ?? '';

        if (portal_authenticate($username, $password)) {
            reset_login_attempts();
            session_regenerate_id(true);
            csrf_regenerate();
            $_SESSION['portal_user'] = [
                'name' => PORTAL_USERNAME,
                'role' => 'admin'
            ];
            header('Location: portal.php');
            exit;
        }

        record_failed_login();
        // Demora intencional para dificultar ataques de fuerza bruta
        usleep(random_int(300000, 800000));
        $messages['errors'][] = 'Credenciales incorrectas.';
    }
    csrf_regenerate();
}

$missingCarColumns = [];
$carColumnsReady = false;
$hasLocationColumn = false;
$hasGalleryColumn = false;
if ($pdo instanceof PDO) {
    foreach (['location', 'gallery'] as $column) {
        if (!columnExists($pdo, 'cars', $column)) {
            $missingCarColumns[] = $column;
        }
    }
    $carColumnsReady = empty($missingCarColumns);
    $hasLocationColumn = !in_array('location', $missingCarColumns, true);
    $hasGalleryColumn = !in_array('gallery', $missingCarColumns, true);
}

$seedCars = [
    [
        'brand' => 'KIA',
        'model' => 'Sportage LX',
        'price' => '$81.900.000',
        'tagline' => 'SUV lista para la aventura urbana',
        'category' => 'suv',
        'year' => '2021',
        'km' => '38.200 km',
        'transmission' => 'Automatica',
        'image' => 'https://images.unsplash.com/photo-1502877338535-766e1452684a?auto=format&fit=crop&w=900&q=60',
        'description' => 'Version LX con techo panoramico, mantenimiento al dia y historial certificado.',
        'location' => 'El Poblado, Medellin',
        'gallery' => []
    ],
    [
        'brand' => 'Toyota',
        'model' => '4Runner SR5',
        'price' => '$165.300.000',
        'tagline' => 'Potencia y confiabilidad japonesa',
        'category' => 'suv',
        'year' => '2020',
        'km' => '44.900 km',
        'transmission' => 'Automatica 4x4',
        'image' => 'https://images.unsplash.com/photo-1503736334956-4c8f8e92946d?auto=format&fit=crop&w=900&q=60',
        'description' => 'Blindaje ligero opcional, mantenimiento en concesionario y kit off-road incluido.',
        'location' => 'El Poblado, Medellin',
        'gallery' => []
    ],
    [
        'brand' => 'Mazda',
        'model' => '3 Grand Touring',
        'price' => '$130.000.000',
        'tagline' => 'Edicion Carbon attitude',
        'category' => 'sedan',
        'year' => '2022',
        'km' => '18.700 km',
        'transmission' => 'Secuencial',
        'image' => 'https://images.unsplash.com/photo-1503376780353-7e6692767b70?auto=format&fit=crop&w=900&q=60',
        'description' => 'Interiores en suede, sonido Bose y paquete i-Activsense completo.',
        'location' => 'El Poblado, Medellin',
        'gallery' => []
    ],
    [
        'brand' => 'Ford',
        'model' => 'Fiesta Titanium',
        'price' => '$36.900.000',
        'tagline' => 'Compacto con ADN deportivo',
        'category' => 'sedan',
        'year' => '2019',
        'km' => '52.000 km',
        'transmission' => 'Automatica',
        'image' => 'https://images.unsplash.com/photo-1471478331149-c72f17e33c73?auto=format&fit=crop&w=900&q=60',
        'description' => 'Version Titanium con navegador Sync y sensores 360.',
        'location' => 'El Poblado, Medellin',
        'gallery' => []
    ],
    [
        'brand' => 'Nissan',
        'model' => 'Frontier NP300',
        'price' => '$119.900.000',
        'tagline' => 'Pickup lista para trabajar',
        'category' => 'pickup',
        'year' => '2020',
        'km' => '61.300 km',
        'transmission' => 'Manual 4x4',
        'image' => 'https://images.unsplash.com/photo-1519641471654-76ce0107ad1b?auto=format&fit=crop&w=900&q=60',
        'description' => 'Version LE con cubre platon y accesorios utilitarios instalados.',
        'location' => 'El Poblado, Medellin',
        'gallery' => []
    ],
    [
        'brand' => 'Toyota',
        'model' => 'Prado TXL',
        'price' => '$274.900.000',
        'tagline' => 'Blindaje nivel III listo para rodar',
        'category' => 'suv',
        'year' => '2018',
        'km' => '48.000 km',
        'transmission' => 'Automatica',
        'image' => 'https://images.unsplash.com/photo-1493238792000-8113da705763?auto=format&fit=crop&w=900&q=60',
        'description' => 'Blindaje certificado con garantia vigente y memoria de servicios completa.',
        'location' => 'El Poblado, Medellin',
        'gallery' => []
    ]
];

if ($pdo instanceof PDO && $carColumnsReady) {
    seedCarsIfEmpty($pdo, $seedCars, $hasLocationColumn, $hasGalleryColumn);
}

$editingCar = null;
$editingGallery = [];
if ($portalUser && $pdo instanceof PDO && isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];
    if ($editId > 0) {
        try {
            $stmt = $pdo->prepare('SELECT id, brand, model, price, tagline, category, year, km, transmission, description, image_url, location, gallery FROM cars WHERE id = ? LIMIT 1');
            $stmt->execute([$editId]);
            $found = $stmt->fetch();
            if ($found) {
                $editingCar = $found;
                $decoded = json_decode($found['gallery'] ?? '[]', true);
                $editingGallery = is_array($decoded) ? $decoded : [];
            }
        } catch (Throwable $th) {
            $messages['errors'][] = 'No se pudo cargar el vehiculo a editar (' . $th->getMessage() . ').';
        }
    }
}

$allCars = [];
if ($pdo instanceof PDO && $portalUser) {
    try {
        $fields = ['id', 'brand', 'model', 'price', 'category', 'image_url'];
        $hasLocation = $hasLocationColumn;
        $hasCreated = columnExists($pdo, 'cars', 'created_at');
        if ($hasLocation) { $fields[] = 'location'; }
        if ($hasCreated) { $fields[] = 'created_at'; }
        $select = implode(', ', $fields);
        $orderBy = $hasCreated ? 'created_at' : 'id';
        $carsStmt = $pdo->query("SELECT {$select} FROM cars ORDER BY {$orderBy} DESC");
        $allCars = $carsStmt->fetchAll();
    } catch (Throwable $th) {
        $messages['errors'][] = 'No fue posible cargar los vehiculos (' . $th->getMessage() . ').';
        $allCars = [];
    }
}

if ($portalUser && (isset($_POST['create_product']) || isset($_POST['update_product']))) {
    if (!csrf_verify($_POST['_csrf'] ?? '')) {
        $messages['errors'][] = 'Token de seguridad invalido. Recarga la pagina.';
    } else {
    csrf_regenerate();
    $isUpdate = isset($_POST['update_product']);
    $carId = (int) ($_POST['car_id'] ?? 0);

    if (!$pdo) {
        $messages['errors'][] = 'No hay conexion con la base de datos.';
    } elseif (!$carColumnsReady) {
        $messages['errors'][] = 'Agrega las columnas faltantes en la tabla cars antes de crear o editar vehicles.';
    } elseif ($isUpdate && $carId <= 0) {
        $messages['errors'][] = 'Falta el identificador del vehiculo a editar.';
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
        $location = trim($_POST['location'] ?? '');

        $existingCar = null;
        $existingGallery = [];
        if ($isUpdate) {
            try {
                $stmt = $pdo->prepare('SELECT id, image_url, gallery FROM cars WHERE id = ? LIMIT 1');
                $stmt->execute([$carId]);
                $existingCar = $stmt->fetch();
                $decoded = json_decode($existingCar['gallery'] ?? '[]', true);
                $existingGallery = is_array($decoded) ? $decoded : [];
            } catch (Throwable $th) {
                $messages['errors'][] = 'No se pudo cargar el vehiculo a editar (' . $th->getMessage() . ').';
            }
            if (!$existingCar) {
                $messages['errors'][] = 'No se encontro el vehiculo a editar.';
            }
        }

        $featuredImage = uploadImage($_FILES['featured_image'] ?? []);
        $replaceFeatured = $isUpdate && !empty($featuredImage);
        $replaceGallery = $isUpdate && !empty($_POST['replace_gallery']);
        $removeGallery = array_values(array_filter($_POST['remove_gallery'] ?? [], 'strlen'));

        $imageToSave = $replaceFeatured ? $featuredImage : ($featuredImage ?: ($existingCar['image_url'] ?? null));

        if (!$brand || !$model || !$price || !$category || !$year || !$location) {
            $messages['errors'][] = 'Completa los campos obligatorios (marca, modelo, precio, categoria, ano, ubicacion).';
        }
        if (!$imageToSave) {
            $messages['errors'][] = 'Necesitas cargar la imagen principal desde tu equipo.';
        }

        $galleryPaths = $isUpdate ? ($replaceGallery ? [] : $existingGallery) : [];
        if ($isUpdate && $removeGallery) {
            $galleryPaths = array_values(array_diff($galleryPaths, $removeGallery));
        }
        if (!empty($_FILES['gallery']['name'][0])) {
            $maxGallery = 12;
            $names = $_FILES['gallery']['name'];
            $tmpNames = $_FILES['gallery']['tmp_name'];
            $errorsFiles = $_FILES['gallery']['error'];
            foreach ($names as $index => $name) {
                if (count($galleryPaths) >= $maxGallery) {
                    break;
                }
                if (($errorsFiles[$index] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    continue;
                }
                $file = [
                    'name' => $name,
                    'tmp_name' => $tmpNames[$index],
                    'error' => $errorsFiles[$index]
                ];
                $uploaded = uploadImage($file);
                if ($uploaded) {
                    $galleryPaths[] = $uploaded;
                }
            }
        }

        if (empty($messages['errors'])) {
            try {
                $uniqueGallery = array_values(array_unique($galleryPaths));
                $trimmedGallery = array_slice($uniqueGallery, 0, 12);
                $galleryJson = $trimmedGallery ? json_encode($trimmedGallery) : null;

                if ($isUpdate) {
                    $stmt = $pdo->prepare('UPDATE cars SET brand = ?, model = ?, price = ?, tagline = ?, category = ?, year = ?, km = ?, transmission = ?, description = ?, image_url = ?, location = ?, gallery = ? WHERE id = ?');
                    $stmt->execute([
                        $brand,
                        $model,
                        $price,
                        $tagline,
                        $category,
                        $year,
                        $km,
                        $transmission,
                        $description,
                        $imageToSave,
                        $location,
                        $galleryJson,
                        $carId
                    ]);
                    $_SESSION['portal_flash_success'] = 'Vehiculo actualizado correctamente.';
                } else {
                    $stmt = $pdo->prepare('INSERT INTO cars (brand, model, price, tagline, category, year, km, transmission, description, image_url, location, gallery) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
                    $stmt->execute([
                        $brand,
                        $model,
                        $price,
                        $tagline,
                        $category,
                        $year,
                        $km,
                        $transmission,
                        $description,
                        $imageToSave,
                        $location,
                        $galleryJson
                    ]);
                    $_SESSION['portal_flash_success'] = 'Vehiculo publicado correctamente.';
                }
                header('Location: portal.php');
                exit;
            } catch (Throwable $th) {
                $messages['errors'][] = 'No fue posible guardar el vehiculo (' . $th->getMessage() . ').';
            }
        }
    }
    } // end csrf check
}

if ($portalUser && isset($_POST['delete_product'])) {
    if (!csrf_verify($_POST['_csrf'] ?? '')) {
        $messages['errors'][] = 'Token de seguridad invalido. Recarga la pagina.';
    } else {
    csrf_regenerate();
    $carId = (int) ($_POST['car_id'] ?? 0);
    if (!$pdo) {
        $messages['errors'][] = 'No hay conexion con la base de datos.';
    } elseif ($carId <= 0) {
        $messages['errors'][] = 'Falta el identificador del vehiculo a eliminar.';
    } else {
        try {
            $stmt = $pdo->prepare('DELETE FROM cars WHERE id = ?');
            $stmt->execute([$carId]);
            $_SESSION['portal_flash_success'] = 'Vehiculo eliminado correctamente.';
            header('Location: portal.php');
            exit;
        } catch (Throwable $th) {
            $messages['errors'][] = 'No fue posible eliminar el vehiculo (' . $th->getMessage() . ').';
        }
    }
    } // end csrf check
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portal Top Autos</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600&family=Unbounded:wght@600&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #050505;
            --panel: rgba(32, 32, 32, 0.92);
            --card: rgba(40, 40, 40, 0.95);
            --text: #f4f4f0;
            --muted: #a7a7a7;
            --accent: #f7c948;
            --accent-strong: #ff8c32;
            --stroke: rgba(255,255,255,0.08);
            --shadow: 0 20px 60px rgba(0,0,0,0.45);
        }
        html { scroll-behavior: smooth; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: 'HK Grotesk', 'Space Grotesk', sans-serif;
            background: radial-gradient(circle at top, #3a3a3a 0%, var(--bg) 55%);
            color: var(--text);
            min-height: 100vh;
            padding: 2rem;
            font-weight: 700;
        }
        h1, h2, h3, h4 {
            font-family: 'HK Grotesk', 'Space Grotesk', sans-serif;
            font-weight: 700;
            font-style: italic;
        }
        a { color: inherit; }
        .portal-shell {
            max-width: 1200px;
            margin: 0 auto;
            background: var(--panel);
            border: 1px solid var(--stroke);
            border-radius: 0;
            padding: clamp(1.5rem, 4vw, 3rem);
            box-shadow: var(--shadow);
        }
        /* Fuerza aristas cuadradas en todo el portal */
        .portal-shell,
        .card,
        .toast,
        .car-tile,
        .modal,
        .edit-preview,
        .preview-main figure,
        .preview-gallery img,
        input,
        textarea,
        select,
        button,
        .pill-btn {
            border-radius: 0;
        }
        header.portal-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        header.portal-head h1 {
            margin: 0;
            text-transform: uppercase;
            display: inline-block;
            padding: 0.4rem 0.85rem;
            border: 1px solid var(--stroke);
            border-radius: 0;
            background: inherit;
        }
        .back-link {
            padding: 0.75rem 1.6rem;
            border-radius: 0;
            border: 1px solid var(--stroke);
            text-transform: uppercase;
            font-size: 0.95rem;
            letter-spacing: 0.08em;
            transition: color 0.2s ease, border-color 0.2s ease;
        }

        .back-link:hover {
            color: var(--accent);
            border-color: var(--accent);
        }
        .messages {
            margin-bottom: 1rem;
        }
        .messages .alert {
            padding: 0.85rem 1rem;
            border-radius: 0;
            margin-bottom: 0.6rem;
        }
        .alert.error { background: rgba(255, 76, 76, 0.15); color: #ffb8b8; }
        .alert.success { background: rgba(70, 189, 120, 0.18); color: #9df5c5; }
        .auth-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 1.5rem;
        }
        .card {
            background: var(--card);
            border: 1px solid var(--stroke);
            border-radius: 0;
            padding: 1.8rem;
        }
        form {
            display: flex;
            flex-direction: column;
            gap: 0.9rem;
        }
        input, textarea, select {
            background: var(--panel);
            border: 1px solid var(--stroke);
            border-radius: 0;
            padding: 0.8rem 0.95rem;
            color: var(--text);
            font-family: inherit;
            font-weight: 700;
        }
        select {
            appearance: none;
            background-image:
                linear-gradient(45deg, transparent 50%, var(--accent) 50%),
                linear-gradient(135deg, var(--accent) 50%, transparent 50%),
                linear-gradient(120deg, rgba(247,201,72,0.12), rgba(255,140,50,0.18));
            background-position:
                calc(100% - 1.35rem) calc(50% - 0.25rem),
                calc(100% - 1rem) calc(50% - 0.25rem),
                100% 0;
            background-size: 9px 9px, 9px 9px, auto;
            background-repeat: no-repeat;
            padding-right: 2.6rem;
        }
        select option {
            background: #0f0f0f;
            color: var(--text);
        }
        input[type="file"] {
            padding: 0.55rem 0.75rem;
            color: var(--text);
            cursor: pointer;
        }
        input[type="file"]::file-selector-button {
            margin-right: 0.75rem;
            border: 1px solid var(--stroke);
            background: linear-gradient(120deg, var(--accent), var(--accent-strong));
            color: #050505;
            padding: 0.45rem 0.9rem;
            font-weight: 700;
            text-transform: uppercase;
            cursor: pointer;
        }
        textarea { min-height: 110px; }
        button, .pill-btn {
            border: none;
            cursor: pointer;
            border-radius: 0;
            padding: 1.05rem 2rem;
            font-weight: 700;
            font-size: 0.95rem;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            background: linear-gradient(120deg, var(--accent), var(--accent-strong));
            color: #050505;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        button:hover, .pill-btn:hover {
            transform: translateY(-3px) scale(1.03);
            box-shadow: 0 20px 35px rgba(0,0,0,0.4);
        }
        .dropzone {
            position: relative;
            border: 1px dashed rgba(255,255,255,0.35);
            padding: 1rem;
            text-align: center;
            color: var(--muted);
            background: rgba(255,255,255,0.02);
            cursor: pointer;
            transition: border-color 0.2s ease, background 0.2s ease, color 0.2s ease;
        }
        .dropzone strong {
            color: var(--text);
        }
        .dropzone.is-dragover {
            border-color: var(--accent);
            background: rgba(247,201,72,0.12);
            color: var(--text);
        }
        .dropzone input[type="file"] {
            position: absolute;
            inset: 0;
            opacity: 0;
            cursor: pointer;
        }
        .replace-toggle {
            background: transparent;
            color: var(--text);
            border: 1px solid var(--stroke);
            padding: 0.7rem 1rem;
        }
        .replace-toggle.is-active {
            border-color: var(--accent);
            color: var(--accent);
        }
        .danger-btn {
            background: #b71c1c;
            color: #fff;
            border: 1px solid rgba(255,255,255,0.18);
        }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.2rem;
            align-items: start;
        }
        label span {
            display: block;
            margin-bottom: 0.3rem;
            font-size: 0.85rem;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }
        .logout-form {
            margin-left: auto;
        }
        .feed-section { margin-top: 2.5rem; }
        .cars-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 1.2rem;
            grid-auto-rows: 1fr;
        }
        .car-tile {
            background: var(--panel);
            border: 1px solid var(--stroke);
            border-radius: 0;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            box-shadow: 0 16px 32px rgba(0,0,0,0.4);
        }
        .car-tile figure {
            width: 100%;
            aspect-ratio: 4 / 3;
            background: #0c0c0c;
        }
        .car-tile figure img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .car-tile-body {
            padding: 0.95rem 1rem 1.1rem;
            display: flex;
            flex-direction: column;
            gap: 0.4rem;
        }
        .car-tile h4 {
            font-size: 1.05rem;
            display: flex;
            align-items: baseline;
            gap: 0.35rem;
        }
        .car-tile h4 span { color: var(--muted); font-style: italic; }
        .tile-price { color: var(--accent); letter-spacing: 0.03em; }
        .tile-meta { color: var(--muted); font-size: 0.9rem; }
        .tile-actions { margin-top: 0.4rem; }
        .tile-actions .pill-btn {
            width: 100%;
            text-align: center;
            padding: 0.9rem 1rem;
        }
        .editing-title {
            font-size: 1.6rem;
            margin: 0 0 0.35rem;
            font-style: italic;
        }
        .editing-sub {
            font-size: 1rem;
            color: var(--muted);
            margin: 0 0 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }
        .edit-preview {
            margin-top: 0.75rem;
            background: var(--panel);
            border: 1px solid var(--stroke);
            padding: 0.75rem;
            display: grid;
            grid-template-columns: minmax(180px, 0.9fr) minmax(210px, 1.1fr);
            column-gap: 1.75rem;
            row-gap: 0.65rem;
            align-items: start;
            justify-items: start;
        }
        .preview-main {
            width: 100%;
            max-width: 380px;
        }
        .preview-main figure {
            width: 100%;
            aspect-ratio: 4 / 3;
            max-height: 160px;
            background: #0c0c0c;
            border: 1px solid var(--stroke);
            overflow: hidden;
        }
        .preview-main img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .preview-gallery {
            min-width: 260px;
        }
        .preview-gallery .thumb-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(90px, 1fr));
            gap: 0.3rem;
        }
        .preview-gallery.collage .thumb-grid {
            grid-template-columns: repeat(auto-fit, minmax(110px, 1fr));
        }
        .preview-gallery img {
            width: 100%;
            height: 100%;
            aspect-ratio: 4 / 3;
            object-fit: cover;
            border: 1px solid var(--stroke);
            background: #0c0c0c;
        }
        .preview-gallery { padding-left: 0.4rem; }
        .tagline-small {
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-size: 0.82rem;
            margin-bottom: 0.35rem;
        }
        .schema-alert {
            background: rgba(255, 146, 63, 0.18);
            color: #ffd2a4;
            border-radius: 0;
            padding: 1rem 1.2rem;
            margin-bottom: 1.5rem;
        }
        .toast {
            position: fixed;
            right: 1.4rem;
            bottom: 1.4rem;
            background: var(--panel);
            border: 1px solid var(--stroke);
            padding: 0.85rem 1.1rem;
            border-radius: 0;
            color: var(--text);
            box-shadow: 0 12px 28px rgba(0,0,0,0.35);
            opacity: 0;
            transform: translateY(12px);
            transition: opacity 0.25s ease, transform 0.25s ease;
            z-index: 2000;
            font-weight: 700;
        }
        .toast.show {
            opacity: 1;
            transform: translateY(0);
        }
        .confirm-overlay {
            position: fixed;
            inset: 0;
            background: rgba(5,5,5,0.82);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.2s ease;
            z-index: 2500;
        }
        .confirm-overlay.show {
            opacity: 1;
            pointer-events: auto;
        }
        .confirm-modal {
            width: min(480px, 92vw);
            background: var(--panel);
            border: 1px solid var(--stroke);
            box-shadow: var(--shadow);
            padding: 1.6rem;
            display: grid;
            gap: 0.9rem;
        }
        .confirm-modal h3 {
            margin: 0;
            text-transform: uppercase;
        }
        .confirm-actions {
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
            flex-wrap: wrap;
        }
        .confirm-actions .ghost-btn {
            background: transparent;
            color: var(--text);
            border: 1px solid var(--stroke);
        }
        @media (max-width: 1100px) {
            .cars-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        }
        @media (max-width: 900px) {
            .cars-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 640px) {
            body { padding: 1rem; }
            .cars-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 520px) {
            .cars-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="portal-shell">
        <header class="portal-head">
            <h1>Portal</h1>
            <a class="back-link" href="index.php">← Volver al sitio</a>
        </header>

        <div class="messages">
            <?php foreach ($messages['errors'] as $error): ?>
                <div class="alert error"><?php echo htmlspecialchars($error); ?></div>
            <?php endforeach; ?>
            <?php foreach ($messages['success'] as $msg): ?>
                <div class="alert success"><?php echo htmlspecialchars($msg); ?></div>
            <?php endforeach; ?>
        </div>

        <?php if (!empty($_SESSION['portal_logout_success'])): ?>
            <div class="toast" id="logoutToast">Sesion cerrada correctamente.</div>
            <script>
                window.addEventListener('DOMContentLoaded', function() {
                    const toast = document.getElementById('logoutToast');
                    if (!toast) return;
                    requestAnimationFrame(() => {
                        toast.classList.add('show');
                        setTimeout(() => {
                            toast.classList.remove('show');
                        }, 3800);
                    });
                });
            </script>
            <?php unset($_SESSION['portal_logout_success']); ?>
        <?php endif; ?>

        <?php if (!$portalUser): ?>
            <div class="auth-grid" style="max-width:420px;margin:0 auto;">
                <section class="card">
                    <h2>Acceso exclusivo</h2>
                    <p style="color:var(--muted);font-size:0.92rem;margin-bottom:0.5rem;">Ingresa tus credenciales para gestionar el inventario.</p>
                    <?php if (is_login_locked()): ?>
                        <?php $remaining = max(1, (int) ceil((($_SESSION['login_lock_until'] ?? 0) - time()) / 60)); ?>
                        <div class="alert error">Cuenta bloqueada por seguridad. Intenta en <?php echo $remaining; ?> minuto(s).</div>
                    <?php endif; ?>
                    <form method="post" autocomplete="off">
                        <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                        <input type="text" name="login_user" placeholder="Usuario" required autocomplete="username" <?php echo is_login_locked() ? 'disabled' : ''; ?>>
                        <input type="password" name="login_password" placeholder="Clave" required autocomplete="current-password" <?php echo is_login_locked() ? 'disabled' : ''; ?>>
                        <button type="submit" name="login" <?php echo is_login_locked() ? 'disabled style="opacity:0.5;cursor:not-allowed;"' : ''; ?>>Entrar</button>
                    </form>
                </section>
            </div>
        <?php else: ?>
            <div class="dashboard-head" style="display:flex;align-items:center;gap:1rem;margin-bottom:1.5rem;">
                <p>Hola, <?php echo htmlspecialchars($portalUser['name']); ?>. Gestiona tu inventario con el look Top Autos.</p>
                <form method="post" class="logout-form">
                    <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                    <button type="submit" name="logout">Cerrar sesion</button>
                </form>
            </div>

            <?php if (!$pdo): ?>
                <div class="schema-alert">Conecta la base de datos en <code>config.php</code> para comenzar.</div>
            <?php elseif (!$carColumnsReady): ?>
                <div class="schema-alert">
                    Faltan columnas en <code>cars</code>: <?php echo implode(', ', $missingCarColumns); ?>. Ejecuta el ALTER TABLE indicado en las instrucciones para poder guardar ubicacion y galeria.
                </div>
            <?php endif; ?>

            <section class="card" style="margin-bottom:1.5rem;">
                <?php if ($editingCar): ?>
                    <h2 class="editing-title">Editando: <?php echo htmlspecialchars($editingCar['brand'] . ' ' . $editingCar['model']); ?></h2>
                    <div style="display:flex;align-items:center;gap:0.75rem;flex-wrap:wrap;position:relative;">
                        <p class="editing-sub" style="margin:0;">Editar vehiculo</p>
                        <a href="portal.php" class="pill-btn" style="position:absolute;top:-48px;right:0;background:#b71c1c;border:1px solid rgba(255,255,255,0.18);color:#fff;padding:0.25rem 0.7rem;font-size:0.78rem;box-shadow:0 0 0 1px rgba(0,0,0,0.18), 0 10px 24px rgba(0,0,0,0.35);">Cancelar edicion</a>
                    </div>
                <?php else: ?>
                    <h2>Crear vehiculo</h2>
                <?php endif; ?>
                <form method="post" enctype="multipart/form-data" id="editCarForm">
                    <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                    <?php if ($editingCar): ?>
                        <input type="hidden" name="car_id" value="<?php echo (int) $editingCar['id']; ?>">
                    <?php endif; ?>
                    <div class="form-grid">
                        <label><span>Marca</span><input type="text" name="brand" value="<?php echo htmlspecialchars($editingCar['brand'] ?? ''); ?>" required></label>
                        <label><span>Modelo</span><input type="text" name="model" value="<?php echo htmlspecialchars($editingCar['model'] ?? ''); ?>" required></label>
                        <label><span>Precio</span><input type="text" name="price" value="<?php echo htmlspecialchars($editingCar['price'] ?? ''); ?>" placeholder="$81.900.000" required></label>
                        <label><span>Tagline</span><input type="text" name="tagline" value="<?php echo htmlspecialchars($editingCar['tagline'] ?? ''); ?>" placeholder="Frase de impacto"></label>
                        <label><span>Categoria</span>
                            <select name="category" required>
                                <option value="">Selecciona</option>
                                <option value="suv" <?php echo (isset($editingCar['category']) && $editingCar['category']==='suv') ? 'selected' : ''; ?>>SUV</option>
                                <option value="sedan" <?php echo (isset($editingCar['category']) && $editingCar['category']==='sedan') ? 'selected' : ''; ?>>Sedan</option>
                                <option value="pickup" <?php echo (isset($editingCar['category']) && $editingCar['category']==='pickup') ? 'selected' : ''; ?>>Pickup</option>
                                <option value="deportivo" <?php echo (isset($editingCar['category']) && $editingCar['category']==='deportivo') ? 'selected' : ''; ?>>Deportivo</option>
                            </select>
                        </label>
                        <label><span>Año</span><input type="text" name="year" value="<?php echo htmlspecialchars($editingCar['year'] ?? ''); ?>" placeholder="2022" required></label>
                        <label><span>Kilometraje</span><input type="text" name="km" value="<?php echo htmlspecialchars($editingCar['km'] ?? ''); ?>" placeholder="38.200 km"></label>
                        <label><span>Transmision</span><input type="text" name="transmission" value="<?php echo htmlspecialchars($editingCar['transmission'] ?? ''); ?>" placeholder="Automatica"></label>
                        <label><span>Ubicacion</span><input type="text" name="location" value="<?php echo htmlspecialchars($editingCar['location'] ?? ''); ?>" placeholder="El Poblado, Medellin" required></label>
                    </div>
                    <label><span>Descripcion</span><textarea name="description" placeholder="Incluye highlights, garantias, estados..."><?php echo htmlspecialchars($editingCar['description'] ?? ''); ?></textarea></label>
                    <div class="form-grid">
                        <div>
                            <span>Imagen destacada</span>
                            <div class="dropzone" data-dropzone data-target="featured_image">
                                <strong>Arrastra y suelta</strong> o haz clic para subir la imagen destacada.
                                <input type="file" id="featured_image" name="featured_image" accept="image/*" <?php echo $editingCar ? '' : 'required'; ?>>
                            </div>
                        </div>
                        <div>
                            <span>Galeria (sube hasta 12 fotos)</span>
                            <div class="dropzone" data-dropzone data-target="gallery_input">
                                <strong>Arrastra y suelta</strong> o haz clic para agregar fotos a la galeria.
                                <input type="file" id="gallery_input" name="gallery[]" accept="image/*" multiple>
                            </div>
                        </div>
                    </div>
                    <?php if ($editingCar): ?>
                        <input type="hidden" name="replace_gallery" id="replaceGalleryInput" value="0">
                        <div style="display:flex;gap:0.8rem;flex-wrap:wrap;align-items:center;">
                            <button type="button" class="replace-toggle" data-replace-toggle>Reemplazar galeria completa</button>
                            <span class="tile-meta">Activa si quieres reemplazar todas las fotos actuales.</span>
                        </div>
                    <?php endif; ?>
                    <button type="submit" name="<?php echo $editingCar ? 'update_product' : 'create_product'; ?>"><?php echo $editingCar ? 'Actualizar vehiculo' : 'Publicar vehiculo'; ?></button>
                </form>

                <?php if ($editingCar): ?>
                    <form method="post" class="delete-product-form" style="margin-top:1rem;">
                        <input type="hidden" name="_csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                        <input type="hidden" name="car_id" value="<?php echo (int) $editingCar['id']; ?>">
                        <button type="submit" name="delete_product" class="danger-btn">Eliminar vehiculo</button>
                    </form>
                <?php endif; ?>

                <?php if ($editingCar): ?>
                    <?php 
                        $mainImage = !empty($editingCar['image_url']) ? $editingCar['image_url'] : 'https://images.unsplash.com/photo-1503736334956-4c8f8e92946d?auto=format&fit=crop&w=900&q=60';
                        $galleryClass = (!empty($editingGallery) && is_array($editingGallery) && count($editingGallery) > 1) ? 'collage' : '';
                    ?>
                    <div class="edit-preview">
                        <div class="preview-main">
                            <div class="tagline-small">Imagen destacada actual</div>
                            <figure>
                                <img src="<?php echo htmlspecialchars($mainImage); ?>" alt="<?php echo htmlspecialchars($editingCar['brand'] . ' ' . $editingCar['model']); ?>">
                            </figure>
                        </div>
                        <div class="preview-gallery <?php echo $galleryClass; ?>">
                            <div class="tagline-small">Galeria subida</div>
                            <?php if (!empty($editingGallery)): ?>
                                <div class="thumb-grid">
                                    <?php foreach ($editingGallery as $url): ?>
                                        <label style="position:relative;display:block;">
                                            <img src="<?php echo htmlspecialchars($url); ?>" alt="Galeria de <?php echo htmlspecialchars($editingCar['brand'] . ' ' . $editingCar['model']); ?>">
                                            <input type="checkbox" name="remove_gallery[]" value="<?php echo htmlspecialchars($url); ?>" form="editCarForm" style="position:absolute;top:6px;right:6px;">
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p class="tile-meta">Aun no hay galeria cargada.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </section>

            <section class="card feed-section">
                <h2>Ultimos cargues</h2>
                <?php if (!$allCars): ?>
                    <p>Aun no hay vehiculos registrados.</p>
                <?php else: ?>
                    <div class="cars-grid">
                        <?php foreach (array_slice($allCars, 0, 12) as $car): ?>
                            <article class="car-tile">
                                <figure>
                                    <img src="<?php echo htmlspecialchars($car['image_url']); ?>" alt="<?php echo htmlspecialchars($car['brand'] . ' ' . $car['model']); ?>">
                                </figure>
                                <div class="car-tile-body">
                                    <h4><?php echo htmlspecialchars($car['brand']); ?> <span><?php echo htmlspecialchars($car['model']); ?></span></h4>
                                    <p class="tile-price"><?php echo htmlspecialchars($car['price']); ?></p>
                                    <p class="tile-meta"><?php echo htmlspecialchars($car['location'] ?? 'Ubicacion N/D'); ?> · <?php echo htmlspecialchars(strtoupper($car['category'] ?? '')); ?></p>
                                    <?php if (isset($car['created_at'])): ?>
                                        <p class="tile-meta" style="font-size:0.82rem;">Cargado: <?php echo htmlspecialchars($car['created_at']); ?></p>
                                    <?php endif; ?>
                                    <div class="tile-actions">
                                        <a class="pill-btn" href="portal.php?edit=<?php echo (int) $car['id']; ?>">Editar</a>
                                    </div>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </div>

    <div class="confirm-overlay" id="deleteConfirm" aria-hidden="true">
        <div class="confirm-modal" role="dialog" aria-modal="true" aria-labelledby="deleteTitle" aria-describedby="deleteDesc">
            <h3 id="deleteTitle">Eliminar vehiculo</h3>
            <p id="deleteDesc">¿Seguro que quieres eliminar este vehiculo? Esta accion no se puede deshacer.</p>
            <div class="confirm-actions">
                <button type="button" class="ghost-btn" data-confirm="cancel">Cancelar</button>
                <button type="button" class="danger-btn" data-confirm="ok">Si, eliminar</button>
            </div>
        </div>
    </div>

    <script>
        document.querySelectorAll('[data-dropzone]').forEach(zone => {
            const inputId = zone.getAttribute('data-target');
            const input = document.getElementById(inputId);
            if (!input) return;

            zone.addEventListener('click', (e) => {
                if (e.target === input) return;
                input.click();
            });
            zone.addEventListener('dragover', (event) => {
                event.preventDefault();
                zone.classList.add('is-dragover');
            });
            zone.addEventListener('dragleave', () => zone.classList.remove('is-dragover'));
            zone.addEventListener('drop', (event) => {
                event.preventDefault();
                zone.classList.remove('is-dragover');
                if (event.dataTransfer?.files?.length) {
                    input.files = event.dataTransfer.files;
                    const name = event.dataTransfer.files.length > 1
                        ? `${event.dataTransfer.files.length} archivos seleccionados`
                        : event.dataTransfer.files[0].name;
                    zone.setAttribute('data-selected', name);
                }
            });
            input.addEventListener('change', () => {
                if (input.files?.length) {
                    const name = input.files.length > 1
                        ? `${input.files.length} archivos seleccionados`
                        : input.files[0].name;
                    zone.setAttribute('data-selected', name);
                }
            });
        });

        const replaceToggle = document.querySelector('[data-replace-toggle]');
        const replaceInput = document.getElementById('replaceGalleryInput');
        replaceToggle?.addEventListener('click', () => {
            const isActive = replaceToggle.classList.toggle('is-active');
            if (replaceInput) {
                replaceInput.value = isActive ? '1' : '0';
            }
        });

        const deleteOverlay = document.getElementById('deleteConfirm');
        let pendingDeleteForm = null;

        document.querySelectorAll('.delete-product-form').forEach(form => {
            form.addEventListener('submit', (event) => {
                event.preventDefault();
                pendingDeleteForm = form;
                deleteOverlay?.classList.add('show');
                deleteOverlay?.setAttribute('aria-hidden', 'false');
            });
        });

        deleteOverlay?.addEventListener('click', (event) => {
            if (event.target === deleteOverlay) {
                deleteOverlay.classList.remove('show');
                deleteOverlay.setAttribute('aria-hidden', 'true');
                pendingDeleteForm = null;
            }
        });

        document.querySelectorAll('[data-confirm="cancel"]').forEach(btn => {
            btn.addEventListener('click', () => {
                deleteOverlay?.classList.remove('show');
                deleteOverlay?.setAttribute('aria-hidden', 'true');
                pendingDeleteForm = null;
            });
        });

        document.querySelectorAll('[data-confirm="ok"]').forEach(btn => {
            btn.addEventListener('click', () => {
                if (pendingDeleteForm) {
                    pendingDeleteForm.submit();
                }
            });
        });
    </script>
</body>
</html>
