<?php
include 'core/init.php';

$user_id = $_SESSION['user_id'] ?? null;

if (!$user_id || User::checkLogIn() === false) {
  header('location: index.php');
  exit;
}
$user = User::getData($user_id);

if (!isset($_GET['tag'])) {
  header('location: home.php');
  exit;
}

$tag = htmlspecialchars($_GET['tag']);

// fallback if BASE_URL is not defined
if (!defined('BASE_URL')) {
  define('BASE_URL', 'http://localhost/twitterclone/');
}


// Fetch trending hashtags ordered by count descending
$sql_trends = "SELECT hashtag, count 
               FROM trends 
               WHERE created_on >= NOW() - INTERVAL 1 DAY 
               ORDER BY count DESC 
               LIMIT 10";

$stmt_trends = Connect::connect()->prepare($sql_trends);
$stmt_trends->execute();
$trends = $stmt_trends->fetchAll(PDO::FETCH_OBJ);

function linkifyTweet($text) {
  $text = preg_replace('/#(\w+)/u', '<a href="hashtag.php?tag=$1" style="color:#1DA1F2;">#$1</a>', $text);
  $text = preg_replace('/@(\w+)/u', '<a href="profile.php?username=$1" style="color:#1DA1F2;">@$1</a>', $text);
  $text = preg_replace('/(https?:\/\/[^\s]+)/', '<a href="$1" target="_blank" rel="noopener">$1</a>', $text);
  return $text;
}

$who_users = Follow::whoToFollow($user_id);
$notify_count = User::CountNotification($user_id);
?>

<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>#<?php echo $tag; ?> | TwitterClone</title>
  <link rel="stylesheet" href="assets/css/bootstrap.min.css">
  <link rel="stylesheet" href="assets/css/all.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

  <link rel="stylesheet" href="assets/css/home_style.css?v=<?php echo time(); ?>">
 <style>
  /* Trending Hashtags List container shifted right */
.trending-list {
  list-style: none;
  margin: 0;
  padding: 0; /* remove left padding */
}

.trending-list li {
  font-size: 18px;
  padding: 14px 20px; /* add left padding here instead */
  border-bottom: 1px solid #e6ecf0;
  transition: background-color 0.2s ease;
  cursor: pointer;
}

.trending-list li:hover {
  background-color: #e9ecef; /* subtle gray hover */
}

.trending-list li:first-child {
  border-top: 1px solid #e6ecf0;       /* top border for first item */
}

.trending-list li:last-child {
  border-bottom: 1px solid #e6ecf0;    /* keep bottom border for balance */
}

.trending-list a {
  color: #000000;
  font-size: 17px;
  text-decoration: none;
  font-weight: 600;                    /* lighter like Twitter */
  position: relative;
}

.trending-list a:hover {
  color: #1DA1F2;
  text-decoration: underline;
}

.trend-count {
  color: #657786;
  font-size: 14px;
  margin-left: 5px;
}
.trend-header {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 3px;
}

.trend-type {
  font-weight: 600;
  border-radius: 12px;
  padding: 2px 8px;
  color: #fff;
  font-size: 13px;
  background-color: #1DA1F2; /* default Twitter blue */
}

.trend-type.sports { background-color: #1DA1F2; }
.trend-type.politics { background-color: #D93025; }
.trend-type.entertainment { background-color: #FF9800; }
.trend-type.technology { background-color: #4CAF50; }
.trend-type.environment { background-color: #2E7D32; }
.trend-type.education { background-color: #9C27B0; }
.trend-type.health { background-color: #E91E63; }
.trend-type.general { background-color: #657786; }


.trend-label {
  background-color: #e8f5fe;
  color: #1d9bf0;
  font-size: 12px;
  font-weight: 600;
  padding: 2px 6px;
  border-radius: 4px;
  text-transform: uppercase;
}

  .hashtag-tweets {
  margin-left: 220px;
}
.box-share.mt-4 {
    max-height: 300px; /* or whatever height you want */
    overflow-y: auto;  /* enable vertical scroll */
    padding-right: 10px; /* prevent scrollbar overlap */
}

/* optional: nicer scrollbar */
.box-share.mt-4::-webkit-scrollbar {
    width: 6px;
}

.box-share.mt-4::-webkit-scrollbar-thumb {
    background-color: #ccc;
    border-radius: 3px;
}

.box-share.mt-4::-webkit-scrollbar-track {
    background: #f1f1f1;
}
.grid-posts {
  display: grid;
  grid-template-columns: 2fr 1fr;  /* center and right bar */
  gap: 20px;
}



</style>


</head>
<body>
<div id="mine">

  <!-- LEFT SIDEBAR -->
  <div class="wrapper-left">
    <div class="sidebar-left">
      <div class="grid-sidebar" style="margin-top: 12px">
        <div class="icon-sidebar-align">
          <img src="<?php echo BASE_URL . "assets/images/twitter-logo.png"; ?>" alt="" height="30px" width="30px" />
        </div>
      </div>

      <a href="home.php">
        <div class="grid-sidebar bg-active" style="margin-top: 12px">
          <div class="icon-sidebar-align">
            <img src="<?php echo BASE_URL . "includes/icons/tweethome.png"; ?>" alt="" height="26.25px" width="26.25px" />
          </div>
          <div class="wrapper-left-elements">
            <span style="margin-top: 4px;"><strong>Home</strong></span>
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
      

      <a href="notification.php">
        <div class="grid-sidebar">
          <div class="icon-sidebar-align position-relative">
            <?php if ($notify_count > 0) { ?>
              <i class="notify-count"><?php echo $notify_count; ?></i> 
            <?php } ?>
            <img src="<?php echo BASE_URL . "includes/icons/tweetnotif.png"; ?>" alt="" height="26.25px" width="26.25px" />
          </div>
          <div class="wrapper-left-elements">
            <span style="margin-top: 4px"><strong>Notifications</strong></span>
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
            <img src="<?php echo BASE_URL . "includes/icons/tweetprof.png"; ?>" alt="" height="26.25px" width="26.25px" />
          </div>
          <div class="wrapper-left-elements">
            <span style="margin-top: 4px"><strong>Profile</strong></span>
          </div>
        </div>
      </a>

      <a href="<?php echo BASE_URL . "account.php"; ?>">
        <div class="grid-sidebar">
          <div class="icon-sidebar-align">
            <img src="<?php echo BASE_URL . "includes/icons/tweetsetting.png"; ?>" alt="" height="26.25px" width="26.25px" />
          </div>
          <div class="wrapper-left-elements">
            <span style="margin-top: 4px"><strong>Settings</strong></span>
          </div>
        </div>
      </a>

      <a href="includes/logout.php">
        <div class="grid-sidebar">
          <div class="icon-sidebar-align">
            <i style="font-size: 26px;" class="fas fa-sign-out-alt"></i>
          </div>
          <div class="wrapper-left-elements">
            <span style="margin-top: 4px"><strong>Logout</strong></span>
          </div>
        </div>
      </a>

      <button class="button-twittear"><strong>Tweet</strong></button>

      <?php include 'includes/profile_dropdown.php'; ?>
    </div>
  </div>
  <!-- END LEFT SIDEBAR -->

  <!-- CENTER SECTION -->
<div class="grid-posts">
  <div class="border-right">
    <div class="grid-toolbar-center">
      <div class="center-input-search">
        <div class="container d-flex align-items-center py-2">
          <a href="javascript: history.go(-1);" class="mr-3 text-dark">
            <i class="fas fa-arrow-left" style="font-size:20px;"></i>
          </a>
          <h2 style="margin: 0; font-size: 20px; font-weight: 700;">
            #<?php echo htmlspecialchars($tag); ?>
          </h2>
        </div>
      </div>
    </div>


    <?php
    $tag = $_GET['tag'] ?? '';

    if (!empty($tag)) {
        echo '<div class="tweets-container">';
        $user_id = $_SESSION['user_id'] ?? null; 
        Tweet::displayTweetsByHashtag($tag, $user_id);
        echo '</div>';
    } else {
        echo "<p style='padding:20px;'>No hashtag selected.</p>";
    }
    ?>
  </div>


    <!-- RIGHT SIDEBAR -->
    <div class="wrapper-right">
      <div style="width: 90%;" class="container">
        <div class="input-group py-2 m-auto pr-5 position-relative">
          <i id="icon-search" class="fas fa-search tryy"></i>
          <input type="text" class="form-control search-input" placeholder="Search Twitter">
          <div class="search-result"></div>
        </div>
      </div>

      <!-- Who to Follow -->
      <div class="box-share">
        <p class="txt-share"><strong>Who to follow</strong></p>
        <?php foreach($who_users as $u) {
          $user_follow = Follow::isUserFollow($user_id , $u->id);
        ?>
          <div class="grid-share">
            <a style="color:black" href="<?php echo $u->username; ?>">
              <img src="assets/images/users/<?php echo $u->img; ?>" alt="" class="img-share" />
            </a>
            <div>
              <p><a style="color:black" href="<?php echo $u->username; ?>"><strong><?php echo $u->name; ?></strong></a></p>
              <p class="username">@<?php echo $u->username; ?>
                <?php if (Follow::FollowsYou($u->id , $user_id)) { ?>
                  <span class="ml-1 follows-you">Follows You</span>
                <?php } ?>
              </p>
            </div>
            <div>
              <button class="follow-btn follow-btn-m <?= $user_follow ? 'following' : 'follow' ?>"
                      data-follow="<?php echo $u->id; ?>"
                      data-user="<?php echo $user_id; ?>"
                      style="font-weight:700;">
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
?>>
    
    <!-- END RIGHT SIDEBAR -->
  </div>
</div>

<!-- JS -->
<script src="assets/js/jquery-3.5.1.min.js"></script>
<script src="assets/js/bootstrap.min.js"></script>
<script src="assets/js/popper.min.js"></script>
<script src="assets/js/photo.js"></script>
<script src="assets/js/like.js"></script>
<script src="assets/js/comment.js"></script>
<script src="assets/js/retweet.js"></script>
<script src="assets/js/follow.js"></script>
<script src="assets/js/search.js"></script>
<script src="assets/js/bookmark.js?v=<?php echo time(); ?>"></script>
<script src="https://kit.fontawesome.com/38e12cc51b.js" crossorigin="anonymous"></script>

</body>
</html>
