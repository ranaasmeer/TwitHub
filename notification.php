<?php  
include 'core/init.php';

$user_id = $_SESSION['user_id'];
$user = User::getData($user_id);
$who_users = Follow::whoToFollow($user_id);

// Handle notification deletion
if (isset($_GET['delete_notify'])) {
    $notify_id = (int)$_GET['delete_notify'];
    $pdo = Connect::connect();
    $stmt = $pdo->prepare("DELETE FROM notifications WHERE id = ? AND notify_for = ?");
    $stmt->execute([$notify_id, $user_id]);
    header("Location: notification.php");
    exit;
}

// Handle delete all notifications
if (isset($_GET['delete_all'])) {
    $pdo = Connect::connect();
    $stmt = $pdo->prepare("DELETE FROM notifications WHERE notify_for = ?");
    $stmt->execute([$user_id]);
    header("Location: notification.php");
    exit;
}

// update notification count
User::updateNotifications($user_id);

$notify_count = User::CountNotification($user_id);
$notofication = User::notification($user_id);

if (User::checkLogIn() === false) 
    header('location: index.php');  

// Count total unread messages for this user
$conn = Connect::connect();
$stmt = $conn->prepare("SELECT COUNT(*) AS unread_total FROM messages WHERE receiver_id = ? AND is_read = 0");
$stmt->execute([$user_id]);
$unreadMessages = $stmt->fetch(PDO::FETCH_OBJ)->unread_total ?? 0;

?>
 
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications | TwitterClone</title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link rel="stylesheet" href="assets/css/profile_style.css?v=<?php echo time(); ?>">
    <link rel="shortcut icon" type="image/png" href="assets/images/twitter.svg"> 
    <link rel="stylesheet" href="assets/css/home_style.css?v=<?php echo time(); ?>">
    <style>
        .notification-item {
            position: relative;
            border-bottom: 4px solid #F5F8FA;
            padding: 15px 10px;
            transition: background 0.2s;
        }
        .notification-item:hover {
            background: #f8f9fa;
        }
        .delete-notify {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            z-index: 1001;
            background: transparent;
            border: none;
            color: #657786;
            font-size: 16px;
            cursor: pointer;
            padding: 8px 12px;
            border-radius: 50%;
            transition: all 0.2s;
        }
        .delete-notify:hover {
            background: rgba(29, 161, 242, 0.1);
            color: #e0245e;
        }
        .delete-all-btn {
            background: #e0245e;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 30px;
            font-size: 13px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.2s;
            margin-left: 15px;
        }
        .delete-all-btn:hover {
            background: #c01b4f;
            transform: translateY(-1px);
        }
        .no-notifications {
            text-align: center;
            padding: 50px 20px;
            color: #657786;
            font-size: 18px;
        }
        .notification-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
        }
        
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
    </style>
</head>
<body>

<script src="assets/js/jquery-3.5.1.min.js"></script>

<div id="mine">
    <div class="wrapper-left">
        <!-- Your existing sidebar code remains the same -->
        <div class="sidebar-left">
          <div class="grid-sidebar" style="margin-top: 12px">
            <div class="icon-sidebar-align">
              <img src="<?php echo BASE_URL . "/assets/images/twitter-logo.png"; ?>" alt="" height="30px" width="30px" />
            </div>
          </div>

          <a href="home.php">
          <div class="grid-sidebar bg-active" style="margin-top: 12px">
            <div class="icon-sidebar-align">
              <img src="<?php echo BASE_URL . "/includes/icons/tweethome.png"; ?>" alt="" height="26.25px" width="26.25px" />
            </div>
            <div class="wrapper-left-elements">
              <a href="home.php" style="margin-top: 4px;"><strong>Home</strong></a>
            </div>
          </div>
          </a>
          <a href="explore.php">
            <div class="grid-sidebar" style="margin-top: 12px">
                <div class="icon-sidebar-align">
                    <img src="<?php echo BASE_URL . "/includes/icons/tweetsearch.png"; ?>" alt="" height="26.25px" width="26.25px" />
                </div>
                <div class="wrapper-left-elements">
                    <a href="explore.php" style="margin-top: 4px;"><strong>Explore</strong></a>
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
                    <a href="messages.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'messages.php' ? 'wrapper-left-active' : ''; ?>" style="margin-top: 4px;">
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
                    <img src="<?php echo BASE_URL . "/includes/icons/tweetnotif.png"; ?>" alt="" height="26.25px" width="26.25px" />
                </div>
                <div class="wrapper-left-elements">
                    <a class="wrapper-left-active" href="notification.php" style="margin-top: 4px"><strong>Notifications</strong></a>
                </div>
            </div>
          </a>
          
          <!-- BOOKMARKS -->
          <a href="bookmarks.php">
            <div class="grid-sidebar">
                <div class="icon-sidebar-align">
                    <img src="<?php echo BASE_URL . "/includes/icons/tweetbookmark.png"; ?>" alt="Bookmarks" height="26.25px" width="26.25px" />
                </div>
                <div class="wrapper-left-elements">
                    <a href="bookmarks.php" style="margin-top: 4px;"><strong>Bookmarks</strong></a>
                </div>
            </div>
          </a>
        
          <a href="<?php echo BASE_URL . $user->username; ?>">
            <div class="grid-sidebar">
                <div class="icon-sidebar-align">
                    <img src="<?php echo BASE_URL . "/includes/icons/tweetprof.png"; ?>" alt="" height="26.25px" width="26.25px" />
                </div>
                <div class="wrapper-left-elements">
                    <a href="<?php echo BASE_URL . $user->username; ?>" style="margin-top: 4px"><strong>Profile</strong></a>
                </div>
            </div>
          </a>
          
          <a href="<?php echo BASE_URL . "account.php"; ?>">
            <div class="grid-sidebar">
                <div class="icon-sidebar-align">
                    <img src="<?php echo BASE_URL . "/includes/icons/tweetsetting.png"; ?>" alt="" height="26.25px" width="26.25px" />
                </div>
                <div class="wrapper-left-elements">
                    <a href="<?php echo BASE_URL . "account.php"; ?>" style="margin-top: 4px"><strong>Settings</strong></a>
                </div>
            </div>
          </a>
          
          <a href="includes/logout.php">
            <div class="grid-sidebar">
                <div class="icon-sidebar-align">
                    <i style="font-size: 26px;" class="fas fa-sign-out-alt"></i>
                </div>
                <div class="wrapper-left-elements">
                    <a href="includes/logout.php" style="margin-top: 4px"><strong>Logout</strong></a>
                </div>
            </div>
          </a>
          
          <button class="button-twittear">
            <strong>Tweet</strong>
          </button>

          <?php include 'includes/profile_dropdown.php'; ?>
        </div>
    </div>
          
    <div class="grid-posts">
        <div class="border-right">
            <div class="grid-toolbar-center">
                <div class="center-input-search"></div>
            </div>

            <div class="box-fixed" id="box-fixed"></div>

            <div class="box-home feed">
                <div class="container">
                    <div style="border-bottom: 1px solid #F5F8FA; padding: 10px 0;" class="row position-fixed box-name">
                        <div class="col-xs-2">
                            <a href="javascript: history.go(-1);"> 
                                <i style="font-size:20px;" class="fas fa-arrow-left arrow-style"></i> 
                            </a>
                        </div>
                        <div class="col-xs-10 notification-header">
                            <p style="margin-top: 12px;" class="home-name">Notifications</p>
                            <?php if (!empty($notofication)): ?>
                                <button class="delete-all-btn" onclick="deleteAllNotifications()">
                                    <i class="fas fa-trash-alt"></i> Delete All
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div> 
                
                <div class="container mt-5">
                   <?php foreach($notofication as $notify) { 
    $user = User::getData($notify->notify_from);
    $timeAgo = Tweet::getTimeAgo($notify->time);
    
    // Determine the main link for the notification
    $mainLink = ($notify->type == 'follow') ? BASE_URL . $user->username : BASE_URL . "status/" . $notify->target;

    if ($notify->type == 'like') { 
        $icon = "<i style='color: red;font-size:30px;' class='fa-heart fas ml-2'></i>";
        $msg = "Liked Your Tweet";
    } else if ($notify->type == 'retweet') { 
        $icon = "<i style='font-size:30px;color: rgb(22, 207, 22);' class='fas fa-retweet ml-2'></i>";
        $msg = "Retweeted Your Tweet";
    } else if ($notify->type == 'qoute') { 
        $icon = "<i style='font-size:30px;color: rgb(22, 207, 22);' class='fas fa-retweet ml-2'></i>";
        $msg = "Quoted Your Tweet";
    } else if ($notify->type == 'comment') { 
        $icon = "<i style='font-size:30px;' class='far fa-comment ml-2'></i>";
        $msg = "Commented on your Tweet";
    } else if ($notify->type == 'reply') { 
        $icon = "<i style='font-size:30px;' class='far fa-comment ml-2'></i>";
        $msg = "Replied to your Comment";
    } else if ($notify->type == 'follow') { 
        $icon = "<i style='font-size:30px;' class='fas fa-user-plus ml-2'></i>";
        $msg = "Followed You";
    } else if ($notify->type == 'mention') { 
        $icon = "<i style='font-size:30px;' class='fas fa-at ml-2'></i>";
        $msg = "Mentioned you in a Tweet";
    }
?>
<!-- The whole div is now clickable via window.location -->
<div class="notification-item" id="notify-<?php echo $notify->id; ?>" 
     onclick="window.location='<?php echo $mainLink; ?>';" 
     style="cursor: pointer;">
    
    <!-- Delete Button: stopPropagation prevents the main div click from firing -->
    <button class="delete-notify" onclick="event.stopPropagation(); deleteNotification(<?php echo $notify->id; ?>)">
        <i class="fas fa-times"></i>
    </button>
    
    <div class="grid-tweet">
        <div class="icon mt-2">
            <?php echo $icon; ?>
        </div>
        <div class="notify-user">
            <p>
                <!-- Profile Image Link: stopPropagation prevents the main div click -->
                <a onclick="event.stopPropagation();" href="<?php echo BASE_URL . $user->username; ?>">
                    <img class="img-user" src="assets/images/users/<?php echo $user->img ?>" alt="">
                </a> 
            </p>
            <p> 
                <!-- Profile Name Link: stopPropagation prevents the main div click -->
                <a onclick="event.stopPropagation();" style="font-weight: 700; font-size:16px; color: #14171a;" href="<?php echo BASE_URL . $user->username; ?>">
                    <?php echo $user->name; ?> 
                </a> 
                <span style="color: #657786;"><?php echo $msg; ?></span>
                <span style="font-weight: 500; color: #657786;" class="ml-3">
                    <?php echo $timeAgo; ?>
                </span> 
            </p>
        </div>
    </div>
</div> 
<?php } ?>
                </div>
            </div>
        </div> 

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
                <?php foreach($who_users as $user) { 
                    $user_follow = Follow::isUserFollow($user_id , $user->id);
                ?>
                    <div class="grid-share">
                        <a style="position: relative; z-index:5; color:black" href="<?php echo $user->username; ?>">
                            <img src="assets/images/users/<?php echo $user->img; ?>" alt="" class="img-share" />
                        </a>
                        <div>
                            <p>
                                <a style="position: relative; z-index:5; color:black" href="<?php echo $user->username; ?>">  
                                    <strong><?php echo $user->name; ?></strong>
                                </a>
                            </p>
                            <p class="username">@<?php echo $user->username; ?>
                            <?php if (Follow::FollowsYou($user->id , $user_id)) { ?>
                                <span class="ml-1 follows-you">Follows You</span>
                            <?php } ?>
                            </p>
                        </div>
                        <div>
                            <button class="follow-btn follow-btn-m <?= $user_follow ? 'following' : 'follow' ?>"
                                    data-follow="<?php echo $user->id; ?>"
                                    data-user="<?php echo $user_id; ?>"
                                    data-profile="<?php echo $profileData->id ?? ''; ?>"
                                    style="font-weight: 700;">
                                <?php if($user_follow) { ?> Following <?php } else { ?> Follow <?php } ?> 
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
</div>

<script src="assets/js/search.js"></script>
<script src="assets/js/photo.js"></script>
<script src="assets/js/follow.js?v=<?php echo time(); ?>"></script>
<script src="assets/js/users.js?v=<?php echo time(); ?>"></script>
<script type="text/javascript" src="assets/js/hashtag.js"></script>
<script type="text/javascript" src="assets/js/like.js"></script>
<script type="text/javascript" src="assets/js/comment.js?v=<?php echo time(); ?>"></script>
<script type="text/javascript" src="assets/js/retweet.js?v=<?php echo time(); ?>"></script>
<script src="https://kit.fontawesome.com/38e12cc51b.js" crossorigin="anonymous"></script>
<script src="assets/js/jquery-3.5.1.min.js"></script>
<script src="assets/js/popper.min.js"></script>
<script src="assets/js/bootstrap.min.js"></script>

<script>
// Delete single notification
function deleteNotification(notifyId) {
    if (confirm('Remove this notification?')) {
        $.ajax({
            url: 'ajax/delete_notification.php',
            type: 'POST',
            data: { notify_id: notifyId },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $('#notify-' + notifyId).fadeOut(300, function() {
                        $(this).remove();
                        // Check if no notifications left
                        if ($('.notification-item').length === 0) {
                            location.reload();
                        }
                    });
                } else {
                    alert('Error deleting notification');
                }
            },
            error: function() {
                // Fallback - reload page
                window.location.href = 'notification.php?delete_notify=' + notifyId;
            }
        });
    }
}

// Delete all notifications
function deleteAllNotifications() {
    if (confirm('Delete ALL notifications? This cannot be undone!')) {
        window.location.href = 'notification.php?delete_all=1';
    }
}

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
    .container {
        padding-left: 55px;
    }
    
    /* Notification item - same style as tweets with zoom effect */
    .notification-item {
        border-bottom: 2px solid #e6ecf0;
        margin-bottom: 10px;
        transition: all 0.2s ease;
        cursor: pointer;
        border-radius: 8px;
    }
    
    /* Hover effect with shadow and zoom/translate */
    .notification-item:hover {
        background: #f8f9fa;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        transform: translateY(-1px);
    }
    
    /* Last item no border */
    .notification-item:last-child {
        border-bottom: none;
    }
    
    /* Delete button */
    .delete-notify {
        position: absolute;
        right: 15px;
        top: 50%;
        transform: translateY(-50%);
        z-index: 1001;
        background: transparent;
        border: none;
        color: #657786;
        font-size: 16px;
        cursor: pointer;
        padding: 8px 12px;
        border-radius: 50%;
        transition: all 0.2s;
    }
    
    .delete-notify:hover {
        background: rgba(29, 161, 242, 0.1);
        color: #e0245e;
        transform: translateY(-50%) scale(1.1);
    }
    
    /* Delete all button */
    .delete-all-btn {
        background: #e0245e;
        color: white;
        border: none;
        padding: 6px 14px;
        border-radius: 30px;
        font-size: 13px;
        font-weight: bold;
        cursor: pointer;
        transition: all 0.2s;
        margin-left: 15px;
    }
    
    .delete-all-btn:hover {
        background: #c01b4f;
        transform: translateY(-2px);
    }
    
    /* Empty state */
    .no-notifications {
        text-align: center;
        padding: 60px 20px;
        color: #657786;
        font-size: 18px;
    }
    
    /* Header flex */
    .notification-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
    }
    
    /* Image zoom effect on hover */
    .img-user {
        transition: transform 0.2s ease;
    }
    
    .notification-item:hover .img-user {
        transform: scale(1.05);
    }
    
    /* Icon zoom effect */
    .notification-item:hover .icon i {
        transform: scale(1.05);
    }
    
    .icon i {
        transition: transform 0.2s ease;
        display: inline-block;
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
</style>

</body>
</html>