<?php
// admin/admin_login.php

// Include the database connection file
// Go up one directory (from 'admin/') to reach 'ventech_locator/', then into 'includes'
include_once('../includes/db_connection.php');

// Start session for login
session_start();

// Initialize variables for errors
$errors = [];

// Check if the form was submitted via POST method
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Sanitize email and password input
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $password = $_POST["password"] ?? '';

    // Validate email and password presence
    if (empty($email)) {
        $errors[] = "Email is required.";
    }
    if (empty($password)) {
        $errors[] = "Password is required.";
    }

    // If no initial validation errors, proceed to authenticate against the database
    if (empty($errors)) {
        // Check if $pdo connection is available from db_connection.php
        if (!isset($pdo) || !$pdo instanceof PDO) {
             error_log("PDO connection not available in admin_login.php");
             $errors[] = "Database connection error. Please try again later.";
        } else {
            try {
                // Prepare SQL query to find user by email and ensure their role is 'admin'
                $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND role = 'admin'");
                $stmt->execute([$email]);
                $admin_user = $stmt->fetch(); // Fetch the admin user row

                // Check if an admin user was found
                if ($admin_user) {
                    // Verify the submitted password against the hashed password in the database
                    // NOTE: Your ventech.sql has '12345678' for admin. If this is a plain text password,
                    // you should use a direct comparison for demonstration, but for production,
                    // ALWAYS use password_verify with hashed passwords.
                    // For example, if '12345678' is the plain password:
                    if ($password === '12345678') { // DIRECT COMPARISON FOR DEMO
                    // If you hashed '12345678' and stored the hash:
                    // if (password_verify($password, $admin_user['password'])) { // Use this in production

                        // Password is correct, set session variables for the logged-in admin
                        $_SESSION['admin_user_id'] = $admin_user['id'];
                        $_SESSION['admin_username'] = $admin_user['username'];
                        $_SESSION['admin_email'] = $admin_user['email'];
                        $_SESSION['admin_user_role'] = 'admin';

                        // Regenerate session ID to prevent session fixation
                        session_regenerate_id(true);

                        // Redirect to the admin dashboard
                        header("Location: admin_dashboard.php");
                        exit;
                    } else {
                        // Password does not match
                        $errors[] = "Incorrect password.";
                    }
                } else {
                    // No admin user found with the provided email and 'admin' role
                    $errors[] = "No admin account found with this email.";
                }
            } catch (PDOException $e) {
                // Log database errors
                error_log("Database login error in admin_login.php: " . $e->getMessage());
                $errors[] = "An error occurred during login. Please try again.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Admin Login - Ventech Locator</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link
    rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css"
  />
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@600&display=swap');
    body {
        font-family: 'Poppins', sans-serif;
        background-color: #f0f2f5;
    }
    .login-container {
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 20px;
    }
    .login-box {
        background-color: white;
        border-radius: 1rem;
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        overflow: hidden;
        max-width: 500px; /* Adjusted max-width for admin login */
        width: 100%;
    }
    .panel {
        padding: 40px;
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        text-align: center;
    }
    input[type="email"],
    input[type="password"] {
        background-color: #e2e8f0;
        color: #1a202c;
        font-size: 0.875rem;
        border-radius: 0.125rem;
        padding: 0.5rem 0.75rem;
        width: 100%;
        border: none;
    }
    input:focus, textarea:focus, select:focus {
        outline: 2px solid transparent;
        outline-offset: 2px;
        box-shadow: ring-2 ring-blue-500;
    }
    .form-button {
        background-color: #00303f; /* Dark blue-grey for admin theme */
        color: white;
        font-weight: 600;
        font-size: 0.75rem;
        border-radius: 0.125rem;
        padding: 0.5rem 1.5rem;
        margin-top: 0.75rem;
        transition: background-color 0.2s;
    }
    .form-button:hover {
        background-color: #004c64; /* Darker blue-grey */
    }

    /* Loading Overlay Styles (retained from client_login.php) */
    #loading-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background-color: white;
        display: flex;
        justify-content: center;
        align-items: center;
        z-index: 10000;
        opacity: 1;
        visibility: visible;
        transition: opacity 0.5s ease-out, visibility 0.5s ease-out;
    }

    #loading-overlay.hidden {
        opacity: 0;
        visibility: hidden;
    }

    .loader-container {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        position: relative;
        width: 150px;
        height: 150px;
    }

    .loader-pin {
        color: #ff5722;
        font-size: 3.5rem;
        margin-bottom: 15px;
        animation: bounce 1.5s infinite;
    }

    @keyframes bounce {
        0%, 20%, 50%, 80%, 100% {
            transform: translateY(0);
        }
        40% {
            transform: translateY(-20px);
        }
        60% {
            transform: translateY(-10px);
        }
    }

    .loader-bar {
        width: 80px;
        height: 4px;
        background-color: #f0f0f0;
        border-radius: 2px;
        position: relative;
        overflow: hidden;
    }
  </style>
</head>
<body class="font-poppins">
  <!-- Loading Overlay -->
  <div id="loading-overlay">
      <div class="loader-container">
          <i class="fas fa-map-marker-alt loader-pin"></i>
          <div class="loader-bar">
              <div class="loader-indicator"></div>
          </div>
      </div>
  </div>

  <div class="flex items-center justify-center bg-white p-6 login-container">
    <div class="bg-white rounded-3xl w-full login-box">
      <div class="panel">
        <h2 class="poppins font-semibold text-2xl mb-6 text-gray-800">Admin Login</h2>
        
        <?php if (!empty($errors)): ?>
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-3 mb-4 rounded text-sm w-full" role="alert">
            <p class="font-bold">Login Error:</p>
            <ul class="list-disc list-inside">
                <?php foreach ($errors as $error): ?>
                    <li><?= htmlspecialchars($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <form id="loginForm" action="admin_login.php" method="POST" class="space-y-4 w-full" aria-label="Admin Login form" novalidate="">
          <div class="relative">
            <input
              type="email"
              name="email"
              placeholder="Admin Email"
              class="w-full bg-gray-300 text-black text-sm rounded-sm px-3 py-2 focus:outline-none"
              value="<?= isset($email) ? htmlspecialchars($email) : '' ?>"
              required
            />
          </div>
          <div class="relative">
            <input
              type="password"
              name="password"
              placeholder="Password"
              class="w-full bg-gray-300 text-black text-sm rounded-sm px-3 py-2 focus:outline-none"
              required
            />
          </div>
          <button
            type="submit"
            class="form-button w-full"
          >
            LOGIN
          </button>
        </form>
      </div>
    </div>
  </div>

  <script>
        // JavaScript to show the loading overlay on form submission and hide on page load
        document.addEventListener('DOMContentLoaded', function() {
            const loginForm = document.getElementById('loginForm');
            const loadingOverlay = document.getElementById('loading-overlay');

            // Show loading overlay immediately when the page starts loading
            if (loadingOverlay) {
                loadingOverlay.classList.add('visible');
            }

            if (loginForm && loadingOverlay) {
                // Show loading overlay when the form is submitted
                loginForm.addEventListener('submit', function() {
                    loadingOverlay.classList.add('visible');
                });
            }
        });

        // Hide loading overlay with minimum display time
        window.addEventListener('load', function() {
            const loadingOverlay = document.getElementById('loading-overlay');
            let minLoadTimePassed = false;
            let pageFullyLoaded = false;

            // Set a timeout for the minimum 3-second display
            setTimeout(() => {
                minLoadTimePassed = true;
                // If page has already fully loaded AND minimum time has passed, hide it.
                if (pageFullyLoaded && loadingOverlay) {
                    loadingOverlay.classList.add('hidden');
                    loadingOverlay.addEventListener('transitionend', function handler() {
                        if (loadingOverlay.classList.contains('hidden')) {
                            loadingOverlay.remove();
                            loadingOverlay.removeEventListener('transitionend', handler);
                        }
                    });
                }
            }, 3000); // 3000 milliseconds = 3 seconds

            pageFullyLoaded = true;
            // If minimum time has already passed, hide it.
            if (minLoadTimePassed && loadingOverlay) {
                loadingOverlay.classList.add('hidden');
                loadingOverlay.addEventListener('transitionend', function handler() {
                    if (loadingOverlay.classList.contains('hidden')) {
                        loadingOverlay.remove();
                        loadingOverlay.removeEventListener('transitionend', handler);
                    }
                });
            }
        });
    </script>
</body>
</html>