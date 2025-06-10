<?php
session_start();

// ==================== Configuration ====================
define('LOGIN_PATH', '/ventech_locator/users/user_login.php'); // Path to your login page

// ==================== Database Connection ====================
// It's highly recommended to centralize this into a shared db_connection.php file
// and include it here instead of defining it directly in each script.
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
    error_log("DB connection error in verify_email.php: " . $e->getMessage());
    header("Location: " . LOGIN_PATH . "?email_verified=0&error_db=1");
    exit;
}

// ==================== Handle Email Verification ====================
if (isset($_GET['token']) && !empty($_GET['token'])) {
    $token = $_GET['token'];

    try {
        // Find the user with the given token and who is not yet verified
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email_verification_token = ? AND email_verified_at IS NULL LIMIT 1");
        $stmt->execute([$token]);
        $user = $stmt->fetch();

        if ($user) {
            // User found, update their verification status
            $update_stmt = $pdo->prepare("UPDATE users SET email_verified_at = NOW(), email_verification_token = NULL WHERE id = ?");
            $update_stmt->execute([$user['id']]);

            // Redirect to login page with success message
            header("Location: " . LOGIN_PATH . "?email_verified=1");
            exit;
        } else {
            // Token not found or user already verified
            error_log("Invalid or expired verification token: " . $token);
            header("Location: " . LOGIN_PATH . "?email_verified=0&reason=invalid_token");
            exit;
        }

    } catch (PDOException $e) {
        error_log("Error during email verification: " . $e->getMessage());
        header("Location: " . LOGIN_PATH . "?email_verified=0&error_verify=1");
        exit;
    }
} else {
    // No token provided
    header("Location: " . LOGIN_PATH . "?email_verified=0&reason=no_token");
    exit;
}
?>
