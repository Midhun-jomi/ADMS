<?php
require_once 'includes/db.php';

$sql = "
CREATE TABLE IF NOT EXISTS notifications (
    id SERIAL PRIMARY KEY,
    user_id INT NOT NULL,
    message TEXT NOT NULL,
    type VARCHAR(50) DEFAULT 'info',
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
";

try {
    db_query($sql);
    echo "Notifications table created successfully.";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
