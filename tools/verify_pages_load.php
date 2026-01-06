<?php
// tools/verify_pages_load.php

// This script scans the project for PHP files and attempts to lint them (php -l) 
// to ensure no syntax errors exist.

$root = realpath(__DIR__ . '/../');
$directories = [
    'includes',
    'dashboards',
    'modules',
    'tools'
];

$files_count = 0;
$errors = [];

echo "🔍 Starting PHP Syntax Verification...\n";
echo "Root: $root\n";

foreach ($directories as $dir) {
    $path = $root . '/' . $dir;
    if (!is_dir($path)) continue;

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
    
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files_count++;
            $cmd = "php -l " . escapeshellarg($file->getPathname());
            $output = [];
            $return_var = 0;
            exec($cmd, $output, $return_var);
            
            if ($return_var !== 0) {
                $errors[] = [
                    'file' => $file->getPathname(),
                    'error' => implode("\n", $output)
                ];
                echo "❌ Error in: " . $file->getFilename() . "\n";
            } else {
                // echo "✅ OK: " . $file->getFilename() . "\n";
            }
        }
    }
}

echo "\n----------------------------------------\n";
echo "Scanned $files_count PHP files.\n";

if (count($errors) > 0) {
    echo "❌ Found " . count($errors) . " files with syntax errors:\n";
    foreach ($errors as $err) {
        echo "\n[File]: " . $err['file'] . "\n";
        echo "[Error]: " . $err['error'] . "\n";
    }
    exit(1);
} else {
    echo "✅ No syntax errors found. System integrity healthy.\n";
    exit(0);
}
?>
