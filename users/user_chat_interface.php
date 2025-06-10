<?php
session_start();
// Include the database connection
require_once __DIR__ . '/db_connection.php'; // Adjust path if db_connection.php is elsewhere

// Check if user is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] === 'owner') {
    header("Location: user_login.php"); // Redirect unauthenticated or owner users
    exit;
}

$current_user_id = $_SESSION['user_id'];
$current_username = htmlspecialchars($_SESSION['username']);

// Define the owner's ID for this chat. You might get this from a config or database.
// For simplicity, let's assume owner is user with ID 1.
$owner_id = 1; // IMPORTANT: Replace with the actual ID of your owner user
$owner_username = 'Ventech Support'; // Default name, fetch from DB if needed

try {
    $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
    $stmt->execute([$owner_id]);
    $owner_data = $stmt->fetch();
    if ($owner_data) {
        $owner_username = htmlspecialchars($owner_data['username']);
    }
} catch (PDOException $e) {
    error_log("Error fetching owner username: " . $e->getMessage());
    // Use default owner_username
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat with Support</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.2.0/css/all.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #1a1a1a; color: #d1d5db; }
        .chat-container {
            display: flex;
            flex-direction: column;
            height: calc(100vh - 40px); /* Adjust for padding */
            max-width: 600px;
            margin: 20px auto;
            background-color: #2a2a2a;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.5);
        }
        .chat-header {
            background-color: #00303f;
            padding: 1rem;
            color: white;
            font-weight: bold;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .chat-messages {
            flex-grow: 1;
            padding: 1rem;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            background-color: #1a1a1a;
        }
        .message-bubble {
            max-width: 80%;
            padding: 0.75rem 1rem;
            border-radius: 1.5rem;
            word-wrap: break-word;
            font-size: 0.9rem;
        }
        .message-bubble.sent {
            background-color: #4a4a4a;
            color: #e2e8f0;
            align-self: flex-end;
            border-bottom-right-radius: 0.5rem;
        }
        .message-bubble.received {
            background-color: #00303f;
            color: #e2e8f0;
            align-self: flex-start;
            border-bottom-left-radius: 0.5rem;
        }
        .message-timestamp {
            font-size: 0.7rem;
            color: #9ca3af;
            margin-top: 0.2rem;
            text-align: right;
        }
        .message-bubble.received + .message-timestamp {
            text-align: left;
        }
        .chat-input-area {
            display: flex;
            padding: 1rem;
            background-color: #2a2a2a;
            border-top: 1px solid #3b3b3b;
        }
        .chat-input-area input {
            flex-grow: 1;
            background-color: #3b3b3b;
            border: 1px solid #4a4a4a;
            border-radius: 2rem;
            padding: 0.75rem 1.25rem;
            color: #e2e8f0;
            outline: none;
            font-size: 0.9rem;
        }
        .chat-input-area input:focus {
            border-color: #f97316; /* orange-500 */
        }
        .chat-input-area button {
            background-color: #f97316; /* orange-500 */
            color: white;
            border-radius: 2rem;
            padding: 0.75rem 1rem;
            margin-left: 0.75rem;
            border: none;
            cursor: pointer;
            transition: background-color 0.2s;
            font-size: 1rem;
        }
        .chat-input-area button:hover {
            background-color: #ea580c; /* orange-600 */
        }
        .back-link {
            display: flex;
            align-items: center;
            color: #d1d5db;
            text-decoration: none;
            font-size: 0.8rem;
            margin-bottom: 1rem;
            gap: 0.5rem;
        }
        .back-link:hover {
            color: #f97316;
        }
        .message-notification {
            font-size: 0.8rem;
            color: #facc15; /* yellow-400 */
            text-align: center;
            padding: 0.5rem;
        }
    </style>
</head>
<body>
    <div class="chat-container">
        <div class="chat-header">
            <a href="user_dashboard.php" class="back-link !text-white !no-underline hover:!text-yellow-400">
                <i class="fas fa-arrow-left"></i> Back
            </a>
            <span>Chat with <?= $owner_username ?></span>
            <div></div> <!-- Placeholder for spacing -->
        </div>

        <div class="chat-messages" id="chat-messages">
            <!-- Messages will be loaded here -->
            <div class="message-notification" id="chat-notification"></div>
        </div>

        <div class="chat-input-area">
            <input type="text" id="message-input" placeholder="Type a message...">
            <button id="send-button">
                <i class="fas fa-paper-plane"></i>
            </button>
        </div>
    </div>

    <script>
        const chatMessagesEl = document.getElementById('chat-messages');
        const messageInput = document.getElementById('message-input');
        const sendButton = document.getElementById('send-button');
        const chatNotificationEl = document.getElementById('chat-notification');

        const currentUserId = <?= json_encode($current_user_id) ?>;
        const recipientId = <?= json_encode($owner_id) ?>; // Owner's ID

        let lastMessageTimestamp = ''; // To fetch only newer messages

        // Function to fetch messages
        async function fetchMessages() {
            try {
                const response = await fetch('ajax_get_messages.php?recipient_id=' + recipientId + '&last_timestamp=' + encodeURIComponent(lastMessageTimestamp));
                const data = await response.json();

                if (data.status === 'success') {
                    if (data.messages.length > 0) {
                        data.messages.forEach(msg => {
                            addMessageToChat(msg);
                            // Update last timestamp to ensure we only get new messages next time
                            if (msg.timestamp > lastMessageTimestamp) {
                                lastMessageTimestamp = msg.timestamp;
                            }
                        });
                        chatMessagesEl.scrollTop = chatMessagesEl.scrollHeight; // Scroll to bottom
                        chatNotificationEl.style.display = 'none'; // Hide notification if messages loaded
                    } else if (lastMessageTimestamp === '') {
                        // Only show this if it's the very first fetch and no messages exist
                        chatNotificationEl.textContent = 'No messages yet. Start the conversation!';
                        chatNotificationEl.style.display = 'block';
                    }
                } else {
                    console.error('Error fetching messages:', data.message);
                    chatNotificationEl.textContent = 'Error loading messages.';
                    chatNotificationEl.style.display = 'block';
                }
            } catch (error) {
                console.error('Network error fetching messages:', error);
                chatNotificationEl.textContent = 'Network error. Could not load messages.';
                chatNotificationEl.style.display = 'block';
            }
        }

        // Function to add a single message to the chat display
        function addMessageToChat(msg) {
            const messageDiv = document.createElement('div');
            const isSent = (msg.sender_id == currentUserId); // Compare with number, not string
            messageDiv.classList.add('message-bubble', isSent ? 'sent' : 'received');
            messageDiv.textContent = msg.message_text;

            const timestampDiv = document.createElement('div');
            timestampDiv.classList.add('message-timestamp');
            // Format timestamp for display
            const date = new Date(msg.timestamp + 'Z'); // 'Z' assumes UTC if your server sends UTC timestamps
            timestampDiv.textContent = date.toLocaleString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true, month: 'short', day: 'numeric' });

            // Append messages and timestamps correctly
            const wrapperDiv = document.createElement('div');
            wrapperDiv.style.display = 'flex';
            wrapperDiv.style.flexDirection = 'column';
            wrapperDiv.style.alignItems = isSent ? 'flex-end' : 'flex-start';

            wrapperDiv.appendChild(messageDiv);
            wrapperDiv.appendChild(timestampDiv);
            chatMessagesEl.appendChild(wrapperDiv);
        }

        // Function to send a message
        async function sendMessage() {
            const messageText = messageInput.value.trim();
            if (messageText === '') return;

            // Optimistically add message to UI
            addMessageToChat({
                sender_id: currentUserId,
                receiver_id: recipientId,
                message_text: messageText,
                timestamp: new Date().toISOString().slice(0, 19).replace('T', ' ') // Current local time for immediate display
            });
            chatMessagesEl.scrollTop = chatMessagesEl.scrollHeight; // Scroll to bottom

            messageInput.value = ''; // Clear input immediately
            sendButton.disabled = true; // Disable to prevent double-send

            const formData = new FormData();
            formData.append('recipient_id', recipientId);
            formData.append('message_text', messageText);

            try {
                const response = await fetch('ajax_send_message.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();

                if (data.status === 'success') {
                    // Message sent successfully, no need to re-fetch all, polling will pick it up
                    // Or, if you want immediate server-confirmed timestamp, you can update the last sent message.
                } else {
                    console.error('Error sending message:', data.message);
                    // Handle error: perhaps remove the optimistically added message or show an error
                }
            } catch (error) {
                console.error('Network error sending message:', error);
                // Handle network error
            } finally {
                sendButton.disabled = false; // Re-enable send button
                messageInput.focus(); // Keep focus on input
            }
        }

        // Event listeners
        sendButton.addEventListener('click', sendMessage);
        messageInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                sendMessage();
            }
        });

        // Initial fetch and then poll for new messages
        fetchMessages();
        setInterval(fetchMessages, 3000); // Poll every 3 seconds for new messages
    </script>
</body>
</html>