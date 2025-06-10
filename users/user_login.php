<?php
session_start();

// ==================== Configuration ====================
define('DASHBOARD_PATH', '/ventech_locator/users/user_dashboard.php');
define('SIGNUP_PATH', '/ventech_locator/users/user_signup.php');
define('FORGOT_PASSWORD_PATH', '#'); // Replace with actual path when available
define('VERIFY_EMAIL_PATH', '/ventech_locator/users/verify_email.php'); // New: Path to email verification script
define('RESEND_VERIFICATION_PATH', '/ventech_locator/users/resend_verification.php'); // New: Path to resend verification script

// ==================== Redirect if Logged In ====================
// This check is for direct access to user_login.php when already logged in.
// If loaded in an iframe, the parent page might handle this differently.
if (isset($_SESSION['user_id'])) {
    // If already logged in, redirect the parent window (if in iframe) or self
    echo '<script type="text/javascript">';
    echo 'if (window.self !== window.top) {'; // Check if inside an iframe
    echo '    window.top.location.href = "' . DASHBOARD_PATH . '";'; // Redirect parent
    echo '} else {';
    echo '    window.location.href = "' . DASHBOARD_PATH . '";'; // Redirect self
    echo '}';
    echo '</script>';
    exit;
}

// ==================== Database Connection ====================
// It's highly recommended to centralize this into a shared db_connection.php file
// and include it here instead of defining it directly.
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

$error = "";
$login_val = ""; // To retain email/username input value
$success_message = ''; // For registration success message
$show_resend_button = false; // Flag to show resend verification email button

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    error_log("DB connection error in user_login.php: " . $e->getMessage());
    $error = "We're experiencing technical issues. Please try again later.";
}

// ==================== Handle POST Login ====================
if ($_SERVER["REQUEST_METHOD"] === "POST" && empty($error)) {

    // -------- Guest Login --------
    if (isset($_POST['login_as_guest'])) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE role = 'guest' LIMIT 1");
            $stmt->execute();
            $guest_user = $stmt->fetch();

            if ($guest_user) {
                // Use existing guest
                $_SESSION['user_id'] = $guest_user['id'];
                $_SESSION['username'] = 'Guest';
                $_SESSION['role'] = 'guest';
            } else {
                // Create new guest account
                $guest_username = 'guest_' . uniqid();
                $guest_email = $guest_username . '@example.com';
                // Generate a random password for the guest, hash it.
                $guest_password_hash = password_hash('guest_' . uniqid(), PASSWORD_DEFAULT);

                $stmt_guest = $pdo->prepare("INSERT INTO users (username, email, password, role, created_at) VALUES (:username, :email, :password, 'guest', NOW())");
                $stmt_guest->execute([
                    ':username' => $guest_username,
                    ':email' => $guest_email,
                    ':password' => $guest_password_hash,
                ]);

                $_SESSION['user_id'] = $pdo->lastInsertId();
                $_SESSION['username'] = $guest_username;
                $_SESSION['role'] = 'guest';
            }
            // Regenerate session ID to prevent session fixation
            session_regenerate_id(true);

            // Redirect the parent window (if in iframe) or self
            echo '<script type="text/javascript">';
            echo 'if (window.self !== window.top) {'; // Check if inside an iframe
            echo '    window.top.location.href = "' . DASHBOARD_PATH . '";'; // Redirect parent
            echo '} else {';
            echo '    window.location.href = "' . DASHBOARD_PATH . '";'; // Redirect self
            echo '}';
            echo '</script>';
            exit;

        } catch (PDOException $e) {
            error_log("Guest login error: " . $e->getMessage());
            $error = "Guest login failed. Please try again.";
        }

    } else {
        // -------- Regular User Login --------
        $login_val = trim($_POST['email_or_username'] ?? '');
        $password = $_POST['password'] ?? '';
        // Sanitize for display, but use raw for password_verify and DB query
        $login_display = htmlspecialchars($login_val, ENT_QUOTES, 'UTF-8');

        if (empty($login_val) || empty($password)) {
            $error = "Please enter both username/email and password.";
        } else {
            try {
                // Prepare statement to find user by email or username
                // IMPORTANT: Ensure 'email_verified_at' column exists in your 'users' table
                $stmt = $pdo->prepare("SELECT id, username, email, password, role, email_verified_at FROM users WHERE email = ? OR username = ? LIMIT 1");
                $stmt->execute([$login_val, $login_val]); // Use raw input for query
                $user = $stmt->fetch();

                if ($user && password_verify($password, $user['password'])) {
                    // Password is correct, now check email verification status
                    if (empty($user['email_verified_at'])) {
                        $error = "Your email address is not verified. Please check your email for a verification link. If you haven't received it, you can request a new one.";
                        $show_resend_button = true; // Show the resend button
                        // Store email/username temporarily in session to pre-fill resend form if needed
                        $_SESSION['unverified_email_or_username'] = $login_val;
                    } else {
                        // Email is verified, set session and redirect
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['username'] = $user['username'];
                        $_SESSION['role'] = $user['role'];

                        // Regenerate session ID to prevent session fixation
                        session_regenerate_id(true);

                        // Redirect the parent window (if in iframe) or self
                        echo '<script type="text/javascript">';
                        echo 'if (window.self !== window.top) {'; // Check if inside an iframe
                        echo '    window.top.location.href = "' . DASHBOARD_PATH . '";'; // Redirect parent
                        echo '} else {';
                        echo '    window.location.href = "' . DASHBOARD_PATH . '";'; // Redirect self
                        echo '}';
                        echo '</script>';
                        exit;
                    }
                } else {
                    $error = "Invalid login credentials.";
                }
            } catch (PDOException $e) {
                error_log("Login error: " . $e->getMessage());
                $error = "Login failed. Please try again.";
            }
        }
    }
}

// ==================== Handle Registration Redirect Message ====================
if (isset($_GET['registered']) && $_GET['registered'] == 1) {
    $success_message = "Registration successful! Please log in.";
    // If registration just happened, and we expect email verification,
    // you might want to explicitly set a message about checking email here.
    // For now, it's covered by the login check, but a direct message can be helpful.
    if (isset($_GET['verification_required']) && $_GET['verification_required'] == 1) {
        $success_message .= " A verification email has been sent to your inbox. Please click the link in the email to activate your account.";
        // Pre-fill the login form with the username/email used for registration if available in GET
        if (isset($_GET['email_or_username'])) {
             $login_val = htmlspecialchars($_GET['email_or_username']);
        }
    }
}

// Handle message from email verification success
if (isset($_GET['email_verified']) && $_GET['email_verified'] == 1) {
    $success_message = "Your email has been successfully verified! You can now log in.";
} elseif (isset($_GET['email_verified']) && $_GET['email_verified'] == 0) {
    $error = "Email verification failed or link expired. Please try logging in or resend verification.";
}

// Handle message from resend verification request
if (isset($_GET['resend_success']) && $_GET['resend_success'] == 1) {
    $success_message = "A new verification email has been sent. Please check your inbox (and spam folder!).";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>User Login - Ventech Locator</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css"/>
     <link rel="stylesheet" href="/ventech_locator/css/user/user_login.css">

    <style>
      /* Add any additional CSS specific to this page here if needed */
      /* For the resend verification button */
      .resend-button {
          background-color: #3b82f6; /* Tailwind blue-500 */
          color: white;
          font-weight: 600;
          font-size: 0.75rem; /* text-xs */
          padding: 0.5rem 1.5rem; /* px-6 py-2 */
          border-radius: 0.125rem; /* rounded-sm */
          margin-top: 0.75rem; /* mt-3 */
          transition: background-color 0.15s ease-in-out;
          width: 100%; /* Full width on small screens */
          box-sizing: border-box; /* Include padding in width */
          border: none; /* No default border */
          cursor: pointer;
      }
      .resend-button:hover {
          background-color: #2563eb; /* Tailwind blue-600 */
      }
      .resend-button:disabled {
          background-color: #9ca3af; /* Tailwind gray-400 */
          cursor: not-allowed;
      }
    </style>
</head>
<body>
  <!-- Loading Overlay -->
  <div id="loading-overlay">
      <div class="loader-container">
          <i class="fas fa-map-marker-alt loader-pin"></i>
          <div class="loader-bar">
              <div class="loader-indicator"></div>
          </div>
      </div>
  </div>

  <div class="min-h-screen flex items-center justify-center p-6"> <!-- Removed bg-gradient-to-r classes -->
    <div class="flex flex-col md:flex-row bg-white rounded-3xl w-full max-w-4xl overflow-hidden">
      <div class="flex flex-col justify-center px-10 py-12 md:w-1/2">
        <h2 class="font-poppins font-semibold text-2xl mb-6 text-black">User Login</h2>
        
        <div class="flex space-x-3 mb-6">
          <button aria-label="Login with Google" class="border border-[#00303f] text-[#00303f] rounded-md w-10 h-10 flex items-center justify-center text-xs font-semibold hover:bg-[#00303f] hover:text-white transition">
            G+
          </button>
          <button aria-label="Login with Facebook" class="border border-[#00303f] text-[#00303f] rounded-md w-10 h-10 flex items-center justify-center text-base font-semibold hover:bg-[#00303f] hover:text-white transition">
            <i class="fab fa-facebook-f"></i>
          </button>
          <button aria-label="Login with GitHub" class="border border-[#00303f] text-[#00303f] rounded-md w-10 h-10 flex items-center justify-center text-base font-semibold hover:bg-[#00303f] hover:text-white transition">
            <i class="fab fa-github"></i>
          </button>
          <button aria-label="Login with LinkedIn" class="border border-[#00303f] text-[#00303f] rounded-md w-10 h-10 flex items-center justify-center text-base font-semibold hover:bg-[#00303f] hover:text-white transition">
            <i class="fab fa-linkedin-in"></i>
          </button>
        </div>
        <div class="text-center mb-4">
          <span class="font-poppins font-semibold text-lg text-black">OR</span>
          <p class="text-xs text-black">Login With Your Email & Password</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-3 mb-4 rounded text-sm" role="alert">
                <p class="font-bold">Login Error:</p>
                <p><?= htmlspecialchars($error) ?></p>
            </div>
        <?php endif; ?>

        <?php if (!empty($success_message)): ?>
            <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-3 mb-4 rounded text-sm" role="alert">
                <p class="font-bold">Success!</p>
                <p><?= htmlspecialchars($success_message) ?></p>
            </div>
        <?php endif; ?>

        <form id="loginForm" method="POST" action="" class="space-y-3" aria-label="User Login form" novalidate="">
          <input
            type="text"
            id="email_or_username"
            name="email_or_username"
            placeholder="Email or Username"
            class="w-full bg-gray-300 text-black text-sm rounded-sm px-3 py-2 focus:outline-none"
            value="<?= htmlspecialchars($login_val) ?>"
            required
          />
          <input
            type="password"
            name="password"
            placeholder="Password"
            class="w-full bg-gray-300 text-black text-sm rounded-sm px-3 py-2 focus:outline-none"
            required
          />
          <button
            type="submit"
            class="bg-[#b5b600] text-white font-semibold text-xs rounded-sm px-6 py-2 mt-3 hover:bg-[#a0a000] transition"
          >
            LOGIN
          </button>
        </form>

        <?php if ($show_resend_button): ?>
            <form id="resendVerificationForm" method="POST" action="<?= RESEND_VERIFICATION_PATH ?>" class="mt-2">
                <input type="hidden" name="email_or_username" value="<?= htmlspecialchars($login_val) ?>">
                <button type="submit" class="resend-button">
                    Resend Verification Email
                </button>
            </form>
        <?php endif; ?>

        <form id="guestLoginForm" method="POST" action="" class="mt-4">
            <button type="submit" name="login_as_guest" class="w-full bg-[#00303f] text-white font-semibold text-xs rounded-sm px-6 py-2 hover:bg-[#1a4a5f] transition">
                LOGIN AS GUEST
            </button>
        </form>

        <p class="text-center text-xs mt-4">
            <a class="font-bold text-blue-700 hover:underline" href="<?= FORGOT_PASSWORD_PATH ?>">Forgot your password?</a>
        </p>
        <p class="text-center text-xs">
            Don't have an account? <a class="font-bold text-blue-700 hover:underline" href="<?= SIGNUP_PATH ?>">Register here</a>
        </p>
      </div>

      <div class="md:w-1/2 bg-[#00303f] rounded-tr-3xl rounded-br-3xl flex flex-col justify-center items-center px-10 py-12 text-white text-center">
        <h2 class="font-poppins font-semibold text-2xl mb-3">Hello</h2>
        <p class="text-xs mb-6">Register to use all features in our site</p>
        <a href="<?= SIGNUP_PATH ?>" class="border border-white text-white text-xs font-semibold px-6 py-2 rounded-sm hover:bg-white hover:text-[#00303f] transition">
          SIGN UP
        </a>
      </div>
    </div>
  </div>

  <script>
        document.addEventListener("DOMContentLoaded", function() {
            const loadingOverlay = document.getElementById('loading-overlay');

            // This variable will track if the minimum loading time has passed
            let minLoadTimePassed = false;
            // This variable will track if the page has fully loaded (all resources)
            let pageFullyLoaded = false;

            // Set a timeout for the minimum 4-second display
            setTimeout(() => {
                minLoadTimePassed = true;
                // If page has already fully loaded AND minimum time has passed, hide it.
                if (pageFullyLoaded) {
                    hideLoadingOverlay();
                }
            }, 4000); // 4000 milliseconds = 4 seconds

            // Function to hide the loading overlay
            function hideLoadingOverlay() {
                if (loadingOverlay) {
                    loadingOverlay.classList.add('hidden'); // Add hidden class to trigger transition
                    // Optional: Remove the element from the DOM after transition
                    loadingOverlay.addEventListener('transitionend', function handler() {
                        if (loadingOverlay.classList.contains('hidden')) {
                            loadingOverlay.remove();
                            loadingOverlay.removeEventListener('transitionend', handler); // Clean up
                        }
                    });
                }
            }

            // Mark page as fully loaded when all resources are ready
            window.addEventListener('load', function() {
                pageFullyLoaded = true;
                // If minimum time has already passed, hide it.
                if (minLoadTimePassed) {
                    hideLoadingOverlay();
                }
            });

            // The existing form submission logic remains as is.
            // The loading overlay is already shown by default on page load.
            // When a form is submitted, PHP handles the redirect immediately,
            // leading to a new page load where this script will run again.
        });
    </script>
</body>
</html>