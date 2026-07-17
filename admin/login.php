<?php
// admin/login.php
session_start();
require_once '../core/init.php'; // uses your project's init & Connect

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php'); exit;
}

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

if (!$username || !$password) {
    $_SESSION['admin_err'] = 'Missing credentials.';
    header('Location: index.php');
    exit;
}

$pdo = Connect::connect();
$stmt = $pdo->prepare("SELECT id, username, password, name FROM admins WHERE username = :u LIMIT 1");
$stmt->execute(['u' => $username]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$admin || !password_verify($password, $admin['password'])) {
    $_SESSION['admin_err'] = 'Invalid username or password.';
    header('Location: index.php');
    exit;
}

// logged in
$_SESSION['admin_id'] = $admin['id'];
$_SESSION['admin_username'] = $admin['username'];
$_SESSION['admin_name'] = $admin['name'] ?? $admin['username'];

header('Location: dashboard.php');
exit;
