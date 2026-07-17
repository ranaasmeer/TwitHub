<?php
include '../core/init.php';

if (User::checkLogIn() === false) {
    exit('not_logged_in');
}

$user_id  = $_SESSION['user_id'];
$tweet_id = $_POST['tweet_id'] ?? null;

if (!$tweet_id) {
    exit('missing_tweet_id');
}

$pdo = Connect::connect();

// 🔍 Check if it's a quote tweet
$checkQuote = $pdo->prepare("
    SELECT retweet_id, tweet_id, retweet_msg 
    FROM retweets 
    WHERE post_id = :pid
");
$checkQuote->execute(['pid' => $tweet_id]);
$quote = $checkQuote->fetch(PDO::FETCH_OBJ);

if ($quote) {
    // 🟣 Delete quote tweet only (not the original)
    $pdo->prepare("DELETE FROM retweets WHERE post_id = :pid")->execute(['pid' => $tweet_id]);
    $pdo->prepare("DELETE FROM tweets WHERE post_id = :pid")->execute(['pid' => $tweet_id]);
    exit('deleted');
}

// 🔍 Otherwise, normal/original tweet
$stmt = $pdo->prepare("
    SELECT t.tweet_by, t.status, t.img, t.video 
    FROM tweets t
    WHERE t.post_id = :pid
");
$stmt->execute(['pid' => $tweet_id]);
$tweet = $stmt->fetch(PDO::FETCH_OBJ);

if ($tweet && (int)$tweet->tweet_by === (int)$user_id) {
    
    // ========== 1️⃣ DELETE PHYSICAL IMAGE FILES ==========
    
    // Delete single image from old img column
    if (!empty($tweet->img)) {
        $image_path = "../assets/images/tweets/" . $tweet->img;
        if (file_exists($image_path)) {
            unlink($image_path);
        }
    }
    
    // Delete single video from old video column (backward compatibility)
    if (!empty($tweet->video)) {
        $video_path = "../assets/videos/tweets/" . $tweet->video;
        if (file_exists($video_path)) {
            unlink($video_path);
        }
    }
    
    // Delete multiple images from tweet_media table
    $stmt = $pdo->prepare("SELECT media_path FROM tweet_media WHERE tweet_id = :tweet_id");
    $stmt->execute(['tweet_id' => $tweet_id]);
    $media_files = $stmt->fetchAll(PDO::FETCH_OBJ);
    
    foreach ($media_files as $media) {
        $media_path = "../assets/images/tweets/" . $media->media_path;
        if (file_exists($media_path)) {
            unlink($media_path);
        }
    }
    
    // ========== 2️⃣ DELETE MULTIPLE VIDEOS FROM tweet_videos TABLE ==========
    $stmt = $pdo->prepare("SELECT video_path FROM tweet_videos WHERE tweet_id = :tweet_id");
    $stmt->execute(['tweet_id' => $tweet_id]);
    $video_files = $stmt->fetchAll(PDO::FETCH_OBJ);
    
    foreach ($video_files as $video) {
        $video_path = "../assets/videos/tweets/" . $video->video_path;
        if (file_exists($video_path)) {
            unlink($video_path);
        }
    }
    
    // ========== 3️⃣ HANDLE HASHTAGS ==========
    preg_match_all("/#+([a-zA-Z0-9_]+)/i", $tweet->status, $matches);
    if (!empty($matches[1])) {
        foreach ($matches[1] as $hashtag) {
            $hashtag = '#' . strtolower($hashtag);

            $update = $pdo->prepare("
                UPDATE trends 
                SET count = count - 1, recent_count = recent_count - 1 
                WHERE hashtag = :hashtag
            ");
            $update->execute(['hashtag' => $hashtag]);

            $deleteTrend = $pdo->prepare("
                DELETE FROM trends 
                WHERE hashtag = :hashtag AND count <= 0
            ");
            $deleteTrend->execute(['hashtag' => $hashtag]);
        }
    }

    // ========== 4️⃣ DELETE FROM RELATED TABLES ==========
    
    // Delete from tweet_media table (multiple images)
    $pdo->prepare("DELETE FROM tweet_media WHERE tweet_id = :pid")->execute(['pid' => $tweet_id]);
    
    // Delete from tweet_videos table (multiple videos)
    $pdo->prepare("DELETE FROM tweet_videos WHERE tweet_id = :pid")->execute(['pid' => $tweet_id]);
    
    // Delete from retweets
    $pdo->prepare("DELETE FROM retweets WHERE tweet_id = :pid OR retweet_id = :pid")->execute(['pid' => $tweet_id]);
    
    // Delete from likes
    $pdo->prepare("DELETE FROM likes WHERE post_id = :pid")->execute(['pid' => $tweet_id]);
    
    // Delete from comments
    $pdo->prepare("DELETE FROM comments WHERE post_id = :pid")->execute(['pid' => $tweet_id]);
    
    // Delete from bookmarks
    $pdo->prepare("DELETE FROM bookmarks WHERE tweet_id = :pid")->execute(['pid' => $tweet_id]);
    
    // Delete from posts table
    $pdo->prepare("DELETE FROM posts WHERE id = :pid")->execute(['pid' => $tweet_id]);

    // Finally delete from tweets table
    $pdo->prepare("DELETE FROM tweets WHERE post_id = :pid")->execute(['pid' => $tweet_id]);

    exit('deleted');
} else {
    exit('unauthorized');
}
?>