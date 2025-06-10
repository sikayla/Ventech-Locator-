<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/db_connection.php'; // Adjust path

// Ensure owner is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'owner') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit;
}

try {
    // Fetch all users who are not 'owner'
    // This is important because the owner shouldn't chat with other owners
    // via this interface, and shouldn't chat with themselves (though that's handled by other logic).
    $stmt = $pdo->prepare("SELECT id, username FROM users WHERE role != 'owner' ORDER BY username ASC");
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['status' => 'success', 'users' => $users]);

} catch (PDOException $e) {
    // Log the database error for debugging purposes
    error_log("Error fetching user list for owner chat: " . $e->getMessage());
    // Send a generic error message to the client
    echo json_encode(['status' => 'error', 'message' => 'Database error.']);
}
?>