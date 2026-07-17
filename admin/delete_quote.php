<?php
require_once '../core/init.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: index.php');
    exit;
}

$pdo = Connect::connect();

if (!isset($_GET['id'])) {
    header('Location: quotes.php');
    exit;
}

$quoteId = (int)$_GET['id'];

$stmt = $pdo->prepare("DELETE FROM retweets WHERE post_id = ?");
$stmt->execute([$quoteId]);

header("Location: quotes.php");
exit;
