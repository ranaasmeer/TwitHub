<?php
require_once '../core/init.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: index.php');
    exit;
}

$pdo = Connect::connect();

if (!isset($_GET['id'])) {
    header('Location: comments.php');
    exit;
}

$commentId = (int)$_GET['id'];

// Fetch comment before deletion (optional: for logging or future use)
$stmt = $pdo->prepare("SELECT * FROM comments WHERE id = ?");
$stmt->execute([$commentId]);
$comment = $stmt->fetch(PDO::FETCH_ASSOC);

if ($comment) {
    // Delete the comment from database
    $stmtDel = $pdo->prepare("DELETE FROM comments WHERE id = ?");
    $stmtDel->execute([$commentId]);
}

// Redirect back to comments list
header('Location: comments.php');
exit;
