<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/db_connection.php'; // Adjust path

// Ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated.']);
    exit;
}

$current_user_id = $_SESSION['user_id'];
$recipient_id = isset($_GET['recipient_id']) ? intval($_GET['recipient_id']) : 0;
$last_timestamp = isset($_GET['last_timestamp']) ? trim($_GET['last_timestamp']) : '';

if ($recipient_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid recipient ID.']);
    exit;
}

try {
    // Fetch messages where:
    // (sender is current_user AND receiver is recipient_id) OR
    // (sender is recipient_id AND receiver is current_user)
    // Ordered by timestamp ascending
    $sql = "SELECT id, sender_id, receiver_id, message_text, timestamp, is_read
            FROM chat_messages
            WHERE ((sender_id = :current_user_id AND receiver_id = :recipient_id)
            OR (sender_id = :recipient_id AND receiver_id = :current_user_id))";

    // Add timestamp filter for polling
    if (!empty($last_timestamp)) {
        // Ensure timestamp format matches database, typically YYYY-MM-DD HH:MM:SS
        // Add a small buffer to avoid missing messages due to timing discrepancies
        $sql .= " AND timestamp > :last_timestamp";
    }

    $sql .= " ORDER BY timestamp ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':current_user_id', $current_user_id, PDO::PARAM_INT);
    $stmt->bindParam(':recipient_id', $recipient_id, PDO::PARAM_INT);
    if (!empty($last_timestamp)) {
        $stmt->bindParam(':last_timestamp', $last_timestamp, PDO::PARAM_STR);
    }
    $stmt->execute();
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['status' => 'success', 'messages' => $messages]);

} catch (PDOException $e) {
    error_log("Error fetching chat messages: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Database error.']);
}
?>
