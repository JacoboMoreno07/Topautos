<?php
// Helper script to generate static HTML for GitHub Pages
// Generates: index.html, portal.html, dashboard.html

$docsDir = __DIR__ . '/docs';
if (!is_dir($docsDir)) {
    mkdir($docsDir, 0775, true);
}

// ─── 1. INDEX (public page) ───
ob_start();
include __DIR__ . '/index.php';
$html = ob_get_clean();
// Point portal link to portal.html
$html = str_replace('href="portal.php"', 'href="portal.html"', $html);
$html = str_replace('action="index.php"', 'action="#"', $html);
file_put_contents($docsDir . '/index.html', $html);
echo "index.html OK (" . strlen($html) . " bytes)\n";

// ─── 2. PORTAL (logged-in view) ───
// Fake session so portal renders the dashboard view
$_SESSION['portal_user'] = ['name' => 'TopAutos2026'];
$_SESSION['login_attempts'] = 0;
// Fake CSRF
if (!function_exists('csrf_token_static')) {
    // csrf_token() is already loaded from config.php
}
ob_start();
include __DIR__ . '/portal.php';
$portalHtml = ob_get_clean();
// Fix links
$portalHtml = str_replace('href="index.php"', 'href="index.html"', $portalHtml);
$portalHtml = str_replace('href="portal.php"', 'href="portal.html"', $portalHtml);
$portalHtml = str_replace('action="portal.php"', 'action="#"', $portalHtml);
// Disable forms (they won't work on static)
$portalHtml = str_replace('method="post"', 'method="post" onsubmit="alert(\'Esta es una vista previa. Las funciones del portal requieren el servidor PHP.\');return false;"', $portalHtml);
file_put_contents($docsDir . '/portal.html', $portalHtml);
echo "portal.html OK (" . strlen($portalHtml) . " bytes)\n";

// ─── 3. DASHBOARD (logged-in view) ───
$_SESSION['ta_logged'] = true;
ob_start();
include __DIR__ . '/dashboard.php';
$dashHtml = ob_get_clean();
$dashHtml = str_replace('action="dashboard.php"', 'action="#"', $dashHtml);
$dashHtml = str_replace('method="post"', 'method="post" onsubmit="alert(\'Esta es una vista previa. Las funciones del dashboard requieren el servidor PHP.\');return false;"', $dashHtml);
file_put_contents($docsDir . '/dashboard.html', $dashHtml);
echo "dashboard.html OK (" . strlen($dashHtml) . " bytes)\n";

echo "\nAll static files generated successfully!\n";
