<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';

header('Content-Type: application/json; charset=utf-8');

$token = get_user_token();
if (!$token) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required.']);
    exit;
}

$user = sb_get_user($token);
if (!$user || empty($user['id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid or expired session.']);
    exit;
}

$currentUserId = $user['id'];
$profile = get_user_profile($currentUserId, $token);
$currentUserName = $profile['full_name'] ?? 'User';

function get_chat_file() {
    $dir = __DIR__ . '/../includes/data';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir . '/chat_messages.json';
}

function read_chat_messages() {
    $file = get_chat_file();
    if (!file_exists($file)) {
        return [];
    }
    $c = file_get_contents($file);
    return json_decode((string) $c, true) ?: [];
}

function save_chat_messages(array $messages) {
    $file = get_chat_file();
    // Keep last 1000 messages
    if (count($messages) > 1000) {
        $messages = array_slice($messages, -1000);
    }
    file_put_contents($file, json_encode($messages, JSON_PRETTY_PRINT));
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $withUserId = $_GET['with'] ?? '';
    $all = read_chat_messages();

    if (!empty($_GET['threads'])) {
        // Return active chat threads for current user
        $threads = [];
        foreach ($all as $m) {
            $otherId = ($m['sender_id'] === $currentUserId) ? $m['receiver_id'] : (($m['receiver_id'] === $currentUserId) ? $m['sender_id'] : null);
            if ($otherId) {
                if (!isset($threads[$otherId]) || strtotime($m['created_at']) > strtotime($threads[$otherId]['last_time'])) {
                    $threads[$otherId] = [
                        'user_id'    => $otherId,
                        'last_msg'   => $m['message'],
                        'last_time'  => $m['created_at'],
                        'unread'     => 0,
                    ];
                }
                if ($m['receiver_id'] === $currentUserId && empty($m['is_read'])) {
                    $threads[$otherId]['unread']++;
                }
            }
        }
        echo json_encode(['threads' => array_values($threads)]);
        exit;
    }

    if ($withUserId !== '') {
        $conversation = [];
        $dirty = false;
        foreach ($all as &$m) {
            if (($m['sender_id'] === $currentUserId && $m['receiver_id'] === $withUserId) ||
                ($m['sender_id'] === $withUserId && $m['receiver_id'] === $currentUserId)) {
                
                // Mark received message as read
                if ($m['receiver_id'] === $currentUserId && empty($m['is_read'])) {
                    $m['is_read'] = true;
                    $dirty = true;
                }
                $conversation[] = $m;
            }
        }
        if ($dirty) {
            save_chat_messages($all);
        }

        echo json_encode(['messages' => $conversation]);
        exit;
    }

    echo json_encode(['messages' => []]);
    exit;
}

if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true) ?: $_POST;

    $receiverId = trim($payload['receiver_id'] ?? '');
    $messageText = trim($payload['message'] ?? '');
    $appointmentId = trim($payload['appointment_id'] ?? '');

    if ($receiverId === '' || $messageText === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Receiver and message content are required.']);
        exit;
    }

    $newMessage = [
        'id'             => bin2hex(random_bytes(12)),
        'sender_id'      => $currentUserId,
        'sender_name'    => $currentUserName,
        'receiver_id'    => $receiverId,
        'appointment_id' => $appointmentId ?: null,
        'message'        => mb_substr($messageText, 0, 2000),
        'is_read'        => false,
        'created_at'     => date('c'),
    ];

    // 1. Try writing to Supabase table
    sb_request('POST', 'chat_messages', '', $newMessage, $token);

    // 2. Persist in local file store
    $all = read_chat_messages();
    $all[] = $newMessage;
    save_chat_messages($all);

    // 3. Trigger In-App Notification for Receiver
    create_notification(
        $receiverId,
        'New Message from ' . $currentUserName,
        mb_substr($messageText, 0, 100) . (strlen($messageText) > 100 ? '...' : ''),
        'chat',
        $token
    );

    echo json_encode(['success' => true, 'message' => $newMessage]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed.']);
?>