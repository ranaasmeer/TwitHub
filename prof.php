<?php
require_once 'core/init.php';

if (!User::checkLogIn()) {
    header("Location: index.php");
    exit;
}

$pdo = Connect::connect();
$viewer_id = $_SESSION['user_id'];

// ==================== HANDLE ALL AJAX REQUESTS FIRST (BEFORE ANY HTML OUTPUT) ====================

// Handle delete reel AJAX - MUST BE FIRST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_reel'])) {
    header('Content-Type: application/json; charset=utf-8');
    $reel_id = (int)($_POST['reel_id'] ?? 0);
    
    if ($reel_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid reel ID']);
        exit;
    }
    
    // Verify the user owns this reel
    $stmt = $pdo->prepare("SELECT user_id, media_path, media_type FROM reels WHERE id = ?");
    $stmt->execute([$reel_id]);
    $reel = $stmt->fetch(PDO::FETCH_OBJ);
    
    if (!$reel) {
        echo json_encode(['success' => false, 'error' => 'Reel not found']);
        exit;
    }
    
    if ($reel->user_id != $viewer_id) {
        echo json_encode(['success' => false, 'error' => 'You can only delete your own reels']);
        exit;
    }
    
    try {
        // Delete from reel_likes
        $stmt = $pdo->prepare("DELETE FROM reel_likes WHERE reel_id = ?");
        $stmt->execute([$reel_id]);
        
        // Delete from reel_views
        $stmt = $pdo->prepare("DELETE FROM reel_views WHERE reel_id = ?");
        $stmt->execute([$reel_id]);
        
        // Delete from reels_saves
        $stmt = $pdo->prepare("DELETE FROM reels_saves WHERE reel_id = ?");
        $stmt->execute([$reel_id]);
        
        // Get all comments for this reel
        $stmt = $pdo->prepare("SELECT id FROM reels_comments WHERE reel_id = ?");
        $stmt->execute([$reel_id]);
        $comments = $stmt->fetchAll(PDO::FETCH_OBJ);
        
        foreach ($comments as $comment) {
            // Delete comment likes
            $stmt = $pdo->prepare("DELETE FROM reels_comment_likes WHERE comment_id = ?");
            $stmt->execute([$comment->id]);
            
            // Get all replies for this comment
            $stmt = $pdo->prepare("SELECT id FROM reels_replies WHERE comment_id = ?");
            $stmt->execute([$comment->id]);
            $replies = $stmt->fetchAll(PDO::FETCH_OBJ);
            
            foreach ($replies as $reply) {
                // Delete reply likes
                $stmt = $pdo->prepare("DELETE FROM reels_reply_likes WHERE reply_id = ?");
                $stmt->execute([$reply->id]);
            }
            
            // Delete replies
            $stmt = $pdo->prepare("DELETE FROM reels_replies WHERE comment_id = ?");
            $stmt->execute([$comment->id]);
        }
        
        // Delete comments
        $stmt = $pdo->prepare("DELETE FROM reels_comments WHERE reel_id = ?");
        $stmt->execute([$reel_id]);
        
        // Delete the media file
        $mediaPath = "assets/reels/" . $reel->media_path;
        if (file_exists($mediaPath)) {
            unlink($mediaPath);
        }
        
        // Delete the reel
        $stmt = $pdo->prepare("DELETE FROM reels WHERE id = ?");
        $stmt->execute([$reel_id]);
        
        echo json_encode(['success' => true, 'message' => 'Reel deleted successfully']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Handle follow/unfollow AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['follow_action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['follow_action'];
    $target_user = (int)($_POST['user_id'] ?? 0);
    
    if ($target_user <= 0 || $target_user === $viewer_id) {
        echo json_encode(['ok' => false, 'msg' => 'Invalid user']);
        exit;
    }

    try {
        if ($action === 'follow') {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM follow WHERE follower_id = ? AND following_id = ?");
            $stmt->execute([$viewer_id, $target_user]);
            if ($stmt->fetchColumn() == 0) {
                $stmt = $pdo->prepare("INSERT INTO follow (follower_id, following_id, time) VALUES (?, ?, NOW())");
                $stmt->execute([$viewer_id, $target_user]);
            }
            echo json_encode(['ok' => true, 'following' => true]);
        } elseif ($action === 'unfollow') {
            $stmt = $pdo->prepare("DELETE FROM follow WHERE follower_id = ? AND following_id = ?");
            $stmt->execute([$viewer_id, $target_user]);
            echo json_encode(['ok' => true, 'following' => false]);
        }
    } catch (PDOException $e) {
        echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
    }
    exit;
}

// ==================== NOW START THE HTML PAGE ====================

$viewer = User::getData($viewer_id);

// Get profile username from URL
$username = isset($_GET['username']) ? User::checkInput($_GET['username']) : '';
if (empty($username)) {
    header("Location: home.php");
    exit;
}

$profileId = User::getIdByUsername($username);
if (!$profileId) {
    header("Location: home.php");
    exit;
}

$profile = User::getData($profileId);
$isOwnProfile = ($profileId == $viewer_id);
$isFollowing = Follow::isUserFollow($viewer_id, $profileId);

// Get user's reels with views_count and saves_count
$stmt = $pdo->prepare("
    SELECT r.*, 
           u.username, u.name, u.img as user_img, u.is_verified,
           (SELECT COUNT(*) FROM reel_likes WHERE reel_id = r.id) as likes_count,
           (SELECT COUNT(*) FROM reels_comments WHERE reel_id = r.id) as comments_count,
           (SELECT COUNT(*) FROM reels_saves WHERE reel_id = r.id) as saves_count,
           r.views_count
    FROM reels r
    JOIN users u ON u.id = r.user_id
    WHERE r.user_id = ?
    ORDER BY r.created_at DESC
");
$stmt->execute([$profileId]);
$userReels = $stmt->fetchAll(PDO::FETCH_OBJ);

// Get user's liked reels with views_count
$stmt = $pdo->prepare("
    SELECT r.*, 
           u.username, u.name, u.img as user_img, u.is_verified,
           (SELECT COUNT(*) FROM reel_likes WHERE reel_id = r.id) as likes_count,
           (SELECT COUNT(*) FROM reels_comments WHERE reel_id = r.id) as comments_count,
           (SELECT COUNT(*) FROM reels_saves WHERE reel_id = r.id) as saves_count,
           r.views_count
    FROM reel_likes rl
    JOIN reels r ON r.id = rl.reel_id
    JOIN users u ON u.id = r.user_id
    WHERE rl.user_id = ?
    ORDER BY rl.created_at DESC
");
$stmt->execute([$profileId]);
$likedReels = $stmt->fetchAll(PDO::FETCH_OBJ);

// ==================== GET SAVED REELS (BOOKMARKS) - ONLY FOR OWN PROFILE ====================
$savedReels = [];
if ($isOwnProfile) {
    $stmt = $pdo->prepare("
        SELECT r.*, 
               u.username, u.name, u.img as user_img, u.is_verified,
               (SELECT COUNT(*) FROM reel_likes WHERE reel_id = r.id) as likes_count,
               (SELECT COUNT(*) FROM reels_comments WHERE reel_id = r.id) as comments_count,
               (SELECT COUNT(*) FROM reels_saves WHERE reel_id = r.id) as saves_count,
               r.views_count,
               (SELECT COUNT(*) FROM reels_saves WHERE reel_id = r.id AND user_id = ?) as is_saved
        FROM reels_saves rs
        JOIN reels r ON r.id = rs.reel_id
        JOIN users u ON u.id = r.user_id
        WHERE rs.user_id = ?
        ORDER BY rs.created_at DESC
    ");
    $stmt->execute([$viewer_id, $viewer_id]);
    $savedReels = $stmt->fetchAll(PDO::FETCH_OBJ);
}

// Get follow counts
$followersCount = Follow::countFollowers($profileId);
$followingCount = Follow::countFollowing($profileId);
$reelsCount = count($userReels);
$likedCount = count($likedReels);
$savedCount = count($savedReels);

// Get unread messages count for sidebar
$stmt = $pdo->prepare("SELECT COUNT(*) AS unread_total FROM messages WHERE receiver_id = ? AND is_read = 0");
$stmt->execute([$viewer_id]);
$unreadMessages = $stmt->fetch(PDO::FETCH_OBJ)->unread_total ?? 0;
$notify_count = User::CountNotification($viewer_id);
$who_users = Follow::whoToFollow($viewer_id);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?php echo htmlspecialchars($profile->name); ?> (@<?php echo htmlspecialchars($profile->username); ?>) | Reels Profile</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: #0a0a0c;
            color: white;
            min-height: 100vh;
        }

        /* Header */
        .profile-header {
            position: sticky;
            top: 0;
            background: rgba(10, 10, 12, 0.95);
            backdrop-filter: blur(20px);
            border-bottom: 1px solid rgba(255,255,255,0.1);
            padding: 12px 20px;
            z-index: 100;
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .back-btn {
            width: 40px;
            height: 40px;
            background: rgba(255,255,255,0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            color: white;
        }

        .back-btn:hover {
            background: rgba(255,255,255,0.2);
        }

        .profile-title {
            flex: 1;
        }

        .profile-title h1 {
            font-size: 20px;
            font-weight: 700;
        }

        .profile-title p {
            font-size: 13px;
            color: #a0a0a8;
            margin-top: 2px;
        }

        /* Cover Image */
        .cover-container {
            position: relative;
            height: 200px;
            background: linear-gradient(135deg, #1da1f2, #0d8bd9);
            overflow: hidden;
        }

        .cover-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        /* Profile Info */
        .profile-info {
            padding: 0 20px;
            margin-top: -40px;
            position: relative;
            z-index: 10;
        }

        .avatar-container {
            position: relative;
            display: inline-block;
        }

        .profile-avatar {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid #0a0a0c;
            background: #0a0a0c;
        }

        .verified-badge-large {
            position: absolute;
            bottom: 5px;
            right: 5px;
            background: #1da1f2;
            border-radius: 50%;
            width: 28px;
            height: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid #0a0a0c;
        }

        .verified-badge-large i {
            font-size: 14px;
            color: white;
        }

        .profile-name {
            margin-top: 12px;
        }

        .profile-name h2 {
            font-size: 24px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .profile-username {
            color: #a0a0a8;
            font-size: 15px;
            margin-top: 4px;
        }

        .profile-bio {
            margin-top: 12px;
            font-size: 14px;
            line-height: 1.5;
            color: #e0e0e0;
        }

        .profile-stats {
            display: flex;
            gap: 24px;
            margin-top: 16px;
            padding-bottom: 16px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }

        .stat {
            cursor: pointer;
        }

        .stat-number {
            font-weight: 700;
            font-size: 18px;
        }

        .stat-label {
            font-size: 13px;
            color: #a0a0a8;
        }

        .follow-btn {
            background: #1da1f2;
            border: none;
            border-radius: 30px;
            padding: 8px 24px;
            color: white;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s;
            margin-top: 12px;
        }

        .follow-btn.following {
            background: transparent;
            border: 1px solid #1da1f2;
            color: #1da1f2;
        }

        .follow-btn:hover {
            transform: scale(1.02);
            opacity: 0.9;
        }

        /* Tabs */
        .profile-tabs {
            display: flex;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            margin-top: 16px;
        }

        .tab-btn {
            flex: 1;
            background: none;
            border: none;
            padding: 16px;
            color: #a0a0a8;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            position: relative;
        }

        .tab-btn.active {
            color: #1da1f2;
        }

        .tab-btn.active::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: #1da1f2;
        }

        /* Reels Grid */
        .reels-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 2px;
            background: #000;
        }

        .reel-card {
            position: relative;
            aspect-ratio: 9 / 16;
            cursor: pointer;
            overflow: hidden;
        }

        .reel-card video,
        .reel-card img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s;
        }

        .reel-card:hover video,
        .reel-card:hover img {
            transform: scale(1.05);
        }

        .reel-overlay {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: linear-gradient(to top, rgba(0,0,0,0.8), transparent);
            padding: 10px;
            display: flex;
            justify-content: space-around;
            opacity: 0;
            transition: opacity 0.3s;
        }

        .reel-card:hover .reel-overlay {
            opacity: 1;
        }

        .reel-overlay span {
            font-size: 12px;
            display: flex;
            align-items: center;
            gap: 5px;
            color: white;
        }

        .reel-overlay i {
            font-size: 12px;
        }

        .reel-overlay .views i {
            color: #a0a0a8;
        }

        .reel-overlay .likes i {
            color: #ff4444;
        }

        .reel-overlay .comments i {
            color: #1da1f2;
        }
        
        .reel-overlay .saves i {
            color: #f9a825;
        }

        .reel-author {
            position: absolute;
            top: 8px;
            left: 8px;
            background: rgba(0,0,0,0.6);
            backdrop-filter: blur(4px);
            border-radius: 20px;
            padding: 4px 10px;
            font-size: 11px;
            display: flex;
            align-items: center;
            gap: 6px;
            z-index: 5;
        }

        .reel-author i {
            font-size: 10px;
            color: #1da1f2;
        }

        .reel-author span {
            color: white;
            font-size: 11px;
        }

        /* Three Dots Menu */
        .reel-menu-btn {
            position: absolute;
            top: 8px;
            right: 8px;
            width: 32px;
            height: 32px;
            background: rgba(0,0,0,0.6);
            backdrop-filter: blur(4px);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            z-index: 10;
            transition: all 0.2s;
            border: none;
            color: white;
            font-size: 14px;
        }

        .reel-menu-btn:hover {
            background: rgba(0,0,0,0.8);
            transform: scale(1.1);
        }

        /* Modal for Delete Confirmation */
        .delete-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.8);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .delete-modal.active {
            display: flex;
        }

        .delete-modal-content {
            background: #1a1a1f;
            border-radius: 20px;
            padding: 24px;
            width: 90%;
            max-width: 320px;
            text-align: center;
        }

        .delete-modal-content h3 {
            margin-bottom: 12px;
            font-size: 20px;
        }

        .delete-modal-content p {
            color: #a0a0a8;
            margin-bottom: 24px;
            font-size: 14px;
        }

        .delete-modal-buttons {
            display: flex;
            gap: 12px;
        }

        .delete-modal-buttons button {
            flex: 1;
            padding: 12px;
            border-radius: 30px;
            font-weight: 600;
            cursor: pointer;
            border: none;
            transition: all 0.2s;
        }

        .confirm-delete {
            background: #e74c3c;
            color: white;
        }

        .confirm-delete:hover {
            background: #c0392b;
        }

        .cancel-delete {
            background: rgba(255,255,255,0.1);
            color: white;
        }

        .cancel-delete:hover {
            background: rgba(255,255,255,0.2);
        }

        .empty-reels {
            text-align: center;
            padding: 60px 20px;
            color: #a0a0a8;
        }

        .empty-reels i {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.5;
        }

        /* Sidebar Navigation */
        .wrapper-left {
            position: fixed;
            left: -280px;
            top: 0;
            width: 280px;
            height: 100vh;
            background: rgba(10,10,12,0.98);
            backdrop-filter: blur(20px);
            z-index: 200;
            transition: left 0.3s ease;
            border-right: 1px solid rgba(255,255,255,0.1);
        }

        .wrapper-left.open {
            left: 0;
        }

        .sidebar-content {
            padding: 20px;
            height: 100%;
            overflow-y: auto;
        }

        .sidebar-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 0;
            color: white;
            text-decoration: none;
            font-size: 16px;
        }

        .sidebar-link i {
            width: 24px;
            font-size: 20px;
        }

        .sidebar-footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid rgba(255,255,255,0.1);
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .user-info img {
            width: 44px;
            height: 44px;
            border-radius: 50%;
        }

        .menu-toggle {
            position: fixed;
            top: 16px;
            left: 16px;
            width: 40px;
            height: 40px;
            background: rgba(0,0,0,0.5);
            backdrop-filter: blur(8px);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            z-index: 60;
            border: 1px solid rgba(255,255,255,0.15);
        }

        .menu-toggle i {
            font-size: 20px;
            color: white;
        }

        @media (max-width: 480px) {
            .profile-avatar {
                width: 80px;
                height: 80px;
            }
            
            .profile-name h2 {
                font-size: 20px;
            }
            
            .stat-number {
                font-size: 16px;
            }
            
            .reels-grid {
                gap: 1px;
            }
            
            .reel-overlay span {
                font-size: 10px;
            }
            
            .reel-menu-btn {
                width: 28px;
                height: 28px;
                font-size: 12px;
            }
        }
    </style>
</head>
<body>

<!-- Menu Toggle -->
<div class="menu-toggle" id="menuToggle">
    <i class="fas fa-bars"></i>
</div>

<!-- Sidebar -->
<div class="wrapper-left" id="leftSidebar">
    <div class="sidebar-content">
        <div class="text-center mb-4">
            <img src="<?php echo BASE_URL . "/assets/images/twitter-logo.png"; ?>" alt="Twitter" height="28px">
        </div>
        
        <a href="home.php" class="sidebar-link">
            <i class="fas fa-home"></i> Home
        </a>
        <a href="explore.php" class="sidebar-link">
            <i class="fas fa-search"></i> Explore
        </a>
        <a href="reels.php" class="sidebar-link">
            <i class="fas fa-film"></i> Reels
        </a>
        <a href="messages.php" class="sidebar-link">
            <i class="fas fa-envelope"></i> Messages
            <?php if (!empty($unreadMessages)): ?>
                <span class="badge bg-primary rounded-pill ms-2"><?php echo $unreadMessages; ?></span>
            <?php endif; ?>
        </a>
        <a href="notification.php" class="sidebar-link">
            <i class="fas fa-bell"></i> Notifications
            <?php if ($notify_count > 0): ?>
                <span class="badge bg-primary rounded-pill ms-2"><?php echo $notify_count; ?></span>
            <?php endif; ?>
        </a>
        <a href="bookmarks.php" class="sidebar-link">
            <i class="fas fa-bookmark"></i> Bookmarks
        </a>
        <a href="<?php echo BASE_URL . $viewer->username; ?>" class="sidebar-link">
            <i class="fas fa-user"></i> Profile
        </a>
        <a href="account.php" class="sidebar-link">
            <i class="fas fa-cog"></i> Settings
        </a>
        
        <div class="sidebar-footer">
            <div class="user-info">
                <img src="assets/images/users/<?php echo $viewer->img; ?>" alt="">
                <div>
                    <div><strong><?php echo $viewer->name; ?></strong></div>
                    <small class="text-secondary">@<?php echo $viewer->username; ?></small>
                </div>
            </div>
            <a href="includes/logout.php" class="sidebar-link mt-3">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
    </div>
</div>

<!-- Profile Header -->
<div class="profile-header">
    <a href="javascript:history.back()" class="back-btn">
        <i class="fas fa-arrow-left"></i>
    </a>
    <div class="profile-title">
        <h1><?php echo htmlspecialchars($profile->name); ?></h1>
        <p><?php echo $reelsCount; ?> reels</p>
    </div>
</div>

<!-- Cover Image -->
<div class="cover-container">
    <img class="cover-img" src="assets/images/users/<?php echo htmlspecialchars($profile->imgCover ?: 'cover.png'); ?>" alt="Cover">
</div>

<!-- Profile Info -->
<div class="profile-info">
    <div class="avatar-container">
        <img class="profile-avatar" src="assets/images/users/<?php echo htmlspecialchars($profile->img); ?>" alt="<?php echo htmlspecialchars($profile->name); ?>">
        <?php if ($profile->is_verified == 1): ?>
            <div class="verified-badge-large">
                <i class="fas fa-check-circle"></i>
            </div>
        <?php endif; ?>
    </div>
    
    <div class="profile-name">
        <h2>
            <?php echo htmlspecialchars($profile->name); ?>
            <?php if ($profile->is_verified == 1): ?>
                <i class="fas fa-check-circle verified-badge" style="color: #1da1f2; font-size: 20px;"></i>
            <?php endif; ?>
        </h2>
        <div class="profile-username">@<?php echo htmlspecialchars($profile->username); ?></div>
        
        <?php if (!empty($profile->bio)): ?>
            <div class="profile-bio"><?php echo nl2br(htmlspecialchars($profile->bio)); ?></div>
        <?php endif; ?>
        
        <?php if (!empty($profile->location) || !empty($profile->website)): ?>
            <div class="profile-bio" style="margin-top: 8px; color: #a0a0a8;">
                <?php if (!empty($profile->location)): ?>
                    <i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($profile->location); ?>
                <?php endif; ?>
                <?php if (!empty($profile->website)): ?>
                    <?php if (!empty($profile->location)): ?> · <?php endif; ?>
                    <i class="fas fa-link"></i> <a href="<?php echo htmlspecialchars($profile->website); ?>" target="_blank" style="color: #1da1f2; text-decoration: none;"><?php echo parse_url($profile->website, PHP_URL_HOST); ?></a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <div class="profile-stats">
        <div class="stat" id="reelsStat">
            <div class="stat-number"><?php echo $reelsCount; ?></div>
            <div class="stat-label">Reels</div>
        </div>
        <div class="stat" id="followersStat">
            <div class="stat-number"><?php echo $followersCount; ?></div>
            <div class="stat-label">Followers</div>
        </div>
        <div class="stat" id="followingStat">
            <div class="stat-number"><?php echo $followingCount; ?></div>
            <div class="stat-label">Following</div>
        </div>
    </div>
    
    <?php if (!$isOwnProfile): ?>
        <button class="follow-btn <?php echo $isFollowing ? 'following' : ''; ?>" data-user="<?php echo $profileId; ?>">
            <?php echo $isFollowing ? 'Following' : 'Follow'; ?>
        </button>
    <?php else: ?>
        <a href="account.php" class="follow-btn" style="display: inline-block; text-decoration: none; text-align: center;">
            Edit Profile
        </a>
    <?php endif; ?>
    
    <!-- Tabs -->
    <div class="profile-tabs">
        <button class="tab-btn active" data-tab="reels">
            <i class="fas fa-film"></i> Reels
        </button>
        <button class="tab-btn" data-tab="liked">
            <i class="fas fa-heart"></i> Liked
        </button>
        <?php if ($isOwnProfile): ?>
            <button class="tab-btn" data-tab="saved">
                <i class="fas fa-bookmark"></i> Saved (<?php echo $savedCount; ?>)
            </button>
        <?php endif; ?>
    </div>
</div>

<!-- Reels Grid Container -->
<div id="reelsContainer" class="reels-grid">
    <?php if (empty($userReels)): ?>
        <div class="empty-reels" style="grid-column: span 3;">
            <i class="fas fa-film"></i>
            <h4>No Reels Yet</h4>
            <?php if ($isOwnProfile): ?>
                <p>Create your first reel!</p>
                <a href="create_reel.php" style="display: inline-block; margin-top: 16px; background: #1da1f2; padding: 10px 20px; border-radius: 30px; color: white; text-decoration: none;">Create Reel</a>
            <?php else: ?>
                <p>This user hasn't posted any reels yet.</p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <?php foreach ($userReels as $index => $reel): ?>
            <div class="reel-card" data-reel-id="<?php echo $reel->id; ?>" data-user-id="<?php echo $profileId; ?>" data-tab="reels">
                <?php if ($reel->media_type === 'video'): ?>
                    <video muted playsinline preload="metadata">
                        <source src="assets/reels/<?php echo htmlspecialchars($reel->media_path); ?>" type="video/mp4">
                    </video>
                <?php else: ?>
                    <img src="assets/reels/<?php echo htmlspecialchars($reel->media_path); ?>" alt="Reel">
                <?php endif; ?>
                <div class="reel-overlay">
                    <span class="views"><i class="fas fa-eye"></i> <?php echo number_format($reel->views_count ?? 0); ?></span>
                    <span class="likes"><i class="fas fa-heart"></i> <?php echo number_format($reel->likes_count); ?></span>
                    <span class="comments"><i class="fas fa-comment"></i> <?php echo number_format($reel->comments_count); ?></span>
                </div>
                <?php if ($isOwnProfile): ?>
                    <button class="reel-menu-btn" data-reel-id="<?php echo $reel->id; ?>">
                        <i class="fas fa-ellipsis-v"></i>
                    </button>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Liked Reels Container (Hidden initially) -->
<div id="likedContainer" class="reels-grid" style="display: none;">
    <?php if (empty($likedReels)): ?>
        <div class="empty-reels" style="grid-column: span 3;">
            <i class="fas fa-heart"></i>
            <h4>No Liked Reels</h4>
            <p>Reels you like will appear here</p>
        </div>
    <?php else: ?>
        <?php foreach ($likedReels as $index => $reel): ?>
           <div class="reel-card" data-reel-id="<?php echo $reel->id; ?>" data-user-id="<?php echo $profileId; ?>" data-tab="liked">
                <?php if ($reel->media_type === 'video'): ?>
                    <video muted playsinline preload="metadata">
                        <source src="assets/reels/<?php echo htmlspecialchars($reel->media_path); ?>" type="video/mp4">
                    </video>
                <?php else: ?>
                    <img src="assets/reels/<?php echo htmlspecialchars($reel->media_path); ?>" alt="Reel">
                <?php endif; ?>
                <div class="reel-author">
                    <i class="fas fa-user-circle"></i>
                    <span>@<?php echo htmlspecialchars($reel->username); ?></span>
                </div>
                <div class="reel-overlay">
                    <span class="views"><i class="fas fa-eye"></i> <?php echo number_format($reel->views_count ?? 0); ?></span>
                    <span class="likes"><i class="fas fa-heart"></i> <?php echo number_format($reel->likes_count); ?></span>
                    <span class="comments"><i class="fas fa-comment"></i> <?php echo number_format($reel->comments_count); ?></span>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Saved Reels Container (Bookmarks - Hidden initially, only for own profile) -->
<?php if ($isOwnProfile): ?>
<div id="savedContainer" class="reels-grid" style="display: none;">
    <?php if (empty($savedReels)): ?>
        <div class="empty-reels" style="grid-column: span 3;">
            <i class="fas fa-bookmark"></i>
            <h4>No Saved Reels</h4>
            <p>Reels you save will appear here</p>
        </div>
    <?php else: ?>
        <?php foreach ($savedReels as $index => $reel): ?>
            <div class="reel-card" data-reel-id="<?php echo $reel->id; ?>" data-user-id="<?php echo $profileId; ?>" data-tab="saved">
                <?php if ($reel->media_type === 'video'): ?>
                    <video muted playsinline preload="metadata">
                        <source src="assets/reels/<?php echo htmlspecialchars($reel->media_path); ?>" type="video/mp4">
                    </video>
                <?php else: ?>
                    <img src="assets/reels/<?php echo htmlspecialchars($reel->media_path); ?>" alt="Reel">
                <?php endif; ?>
                <div class="reel-author">
                    <i class="fas fa-user-circle"></i>
                    <span>@<?php echo htmlspecialchars($reel->username); ?></span>
                </div>
                <div class="reel-overlay">
                    <span class="views"><i class="fas fa-eye"></i> <?php echo number_format($reel->views_count ?? 0); ?></span>
                    <span class="likes"><i class="fas fa-heart"></i> <?php echo number_format($reel->likes_count); ?></span>
                    <span class="comments"><i class="fas fa-comment"></i> <?php echo number_format($reel->comments_count); ?></span>
                    <span class="saves"><i class="fas fa-bookmark"></i> <?php echo number_format($reel->saves_count); ?></span>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Delete Confirmation Modal -->
<div class="delete-modal" id="deleteModal">
    <div class="delete-modal-content">
        <h3>Delete Reel?</h3>
        <p>Are you sure you want to delete this reel? This action cannot be undone.</p>
        <div class="delete-modal-buttons">
            <button class="cancel-delete" id="cancelDeleteBtn">Cancel</button>
            <button class="confirm-delete" id="confirmDeleteBtn">Delete</button>
        </div>
    </div>
</div>

<script src="assets/js/jquery-3.5.1.min.js"></script>
<script>
// ==================== Tab Switching ====================
const reelsContainer = document.getElementById('reelsContainer');
const likedContainer = document.getElementById('likedContainer');
const savedContainer = <?php echo $isOwnProfile ? "document.getElementById('savedContainer')" : "null"; ?>;
const tabBtns = document.querySelectorAll('.tab-btn');

let currentTab = 'reels';

tabBtns.forEach(btn => {
    btn.addEventListener('click', () => {
        const tab = btn.dataset.tab;
        currentTab = tab;
        
        tabBtns.forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        
        // Hide all containers
        reelsContainer.style.display = 'none';
        likedContainer.style.display = 'none';
        if (savedContainer) {
            savedContainer.style.display = 'none';
        }
        
        // Show selected container
        if (tab === 'reels') {
            reelsContainer.style.display = 'grid';
        } else if (tab === 'liked') {
            likedContainer.style.display = 'grid';
        } else if (tab === 'saved' && savedContainer) {
            savedContainer.style.display = 'grid';
        }
    });
});

// ==================== Follow/Unfollow ====================
async function handleFollow(btn, userId) {
    const isFollowing = btn.classList.contains('following');
    const formData = new FormData();
    formData.append('follow_action', isFollowing ? 'unfollow' : 'follow');
    formData.append('user_id', userId);
    
    try {
        const response = await fetch(window.location.pathname, { method: 'POST', body: formData });
        const data = await response.json();
        if (data.ok) {
            if (data.following) {
                btn.classList.add('following');
                btn.textContent = 'Following';
            } else {
                btn.classList.remove('following');
                btn.textContent = 'Follow';
            }
        }
    } catch (error) {
        console.error('Follow error:', error);
    }
}

document.querySelectorAll('.follow-btn').forEach(btn => {
    btn.addEventListener('click', (e) => {
        e.preventDefault();
        const userId = btn.dataset.user;
        handleFollow(btn, userId);
    });
});

// ==================== Sidebar Menu ====================
const menuToggle = document.getElementById('menuToggle');
const leftSidebar = document.getElementById('leftSidebar');

menuToggle.addEventListener('click', () => {
    leftSidebar.classList.toggle('open');
});

document.addEventListener('click', (e) => {
    if (!leftSidebar.contains(e.target) && !menuToggle.contains(e.target) && leftSidebar.classList.contains('open')) {
        leftSidebar.classList.remove('open');
    }
});

// ==================== Video Hover Play/Pause ====================
document.querySelectorAll('.reel-card video').forEach(video => {
    video.addEventListener('mouseenter', () => {
        video.play().catch(() => {});
    });
    video.addEventListener('mouseleave', () => {
        video.pause();
        video.currentTime = 0;
    });
});

// ==================== Delete Reel Functionality ====================
let reelToDelete = null;
const deleteModal = document.getElementById('deleteModal');
const cancelDeleteBtn = document.getElementById('cancelDeleteBtn');
const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');

// Add click event to all delete buttons - Use event delegation
document.addEventListener('click', function(e) {
    const menuBtn = e.target.closest('.reel-menu-btn');
    if (menuBtn) {
        e.stopPropagation();
        e.preventDefault();
        reelToDelete = menuBtn.getAttribute('data-reel-id');
        deleteModal.classList.add('active');
    }
});

// Cancel delete
if (cancelDeleteBtn) {
    cancelDeleteBtn.addEventListener('click', () => {
        deleteModal.classList.remove('active');
        reelToDelete = null;
    });
}

// Confirm delete
if (confirmDeleteBtn) {
    confirmDeleteBtn.addEventListener('click', async () => {
        if (!reelToDelete) return;
        
        try {
            const formData = new FormData();
            formData.append('delete_reel', '1');
            formData.append('reel_id', reelToDelete);
            
            const response = await fetch(window.location.pathname, { 
                method: 'POST', 
                body: formData 
            });
            
            const data = await response.json();
            
            if (data.success) {
                // Remove the reel card from DOM (check both containers)
                const reelCardInReels = document.querySelector(`#reelsContainer .reel-card[data-reel-id="${reelToDelete}"]`);
                const reelCardInSaved = document.querySelector(`#savedContainer .reel-card[data-reel-id="${reelToDelete}"]`);
                
                if (reelCardInReels) {
                    reelCardInReels.remove();
                }
                if (reelCardInSaved) {
                    reelCardInSaved.remove();
                }
                
                // Update reels count in header
                const reelsCountElement = document.querySelector('.profile-title p');
                if (reelsCountElement) {
                    let currentCount = parseInt(reelsCountElement.textContent);
                    if (!isNaN(currentCount)) {
                        reelsCountElement.textContent = (currentCount - 1) + ' reels';
                    }
                }
                
                // Update reels stat number
                const reelsStat = document.querySelector('#reelsStat .stat-number');
                if (reelsStat) {
                    let currentCount = parseInt(reelsStat.textContent);
                    if (!isNaN(currentCount)) {
                        reelsStat.textContent = currentCount - 1;
                    }
                }
                
                // Check if no reels left in reels container
                const remainingReels = document.querySelectorAll('#reelsContainer .reel-card').length;
                if (remainingReels === 0) {
                    reelsContainer.innerHTML = `
                        <div class="empty-reels" style="grid-column: span 3;">
                            <i class="fas fa-film"></i>
                            <h4>No Reels Yet</h4>
                            <p>Create your first reel!</p>
                            <a href="create_reel.php" style="display: inline-block; margin-top: 16px; background: #1da1f2; padding: 10px 20px; border-radius: 30px; color: white; text-decoration: none;">Create Reel</a>
                        </div>
                    `;
                }
                
                // Check if no saved reels left
                if (savedContainer) {
                    const remainingSaved = document.querySelectorAll('#savedContainer .reel-card').length;
                    if (remainingSaved === 0) {
                        savedContainer.innerHTML = `
                            <div class="empty-reels" style="grid-column: span 3;">
                                <i class="fas fa-bookmark"></i>
                                <h4>No Saved Reels</h4>
                                <p>Reels you save will appear here</p>
                            </div>
                        `;
                    }
                }
                
                showToast('Reel deleted successfully');
            } else {
                showToast(data.error || 'Failed to delete reel');
            }
        } catch (error) {
            console.error('Delete error:', error);
            showToast('An error occurred while deleting');
        }
        
        deleteModal.classList.remove('active');
        reelToDelete = null;
    });
}

// Close modal when clicking outside
if (deleteModal) {
    deleteModal.addEventListener('click', (e) => {
        if (e.target === deleteModal) {
            deleteModal.classList.remove('active');
            reelToDelete = null;
        }
    });
}

function showToast(message) {
    const toast = document.createElement('div');
    toast.textContent = message;
    toast.style.cssText = 'position:fixed;bottom:100px;left:50%;transform:translateX(-50%);background:rgba(0,0,0,0.8);color:white;padding:8px 16px;border-radius:30px;z-index:2000;font-size:13px';
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 3000);
}

// ==================== Open Reel in Reels Page ====================
// When clicking a reel card (not the menu button), redirect to reels.php
document.querySelectorAll('.reel-card').forEach(card => {
    card.addEventListener('click', (e) => {
        // Don't redirect if clicking on the menu button
        if (e.target.closest('.reel-menu-btn')) {
            return;
        }
        
        const reelId = card.dataset.reelId;
        const userId = card.dataset.userId;
        const tab = card.dataset.tab || 'reels';
        
        if (reelId) {
            window.location.href = `reels.php?reel=${reelId}&user=${userId}&tab=${tab}`;
        }
    });
});

console.log('Profile page loaded - with Bookmarks/Saved tab for own profile');
</script>
</body>
</html>