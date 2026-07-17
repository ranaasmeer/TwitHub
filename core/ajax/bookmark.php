<?php
include '../init.php';

if (isset($_POST['tweet_id'])) {
    $user_id = $_SESSION['user_id'];
    $tweet_id = $_POST['tweet_id'];

    $stmt = Connect::connect()->prepare("SELECT * FROM bookmarks WHERE user_id = :uid AND tweet_id = :tid");
    $stmt->execute(['uid' => $user_id, 'tid' => $tweet_id]);

    if ($stmt->rowCount() > 0) {
        // remove bookmark
        $remove = Connect::connect()->prepare("DELETE FROM bookmarks WHERE user_id = :uid AND tweet_id = :tid");
        $remove->execute(['uid' => $user_id, 'tid' => $tweet_id]);
        echo json_encode(['status' => 'removed']);
    } else {
        // add bookmark
        $insert = Connect::connect()->prepare("INSERT INTO bookmarks (user_id, tweet_id) VALUES (:uid, :tid)");
        $insert->execute(['uid' => $user_id, 'tid' => $tweet_id]);
        echo json_encode(['status' => 'added']);
    }
}
?>

