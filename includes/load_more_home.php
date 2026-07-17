<?php
include '../core/init.php';

if (User::checkLogIn() === false) {
    exit;
}

$user_id = $_SESSION['user_id'];
$offset = isset($_POST['offset']) ? (int)$_POST['offset'] : 0;

ob_start();
Tweet::displayHomePosts($user_id, $offset);
$html = ob_get_clean();

echo $html;
?>