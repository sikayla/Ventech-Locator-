<?php
session_start();

// PHPMailer Autoload (Assuming you have PHPMailer installed via Composer or manually)
// If you're not using Composer, you'll need to include the files manually:
// require 'path/to/PHPMailer/src/Exception.php';
// require 'path/to/PHPMailer/src/PHPMailer.php';
// require 'path/to/PHPMailer/src/SMTP.php';

// If using Composer:
// require 'vendor/autoload.php';

// Use a placeholder for email sending if PHPMailer is not set up
// For actual email sending, uncomment PHPMailer imports and configure it
// use PHPMailer\PHPMailer\PHPMailer;
// use PHPMailer\PHPMailer\Exception;

// ==================== Configuration ====================
define('LOGIN_PATH', '/ventech_locator/users/user_login.php'); // Path to your login page
define('VERIFY_EMAIL_PATH', '/ventech_locator/users/verify_email.php'); // Path to the verification script

// Email sender configuration (REPLACE WITH YOUR ACTUAL SMTP DETAILS)
define('SMTP_HOST', 'smtp.example.com'); // Your SMTP server
define('SMTP_USERNAME', 'your_email@example.com'); // Your SMTP username
define('SMTP_PASSWORD', 'your_email_password'); // Your SMTP password
define('SMTP_PORT', 587); // Typically 587 for TLS, 465 for SSL
define('SMTP_SECURE', 'tls'); // Use 'ssl' or 'tls'

// ==================== Database Connection ====================
$host = 'localhost';
$db = 'ventech_db';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    error_log("DB connection error in resend_verification.php: " . $e->getMessage());
    header("Location: " . LOGIN_PATH . "?resend_success=0&error_db=1");
    exit;
}

// ==================== Handle Resend Verification Email ====================
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['email_or_username']) && !empty($_POST['email_or_username'])) {
    $login_val = trim($_POST['email_or_username']);

    try {
        // Find the user by email or username
        $stmt = $pdo->prepare("SELECT id, email, username, email_verified_at FROM users WHERE email = ? OR username = ? LIMIT 1");
        $stmt->execute([$login_val, $login_val]);
        $user = $stmt->fetch();

        if ($user) {
            if (!empty($user['email_verified_at'])) {
                // User is already verified
                header("Location: " . LOGIN_PATH . "?resend_success=0&reason=already_verified");
                exit;
            }

            // User exists but is not verified - generate new token and send email
            $new_token = bin2hex(random_bytes(32)); // Generate a new random token
            $user_email = $user['email']; // Get the actual email from the DB

            // Update user with the new token
            $update_stmt = $pdo->prepare("UPDATE users SET email_verification_token = ? WHERE id = ?");
            $update_stmt->execute([$new_token, $user['id']]);

            // ==================== Email Sending Logic (PHPMailer Recommended) ====================
            // For this example, we'll use a placeholder.
            // In a real application, you would integrate a library like PHPMailer.

            $verification_link = "http://" . $_SERVER['HTTP_HOST'] . VERIFY_EMAIL_PATH . "?token=" . $new_token;
            $subject = "Verify your email for Ventech Locator";
            $body = "Hi " . htmlspecialchars($user['username']) . ",<br><br>"
                  . "Thank you for registering! Please click on the following link to verify your email address:<br>"
                  . "<a href='" . $verification_link . "'>Verify Email Address</a><br><br>"
                  . "If you did not register for an account, please ignore this email.<br><br>"
                  . "Regards,<br>Ventech Locator Team";

            // PHPMailer Integration Example (uncomment and configure for live use)
            /*
            $mail = new PHPMailer(true);
            try {
                //Server settings
                $mail->isSMTP();
                $mail->Host       = SMTP_HOST;
                $mail->SMTPAuth   = true;
                $mail->Username   = SMTP_USERNAME;
                $mail->Password   = SMTP_PASSWORD;
                $mail->SMTPSecure = SMTP_SECURE;
                $mail->Port       = SMTP_PORT;

                //Recipients
                $mail->setFrom(SMTP_USERNAME, 'Ventech Locator');
                $mail->addAddress($user_email, $user['username']);

                //Content
                $mail->isHTML(true);
                $mail->Subject = $subject;
                $mail->Body    = $body;
                $mail->AltBody = strip_tags($body); // Plain text for non-HTML mail clients

                $mail->send();
                error_log("Verification email sent to: " . $user_email);

            } catch (Exception $e) {
                error_log("Failed to send verification email to " . $user_email . ". Mailer Error: " . $mail->ErrorInfo);
                // Optionally, don't redirect to success if email sending failed.
                // For now, we proceed to success message as token is updated.
            }
            */

            // Placeholder for email sending success
            // In a production environment, you'd only redirect to success IF the email was sent
            header("Location: " . LOGIN_PATH . "?resend_success=1");
            exit;

        } else {
            // User not found
            header("Location: " . LOGIN_PATH . "?resend_success=0&reason=user_not_found");
            exit;
        }

    } catch (PDOException $e) {
        error_log("Error during resend verification: " . $e->getMessage());
        header("Location: " . LOGIN_PATH . "?resend_success=0&error_resend=1");
        exit;
    }
} else {
    // Invalid request (e.g., accessed directly without POST data)
    header("Location: " . LOGIN_PATH . "?resend_success=0&reason=invalid_request");
    exit;
}
?>