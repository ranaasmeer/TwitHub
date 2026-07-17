<?php
session_start();
require_once '../core/init.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit;
}

if (isset($_POST['notify_id'])) {
    $notify_id = (int)$_POST['notify_id'];
    $user_id = $_SESSION['user_id'];
    
    $pdo = Connect::connect();
    $stmt = $pdo->prepare("DELETE FROM notifications WHERE id = ? AND notify_for = ?");
    $result = $stmt->execute([$notify_id, $user_id]);
    
    if ($result) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false]);
    }
} else {
    echo json_encode(['success' => false]);
}
?>