<?php
require_once '../core/init.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: index.php');
    exit;
}

$pdo = Connect::connect();

if (!isset($_GET['id'])) {
    header('Location: quotes.php');
    exit;
}

$quoteId = (int)$_GET['id'];

// Fetch quote details
$stmt = $pdo->prepare("
    SELECT r.*, u.username, u.name, u.img AS avatar, t.status AS original_tweet
    FROM retweets r
    LEFT JOIN users u ON r.retweet_id = u.id
    LEFT JOIN tweets t ON r.tweet_id = t.post_id
    WHERE r.post_id = ?
");
$stmt->execute([$quoteId]);
$quote = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$quote) {
    header('Location: quotes.php');
    exit;
}

// Handle update
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $retweet_msg = trim($_POST['retweet_msg']);

    if ($retweet_msg === '') {
        $errors[] = "Quote message cannot be empty.";
    }

    if (empty($errors)) {
        $stmt = $pdo->prepare("UPDATE retweets SET retweet_msg = ? WHERE post_id = ?");
        $stmt->execute([$retweet_msg, $quoteId]);
        header('Location: quotes.php');
        exit;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Edit Quote/Retweet · Admin Panel</title>
  <link rel="icon" href="../assets/images/twitter-logo.png" type="image/png">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    :root { --primary: #1DA1F2; --secondary: #14171A; --light: #f5f8fa; --white: #fff; --gray: #657786; --danger: #e0245e; }
    body.dark { --primary: #1DA1F2; --secondary: #e6ecf0; --light: #1a1d21; --white: #1c1f23; --gray: #8899a6; }
    * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Poppins', sans-serif; transition: background 0.3s, color 0.3s; }
    body { display: flex; min-height: 100vh; background: var(--light); color: var(--secondary); }

    .sidebar { width: 250px; background: var(--secondary); color: var(--white); display: flex; flex-direction: column; justify-content: space-between; padding: 20px 0; position: fixed; left: 0; top: 0; bottom: 0; }
    .sidebar-header { text-align: center; margin-bottom: 20px; }
    .sidebar-logo { width: 60px; margin-bottom: 10px; }
    .sidebar-nav a { color: var(--white); text-decoration: none; padding: 14px 25px; display: flex; align-items: center; gap: 10px; font-size: 15px; transition: all 0.3s; }
    .sidebar-nav a:hover, .sidebar-nav a.active { background: var(--primary); border-radius: 0 20px 20px 0; }
    .sidebar-footer { text-align: center; padding: 15px; }
    .logout-btn { color: var(--white); text-decoration: none; background: var(--danger); padding: 10px 20px; border-radius: 25px; }

    .main-content { margin-left: 250px; flex: 1; padding: 30px; }
    .topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
    .theme-toggle { background: none; border: none; color: var(--primary); font-size: 22px; cursor: pointer; }

    form { background: var(--white); padding: 30px; border-radius: 10px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); max-width: 700px; }
    form .form-group { margin-bottom: 15px; }
    form label { font-weight: 600; display: block; margin-bottom: 5px; }
    form textarea { width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #ccc; resize: vertical; min-height: 80px; }
    form button { background: var(--primary); color: #fff; padding: 10px 25px; border: none; border-radius: 25px; cursor: pointer; font-weight: 600; }
    .errors { color: var(--danger); margin-bottom: 10px; }

    .user-info { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
    .user-info img { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; }
    .original-tweet { background: #f1f1f1; padding: 10px 15px; border-radius: 8px; margin-top: 10px; font-size: 14px; color: var(--gray); }
  </style>
</head>
<body>
  <aside class="sidebar">
    <div>
      <div class="sidebar-header">
        <img src="../assets/images/twitter-logo.png" class="sidebar-logo" alt="logo">
        <h2>Admin Panel</h2>
      </div>
      <nav class="sidebar-nav">
        <a href="dashboard.php"><i class="fa-solid fa-house"></i> Dashboard</a>
        <a href="users.php"><i class="fa-solid fa-users"></i> Manage Users</a>
        <a href="tweets.php"><i class="fa-brands fa-twitter"></i> Manage Tweets</a>
        <a href="comments.php"><i class="fa-solid fa-comments"></i> Manage Comments</a>
        <a href="trends.php"><i class="fa-solid fa-chart-line"></i> Manage Trends</a>
        <a href="replies.php"><i class="fa-solid fa-reply"></i> Manage Replies</a>
        <a href="quotes.php" class="active"><i class="fa-solid fa-retweet"></i> Manage Quotes</a>
        <a href="bookmarks.php"><i class="fa-solid fa-bookmark"></i> Manage Bookmarks</a>
        <a href="user_analytics.php"><i class="fa-solid fa-chart-simple"></i> User Analytics</a>
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
      <h1>Edit Quote/Retweet</h1>
      <a href="quotes.php" class="theme-toggle" title="Back"><i class="fa-solid fa-arrow-left"></i></a>
    </div>

    <?php if (!empty($errors)): ?>
      <div class="errors">
        <?php foreach ($errors as $e) echo "<p>" . htmlspecialchars($e) . "</p>"; ?>
      </div>
    <?php endif; ?>

    <form method="POST">
      <div class="user-info">
        <img src="../assets/images/users/<?php echo htmlspecialchars($quote['avatar']); ?>" alt="">
        <div>
          <strong><?php echo htmlspecialchars($quote['name']); ?></strong><br>
          <small>@<?php echo htmlspecialchars($quote['username']); ?></small>
        </div>
      </div>

      <label>Original Tweet</label>
      <div class="original-tweet"><?php echo htmlspecialchars($quote['original_tweet'] ?? '—'); ?></div>

      <div class="form-group">
        <label>Quote Message</label>
        <textarea name="retweet_msg"><?php echo htmlspecialchars($quote['retweet_msg']); ?></textarea>
      </div>

      <button type="submit">Update Quote</button>
    </form>
  </main>
</body>
</html>
