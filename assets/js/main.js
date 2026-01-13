// assets/js/main.js
document.addEventListener('DOMContentLoaded', function () {
    // Handle Header Buttons
    // Handle Header Buttons
    // Legacy event listeners removed. Header buttons now have specific HREFs or independent JS handlers.


    // Handle Search
    const searchInput = document.querySelector('.search-bar input');
    // Event listener removed to allow form submission


    // Add hover effects or other interactions here
    // Handle Sidebar Toggle (Mobile)
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebar = document.querySelector('.sidebar');
    const body = document.body;
    
    // Create overlay if not exists
    let overlay = document.querySelector('.sidebar-overlay');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.className = 'sidebar-overlay';
        body.appendChild(overlay);
    }

    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function(e) {
            e.stopPropagation();
            sidebar.classList.toggle('active');
            overlay.classList.toggle('active');
        });
    }

    // Close sidebar when clicking overlay
    overlay.addEventListener('click', function() {
        sidebar.classList.remove('active');
        overlay.classList.remove('active');
    });

    // Close on escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
             sidebar.classList.remove('active');
             overlay.classList.remove('active');
        }
    });
});
