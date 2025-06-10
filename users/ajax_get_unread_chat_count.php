<?php
session_start();
header('Content-Type: application/json');

// Include the database connection file
// Adjust this path if your db_connection.php is not in the same directory as this file
require_once __DIR__ . '/db_connection.php';

// Check if the user is logged in and has the 'owner' role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'owner') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit;
}

$owner_id = $_SESSION['user_id'];

try {
    // Count unread messages where the current owner is the receiver
    // and the sender is NOT the owner (i.e., it's from a student/regular user)
    // and the message has not been read (is_read = 0)
    $stmt = $pdo->prepare("
        SELECT COUNT(cm.id) AS unread_count
        FROM chat_messages cm
        JOIN users s ON cm.sender_id = s.id
        WHERE cm.receiver_id = :owner_id
        AND cm.is_read = 0
        AND s.role != 'owner'
    ");
    $stmt->bindParam(':owner_id', $owner_id, PDO::PARAM_INT);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode(['status' => 'success', 'unread_count' => (int)$result['unread_count']]);

} catch (PDOException $e) {
    error_log("Database error fetching unread chat count for owner: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Database error.']);
}
?>
