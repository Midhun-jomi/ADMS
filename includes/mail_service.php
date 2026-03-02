<?php
// includes/mail_service.php
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Sends an email using PHPMailer via Google SMTP
 * 
 * @param string $to Recipient email address
 * @param string $subject Email subject
 * @param string $body Email content (HTML supported)
 * @return bool True on success, false on failure
 */
function send_email_smtp($to, $subject, $body) {
    // You should move these credentials to a secure config file or .env in production
    $smtp_host = 'smtp.gmail.com';
    $smtp_username = 'mjcmidhun@gmail.com'; // Replace with a real Gmail address
    $smtp_password = 'inwhhxkomklikjzl'; // Replace with an App Password (not the main password)
    $smtp_port = 587; // TLS port
    
    $mail = new PHPMailer(true);

    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = $smtp_host;
        $mail->SMTPAuth   = true;
        $mail->Username   = $smtp_username;
        $mail->Password   = $smtp_password;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $smtp_port;

        // Recipients
        $mail->setFrom($smtp_username, 'ADMS Hospital');
        $mail->addAddress($to);

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = nl2br($body);
        $mail->AltBody = strip_tags($body);

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Message could not be sent. Mailer Error: {$mail->ErrorInfo}");
        return false;
    }
}
?>
