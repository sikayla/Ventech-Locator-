<?php
// admin/admin_dashboard.php

// Include the database connection file
// Go up one directory (from 'admin/') to reach 'ventech_locator/', then into 'includes'
include_once('../includes/db_connection.php');

// Start session
session_start();

// Check if admin is logged in, otherwise redirect to admin login page
if (!isset($_SESSION['admin_user_id']) || $_SESSION['admin_user_role'] !== 'admin') {
    header("Location: admin_login.php");
    exit;
}

$admin_user_id = $_SESSION['admin_user_id'];
$admin_username = $_SESSION['admin_username'];

// Check if PDO connection is available
if (!isset($pdo) || !$pdo instanceof PDO) {
    error_log("PDO connection not available in admin_dashboard.php");
    die("Sorry, we're experiencing technical difficulties with the database. Please try again later.");
}

// Fetch dashboard summary counts
$total_users_count = 0;
$total_venues_count = 0;
$total_reservations_count = 0;
$pending_reservations_count = 0;

try {
    // Total Users (excluding admin role itself, if desired, but including all for a general count)
    $stmt = $pdo->query("SELECT COUNT(*) FROM users");
    $total_users_count = $stmt->fetchColumn();

    // Total Venues
    $stmt = $pdo->query("SELECT COUNT(*) FROM venue");
    $total_venues_count = $stmt->fetchColumn();

    // Total Reservations
    $stmt = $pdo->query("SELECT COUNT(*) FROM venue_reservations");
    $total_reservations_count = $stmt->fetchColumn();

    // Pending Reservations
    $stmt = $pdo->query("SELECT COUNT(*) FROM venue_reservations WHERE status = 'pending'");
    $pending_reservations_count = $stmt->fetchColumn();

} catch (PDOException $e) {
    error_log("Error fetching dashboard summary: " . $e->getMessage());
    // Gracefully degrade or display an error message on the page
}

// Fetch a list of users for management (e.g., last 20 users or filterable)
$users = [];
try {
    $stmt = $pdo->query("SELECT id, username, email, role, created_at FROM users ORDER BY created_at DESC LIMIT 20");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching users for admin dashboard: " . $e->getMessage());
}

// Admin Logout Path
$adminLogoutPath = 'admin_logout.php'; // In the same 'admin' directory
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1" name="viewport"/>
    <title>Admin Dashboard - Ventech Locator</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@600&family=Open+Sans&display=swap" rel="stylesheet"/>

    <style>
        body {
            font-family: 'Open Sans', sans-serif;
            background-color: #f4f7f6;
        }
        .header-bg {
            background-color: #00303f; /* Dark blue-grey */
        }
        .sidebar-bg {
            background-color: #1a202c; /* Even darker, almost black for sidebar */
        }
        .main-content-bg {
            background-color: #edf2f7; /* Light grey for content area */
        }
        .card {
            background-color: white;
            border-radius: 0.5rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            transition: all 0.2s ease-in-out;
        }
        .card:hover {
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            transform: translateY(-2px);
        }
        .table-header {
            background-color: #e2e8f0;
        }
        .action-button {
            padding: 0.4rem 0.8rem;
            border-radius: 0.25rem;
            font-size: 0.75rem;
            font-weight: 600;
            transition: background-color 0.2s;
        }
        .edit-button {
            background-color: #4299e1; /* Blue */
            color: white;
        }
        .edit-button:hover {
            background-color: #3182ce;
        }
        .delete-button {
            background-color: #ef4444; /* Red */
            color: white;
        }
        .delete-button:hover {
            background-color: #dc2626;
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
<body class="flex h-screen">

    <!-- Loading Overlay -->
    <div id="loading-overlay">
        <div class="loader-container">
            <i class="fas fa-map-marker-alt loader-pin"></i>
            <div class="loader-bar">
                <div class="loader-indicator"></div>
            </div>
        </div>
    </div>

    <!-- Sidebar -->
    <aside class="sidebar-bg w-64 flex flex-col justify-between p-6 text-white shadow-lg">
        <div>
            <h1 class="text-2xl font-bold text-center mb-10 mt-2">Admin Panel</h1>
            <nav>
                <ul class="space-y-4">
                    <li>
                        <a href="admin_dashboard.php" class="flex items-center text-lg hover:text-blue-300 transition-colors">
                            <i class="fas fa-tachometer-alt mr-3"></i> Dashboard
                        </a>
                    </li>
                    <li>
                        <a href="#" class="flex items-center text-lg hover:text-blue-300 transition-colors">
                            <i class="fas fa-users mr-3"></i> Users
                        </a>
                    </li>
                    <li>
                        <a href="#" class="flex items-center text-lg hover:text-blue-300 transition-colors">
                            <i class="fas fa-store mr-3"></i> Venues
                        </a>
                    </li>
                    <li>
                        <a href="#" class="flex items-center text-lg hover:text-blue-300 transition-colors">
                            <i class="fas fa-calendar-alt mr-3"></i> Reservations
                        </a>
                    </li>
                    <li>
                        <a href="#" class="flex items-center text-lg hover:text-blue-300 transition-colors">
                            <i class="fas fa-chart-line mr-3"></i> Reports
                        </a>
                    </li>
                </ul>
            </nav>
        </div>
        <div class="mt-auto">
            <a href="<?php echo htmlspecialchars($adminLogoutPath); ?>" class="flex items-center text-lg hover:text-red-400 transition-colors">
                <i class="fas fa-sign-out-alt mr-3"></i> Logout
            </a>
        </div>
    </aside>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col main-content-bg overflow-auto">
        <!-- Header -->
        <header class="header-bg p-6 text-white shadow-md flex justify-between items-center">
            <h2 class="text-3xl font-bold">Dashboard</h2>
            <div class="flex items-center space-x-4">
                <span class="text-xl">Welcome, <strong class="text-yellow-300"><?= htmlspecialchars($admin_username) ?></strong>!</span>
            </div>
        </header>

        <!-- Page Content -->
        <main class="p-6 md:p-8 lg:p-10 flex-1 overflow-y-auto">
            <section class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <div class="card p-6 flex flex-col justify-between">
                    <h3 class="text-lg font-semibold text-gray-600 flex items-center mb-2">
                        <i class="fas fa-users mr-3 text-blue-500 text-2xl"></i> Total Users
                    </h3>
                    <p class="text-4xl font-bold text-gray-800 mt-auto"><?= htmlspecialchars($total_users_count) ?></p>
                </div>
                <div class="card p-6 flex flex-col justify-between">
                    <h3 class="text-lg font-semibold text-gray-600 flex items-center mb-2">
                        <i class="fas fa-store mr-3 text-green-500 text-2xl"></i> Total Venues
                    </h3>
                    <p class="text-4xl font-bold text-gray-800 mt-auto"><?= htmlspecialchars($total_venues_count) ?></p>
                </div>
                <div class="card p-6 flex flex-col justify-between">
                    <h3 class="text-lg font-semibold text-gray-600 flex items-center mb-2">
                        <i class="fas fa-calendar-alt mr-3 text-purple-500 text-2xl"></i> Total Reservations
                    </h3>
                    <p class="text-4xl font-bold text-gray-800 mt-auto"><?= htmlspecialchars($total_reservations_count) ?></p>
                </div>
                <div class="card p-6 flex flex-col justify-between">
                    <h3 class="text-lg font-semibold text-gray-600 flex items-center mb-2">
                        <i class="fas fa-hourglass-half mr-3 text-yellow-500 text-2xl"></i> Pending Reservations
                    </h3>
                    <p class="text-4xl font-bold text-gray-800 mt-auto"><?= htmlspecialchars($pending_reservations_count) ?></p>
                </div>
            </section>

            <section class="mb-8">
                <h2 class="text-2xl font-bold text-gray-800 mb-6">Recent Users</h2>
                <div class="card overflow-x-auto">
                    <?php if (count($users) > 0): ?>
                        <table class="w-full table-auto text-left whitespace-nowrap">
                            <thead class="table-header">
                                <tr>
                                    <th class="px-6 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider">ID</th>
                                    <th class="px-6 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider">Username</th>
                                    <th class="px-6 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider">Email</th>
                                    <th class="px-6 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider">Role</th>
                                    <th class="px-6 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider">Created At</th>
                                    <th class="px-6 py-3 text-xs font-semibold text-gray-600 uppercase tracking-wider text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php foreach ($users as $user): ?>
                                <tr>
                                    <td class="px-6 py-4 text-sm font-medium text-gray-900"><?= htmlspecialchars($user['id']) ?></td>
                                    <td class="px-6 py-4 text-sm text-gray-700"><?= htmlspecialchars($user['username']) ?></td>
                                    <td class="px-6 py-4 text-sm text-gray-700"><?= htmlspecialchars($user['email']) ?></td>
                                    <td class="px-6 py-4 text-sm text-gray-700"><?= htmlspecialchars(ucfirst($user['role'])) ?></td>
                                    <td class="px-6 py-4 text-sm text-gray-700"><?= htmlspecialchars(date("M d, Y H:i", strtotime($user['created_at']))) ?></td>
                                    <td class="px-6 py-4 text-sm text-center">
                                        <a href="#" class="action-button edit-button mr-2">Edit</a>
                                        <a href="#" class="action-button delete-button">Delete</a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p class="p-6 text-center text-gray-600">No users found.</p>
                    <?php endif; ?>
                </div>
            </section>
        </main>
    </div>

    <script>
        // Loading Overlay JavaScript
        document.addEventListener('DOMContentLoaded', function() {
            const loadingOverlay = document.getElementById('loading-overlay');
            let minLoadTimePassed = false;
            let pageFullyLoaded = false;

            // Set a timeout for the minimum 1-second display for admin panel (can adjust)
            setTimeout(() => {
                minLoadTimePassed = true;
                if (pageFullyLoaded && loadingOverlay) {
                    loadingOverlay.classList.add('hidden');
                    loadingOverlay.addEventListener('transitionend', function handler() {
                        if (loadingOverlay.classList.contains('hidden')) {
                            loadingOverlay.remove();
                            loadingOverlay.removeEventListener('transitionend', handler);
                        }
                    });
                }
            }, 1000); // 1000 milliseconds = 1 second

            pageFullyLoaded = true;
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