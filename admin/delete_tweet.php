<?php
require_once '../core/init.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: index.php');
    exit;
}

$pdo = Connect::connect();

if (!isset($_GET['id'])) {
    header('Location: tweets.php');
    exit;
}

$tweetId = (int)$_GET['id'];

// Fetch tweet first to delete media and handle hashtags
$stmt = $pdo->prepare("SELECT status, img, video FROM tweets WHERE post_id = ?");
$stmt->execute([$tweetId]);
$tweet = $stmt->fetch(PDO::FETCH_ASSOC);

if ($tweet) {
    // --- Delete images ---
    if (!empty($tweet['img'])) {
        $images = explode(',', $tweet['img']);
        foreach ($images as $img) {
            $filePath = "../assets/images/tweets/$img";
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }
    }

    // --- Delete video ---
    if (!empty($tweet['video'])) {
        $videoPath = "../assets/videos/tweets/" . $tweet['video'];
        if (file_exists($videoPath)) {
            unlink($videoPath);
        }
    }

    // --- Handle hashtags ---
    preg_match_all('/#(\w+)/', $tweet['status'], $matches);
    $hashtags = !empty($matches[1]) ? $matches[1] : [];

    foreach ($hashtags as $tag) {
        $hashtag = '#' . $tag; // Add # to match trends table

        // Decrement count and recent_count safely (prevent negative)
        $stmtUpdate = $pdo->prepare("
            UPDATE trends 
            SET count = GREATEST(count - 1, 0), 
                recent_count = GREATEST(recent_count - 1, 0) 
            WHERE hashtag = ?
        ");
        $stmtUpdate->execute([$hashtag]);

        // Delete trend if count is now 0
        $stmtDelete = $pdo->prepare("DELETE FROM trends WHERE hashtag = ? AND count = 0");
        $stmtDelete->execute([$hashtag]);
    }

    // --- Delete tweet ---
    $stmtDel = $pdo->prepare("DELETE FROM tweets WHERE post_id = ?");
    $stmtDel->execute([$tweetId]);
}

// Redirect back to tweets list
header('Location: tweets.php');
exit;
?>
