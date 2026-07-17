<?php
include 'core/init.php';

$user_id = $_SESSION['user_id'];
$user = User::getData($user_id);

if (User::checkLogIn() === false) {
    header('location: index.php');
    exit;
}

$conn = Connect::connect();

// Update last seen
$stmt = $conn->prepare("UPDATE users SET last_seen = NOW() WHERE id = ?");
$stmt->execute([$user_id]);

// Realtime presence endpoints: keeps current user online and checks selected chat user's status
if (isset($_POST['presence_action']) && $_POST['presence_action'] === 'heartbeat') {
    header('Content-Type: application/json');
    $hb = $conn->prepare("UPDATE users SET last_seen = NOW() WHERE id = ?");
    $hb->execute([$user_id]);
    echo json_encode(['ok' => true]);
    exit;
}

if (isset($_GET['presence_action']) && $_GET['presence_action'] === 'check' && isset($_GET['user_id'])) {
    header('Content-Type: application/json');
    $checkUserId = (int)$_GET['user_id'];

    $stmt = $conn->prepare("SELECT last_seen, TIMESTAMPDIFF(SECOND, last_seen, NOW()) AS seconds_ago FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$checkUserId]);
    $presenceUser = $stmt->fetch(PDO::FETCH_OBJ);

    if (!$presenceUser || !$presenceUser->last_seen || $presenceUser->last_seen == '0000-00-00 00:00:00') {
        echo json_encode(['online' => false, 'text' => 'last seen never']);
        exit;
    }

    $secondsAgo = (int)$presenceUser->seconds_ago;
    $isOnlineNow = $secondsAgo >= 0 && $secondsAgo <= 45;

    echo json_encode([
        'online' => $isOnlineNow,
        'text' => $isOnlineNow ? '● Online' : 'last seen ' . formatLastSeen($presenceUser->last_seen),
        'seconds_ago' => $secondsAgo
    ]);
    exit;
}

// Create uploads directory if not exists
$uploadDir = 'uploads/messages/';
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}



// ========== GET FOLLOWING AND FOLLOWERS ==========
$followingIds = [];
$followersIds = [];

try {
    $checkTable = $conn->query("SHOW TABLES LIKE 'follow'");
    if ($checkTable->rowCount() > 0) {
        $followingQuery = $conn->prepare("SELECT following_id FROM follow WHERE follower_id = ?");
        $followingQuery->execute([$user_id]);
        $followingIds = $followingQuery->fetchAll(PDO::FETCH_COLUMN);
        
        $followersQuery = $conn->prepare("SELECT follower_id FROM follow WHERE following_id = ?");
        $followersQuery->execute([$user_id]);
        $followersIds = $followersQuery->fetchAll(PDO::FETCH_COLUMN);
    }
} catch (PDOException $e) {
    $followingIds = [];
    $followersIds = [];
}

// Get filter type
$filterType = isset($_GET['filter']) ? $_GET['filter'] : (isset($_POST['filter']) ? $_POST['filter'] : 'following');
$searchFilterType = $filterType;

// Handle remove from recent chats
if (isset($_POST['remove_chat']) && isset($_POST['chat_user_id'])) {
    $chatUserId = (int)$_POST['chat_user_id'];
    $stmt = $conn->prepare("INSERT INTO hidden_chats (user_id, chat_with_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE hidden_at = CURRENT_TIMESTAMP");
    $stmt->execute([$user_id, $chatUserId]);
    echo 'removed';
    exit;
}

// Handle clear chat
if (isset($_POST['clear_chat']) && isset($_POST['chat_with_id'])) {
    $chatWithId = (int)$_POST['chat_with_id'];
    $stmt = $conn->prepare("INSERT INTO cleared_chats (user_id, chat_with_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE cleared_at = CURRENT_TIMESTAMP");
    $stmt->execute([$user_id, $chatWithId]);
    echo 'cleared';
    exit;
}

// Handle delete for myself (soft delete - only hides for current user)
if (isset($_POST['delete_for_me']) && isset($_POST['msg_id'])) {
    $msgId = (int)$_POST['msg_id'];
    
    // Get current deleted_for_me list
    $stmt = $conn->prepare("SELECT deleted_for_me FROM messages WHERE id = ?");
    $stmt->execute([$msgId]);
    $msg = $stmt->fetch(PDO::FETCH_OBJ);
    
    $deletedForMe = $msg->deleted_for_me ? json_decode($msg->deleted_for_me, true) : [];
    if (!in_array($user_id, $deletedForMe)) {
        $deletedForMe[] = $user_id;
    }
    
    $stmt = $conn->prepare("UPDATE messages SET deleted_for_me = ? WHERE id = ?");
    $stmt->execute([json_encode($deletedForMe), $msgId]);
    echo 'deleted_for_me';
    exit;
}

// Handle delete for everyone
if (isset($_POST['delete_for_everyone']) && isset($_POST['msg_id'])) {
    $msgId = (int)$_POST['msg_id'];
    $check = $conn->prepare("SELECT id, sender_id, file_path FROM messages WHERE id = ? AND sender_id = ?");
    $check->execute([$msgId, $user_id]);
    if ($check->rowCount() > 0) {
        $msg = $check->fetch(PDO::FETCH_OBJ);
        $paths = json_decode($msg->file_path, true);
        if ($paths && is_array($paths)) {
            foreach ($paths as $path) {
                if ($path && file_exists($path)) {
                    unlink($path);
                }
            }
        } elseif ($msg->file_path && file_exists($msg->file_path)) {
            unlink($msg->file_path);
        }
        $del = $conn->prepare("UPDATE messages SET deleted_for_everyone = 1, message = 'This message was deleted' WHERE id = ?");
        $del->execute([$msgId]);
        echo 'deleted_for_everyone';
        exit;
    }
    exit('not_authorized');
}

// --- Handle Toggle Block User ---
if (isset($_POST['toggle_block']) && isset($_POST['blocked_id'])) {
    $blockedId = (int)$_POST['blocked_id'];
    
    // Check if already blocked
    $check = $conn->prepare("SELECT id FROM blocks WHERE blocker_id = ? AND blocked_id = ?");
    $check->execute([$user_id, $blockedId]);
    
    if ($check->rowCount() > 0) {
        // Unblock
        $stmt = $conn->prepare("DELETE FROM blocks WHERE blocker_id = ? AND blocked_id = ?");
        $stmt->execute([$user_id, $blockedId]);
        echo 'unblocked';
    } else {
        // Block
        $stmt = $conn->prepare("INSERT INTO blocks (blocker_id, blocked_id) VALUES (?, ?)");
        $stmt->execute([$user_id, $blockedId]);
        echo 'blocked';
    }
    exit;
}

// Get users with conversations (for forward modal)
if (isset($_GET['get_conversation_users'])) {
    header('Content-Type: application/json');
    
    $stmt = $conn->prepare("
        SELECT DISTINCT u.id, u.username, u.name, u.img, u.is_verified,
               m.created_at as last_message_time
        FROM messages m
        JOIN users u ON (u.id = m.sender_id OR u.id = m.receiver_id)
        WHERE (m.sender_id = ? OR m.receiver_id = ?)
        AND u.id != ?
        GROUP BY u.id
        ORDER BY MAX(m.created_at) DESC
    ");
    $stmt->execute([$user_id, $user_id, $user_id]);
    $users = $stmt->fetchAll(PDO::FETCH_OBJ);
    
    echo json_encode($users);
    exit;
}

// Handle forward message - FIXED for mixed media (images + videos + documents)
if (isset($_POST['forward_message']) && isset($_POST['msg_id']) && isset($_POST['recipients'])) {
    $originalMsgId = (int)$_POST['msg_id'];
    $recipients = json_decode($_POST['recipients'], true);

    if (!is_array($recipients) || empty($recipients)) {
        exit('no_recipients');
    }

    // Get original message
    $stmt = $conn->prepare("SELECT message, file_path, file_type, file_name, voice_duration, voice_waveform FROM messages WHERE id = ?");
    $stmt->execute([$originalMsgId]);
    $originalMsg = $stmt->fetch(PDO::FETCH_OBJ);

    if ($originalMsg) {
        // Normalize old/new database formats into arrays.
        // New messages store JSON arrays. Old messages may have a single string path/type/name.
        $oldPaths = json_decode($originalMsg->file_path, true);
        if (!is_array($oldPaths)) {
            $oldPaths = !empty($originalMsg->file_path) ? [$originalMsg->file_path] : [];
        }

        $oldTypes = json_decode($originalMsg->file_type, true);
        if (!is_array($oldTypes)) {
            $oldTypes = !empty($originalMsg->file_type) ? [$originalMsg->file_type] : [];
        }

        $oldNames = json_decode($originalMsg->file_name, true);
        if (!is_array($oldNames)) {
            $oldNames = !empty($originalMsg->file_name) ? [$originalMsg->file_name] : [];
        }

        foreach ($recipients as $recipientId) {
            $recipientId = (int)$recipientId;
            if ($recipientId <= 0) {
                continue;
            }

            $newMessage = "[Forwarded] " . $originalMsg->message;
            $newPaths = [];
            $newTypes = [];
            $newNames = [];

            // Copy every file and keep file_path, file_type, file_name indexes matched.
            foreach ($oldPaths as $i => $oldPath) {
                if ($oldPath && file_exists($oldPath)) {
                    $extension = pathinfo($oldPath, PATHINFO_EXTENSION);
                    $newFilename = time() . '_' . uniqid('', true) . ($extension ? '.' . $extension : '');
                    $newFilePath = $uploadDir . $newFilename;

                    if (copy($oldPath, $newFilePath)) {
                        $newPaths[] = $newFilePath;
                        $newTypes[] = $oldTypes[$i] ?? 'document';
                        $newNames[] = $oldNames[$i] ?? basename($oldPath);
                    }
                }
            }

            $stmt = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, message, file_path, file_type, file_name, is_deleted, reply_to, voice_duration, voice_waveform) VALUES (?, ?, ?, ?, ?, ?, 0, NULL, ?, ?)");
            $stmt->execute([
                $user_id,
                $recipientId,
                $newMessage,
                json_encode($newPaths),
                json_encode($newTypes),
                json_encode($newNames),
                $originalMsg->voice_duration ?? null,
                $originalMsg->voice_waveform ?? null
            ]);

            // Remove from hidden_chats for this recipient
            $stmt = $conn->prepare("DELETE FROM hidden_chats WHERE user_id = ? AND chat_with_id = ?");
            $stmt->execute([$recipientId, $user_id]);
        }
        echo 'forwarded';
        exit;
    }
    exit('error');
}

// AJAX endpoint for live search
if (isset($_GET['ajax_search']) && isset($_GET['query'])) {
    header('Content-Type: application/json');
    $query = trim($_GET['query']);
    $filter = isset($_GET['filter']) ? $_GET['filter'] : 'following';
    $results = [];
    
    if (!empty($query)) {
        $search = "%" . $query . "%";
        
        if ($filter == 'following' && !empty($followingIds)) {
            $placeholders = implode(',', array_fill(0, count($followingIds), '?'));
            $stmt = $conn->prepare("SELECT id, username, name, img, is_verified FROM users WHERE (username LIKE ? OR name LIKE ?) AND id != ? AND id IN ($placeholders) LIMIT 10");
            $params = array_merge([$search, $search, $user_id], $followingIds);
            $stmt->execute($params);
            $results = $stmt->fetchAll(PDO::FETCH_OBJ);
        } elseif ($filter == 'followers' && !empty($followersIds)) {
            $placeholders = implode(',', array_fill(0, count($followersIds), '?'));
            $stmt = $conn->prepare("SELECT id, username, name, img, is_verified FROM users WHERE (username LIKE ? OR name LIKE ?) AND id != ? AND id IN ($placeholders) LIMIT 10");
            $params = array_merge([$search, $search, $user_id], $followersIds);
            $stmt->execute($params);
            $results = $stmt->fetchAll(PDO::FETCH_OBJ);
        }
    }
    
    echo json_encode($results);
    exit;
}

// UNIFIED HANDLE SEND MESSAGE (Multiple Media + Text + Reply) - WITH BLOCK CHECK
if (isset($_POST['send']) && isset($_GET['user'])) {
    $receiver = (int)$_GET['user'];
    $message = trim($_POST['message']);
    $replyTo = !empty($_POST['reply_to_id']) ? (int)$_POST['reply_to_id'] : null;
    $voiceDuration = isset($_POST['voice_duration']) && $_POST['voice_duration'] !== '' ? (int)$_POST['voice_duration'] : null;
    $voiceWaveform = !empty($_POST['voice_waveform']) ? $_POST['voice_waveform'] : null;
    $files = $_FILES['file_attachment'] ?? null;
    
    // START BLOCK CHECK - Check BOTH directions
    $checkBlock = $conn->prepare("SELECT id FROM blocks WHERE (blocker_id = ? AND blocked_id = ?) OR (blocker_id = ? AND blocked_id = ?)");
    $checkBlock->execute([$user_id, $receiver, $receiver, $user_id]);
    if ($checkBlock->rowCount() > 0) {
        header("location: messages.php?user=" . $receiver . "&filter=" . $filterType . "&error=blocked");
        exit;
    }
    // END BLOCK CHECK
    
    $allPaths = [];
    $allTypes = [];
    $allNames = [];

    if ($files && isset($files['name']) && is_array($files['name']) && !empty($files['name'][0])) {
        $allowedTypes = [
            'photo' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
            'video' => ['video/mp4', 'video/webm', 'video/ogg', 'video/quicktime'],
            'document' => ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 
                           'text/plain', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                           'application/zip', 'application/x-rar-compressed'],
            'voice' => ['audio/webm', 'audio/ogg', 'audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/x-wav', 'audio/mp4', 'audio/m4a', 'video/webm', 'application/octet-stream']
        ];
        
        $maxSizes = [
            'photo' => 10 * 1024 * 1024,
            'video' => 50 * 1024 * 1024,
            'document' => 20 * 1024 * 1024,
            'voice' => 15 * 1024 * 1024
        ];

        foreach ($files['name'] as $key => $val) {
            if ($files['error'][$key] === UPLOAD_ERR_OK) {
                $fileMime = mime_content_type($files['tmp_name'][$key]);
                $actualType = 'document';
                foreach ($allowedTypes as $type => $mimes) {
                    if (in_array($fileMime, $mimes)) { 
                        $actualType = $type; 
                        break; 
                    }
                }
                if (strpos($files['name'][$key], 'voice_message_') === 0) {
                    $actualType = 'voice';
                }
                
                if ($files['size'][$key] <= $maxSizes[$actualType]) {
                    $extension = pathinfo($files['name'][$key], PATHINFO_EXTENSION);
                    $filename = time() . '_' . uniqid() . '.' . $extension;
                    $filepath = $uploadDir . $filename;
                    
                    if (move_uploaded_file($files['tmp_name'][$key], $filepath)) {
                        $allPaths[] = $filepath;
                        $allTypes[] = $actualType;
                        $allNames[] = $files['name'][$key];
                    }
                }
            }
        }
    }

    if (!empty($message) || !empty($allPaths)) {
        // Encode arrays to JSON for the database
        $dbPath = json_encode($allPaths);
        $dbType = json_encode($allTypes);
        $dbName = json_encode($allNames);

        $stmt = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, message, file_path, file_type, file_name, is_deleted, reply_to, voice_duration, voice_waveform) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?)");
        $stmt->execute([$user_id, $receiver, $message, $dbPath, $dbType, $dbName, $replyTo, $voiceDuration, $voiceWaveform]);
        
        $stmt = $conn->prepare("DELETE FROM hidden_chats WHERE user_id = ? AND chat_with_id = ?");
        $stmt->execute([$user_id, $receiver]);
    }
    
    header("location: messages.php?user=" . $receiver . "&filter=" . $filterType);
    exit;
}

// Handle PIN message
if (isset($_POST['pin_msg']) && isset($_POST['msg_id'])) {
    $msgId = (int)$_POST['msg_id'];
    $check = $conn->prepare("SELECT id, sender_id, receiver_id, deleted_for_everyone FROM messages WHERE id = ?");
    $check->execute([$msgId]);
    $msg = $check->fetch(PDO::FETCH_OBJ);
    if ($msg && ($msg->sender_id == $user_id || $msg->receiver_id == $user_id) && $msg->deleted_for_everyone == 0) {
        try {
            $checkColumn = $conn->query("SHOW COLUMNS FROM messages LIKE 'is_pinned'");
            if ($checkColumn->rowCount() == 0) {
                $conn->exec("ALTER TABLE messages ADD COLUMN is_pinned TINYINT(1) DEFAULT 0");
            }
        } catch (PDOException $e) {}
        
        $currentPin = $conn->prepare("SELECT is_pinned FROM messages WHERE id = ?");
        $currentPin->execute([$msgId]);
        $pinStatus = $currentPin->fetchColumn();
        $newStatus = $pinStatus ? 0 : 1;
        $updatePin = $conn->prepare("UPDATE messages SET is_pinned = ? WHERE id = ?");
        $updatePin->execute([$newStatus, $msgId]);
        echo $newStatus;
        exit;
    }
    exit('0');
}

// Load chat
$receiverData = null;
$chatMessages = [];
$pinnedMessages = [];

// Check block status
$iBlockedHim = false;
$heBlockedMe = false;

if (isset($_GET['user']) && !empty($_GET['user'])) {
    $receiver = (int)$_GET['user'];
    
    // Check if I blocked him
    $checkMe = $conn->prepare("SELECT id FROM blocks WHERE blocker_id = ? AND blocked_id = ?");
    $checkMe->execute([$user_id, $receiver]);
    $iBlockedHim = $checkMe->rowCount() > 0;

    // Check if he blocked me
    $checkHim = $conn->prepare("SELECT id FROM blocks WHERE blocker_id = ? AND blocked_id = ?");
    $checkHim->execute([$receiver, $user_id]);
    $heBlockedMe = $checkHim->rowCount() > 0;
    
    // Get user data regardless of block status (so we can show header)
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$receiver]);
    $receiverData = $stmt->fetch(PDO::FETCH_OBJ);
    
    // Only load messages if not blocked by either side
    if (!$iBlockedHim && !$heBlockedMe) {
        $stmt = $conn->prepare("UPDATE messages SET is_read = 1 WHERE sender_id = ? AND receiver_id = ? AND is_read = 0");
        $stmt->execute([$receiver, $user_id]);
        
        $stmt = $conn->prepare("DELETE FROM hidden_chats WHERE user_id = ? AND chat_with_id = ?");
        $stmt->execute([$user_id, $receiver]);
        
        try {
            $checkColumn = $conn->query("SHOW COLUMNS FROM messages LIKE 'is_deleted'");
            if ($checkColumn->rowCount() == 0) {
                $conn->exec("ALTER TABLE messages ADD COLUMN is_deleted TINYINT(1) DEFAULT 0");
            }
        } catch (PDOException $e) {}
        
        // Check if chat is cleared for this user
        $clearedAt = null;
        $stmt = $conn->prepare("SELECT cleared_at FROM cleared_chats WHERE user_id = ? AND chat_with_id = ?");
        $stmt->execute([$user_id, $receiver]);
        $cleared = $stmt->fetch(PDO::FETCH_OBJ);
        if ($cleared) {
            $clearedAt = $cleared->cleared_at;
        }
        
        // Fetch messages with replies joined
        if ($clearedAt) {
            $stmt = $conn->prepare("
                SELECT m.*, 
                       rm.message as reply_msg, 
                       ru.username as reply_user,
                       ru.id as reply_sender_id
                FROM messages m
                LEFT JOIN messages rm ON m.reply_to = rm.id
                LEFT JOIN users ru ON rm.sender_id = ru.id
                WHERE ((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?))
                AND m.created_at > ?
                ORDER BY m.created_at ASC
            ");
            $stmt->execute([$user_id, $receiver, $receiver, $user_id, $clearedAt]);
        } else {
            $stmt = $conn->prepare("
                SELECT m.*, 
                       rm.message as reply_msg, 
                       ru.username as reply_user,
                       ru.id as reply_sender_id
                FROM messages m
                LEFT JOIN messages rm ON m.reply_to = rm.id
                LEFT JOIN users ru ON rm.sender_id = ru.id
                WHERE (m.sender_id = ? AND m.receiver_id = ?) 
                   OR (m.sender_id = ? AND m.receiver_id = ?)
                ORDER BY m.created_at ASC
            ");
            $stmt->execute([$user_id, $receiver, $receiver, $user_id]);
        }
        $allMessages = $stmt->fetchAll(PDO::FETCH_OBJ);
        
        // Filter messages based on delete_for_me
        foreach ($allMessages as $msg) {
            $deletedForMe = $msg->deleted_for_me ? json_decode($msg->deleted_for_me, true) : [];
            if (!in_array($user_id, $deletedForMe) && $msg->deleted_for_everyone == 0) {
                $chatMessages[] = $msg;
            } elseif ($msg->deleted_for_everyone == 1) {
                $msg->message = "This message was deleted";
                $chatMessages[] = $msg;
            }
        }
        
        $stmt = $conn->prepare("
            SELECT * FROM messages 
            WHERE ((sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?))
            AND is_pinned = 1 AND deleted_for_everyone = 0
            ORDER BY created_at DESC
        ");
        $stmt->execute([$user_id, $receiver, $receiver, $user_id]);
        $pinnedMessages = $stmt->fetchAll(PDO::FETCH_OBJ);
    }
}

// Load recent chats - REMOVED b.id IS NULL so blocked users appear in sidebar
if ($filterType == 'following') {
    $followingPlaceholders = !empty($followingIds) ? implode(',', array_fill(0, count($followingIds), '?')) : '0';
    $stmt = $conn->prepare("
        SELECT u.*, 
               m.message, 
               m.created_at,
               m.is_deleted,
               m.sender_id as last_sender_id,
               m.file_type,
               m.file_name,
               m.deleted_for_everyone,
               (
                 SELECT COUNT(*) FROM messages 
                 WHERE sender_id = u.id 
                   AND receiver_id = ? 
                   AND is_read = 0
               ) AS unread_count
        FROM messages m
        JOIN users u 
          ON (CASE WHEN m.sender_id = ? THEN m.receiver_id = u.id 
                   WHEN m.receiver_id = ? THEN m.sender_id = u.id END)
        LEFT JOIN cleared_chats cc ON cc.user_id = ? AND cc.chat_with_id = u.id
        WHERE m.id IN (
            SELECT MAX(m2.id) 
            FROM messages m2
            LEFT JOIN cleared_chats cc2 ON cc2.user_id = ? AND cc2.chat_with_id = (CASE WHEN m2.sender_id = ? THEN m2.receiver_id ELSE m2.sender_id END)
            WHERE (m2.sender_id = ? OR m2.receiver_id = ?)
            AND (cc2.cleared_at IS NULL OR m2.created_at > cc2.cleared_at)
            AND NOT EXISTS (
                SELECT 1 FROM hidden_chats hc 
                WHERE hc.user_id = ? 
                AND hc.chat_with_id = (CASE WHEN m2.sender_id = ? THEN m2.receiver_id ELSE m2.sender_id END)
            )
            GROUP BY LEAST(m2.sender_id, m2.receiver_id), GREATEST(m2.sender_id, m2.receiver_id)
        )
        AND u.id IN ($followingPlaceholders)
        ORDER BY m.created_at DESC
    ");
    $params = array_merge([$user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id], $followingIds);
    $stmt->execute($params);
    $recentChats = $stmt->fetchAll(PDO::FETCH_OBJ);
} elseif ($filterType == 'followers') {
    $followersPlaceholders = !empty($followersIds) ? implode(',', array_fill(0, count($followersIds), '?')) : '0';
    $stmt = $conn->prepare("
        SELECT u.*, 
               m.message, 
               m.created_at,
               m.is_deleted,
               m.sender_id as last_sender_id,
               m.file_type,
               m.file_name,
               m.deleted_for_everyone,
               (
                 SELECT COUNT(*) FROM messages 
                 WHERE sender_id = u.id 
                   AND receiver_id = ? 
                   AND is_read = 0
               ) AS unread_count
        FROM messages m
        JOIN users u 
          ON (CASE WHEN m.sender_id = ? THEN m.receiver_id = u.id 
                   WHEN m.receiver_id = ? THEN m.sender_id = u.id END)
        LEFT JOIN cleared_chats cc ON cc.user_id = ? AND cc.chat_with_id = u.id
        WHERE m.id IN (
            SELECT MAX(m2.id) 
            FROM messages m2
            LEFT JOIN cleared_chats cc2 ON cc2.user_id = ? AND cc2.chat_with_id = (CASE WHEN m2.sender_id = ? THEN m2.receiver_id ELSE m2.sender_id END)
            WHERE (m2.sender_id = ? OR m2.receiver_id = ?)
            AND (cc2.cleared_at IS NULL OR m2.created_at > cc2.cleared_at)
            AND NOT EXISTS (
                SELECT 1 FROM hidden_chats hc 
                WHERE hc.user_id = ? 
                AND hc.chat_with_id = (CASE WHEN m2.sender_id = ? THEN m2.receiver_id ELSE m2.sender_id END)
            )
            GROUP BY LEAST(m2.sender_id, m2.receiver_id), GREATEST(m2.sender_id, m2.receiver_id)
        )
        AND u.id IN ($followersPlaceholders)
        ORDER BY m.created_at DESC
    ");
    $params = array_merge([$user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id], $followersIds);
    $stmt->execute($params);
    $recentChats = $stmt->fetchAll(PDO::FETCH_OBJ);
} else {
    $stmt = $conn->prepare("
        SELECT u.*, 
               m.message, 
               m.created_at,
               m.is_deleted,
               m.sender_id as last_sender_id,
               m.file_type,
               m.file_name,
               m.deleted_for_everyone,
               (
                 SELECT COUNT(*) FROM messages 
                 WHERE sender_id = u.id 
                   AND receiver_id = ? 
                   AND is_read = 0
               ) AS unread_count
        FROM messages m
        JOIN users u 
          ON (CASE WHEN m.sender_id = ? THEN m.receiver_id = u.id 
                   WHEN m.receiver_id = ? THEN m.sender_id = u.id END)
        LEFT JOIN cleared_chats cc ON cc.user_id = ? AND cc.chat_with_id = u.id
        WHERE m.id IN (
            SELECT MAX(m2.id) 
            FROM messages m2
            LEFT JOIN cleared_chats cc2 ON cc2.user_id = ? AND cc2.chat_with_id = (CASE WHEN m2.sender_id = ? THEN m2.receiver_id ELSE m2.sender_id END)
            WHERE (m2.sender_id = ? OR m2.receiver_id = ?)
            AND (cc2.cleared_at IS NULL OR m2.created_at > cc2.cleared_at)
            AND NOT EXISTS (
                SELECT 1 FROM hidden_chats hc 
                WHERE hc.user_id = ? 
                AND hc.chat_with_id = (CASE WHEN m2.sender_id = ? THEN m2.receiver_id ELSE m2.sender_id END)
            )
            GROUP BY LEAST(m2.sender_id, m2.receiver_id), GREATEST(m2.sender_id, m2.receiver_id)
        )
        ORDER BY m.created_at DESC
    ");
    $stmt->execute([$user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id]);
    $recentChats = $stmt->fetchAll(PDO::FETCH_OBJ);
}

function isOnline($last_seen) {
    if (!$last_seen || $last_seen == '0000-00-00 00:00:00') return false;
    $last_seen_time = strtotime($last_seen);
    $current_time = time();
    return (abs($current_time - $last_seen_time) <= 45);
}

function formatLastSeen($time)
{
    if (!$time || $time == '0000-00-00 00:00:00') return "never";
    $timestamp = strtotime($time);
    $now = time();
    $today = strtotime(date('Y-m-d'));
    $yesterday = strtotime('-1 day', $today);
    
    if (date('Y-m-d', $timestamp) == date('Y-m-d', $now)) {
        return "today at " . date("g:i A", $timestamp);
    } elseif (date('Y-m-d', $timestamp) == date('Y-m-d', $yesterday)) {
        return "yesterday at " . date("g:i A", $timestamp);
    } else {
        return date("M j, Y \a\\t g:i A", $timestamp);
    }
}

function formatTime($time)
{
    if (!$time || $time == '0000-00-00 00:00:00') return "";
    return date("g:i A", strtotime($time));
}

function formatDateSeparator($timestamp)
{
    $now = time();
    $today = strtotime(date('Y-m-d'));
    $yesterday = strtotime('-1 day', $today);
    
    if (date('Y-m-d', $timestamp) == date('Y-m-d', $now)) {
        return "Today";
    } elseif (date('Y-m-d', $timestamp) == date('Y-m-d', $yesterday)) {
        return "Yesterday";
    } else {
        return date("F j, Y", $timestamp);
    }
}

function getFileIcon($fileType)
{
    switch($fileType) {
        case 'photo': return '<i class="fas fa-image" style="color: #4CAF50;"></i>';
        case 'video': return '<i class="fas fa-video" style="color: #FF9800;"></i>';
        case 'document': return '<i class="fas fa-file-alt" style="color: #2196F3;"></i>';
        case 'voice': return '<i class="fas fa-microphone" style="color: #128C7E;"></i>';
        default: return '<i class="fas fa-paperclip"></i>';
    }
}

function getFileSize($filepath)
{
    if (file_exists($filepath)) {
        $bytes = filesize($filepath);
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        } else {
            return $bytes . ' bytes';
        }
    }
    return 'Unknown size';
}

function isPreviewableFile($filename) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $previewable = ['pdf', 'txt', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'webm', 'ogg'];
    return in_array($ext, $previewable);
}

function isEmojiOnlyMessage($text) {
    $text = trim((string)$text);
    if ($text === '') return false;

    // Remove spaces, emoji variation selectors, joiners, skin tones, flags, symbols and pictographs.
    $withoutEmoji = preg_replace('/[\s\x{200D}\x{FE0E}\x{FE0F}\x{1F1E6}-\x{1F1FF}\x{1F3FB}-\x{1F3FF}\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{20E3}]+/u', '', $text);
    return $withoutEmoji === '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
  <title>Messages | TwitterClone</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
  <!-- Emoji Picker Library -->
  <script src="https://cdn.jsdelivr.net/npm/@joeattardi/emoji-button@4.6.4/dist/index.min.js"></script>
  <style>
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    body {
      background: #e9ecef;
      font-family: 'Inter', sans-serif;
      display: flex;
      justify-content: center;
      align-items: center;
      min-height: 100vh;
      padding: 20px;
    }

    .messenger-container {
      display: flex;
      width: 1300px;
      max-width: 98vw;
      height: 90vh;
      background: #fff;
      border-radius: 28px;
      box-shadow: 0 20px 35px -12px rgba(0, 0, 0, 0.2);
      overflow: hidden;
    }

    .chats-sidebar {
      width: 360px;
      background: #fefefe;
      border-right: 1px solid #e9ecef;
      display: flex;
      flex-direction: column;
      flex-shrink: 0;
    }

    .sidebar-header {
      padding: 20px 16px 12px;
      background: #fff;
      border-bottom: 1px solid #eef2f6;
      position: relative;
    }

    .back-home {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      text-decoration: none;
      color: #128C7E;
      font-weight: 500;
      font-size: 14px;
      margin-bottom: 16px;
    }

    .filter-tabs {
      display: flex;
      gap: 8px;
      margin-bottom: 16px;
      background: #f0f2f5;
      padding: 4px;
      border-radius: 30px;
    }

    .filter-tab {
      flex: 1;
      text-align: center;
      padding: 8px 12px;
      border-radius: 25px;
      cursor: pointer;
      font-size: 13px;
      font-weight: 500;
      color: #667781;
      transition: all 0.2s ease;
      background: transparent;
      border: none;
    }

    .filter-tab.active {
      background: #128C7E;
      color: white;
    }

    .search-wrapper {
      position: relative;
      background: #f0f2f5;
      border-radius: 30px;
      padding: 8px 16px;
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .search-wrapper input {
      border: none;
      background: transparent;
      outline: none;
      width: 100%;
      font-size: 14px;
    }

    .search-wrapper button {
      background: none;
      border: none;
      color: #128C7E;
      cursor: pointer;
    }

    .search-dropdown {
      position: absolute;
      top: 100%;
      left: 0;
      right: 0;
      background: white;
      border-radius: 12px;
      box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
      margin-top: 8px;
      z-index: 1000;
      max-height: 300px;
      overflow-y: auto;
      display: none;
    }

    .search-dropdown.show {
      display: block;
      animation: fadeInDown 0.2s ease;
    }

    @keyframes fadeInDown {
      from {
        opacity: 0;
        transform: translateY(-10px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    .search-dropdown-item {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 12px 16px;
      cursor: pointer;
      transition: background 0.15s;
      border-bottom: 1px solid #f0f2f5;
    }

    .search-dropdown-item:hover {
      background: #f5f6f6;
    }

    .search-dropdown-item:last-child {
      border-bottom: none;
    }

    .search-dropdown-avatar {
      width: 40px;
      height: 40px;
      border-radius: 50%;
      object-fit: cover;
    }

    .search-dropdown-info {
      flex: 1;
    }

    .search-dropdown-username {
      font-weight: 600;
      font-size: 14px;
      color: #111b21;
    }

    .search-dropdown-name {
      font-size: 12px;
      color: #667781;
    }

    .search-dropdown-verified {
      color: #128C7E;
      margin-left: 4px;
      font-size: 12px;
    }

    .search-dropdown-empty {
      padding: 20px;
      text-align: center;
      color: #8696a0;
      font-size: 13px;
    }

    .search-dropdown::-webkit-scrollbar {
      width: 6px;
    }

    .search-dropdown::-webkit-scrollbar-track {
      background: #f1f1f1;
      border-radius: 3px;
    }

    .search-dropdown::-webkit-scrollbar-thumb {
      background: #c1c1c1;
      border-radius: 3px;
    }

    .recent-label {
      font-size: 13px;
      font-weight: 600;
      color: #54656f;
      padding: 16px 16px 8px;
    }

    .chat-list {
      flex: 1;
      overflow-y: auto;
    }

    .chat-item {
      display: flex;
      align-items: center;
      padding: 12px 16px;
      cursor: pointer;
      transition: background 0.15s ease;
      position: relative;
    }

    .chat-item:hover {
      background: #f5f6f6;
    }

    .chat-avatar {
      width: 52px;
      height: 52px;
      border-radius: 50%;
      object-fit: cover;
      margin-right: 12px;
    }

    .chat-info {
      flex: 1;
      min-width: 0;
    }

    .chat-name {
      font-weight: 600;
      font-size: 15px;
      color: #111b21;
    }

    .chat-preview {
      font-size: 13px;
      color: #667781;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .unread-badge {
      background: #25D366;
      color: white;
      font-size: 12px;
      font-weight: 600;
      min-width: 20px;
      height: 20px;
      border-radius: 20px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      padding: 0 6px;
    }

    .chat-menu-btn {
      background: none;
      border: none;
      cursor: pointer;
      padding: 8px;
      border-radius: 50%;
      color: #667781;
      font-size: 14px;
      transition: all 0.2s ease;
      display: flex;
      align-items: center;
      justify-content: center;
      margin-left: 8px;
      flex-shrink: 0;
    }

    .chat-menu-btn:hover {
      background: #e9edf2;
      color: #128C7E;
    }

    .chat-context-menu {
      position: fixed;
      background: white;
      border-radius: 12px;
      box-shadow: 0 8px 20px rgba(0, 0, 0, 0.2);
      min-width: 180px;
      z-index: 10000;
      overflow: hidden;
      animation: fadeInScale 0.12s ease;
    }

    .chat-menu-item {
      padding: 12px 18px;
      display: flex;
      align-items: center;
      gap: 12px;
      font-size: 14px;
      font-weight: 500;
      cursor: pointer;
      color: #e0245e;
    }

    .chat-menu-item:hover {
      background: #f0f2f5;
    }

    .chat-header-menu {
      margin-left: auto;
      position: relative;
    }

    .chat-header-btn {
      background: none;
      border: none;
      cursor: pointer;
      padding: 8px 12px;
      border-radius: 30px;
      color: #54656f;
      font-size: 18px;
      transition: all 0.2s ease;
    }

    .chat-header-btn:hover {
      background: #f0f2f5;
      color: #128C7E;
    }

    .header-dropdown-menu {
      position: absolute;
      top: 100%;
      right: 0;
      background: white;
      border-radius: 12px;
      box-shadow: 0 8px 20px rgba(0, 0, 0, 0.2);
      min-width: 200px;
      z-index: 1000;
      overflow: hidden;
      display: none;
      animation: fadeInScale 0.12s ease;
    }

    .header-dropdown-menu.show {
      display: block;
    }

    .header-dropdown-item {
      padding: 12px 18px;
      display: flex;
      align-items: center;
      gap: 12px;
      font-size: 14px;
      font-weight: 500;
      cursor: pointer;
      transition: background 0.15s;
    }

    .header-dropdown-item:hover {
      background: #f0f2f5;
    }

    .header-dropdown-item.danger {
      color: #e0245e;
    }

    .chat-panel {
      flex: 1;
      display: flex;
      flex-direction: column;
      background: #f6f7f9;
    }

    .chat-panel-header {
      background: #ffffff;
      padding: 14px 20px;
      border-bottom: 1px solid #e9edf2;
      display: flex;
      align-items: center;
      gap: 14px;
    }

    .header-avatar {
      width: 44px;
      height: 44px;
      border-radius: 50%;
      object-fit: cover;
    }

    .header-details h3 {
      font-size: 16px;
      font-weight: 600;
      margin-bottom: 2px;
    }

    .header-details span {
      font-size: 12px;
      color: #8696a0;
    }

    .presence-online {
      color: #22c55e;
      font-weight: 700;
    }

    .presence-offline {
      color: #8696a0;
      font-weight: 500;
    }

    .pinned-banner {
      background: #fff7e6;
      border-bottom: 1px solid #ffe6b3;
      padding: 12px 20px;
      display: flex;
      flex-direction: column;
      gap: 10px;
      max-height: 0;
      overflow: hidden;
      transition: max-height 0.3s ease-out;
    }

    .pinned-banner.show {
      max-height: 250px;
      overflow-y: auto;
    }

    .pinned-header {
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 12px;
      font-weight: 600;
      color: #d68a00;
      padding-bottom: 5px;
      border-bottom: 1px solid #ffe6b3;
    }

    .pinned-message-item {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 8px 10px;
      background: #fffdf5;
      border-radius: 8px;
      cursor: pointer;
      font-size: 13px;
    }

    .pinned-message-item:hover {
      background: #fff3d6;
    }

    .messages-area {
      flex: 1;
      overflow-y: auto;
      padding: 20px 24px;
      display: flex;
      flex-direction: column;
      gap: 8px;
      background-image: radial-gradient(#e4e9f0 1px, transparent 1px);
      background-size: 20px 20px;
    }

    /* Selection mode styles */
    .message-bubble.selection-mode {
      cursor: pointer;
      position: relative;
    }
    
    .message-bubble.selection-mode::before {
      content: '';
      position: absolute;
      left: -24px;
      top: 50%;
      transform: translateY(-50%);
      width: 18px;
      height: 18px;
      border: 2px solid #128C7E;
      border-radius: 4px;
      background: white;
    }
    
    .message-bubble.selection-mode.selected::before {
      background: #128C7E;
      content: '✓';
      color: white;
      font-size: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      line-height: 1;
    }
    
    .selection-header {
      background: #128C7E;
      color: white;
      padding: 12px 20px;
      display: flex;
      align-items: center;
      gap: 12px;
    }
    
    .selection-header button {
      background: none;
      border: none;
      color: white;
      cursor: pointer;
      font-size: 14px;
    }
    
    .selection-count {
      flex: 1;
      font-weight: 600;
    }
    
    .selection-actions {
      display: flex;
      gap: 16px;
    }
    
    .selection-actions button {
      background: rgba(255,255,255,0.2);
      padding: 6px 12px;
      border-radius: 20px;
    }

    .date-separator {
      display: flex;
      justify-content: center;
      margin: 16px 0;
    }
    
    .date-separator span {
      background: #e9edf2;
      padding: 4px 12px;
      border-radius: 20px;
      font-size: 11px;
      font-weight: 500;
      color: #54656f;
    }

    .message-wrapper {
      display: flex;
      flex-direction: column;
      max-width: 65%;
      position: relative;
    }

    .message-wrapper.outgoing {
      align-self: flex-end;
    }

    .message-wrapper.incoming {
      align-self: flex-start;
    }

    .message-bubble {
      padding: 10px 14px;
      border-radius: 18px;
      font-size: 14px;
      line-height: 1.45;
      word-wrap: break-word;
      cursor: pointer;
      transition: 0.1s;
    }

    .message-out {
      background: #dcf8c5;
      border-bottom-right-radius: 4px;
      color: #111b21;
    }

    .message-in {
      background: white;
      border-bottom-left-radius: 4px;
      color: #111b21;
      box-shadow: 0 1px 1px rgba(0,0,0,0.05);
    }

    .deleted-message {
      background: #e9edf2 !important;
      color: #8696a0 !important;
      font-style: italic;
      cursor: default;
    }

    .pinned-message {
      border-left: 4px solid #ffc107;
      background: #fff7e6;
    }

    .message-time {
      font-size: 10px;
      color: #8696a0;
      margin-top: 4px;
      margin-left: 8px;
      margin-right: 8px;
    }

    .outgoing .message-time {
      text-align: right;
    }

    .incoming .message-time {
      text-align: left;
    }

    .highlight-message {
      animation: highlight 1s ease-out;
    }

    @keyframes highlight {
      0% {
        background: #ffecb3;
        transform: scale(1.02);
      }
      100% {
        background: inherit;
        transform: scale(1);
      }
    }

    .file-message {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .file-message i {
      font-size: 32px;
    }

    .file-info {
      display: flex;
      flex-direction: column;
    }

    .file-name {
      font-size: 13px;
      font-weight: 500;
    }

    .file-size {
      font-size: 10px;
      opacity: 0.7;
    }

    .file-actions {
      display: flex;
      gap: 12px;
      margin-top: 6px;
    }

    .file-actions a {
      color: #128C7E;
      text-decoration: none;
      font-size: 11px;
    }

    .file-actions a:hover {
      text-decoration: underline;
    }

    .image-preview {
      max-width: 200px;
      max-height: 150px;
      border-radius: 12px;
      cursor: pointer;
      margin-bottom: 6px;
    }

    .video-preview {
      max-width: 200px;
      max-height: 150px;
      border-radius: 12px;
      cursor: pointer;
      margin-bottom: 6px;
    }

    /* Forward modal */
    .forward-modal {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0,0,0,0.5);
      z-index: 20000;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    
    .forward-modal-content {
      background: white;
      border-radius: 24px;
      width: 400px;
      max-width: 90%;
      max-height: 80%;
      overflow: hidden;
      display: flex;
      flex-direction: column;
    }
    
    .forward-modal-header {
      padding: 16px 20px;
      border-bottom: 1px solid #e9edf2;
      display: flex;
      align-items: center;
      gap: 12px;
    }
    
    .forward-modal-header h3 {
      flex: 1;
      font-size: 18px;
    }
    
    .forward-modal-close {
      background: none;
      border: none;
      font-size: 24px;
      cursor: pointer;
      color: #8696a0;
    }
    
    .forward-search {
      padding: 12px 20px;
      border-bottom: 1px solid #e9edf2;
    }
    
    .forward-search input {
      width: 100%;
      padding: 10px 16px;
      border: 1px solid #e9edf2;
      border-radius: 30px;
      outline: none;
    }
    
    .forward-user-list {
      flex: 1;
      overflow-y: auto;
      padding: 8px 0;
    }
    
    .forward-user-item {
      display: flex;
      align-items: center;
      padding: 12px 20px;
      cursor: pointer;
      transition: background 0.15s;
      gap: 12px;
    }
    
    .forward-user-item:hover {
      background: #f5f6f6;
    }
    
    .forward-user-item.selected {
      background: #e8f5e9;
    }
    
    .forward-user-avatar {
      width: 44px;
      height: 44px;
      border-radius: 50%;
      object-fit: cover;
    }
    
    .forward-user-info {
      flex: 1;
    }
    
    .forward-user-name {
      font-weight: 600;
    }
    
    .forward-user-username {
      font-size: 12px;
      color: #8696a0;
    }
    
    .forward-user-check {
      width: 22px;
      height: 22px;
      border: 2px solid #cbd5e1;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    
    .forward-user-item.selected .forward-user-check {
      background: #128C7E;
      border-color: #128C7E;
      color: white;
    }
    
    .forward-modal-footer {
      padding: 16px 20px;
      border-top: 1px solid #e9edf2;
      display: flex;
      justify-content: flex-end;
    }
    
    .forward-send-btn {
      background: #128C7E;
      color: white;
      border: none;
      padding: 10px 24px;
      border-radius: 30px;
      cursor: pointer;
      font-weight: 600;
    }
    
    .forward-send-btn:disabled {
      background: #cbd5e1;
      cursor: not-allowed;
    }

    .attachment-menu {
      position: absolute;
      bottom: 70px;
      left: 20px;
      background: white;
      border-radius: 20px;
      box-shadow: 0 8px 20px rgba(0, 0, 0, 0.2);
      min-width: 200px;
      z-index: 1000;
      overflow: hidden;
      animation: fadeInUp 0.2s ease;
    }

    @keyframes fadeInUp {
      from {
        opacity: 0;
        transform: translateY(10px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    .attachment-item {
      padding: 12px 18px;
      display: flex;
      align-items: center;
      gap: 12px;
      font-size: 14px;
      font-weight: 500;
      color: #111;
      transition: background 0.1s;
      cursor: pointer;
    }

    .attachment-item:hover {
      background: #f0f2f5;
    }

    .attachment-item i {
      width: 24px;
      font-size: 20px;
    }

    .attachment-item i.fa-image { color: #4CAF50; }
    .attachment-item i.fa-video { color: #FF9800; }
    .attachment-item i.fa-file-alt { color: #2196F3; }
    .attachment-item i.fa-photo-video { color: #128C7E; }

    /* Media Grid System */
    .media-grid {
      display: grid;
      gap: 4px;
      border-radius: 12px;
      overflow: hidden;
      margin-bottom: 8px;
      max-width: 300px;
    }

    /* Layouts based on count */
    .grid-1 { grid-template-columns: 1fr; }
    .grid-2 { grid-template-columns: 1fr 1fr; }
    .grid-3 { grid-template-columns: 1fr 1fr; grid-template-rows: 150px 100px; }
    .grid-3 .grid-item:first-child { grid-column: span 2; }
    .grid-4 { grid-template-columns: 1fr 1fr; grid-template-rows: 120px 120px; }

    .grid-item {
      position: relative;
      width: 100%;
      height: 100%;
      min-height: 100px;
      cursor: pointer;
    }

    .grid-item img, .grid-item video {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }

    .more-overlay {
      position: absolute;
      top: 0; left: 0; width: 100%; height: 100%;
      background: rgba(0,0,0,0.6);
      color: white;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 20px;
      font-weight: 700;
    }

    /* Lightbox / Gallery Navigation */
    .media-modal {
      display: none;
      position: fixed;
      top: 0; left: 0; width: 100%; height: 100%;
      background: rgba(0, 0, 0, 0.95);
      z-index: 10000;
      flex-direction: column;
      justify-content: center;
      align-items: center;
    }

    .media-modal img, .media-modal video {
      max-width: 85%;
      max-height: 80vh;
      object-fit: contain;
      border-radius: 8px;
      display: block;
    }

    .modal-nav {
      position: absolute;
      top: 50%;
      transform: translateY(-50%);
      background: rgba(255,255,255,0.1);
      border: none;
      color: white;
      padding: 20px;
      cursor: pointer;
      font-size: 30px;
      border-radius: 50%;
      transition: 0.3s;
      z-index: 10001;
    }

    .modal-nav:hover { background: rgba(255,255,255,0.3); }
    .modal-nav.prev { left: 20px; }
    .modal-nav.next { right: 20px; }

    .modal-counter {
      position: absolute;
      bottom: 20px;
      color: white;
      font-size: 14px;
      background: rgba(0,0,0,0.5);
      padding: 5px 15px;
      border-radius: 20px;
    }

    /* Reply Quote in Chat Bubble */
    .replied-quote {
      background: rgba(0, 0, 0, 0.05);
      border-left: 4px solid #128C7E;
      padding: 5px 10px;
      border-radius: 4px;
      margin-bottom: 8px;
      cursor: pointer;
      font-size: 12px;
    }
    .reply-username { font-weight: bold; color: #128C7E; display: block; }
    .reply-text { color: #555; margin: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

    /* Reply Preview in Footer */
    .reply-preview-wrapper {
      background: #f0f2f5;
      padding: 10px 20px;
      border-bottom: 1px solid #ddd;
    }
    .reply-content-box {
      display: flex;
      align-items: center;
      background: white;
      padding: 8px;
      border-radius: 8px;
      position: relative;
    }
    .reply-indicator { width: 4px; background: #128C7E; align-self: stretch; border-radius: 4px; margin-right: 10px; }
    .reply-details span { font-weight: bold; font-size: 13px; color: #128C7E; }
    .reply-details p { margin: 0; font-size: 12px; color: #666; }
    #cancelReplyBtn { position: absolute; right: 10px; background: none; border: none; cursor: pointer; color: #888; }

    /* Footer Preview & Cross Icon Visibility */
    .attachment-preview-wrapper {
      position: relative;
      padding: 20px;
      background: #f8f9fa;
      border-bottom: 1px solid #eee;
      display: flex;
      align-items: center;
      gap: 15px;
      overflow-x: auto;
      min-height: 100px;
    }

    #removePreviewBtn {
      position: absolute;
      top: 10px;
      right: 10px;
      background: #e0245e;
      color: white;
      border: none;
      border-radius: 50%;
      width: 28px;
      height: 28px;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: 0 2px 8px rgba(0,0,0,0.3);
      z-index: 10;
    }

    #removePreviewBtn:hover {
      background: #ff0000;
      transform: scale(1.1);
    }

    .preview-info {
      display: flex;
      flex-direction: column;
      margin-left: 10px;
      color: #54656f;
    }

    .chat-footer-container {
      background: #fff;
      border-top: 1px solid #e9edf2;
    }

    .preview-item {
      position: relative;
      display: inline-block;
    }

    .preview-item img, .preview-item video {
      width: 60px;
      height: 60px;
      object-fit: cover;
      border-radius: 8px;
    }

    .preview-item i {
      font-size: 40px;
      color: #128C7E;
    }

    #previewName {
      font-size: 13px;
      font-weight: 600;
      max-width: 200px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .chat-footer {
      background: #fff;
      padding: 12px 20px;
      display: flex;
      align-items: center;
      gap: 12px;
      position: relative;
    }

    .attach-btn {
      background: none;
      border: none;
      cursor: pointer;
      padding: 8px;
      border-radius: 50%;
      transition: background 0.2s;
      color: #54656f;
      font-size: 20px;
    }

    .attach-btn:hover {
      background: #f0f2f5;
    }

    .chat-footer input[type="text"] {
      flex: 1;
      border: none;
      background: #f0f2f5;
      border-radius: 30px;
      padding: 12px 18px;
      font-size: 14px;
      outline: none;
    }

    .chat-footer button[type="submit"] {
      background: #128C7E;
      border: none;
      color: white;
      padding: 10px 20px;
      border-radius: 30px;
      font-weight: 600;
      cursor: pointer;
    }

    .chat-footer button[type="submit"]:hover {
      background: #075e54;
    }

    .empty-chat-placeholder {
      flex: 1;
      display: flex;
      align-items: center;
      justify-content: center;
      color: #8a9aa8;
      text-align: center;
      flex-direction: column;
      gap: 12px;
    }

    .message-menu {
      position: fixed;
      background: white;
      border-radius: 12px;
      box-shadow: 0 8px 20px rgba(0, 0, 0, 0.2);
      min-width: 200px;
      z-index: 1000;
      overflow: hidden;
      animation: fadeInScale 0.12s ease;
    }

    .menu-item {
      padding: 12px 18px;
      display: flex;
      align-items: center;
      gap: 12px;
      font-size: 14px;
      font-weight: 500;
      cursor: pointer;
    }

    .menu-item:hover {
      background: #f0f2f5;
    }
    
    .menu-item.danger {
      color: #e0245e;
    }

    @keyframes fadeInScale {
      from {
        opacity: 0;
        transform: scale(0.96);
      }
      to {
        opacity: 1;
        transform: scale(1);
      }
    }

    .modal-close {
      position: absolute;
      top: 20px;
      right: 30px;
      color: white;
      font-size: 40px;
      cursor: pointer;
      z-index: 10001;
    }

    .messages-area::-webkit-scrollbar, .chat-list::-webkit-scrollbar {
      width: 6px;
    }

    .messages-area::-webkit-scrollbar-thumb {
      background: #cbd5e1;
      border-radius: 8px;
    }
    
    .clear-chat-message {
      text-align: center;
      color: #8696a0;
      font-size: 12px;
      padding: 20px;
      font-style: italic;
    }
    
    /* Styles for media + text combined display */
    .media-container {
      margin-bottom: 8px;
    }
    
    .text-content {
      word-break: break-word;
    }

    .text-content.emoji-only-message {
      font-size: 34px;
      line-height: 1.15;
      letter-spacing: 1px;
      padding: 2px 0;
    }

    .message-wrapper.outgoing .text-content.emoji-only-message,
    .message-wrapper.incoming .text-content.emoji-only-message {
      display: inline-block;
    }
    
    .file-box {
      background: #eee; 
      height: 100%; 
      display: flex; 
      align-items: center; 
      justify-content: center;
    }
    
    .document-icon {
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      color: white;
      flex-direction: column;
    }


    /* Voice Recording + Voice Message UI */
    .voice-record-btn.recording {
      background: #ffe8e8 !important;
      color: #e0245e !important;
      animation: voicePulse 1s infinite;
    }

    @keyframes voicePulse {
      0%, 100% { box-shadow: 0 0 0 0 rgba(224,36,94,0.35); }
      50% { box-shadow: 0 0 0 8px rgba(224,36,94,0); }
    }

    .voice-preview-box {
      display: flex;
      align-items: center;
      gap: 10px;
      background: #ffffff;
      border: 1px solid #d9f0ea;
      border-radius: 16px;
      padding: 10px 12px;
      min-width: 260px;
      max-width: 100%;
    }

    .voice-preview-status {
      font-size: 12px;
      font-weight: 700;
      color: #128C7E;
      white-space: nowrap;
    }

    .voice-record-dot {
      width: 10px;
      height: 10px;
      background: #e0245e;
      border-radius: 50%;
      animation: voiceBlink 1s infinite;
    }

    @keyframes voiceBlink { 50% { opacity: .25; } }

    .voice-preview-timer,
    .voice-duration {
      font-size: 12px;
      color: #54656f;
      min-width: 36px;
      text-align: right;
    }

    .voice-preview-actions {
      display: flex;
      align-items: center;
      gap: 6px;
      margin-left: auto;
    }

    .voice-mini-btn {
      border: none;
      width: 30px;
      height: 30px;
      border-radius: 50%;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }

    .voice-stop-btn { background: #e0245e; color: #fff; }
    .voice-cancel-btn { background: #eef2f6; color: #54656f; }
    .voice-pause-btn { background: #128C7E; color: #fff; }
    .voice-preview-box.recording-live .voice-wave {
      min-width: 130px;
      cursor: default;
      overflow: visible;
    }
    .voice-preview-box.recording-live .voice-wave span {
      background: rgba(224, 36, 94, 0.18);
    }
    .voice-preview-box.recording-live .voice-wave span.live-played {
      background: rgba(224, 36, 94, 0.90);
      transform: scaleY(1.08);
    }
    .voice-preview-box.recording-live .voice-progress-dot {
      opacity: 1;
      background: #e0245e;
    }
    .voice-preview-box.recording-paused .voice-record-dot {
      animation: none;
      background: #8696a0;
    }
    .voice-preview-box.recording-paused .voice-preview-status {
      color: #8696a0;
    }
    .voice-preview-box.recording-paused .voice-wave span {
      opacity: .55;
    }
    .voice-preview-box.recording-paused .voice-progress-dot {
      background: #8696a0;
    }

    .voice-message-player {
      display: flex;
      align-items: center;
      gap: 10px;
      min-width: 220px;
      max-width: 320px;
      padding: 8px 4px;
    }

    .voice-play-btn {
      border: none;
      width: 34px;
      height: 34px;
      border-radius: 50%;
      background: #128C7E;
      color: #fff;
      cursor: pointer;
      flex-shrink: 0;
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }

    .voice-wave {
      flex: 1;
      height: 34px;
      display: flex;
      align-items: center;
      gap: 3px;
      min-width: 110px;
      position: relative;
      cursor: pointer;
      padding: 0 2px;
    }

    .voice-wave span {
      display: block;
      width: 3px;
      border-radius: 99px;
      background: rgba(18, 140, 126, 0.22); /* light waveform before play */
      transition: background .12s ease, transform .12s ease;
    }

    .voice-wave span.played {
      background: rgba(18, 140, 126, 0.92); /* filled color behind moving dot */
    }

    .voice-message-player.playing .voice-wave span.played {
      background: rgba(7, 94, 84, 0.98);
      transform: scaleY(1.06);
    }

    .voice-progress-dot {
      position: absolute;
      top: 50%;
      left: 0;
      width: 11px;
      height: 11px;
      border-radius: 50%;
      background: #075e54;
      border: 2px solid #ffffff;
      box-shadow: 0 1px 5px rgba(0,0,0,0.25);
      transform: translate(-50%, -50%);
      opacity: 0;
      pointer-events: none;
      transition: left .08s linear, opacity .15s ease;
      z-index: 2;
    }

    .voice-message-player.playing .voice-progress-dot,
    .voice-message-player.has-progress .voice-progress-dot {
      opacity: 1;
    }

    .voice-speed-btn {
      border: none;
      background: rgba(18, 140, 126, 0.12);
      color: #075e54;
      border-radius: 999px;
      padding: 4px 8px;
      font-size: 11px;
      font-weight: 700;
      cursor: pointer;
      min-width: 34px;
      flex-shrink: 0;
    }

    .voice-speed-btn:hover,
    .voice-speed-btn.active {
      background: #128C7E;
      color: #fff;
    }

    .voice-preview-audio {
      max-width: 230px;
      height: 34px;
    }

    /* Custom Emoji Picker Fallback */
    .custom-emoji-picker {
      position: fixed;
      width: 310px;
      max-width: calc(100vw - 24px);
      max-height: 260px;
      overflow-y: auto;
      background: #ffffff;
      border: 1px solid #e9edf2;
      border-radius: 16px;
      box-shadow: 0 10px 30px rgba(0,0,0,0.22);
      padding: 10px;
      z-index: 30000;
      display: none;
      grid-template-columns: repeat(8, 1fr);
      gap: 4px;
    }

    .custom-emoji-picker.show {
      display: grid;
    }

    .custom-emoji-picker button {
      border: none;
      background: transparent;
      cursor: pointer;
      font-size: 22px;
      line-height: 1;
      padding: 7px 0;
      border-radius: 8px;
    }

    .custom-emoji-picker button:hover {
      background: #f0f2f5;
    }

  

/* ============================================================
   FINAL WHATSAPP-LIKE MOBILE RESPONSIVE FIX
   Preserves all existing PHP/JS/features.
   - Mobile chat list shows first.
   - When chat is open, right chat panel fills screen.
   - Back arrow returns to left chat list.
   ============================================================ */

.mobile-chat-back{
    display:none;
    align-items:center;
    justify-content:center;
    width:38px;
    height:38px;
    min-width:38px;
    border-radius:50%;
    color:#128C7E;
    text-decoration:none;
    font-size:18px;
    transition:background .15s ease;
}

.mobile-chat-back:hover{
    background:#f0f2f5;
    color:#075e54;
    text-decoration:none;
}

html,
body{
    max-width:100%;
    overflow-x:hidden;
}

/* Keep desktop as your current design */
.messenger-container{
    min-width:0;
}

.chat-panel,
.chats-sidebar,
.messages-area{
    min-width:0;
}

.message-bubble{
    overflow-wrap:anywhere;
    word-break:break-word;
}

@media only screen and (max-width: 768px){

    html,
    body{
        width:100% !important;
        height:100% !important;
        min-height:100% !important;
        overflow:hidden !important;
    }

    body{
        padding:0 !important;
        margin:0 !important;
        display:block !important;
        background:#fff !important;
    }

    .messenger-container{
        width:100vw !important;
        max-width:100vw !important;
        height:100vh !important;
        min-height:100vh !important;
        border-radius:0 !important;
        box-shadow:none !important;
        overflow:hidden !important;
        display:flex !important;
        position:relative !important;
        background:#fff !important;
    }

    /* Default mobile list view */
    body.mobile-chat-list .chats-sidebar{
        display:flex !important;
        width:100vw !important;
        max-width:100vw !important;
        min-width:0 !important;
        height:100vh !important;
        border-right:0 !important;
    }

    body.mobile-chat-list .chat-panel{
        display:none !important;
    }

    /* Open chat view */
    body.mobile-chat-open .chats-sidebar{
        display:none !important;
    }

    body.mobile-chat-open .chat-panel{
        display:flex !important;
        width:100vw !important;
        max-width:100vw !important;
        min-width:0 !important;
        height:100vh !important;
        min-height:100vh !important;
        flex:1 1 auto !important;
    }

    .mobile-chat-back{
        display:inline-flex !important;
    }

    .chat-panel-header{
        min-height:62px !important;
        padding:9px 10px !important;
        gap:8px !important;
        flex-shrink:0 !important;
        position:relative !important;
        z-index:20 !important;
    }

    .header-avatar{
        width:38px !important;
        height:38px !important;
        min-width:38px !important;
    }

    .header-details{
        flex:1 1 auto !important;
        min-width:0 !important;
    }

    .header-details h3{
        font-size:15px !important;
        white-space:nowrap !important;
        overflow:hidden !important;
        text-overflow:ellipsis !important;
        margin:0 0 2px 0 !important;
    }

    .header-details span{
        display:block !important;
        max-width:100% !important;
        font-size:11px !important;
        white-space:nowrap !important;
        overflow:hidden !important;
        text-overflow:ellipsis !important;
    }

    .chat-header-menu{
        margin-left:2px !important;
        flex:0 0 auto !important;
    }

    .chat-header-btn{
        padding:7px 8px !important;
        font-size:16px !important;
    }

    .header-dropdown-menu{
        right:0 !important;
        min-width:180px !important;
        max-width:calc(100vw - 20px) !important;
    }

    .sidebar-header{
        padding:14px 12px 10px !important;
        flex-shrink:0 !important;
    }

    .filter-tabs{
        gap:5px !important;
        margin-bottom:12px !important;
    }

    .filter-tab{
        padding:8px 5px !important;
        font-size:12px !important;
    }

    .search-wrapper{
        padding:8px 12px !important;
    }

    .chat-list{
        flex:1 1 auto !important;
        overflow-y:auto !important;
        -webkit-overflow-scrolling:touch !important;
    }

    .chat-item{
        padding:10px 12px !important;
    }

    .chat-avatar{
        width:46px !important;
        height:46px !important;
        min-width:46px !important;
        margin-right:10px !important;
    }

    .chat-info{
        min-width:0 !important;
    }

    .chat-name{
        font-size:14px !important;
    }

    .chat-preview{
        font-size:12px !important;
    }

    .messages-area{
        flex:1 1 auto !important;
        min-height:0 !important;
        overflow-y:auto !important;
        -webkit-overflow-scrolling:touch !important;
        padding:12px 10px !important;
        gap:6px !important;
    }

    .message-wrapper{
        max-width:86% !important;
    }

    .message-bubble{
        padding:9px 12px !important;
        font-size:13px !important;
        line-height:1.42 !important;
        border-radius:16px !important;
    }

    .text-content.emoji-only-message{
        font-size:30px !important;
    }

    .message-time{
        font-size:9px !important;
    }

    .pinned-banner.show{
        max-height:160px !important;
        overflow-y:auto !important;
    }

    .chat-footer-container{
        flex-shrink:0 !important;
        width:100% !important;
        max-width:100% !important;
    }

    .chat-footer{
        padding:8px 8px !important;
        gap:6px !important;
        width:100% !important;
        max-width:100% !important;
    }

    .chat-footer input[type="text"]{
        min-width:0 !important;
        flex:1 1 auto !important;
        padding:10px 12px !important;
        font-size:13px !important;
    }

    .attach-btn,
    .voice-record-btn,
    .emoji-btn{
        width:36px !important;
        height:36px !important;
        min-width:36px !important;
        padding:0 !important;
        display:inline-flex !important;
        align-items:center !important;
        justify-content:center !important;
        font-size:17px !important;
        flex:0 0 auto !important;
    }

    .chat-footer button[type="submit"]{
        width:42px !important;
        min-width:42px !important;
        height:38px !important;
        padding:0 !important;
        border-radius:50% !important;
        font-size:0 !important;
        position:relative !important;
        flex:0 0 auto !important;
    }

    .chat-footer button[type="submit"]::before{
        content:"\\f1d8";
        font-family:"Font Awesome 6 Free";
        font-weight:900;
        font-size:15px;
    }

    .attachment-menu{
        left:8px !important;
        bottom:58px !important;
        min-width:180px !important;
        max-width:calc(100vw - 16px) !important;
    }

    .attachment-preview-wrapper{
        padding:12px 40px 12px 12px !important;
        min-height:80px !important;
        gap:10px !important;
        overflow-x:auto !important;
    }

    .preview-item img,
    .preview-item video{
        width:50px !important;
        height:50px !important;
    }

    #previewName{
        max-width:145px !important;
    }

    .reply-preview-wrapper{
        padding:8px 10px !important;
    }

    .media-grid{
        width:min(230px, 100%) !important;
        max-width:100% !important;
    }

    .grid-3{
        grid-template-rows:120px 85px !important;
    }

    .grid-4{
        grid-template-rows:100px 100px !important;
    }

    .image-preview,
    .video-preview{
        max-width:170px !important;
        max-height:130px !important;
    }

    .voice-preview-box{
        min-width:0 !important;
        width:100% !important;
        max-width:100% !important;
        padding:8px 10px !important;
        gap:6px !important;
    }

    .voice-preview-status{
        font-size:11px !important;
    }

    .voice-wave{
        min-width:70px !important;
        max-width:120px !important;
    }

    .voice-preview-actions{
        gap:4px !important;
    }

    .voice-mini-btn{
        width:28px !important;
        height:28px !important;
        min-width:28px !important;
    }

    .voice-message{
        min-width:180px !important;
        max-width:100% !important;
    }

    .voice-message .voice-wave{
        max-width:130px !important;
    }

    .forward-modal-content{
        width:92vw !important;
        max-width:92vw !important;
        max-height:85vh !important;
        border-radius:18px !important;
    }

    .media-modal img,
    .media-modal video{
        max-width:92vw !important;
        max-height:75vh !important;
    }

    .modal-nav{
        padding:12px !important;
        font-size:22px !important;
    }

    .modal-nav.prev{ left:8px !important; }
    .modal-nav.next{ right:8px !important; }

    .modal-close{
        top:12px !important;
        right:16px !important;
        font-size:32px !important;
    }
}

@media only screen and (max-width:420px){
    .chat-panel-header{
        min-height:58px !important;
        padding:8px 8px !important;
        gap:7px !important;
    }

    .mobile-chat-back{
        width:34px !important;
        height:34px !important;
        min-width:34px !important;
        font-size:16px !important;
    }

    .header-avatar{
        width:35px !important;
        height:35px !important;
        min-width:35px !important;
    }

    .message-wrapper{
        max-width:90% !important;
    }

    .chat-footer{
        padding:7px 6px !important;
        gap:5px !important;
    }

    .attach-btn,
    .voice-record-btn,
    .emoji-btn{
        width:34px !important;
        height:34px !important;
        min-width:34px !important;
    }

    .chat-footer button[type="submit"]{
        width:38px !important;
        min-width:38px !important;
        height:36px !important;
    }

    .media-grid{
        width:min(210px, 100%) !important;
    }

    .voice-message{
        min-width:170px !important;
    }
}

</style>
</head>
<body class="<?php echo $receiverData ? 'mobile-chat-open' : 'mobile-chat-list'; ?>">
<div class="messenger-container">
  <!-- LEFT SIDEBAR -->
  <div class="chats-sidebar">
    <div class="sidebar-header">
      <a href="home.php" class="back-home"><i class="fas fa-arrow-left"></i> Back to Home</a>
      
      <div class="filter-tabs">
        <button class="filter-tab <?php echo $filterType == 'following' ? 'active' : ''; ?>" data-filter="following">
          <i class="fas fa-user-check"></i> Following
        </button>
        <button class="filter-tab <?php echo $filterType == 'followers' ? 'active' : ''; ?>" data-filter="followers">
          <i class="fas fa-users"></i> Followers
        </button>
      </div>
      
      <div class="search-wrapper">
        <i class="fas fa-search"></i>
        <input type="text" id="liveSearchInput" placeholder="<?php echo $filterType == 'following' ? 'Search in followed users...' : 'Search in followers...'; ?>" autocomplete="off">
        <input type="hidden" id="searchFilter" value="<?php echo $filterType; ?>">
        <div class="search-dropdown" id="searchDropdown"></div>
      </div>
    </div>

    <div class="chat-list" id="chatList">
      <div class="recent-label">RECENT CHATS</div>
      <div id="recentChatsContainer">
        <?php if (!empty($recentChats)): ?>
          <?php foreach ($recentChats as $chat): ?>
            <div class="chat-item" data-userid="<?=$chat->id?>" data-username="<?=htmlspecialchars($chat->username)?>">
              <img class="chat-avatar" src="assets/images/users/<?=$chat->img?>" alt="">
              <div class="chat-info">
                <div class="chat-name">
                  <?=htmlspecialchars($chat->username)?>
                  <?php 
                    $checkB = $conn->prepare("SELECT id FROM blocks WHERE blocker_id = ? AND blocked_id = ?");
                    $checkB->execute([$user_id, $chat->id]);
                    if($checkB->rowCount() > 0) echo '<span style="color:#e0245e; font-size:10px; margin-left:5px;">(Blocked)</span>';
                  ?>
                </div>
                <div class="chat-preview">
                  <?php if (isset($chat->deleted_for_everyone) && $chat->deleted_for_everyone == 1): ?>
                    <i class="fas fa-ban"></i> This message was deleted
                  <?php elseif (isset($chat->file_type)): ?>
                    <?php 
                    $fileTypes = json_decode($chat->file_type, true);
                    $firstFileType = is_array($fileTypes) ? ($fileTypes[0] ?? $chat->file_type) : $chat->file_type;
                    $fileNames = json_decode($chat->file_name, true);
                    if ($fileNames && is_array($fileNames) && count($fileNames) > 1) {
                      echo '<i class="fas fa-images"></i> ' . count($fileNames) . ' media files';
                    } else {
                      if ($firstFileType === 'voice') {
                        echo getFileIcon('voice') . ' Voice message';
                      } else {
                        echo getFileIcon($firstFileType); 
                        echo ' ' . htmlspecialchars(is_array($fileNames) ? ($fileNames[0] ?? $chat->message) : ($chat->file_name ?: $chat->message));
                      }
                    }
                    ?>
                  <?php else: ?>
                    <?= htmlspecialchars($chat->message); ?>
                  <?php endif; ?>
                </div>
              </div>
              <?php if ($chat->unread_count > 0): ?>
                <span class="unread-badge"><?=$chat->unread_count?></span>
              <?php endif; ?>
              <button class="chat-menu-btn" data-userid="<?=$chat->id?>" data-username="<?=htmlspecialchars($chat->username)?>">
                <i class="fas fa-chevron-down"></i>
              </button>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <div style="padding: 20px; text-align: center; color: #8696a0; font-size: 13px;">
            No chats yet. Start a conversation!
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- RIGHT CHAT PANEL -->
  <div class="chat-panel">
    <?php if ($receiverData): ?>
      <div class="chat-panel-header">
        <a href="messages.php?filter=<?php echo htmlspecialchars($filterType); ?>" class="mobile-chat-back" aria-label="Back to chats">
          <i class="fas fa-arrow-left"></i>
        </a>
        <img class="header-avatar" src="assets/images/users/<?=$receiverData->img?>" alt="">
        <div class="header-details">
          <h3><?=htmlspecialchars($receiverData->name)?></h3>
          <span id="chatPresenceText" data-receiver-id="<?= (int)$receiverData->id ?>" data-can-check="<?= (!$iBlockedHim && !$heBlockedMe) ? '1' : '0' ?>">
            <?php if (!$iBlockedHim && !$heBlockedMe && isOnline($receiverData->last_seen)): ?>
                <span class="presence-online">● Online</span>
            <?php elseif (!$iBlockedHim && !$heBlockedMe): ?>
                last seen <?=formatLastSeen($receiverData->last_seen)?>
            <?php elseif ($iBlockedHim): ?>
                <span style="color:#e0245e;">You blocked this user</span>
            <?php elseif ($heBlockedMe): ?>
                <span style="color:#e0245e;">Blocked you</span>
            <?php else: ?>
                <span style="color:#e0245e;">Blocked</span>
            <?php endif; ?>
          </span>
        </div>
        <div class="chat-header-menu">
          <button class="chat-header-btn" id="chatHeaderMenuBtn">
            <i class="fas fa-ellipsis-v"></i>
          </button>
          <div class="header-dropdown-menu" id="headerDropdownMenu">
            <?php if (!$heBlockedMe): ?>
              <div class="header-dropdown-item danger" id="clearChatBtn">
                <i class="fas fa-trash-alt"></i> Clear Chat
              </div>
            <?php endif; ?>
            <div class="header-dropdown-item danger" id="blockUserBtn">
              <i class="fas fa-ban"></i> 
              <span id="blockBtnText"><?php echo $iBlockedHim ? 'Unblock' : 'Block User'; ?></span>
            </div>
          </div>
        </div>
      </div>
      
      <!-- Selection Mode Header (hidden by default) -->
      <div id="selectionHeader" class="selection-header" style="display: none;">
        <button id="cancelSelectionBtn"><i class="fas fa-times"></i></button>
        <span id="selectionCount" class="selection-count">0 selected</span>
        <div class="selection-actions">
          <button id="forwardSelectedBtn"><i class="fas fa-share"></i> Forward</button>
        </div>
      </div>
      
      <!-- Show messages and footer only if NOT blocked by either side -->
      <?php if (!$iBlockedHim && !$heBlockedMe): ?>
      
        <?php if (!empty($pinnedMessages)): ?>
        <div class="pinned-banner show">
          <div class="pinned-header">
            <i class="fas fa-thumbtack"></i>
            <span>Pinned Messages (<?=count($pinnedMessages)?>)</span>
          </div>
          <?php foreach ($pinnedMessages as $pinnedMsg): ?>
            <div class="pinned-message-item" data-msg-id="<?=$pinnedMsg->id?>">
              <i class="fas fa-thumbtack"></i>
              <div class="pinned-message-content">
                <?php 
                $pinnedPaths = json_decode($pinnedMsg->file_path, true);
                if ($pinnedPaths && is_array($pinnedPaths) && count($pinnedPaths) > 0): ?>
                  <?= getFileIcon($pinnedMsg->file_type); ?> <?= count($pinnedPaths) . ' media file(s)' ?>
                <?php else: ?>
                  <?= htmlspecialchars(substr($pinnedMsg->message, 0, 80)); ?>
                <?php endif; ?>
              </div>
              <i class="fas fa-arrow-right"></i>
            </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
        
        <div class="messages-area" id="messagesArea">
          <?php if (empty($chatMessages)): ?>
            <div class="clear-chat-message">
              <i class="fas fa-comment-slash"></i>
              <p>No messages yet. Start a conversation!</p>
            </div>
          <?php else: ?>
            <?php 
            $lastDate = '';
            foreach ($chatMessages as $msg): 
              $isMine = ($msg->sender_id == $user_id);
              $isDeleted = (isset($msg->deleted_for_everyone) && $msg->deleted_for_everyone == 1);
              $msgDate = date('Y-m-d', strtotime($msg->created_at));
              $dateLabel = '';
              
              if ($lastDate != $msgDate) {
                $lastDate = $msgDate;
                $dateLabel = formatDateSeparator(strtotime($msg->created_at));
              }
              
              $displayMessage = $msg->message;
              if ($isDeleted) {
                $displayMessage = "This message was deleted";
              }
              
              // Handle file data
              $hasFile = false;
              $paths = [];
              $types = [];
              $names = [];
              $mediaList = [];
              
              if (isset($msg->file_path) && !empty($msg->file_path) && !$isDeleted) {
                $decodedPaths = json_decode($msg->file_path, true);
                if ($decodedPaths && is_array($decodedPaths)) {
                  $paths = $decodedPaths;
                  $types = json_decode($msg->file_type, true) ?: [];
                  $names = json_decode($msg->file_name, true) ?: [];
                  $hasFile = !empty($paths);
                  for ($j = 0; $j < count($paths); $j++) {
                    $mediaList[] = ['src' => $paths[$j], 'type' => ($types[$j] ?? 'photo'), 'name' => ($names[$j] ?? 'file'), 'duration' => ($msg->voice_duration ?? null), 'waveform' => ($msg->voice_waveform ?? null)];
                  }
                } else {
                  if (file_exists($msg->file_path)) {
                    $paths = [$msg->file_path];
                    $types = [$msg->file_type];
                    $names = [$msg->file_name];
                    $hasFile = true;
                    $mediaList[] = ['src' => $msg->file_path, 'type' => ($msg->file_type == 'voice' ? 'voice' : ($msg->file_type == 'photo' ? 'photo' : 'video')), 'name' => $msg->file_name, 'duration' => ($msg->voice_duration ?? null), 'waveform' => ($msg->voice_waveform ?? null)];
                  }
                }
              }
              
              $count = count($paths);
              $displayCount = min($count, 4);
              $mediaJson = htmlspecialchars(json_encode($mediaList), ENT_QUOTES, 'UTF-8');
              $pathsJson = htmlspecialchars(json_encode($paths), ENT_QUOTES, 'UTF-8');
              $namesJson = htmlspecialchars(json_encode($names), ENT_QUOTES, 'UTF-8');
              $typesJson = htmlspecialchars(json_encode($types), ENT_QUOTES, 'UTF-8');
              
              $replyUsername = $msg->reply_user;
              $replyText = $msg->reply_msg;
              if ($replyText && strlen($replyText) > 50) {
                $replyText = substr($replyText, 0, 50) . '...';
              }
              $replyToId = $msg->reply_to;
            ?>
              <?php if ($dateLabel): ?>
                <div class="date-separator"><span><?= $dateLabel ?></span></div>
              <?php endif; ?>
              <div class="message-wrapper <?= $isMine ? 'outgoing' : 'incoming' ?>">
                <div class="message-bubble <?= $isMine ? 'message-out' : 'message-in' ?> <?= isset($msg->is_pinned) && $msg->is_pinned ? 'pinned-message' : '' ?> <?= $isDeleted ? 'deleted-message' : '' ?>" 
                     data-msg-id="<?= $msg->id ?>" 
                     data-sender="<?= $msg->sender_id ?>" 
                     data-is-deleted="<?= $isDeleted ? 1 : 0 ?>"
                     data-paths='<?= $pathsJson ?>'
                     data-names='<?= $namesJson ?>'
                     data-types='<?= $typesJson ?>'>
                  
                  <?php if ($isDeleted): ?>
                    <i class="fas fa-ban"></i> This message was deleted
                  <?php else: ?>
                    
                    <?php if (!empty($replyToId) && $replyText): ?>
                      <div class="replied-quote" onclick="event.stopPropagation(); scrollToMessage(<?= $replyToId ?>)">
                        <span class="reply-username">@<?= htmlspecialchars($replyUsername) ?></span>
                        <p class="reply-text"><?= htmlspecialchars($replyText) ?></p>
                      </div>
                    <?php endif; ?>
                    
                    <?php if ($hasFile && $count > 0): ?>
                        <?php if (($types[0] ?? '') === 'voice'): ?>
                          <div class="voice-message-player <?= $isMine ? 'mine' : 'theirs' ?>" onclick="event.stopPropagation();">
                            <button type="button" class="voice-play-btn" aria-label="Play voice message"><i class="fas fa-play"></i></button>
                            <div class="voice-wave" data-waveform="<?= htmlspecialchars($msg->voice_waveform ?? '', ENT_QUOTES, 'UTF-8') ?>">
                              <?php for ($w = 0; $w < 28; $w++): ?>
                                <span style="height: <?= 8 + (($w * 7) % 22) ?>px"></span>
                              <?php endfor; ?>
                              <div class="voice-progress-dot"></div>
                            </div>
                            <span class="voice-duration"><?= !empty($msg->voice_duration) ? gmdate('i:s', (int)$msg->voice_duration) : '0:00' ?></span>
                            <button type="button" class="voice-speed-btn" data-speed="1">1x</button>
                            <audio preload="metadata" src="<?= htmlspecialchars($paths[0]) ?>"></audio>
                          </div>
                        <?php else: ?>
                          <div class="media-grid grid-<?= $displayCount ?>">
                              <?php for ($i = 0; $i < $displayCount; $i++): ?>
                                  <div class="grid-item" 
                                       data-file-type="<?= isset($types[$i]) ? $types[$i] : 'document' ?>"
                                       data-file-path="<?= $paths[$i] ?>"
                                       data-file-name="<?= htmlspecialchars($names[$i] ?? 'file') ?>"
                                       onclick="event.stopPropagation(); handleMediaClick(<?= $mediaJson ?>, <?= $i ?>, this)">
                                      <?php if (isset($types[$i]) && $types[$i] == 'photo'): ?>
                                          <img src="<?= $paths[$i] ?>">
                                      <?php elseif (isset($types[$i]) && $types[$i] == 'video'): ?>
                                          <video src="<?= $paths[$i] ?>"></video>
                                      <?php else: ?>
                                          <div class="file-box document-icon">
                                              <i class="fas fa-file-alt fa-3x"></i>
                                              <div style="font-size: 10px; margin-top: 5px;"><?= strtoupper(pathinfo($names[$i] ?? 'file', PATHINFO_EXTENSION)) ?></div>
                                          </div>
                                      <?php endif; ?>

                                      <?php if ($i == 3 && $count > 4): ?>
                                          <div class="more-overlay">+<?= ($count - 4) ?></div>
                                      <?php endif; ?>
                                  </div>
                              <?php endfor; ?>
                          </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if (!empty($displayMessage)): ?>
                        <?php $emojiOnlyClass = isEmojiOnlyMessage($displayMessage) ? ' emoji-only-message' : ''; ?>
                        <div class="text-content<?= $emojiOnlyClass ?>">
                            <?= htmlspecialchars($displayMessage) ?>
                        </div>
                    <?php endif; ?>

                  <?php endif; ?>
                </div>
                <div class="message-time"><?= formatTime($msg->created_at) ?></div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
        
        <!-- Unified Chat Footer -->
        <div class="chat-footer-container">
          <div id="replyPreview" class="reply-preview-wrapper" style="display: none;">
            <div class="reply-content-box">
              <div class="reply-indicator"></div>
              <div class="reply-details">
                <span id="replyUser"></span>
                <p id="replySnippet"></p>
              </div>
              <button type="button" id="cancelReplyBtn"><i class="fas fa-times"></i></button>
            </div>
          </div>
          
          <div id="attachmentPreview" class="attachment-preview-wrapper" style="display: none;">
            <div id="previewMedia" style="display: flex; gap: 10px; align-items: center;"></div>
            <div class="preview-info">
              <span id="previewName" style="font-weight:bold; font-size:12px;"></span>
            </div>
            <button type="button" id="removePreviewBtn" title="Remove all attachments">
              <i class="fas fa-times"></i>
            </button>
          </div>

          <div class="chat-footer">
            <button type="button" class="attach-btn" id="attachBtn">
              <i class="fas fa-paperclip"></i>
            </button>
            
            <button type="button" class="attach-btn" id="emojiBtn">
              <i class="far fa-smile"></i>
            </button>

            <button type="button" class="attach-btn voice-record-btn" id="voiceRecordBtn" title="Record voice message">
              <i class="fas fa-microphone"></i>
            </button>
            
            <form method="POST" enctype="multipart/form-data" id="mainChatForm" action="messages.php?user=<?=$receiver?>&filter=<?=$filterType?>" style="flex: 1; display: flex; gap: 12px;">
              <input type="hidden" name="reply_to_id" id="replyToInput">
              <input type="hidden" name="voice_duration" id="voiceDurationInput">
              <input type="hidden" name="voice_waveform" id="voiceWaveformInput">
              <input type="file" name="file_attachment[]" id="fileInput" style="display: none;" multiple accept="image/*,video/*,audio/*,.pdf,.txt,.doc,.docx,.xls,.xlsx,.zip">
              <input type="text" name="message" id="messageInput" placeholder="Type a message..." autocomplete="off">
              <button type="submit" name="send" id="sendBtn">Send <i class="fas fa-paper-plane"></i></button>
            </form>
          </div>
        </div>
      
      <?php elseif ($iBlockedHim): ?>
        <div class="empty-chat-placeholder" style="background: #fff1f1;">
          <i class="fas fa-user-slash" style="font-size: 48px; color: #e0245e;"></i>
          <p style="color: #e0245e;">You have blocked this user. Use the menu above to unblock.</p>
        </div>
      <?php elseif ($heBlockedMe): ?>
        <div class="empty-chat-placeholder" style="background: #fff1f1;">
          <i class="fas fa-user-slash" style="font-size: 48px; color: #e0245e;"></i>
          <p style="color: #e0245e;">You cannot reply to this user. You have been blocked.</p>
        </div>
      <?php endif; ?>
      
    <?php else: ?>
      <div class="empty-chat-placeholder">
        <i class="fas fa-comments" style="font-size: 48px; opacity: 0.4;"></i>
        <p>Select a conversation or search to start messaging</p>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Media Gallery Modal with Navigation -->
<div id="mediaModal" class="media-modal">
  <span class="modal-close" onclick="closeMediaModal()">&times;</span>
  
  <button class="modal-nav prev" id="prevBtn" onclick="changeMedia(-1)"><i class="fas fa-chevron-left"></i></button>
  
  <img id="modalImage" src="" alt="" style="display: none;">
  <video id="modalVideo" controls style="display: none;"></video>
  
  <button class="modal-nav next" id="nextBtn" onclick="changeMedia(1)"><i class="fas fa-chevron-right"></i></button>
  
  <div class="modal-counter" id="modalCounter">1 / 1</div>
</div>

<script>
  let selectedMessages = new Set();
  let isSelectionMode = false;
  let currentChatWithId = <?php echo isset($receiver) ? $receiver : 0; ?>;
  
  // Gallery variables
  let galleryItems = [];
  let galleryIndex = 0;
  
  // Helper function to check if a file is previewable in browser
  function isPreviewableFile(filename) {
    const ext = (filename.split('.').pop() || '').toLowerCase();
    const previewable = ['pdf', 'txt', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'webm', 'ogg'];
    return previewable.includes(ext);
  }
  
  // Handle click on grid items
  function handleMediaClick(items, index, element) {
    const item = items[index];
    const fileType = element.getAttribute('data-file-type');
    const filePath = element.getAttribute('data-file-path');
    const fileName = element.getAttribute('data-file-name');
    
    if (fileType === 'photo' || fileType === 'video') {
      openGallery(items, index);
    } else {
      if (isPreviewableFile(fileName)) {
        window.open(filePath, '_blank');
      } else {
        alert('This file type cannot be previewed. Please use the Download option in the menu to save it.');
      }
    }
  }
  
  function openGallery(items, index) {
    galleryItems = items;
    galleryIndex = index;
    showMedia();
    document.getElementById('mediaModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
  }
  
  function showMedia() {
    const item = galleryItems[galleryIndex];
    const modalImg = document.getElementById('modalImage');
    const modalVideo = document.getElementById('modalVideo');
    const counter = document.getElementById('modalCounter');
    
    modalImg.style.display = 'none';
    modalVideo.style.display = 'none';
    if(modalVideo.pause) modalVideo.pause();

    if (item.type === 'photo' || item.type === 'image' || (item.type && item.type.includes('image'))) {
      modalImg.src = item.src;
      modalImg.style.display = 'block';
    } else if (item.type === 'video' || (item.type && item.type.includes('video'))) {
      modalVideo.src = item.src;
      modalVideo.style.display = 'block';
      modalVideo.load();
    }
    
    const prevBtn = document.getElementById('prevBtn');
    const nextBtn = document.getElementById('nextBtn');
    if (galleryItems.length > 1) {
      prevBtn.style.visibility = 'visible';
      nextBtn.style.visibility = 'visible';
    } else {
      prevBtn.style.visibility = 'hidden';
      nextBtn.style.visibility = 'hidden';
    }
    
    counter.innerText = `${galleryIndex + 1} / ${galleryItems.length}`;
  }
  
  function changeMedia(step) {
    galleryIndex += step;
    if (galleryIndex < 0) galleryIndex = galleryItems.length - 1;
    if (galleryIndex >= galleryItems.length) galleryIndex = 0;
    showMedia();
  }
  
  function closeMediaModal() {
    const modal = document.getElementById('mediaModal');
    const modalVideo = document.getElementById('modalVideo');
    modal.style.display = 'none';
    modalVideo.pause();
    modalVideo.src = '';
    document.body.style.overflow = '';
  }
  
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
      closeMediaModal();
      if (isSelectionMode) exitSelectionMode();
    }
    if (document.getElementById('mediaModal').style.display === 'flex') {
      if (e.key === 'ArrowLeft') changeMedia(-1);
      if (e.key === 'ArrowRight') changeMedia(1);
    }
  });
  
  // Exit selection mode
  function exitSelectionMode() {
    isSelectionMode = false;
    selectedMessages.clear();
    document.querySelectorAll('.message-bubble').forEach(msg => {
      msg.classList.remove('selection-mode', 'selected');
    });
    document.getElementById('selectionHeader').style.display = 'none';
    document.querySelector('.chat-footer').style.display = 'flex';
  }
  
  // Enter selection mode
  function enterSelectionMode() {
    isSelectionMode = true;
    document.querySelectorAll('.message-bubble').forEach(msg => {
      const isDeleted = msg.getAttribute('data-is-deleted');
      if (isDeleted !== '1') {
        msg.classList.add('selection-mode');
      }
    });
    document.getElementById('selectionHeader').style.display = 'flex';
    document.querySelector('.chat-footer').style.display = 'none';
    updateSelectionCount();
  }
  
  function updateSelectionCount() {
    document.getElementById('selectionCount').innerText = selectedMessages.size + ' selected';
  }
  
  // Forward selected messages
  function forwardSelectedMessages() {
    if (selectedMessages.size === 0) return;
    const messageIds = Array.from(selectedMessages);
    showForwardModal(messageIds);
  }
  
  function showForwardModal(messageIds) {
    const modal = document.createElement('div');
    modal.className = 'forward-modal';
    modal.innerHTML = `
      <div class="forward-modal-content">
        <div class="forward-modal-header">
          <h3>Forward to...</h3>
          <button class="forward-modal-close">&times;</button>
        </div>
        <div class="forward-search">
          <input type="text" id="forwardSearchInput" placeholder="Search users...">
        </div>
        <div class="forward-user-list" id="forwardUserList">
          <div style="padding: 20px; text-align: center;">Loading conversations...</div>
        </div>
        <div class="forward-modal-footer">
          <button class="forward-send-btn" id="forwardSendBtn" disabled>Send (0)</button>
        </div>
      </div>
    `;
    
    document.body.appendChild(modal);
    
    let selectedUsers = new Set();
    let allUsers = [];
    
    function loadUsers() {
      fetch(`messages.php?get_conversation_users=1`)
        .then(response => response.json())
        .then(data => {
          allUsers = data;
          if (allUsers.length === 0) {
            document.getElementById('forwardUserList').innerHTML = '<div style="padding: 20px; text-align: center;">No conversations yet. Start a chat first!</div>';
          } else {
            renderUserList(allUsers);
          }
        })
        .catch(error => {
          console.error('Error loading users:', error);
          document.getElementById('forwardUserList').innerHTML = '<div style="padding: 20px; text-align: center;">Error loading users</div>';
        });
    }
    
    function renderUserList(users) {
      const container = document.getElementById('forwardUserList');
      if (users.length === 0) {
        container.innerHTML = '<div style="padding: 20px; text-align: center;">No users found</div>';
        return;
      }
      
      container.innerHTML = users.map(user => `
        <div class="forward-user-item ${selectedUsers.has(user.id) ? 'selected' : ''}" data-user-id="${user.id}">
          <img class="forward-user-avatar" src="assets/images/users/${user.img}" alt="">
          <div class="forward-user-info">
            <div class="forward-user-name">${escapeHtml(user.name)}</div>
            <div class="forward-user-username">@${escapeHtml(user.username)}</div>
          </div>
          <div class="forward-user-check">${selectedUsers.has(user.id) ? '✓' : ''}</div>
        </div>
      `).join('');
      
      container.querySelectorAll('.forward-user-item').forEach(item => {
        item.addEventListener('click', (e) => {
          e.stopPropagation();
          const userId = parseInt(item.dataset.userId);
          if (selectedUsers.has(userId)) {
            selectedUsers.delete(userId);
          } else {
            selectedUsers.add(userId);
          }
          renderUserList(allUsers);
          const sendBtn = document.getElementById('forwardSendBtn');
          sendBtn.disabled = selectedUsers.size === 0;
          sendBtn.innerText = `Send (${selectedUsers.size})`;
        });
      });
    }
    
    const searchInput = modal.querySelector('#forwardSearchInput');
    searchInput.addEventListener('input', function() {
      const query = this.value.toLowerCase();
      const filtered = allUsers.filter(user => 
        user.name.toLowerCase().includes(query) || 
        user.username.toLowerCase().includes(query)
      );
      renderUserList(filtered);
    });
    
    modal.querySelector('.forward-modal-close').addEventListener('click', () => modal.remove());
    modal.addEventListener('click', (e) => {
      if (e.target === modal) modal.remove();
    });
    
    document.getElementById('forwardSendBtn').addEventListener('click', () => {
      const recipients = Array.from(selectedUsers);
      if (recipients.length === 0) return;
      
      let promises = [];
      for (const msgId of messageIds) {
        promises.push(fetch(window.location.href, {
          method: 'POST',
          body: `forward_message=1&msg_id=${msgId}&recipients=${JSON.stringify(recipients)}`,
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
        }));
      }
      
      Promise.all(promises).then(() => {
        modal.remove();
        exitSelectionMode();
        showToast('Messages forwarded successfully!');
      });
    });
    
    loadUsers();
  }
  
  function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }
  
  function showToast(message) {
    const toast = document.createElement('div');
    toast.style.cssText = 'position:fixed; bottom:30px; left:50%; transform:translateX(-50%); background:rgba(0,0,0,0.8); color:white; padding:10px 20px; border-radius:30px; z-index:20000; font-size:14px;';
    toast.innerText = message;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
  }
  
  // --- REPLY LOGIC ---
  function setReply(msgId, username, text) {
    document.getElementById('replyToInput').value = msgId;
    document.getElementById('replyUser').innerText = "@" + username;
    document.getElementById('replySnippet').innerText = text.substring(0, 60);
    document.getElementById('replyPreview').style.display = 'block';
    document.getElementById('messageInput').focus();
  }
  
  function scrollToMessage(msgId) {
    const msgElement = document.querySelector(`.message-bubble[data-msg-id="${msgId}"]`);
    if (msgElement) {
      msgElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
      msgElement.classList.add('highlight-message');
      setTimeout(() => msgElement.classList.remove('highlight-message'), 2000);
    }
  }
  
  // --- Block User Handler ---
  const blockUserBtn = document.getElementById('blockUserBtn');
  const blockBtnText = document.getElementById('blockBtnText');
  
  if (blockUserBtn) {
    blockUserBtn.addEventListener('click', function() {
        const blockedId = <?php echo isset($receiver) ? $receiver : 0; ?>;
        const action = blockBtnText.innerText === 'Block User' ? 'block' : 'unblock';
        
        if (confirm(`Are you sure you want to ${action} this user?`)) {
            fetch(window.location.href, {
                method: 'POST',
                body: `toggle_block=1&blocked_id=${blockedId}`,
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
            })
            .then(response => response.text())
            .then(data => {
                if (data === 'blocked') {
                    blockBtnText.innerText = 'Unblock';
                    showToast('User blocked successfully');
                    window.location.reload();
                } else if (data === 'unblocked') {
                    blockBtnText.innerText = 'Block User';
                    showToast('User unblocked successfully');
                    window.location.reload();
                }
            })
            .catch(error => console.error('Error:', error));
        }
        const headerDropdownMenu = document.getElementById('headerDropdownMenu');
        if (headerDropdownMenu) headerDropdownMenu.classList.remove('show');
    });
  }
  
  (function() {
    // Emoji Picker - fixed with safe custom fallback
    const emojiBtn = document.getElementById('emojiBtn');
    const messageInputForEmoji = document.getElementById('messageInput');

    function insertEmojiIntoInput(emoji) {
      if (!messageInputForEmoji) return;

      const start = messageInputForEmoji.selectionStart ?? messageInputForEmoji.value.length;
      const end = messageInputForEmoji.selectionEnd ?? messageInputForEmoji.value.length;
      const currentValue = messageInputForEmoji.value;

      messageInputForEmoji.value =
        currentValue.substring(0, start) + emoji + currentValue.substring(end);

      const newPosition = start + emoji.length;
      messageInputForEmoji.focus();
      messageInputForEmoji.setSelectionRange(newPosition, newPosition);
    }

    function createCustomEmojiPicker() {
      let picker = document.getElementById('customEmojiPicker');
      if (picker) return picker;

      const emojis = [
        '😀','😃','😄','😁','😆','😅','😂','🤣',
        '😊','😇','🙂','🙃','😉','😍','😘','😗',
        '😙','😚','😋','😜','😝','😛','🤑','🤗',
        '🤔','🤐','🤨','😐','😑','😶','🙄','😏',
        '😣','😥','😮','🤐','😯','😪','😫','🥱',
        '😴','😌','😛','😜','😝','🤤','😒','😓',
        '😔','😕','🙃','🫠','🫡','🤭','🫢','🫣',
        '😎','🤓','🧐','😤','😡','😠','🤬','😳',
        '🥺','😢','😭','😱','😖','😞','😟','😤',
        '👍','👎','👌','✌️','🤞','🤟','🤘','🤙',
        '👋','👏','🙌','👐','🤲','🙏','💪','🤝',
        '❤️','🧡','💛','💚','💙','💜','🖤','🤍',
        '💔','❣️','💕','💞','💓','💗','💖','💘',
        '🔥','✨','🎉','🎊','💯','✅','❌','⭐'
      ];

      picker = document.createElement('div');
      picker.id = 'customEmojiPicker';
      picker.className = 'custom-emoji-picker';

      emojis.forEach(emoji => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.textContent = emoji;
        btn.addEventListener('click', function(e) {
          e.preventDefault();
          e.stopPropagation();
          insertEmojiIntoInput(emoji);
        });
        picker.appendChild(btn);
      });

      document.body.appendChild(picker);
      return picker;
    }

    function positionEmojiPicker(picker) {
      const rect = emojiBtn.getBoundingClientRect();
      const pickerWidth = 310;
      const pickerHeight = 260;

      let left = rect.left;
      let top = rect.top - pickerHeight - 10;

      if (left + pickerWidth > window.innerWidth - 10) {
        left = window.innerWidth - pickerWidth - 10;
      }
      if (left < 10) left = 10;

      if (top < 10) {
        top = rect.bottom + 10;
      }

      picker.style.left = left + 'px';
      picker.style.top = top + 'px';
    }

    if (emojiBtn) {
      let externalPickerReady = false;

      // Try external EmojiButton library first, but do not depend on it.
      if (typeof EmojiButton !== 'undefined') {
        try {
          const picker = new EmojiButton({
            position: 'top-start',
            rootElement: document.body
          });

          picker.on('emoji', selection => {
            insertEmojiIntoInput(selection.emoji || selection);
          });

          emojiBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            picker.togglePicker(emojiBtn);
          });

          externalPickerReady = true;
        } catch (error) {
          externalPickerReady = false;
          console.warn('EmojiButton failed, using custom emoji picker.', error);
        }
      }

      // Fallback picker works even if CDN/library does not load.
      if (!externalPickerReady) {
        const customPicker = createCustomEmojiPicker();

        emojiBtn.addEventListener('click', function(e) {
          e.preventDefault();
          e.stopPropagation();
          positionEmojiPicker(customPicker);
          customPicker.classList.toggle('show');
        });

        customPicker.addEventListener('click', function(e) {
          e.stopPropagation();
        });

        document.addEventListener('click', function(e) {
          if (!customPicker.contains(e.target) && e.target !== emojiBtn && !emojiBtn.contains(e.target)) {
            customPicker.classList.remove('show');
          }
        });

        window.addEventListener('resize', function() {
          if (customPicker.classList.contains('show')) {
            positionEmojiPicker(customPicker);
          }
        });
      }
    }
    
    // Cancel Reply
    const cancelReplyBtn = document.getElementById('cancelReplyBtn');
    if (cancelReplyBtn) {
      cancelReplyBtn.addEventListener('click', () => {
        document.getElementById('replyToInput').value = '';
        document.getElementById('replyPreview').style.display = 'none';
      });
    }
    
    // Live Search Functionality
    const searchInput = document.getElementById('liveSearchInput');
    const searchDropdown = document.getElementById('searchDropdown');
    const searchFilter = document.getElementById('searchFilter');
    let searchTimeout;
    
    if (searchInput) {
      searchInput.addEventListener('input', function() {
        const query = this.value.trim();
        clearTimeout(searchTimeout);
        if (query.length === 0) {
          searchDropdown.classList.remove('show');
          return;
        }
        searchTimeout = setTimeout(() => {
          const filter = searchFilter.value;
          fetch(`messages.php?ajax_search=1&query=${encodeURIComponent(query)}&filter=${filter}`)
            .then(response => response.json())
            .then(data => {
              searchDropdown.innerHTML = '';
              if (data.length === 0) {
                const emptyDiv = document.createElement('div');
                emptyDiv.className = 'search-dropdown-empty';
                emptyDiv.innerHTML = '<i class="fas fa-user-slash"></i> No users found';
                searchDropdown.appendChild(emptyDiv);
              } else {
                data.forEach(user => {
                  const item = document.createElement('div');
                  item.className = 'search-dropdown-item';
                  item.innerHTML = `
                    <img class="search-dropdown-avatar" src="assets/images/users/${user.img}" alt="">
                    <div class="search-dropdown-info">
                      <div class="search-dropdown-username">
                        ${escapeHtml(user.username)}
                        ${user.is_verified ? '<span class="search-dropdown-verified">✔️</span>' : ''}
                      </div>
                      <div class="search-dropdown-name">${escapeHtml(user.name)}</div>
                    </div>
                  `;
                  item.addEventListener('click', () => {
                    window.location.href = `messages.php?user=${user.id}&filter=${filter}`;
                  });
                  searchDropdown.appendChild(item);
                });
              }
              searchDropdown.classList.add('show');
            })
            .catch(error => console.error('Search error:', error));
        }, 300);
      });
      
      document.addEventListener('click', function(e) {
        if (!searchInput.contains(e.target) && !searchDropdown.contains(e.target)) {
          searchDropdown.classList.remove('show');
        }
      });
      
      searchDropdown.addEventListener('click', function(e) {
        e.stopPropagation();
      });
    }
    
    // Filter tabs
    const filterTabs = document.querySelectorAll('.filter-tab');
    filterTabs.forEach(tab => {
      tab.addEventListener('click', function() {
        const filter = this.getAttribute('data-filter');
        const url = new URL(window.location.href);
        url.searchParams.set('filter', filter);
        url.searchParams.delete('user');
        window.location.href = url.toString();
      });
    });
    
    // Chat item clicks
    document.querySelectorAll('.chat-item').forEach(item => {
      const menuBtn = item.querySelector('.chat-menu-btn');
      item.addEventListener('click', function(e) {
        if (menuBtn && menuBtn.contains(e.target)) return;
        const userId = this.getAttribute('data-userid');
        if(userId) window.location.href = `messages.php?user=${userId}&filter=<?php echo $filterType; ?>`;
      });
    });
    
    // Menu button for recent chats
    let activeChatMenu = null;
    
    function showChatContextMenu(event, chatItem, userId, username, menuBtn) {
      event.preventDefault();
      event.stopPropagation();
      if (activeChatMenu) activeChatMenu.remove();
      
      const menu = document.createElement('div');
      menu.className = 'chat-context-menu';
      const deleteItem = document.createElement('div');
      deleteItem.className = 'chat-menu-item';
      deleteItem.innerHTML = '<i class="fas fa-trash-alt"></i> Remove from recents';
      deleteItem.onclick = (e) => {
        e.stopPropagation();
        if (confirm(`Remove ${username} from recent chats?`)) {
          fetch(window.location.href, {
            method: 'POST',
            body: `remove_chat=1&chat_user_id=${userId}`,
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
          })
          .then(response => response.text())
          .then(data => {
            if (data === 'removed') {
              chatItem.style.transition = 'all 0.3s ease';
              chatItem.style.opacity = '0';
              chatItem.style.transform = 'translateX(-20px)';
              setTimeout(() => {
                chatItem.remove();
                const container = document.getElementById('recentChatsContainer');
                if (container.children.length === 0 || (container.children.length === 1 && container.children[0].classList.contains('chat-item') === false)) {
                  container.innerHTML = '<div style="padding: 20px; text-align: center; color: #8696a0; font-size: 13px;">No chats yet. Start a conversation!</div>';
                }
              }, 300);
            }
          })
          .catch(error => console.error('Error:', error));
        }
        menu.remove();
        activeChatMenu = null;
      };
      menu.appendChild(deleteItem);
      
      document.body.appendChild(menu);
      const rect = menuBtn.getBoundingClientRect();
      menu.style.position = 'fixed';
      menu.style.left = `${rect.left + window.scrollX - 100}px`;
      menu.style.top = `${rect.bottom + window.scrollY + 5}px`;
      activeChatMenu = menu;
      
      const closeMenu = (e) => {
        if (!menu.contains(e.target)) {
          menu.remove();
          activeChatMenu = null;
          document.removeEventListener('click', closeMenu);
        }
      };
      setTimeout(() => document.addEventListener('click', closeMenu), 10);
    }
    
    document.querySelectorAll('.chat-menu-btn').forEach(btn => {
      btn.addEventListener('click', (e) => {
        e.stopPropagation();
        const chatItem = btn.closest('.chat-item');
        const userId = btn.getAttribute('data-userid');
        const username = btn.getAttribute('data-username');
        if (chatItem && userId) {
          showChatContextMenu(e, chatItem, userId, username, btn);
        }
      });
    });
    
    // Chat header three-dot menu
    const chatHeaderMenuBtn = document.getElementById('chatHeaderMenuBtn');
    const headerDropdownMenu = document.getElementById('headerDropdownMenu');
    const clearChatBtn = document.getElementById('clearChatBtn');
    
    if (chatHeaderMenuBtn) {
      chatHeaderMenuBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        headerDropdownMenu.classList.toggle('show');
      });
      
      document.addEventListener('click', function(e) {
        if (!chatHeaderMenuBtn.contains(e.target) && !headerDropdownMenu.contains(e.target)) {
          headerDropdownMenu.classList.remove('show');
        }
      });
    }
    
    if (clearChatBtn) {
      clearChatBtn.addEventListener('click', function() {
        const chatWithId = <?php echo isset($receiver) ? $receiver : 0; ?>;
        const chatWithName = "<?php echo isset($receiverData) ? addslashes($receiverData->name) : ''; ?>";
        if (confirm(`Clear chat with ${chatWithName}? This will only clear messages on your side.`)) {
          fetch(window.location.href, {
            method: 'POST',
            body: `clear_chat=1&chat_with_id=${chatWithId}`,
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
          })
          .then(response => response.text())
          .then(data => {
            if (data === 'cleared') {
              window.location.reload();
            }
          })
          .catch(error => console.error('Error:', error));
        }
        headerDropdownMenu.classList.remove('show');
      });
    }
    
    // Selection mode handlers
    const cancelSelectionBtn = document.getElementById('cancelSelectionBtn');
    const forwardSelectedBtn = document.getElementById('forwardSelectedBtn');
    
    if (cancelSelectionBtn) {
      cancelSelectionBtn.addEventListener('click', exitSelectionMode);
    }
    
    if (forwardSelectedBtn) {
      forwardSelectedBtn.addEventListener('click', forwardSelectedMessages);
    }
    
    // Long press for selection mode on mobile
    let pressTimer = null;
    let pressedMessage = null;
    
    document.querySelectorAll('.message-bubble').forEach(msg => {
      const isDeleted = msg.getAttribute('data-is-deleted');
      msg.addEventListener('touchstart', (e) => {
        if (isDeleted === '1') return;
        pressTimer = setTimeout(() => {
          pressedMessage = msg;
          if (!isSelectionMode) {
            enterSelectionMode();
          }
          const msgId = msg.getAttribute('data-msg-id');
          if (selectedMessages.has(msgId)) {
            selectedMessages.delete(msgId);
            msg.classList.remove('selected');
          } else {
            selectedMessages.add(msgId);
            msg.classList.add('selected');
          }
          updateSelectionCount();
        }, 500);
      });
      msg.addEventListener('touchend', () => clearTimeout(pressTimer));
      msg.addEventListener('touchmove', () => clearTimeout(pressTimer));
    });
    
    // Message menu (right click / click)
    let activeMessageMenu = null;
    
    function showMessageMenu(event, msgElement, msgId, isSender, isDeleted) {
      event.stopPropagation();
      if (activeMessageMenu) activeMessageMenu.remove();
      if (isDeleted === '1') return;

      const menu = document.createElement('div');
      menu.className = 'message-menu';

      // Copy option
      const copyItem = document.createElement('div');
      copyItem.className = 'menu-item';
      copyItem.innerHTML = '<i class="fas fa-copy"></i> Copy';
      copyItem.onclick = () => {
        navigator.clipboard.writeText(msgElement.innerText.replace(/📌|🔖|Forwarded/g,'').trim());
        menu.remove();
      };
      menu.appendChild(copyItem);

      // Download Option
      const pathsAttr = msgElement.getAttribute('data-paths');
      const namesAttr = msgElement.getAttribute('data-names');
      const paths = pathsAttr ? JSON.parse(pathsAttr) : [];
      const names = namesAttr ? JSON.parse(namesAttr) : [];
      
      if (paths.length > 0) {
        const downloadItem = document.createElement('div');
        downloadItem.className = 'menu-item';
        const label = paths.length > 1 ? `Download All (${paths.length})` : 'Download Media';
        downloadItem.innerHTML = `<i class="fas fa-download"></i> ${label}`;
        downloadItem.onclick = () => {
          paths.forEach((path, index) => {
            setTimeout(() => {
              const link = document.createElement('a');
              link.href = path;
              link.download = names[index] || 'download';
              document.body.appendChild(link);
              link.click();
              document.body.removeChild(link);
            }, index * 200);
          });
          menu.remove();
        };
        menu.appendChild(downloadItem);
      }

      // Reply Option
      const replyItem = document.createElement('div');
      replyItem.className = 'menu-item';
      replyItem.innerHTML = '<i class="fas fa-reply"></i> Reply';
      replyItem.onclick = () => {
        const textElement = msgElement.querySelector('.text-content');
        let replyText = textElement ? textElement.innerText : '';
        if (!replyText && msgElement.querySelector('.media-grid')) {
          const fileNames = names.length > 0 ? names.join(', ') : '';
          replyText = fileNames ? `[Media: ${fileNames.substring(0, 30)}]` : '[Media]';
        }
        const senderId = msgElement.getAttribute('data-sender');
        const isCurrentUserSender = senderId && parseInt(senderId) === <?= json_encode($user_id) ?>;
        const replyUsername = isCurrentUserSender ? 'You' : "<?= htmlspecialchars($receiverData->username ?? 'User') ?>";
        setReply(msgId, replyUsername, replyText);
        menu.remove();
      };
      menu.appendChild(replyItem);

      // Forward option
      const forwardItem = document.createElement('div');
      forwardItem.className = 'menu-item';
      forwardItem.innerHTML = '<i class="fas fa-share"></i> Forward';
      forwardItem.onclick = () => {
        menu.remove();
        if (!isSelectionMode) {
          enterSelectionMode();
        }
        if (!selectedMessages.has(msgId)) {
          selectedMessages.add(msgId);
          msgElement.classList.add('selected');
          updateSelectionCount();
        }
        forwardSelectedMessages();
      };
      menu.appendChild(forwardItem);
      
      // Select option
      const selectItem = document.createElement('div');
      selectItem.className = 'menu-item';
      selectItem.innerHTML = '<i class="fas fa-check-square"></i> Select';
      selectItem.onclick = () => {
        menu.remove();
        if (!isSelectionMode) {
          enterSelectionMode();
        }
        if (selectedMessages.has(msgId)) {
          selectedMessages.delete(msgId);
          msgElement.classList.remove('selected');
        } else {
          selectedMessages.add(msgId);
          msgElement.classList.add('selected');
        }
        updateSelectionCount();
      };
      menu.appendChild(selectItem);
      
      // Pin option
      const pinItem = document.createElement('div');
      pinItem.className = 'menu-item';
      const isPinned = msgElement.classList.contains('pinned-message');
      pinItem.innerHTML = `<i class="fas fa-thumbtack"></i> ${isPinned ? 'Unpin' : 'Pin'}`;
      pinItem.onclick = () => {
        fetch(window.location.href, {
          method: 'POST',
          body: `pin_msg=1&msg_id=${msgId}`,
          headers: {'Content-Type': 'application/x-www-form-urlencoded'}
        }).then(() => location.reload());
        menu.remove();
      };
      menu.appendChild(pinItem);
      
      // Delete options
      if (isSender) {
        const deleteForEveryone = document.createElement('div');
        deleteForEveryone.className = 'menu-item danger';
        deleteForEveryone.innerHTML = '<i class="fas fa-trash-alt"></i> Delete for everyone';
        deleteForEveryone.onclick = () => {
          if (confirm('Delete this message for everyone? This action cannot be undone.')) {
            fetch(window.location.href, {
              method: 'POST',
              body: `delete_for_everyone=1&msg_id=${msgId}`,
              headers: {'Content-Type': 'application/x-www-form-urlencoded'}
            }).then(() => location.reload());
          }
          menu.remove();
        };
        menu.appendChild(deleteForEveryone);
        
        const deleteForMe = document.createElement('div');
        deleteForMe.className = 'menu-item';
        deleteForMe.innerHTML = '<i class="fas fa-user-slash"></i> Delete for me';
        deleteForMe.onclick = () => {
          if (confirm('Delete this message only for yourself? The other person will still see it.')) {
            fetch(window.location.href, {
              method: 'POST',
              body: `delete_for_me=1&msg_id=${msgId}`,
              headers: {'Content-Type': 'application/x-www-form-urlencoded'}
            }).then(() => location.reload());
          }
          menu.remove();
        };
        menu.appendChild(deleteForMe);
      } else {
        const deleteForMe = document.createElement('div');
        deleteForMe.className = 'menu-item';
        deleteForMe.innerHTML = '<i class="fas fa-user-slash"></i> Delete for me';
        deleteForMe.onclick = () => {
          if (confirm('Delete this message only for yourself? The other person will still see it.')) {
            fetch(window.location.href, {
              method: 'POST',
              body: `delete_for_me=1&msg_id=${msgId}`,
              headers: {'Content-Type': 'application/x-www-form-urlencoded'}
            }).then(() => location.reload());
          }
          menu.remove();
        };
        menu.appendChild(deleteForMe);
      }
      
      document.body.appendChild(menu);
      const rect = msgElement.getBoundingClientRect();
      menu.style.left = `${rect.left + window.scrollX}px`;
      menu.style.top = `${rect.top + window.scrollY - 10}px`;
      activeMessageMenu = menu;
      
      const close = (e) => {
        if (!menu.contains(e.target)) {
          menu.remove();
          document.removeEventListener('click', close);
        }
      };
      setTimeout(() => document.addEventListener('click', close), 10);
    }
    
    document.querySelectorAll('.message-bubble').forEach(msg => {
      const isDeleted = msg.getAttribute('data-is-deleted');
      const msgId = msg.getAttribute('data-msg-id');
      const sender = msg.getAttribute('data-sender');
      const isSender = sender && parseInt(sender) === <?= json_encode($user_id) ?>;
      
      msg.addEventListener('click', (e) => {
        if (isSelectionMode) {
          e.stopPropagation();
          if (selectedMessages.has(msgId)) {
            selectedMessages.delete(msgId);
            msg.classList.remove('selected');
          } else {
            selectedMessages.add(msgId);
            msg.classList.add('selected');
          }
          updateSelectionCount();
        } else {
          showMessageMenu(e, msg, msgId, isSender, isDeleted);
        }
      });
    });
    
    // Attachment Preview Logic
    const fileInput = document.getElementById('fileInput');
    const attachmentPreview = document.getElementById('attachmentPreview');
    const previewMedia = document.getElementById('previewMedia');
    const previewName = document.getElementById('previewName');
    const removePreviewBtn = document.getElementById('removePreviewBtn');
    const messageInput = document.getElementById('messageInput');
    
    if (fileInput) {
      fileInput.addEventListener('change', function() {
        const files = Array.from(this.files);
        if (voiceDurationInput) voiceDurationInput.value = '';
        if (voiceWaveformInput) voiceWaveformInput.value = '';
        previewMedia.innerHTML = '';
        
        if (files.length > 0) {
          files.forEach((file) => {
            const item = document.createElement('div');
            item.className = 'preview-item';
            if (file.type.startsWith('image/')) {
              const reader = new FileReader();
              reader.onload = function(e) {
                item.innerHTML = `<img src="${e.target.result}" style="width:60px; height:60px; object-fit:cover; border-radius:8px;">`;
              }
              reader.readAsDataURL(file);
            } else if (file.type.startsWith('video/')) {
              item.innerHTML = `<div style="width:60px; height:60px; background:#eee; display:flex; align-items:center; justify-content:center; border-radius:8px;"><i class="fas fa-video fa-2x" style="color:#FF9800;"></i></div>`;
            } else if (file.type.startsWith('audio/')) {
              const audioUrl = URL.createObjectURL(file);
              item.style.display = 'block';
              item.innerHTML = `
                <div class="voice-preview-box" style="min-width:280px;">
                  <button type="button" class="voice-mini-btn voice-stop-btn upload-audio-play"><i class="fas fa-play"></i></button>
                  <audio class="voice-preview-audio" preload="metadata" src="${audioUrl}"></audio>
                  <span class="voice-preview-timer upload-audio-duration">0:00</span>
                  <button type="button" class="voice-speed-btn upload-audio-speed" data-speed="1">1x</button>
                </div>`;

              const audio = item.querySelector('audio');
              const playBtn = item.querySelector('.upload-audio-play');
              const icon = playBtn.querySelector('i');
              const durationBox = item.querySelector('.upload-audio-duration');
              const speedBtn = item.querySelector('.upload-audio-speed');

              audio.addEventListener('loadedmetadata', function() {
                if (durationBox && audio.duration && isFinite(audio.duration)) {
                  durationBox.dataset.totalDuration = String(audio.duration);
                  durationBox.textContent = formatVoiceTime(audio.duration);
                  if (files.length === 1 && voiceDurationInput) voiceDurationInput.value = Math.max(1, Math.round(audio.duration));
                  if (files.length === 1 && voiceWaveformInput) voiceWaveformInput.value = makeFakeWaveform();
                }
              });

              audio.addEventListener('timeupdate', function() {
                if (durationBox && audio.duration && isFinite(audio.duration) && !audio.paused) {
                  durationBox.textContent = formatVoiceTime(audio.currentTime);
                }
              });

              audio.addEventListener('pause', function() {
                if (durationBox && audio.duration && isFinite(audio.duration) && !audio.ended) {
                  durationBox.textContent = audio.currentTime > 0 ? formatVoiceTime(audio.currentTime) : formatVoiceTime(audio.duration);
                }
              });

              playBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                if (audio.paused) {
                  audio.play();
                  icon.className = 'fas fa-pause';
                } else {
                  audio.pause();
                  icon.className = 'fas fa-play';
                }
              });

              audio.addEventListener('ended', function() {
                icon.className = 'fas fa-play';
                audio.currentTime = 0;
                if (durationBox && audio.duration && isFinite(audio.duration)) {
                  durationBox.textContent = formatVoiceTime(audio.duration);
                }
              });

              speedBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                const speeds = [1, 2, 3];
                const current = parseFloat(speedBtn.dataset.speed || '1');
                const next = speeds[(speeds.indexOf(current) + 1) % speeds.length] || 1;
                audio.playbackRate = next;
                speedBtn.dataset.speed = String(next);
                speedBtn.textContent = next + 'x';
                speedBtn.classList.toggle('active', next !== 1);
              });
            } else {
              const ext = (file.name.split('.').pop() || 'file').toUpperCase();
              item.innerHTML = `<div style="width:60px; height:60px; background:linear-gradient(135deg, #667eea 0%, #764ba2 100%); display:flex; flex-direction:column; align-items:center; justify-content:center; border-radius:8px; color:white;"><i class="fas fa-file-alt fa-2x"></i><span style="font-size:8px; margin-top:2px;">${ext}</span></div>`;
            }
            previewMedia.appendChild(item);
          });
          previewName.innerText = files.length + " file(s) selected";
          attachmentPreview.style.display = 'flex';
          if (messageInput) messageInput.focus();
        }
      });
    }
    
    if (removePreviewBtn) {
      removePreviewBtn.addEventListener('click', function() {
        if (fileInput) fileInput.value = '';
        resetVoiceRecording(false);
        attachmentPreview.style.display = 'none';
        previewMedia.innerHTML = '';
        if (previewName) previewName.innerText = '';
      });
    }
    
    // Attachment menu logic
    const attachBtn = document.getElementById('attachBtn');
    let attachmentMenu = null;
    
    function removeAttachmentMenu() {
      if(attachmentMenu) {
        attachmentMenu.remove();
        attachmentMenu = null;
      }
    }
    
    if(attachBtn) {
      attachBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        removeAttachmentMenu();
        
        const menu = document.createElement('div');
        menu.className = 'attachment-menu';
        
        const types = [
          { name: 'Photos & Videos', icon: 'fas fa-photo-video', accept: 'image/*,video/*' },
          { name: 'Photo', icon: 'fas fa-image', accept: 'image/*' },
          { name: 'Video', icon: 'fas fa-video', accept: 'video/*' },
          { name: 'Document', icon: 'fas fa-file-alt', accept: '.pdf,.doc,.docx,.txt,.xls,.xlsx,.zip' },
          { name: 'Audio file', icon: 'fas fa-microphone', accept: 'audio/*' }
        ];
        
        types.forEach(t => {
          const item = document.createElement('div');
          item.className = 'attachment-item';
          item.innerHTML = `<i class="${t.icon}"></i> ${t.name}`;
          item.addEventListener('click', () => {
            if (fileInput) {
              fileInput.accept = t.accept;
              fileInput.click();
            }
            removeAttachmentMenu();
          });
          menu.appendChild(item);
        });
        
        document.body.appendChild(menu);
        const rect = attachBtn.getBoundingClientRect();
        menu.style.position = 'absolute';
        menu.style.bottom = `${window.innerHeight - rect.top + 10}px`;
        menu.style.left = `${rect.left}px`;
        attachmentMenu = menu;
        
        const closeHandler = (e) => {
          if(!menu.contains(e.target) && e.target !== attachBtn) {
            removeAttachmentMenu();
            document.removeEventListener('click', closeHandler);
          }
        };
        setTimeout(() => document.addEventListener('click', closeHandler), 10);
      });
    }
    

    // Voice message playback with moving dot + progressive waveform fill
    document.querySelectorAll('.voice-message-player').forEach(player => {
      const btn = player.querySelector('.voice-play-btn');
      const audio = player.querySelector('audio');
      const icon = btn ? btn.querySelector('i') : null;
      const wave = player.querySelector('.voice-wave');
      const bars = wave ? Array.from(wave.querySelectorAll('span')) : [];
      const durationText = player.querySelector('.voice-duration');
      const speedBtn = player.querySelector('.voice-speed-btn');
      let dot = wave ? wave.querySelector('.voice-progress-dot') : null;

      if (!btn || !audio || !icon || !wave) return;
      if (!dot) {
        dot = document.createElement('div');
        dot.className = 'voice-progress-dot';
        wave.appendChild(dot);
      }

      function resetVoiceProgress(targetPlayer) {
        const targetWave = targetPlayer.querySelector('.voice-wave');
        const targetBars = targetWave ? targetWave.querySelectorAll('span') : [];
        const targetDot = targetWave ? targetWave.querySelector('.voice-progress-dot') : null;
        targetPlayer.classList.remove('playing', 'has-progress');
        targetBars.forEach(bar => bar.classList.remove('played'));
        if (targetDot) targetDot.style.left = '0%';
      }

      function updateVoiceProgress() {
        const duration = audio.duration || 0;
        const percent = duration ? Math.min(100, Math.max(0, (audio.currentTime / duration) * 100)) : 0;
        const playedBars = Math.round((percent / 100) * bars.length);

        if (dot) dot.style.left = percent + '%';
        bars.forEach((bar, index) => {
          bar.classList.toggle('played', index < playedBars);
        });

        if (percent > 0) player.classList.add('has-progress');
        if (durationText && !audio.paused && duration && isFinite(duration)) {
          durationText.textContent = formatVoiceTime(audio.currentTime);
        }
      }

      btn.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        document.querySelectorAll('.voice-message-player audio').forEach(other => {
          if (other !== audio) {
            other.pause();
            const otherPlayer = other.closest('.voice-message-player');
            if (otherPlayer) {
              otherPlayer.classList.remove('playing');
              const otherIcon = otherPlayer.querySelector('.voice-play-btn i');
              if (otherIcon) otherIcon.className = 'fas fa-play';
            }
          }
        });

        if (audio.paused) {
          audio.play();
          player.classList.add('playing');
          icon.className = 'fas fa-pause';
          updateVoiceProgress();
        } else {
          audio.pause();
          player.classList.remove('playing');
          icon.className = 'fas fa-play';
        }
      });

      if (speedBtn) {
        speedBtn.addEventListener('click', function(e) {
          e.preventDefault();
          e.stopPropagation();
          const speeds = [1, 2, 3];
          const current = parseFloat(speedBtn.dataset.speed || '1');
          const next = speeds[(speeds.indexOf(current) + 1) % speeds.length] || 1;
          audio.playbackRate = next;
          speedBtn.dataset.speed = String(next);
          speedBtn.textContent = next + 'x';
          speedBtn.classList.toggle('active', next !== 1);
        });
      }

      function setRealDuration() {
        if (durationText && audio.duration && isFinite(audio.duration)) {
          durationText.textContent = formatVoiceTime(audio.duration);
        }
      }

      wave.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        const duration = audio.duration || 0;
        if (!duration) return;
        const rect = wave.getBoundingClientRect();
        const percent = Math.min(1, Math.max(0, (e.clientX - rect.left) / rect.width));
        audio.currentTime = duration * percent;
        updateVoiceProgress();
      });

      audio.addEventListener('timeupdate', updateVoiceProgress);
      audio.addEventListener('loadedmetadata', function() {
        setRealDuration();
        updateVoiceProgress();
      });
      audio.addEventListener('pause', function() {
        if (!audio.ended) player.classList.remove('playing');
        if (durationText && audio.duration && isFinite(audio.duration)) {
          durationText.textContent = audio.currentTime > 0 ? formatVoiceTime(audio.currentTime) : formatVoiceTime(audio.duration);
        }
      });
      audio.addEventListener('ended', function() {
        icon.className = 'fas fa-play';
        audio.currentTime = 0;
        resetVoiceProgress(player);
        setRealDuration();
      });
    });

    // Voice recorder logic
    const voiceRecordBtn = document.getElementById('voiceRecordBtn');
    const voiceDurationInput = document.getElementById('voiceDurationInput');
    const voiceWaveformInput = document.getElementById('voiceWaveformInput');
    const mainChatForm = document.getElementById('mainChatForm');
    let mediaRecorder = null;
    let voiceChunks = [];
    let voiceStream = null;
    let voiceStartedAt = 0;
    let voiceTimer = null;
    let recordedVoiceFile = null;
    let voicePaused = false;
    let voicePausedAt = 0;
    let voicePausedTotal = 0;
    let voiceAudioContext = null;
    let voiceAnalyser = null;
    let voiceDataArray = null;

    function formatVoiceTime(seconds) {
      seconds = Math.max(0, Math.floor(seconds || 0));
      const m = Math.floor(seconds / 60);
      const s = seconds % 60;
      return m + ':' + String(s).padStart(2, '0');
    }

    function makeFakeWaveform() {
      const bars = [];
      for (let i = 0; i < 28; i++) bars.push(8 + Math.floor(Math.random() * 24));
      return bars.join(',');
    }

    function drawWaveBars(container, live) {
      container.innerHTML = '';
      for (let i = 0; i < 28; i++) {
        const span = document.createElement('span');
        span.style.height = (live ? (8 + ((Date.now() / 80 + i * 5) % 24)) : (8 + ((i * 7) % 22))) + 'px';
        container.appendChild(span);
      }
    }

    function resetVoiceRecording(clearFileInput = true) {
      if (voiceTimer) clearInterval(voiceTimer);
      voiceTimer = null;
      if (voiceStream) voiceStream.getTracks().forEach(track => track.stop());
      voiceStream = null;
      if (voiceAudioContext) {
        try { voiceAudioContext.close(); } catch (e) {}
      }
      voiceAudioContext = null;
      voiceAnalyser = null;
      voiceDataArray = null;
      voicePaused = false;
      voicePausedAt = 0;
      voicePausedTotal = 0;
      voiceChunks = [];
      recordedVoiceFile = null;
      if (voiceRecordBtn) {
        voiceRecordBtn.classList.remove('recording');
        voiceRecordBtn.innerHTML = '<i class="fas fa-microphone"></i>';
      }
      if (voiceDurationInput) voiceDurationInput.value = '';
      if (voiceWaveformInput) voiceWaveformInput.value = '';
      if (clearFileInput && fileInput) fileInput.value = '';
    }

    function getRecordingSeconds() {
      let now = Date.now();
      let pausedExtra = voicePaused && voicePausedAt ? (now - voicePausedAt) : 0;
      return Math.max(0, Math.floor((now - voiceStartedAt - voicePausedTotal - pausedExtra) / 1000));
    }

    function setupVoiceAnalyser(stream) {
      try {
        const AudioCtx = window.AudioContext || window.webkitAudioContext;
        if (!AudioCtx) return;
        voiceAudioContext = new AudioCtx();
        const source = voiceAudioContext.createMediaStreamSource(stream);
        voiceAnalyser = voiceAudioContext.createAnalyser();
        voiceAnalyser.fftSize = 64;
        voiceAnalyser.smoothingTimeConstant = 0.72;
        source.connect(voiceAnalyser);
        voiceDataArray = new Uint8Array(voiceAnalyser.frequencyBinCount);
      } catch (e) {
        voiceAudioContext = null;
        voiceAnalyser = null;
        voiceDataArray = null;
      }
    }

    function showRecordingPreview() {
      if (!attachmentPreview || !previewMedia || !previewName) return;
      previewMedia.innerHTML = `
        <div class="voice-preview-box recording-live" id="voicePreviewBox">
          <span class="voice-record-dot"></span>
          <span class="voice-preview-status" id="voicePreviewStatus">Recording</span>
          <div class="voice-wave" id="voiceLiveWave">
            <div class="voice-progress-dot" id="voiceLiveDot"></div>
          </div>
          <span class="voice-preview-timer" id="voiceTimerText">0:00</span>
          <div class="voice-preview-actions">
            <button type="button" class="voice-mini-btn voice-pause-btn" id="pauseVoiceBtn" title="Pause"><i class="fas fa-pause"></i></button>
            <button type="button" class="voice-mini-btn voice-stop-btn" id="stopVoiceBtn" title="Stop"><i class="fas fa-stop"></i></button>
            <button type="button" class="voice-mini-btn voice-cancel-btn" id="cancelVoiceBtn" title="Cancel"><i class="fas fa-times"></i></button>
          </div>
        </div>`;
      previewName.innerText = 'Voice message';
      attachmentPreview.style.display = 'flex';

      const box = document.getElementById('voicePreviewBox');
      const wave = document.getElementById('voiceLiveWave');
      const timerText = document.getElementById('voiceTimerText');
      const statusText = document.getElementById('voicePreviewStatus');
      const liveDot = document.getElementById('voiceLiveDot');
      const pauseBtn = document.getElementById('pauseVoiceBtn');
      const pauseIcon = pauseBtn ? pauseBtn.querySelector('i') : null;
      let liveStep = 0;

      function buildLiveBars() {
        if (!wave) return [];
        wave.querySelectorAll('span').forEach(el => el.remove());
        const bars = [];
        for (let i = 0; i < 32; i++) {
          const span = document.createElement('span');
          span.style.height = '10px';
          wave.appendChild(span);
          bars.push(span);
        }
        if (liveDot) wave.appendChild(liveDot);
        return bars;
      }

      const liveBars = buildLiveBars();

      const updateLive = () => {
        const elapsed = getRecordingSeconds();
        if (timerText) timerText.innerText = formatVoiceTime(elapsed);
        if (!wave || !liveBars.length) return;

        if (!voicePaused && voiceAnalyser && voiceDataArray) {
          voiceAnalyser.getByteFrequencyData(voiceDataArray);
        }

        if (!voicePaused) liveStep = (liveStep + 1) % liveBars.length;
        liveBars.forEach((bar, i) => {
          let h = 8 + ((i * 7 + liveStep * 3) % 18);
          if (!voicePaused && voiceAnalyser && voiceDataArray) {
            const v = voiceDataArray[i % voiceDataArray.length] || 0;
            h = 6 + Math.round((v / 255) * 30);
          }
          bar.style.height = Math.max(6, Math.min(34, h)) + 'px';
          bar.classList.toggle('live-played', i <= liveStep);
        });
        if (liveDot) {
          const progress = liveBars.length > 1 ? liveStep / (liveBars.length - 1) : 0;
          liveDot.style.left = (progress * 100) + '%';
        }
      };
      updateLive();
      voiceTimer = setInterval(updateLive, 120);

      if (pauseBtn) {
        pauseBtn.addEventListener('click', function(e) {
          e.preventDefault();
          e.stopPropagation();
          if (!mediaRecorder) return;
          if (mediaRecorder.state === 'recording') {
            try { mediaRecorder.pause(); } catch (err) {}
            voicePaused = true;
            voicePausedAt = Date.now();
            if (box) box.classList.add('recording-paused');
            if (statusText) statusText.textContent = 'Paused';
            if (pauseIcon) pauseIcon.className = 'fas fa-play';
            pauseBtn.title = 'Resume';
            if (voiceAudioContext && voiceAudioContext.state === 'running') {
              try { voiceAudioContext.suspend(); } catch (err) {}
            }
          } else if (mediaRecorder.state === 'paused') {
            try { mediaRecorder.resume(); } catch (err) {}
            if (voicePausedAt) voicePausedTotal += (Date.now() - voicePausedAt);
            voicePaused = false;
            voicePausedAt = 0;
            if (box) box.classList.remove('recording-paused');
            if (statusText) statusText.textContent = 'Recording';
            if (pauseIcon) pauseIcon.className = 'fas fa-pause';
            pauseBtn.title = 'Pause';
            if (voiceAudioContext && voiceAudioContext.state === 'suspended') {
              try { voiceAudioContext.resume(); } catch (err) {}
            }
          }
        });
      }

      document.getElementById('stopVoiceBtn').addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        if (mediaRecorder && (mediaRecorder.state === 'recording' || mediaRecorder.state === 'paused')) mediaRecorder.stop();
      });
      document.getElementById('cancelVoiceBtn').addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        if (mediaRecorder && (mediaRecorder.state === 'recording' || mediaRecorder.state === 'paused')) mediaRecorder.stop();
        resetVoiceRecording(true);
        attachmentPreview.style.display = 'none';
        previewMedia.innerHTML = '';
        previewName.innerText = '';
      });
    }

    function showRecordedVoicePreview(blob, duration) {
      if (!attachmentPreview || !previewMedia || !previewName || !fileInput) return;
      const audioUrl = URL.createObjectURL(blob);
      const ext = blob.type.includes('ogg') ? 'ogg' : 'webm';
      recordedVoiceFile = new File([blob], `voice_message_${Date.now()}.${ext}`, { type: blob.type || 'audio/webm' });
      const dt = new DataTransfer();
      dt.items.add(recordedVoiceFile);
      fileInput.files = dt.files;
      if (voiceDurationInput) voiceDurationInput.value = duration;
      if (voiceWaveformInput) voiceWaveformInput.value = makeFakeWaveform();

      previewMedia.innerHTML = `
        <div class="voice-preview-box">
          <button type="button" class="voice-mini-btn voice-stop-btn" id="previewVoicePlay"><i class="fas fa-play"></i></button>
          <audio class="voice-preview-audio" preload="metadata" src="${audioUrl}"></audio>
          <div class="voice-wave" id="recordedPreviewWave">
            ${makeFakeWaveform().split(',').map(h => `<span style="height:${h}px"></span>`).join('')}
            <div class="voice-progress-dot"></div>
          </div>
          <span class="voice-preview-timer" id="recordedPreviewTimer">${formatVoiceTime(duration)}</span>
        </div>`;
      previewName.innerText = 'Voice message ready';
      attachmentPreview.style.display = 'flex';

      const previewAudio = previewMedia.querySelector('audio');
      const previewPlay = document.getElementById('previewVoicePlay');
      const previewIcon = previewPlay ? previewPlay.querySelector('i') : null;
      const previewWave = document.getElementById('recordedPreviewWave');
      const previewBars = previewWave ? Array.from(previewWave.querySelectorAll('span')) : [];
      const previewDot = previewWave ? previewWave.querySelector('.voice-progress-dot') : null;
      const previewTimer = document.getElementById('recordedPreviewTimer');
      function updateRecordedPreviewProgress() {
        if (!previewAudio || !previewWave || !previewAudio.duration || !isFinite(previewAudio.duration)) return;
        const progress = Math.max(0, Math.min(1, previewAudio.currentTime / previewAudio.duration));
        const activeCount = Math.round(progress * previewBars.length);
        previewBars.forEach((bar, i) => bar.classList.toggle('played', i < activeCount));
        if (previewDot) previewDot.style.left = (progress * 100) + '%';
        previewWave.parentElement.classList.toggle('has-progress', progress > 0);
        if (previewTimer) previewTimer.textContent = previewAudio.paused && previewAudio.currentTime === 0 ? formatVoiceTime(duration) : formatVoiceTime(previewAudio.currentTime);
      }
      if (previewPlay && previewAudio && previewIcon) {
        previewPlay.addEventListener('click', function(e) {
          e.preventDefault();
          e.stopPropagation();
          if (previewAudio.paused) {
            previewAudio.play();
            previewIcon.className = 'fas fa-pause';
          } else {
            previewAudio.pause();
            previewIcon.className = 'fas fa-play';
          }
        });
        previewAudio.addEventListener('timeupdate', updateRecordedPreviewProgress);
        previewAudio.addEventListener('loadedmetadata', updateRecordedPreviewProgress);
        previewAudio.addEventListener('pause', updateRecordedPreviewProgress);
        previewAudio.addEventListener('ended', function() {
          previewIcon.className = 'fas fa-play';
          previewAudio.currentTime = 0;
          updateRecordedPreviewProgress();
          if (previewTimer) previewTimer.textContent = formatVoiceTime(duration);
        });
      }
      if (messageInput) messageInput.focus();
    }

    async function startVoiceRecording() {
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || typeof MediaRecorder === 'undefined') {
        alert('Voice recording is not supported in this browser. Please upload an audio file instead.');
        return;
      }
      if (fileInput && fileInput.files && fileInput.files.length > 0) {
        if (!confirm('This will replace selected attachment with a voice message. Continue?')) return;
        fileInput.value = '';
      }
      try {
        voiceStream = await navigator.mediaDevices.getUserMedia({ audio: true });
        const mimeType = MediaRecorder.isTypeSupported('audio/webm;codecs=opus') ? 'audio/webm;codecs=opus' : (MediaRecorder.isTypeSupported('audio/ogg;codecs=opus') ? 'audio/ogg;codecs=opus' : 'audio/webm');
        mediaRecorder = new MediaRecorder(voiceStream, { mimeType });
        voiceChunks = [];
        voiceStartedAt = Date.now();
        voicePaused = false;
        voicePausedAt = 0;
        voicePausedTotal = 0;
        setupVoiceAnalyser(voiceStream);
        mediaRecorder.ondataavailable = e => { if (e.data && e.data.size > 0) voiceChunks.push(e.data); };
        mediaRecorder.onstop = function() {
          if (voicePaused && voicePausedAt) voicePausedTotal += (Date.now() - voicePausedAt);
          const duration = Math.max(1, Math.floor((Date.now() - voiceStartedAt - voicePausedTotal) / 1000));
          if (voiceTimer) clearInterval(voiceTimer);
          if (voiceStream) voiceStream.getTracks().forEach(track => track.stop());
          voiceStream = null;
          if (voiceAudioContext) {
            try { voiceAudioContext.close(); } catch (e) {}
          }
          voiceAudioContext = null;
          voiceAnalyser = null;
          voiceDataArray = null;
          if (voiceChunks.length > 0) {
            const blob = new Blob(voiceChunks, { type: mediaRecorder.mimeType || 'audio/webm' });
            showRecordedVoicePreview(blob, duration);
          }
          if (voiceRecordBtn) {
            voiceRecordBtn.classList.remove('recording');
            voiceRecordBtn.innerHTML = '<i class="fas fa-microphone"></i>';
          }
        };
        mediaRecorder.start();
        if (voiceRecordBtn) {
          voiceRecordBtn.classList.add('recording');
          voiceRecordBtn.innerHTML = '<i class="fas fa-stop"></i>';
        }
        showRecordingPreview();
      } catch (err) {
        alert('Microphone permission was denied or unavailable.');
      }
    }

    if (voiceRecordBtn) {
      voiceRecordBtn.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        if (mediaRecorder && mediaRecorder.state === 'recording') {
          mediaRecorder.stop();
        } else {
          startVoiceRecording();
        }
      });
    }

    if (mainChatForm) {
      mainChatForm.addEventListener('submit', function(e) {
        if (mediaRecorder && mediaRecorder.state === 'recording') {
          e.preventDefault();
          alert('Please stop the voice recording before sending.');
        }
      });
    }

    // Auto scroll
    const msgArea = document.getElementById('messagesArea');
    if(msgArea) msgArea.scrollTop = msgArea.scrollHeight;
    
    // Pinned message clicks
    document.querySelectorAll('.pinned-message-item').forEach(item => {
      item.addEventListener('click', () => scrollToMessage(item.getAttribute('data-msg-id')));
    });
    
    if(window.location.hash) {
      setTimeout(() => scrollToMessage(window.location.hash.substring(1)), 500);
    }
  })();

// ========== REALTIME ONLINE / LAST SEEN ==========
(function() {
  const presenceEl = document.getElementById('chatPresenceText');
  const receiverId = presenceEl ? presenceEl.getAttribute('data-receiver-id') : '';
  const canCheckPresence = presenceEl && presenceEl.getAttribute('data-can-check') === '1';

  function sendPresenceHeartbeat() {
    const body = new URLSearchParams();
    body.append('presence_action', 'heartbeat');

    if (navigator.sendBeacon) {
      try {
        navigator.sendBeacon('messages.php', body);
        return;
      } catch (e) {}
    }

    fetch('messages.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString(),
      keepalive: true
    }).catch(function() {});
  }

  function refreshReceiverPresence() {
    if (!canCheckPresence || !receiverId) return;

    fetch('messages.php?presence_action=check&user_id=' + encodeURIComponent(receiverId), {
      cache: 'no-store'
    })
      .then(function(res) { return res.json(); })
      .then(function(data) {
        if (!presenceEl || !data) return;
        if (data.online) {
          presenceEl.innerHTML = '<span class="presence-online">● Online</span>';
        } else {
          presenceEl.innerHTML = '<span class="presence-offline">' + escapeHtml(data.text || 'last seen recently') + '</span>';
        }
      })
      .catch(function() {});
  }

  // Mark current user online immediately, then keep it fresh.
  sendPresenceHeartbeat();
  refreshReceiverPresence();

  setInterval(sendPresenceHeartbeat, 15000);
  setInterval(refreshReceiverPresence, 5000);

  document.addEventListener('visibilitychange', function() {
    if (!document.hidden) {
      sendPresenceHeartbeat();
      refreshReceiverPresence();
    }
  });
})();

</script>
</body>
</html>