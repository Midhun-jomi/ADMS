<?php
// help_center.php
require_once 'includes/db.php';
require_once 'includes/auth_session.php';
check_auth();

$page_title = "Help Center";
include 'includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <i class="far fa-question-circle"></i> Frequently Asked Questions
    </div>
    
    <div id="faq-container" style="padding: 20px;">
        <div class="text-center">
            <i class="fas fa-spinner fa-spin fa-2x"></i>
            <p>Loading helpful answers...</p>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Simulated API Call
    fetchFAQs();
});

function fetchFAQs() {
    const container = document.getElementById('faq-container');
    
    // Using a placeholder API or simulating one since real external APIs might be blocked or unreliable in this env
    // We'll simulate a fetch to a local JSON handler or just mimic the async behavior
    
    // Simulate API delay
    setTimeout(() => {
        const faqs = [
            {
                id: 1,
                question: "How do I book an appointment?",
                answer: "You can book an appointment by clicking the 'Book Appointment' button on your dashboard or navigation menu. Select a doctor, date, and time slot."
            },
            {
                id: 2,
                question: "How can I view my lab results?",
                answer: "Go to the 'Lab Results' section. Note that you must pay any outstanding bills related to the test before you can view the detailed results."
            },
            {
                id: 3,
                question: "How do I change my password?",
                answer: "Navigate to the Settings page (gear icon in the header). Enter your current password and your new desired password to update it."
            },
            {
                id: 4,
                question: "Can I download my medical records?",
                answer: "Yes, you can request certificates or view your history in the 'Medical History' section (if applicable) or request a specific certificate from the 'Request Certificate' page."
            },
            {
                id: 5,
                question: "What payment methods are accepted?",
                answer: "We accept credit cards, debit cards, and insurance payments. You can view your pending bills in the 'Payments' section."
            }
        ];

        renderFAQs(faqs);
    }, 800);
}

function renderFAQs(data) {
    const container = document.getElementById('faq-container');
    container.innerHTML = '';
    
    if (data.length === 0) {
        container.innerHTML = '<p>No FAQs available at the moment.</p>';
        return;
    }
    
    data.forEach(item => {
        const details = document.createElement('details');
        details.style.marginBottom = '15px';
        details.style.border = '1px solid #eee';
        details.style.borderRadius = '8px';
        details.style.padding = '10px';
        
        const summary = document.createElement('summary');
        summary.textContent = item.question;
        summary.style.fontWeight = '600';
        summary.style.cursor = 'pointer';
        summary.style.outline = 'none';
        
        const answer = document.createElement('div');
        answer.style.marginTop = '10px';
        answer.style.color = '#555';
        answer.style.lineHeight = '1.5';
        answer.textContent = item.answer;
        
        details.appendChild(summary);
        details.appendChild(answer);
        container.appendChild(details);
    });
}
</script>

<?php include 'includes/footer.php'; ?>
