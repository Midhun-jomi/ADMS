<?php
// includes/header.php
require_once 'auth_session.php';
check_auth();
$user_email = $_SESSION['email'] ?? 'User';
$user_role = $_SESSION['role'] ?? 'Guest';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ADMS Hospital - Hospital Management</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="/assets/js/main.js" defer></script>
</head>
<body>
    <div class="dashboard-container">
        <?php include 'sidebar.php'; ?>
        
        <div class="content-wrapper">
            <header class="top-header">
                <div class="search-bar">
                    <form action="/search_results.php" method="GET" style="display: flex; align-items: center; width: 100%;">
                        <button type="submit" style="background: none; border: none; cursor: pointer; padding: 0; margin-right: 10px;" aria-label="Search">
                            <i class="fas fa-search" style="color: #888;"></i>
                        </button>
                        <input type="text" name="q" placeholder="Search here..." required style="border: none; outline: none; flex: 1; font-size: 0.95em;">
                    </form>
                </div>
                
                <div class="header-actions">
                    <?php if ($user_role === 'patient'): ?>
                        <a href="/modules/ehr/book_appointment.php" class="btn btn-primary" style="padding: 8px 15px; font-size: 0.9em; text-decoration: none;">
                            <i class="far fa-calendar-plus"></i> Book Appointment
                        </a>
                    <?php else: ?>
                        <button class="btn btn-primary" style="padding: 8px 15px; font-size: 0.9em;">
                            <i class="fas fa-plus"></i> Add New
                        </button>
                    <?php endif; ?>
                    
                    <!-- Notification Dropdown -->
                    <?php
                    // Fetch notifications
                    $notifs = [];
                    $unread_count = 0;
                    if (isset($_SESSION['user_id'])) { // Ensure user is logged in
                        $uid = $_SESSION['user_id'];
                        $notifs = db_select("SELECT * FROM notifications WHERE user_id = $1 ORDER BY created_at DESC LIMIT 5", [$uid]);
                        $unread = db_select_one("SELECT COUNT(*) as c FROM notifications WHERE user_id = $1 AND is_read = FALSE", [$uid]);
                        $unread_count = $unread['c'] ?? 0;
                    }
                    ?>
                    <div class="dropdown" style="position: relative; display: inline-block;">
                        <button id="notif-btn" class="icon-btn" style="position: relative;">
                            <i class="far fa-bell"></i>
                            <?php if ($unread_count > 0): ?>
                                <span style="position: absolute; top: 0; right: 0; width: 10px; height: 10px; background: #ef4444; border-radius: 50%; border: 2px solid white;"></span>
                            <?php endif; ?>
                        </button>
                        <div id="notif-dropdown" class="dropdown-content" style="display: none; position: absolute; right: 0; background: white; min-width: 320px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); z-index: 1000; border-radius: 12px; overflow: hidden; border: 1px solid #f3f4f6;">
                            <div style="padding: 15px; background: #fff; border-bottom: 1px solid #f3f4f6; font-weight: 600; color: #1f2937; display: flex; justify-content: space-between; align-items: center;">
                                <span>Notifications</span>
                                <?php if ($unread_count > 0): ?>
                                    <span style="background: #fee2e2; color: #b91c1c; padding: 2px 8px; border-radius: 99px; font-size: 0.75em;"><?php echo $unread_count; ?> New</span>
                                <?php endif; ?>
                            </div>
                            <div style="max-height: 350px; overflow-y: auto;">
                                <?php if (empty($notifs)): ?>
                                    <div style="padding: 30px; text-align: center; color: #9ca3af;">
                                        <i class="far fa-bell-slash" style="font-size: 2em; margin-bottom: 10px; opacity: 0.5;"></i>
                                        <p style="margin: 0; font-size: 0.9em;">No notifications yet.</p>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($notifs as $n): ?>
                                        <div style="padding: 15px; border-bottom: 1px solid #f3f4f6; transition: background 0.2s; <?php echo !$n['is_read'] ? 'background: #fdfafa;' : ''; ?>">
                                            <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                                                <strong style="font-size: 0.9em; color: #111827;"><?php echo htmlspecialchars($n['title']); ?></strong>
                                                <small style="color: #9ca3af; font-size: 0.75em;"><?php echo date('M d, h:i A', strtotime($n['created_at'])); ?></small>
                                            </div>
                                            <div style="font-size: 0.85em; color: #4b5563; line-height: 1.4;">
                                                <?php echo htmlspecialchars($n['message']); ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <a href="/settings.php" class="icon-btn" style="text-decoration: none; color: inherit; display: flex; align-items: center; justify-content: center;">
                        <i class="fas fa-cog"></i>
                    </a>
                    
                    <div class="user-profile">
                        <?php
                        // Check for profile image
                        $header_profile_img = "https://ui-avatars.com/api/?name=" . urlencode($user_email) . "&background=random";
                        if (isset($_SESSION['user_id'])) {
                            $u_img = db_select_one("SELECT profile_image FROM users WHERE id = $1", [$_SESSION['user_id']]);
                            if ($u_img && !empty($u_img['profile_image'])) {
                                $header_profile_img = $u_img['profile_image'] . "?t=" . time();
                            }
                        }
                        ?>
                        <img src="<?php echo $header_profile_img; ?>" alt="User" class="user-avatar" style="object-fit: cover;">
                        <div class="user-info">
                            <span class="user-name"><?php echo htmlspecialchars(explode('@', $user_email)[0]); ?></span>
                            <span class="user-role"><?php echo ucfirst($user_role); ?></span>
                        </div>
                        <a href="/auth/logout.php" style="color: #dc3545; margin-left: 10px;"><i class="fas fa-sign-out-alt"></i></a>
                    </div>
                </div>

                <script>
                    document.addEventListener('click', function(event) {
                        var notifBtn = document.getElementById('notif-btn');
                        var notifDropdown = document.getElementById('notif-dropdown');
                        
                        // Check if click was on or inside the notification button
                        if (notifBtn.contains(event.target)) {
                            // Toggle
                            if (notifDropdown.style.display === 'block') {
                                notifDropdown.style.display = 'none';
                            } else {
                                notifDropdown.style.display = 'block';
                            }
                        } else {
                            // Clicked outside, close if open
                            // But NOT if clicking inside the dropdown itself (so we can interact with it)
                            if (notifDropdown.style.display === 'block' && !notifDropdown.contains(event.target)) {
                                notifDropdown.style.display = 'none';
                            }
                        }
                    });
                </script>
            </header>

            <main class="main-content">
                <!-- Global Stats Widgets (Requested on every page) -->
                <?php 
                if ($user_role !== 'patient') {
                    include __DIR__ . '/stats_widgets.php'; 
                }
                ?>

                <?php if (isset($page_title)): ?>
                    <h2 style="margin-bottom: 25px;"><?php echo $page_title; ?></h2>
                <?php endif; ?>
