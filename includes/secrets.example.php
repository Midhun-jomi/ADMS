<?php
// includes/secrets.example.php
// Copy this file to includes/secrets.php and fill in real values.
// DO NOT put real credentials in this file.

// --- Database (Supabase PostgreSQL) ---
define('DB_HOST',     'your-supabase-host.supabase.com');
define('DB_PORT',     '5432');
define('DB_NAME',     'postgres');
define('DB_USER',     'postgres.your_project_ref');
define('DB_PASSWORD', 'your_database_password');

// --- SMTP (Gmail App Password) ---
define('SMTP_HOST',     'smtp.gmail.com');
define('SMTP_PORT',     587);
define('SMTP_USERNAME', 'your_email@gmail.com');
define('SMTP_PASSWORD', 'your_gmail_app_password');
