<?php
// Helper script to generate static HTML from index.php
ob_start();
include __DIR__ . '/index.php';
$html = ob_get_clean();

// Fix the portal link for static version
$html = str_replace('href="portal.php"', 'href="#" onclick="return false;"', $html);

// Write with UTF-8 BOM to ensure correct encoding
file_put_contents(__DIR__ . '/docs/index.html', $html);

echo "Static HTML generated successfully (" . strlen($html) . " bytes)\n";
