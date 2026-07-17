<?php
// File: cron/clean_preferences.php
// Run this script daily via cron job to clean old preferences

require_once __DIR__ . '/../core/init.php';

$pdo = Connect::connect();

echo "Starting preference cleanup...\n";

// 1. Delete hashtag interests with zero or negative counts
$stmt = $pdo->prepare("DELETE FROM user_hashtag_interests WHERE interaction_count <= 0");
$stmt->execute();
$deleted = $stmt->rowCount();
echo "Deleted {$deleted} zero/negative hashtag interests\n";

// 2. Delete category preferences with zero or negative scores
$stmt = $pdo->prepare("DELETE FROM user_category_preferences WHERE score <= 0");
$stmt->execute();
$deleted = $stmt->rowCount();
echo "Deleted {$deleted} zero/negative category preferences\n";

// 3. Delete old hashtag interests not updated in 30 days (optional)
$stmt = $pdo->prepare("DELETE FROM user_hashtag_interests WHERE last_interaction < DATE_SUB(NOW(), INTERVAL 30 DAY) AND interaction_count < 3");
$stmt->execute();
$deleted = $stmt->rowCount();
echo "Deleted {$deleted} old low-engagement hashtag interests\n";

// 4. Decay old preferences (reduce scores over time)
$stmt = $pdo->prepare("UPDATE user_hashtag_interests SET interaction_count = GREATEST(interaction_count - 1, 0) WHERE last_interaction < DATE_SUB(NOW(), INTERVAL 7 DAY)");
$stmt->execute();
$decayed = $stmt->rowCount();
echo "Decayed {$decayed} old hashtag interests\n";

// 5. Update engagement scores for reels
$stmt = $pdo->prepare("
    UPDATE reels r 
    SET engagement_score = (
        (likes_count * 2) + 
        (comments_count * 3) + 
        (saves_count * 1.5) + 
        (views_count * 0.1)
    ) / 100
");
$stmt->execute();
echo "Updated engagement scores for all reels\n";

echo "Preference cleanup completed!\n";
?>