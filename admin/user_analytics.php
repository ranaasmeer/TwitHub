<?php
require_once '../core/init.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: index.php');
    exit;
}

$pdo = Connect::connect();

$user = null;
$user_id = null;
$error = '';
$searchQuery = '';

$likedTweets = [];
$retweetedTweets = [];
$mostCommentedTweets = [];
$dailyData = [];
$followersData = [];

// 🔍 Search user
if (isset($_GET['search'])) {
    $searchQuery = trim($_GET['search']);
    if (!empty($searchQuery)) {
        $stmt = $pdo->prepare("
            SELECT id, username, name, img 
            FROM users 
            WHERE username LIKE ? OR name LIKE ? 
            LIMIT 1
        ");
        $stmt->execute(["%$searchQuery%", "%$searchQuery%"]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            $user_id = $user['id'];
        } else {
            $error = "No user found with that name or username.";
        }
    }
}

// 📊 Fetch analytics if user found
if ($user_id) {

    // ❤️ Most liked tweets
    $likedTweetsStmt = $pdo->prepare("
        SELECT t.post_id, t.status, COUNT(l.id) AS like_count
        FROM tweets t
        LEFT JOIN likes l ON t.post_id = l.post_id
        WHERE t.tweet_by = ?
        GROUP BY t.post_id, t.status
        ORDER BY like_count DESC
        LIMIT 5
    ");
    $likedTweetsStmt->execute([$user_id]);
    $likedTweets = $likedTweetsStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$likedTweets) {
        $likedTweets = [['status'=>'No likes yet','like_count'=>0]];
    }

    // 🔁 Most retweeted tweets
    $retweetedTweetsStmt = $pdo->prepare("
        SELECT t.post_id, t.status, 
               COUNT(r.post_id) AS retweet_count
        FROM tweets t
        LEFT JOIN retweets r ON t.post_id = r.tweet_id
        WHERE t.tweet_by = ?
        GROUP BY t.post_id, t.status
        ORDER BY retweet_count DESC
        LIMIT 5
    ");
    $retweetedTweetsStmt->execute([$user_id]);
    $retweetedTweets = $retweetedTweetsStmt->fetchAll(PDO::FETCH_ASSOC);

    // 💬 Most Commented Tweets
    $mostCommentedStmt = $pdo->prepare("
        SELECT t.post_id, t.status, COUNT(c.id) AS comment_count
        FROM tweets t
        LEFT JOIN comments c ON t.post_id = c.post_id
        WHERE t.tweet_by = ?
        GROUP BY t.post_id, t.status
        ORDER BY comment_count DESC
        LIMIT 5
    ");
    $mostCommentedStmt->execute([$user_id]);
    $mostCommentedTweets = $mostCommentedStmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$mostCommentedTweets) {
        $mostCommentedTweets = [['status'=>'No comments yet','comment_count'=>0]];
    }

    // 📅 Daily tweet activity
    $dailyActivityStmt = $pdo->prepare("
        SELECT DATE(p.post_on) AS day, COUNT(*) AS tweets
        FROM tweets t
        INNER JOIN posts p ON t.post_id = p.id
        WHERE t.tweet_by = ? AND p.post_on IS NOT NULL
        GROUP BY day
        ORDER BY day ASC
    ");
    $dailyActivityStmt->execute([$user_id]);
    $dailyData = $dailyActivityStmt->fetchAll(PDO::FETCH_ASSOC);

    // 👥 Daily followers gained
    $dailyFollowersStmt = $pdo->prepare("
        SELECT DATE(f.time) AS day, COUNT(*) AS gained
        FROM follow f
        WHERE f.following_id = ?
        GROUP BY day
        ORDER BY day ASC
    ");
    $dailyFollowersStmt->execute([$user_id]);
    $followersData = $dailyFollowersStmt->fetchAll(PDO::FETCH_ASSOC);

    // Fill missing dates with 0
    function fillMissingDates($data, $key = 'day', $value = 'tweets') {
        if (!$data) return [];

        $start = new DateTime($data[0][$key]);
        $end = new DateTime(end($data)[$key]);
        $end->modify('+1 day');

        $interval = new DateInterval('P1D');
        $period = new DatePeriod($start, $interval, $end);

        $allDays = [];
        foreach ($period as $date) {
            $allDays[$date->format('Y-m-d')] = 0;
        }

        foreach ($data as $d) {
            $allDays[$d[$key]] = (int)$d[$value];
        }

        $result = [];
        foreach ($allDays as $day => $count) {
            $result[] = [$key => $day, $value => $count];
        }
        return $result;
    }

    $dailyData = fillMissingDates($dailyData, 'day', 'tweets');
    $followersData = fillMissingDates($followersData, 'day', 'gained');
}
?>


<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>User Analytics · TwitterClone</title>
<link rel="icon" href="../assets/images/twitter-logo.png" type="image/png">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
:root {
  --primary: #1DA1F2;
  --secondary: #14171A;
  --light: #f5f8fa;
  --white: #fff;
  --gray: #657786;
  --danger: #e0245e;
  --success: #17bf63;
}
body.dark {
  --primary: #1DA1F2;
  --secondary: #e6ecf0;
  --light: #1a1d21;
  --white: #1c1f23;
  --gray: #8899a6;
}
* { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Poppins', sans-serif; transition: background 0.3s, color 0.3s; }
body { display: flex; min-height: 100vh; background: var(--light); color: var(--secondary); }
.sidebar { width: 250px; background: var(--secondary); color: var(--white); display: flex; flex-direction: column; justify-content: space-between; padding: 20px 0; position: fixed; left: 0; top: 0; bottom: 0; }
.sidebar-header { text-align: center; margin-bottom: 20px; }
.sidebar-logo { width: 60px; margin-bottom: 10px; }
.sidebar-nav a { color: var(--white); text-decoration: none; padding: 14px 25px; display: flex; align-items: center; gap: 10px; font-size: 15px; transition: all 0.3s; }
.sidebar-nav a:hover, .sidebar-nav a.active { background: var(--primary); border-radius: 0 20px 20px 0; }
.sidebar-footer { text-align: center; padding: 15px; }
.logout-btn { color: var(--white); text-decoration: none; background: var(--danger); padding: 10px 20px; border-radius: 25px; transition: 0.3s; }
.logout-btn:hover { background: #ff4d6d; }
.main-content { margin-left: 250px; flex: 1; padding: 30px; }
.topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
.theme-toggle { background: none; border: none; color: var(--primary); font-size: 22px; cursor: pointer; }
.search-box { margin-bottom: 25px; display: flex; gap: 10px; }
.search-box input { flex: 1; padding: 12px 15px; border-radius: 25px; border: 1px solid #ccc; outline: none; font-size: 15px; }
.search-box button { background: var(--primary); color: #fff; border: none; padding: 12px 20px; border-radius: 25px; cursor: pointer; transition: 0.3s; }
.search-box button:hover { background: #0d8de6; }
.user-info { display: flex; align-items: center; gap: 20px; margin-bottom: 30px; }
.user-info img { width: 80px; height: 80px; border-radius: 50%; object-fit: cover; }
.user-info h2 { font-size: 24px; }
.section { background: var(--white); border-radius: 15px; padding: 25px; margin-bottom: 30px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); }
.section h3 { margin-bottom: 15px; color: var(--primary); }
ul.tweet-list { list-style: none; }
ul.tweet-list li { padding: 10px 0; border-bottom: 1px solid #eee; }
.tweet-text { color: var(--secondary); }
.tweet-stats { color: var(--gray); font-size: 14px; margin-top: 5px; }
canvas { width: 100% !important; height: 350px !important; }
</style>
</head>
<body>
<aside class="sidebar">
  <div>
    <div class="sidebar-header">
      <img src="../assets/images/twitter-logo.png" class="sidebar-logo">
      <h2>Admin Panel</h2>
    </div>
    <nav class="sidebar-nav">
      <a href="dashboard.php"><i class="fa-solid fa-house"></i> Dashboard</a>
      <a href="users.php"><i class="fa-solid fa-users"></i> Manage Users</a>
      <a href="tweets.php"><i class="fa-brands fa-twitter"></i> Manage Tweets</a>
      <a href="comments.php"><i class="fa-solid fa-comments"></i> Manage Comments</a>
      <a href="trends.php"><i class="fa-solid fa-chart-line"></i> Manage Trends</a>
      <a href="replies.php"><i class="fa-solid fa-reply"></i> Manage Replies</a>
      <a href="quotes.php"><i class="fa-solid fa-retweet"></i> Manage Quotes/Retweets</a>
      <a href="bookmarks.php"><i class="fa-solid fa-bookmark"></i> Manage Bookmarks</a>
      <a href="user_analytics.php" class="active"><i class="fa-solid fa-chart-simple"></i> User Analytics</a>
      <a href="manage_music.php"><i class="fa-solid fa-music"></i> Music Library</a>
      <a href="manage_reels.php"><i class="fa-solid fa-film"></i> Manage Reels</a>
    </nav>
  </div>
  <div class="sidebar-footer">
    <a href="logout.php" class="logout-btn"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
  </div>
</aside>

<main class="main-content">
  <div class="topbar">
    <h1>User Analytics</h1>
    <button id="themeToggle" class="theme-toggle"><i class="fa-solid fa-moon"></i></button>
  </div>

  <form method="get" class="search-box">
    <input type="text" name="search" placeholder="Search user by username or name..." value="<?php echo htmlspecialchars($searchQuery); ?>">
    <button type="submit"><i class="fa-solid fa-search"></i> Search</button>
  </form>

  <?php if ($error): ?>
    <p style="color:red;"><?php echo $error; ?></p>
  <?php endif; ?>

  <?php if ($user): ?>
    <div class="user-info">
      <img src="../assets/images/users/<?php echo htmlspecialchars($user['img']); ?>" alt="Profile">
      <div>
        <h2>@<?php echo htmlspecialchars($user['username']); ?></h2>
        <p style="color:var(--gray);"><?php echo htmlspecialchars($user['name']); ?></p>
      </div>
    </div>

    <div class="section">
      <h3>🔥 Most Liked Tweets</h3>
      <ul class="tweet-list">
        <?php foreach ($likedTweets as $t): ?>
          <li>
            <div class="tweet-text"><?php echo htmlspecialchars($t['status']); ?></div>
            <div class="tweet-stats"><i class="fa-solid fa-heart"></i> <?php echo $t['like_count']; ?> likes</div>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>

    <div class="section">
      <h3>🔁 Most Retweeted Tweets</h3>
      <ul class="tweet-list">
        <?php foreach ($retweetedTweets as $t): ?>
          <li>
            <div class="tweet-text"><?php echo htmlspecialchars($t['status']); ?></div>
            <div class="tweet-stats"><i class="fa-solid fa-retweet"></i> <?php echo $t['retweet_count']; ?> retweets</div>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>

    <div class="section">
      <h3>💬 Most Commented Tweets</h3>
      <ul class="tweet-list">
        <?php foreach ($mostCommentedTweets as $t): ?>
          <li>
            <div class="tweet-text"><?php echo htmlspecialchars($t['status']); ?></div>
            <div class="tweet-stats"><i class="fa-solid fa-comment"></i> <?php echo $t['comment_count']; ?> comments</div>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>

    <div class="section">
      <h3>📆 Daily Activity</h3>
      <canvas id="dailyActivity"></canvas>
    </div>

    <div class="section">
      <h3>👥 Daily Followers Gained</h3>
      <canvas id="followersChart"></canvas>
    </div>
  <?php endif; ?>
</main>

<script>
const body = document.body;
const toggleBtn = document.getElementById('themeToggle');
const icon = toggleBtn.querySelector('i');
if (localStorage.getItem('theme') === 'dark') {
  body.classList.add('dark');
  icon.classList.replace('fa-moon', 'fa-sun');
}
toggleBtn.addEventListener('click', () => {
  body.classList.toggle('dark');
  const dark = body.classList.contains('dark');
  icon.classList.toggle('fa-sun', dark);
  icon.classList.toggle('fa-moon', !dark);
  localStorage.setItem('theme', dark ? 'dark' : 'light');
});

// Charts
<?php if ($user_id): ?>
const dailyLabels = <?php echo json_encode(array_column($dailyData, 'day')); ?>;
const dailyCounts = <?php echo json_encode(array_column($dailyData, 'tweets')); ?>;
const followerLabels = <?php echo json_encode(array_column($followersData, 'day')); ?>;
const followerCounts = <?php echo json_encode(array_column($followersData, 'gained')); ?>;

new Chart(document.getElementById('dailyActivity'), {
  type: 'line',
  data: {
    labels: dailyLabels,
    datasets: [{
      label: 'Tweets per Day',
      data: dailyCounts,
      borderColor: '#1DA1F2',
      backgroundColor: 'rgba(29,161,242,0.3)',
      fill: true,
      borderWidth: 2,
      tension: 0.3
    }]
  },
  options: {
    plugins:{legend:{display:false}},
    scales:{y:{beginAtZero:true}, x:{ticks:{maxRotation:90, minRotation:45}}}
  }
});

new Chart(document.getElementById('followersChart'), {
  type: 'bar',
  data: {
    labels: followerLabels,
    datasets: [{
      label: 'Followers Gained',
      data: followerCounts,
      backgroundColor: 'rgba(23,191,99,0.6)',
      borderColor: '#17BF63',
      borderWidth: 2,
      borderRadius: 6
    }]
  },
  options: {
    plugins:{legend:{display:false}},
    scales:{y:{beginAtZero:true}, x:{ticks:{maxRotation:90, minRotation:45}}}
  }
});
<?php endif; ?>
</script>
</body>
</html>
