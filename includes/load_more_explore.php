<?php
include '../core/init.php';

if (User::checkLogIn() === false) {
    exit;
}

$user_id = (int)$_SESSION['user_id'];
$offset = isset($_POST['offset']) ? max(0, (int)$_POST['offset']) : 0;

ob_start();
Tweet::displayExplorePosts($user_id, $offset);
$html = trim(ob_get_clean());

if ($html === '') {
    if ($offset === 0) {
        echo '<div class="explore-empty-state"><i class="far fa-comment-dots"></i><p>No posts found</p></div>';
    } else {
        echo '';
    }
} else {
    echo $html;
}
?>