<?php
// includes/config.php

// Determine the project's root directory relative to the server's document root
// This ensures links work whether the app is at the root (localhost:8000) or in a subfolder (localhost/project)

$project_dir = dirname(__DIR__); // Absolute path to project root
$doc_root = $_SERVER['DOCUMENT_ROOT'];

// Normalize paths (handle Windows backslashes)
$project_dir = str_replace('\\', '/', $project_dir);
$doc_root = str_replace('\\', '/', $doc_root);

// Calculate the relative path (BASE_URL)
if (strpos($project_dir, $doc_root) === 0) {
    $base_url = substr($project_dir, strlen($doc_root));
    $base_url = rtrim($base_url, '/'); // Remove trailing slash if any
} else {
    // Fallback if document root doesn't match (e.g. some symbolic link setups), though unlikely in XAMPP/standard
    $base_url = ''; 
}

// Define the constant
if (!defined('BASE_URL')) {
    define('BASE_URL', $base_url);
}
?>
