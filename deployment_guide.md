# Deployment Guide

## 1. Server Requirements
-   **Web Server**: Apache or Nginx.
-   **PHP**: Version 8.0 or higher.
-   **Extensions**: `pgsql` (PostgreSQL), `openssl`, `mbstring`.
-   **Database**: Supabase PostgreSQL (External).

## 2. Environment Configuration
1.  Upload all files to your web server's public directory (e.g., `public_html`).
2.  Ensure `.env` file is present and contains your Supabase credentials:
    ```ini
    DB_HOST=aws-1-ap-southeast-2.pooler.supabase.com
    DB_PORT=6543
    DB_NAME=postgres
    DB_USER=postgres.rmxquvqwzskridfqbdws
    DB_PASS=YourStrongPassword
    ```
3.  **Security Note**: Deny web access to `.env` and `includes/` if possible using `.htaccess` or Nginx config.

## 3. Database Migration
-   The database schema is already set up on Supabase.
-   If deploying to a fresh DB, run the SQL commands from `database/schema.sql` in the Supabase SQL Editor.

## 4. Production Settings
-   In `includes/db.php`, ensure `display_errors` is turned off for production.
-   Set up SSL (HTTPS) on your web server.

## 5. Troubleshooting
-   **Connection Failed**: Check if your server's IP is allowed in Supabase (if not using 0.0.0.0/0).
-   **White Screen**: Check PHP error logs.
