<?php

// Add PHPMailer autoload
require_once __DIR__ . '/../vendor/autoload.php';

include 'classes/connection.php';
include 'classes/User.php';
include 'classes/Follow.php';
include 'classes/Tweet.php';

session_start();
 
// ✅ Get database connection
$pdo = Connect::connect();

// instead of using objects and decide to user static function
// $User = new User();
// $getFormFollow = new Follow($conn);
// $getFormTweet = new Tweet($conn);

define("BASE_URL" , "http://localhost/twitterclone/");

// ✅ BLOCK CHECK FOR LOGGED IN USERS - Prevents blocked users from accessing any page
if (isset($_SESSION['user_id'])) {
    try {
        $checkBlockStmt = $pdo->prepare("SELECT is_blocked FROM users WHERE id = ?");
        $checkBlockStmt->execute([$_SESSION['user_id']]);
        $userStatus = $checkBlockStmt->fetch(PDO::FETCH_ASSOC);
        
        // If user is blocked, destroy session and redirect to login
        if ($userStatus && isset($userStatus['is_blocked']) && $userStatus['is_blocked'] == 1) {
            session_destroy();
            session_start();
            $_SESSION['login_error'] = "Your account has been blocked. Please contact the administrator.";
            header('Location: ' . BASE_URL . 'login.php');
            exit;
        }
    } catch (PDOException $e) {
        // If column doesn't exist yet, just continue
        error_log("Block check failed: " . $e->getMessage());
    }
}