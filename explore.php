<?php  
include 'core/init.php';

if (User::checkLogIn() === false) 
    header('location: index.php');    

$user_id = $_SESSION['user_id'];
$user = User::getData($user_id);
$who_users = Follow::whoToFollow($user_id);

// Update notifications
User::updateNotifications($user_id);
$notify_count = User::CountNotification($user_id);
// Count total unread messages for this user
$conn = Connect::connect();
$stmt = $conn->prepare("SELECT COUNT(*) AS unread_total FROM messages WHERE receiver_id = ? AND is_read = 0");
$stmt->execute([$user_id]);
$unreadMessages = $stmt->fetch(PDO::FETCH_OBJ)->unread_total ?? 0;


/**
 * Dynamic Explore Tabs AJAX
 * - Keeps existing For You functionality untouched.
 * - Latest / Top / News tabs load from this same file.
 */
function explore_table_exists($conn, $table) {
    try {
        $stmt = $conn->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

function explore_column_exists($conn, $table, $column) {
    try {
        $stmt = $conn->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

function explore_render_tweets($tweets) {
    if (!empty($tweets) && is_array($tweets)) {
        $printed = false;
        foreach ($tweets as $tweet) {
            if (isset($tweet) && isset($tweet->id) && (Tweet::isTweet($tweet->id) || Tweet::isRetweet($tweet->id))) {
                $printed = true;
                include 'includes/tweets.php';
            }
        }
        if (!$printed) {
            echo '<div class="explore-empty-state"><i class="far fa-comment-dots"></i><p>No posts found</p></div>';
        }
    } else {
        echo '<div class="explore-empty-state"><i class="far fa-comment-dots"></i><p>No posts found</p></div>';
    }
}

function explore_get_posts($conn, $tab = 'latest', $offset = 0, $limit = 20, $search = '') {
    $offset = max(0, (int)$offset);
    $limit = max(1, min(50, (int)$limit));
    $tab = in_array($tab, ['foryou', 'latest', 'top', 'news', 'photo', 'video'], true) ? $tab : 'latest';
    $search = trim((string)$search);

    /*
     * UPDATED:
     * Dynamic Explore tabs now support normal tweets, retweets, quote tweets,
     * and multimedia from original/quoted/retweeted source posts.
     * The main For You tab display remains untouched.
     */
    $likesExpr = "(SELECT COUNT(*) FROM likes l WHERE l.post_id = p.id)";
    $commentsExpr = "(SELECT COUNT(*) FROM comments c WHERE c.post_id = p.id)";
    $retweetsExpr = "(SELECT COUNT(*) FROM retweets r WHERE (r.tweet_id = p.id OR r.retweet_id = p.id))";

    $sourceExpr = "COALESCE(t.post_id, rt.tweet_id, rt.retweet_id, p.id)";

    $where = [];
    $params = [];

    $where[] = "p.user_id != ?";
    $params[] = isset($GLOBALS['user_id']) ? (int)$GLOBALS['user_id'] : 0;

    $where[] = "(t.post_id IS NOT NULL OR rt.post_id IS NOT NULL)";

    if (explore_column_exists($conn, 'users', 'is_blocked')) {
        $where[] = "(u.is_blocked IS NULL OR u.is_blocked = 0)";
    }

    if ($search !== '') {
        $where[] = "(
            t.status LIKE ? OR
            src_t.status LIKE ? OR
            quoted_t.status LIKE ? OR
            rt.retweet_msg LIKE ? OR
            u.username LIKE ? OR
            u.name LIKE ?
        )";
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    if ($tab === 'news') {
        $where[] = "(
            t.status LIKE '%#news%' OR t.status LIKE '%#breaking%' OR t.status LIKE '%#update%' OR
            t.status LIKE '%news%' OR t.status LIKE '%breaking%' OR t.status LIKE '%update%' OR
            t.status LIKE '%latest%' OR t.status LIKE '%http%' OR t.status LIKE '%خبر%' OR

            src_t.status LIKE '%#news%' OR src_t.status LIKE '%#breaking%' OR src_t.status LIKE '%#update%' OR
            src_t.status LIKE '%news%' OR src_t.status LIKE '%breaking%' OR src_t.status LIKE '%update%' OR
            src_t.status LIKE '%latest%' OR src_t.status LIKE '%http%' OR src_t.status LIKE '%خبر%' OR

            quoted_t.status LIKE '%#news%' OR quoted_t.status LIKE '%#breaking%' OR quoted_t.status LIKE '%#update%' OR
            quoted_t.status LIKE '%news%' OR quoted_t.status LIKE '%breaking%' OR quoted_t.status LIKE '%update%' OR
            quoted_t.status LIKE '%latest%' OR quoted_t.status LIKE '%http%' OR quoted_t.status LIKE '%خبر%' OR

            rt.retweet_msg LIKE '%#news%' OR rt.retweet_msg LIKE '%#breaking%' OR rt.retweet_msg LIKE '%#update%' OR
            rt.retweet_msg LIKE '%news%' OR rt.retweet_msg LIKE '%breaking%' OR rt.retweet_msg LIKE '%update%' OR
            rt.retweet_msg LIKE '%latest%' OR rt.retweet_msg LIKE '%http%' OR rt.retweet_msg LIKE '%خبر%'
        )";
    }

    if ($tab === 'photo') {
        $where[] = "(
            (t.img IS NOT NULL AND t.img != '') OR
            (src_t.img IS NOT NULL AND src_t.img != '') OR
            (quoted_t.img IS NOT NULL AND quoted_t.img != '') OR
            EXISTS (SELECT 1 FROM tweet_media tm WHERE tm.tweet_id = $sourceExpr AND (tm.media_type = 'image' OR tm.media_type IS NULL OR tm.media_type = '')) OR
            EXISTS (SELECT 1 FROM tweet_media tm2 WHERE tm2.tweet_id = p.id AND (tm2.media_type = 'image' OR tm2.media_type IS NULL OR tm2.media_type = ''))
        )";
    }

    if ($tab === 'video') {
        $where[] = "(
            (t.video IS NOT NULL AND t.video != '') OR
            (src_t.video IS NOT NULL AND src_t.video != '') OR
            (quoted_t.video IS NOT NULL AND quoted_t.video != '') OR
            EXISTS (SELECT 1 FROM tweet_media tmv WHERE tmv.tweet_id = $sourceExpr AND tmv.media_type = 'video') OR
            EXISTS (SELECT 1 FROM tweet_media tmv2 WHERE tmv2.tweet_id = p.id AND tmv2.media_type = 'video') OR
            EXISTS (SELECT 1 FROM tweet_videos tv WHERE tv.tweet_id = $sourceExpr) OR
            EXISTS (SELECT 1 FROM tweet_videos tv2 WHERE tv2.tweet_id = p.id)
        )";
    }

    $whereSql = 'WHERE ' . implode(' AND ', $where);

    if ($tab === 'top') {
        $orderSql = "ORDER BY (p.post_on >= NOW() - INTERVAL 7 DAY) DESC, engagement_score DESC, p.post_on DESC, p.id DESC";
    } else {
        $orderSql = "ORDER BY p.post_on DESC, engagement_score DESC, p.id DESC";
    }

    $sql = "
        SELECT 
            p.id AS id,
            p.user_id,
            p.post_on,

            t.post_id AS tweet_post_id,
            t.status,
            t.img,
            t.video,

            rt.post_id AS retweet_post_id,
            rt.retweet_msg,
            rt.tweet_id,
            rt.retweet_id,

            u.name,
            u.username,
            u.img AS user_img,

            $sourceExpr AS source_post_id,

            CASE 
                WHEN t.post_id IS NOT NULL THEN 'tweet'
                WHEN rt.post_id IS NOT NULL AND rt.retweet_msg IS NULL THEN 'retweet'
                WHEN rt.post_id IS NOT NULL AND rt.retweet_msg IS NOT NULL THEN 'quote'
                ELSE 'unknown'
            END AS post_type,

            (($likesExpr) + ($commentsExpr) + ($retweetsExpr)) AS engagement_score

        FROM posts p
        LEFT JOIN tweets t ON t.post_id = p.id
        LEFT JOIN retweets rt ON rt.post_id = p.id
        LEFT JOIN tweets src_t ON src_t.post_id = rt.tweet_id
        LEFT JOIN retweets quoted_rt ON quoted_rt.post_id = rt.retweet_id
        LEFT JOIN tweets quoted_t ON quoted_t.post_id = quoted_rt.tweet_id
        INNER JOIN users u ON p.user_id = u.id

        $whereSql
        $orderSql
        LIMIT $limit OFFSET $offset
    ";

    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    } catch (Exception $e) {
        return [];
    }
}


function explore_get_people($conn, $offset = 0, $limit = 20, $search = '') {
    $offset = max(0, (int)$offset);
    $limit = max(1, min(50, (int)$limit));
    $search = trim((string)$search);

    $where = ["u.id != ?"];
    $params = [isset($GLOBALS['user_id']) ? (int)$GLOBALS['user_id'] : 0];

    if (explore_column_exists($conn, 'users', 'is_blocked')) {
        $where[] = "(u.is_blocked IS NULL OR u.is_blocked = 0)";
    }

    if ($search !== '') {
        $where[] = "(u.username LIKE ? OR u.name LIKE ? OR u.bio LIKE ? OR u.location LIKE ? OR u.website LIKE ?)";
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $whereSql = 'WHERE ' . implode(' AND ', $where);

    $sql = "
        SELECT
            u.id,
            u.name,
            u.username,
            u.img,
            u.bio,
            u.location,
            (SELECT COUNT(*) FROM follow f WHERE f.following_id = u.id) AS followers_count,
            (SELECT COUNT(*) FROM posts p WHERE p.user_id = u.id) AS posts_count
        FROM users u
        $whereSql
        ORDER BY followers_count DESC, posts_count DESC, u.id DESC
        LIMIT $limit OFFSET $offset
    ";

    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_OBJ);
    } catch (Exception $e) {
        return [];
    }
}

function explore_render_people($people, $viewer_id) {
    if (empty($people) || !is_array($people)) {
        echo '<div class="explore-empty-state"><i class="far fa-user"></i><p>No people found</p></div>';
        return;
    }

    foreach ($people as $person) {
        $is_following = Follow::isUserFollow($viewer_id, $person->id);
        $profileUrl = BASE_URL . $person->username;
        $img = !empty($person->img) ? $person->img : 'default.jpg';
        ?>
        <div class="explore-person-card">
            <a href="<?php echo htmlspecialchars($profileUrl); ?>" class="explore-person-avatar-link">
                <img src="assets/images/users/<?php echo htmlspecialchars($img); ?>" alt="" class="explore-person-avatar">
            </a>
            <div class="explore-person-info">
                <a href="<?php echo htmlspecialchars($profileUrl); ?>" class="explore-person-name">
                    <?php echo htmlspecialchars($person->name); ?>
                </a>
                <a href="<?php echo htmlspecialchars($profileUrl); ?>" class="explore-person-username">
                    @<?php echo htmlspecialchars($person->username); ?>
                </a>
                <?php if (!empty($person->bio)): ?>
                    <div class="explore-person-bio"><?php echo htmlspecialchars($person->bio); ?></div>
                <?php endif; ?>
                <div class="explore-person-meta">
                    <?php echo (int)$person->followers_count; ?> followers · <?php echo (int)$person->posts_count; ?> posts
                </div>
            </div>
            <div class="explore-person-action">
                <button class="follow-btn follow-btn-m <?php echo $is_following ? 'following' : 'follow'; ?>"
                    data-follow="<?php echo (int)$person->id; ?>"
                    data-user="<?php echo (int)$viewer_id; ?>"
                    style="font-weight:700;">
                    <?php echo $is_following ? 'Following' : 'Follow'; ?>
                </button>
            </div>
        </div>
        <?php
    }
}

if (isset($_POST['explore_action']) && $_POST['explore_action'] === 'load_tab') {
    if (User::checkLogIn() === false) {
        http_response_code(403);
        exit;
    }

    $ajaxConn = Connect::connect();
    $ajaxTab = $_POST['tab'] ?? 'latest';
    if ($ajaxTab === 'foryou' && trim((string)($_POST['search'] ?? '')) === '') {
        exit;
    }
    $ajaxOffset = isset($_POST['offset']) ? (int)$_POST['offset'] : 0;
    $ajaxSearch = $_POST['search'] ?? '';

    if ($ajaxTab === 'people') {
        $people = explore_get_people($ajaxConn, $ajaxOffset, 20, $ajaxSearch);
        explore_render_people($people, $user_id);
        exit;
    }

    $tweets = explore_get_posts($ajaxConn, $ajaxTab, $ajaxOffset, 20, $ajaxSearch);
    explore_render_tweets($tweets);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Explore | TwitterClone</title>

  <link rel="stylesheet" href="assets/css/bootstrap.min.css">
  <link rel="stylesheet" href="assets/css/all.min.css">
  <link rel="stylesheet" href="assets/css/home_style.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="assets/css/profile_style.css?v=<?php echo time(); ?>">

  <link rel="shortcut icon" type="image/png" href="assets/images/twitter.svg">
  
  <style>
    /* ========== SEARCH STYLES ADDED ========== */
    .input-group {
        position: relative;
        width: 100%;
    }
    
    .search-input {
        width: 100%;
        padding: 12px 40px 12px 45px;
        border: 2px solid #e1e8ed;
        border-radius: 50px;
        font-size: 15px;
        background: #f5f8fa;
        transition: all 0.3s ease;
    }
    
    .search-input:focus {
        outline: none;
        border-color: #1DA1F2;
        background: #ffffff;
        box-shadow: 0 0 0 3px rgba(29, 161, 242, 0.1);
    }
    
    .search-input::placeholder {
        color: #8899a6;
        font-size: 14px;
    }
    
    #icon-search {
        position: absolute;
        left: 15px;
        top: 50%;
        transform: translateY(-50%);
        color: #657786;
        font-size: 18px;
        z-index: 10;
        pointer-events: none;
    }
    
    .search-result {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        background: white;
        border-radius: 16px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.15);
        margin-top: 8px;
        z-index: 1000;
        max-height: 400px;
        overflow-y: auto;
        display: none;
    }
    
    .search-result-item {
        display: flex;
        align-items: center;
        padding: 12px 16px;
        cursor: pointer;
        transition: background 0.2s ease;
        border-bottom: 1px solid #e6ecf0;
        text-decoration: none;
    }
    
    .search-result-item:last-child {
        border-bottom: none;
    }
    
    .search-result-item:hover {
        background: #f5f8fa;
    }
    
    .search-result-img {
        width: 44px;
        height: 44px;
        border-radius: 50%;
        object-fit: cover;
        margin-right: 12px;
    }
    
    .search-result-info {
        flex: 1;
    }
    
    .search-result-name {
        font-weight: 700;
        font-size: 15px;
        color: #14171a;
        margin-bottom: 2px;
    }
    
    .search-result-username {
        font-size: 13px;
        color: #657786;
    }
    
    .search-result-bio {
        font-size: 12px;
        color: #8899a6;
        margin-top: 2px;
    }
    
    .search-result-badge {
        background: #e8f5fe;
        color: #1DA1F2;
        font-size: 11px;
        padding: 2px 8px;
        border-radius: 20px;
        margin-left: 8px;
        font-weight: 500;
    }
    
    .search-loading {
        padding: 20px;
        text-align: center;
        color: #657786;
    }
    
    .search-loading i {
        animation: spin 1s linear infinite;
    }
    
    @keyframes spin {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }
    
    .search-no-results {
        padding: 30px 20px;
        text-align: center;
        color: #657786;
    }
    
    .search-no-results i {
        font-size: 40px;
        margin-bottom: 10px;
        display: block;
        color: #e1e8ed;
    }
    
    .search-load-more {
        padding: 12px;
        text-align: center;
        border-top: 1px solid #e6ecf0;
    }
    
    .load-more-search {
        background: transparent;
        border: none;
        color: #1DA1F2;
        font-weight: 600;
        font-size: 14px;
        cursor: pointer;
        padding: 8px 16px;
        border-radius: 30px;
        transition: all 0.2s ease;
        width: 100%;
    }
    
    .load-more-search:hover {
        background: rgba(29, 161, 242, 0.1);
    }
    
    .search-result::-webkit-scrollbar {
        width: 6px;
    }
    
    .search-result::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 10px;
    }
    
    .search-result::-webkit-scrollbar-thumb {
        background: #1DA1F2;
        border-radius: 10px;
    }
    
    .sidebar-unread-badge {
        position: absolute;
        top: -8px;
        right: -8px;
        background: #1da1f2;
        color: white;
        font-size: 11px;
        border-radius: 50%;
        padding: 2px 6px;
        min-width: 18px;
        text-align: center;
    }
    #mine .grid-share{
	padding: 12px 16px;
	margin-top: 0;
	display: flex;
	align-items: center;
	gap: 12px;
	transition: 0.5s;
	border-bottom: 1px solid #e6ecf0;
  }
  
  #mine .grid-share:last-child {
	border-bottom: none;
  }
  
  #mine .grid-share > div:first-of-type {
	flex: 1;
  }
  
  #mine .grid-share > div:last-child {
	margin-left: auto;
  }
  
  #mine .grid-share p {
	margin: 0;
	line-height: 1.3;
  }
  
  #mine .grid-share .username {
	font-size: 13px;
	color: #657786;
	margin-top: 2px;
	display: block;
  }
  
  #mine .grid-share .img-share {
	width: 48px;
	height: 48px;
	border-radius: 50%;
	object-fit: cover;
	flex-shrink: 0;
  }


    /* ========== EXPLORE CENTER SEARCH + TABS ========== */
    .explore-top-search-wrap {
        box-sizing: border-box;
        width: auto;
        max-width: 100%;
        margin: 0 16px 0 200px; /* more left gap so it never touches/overflows into sidebar */
        padding: 10px 0 12px;
        border-bottom: 1px solid #e6ecf0;
        background: #fff;
        position: sticky;
        top: 0;
        z-index: 20;
        overflow: hidden;
    }

    .explore-feed-search-box {
        position: relative;
        width: 100%;
        box-sizing: border-box;
    }

    .explore-feed-search-box i {
        position: absolute;
        left: 16px;
        top: 50%;
        transform: translateY(-50%);
        color: #657786;
        font-size: 16px;
        pointer-events: none;
    }

    #exploreFeedSearch {
        width: 100%;
        box-sizing: border-box;
        border: 1px solid #e6ecf0;
        background: #f5f8fa;
        border-radius: 999px;
        padding: 12px 42px 12px 44px;
        font-size: 15px;
        outline: none;
        transition: 0.2s ease;
    }

    #exploreFeedSearch:focus {
        background: #fff;
        border-color: #1DA1F2;
        box-shadow: 0 0 0 3px rgba(29, 161, 242, 0.10);
    }

    .clear-explore-search {
        position: absolute;
        right: 12px;
        top: 50%;
        transform: translateY(-50%);
        border: 0;
        background: #1DA1F2;
        color: #fff;
        width: 22px;
        height: 22px;
        line-height: 22px;
        border-radius: 50%;
        display: none;
        cursor: pointer;
        font-size: 14px;
        padding: 0;
    }

    .explore-tabs {
        box-sizing: border-box;
        width: auto;
        max-width: 100%;
        margin: 0 16px 0 200px; /* same safe left gap as search box */
        overflow: hidden;
        display: flex;
        align-items: center;
        border-bottom: 1px solid #e6ecf0;
        background: #fff;
        position: sticky;
        top: 72px;
        z-index: 19;
    }

    .explore-tab-btn {
        flex: 1 1 0;
        min-width: 0;
        border: 0;
        background: transparent;
        padding: 15px 6px;
        color: #657786;
        font-weight: 700;
        cursor: pointer;
        position: relative;
        transition: 0.2s ease;
        white-space: nowrap;
        text-align: center;
        font-size: 15px;
    }

    .explore-tab-btn:hover {
        background: #f5f8fa;
        color: #14171a;
    }

    .explore-tab-btn.active {
        color: #14171a;
    }

    .explore-tab-btn.active:after {
        content: '';
        position: absolute;
        left: 32%;
        right: 32%;
        bottom: 0;
        height: 4px;
        background: #1DA1F2;
        border-radius: 999px;
    }

    .explore-tab-panel { display: none; }
    .explore-tab-panel.active { display: block; }

    .explore-empty-state,
    .explore-tab-loading,
    .explore-no-more {
        text-align: center;
        padding: 28px 16px;
        color: #657786;
        border-bottom: 1px solid #e6ecf0;
    }

    .explore-empty-state i {
        font-size: 32px;
        color: #ccd6dd;
        margin-bottom: 8px;
        display: block;
    }


    /* Keep Explore search/tabs inside center column on every screen */
    .grid-posts,
    .border-right,
    .grid-toolbar-center,
    .center-input-search {
        min-width: 0;
    }

    @media (max-width: 1200px) {
        .explore-top-search-wrap,
        .explore-tabs {
            margin-left: 22px;
            margin-right: 14px;
        }
    }

    @media (max-width: 991px) {
        .explore-top-search-wrap,
        .explore-tabs {
            margin-left: 18px;
            margin-right: 12px;
        }
        .explore-tab-btn {
            font-size: 14px;
            padding: 14px 4px;
        }
    }

    @media (max-width: 575px) {
        .explore-top-search-wrap,
        .explore-tabs {
            margin-left: 12px;
            margin-right: 12px;
        }
        #exploreFeedSearch {
            font-size: 14px;
            padding: 11px 38px 11px 40px;
        }
        .explore-tab-btn {
            font-size: 13px;
            padding: 13px 2px;
        }
        .explore-tab-btn.active:after {
            left: 24%;
            right: 24%;
        }
    }


    .explore-person-card {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px 12px;
        border-bottom: 1px solid #e6ecf0;
        background: #fff;
        width: 100%;
        box-sizing: border-box;
        overflow: hidden;
    }

    .explore-person-card:hover {
        background: #f7f9fa;
    }

    .explore-person-avatar {
        width: 44px;
        height: 44px;
        border-radius: 50%;
        object-fit: cover;
        flex-shrink: 0;
        display: block;
    }

    .explore-person-avatar-link {
        flex-shrink: 0;
    }

    .explore-person-info {
        flex: 1 1 auto;
        min-width: 0;
        overflow: hidden;
    }

    .explore-person-name {
        display: block;
        color: #14171a;
        font-weight: 700;
        text-decoration: none;
        line-height: 1.2;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 100%;
    }

    .explore-person-name:hover,
    .explore-person-username:hover {
        text-decoration: underline;
    }

    .explore-person-username {
        display: block;
        color: #657786;
        font-size: 13px;
        text-decoration: none;
        margin-top: 1px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .explore-person-bio {
        color: #14171a;
        font-size: 13px;
        line-height: 1.3;
        margin-top: 4px;
        word-break: break-word;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }

    .explore-person-meta {
        color: #657786;
        font-size: 12px;
        margin-top: 4px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .explore-person-action {
        flex: 0 0 auto;
        margin-left: 6px;
    }

    .explore-person-action .follow-btn {
        padding: 6px 13px !important;
        font-size: 13px !important;
        line-height: 1.2 !important;
        min-width: 78px;
        white-space: nowrap;
    }

    @media (max-width: 575px) {
        .explore-person-card {
            padding: 9px 10px;
            gap: 8px;
        }
        .explore-person-avatar {
            width: 38px;
            height: 38px;
        }
        .explore-person-action {
            margin-left: 4px;
        }
        .explore-person-action .follow-btn {
            padding: 5px 9px !important;
            font-size: 12px !important;
            min-width: 66px;
        }
        .explore-person-bio {
            -webkit-line-clamp: 1;
        }
        .explore-tabs {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        .explore-tabs::-webkit-scrollbar {
            display: none;
        }
        .explore-tab-btn {
            flex: 0 0 auto;
            min-width: 76px;
        }
    }

  </style>
</head>
<body>

<script src="assets/js/jquery-3.5.1.min.js"></script>

<div id="mine">
  <!-- LEFT SIDEBAR -->
  <div class="wrapper-left">
    <div class="sidebar-left">
      <div class="grid-sidebar" style="margin-top: 12px">
        <div class="icon-sidebar-align">
          <img src="<?php echo BASE_URL . "/assets/images/twitter-logo.png"; ?>" alt="Twitter" height="30px" width="30px" />
        </div>
      </div>

      <a href="home.php">
        <div class="grid-sidebar">
          <div class="icon-sidebar-align">
            <img src="<?php echo BASE_URL . "/includes/icons/tweethome.png"; ?>" alt="Home" height="26" width="26" />
          </div>
          <div class="wrapper-left-elements">
            <strong>Home</strong>
          </div>
        </div>
      </a>

      <a href="explore.php">
        <div class="grid-sidebar bg-active">
          <div class="icon-sidebar-align">
            <img src="<?php echo BASE_URL . "/includes/icons/tweetsearch.png"; ?>" alt="Explore" height="26" width="26" />
          </div>
          <div class="wrapper-left-elements">
            <strong>Explore</strong>
          </div>
        </div>
      </a>

      <a href="reels.php">
  <div class="grid-sidebar <?php echo basename($_SERVER['PHP_SELF']) == 'reels.php' ? 'bg-active' : ''; ?>" style="margin-top: 12px">
    <div class="icon-sidebar-align">
      <img src="<?php echo BASE_URL . "/includes/icons/tweetreels.png"; ?>" alt="Reels" height="26.25px" width="26.25px" />
    </div>
    <div class="wrapper-left-elements">
      <a href="reels.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'reels.php' ? 'wrapper-left-active' : ''; ?>" style="margin-top: 4px;">
        <strong>Reels</strong>
      </a>
    </div>
  </div>
</a>

<a href="messages.php">
  <div class="grid-sidebar <?php echo basename($_SERVER['PHP_SELF']) == 'messages.php' ? 'bg-active' : ''; ?>" 
       style="margin-top: 12px; position: relative;">
    <div class="icon-sidebar-align" style="position: relative;">
      <img src="<?php echo BASE_URL . "/includes/icons/tweetmessage.png"; ?>" 
           alt="Messages" 
           height="26.25px" 
           width="26.25px" />
      
      <?php if (!empty($unreadMessages) && $unreadMessages > 0): ?>
        <span class="sidebar-unread-badge"><?php echo $unreadMessages; ?></span>
      <?php endif; ?>
    </div>

    <div class="wrapper-left-elements">
      <a href="messages.php" 
         class="<?php echo basename($_SERVER['PHP_SELF']) == 'messages.php' ? 'wrapper-left-active' : ''; ?>" 
         style="margin-top: 4px;">
        <strong>Messages</strong>
      </a>
    </div>
  </div>
</a>

      <a href="notification.php">
        <div class="grid-sidebar">
          <div class="icon-sidebar-align position-relative">
            <?php if ($notify_count > 0) { ?>
              <i class="notify-count"><?php echo $notify_count; ?></i>
            <?php } ?>
            <img src="<?php echo BASE_URL . "/includes/icons/tweetnotif.png"; ?>" alt="Notifications" height="26" width="26" />
          </div>
          <div class="wrapper-left-elements">
            <strong>Notifications</strong>
          </div>
        </div>
      </a>
                <!-- BOOKMARKS -->
<a href="bookmarks.php">
  <div class="grid-sidebar">
    <div class="icon-sidebar-align">
      <img src="<?php echo BASE_URL . "/includes/icons/tweetbookmark.png"; ?>" 
           alt="Bookmarks" 
           height="26.25px" 
           width="26.25px" />
    </div>
    <div class="wrapper-left-elements">
      <a href="bookmarks.php" style="margin-top: 4px;"><strong>Bookmarks</strong></a>
    </div>
  </div>
</a>

      <a href="<?php echo BASE_URL . $user->username; ?>">
        <div class="grid-sidebar">
          <div class="icon-sidebar-align">
            <img src="<?php echo BASE_URL . "/includes/icons/tweetprof.png"; ?>" alt="Profile" height="26" width="26" />
          </div>
          <div class="wrapper-left-elements">
            <strong>Profile</strong>
          </div>
        </div>
      </a>

      <a href="account.php">
        <div class="grid-sidebar">
          <div class="icon-sidebar-align">
            <img src="<?php echo BASE_URL . "/includes/icons/tweetsetting.png"; ?>" alt="Settings" height="26" width="26" />
          </div>
          <div class="wrapper-left-elements">
            <strong>Settings</strong>
          </div>
        </div>
      </a>

      <a href="includes/logout.php">
        <div class="grid-sidebar">
          <div class="icon-sidebar-align">
            <i class="fas fa-sign-out-alt" style="font-size: 26px;"></i>
          </div>
          <div class="wrapper-left-elements">
            <strong>Logout</strong>
          </div>
        </div>
      </a>

      <button class="button-twittear"><strong>Tweet</strong></button>

      <?php include 'includes/profile_dropdown.php'; ?>
    </div>
  </div>

  <!-- CENTER SECTION -->
  <div class="grid-posts">
    <div class="border-right">
<div class="grid-toolbar-center">
  <div class="center-input-search">
    <div class="container d-flex align-items-center py-2">
      <a href="javascript: history.go(-1);" class="mr-3 text-dark">
        <i class="fas fa-arrow-left" style="font-size:20px;"></i>
      </a>
      <h2 style="margin: 0; font-size: 20px; font-weight: 700;">Explore</h2>
    </div>
  </div>
</div>

      

      <div class="explore-top-search-wrap">
        <div class="explore-feed-search-box">
          <i class="fas fa-search"></i>
          <input type="text" id="exploreFeedSearch" placeholder="Search posts or people">
          <button type="button" class="clear-explore-search" aria-label="Clear search">&times;</button>
        </div>
      </div>

      <div class="explore-tabs" role="tablist">
        <button type="button" class="explore-tab-btn active" data-tab="foryou">For You</button>
        <button type="button" class="explore-tab-btn" data-tab="latest">Latest</button>
        <button type="button" class="explore-tab-btn" data-tab="top">Top</button>
        <button type="button" class="explore-tab-btn" data-tab="news">News</button>
        <button type="button" class="explore-tab-btn" data-tab="people">People</button>
        <button type="button" class="explore-tab-btn" data-tab="photo">Photo</button>
        <button type="button" class="explore-tab-btn" data-tab="video">Video</button>
      </div>

      <div class="explore-tab-panel active" id="explore-tab-foryou">
        <div class="explore-center-placeholder">
          <?php Tweet::displayExplorePosts($user_id); ?>
        </div>
      </div>

      <div class="explore-tab-panel" id="explore-tab-latest">
        <div class="explore-dynamic-feed" data-tab="latest"></div>
      </div>

      <div class="explore-tab-panel" id="explore-tab-top">
        <div class="explore-dynamic-feed" data-tab="top"></div>
      </div>

      <div class="explore-tab-panel" id="explore-tab-news">
        <div class="explore-dynamic-feed" data-tab="news"></div>
      </div>

      <div class="explore-tab-panel" id="explore-tab-people">
        <div class="explore-dynamic-feed" data-tab="people"></div>
      </div>

      <div class="explore-tab-panel" id="explore-tab-photo">
        <div class="explore-dynamic-feed" data-tab="photo"></div>
      </div>

      <div class="explore-tab-panel" id="explore-tab-video">
        <div class="explore-dynamic-feed" data-tab="video"></div>
      </div>
    </div>
  </div>

  <!-- RIGHT SIDEBAR -->
  <div class="wrapper-right">
    <div style="width: 90%;" class="container">
      <div class="input-group py-2 m-auto pr-5 position-relative">
        <i id="icon-search" class="fas fa-search tryy"></i>
        <input type="text" class="form-control search-input" id="searchInput" placeholder="Search Twitter">
        <div class="search-result"></div>
      </div>
    </div>

    <div class="box-share">
      <p class="txt-share"><strong>Who to follow</strong></p>
      <?php 
      foreach($who_users as $who) { 
          $user_follow = Follow::isUserFollow($user_id , $who->id);
      ?>
      <div class="grid-share">
        <a href="<?php echo $who->username; ?>" style="color:black">
          <img src="assets/images/users/<?php echo $who->img; ?>" alt="" class="img-share" />
        </a>
        <div>
          <p><strong><?php echo $who->name; ?></strong></p>
          <p class="username">@<?php echo $who->username; ?></p>
        </div>
        <div>
          <button class="follow-btn follow-btn-m <?= $user_follow ? 'following' : 'follow' ?>"
            data-follow="<?php echo $who->id; ?>"
            data-user="<?php echo $user_id; ?>"
            style="font-weight:700;">
            <?php echo $user_follow ? 'Following' : 'Follow'; ?>
          </button>
        </div>
      </div>
      <?php } ?>
    </div>

    <?php
    $trends = Tweet::trendingHashtags();
    echo Tweet::getTrendingHashtagsBox($trends);
    ?>
  </div>
</div>

<script src="assets/js/search.js"></script>
<script src="assets/js/photo.js"></script>
<script src="assets/js/follow.js?v=<?php echo time(); ?>"></script>
<script src="https://kit.fontawesome.com/38e12cc51b.js" crossorigin="anonymous"></script>
<script src="assets/js/bootstrap.min.js"></script>
<script src="assets/js/like.js"></script>
<script src="assets/js/comment.js?v=<?php echo time(); ?>"></script>
<script src="assets/js/retweet.js?v=<?php echo time(); ?>"></script>
<script src="assets/js/bookmark.js?v=<?php echo time(); ?>"></script>

      <!-- <script src="assets/js/jquery-3.4.1.slim.min.js"></script> -->
      <script src="assets/js/jquery-3.5.1.min.js"></script>
        <script src="assets/js/popper.min.js"></script>
        <script src="assets/js/bootstrap.min.js"></script>
        
<script>
$(document).ready(function() {
  const tabState = {
    foryou: { offset: 20, loading: false, hasMore: true, loaded: true },
    latest: { offset: 0, loading: false, hasMore: true, loaded: false },
    top: { offset: 0, loading: false, hasMore: true, loaded: false },
    news: { offset: 0, loading: false, hasMore: true, loaded: false },
    people: { offset: 0, loading: false, hasMore: true, loaded: false },
    photo: { offset: 0, loading: false, hasMore: true, loaded: false },
    video: { offset: 0, loading: false, hasMore: true, loaded: false }
  };

  let activeExploreTab = 'foryou';
  let exploreSearchTimer;

  function getExploreSearch() {
    return $('#exploreFeedSearch').val().trim();
  }

  function updateClearButton() {
    $('.clear-explore-search').toggle(getExploreSearch().length > 0);
  }

  function showTab(tab) {
    activeExploreTab = tab;
    $('.explore-tab-btn').removeClass('active');
    $('.explore-tab-btn[data-tab="' + tab + '"]').addClass('active');
    $('.explore-tab-panel').removeClass('active');
    $('#explore-tab-' + tab).addClass('active');

    if (tab !== 'foryou' && !tabState[tab].loaded) {
      resetAndLoadDynamicTab(tab);
    }

    if (tab === 'foryou') {
      if (getExploreSearch() !== '') {
        resetAndLoadForYouSearch();
      } else {
        resetForYouNormal();
      }
    }
  }

  let savedForYouHtml = $('#explore-tab-foryou .explore-center-placeholder').html();
  let forYouSearchMode = false;

  function resetForYouNormal() {
    const $feed = $('#explore-tab-foryou .explore-center-placeholder');
    if (forYouSearchMode) {
      $feed.html(savedForYouHtml);
      tabState.foryou = { offset: 20, loading: false, hasMore: true, loaded: true };
      forYouSearchMode = false;
    }
  }

  function resetAndLoadForYouSearch() {
    const $feed = $('#explore-tab-foryou .explore-center-placeholder');
    if (!forYouSearchMode) savedForYouHtml = $feed.html();
    forYouSearchMode = true;
    tabState.foryou = { offset: 0, loading: false, hasMore: true, loaded: true };
    $feed.html('');
    loadForYouSearch(true);
  }

  function loadForYouSearch(replace) {
    if (tabState.foryou.loading || !tabState.foryou.hasMore) return;

    const $feed = $('#explore-tab-foryou .explore-center-placeholder');
    tabState.foryou.loading = true;

    $.ajax({
      url: 'explore.php',
      type: 'POST',
      data: {
        explore_action: 'load_tab',
        tab: 'foryou',
        offset: tabState.foryou.offset,
        search: getExploreSearch()
      },
      beforeSend: function() {
        if (replace) {
          $feed.html('<div class="explore-tab-loading"><i class="fas fa-spinner fa-pulse"></i> Searching posts...</div>');
        } else {
          $feed.append('<div class="explore-tab-loading"><i class="fas fa-spinner fa-pulse"></i> Loading more...</div>');
        }
      },
      success: function(response) {
        $feed.find('.explore-tab-loading').remove();
        const html = $.trim(response);

        if (html !== '') {
          if (replace) $feed.html(html); else $feed.append(html);
          tabState.foryou.offset += 20;
          tabState.foryou.hasMore = html.indexOf('explore-empty-state') === -1;
        } else {
          tabState.foryou.hasMore = false;
          if (tabState.foryou.offset === 0) {
            $feed.html('<div class="explore-empty-state"><i class="fas fa-search"></i><p>No matching posts found</p></div>');
          } else if (!$feed.find('.explore-no-more').length) {
            $feed.append('<div class="explore-no-more">No more matching posts</div>');
          }
        }
      },
      error: function() {
        $feed.find('.explore-tab-loading').remove();
        if (replace) {
          $feed.html('<div class="explore-empty-state"><i class="fas fa-exclamation-triangle"></i><p>Error searching posts</p></div>');
        }
      },
      complete: function() {
        tabState.foryou.loading = false;
      }
    });
  }

  function resetAndLoadDynamicTab(tab) {
    const $feed = $('#explore-tab-' + tab + ' .explore-dynamic-feed');
    tabState[tab] = { offset: 0, loading: false, hasMore: true, loaded: true };
    $feed.html('');
    loadDynamicTab(tab, true);
  }

  function loadDynamicTab(tab, replace) {
    if (tab === 'foryou' || tabState[tab].loading || !tabState[tab].hasMore) return;

    const $feed = $('#explore-tab-' + tab + ' .explore-dynamic-feed');
    tabState[tab].loading = true;

    $.ajax({
      url: 'explore.php',
      type: 'POST',
      data: {
        explore_action: 'load_tab',
        tab: tab,
        offset: tabState[tab].offset,
        search: getExploreSearch()
      },
      beforeSend: function() {
        if (replace) {
          $feed.html('<div class="explore-tab-loading"><i class="fas fa-spinner fa-pulse"></i> Loading posts...</div>');
        } else {
          $feed.append('<div class="explore-tab-loading"><i class="fas fa-spinner fa-pulse"></i> Loading more...</div>');
        }
      },
      success: function(response) {
        $feed.find('.explore-tab-loading').remove();
        const html = $.trim(response);

        if (html !== '') {
          if (replace) $feed.html(html); else $feed.append(html);
          tabState[tab].offset += 20;
          tabState[tab].hasMore = html.indexOf('explore-empty-state') === -1;
        } else {
          tabState[tab].hasMore = false;
          if (tabState[tab].offset === 0) {
            $feed.html('<div class="explore-empty-state"><i class="far fa-comment-dots"></i><p>No posts found</p></div>');
          } else if (!$feed.find('.explore-no-more').length) {
            $feed.append('<div class="explore-no-more">No more posts</div>');
          }
        }
      },
      error: function() {
        $feed.find('.explore-tab-loading').remove();
        if (replace) {
          $feed.html('<div class="explore-empty-state"><i class="fas fa-exclamation-triangle"></i><p>Error loading posts</p></div>');
        }
      },
      complete: function() {
        tabState[tab].loading = false;
      }
    });
  }

  $('.explore-tab-btn').on('click', function() {
    showTab($(this).data('tab'));
  });

  $('#exploreFeedSearch').on('input', function() {
    clearTimeout(exploreSearchTimer);
    updateClearButton();

    exploreSearchTimer = setTimeout(function() {
      if (activeExploreTab === 'foryou') {
        if (getExploreSearch() === '') resetForYouNormal(); else resetAndLoadForYouSearch();
      } else {
        resetAndLoadDynamicTab(activeExploreTab);
      }
    }, 300);
  });

  $('.clear-explore-search').on('click', function() {
    $('#exploreFeedSearch').val('');
    updateClearButton();
    if (activeExploreTab === 'foryou') {
      resetForYouNormal();
    } else {
      resetAndLoadDynamicTab(activeExploreTab);
    }
  });

  $(window).on('scroll', function() {
    if ($(window).scrollTop() + $(window).height() < $(document).height() - 200) return;

    if (activeExploreTab === 'foryou') {
      if (getExploreSearch() !== '') {
        loadForYouSearch(false);
        return;
      }

      if (tabState.foryou.loading || !tabState.foryou.hasMore) return;

      tabState.foryou.loading = true;
      $.ajax({
        url: 'includes/load_more_explore.php',
        type: 'POST',
        data: { offset: tabState.foryou.offset },
        beforeSend: function() {
          $('.explore-center-placeholder').append('<div class="loading" style="text-align:center;padding:10px;">Loading more...</div>');
        },
        success: function(response) {
          $('.loading').remove();
          if ($.trim(response) !== '') {
            $('.explore-center-placeholder').append(response);
            tabState.foryou.offset += 20;
            tabState.foryou.loading = false;
          } else {
            tabState.foryou.hasMore = false;
            $('.explore-center-placeholder').append('<p style="text-align:center;padding:10px;">No more posts</p>');
          }
        },
        error: function() {
          $('.loading').remove();
          tabState.foryou.loading = false;
        }
      });
    } else {
      loadDynamicTab(activeExploreTab, false);
    }
  });
});

// ========== SEARCH FUNCTIONALITY ADDED ==========
$(document).ready(function() {
    let searchTimeout;
    let currentQuery = '';
    
    $('#searchInput').on('input', function() {
        clearTimeout(searchTimeout);
        const query = $(this).val().trim();
        currentQuery = query;
        
        const $searchResult = $('.search-result');
        
        if (query.length < 2) {
            $searchResult.fadeOut(200);
            return;
        }
        
        searchTimeout = setTimeout(function() {
            $searchResult.html('<div class="search-loading"><i class="fas fa-spinner fa-pulse"></i> Searching...</div>').fadeIn(200);
            
            $.ajax({
                url: 'includes/search_ajax.php',
                type: 'POST',
                data: { search: query, offset: 0 },
                success: function(response) {
                    if (response.trim() === '') {
                        $searchResult.html('<div class="search-no-results"><i class="fas fa-user-slash"></i> No users found</div>');
                    } else {
                        $searchResult.html(response);
                    }
                },
                error: function() {
                    $searchResult.html('<div class="search-no-results"><i class="fas fa-exclamation-triangle"></i> Error searching</div>');
                }
            });
        }, 300);
    });
    
    // Load more results
    $(document).on('click', '.load-more-search', function() {
        const button = $(this);
        const loadMoreDiv = button.closest('.search-load-more');
        const offset = loadMoreDiv.data('offset');
        
        button.html('<i class="fas fa-spinner fa-pulse"></i> Loading...');
        
        $.ajax({
            url: 'includes/search_ajax.php',
            type: 'POST',
            data: { search: currentQuery, offset: offset },
            success: function(response) {
                loadMoreDiv.remove();
                $('.search-result').append(response);
            },
            error: function() {
                button.html('<i class="fas fa-exclamation-triangle"></i> Error loading');
            }
        });
    });
    
    // Close search results when clicking outside
    $(document).on('click', function(e) {
        if (!$(e.target).closest('.input-group').length) {
            $('.search-result').fadeOut(200);
        }
    });
});
</script>

<style>
.explore-center-placeholder {
  padding: 0;
  color: #14171a;
  font-size: 15px;
  text-align: left; /* fix alignment */
  border-top: 1px solid #e6ecf0;
  display: block;
}

.wrapper-right {
  position: fixed;
  top: 0;
  right: 0;
  width: 350px;
  background: #fff;
  height: 100vh;
  overflow-y: auto;
  border-left: 1px solid #e6ecf0;
  padding-top: 10px;
}
.loading {
  color: #657786;
  font-size: 14px;
}

/* Extra protection: no horizontal overflow from center Explore controls */
body, html {
  max-width: 100%;
  overflow-x: hidden;
}
.explore-top-search-wrap,
.explore-tabs,
.explore-feed-search-box,
#exploreFeedSearch {
  max-width: 100%;
}
</style>

</body>
</html>