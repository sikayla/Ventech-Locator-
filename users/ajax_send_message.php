<?php
session_start();
require_once __DIR__ . '/db_connection.php'; // Adjust path if db_connection.php is elsewhere

// Check if user is logged in AND is an owner
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'owner') {
    header("Location: user_login.php"); // Redirect if not logged in or not an owner
    exit;
}

$current_owner_id = $_SESSION['user_id'];
$current_owner_username = htmlspecialchars($_SESSION['username']);

$recipient_user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
$recipient_username = 'Select a User';

// If a user_id is provided, fetch their username
if ($recipient_user_id > 0) {
    try {
        $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ? AND role != 'owner'"); // Ensure owners don't chat with themselves via this interface or other owners
        $stmt->execute([$recipient_user_id]);
        $user_data = $stmt->fetch();
        if ($user_data) {
            $recipient_username = htmlspecialchars($user_data['username']);
        } else {
            $recipient_user_id = 0; // Invalid user_id
            $recipient_username = 'User Not Found';
        }
    } catch (PDOException $e) {
        error_log("Error fetching recipient username: " . $e->getMessage());
        $recipient_user_id = 0;
        $recipient_username = 'Error Fetching User';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Owner Chat Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.2.0/css/all.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #1a1a1a; color: #d1d5db; }
        .chat-layout {
            display: flex;
            height: calc(100vh - 40px); /* Adjust for padding */
            max-width: 1000px;
            margin: 20px auto;
            background-color: #2a2a2a;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.5);
        }
        .user-list-panel {
            width: 250px;
            background-color: #3b3b3b;
            border-right: 1px solid #4a4a4a;
            padding: 1rem;
            display: flex;
            flex-direction: column;
        }
        .user-list-panel h2 {
            color: white;
            font-weight: bold;
            font-size: 1.25rem;
            margin-bottom: 1rem;
        }
        .user-list {
            flex-grow: 1;
            overflow-y: auto;
        }
        .user-item {
            display: flex;
            align-items: center;
            padding: 0.75rem;
            margin-bottom: 0.5rem;
            background-color: #4a4a4a;
            border-radius: 6px;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        .user-item:hover {
            background-color: #5a5a5a;
        }
        .user-item.active {
            background-color: #f97316; /* orange-500 */
            color: white;
        }
        .user-item.active:hover {
            background-color: #ea580c; /* orange-600 */
        }
        .user-item .username {
            font-weight: bold;
            margin-left: 0.5rem;
        }
        .chat-main-panel {
            flex-grow: 1;
            display: flex;
            flex-direction: column;
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

        /* Responsive Adjustments */
        @media (max-width: 768px) {
            .chat-layout {
                flex-direction: column;
                height: auto;
                max-width: 100%;
                margin: 10px;
            }
            .user-list-panel {
                width: 100%;
                border-right: none;
                border-bottom: 1px solid #4a4a4a;
                max-height: 200px; /* Limit height of user list on mobile */
            }
            .chat-main-panel {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="chat-layout">
        <div class="user-list-panel">
            <h2>Users</h2>
            <div class="user-list" id="user-list">
                <!-- User list will be loaded here -->
                <div class="message-notification" id="user-list-notification">Loading users...</div>
            </div>
            <a href="user_dashboard.php" class="back-link !text-white !no-underline hover:!text-yellow-400 mt-4">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>

        <div class="chat-main-panel">
            <div class="chat-header">
                <span>Chatting with: <span id="chat-recipient-name"><?= $recipient_username ?></span></span>
            </div>

            <div class="chat-messages" id="chat-messages">
                <!-- Messages will be loaded here -->
                <div class="message-notification" id="chat-notification">
                    <?php if ($recipient_user_id == 0): ?>
                        Please select a user from the left panel to start chatting.
                    <?php else: ?>
                        Loading messages...
                    <?php endif; ?>
                </div>
            </div>

            <div class="chat-input-area">
                <input type="text" id="message-input" placeholder="Type a message..." <?= $recipient_user_id == 0 ? 'disabled' : '' ?>>
                <button id="send-button" <?= $recipient_user_id == 0 ? 'disabled' : '' ?>>
                    <i class="fas fa-paper-plane"></i>
                </button>
            </div>
        </div>
    </div>

    <script>
        const chatMessagesEl = document.getElementById('chat-messages');
        const messageInput = document.getElementById('message-input');
        const sendButton = document.getElementById('send-button');
        const chatNotificationEl = document.getElementById('chat-notification');
        const userListEl = document.getElementById('user-list');
        const userListNotificationEl = document.getElementById('user-list-notification');
        const chatRecipientNameEl = document.getElementById('chat-recipient-name');

        const currentOwnerId = <?= json_encode($current_owner_id) ?>;
        let activeRecipientId = <?= json_encode($recipient_user_id) ?>;

        let lastMessageTimestamp = ''; // To fetch only newer messages
        let messagePollingInterval = null; // To store interval ID for clearing

        // Function to fetch the list of users
        async function fetchUserList() {
            try {
                const response = await fetch('ajax_get_user_list.php');
                const data = await response.json();

                if (data.status === 'success') {
                    userListEl.innerHTML = ''; // Clear existing list
                    if (data.users.length > 0) {
                        data.users.forEach(user => {
                            const userItem = document.createElement('div');
                            userItem.classList.add('user-item', 'flex', 'items-center', 'p-3', 'rounded', 'cursor-pointer', 'transition');
                            if (user.id == activeRecipientId) { // Use == for comparison as activeRecipientId could be number
                                userItem.classList.add('active');
                            }
                            userItem.dataset.userId = user.id;
                            userItem.innerHTML = `
                                <i class="fas fa-user-circle text-xl"></i>
                                <span class="username ml-2">${user.username}</span>
                            `;
                            userItem.addEventListener('click', () => selectUser(user.id, user.username));
                            userListEl.appendChild(userItem);
                        });
                        userListNotificationEl.style.display = 'none'; // Hide notification
                    } else {
                        userListNotificationEl.textContent = 'No users found to chat with.';
                        userListNotificationEl.style.display = 'block';
                    }
                } else {
                    console.error('Error fetching user list:', data.message);
                    userListNotificationEl.textContent = 'Error loading users.';
                    userListNotificationEl.style.display = 'block';
                }
            } catch (error) {
                console.error('Network error fetching user list:', error);
                userListNotificationEl.textContent = 'Network error. Could not load users.';
                userListNotificationEl.style.display = 'block';
            }
        }

        // Function to select a user and load their chat
        function selectUser(userId, username) {
            if (activeRecipientId === userId) return; // Already selected

            activeRecipientId = userId;
            chatRecipientNameEl.textContent = username;

            // Update active state in UI
            document.querySelectorAll('.user-item').forEach(item => {
                item.classList.remove('active');
                if (item.dataset.userId == userId) {
                    item.classList.add('active');
                }
            });

            // Clear previous messages and reset timestamp
            chatMessagesEl.innerHTML = '<div class="message-notification" id="chat-notification">Loading messages...</div>';
            lastMessageTimestamp = '';

            // Enable input and button
            messageInput.disabled = false;
            sendButton.disabled = false;
            messageInput.focus();

            // Clear any existing polling interval and start a new one
            if (messagePollingInterval) {
                clearInterval(messagePollingInterval);
            }
            fetchMessages(); // Initial fetch for new user
            messagePollingInterval = setInterval(fetchMessages, 3000); // Start polling
        }


        // Function to fetch messages
        async function fetchMessages() {
            if (activeRecipientId === 0) return; // Don't fetch if no user selected

            try {
                const response = await fetch(`ajax_get_messages.php?recipient_id=${activeRecipientId}&last_timestamp=${encodeURIComponent(lastMessageTimestamp)}`);
                const data = await response.json();

                if (data.status === 'success') {
                    if (data.messages.length > 0) {
                        // Remove initial "Loading messages..." notification
                        const notificationEl = document.getElementById('chat-notification');
                        if (notificationEl) notificationEl.remove();

                        data.messages.forEach(msg => {
                            addMessageToChat(msg);
                            if (msg.timestamp > lastMessageTimestamp) {
                                lastMessageTimestamp = msg.timestamp;
                            }
                        });
                        chatMessagesEl.scrollTop = chatMessagesEl.scrollHeight; // Scroll to bottom
                    } else if (lastMessageTimestamp === '') {
                        // Only show "No messages yet" if it's the very first fetch for this chat
                        const notificationEl = document.getElementById('chat-notification');
                        if (notificationEl) {
                            notificationEl.textContent = 'No messages yet. Start the conversation!';
                            notificationEl.style.display = 'block';
                        } else {
                            const newNotification = document.createElement('div');
                            newNotification.classList.add('message-notification');
                            newNotification.id = 'chat-notification';
                            newNotification.textContent = 'No messages yet. Start the conversation!';
                            chatMessagesEl.appendChild(newNotification);
                        }
                    }
                } else {
                    console.error('Error fetching messages:', data.message);
                    chatMessagesEl.innerHTML = `<div class="message-notification text-red-400">Error loading messages: ${data.message}</div>`;
                }
            } catch (error) {
                console.error('Network error fetching messages:', error);
                chatMessagesEl.innerHTML = `<div class="message-notification text-red-400">Network error. Could not load messages.</div>`;
            }
        }

        // Function to add a single message to the chat display
        function addMessageToChat(msg) {
            const messageDiv = document.createElement('div');
            // Sender is the current owner if msg.sender_id matches currentOwnerId
            const isSent = (msg.sender_id == currentOwnerId);
            messageDiv.classList.add('message-bubble', isSent ? 'sent' : 'received');
            messageDiv.textContent = msg.message_text;

            const timestampDiv = document.createElement('div');
            timestampDiv.classList.add('message-timestamp');
            const date = new Date(msg.timestamp + 'Z'); // 'Z' assumes UTC if your server sends UTC timestamps
            timestampDiv.textContent = date.toLocaleString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true, month: 'short', day: 'numeric' });

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
            if (messageText === '' || activeRecipientId === 0) return;

            // Optimistically add message to UI
            addMessageToChat({
                sender_id: currentOwnerId,
                receiver_id: activeRecipientId,
                message_text: messageText,
                timestamp: new Date().toISOString().slice(0, 19).replace('T', ' ')
            });
            chatMessagesEl.scrollTop = chatMessagesEl.scrollHeight;

            messageInput.value = '';
            sendButton.disabled = true;

            const formData = new FormData();
            formData.append('recipient_id', activeRecipientId);
            formData.append('message_text', messageText);

            try {
                const response = await fetch('ajax_send_message.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();

                if (data.status === 'success') {
                    // Message sent, polling will pick it up, or you can update the last sent message with actual server timestamp if needed
                } else {
                    console.error('Error sending message:', data.message);
                    // Handle error
                }
            } catch (error) {
                console.error('Network error sending message:', error);
                // Handle network error
            } finally {
                sendButton.disabled = false;
                messageInput.focus();
            }
        }

        // Event listeners
        sendButton.addEventListener('click', sendMessage);
        messageInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                sendMessage();
            }
        });

        // Initial load of user list
        fetchUserList();

        // If a recipient is already selected via GET (e.g., from a dashboard link), load their chat
        if (activeRecipientId !== 0) {
             // Simulate selecting the user
            selectUser(activeRecipientId, chatRecipientNameEl.textContent);
        }
    </script>
</body>
</html>
