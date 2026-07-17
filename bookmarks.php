<?php
include 'core/init.php';

$user_id = $_SESSION['user_id'];
$user = User::getData($user_id);

if (User::checkLogIn() === false) 
    header('location: index.php');

$who_users = Follow::whoToFollow($user_id);
$notify_count = User::CountNotification($user_id);

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
  <title>Bookmarks | TwitterClone</title>
  <link rel="shortcut icon" type="image/png" href="assets/images/twitter.svg"> 
  <link rel="stylesheet" href="assets/css/bootstrap.min.css">
  <link rel="stylesheet" href="assets/css/all.min.css">
  <link rel="stylesheet" href="assets/css/home_style.css?v=<?php echo time(); ?>">
  <link rel="stylesheet" href="assets/css/profile_style.css?v=<?php echo time(); ?>">
  <style>
    .bookmark-empty {
      text-align: center;
      margin-top: 40px;
      color: #657786;
      font-size: 18px;
    }
    .bookmark-empty i {
      font-size: 50px;
      color: #1da1f2;
      margin-bottom: 15px;
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
  </style>
</head>
<body>
<div id="mine">

  <!-- ✅ LEFT SIDEBAR (exactly like home.php) -->
  <div class="wrapper-left">
    <div class="sidebar-left">
      <div class="grid-sidebar" style="margin-top: 12px">
        <div class="icon-sidebar-align">
          <img src="<?php echo BASE_URL . "/assets/images/twitter-logo.png"; ?>" alt="" height="30px" width="30px" />
        </div>
      </div>

      <a href="home.php">
        <div class="grid-sidebar" style="margin-top: 12px">
          <div class="icon-sidebar-align">
            <img src="<?php echo BASE_URL . "/includes/icons/tweethome.png"; ?>" alt="" height="26.25px" width="26.25px" />
          </div>
          <div class="wrapper-left-elements">
            <a href="home.php" style="margin-top: 4px;"><strong>Home</strong></a>
          </div>
        </div>
      </a>

      <a href="explore.php">
        <div class="grid-sidebar">
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
            <img src="<?php echo BASE_URL . "/includes/icons/tweetnotif.png"; ?>" alt="" height="26.25px" width="26.25px" />
          </div>
          <div class="wrapper-left-elements">
            <a href="notification.php" style="margin-top: 4px"><strong>Notifications</strong></a>
          </div>
        </div>
      </a>

      <!-- ✅ Active Bookmarks link -->
      <a href="bookmarks.php">
        <div class="grid-sidebar bg-active">
          <div class="icon-sidebar-align">
            <img src="<?php echo BASE_URL . "/includes/icons/tweetbookmark.png"; ?>" alt="Bookmarks" height="26.25px" width="26.25px" />
          </div>
          <div class="wrapper-left-elements">
            <a class="wrapper-left-active" href="bookmarks.php" style="margin-top: 4px;"><strong>Bookmarks</strong></a>
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

      <button class="button-twittear"><strong>Tweet</strong></button>

     <?php include 'includes/profile_dropdown.php'; ?>
    </div>
  </div>

  <!-- ✅ CENTER SECTION (empty for now) -->
  <div class="grid-posts">
    <div class="border-right">
      <div class="grid-toolbar-center">
        <div class="center-input-search">
          <div class="container d-flex align-items-center py-2">
            <a href="javascript: history.go(-1);" class="mr-3 text-dark">
              <i class="fas fa-arrow-left" style="font-size:20px;"></i>
            </a>
            <h2 style="margin: 0; font-size: 20px; font-weight: 700;">Bookmarks</h2>
          </div>
        </div>
      </div>

      <?php
      // Fetch all bookmarked tweets for the logged-in user
      $bookmarkedTweets = Tweet::getBookmarks($user_id);

      if ($bookmarkedTweets && count($bookmarkedTweets) > 0) {
          // ✅ Use the same include logic as in home.php
          foreach ($bookmarkedTweets as $tweet) {
              include 'includes/tweets.php'; // show each bookmarked tweet
          }
      } else {
          // ✅ If no bookmarks found, show placeholder
          echo '
          <div class="bookmark-empty">
              <i class="fa-regular fa-bookmark"></i>
              <p>Your bookmarked tweets will appear here.</p>
          </div>';
      }
      ?>
    </div>
  </div>

  <!-- ✅ RIGHT SIDEBAR -->
  <div class="wrapper-right">
    <div style="width: 90%;" class="container">
      <div class="input-group py-2 m-auto pr-5 position-relative">
        <i id="icon-search" class="fas fa-search tryy"></i>
        <input type="text" class="form-control search-input" id="searchInput" placeholder="Search Twitter">
        <div class="search-result"></div>
      </div>
    </div>

    <!-- Who to follow -->
    <div class="box-share">
      <p class="txt-share"><strong>Who to follow</strong></p>
      <?php 
      foreach($who_users as $user2) { 
        $user_follow = Follow::isUserFollow($user_id, $user2->id);
      ?>
      <div class="grid-share">
        <a style="position: relative; z-index:5; color:black" href="<?php echo $user2->username; ?>">
          <img src="assets/images/users/<?php echo $user2->img; ?>" alt="" class="img-share" />
        </a>
        <div>
          <p>
            <a style="position: relative; z-index:5; color:black" href="<?php echo $user2->username; ?>">  
              <strong><?php echo $user2->name; ?></strong>
            </a>
          </p>
          <p class="username">@<?php echo $user2->username; ?>
            <?php if (Follow::FollowsYou($user2->id , $user_id)) { ?>
              <span class="ml-1 follows-you">Follows You</span>
            <?php } ?>
          </p>
        </div>
        <div>
          <button class="follow-btn follow-btn-m <?= $user_follow ? 'following' : 'follow' ?>"
                  data-follow="<?php echo $user2->id; ?>"
                  data-user="<?php echo $user_id; ?>"
                  style="font-weight: 700;">
            <?php echo $user_follow ? 'Following' : 'Follow'; ?>
          </button>
        </div>
      </div>
      <?php } ?>
    </div>

    <!-- Trending Hashtags -->
    <?php
    $trends = Tweet::trendingHashtags();
    echo Tweet::getTrendingHashtagsBox($trends);
    ?>
  </div>
</div>

<!-- JS -->
<script src="assets/js/search.js"></script>
<script src="assets/js/follow.js?v=<?php echo time(); ?>"></script>
<script src="assets/js/photo.js?v=<?php echo time(); ?>"></script>
<script src="assets/js/hashtag.js"></script>
<script src="assets/js/like.js"></script>
<script src="assets/js/comment.js?v=<?php echo time(); ?>"></script>
<script src="assets/js/retweet.js?v=<?php echo time(); ?>"></script>
<script src="https://kit.fontawesome.com/38e12cc51b.js" crossorigin="anonymous"></script>
<script src="assets/js/jquery-3.5.1.min.js"></script>
<script src="assets/js/popper.min.js"></script>
<script src="assets/js/bootstrap.min.js"></script>
<script src="assets/js/bookmark.js?v=<?php echo time(); ?>"></script>

<script>
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

</body>
</html>