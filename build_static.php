<?php
// Helper script to generate static HTML for GitHub Pages
// Generates: index.html, portal.html, dashboard.html

// Suppress all warnings/notices so they don't leak into HTML output
error_reporting(0);
ini_set('display_errors', '0');

$docsDir = __DIR__ . '/docs';
if (!is_dir($docsDir)) {
    mkdir($docsDir, 0775, true);
}

// ─── 1. INDEX (public page) ───
ob_start();
include __DIR__ . '/index.php';
$html = ob_get_clean();
// Strip any PHP warnings that leaked before <!DOCTYPE
$html = preg_replace('/^.*?(<!DOCTYPE)/si', '$1', $html);
// Point portal link to portal.html
$html = str_replace('href="portal.php"', 'href="portal.html"', $html);
$html = str_replace('action="index.php"', 'action="#"', $html);
file_put_contents($docsDir . '/index.html', $html);
echo "index.html OK (" . strlen($html) . " bytes)\n";

// ─── 2. PORTAL (logged-in view) ───
$_SESSION['portal_user'] = ['name' => 'TopAutos2026'];
$_SESSION['login_attempts'] = 0;
ob_start();
include __DIR__ . '/portal.php';
$portalHtml = ob_get_clean();
// Strip any PHP warnings that leaked before <!DOCTYPE
$portalHtml = preg_replace('/^.*?(<!DOCTYPE)/si', '$1', $portalHtml);
// Fix links
$portalHtml = str_replace('href="index.php"', 'href="index.html"', $portalHtml);
$portalHtml = str_replace('href="portal.php"', 'href="portal.html"', $portalHtml);
$portalHtml = str_replace('action="portal.php"', 'action="#"', $portalHtml);
// Disable forms (static preview)
$portalHtml = str_replace('method="post"', 'method="post" onsubmit="alert(\'Vista previa estatica. Para usar el portal necesitas el servidor PHP local.\');return false;"', $portalHtml);
// Remove "Conecta la base de datos" alert
$portalHtml = preg_replace('/<div class="schema-alert">.*?<\/div>/s', '', $portalHtml);
file_put_contents($docsDir . '/portal.html', $portalHtml);
echo "portal.html OK (" . strlen($portalHtml) . " bytes)\n";

// ─── 3. DASHBOARD (logged-in view) ───
$_SESSION['ta_logged'] = true;
ob_start();
include __DIR__ . '/dashboard.php';
$dashHtml = ob_get_clean();
// Strip any PHP warnings
$dashHtml = preg_replace('/^.*?(<!DOCTYPE)/si', '$1', $dashHtml);
$dashHtml = str_replace('action="dashboard.php"', 'action="#"', $dashHtml);
$dashHtml = str_replace('method="post"', 'method="post" onsubmit="alert(\'Vista previa estatica. Para usar el dashboard necesitas el servidor PHP local.\');return false;"', $dashHtml);
file_put_contents($docsDir . '/dashboard.html', $dashHtml);
echo "dashboard.html OK (" . strlen($dashHtml) . " bytes)\n";

echo "\nAll static files generated successfully!\n";
