<?php
// Helper script to generate static HTML for GitHub Pages
// Generates: index.html (public page only, no portal access)

// Suppress all warnings/notices so they don't leak into HTML output
error_reporting(0);
ini_set('display_errors', '0');

$docsDir = __DIR__ . '/docs';
if (!is_dir($docsDir)) {
    mkdir($docsDir, 0775, true);
}

// ─── INDEX (public page) ───
ob_start();
include __DIR__ . '/index.php';
$html = ob_get_clean();
// Strip any PHP warnings that leaked before <!DOCTYPE
$html = preg_replace('/^.*?(<!DOCTYPE)/si', '$1', $html);
// Remove the portal link/button from the nav
$html = preg_replace('/<div class="portal-group[^"]*">.*?<\/div>/s', '', $html);
$html = str_replace('action="index.php"', 'action="#"', $html);
file_put_contents($docsDir . '/index.html', $html);
echo "index.html OK (" . strlen($html) . " bytes)\n";

// Remove old portal/dashboard static files if they exist
foreach (['portal.html', 'dashboard.html'] as $old) {
    $path = $docsDir . '/' . $old;
    if (file_exists($path)) {
        unlink($path);
        echo "Deleted $old\n";
    }
}

echo "\nStatic site generated successfully!\n";
