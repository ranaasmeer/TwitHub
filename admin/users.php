<?php
require_once '../core/init.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: index.php');
    exit;
}

$pdo = Connect::connect();

// Handle Block/Unblock User
if (isset($_GET['block'])) {
    $userId = (int)$_GET['block'];
    $action = isset($_GET['action']) ? $_GET['action'] : '';
    
    if ($action === 'block') {
        $stmt = $pdo->prepare("UPDATE users SET is_blocked = 1 WHERE id = ?");
        $stmt->execute([$userId]);
        $_SESSION['admin_success'] = "🚫 User has been blocked successfully!";
    } elseif ($action === 'unblock') {
        $stmt = $pdo->prepare("UPDATE users SET is_blocked = 0 WHERE id = ?");
        $stmt->execute([$userId]);
        $_SESSION['admin_success'] = "✅ User has been unblocked successfully!";
    }
    
    header("Location: users.php");
    exit;
}

// Handle Delete User
if (isset($_GET['delete'])) {
    $userId = (int)$_GET['delete'];
    
    try {
        $pdo->beginTransaction();
        
        // 1. FIRST, GET ALL TWEET IDs FROM THIS USER (for media deletion)
        $tweetIdsStmt = $pdo->prepare("SELECT post_id FROM tweets WHERE tweet_by = ?");
        $tweetIdsStmt->execute([$userId]);
        $userTweetIds = $tweetIdsStmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (!empty($userTweetIds)) {
            // 2. DELETE PHYSICAL IMAGE FILES FROM tweet_media
            $placeholders = implode(',', array_fill(0, count($userTweetIds), '?'));
            $mediaStmt = $pdo->prepare("SELECT media_path FROM tweet_media WHERE tweet_id IN ($placeholders)");
            $mediaStmt->execute($userTweetIds);
            $mediaFiles = $mediaStmt->fetchAll(PDO::FETCH_COLUMN);
            
            foreach ($mediaFiles as $mediaFile) {
                $filePath = "../assets/images/tweets/" . $mediaFile;
                if (file_exists($filePath)) {
                    unlink($filePath);
                }
            }
            
            // 3. DELETE PHYSICAL VIDEO FILES FROM tweet_videos
            $videoStmt = $pdo->prepare("SELECT video_path FROM tweet_videos WHERE tweet_id IN ($placeholders)");
            $videoStmt->execute($userTweetIds);
            $videoFiles = $videoStmt->fetchAll(PDO::FETCH_COLUMN);
            
            foreach ($videoFiles as $videoFile) {
                $filePath = "../assets/videos/tweets/" . $videoFile;
                if (file_exists($filePath)) {
                    unlink($filePath);
                }
            }
            
            // 4. DELETE OLD SINGLE IMAGES FROM tweets.img COLUMN
            $imgStmt = $pdo->prepare("SELECT img FROM tweets WHERE tweet_by = ? AND img IS NOT NULL");
            $imgStmt->execute([$userId]);
            $oldImages = $imgStmt->fetchAll(PDO::FETCH_COLUMN);
            
            foreach ($oldImages as $oldImg) {
                if (!empty($oldImg)) {
                    $oldImgPaths = explode(',', $oldImg);
                    foreach ($oldImgPaths as $imgPath) {
                        $filePath = "../assets/images/tweets/" . $imgPath;
                        if (file_exists($filePath)) {
                            unlink($filePath);
                        }
                    }
                }
            }
            
            // 5. DELETE OLD SINGLE VIDEOS FROM tweets.video COLUMN
            $videoStmt = $pdo->prepare("SELECT video FROM tweets WHERE tweet_by = ? AND video IS NOT NULL");
            $videoStmt->execute([$userId]);
            $oldVideos = $videoStmt->fetchAll(PDO::FETCH_COLUMN);
            
            foreach ($oldVideos as $oldVideo) {
                if (!empty($oldVideo)) {
                    $filePath = "../assets/videos/tweets/" . $oldVideo;
                    if (file_exists($filePath)) {
                        unlink($filePath);
                    }
                }
            }
        }
        
        // 6. DELETE USER AVATAR AND COVER IMAGES
        $userStmt = $pdo->prepare("SELECT img, imgCover FROM users WHERE id = ?");
        $userStmt->execute([$userId]);
        $userData = $userStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($userData) {
            if (!empty($userData['img']) && $userData['img'] !== 'default.jpg') {
                $avatarPath = "../assets/images/users/" . $userData['img'];
                if (file_exists($avatarPath)) {
                    unlink($avatarPath);
                }
            }
            if (!empty($userData['imgCover']) && $userData['imgCover'] !== 'cover.png') {
                $coverPath = "../assets/images/users/" . $userData['imgCover'];
                if (file_exists($coverPath)) {
                    unlink($coverPath);
                }
            }
        }
        
        // 7. DELETE FROM ALL RELATED TABLES (NO CASCADE)
        $queries = [
            "DELETE FROM tweet_media WHERE tweet_id IN ($placeholders)",
            "DELETE FROM tweet_videos WHERE tweet_id IN ($placeholders)",
            "DELETE FROM reel_views WHERE user_id = ?",
            "DELETE FROM reels_saves WHERE user_id = ?",
            "DELETE FROM reels_reply_likes WHERE user_id = ?",
            "DELETE FROM reels_replies WHERE user_id = ?",
            "DELETE FROM reels_comment_likes WHERE user_id = ?",
            "DELETE FROM reels_comments WHERE user_id = ?",
            "DELETE FROM reel_likes WHERE user_id = ?",
            "DELETE FROM reels WHERE user_id = ?",
            "DELETE FROM reply_likes WHERE user_id = ?",
            "DELETE FROM replies WHERE user_id = ?",
            "DELETE FROM comment_likes WHERE user_id = ?",
            "DELETE FROM comments WHERE user_id = ?",
            "DELETE FROM likes WHERE user_id = ?",
            "DELETE FROM retweets WHERE user_id = ?",
            "DELETE FROM tweets WHERE tweet_by = ?",
            "DELETE FROM bookmarks WHERE user_id = ?",
            "DELETE FROM follow WHERE follower_id = ? OR following_id = ?",
            "DELETE FROM notifications WHERE notify_for = ? OR notify_from = ?",
            "DELETE FROM messages WHERE sender_id = ? OR receiver_id = ?",
            "DELETE FROM user_category_preferences WHERE user_id = ?",
            "DELETE FROM user_hashtag_interests WHERE user_id = ?",
            "DELETE FROM posts WHERE user_id = ?",
        ];
        
        foreach ($queries as $query) {
            try {
                $stmt = $pdo->prepare($query);
                if (strpos($query, 'IN') !== false && strpos($query, 'tweet_media') !== false) {
                    if (!empty($userTweetIds)) {
                        $stmt->execute($userTweetIds);
                    }
                } elseif (strpos($query, 'OR') !== false) {
                    $stmt->execute([$userId, $userId]);
                } else {
                    $stmt->execute([$userId]);
                }
            } catch (PDOException $e) {
                error_log("Query failed: " . $query . " - Error: " . $e->getMessage());
            }
        }
        
        // 8. DELETE REEL MUSIC USAGE
        try {
            $reelStmt = $pdo->prepare("SELECT id FROM reels WHERE user_id = ?");
            $reelStmt->execute([$userId]);
            $reelIds = $reelStmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (!empty($reelIds)) {
                $reelPlaceholders = implode(',', array_fill(0, count($reelIds), '?'));
                $stmt = $pdo->prepare("DELETE FROM reel_music_usage WHERE reel_id IN ($reelPlaceholders)");
                $stmt->execute($reelIds);
            }
        } catch (PDOException $e) {
            error_log("reel_music_usage delete failed: " . $e->getMessage());
        }
        
        // 9. FINALLY DELETE THE USER
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        
        $pdo->commit();
        
        $_SESSION['admin_success'] = "✅ User deleted successfully! All related data (tweets, images, videos, reels, comments, likes, etc.) has been removed.";
        
    } catch (Exception $e) {
        $pdo->rollback();
        $_SESSION['admin_error'] = "❌ Error deleting user: " . $e->getMessage();
    }
    
    header("Location: users.php");
    exit;
}

// Initial fetch all users
$usersStmt = $pdo->query("
    SELECT u.*, 
           (SELECT COUNT(*) FROM tweets WHERE tweet_by = u.id) as tweet_count,
           (SELECT COUNT(*) FROM follow WHERE following_id = u.id) as follower_count,
           (SELECT COUNT(*) FROM follow WHERE follower_id = u.id) as following_count,
           (SELECT COUNT(*) FROM reels WHERE user_id = u.id) as reels_count
    FROM users u 
    ORDER BY u.id DESC
");
$allUsers = $usersStmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Manage Users · Admin Panel</title>
  <link rel="icon" href="../assets/images/twitter-logo.png" type="image/png">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    :root {
      --primary: #1DA1F2;
      --secondary: #14171A;
      --light: #f5f8fa;
      --white: #fff;
      --gray: #657786;
      --danger: #e0245e;
      --success: #17bf63;
      --warning: #ffad1f;
      --blocked: #9e9e9e;
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

    /* Sidebar */
    .sidebar { width: 250px; background: var(--secondary); color: var(--white); display: flex; flex-direction: column; justify-content: space-between; padding: 20px 0; position: fixed; left: 0; top: 0; bottom: 0; }
    .sidebar-header { text-align: center; margin-bottom: 20px; }
    .sidebar-logo { width: 60px; margin-bottom: 10px; }
    .sidebar-nav a { color: var(--white); text-decoration: none; padding: 14px 25px; display: flex; align-items: center; gap: 10px; font-size: 15px; transition: all 0.3s; }
    .sidebar-nav a:hover, .sidebar-nav a.active { background: var(--primary); border-radius: 0 20px 20px 0; }
    .sidebar-footer { text-align: center; padding: 15px; }
    .logout-btn { color: var(--white); text-decoration: none; background: var(--danger); padding: 10px 20px; border-radius: 25px; transition: 0.3s; }
    .logout-btn:hover { background: #ff4d6d; }

    /* Main */
    .main-content { margin-left: 250px; flex: 1; padding: 30px; }
    .topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; flex-wrap: wrap; gap: 15px; }
    .theme-toggle { background: none; border: none; color: var(--primary); font-size: 22px; cursor: pointer; }

    h1 { margin-bottom: 20px; }

    /* Search Form Styles */
    .search-form {
      margin-bottom: 25px;
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
      align-items: center;
      background: var(--white);
      padding: 15px 20px;
      border-radius: 12px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    .search-form input {
      flex: 2;
      min-width: 250px;
      padding: 12px 18px;
      border-radius: 30px;
      border: 1px solid #ddd;
      font-size: 14px;
      background: var(--light);
      color: var(--secondary);
      transition: all 0.3s;
    }
    .search-form input:focus {
      outline: none;
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(29,161,242,0.1);
    }
    .search-form button {
      padding: 12px 24px;
      border-radius: 30px;
      background: var(--primary);
      color: white;
      border: none;
      cursor: pointer;
      font-weight: 600;
      transition: all 0.3s;
    }
    .search-form button:hover {
      background: #0d8ddb;
      transform: translateY(-1px);
    }
    .search-form .reset-btn {
      background: var(--gray);
      text-decoration: none;
      display: inline-block;
      text-align: center;
      color: white;
      padding: 12px 24px;
      border-radius: 30px;
    }
    .search-form .reset-btn:hover {
      background: #5a6e7e;
      transform: translateY(-1px);
    }
    .search-info {
      font-size: 13px;
      color: var(--gray);
      margin-bottom: 15px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 10px;
    }
    .search-loading {
      display: none;
      text-align: center;
      padding: 20px;
      color: var(--primary);
    }
    .search-loading i {
      animation: spin 1s linear infinite;
    }
    @keyframes spin {
      from { transform: rotate(0deg); }
      to { transform: rotate(360deg); }
    }

    /* Banner Alert Styles */
    .alert-banner {
      position: fixed;
      top: 20px;
      right: 20px;
      z-index: 9999;
      padding: 16px 24px;
      border-radius: 12px;
      font-size: 14px;
      font-weight: 500;
      display: flex;
      align-items: center;
      gap: 12px;
      box-shadow: 0 4px 20px rgba(0,0,0,0.15);
      animation: slideInRight 0.3s ease;
      max-width: 450px;
    }
    
    @keyframes slideInRight {
      from {
        transform: translateX(100%);
        opacity: 0;
      }
      to {
        transform: translateX(0);
        opacity: 1;
      }
    }
    
    @keyframes fadeOut {
      from {
        opacity: 1;
      }
      to {
        opacity: 0;
        visibility: hidden;
      }
    }
    
    .alert-success {
      background: linear-gradient(135deg, #17bf63, #129f52);
      color: white;
      border-left: 4px solid #0d7a3e;
    }
    
    .alert-error {
      background: linear-gradient(135deg, #e0245e, #c01b4f);
      color: white;
      border-left: 4px solid #9a123b;
    }
    
    .alert-banner i {
      font-size: 20px;
    }
    
    .alert-banner .close-alert {
      margin-left: auto;
      cursor: pointer;
      opacity: 0.8;
      transition: opacity 0.2s;
    }
    
    .alert-banner .close-alert:hover {
      opacity: 1;
    }

    /* Table */
    table { width: 100%; border-collapse: collapse; background: var(--white); border-radius: 10px; overflow: hidden; box-shadow: 0 4px 10px rgba(0,0,0,0.05); }
    th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #eee; font-size: 14px; }
    th { background: var(--primary); color: #fff; font-weight: 600; }
    td img { width: 40px; height: 40px; border-radius: 50%; object-fit: cover; }
    tr:hover { background: rgba(29,161,242,0.1); }
    tr.blocked { background: rgba(158, 158, 158, 0.1); opacity: 0.8; }
    .no-results td { text-align: center; padding: 40px; }

    .actions a { margin-right: 8px; text-decoration: none; color: var(--primary); font-weight: bold; display: inline-block; margin: 2px; }
    .actions a.delete { color: var(--danger); }
    .actions a.block { color: var(--warning); }
    .actions a.unblock { color: var(--success); }
    .actions a:hover { text-decoration: underline; }

    .stats { font-size: 12px; color: var(--gray); }
    .blocked-badge {
      background: var(--danger);
      color: white;
      padding: 2px 8px;
      border-radius: 20px;
      font-size: 10px;
      font-weight: 600;
      margin-left: 5px;
    }

    body.dark table { background: var(--white); color: var(--secondary); }
    body.dark th { background: var(--primary); color: #fff; }
    body.dark tr:hover { background: rgba(29,161,242,0.2); }
    body.dark .search-form { background: var(--white); }
    
    /* Responsive */
    @media (max-width: 768px) {
      .main-content { margin-left: 0; padding: 15px; }
      .sidebar { display: none; }
      .search-form { flex-direction: column; }
      .search-form input, .search-form button, .search-form .reset-btn { width: 100%; }
      table, thead, tbody, th, td, tr { display: block; }
      thead { display: none; }
      tr { margin-bottom: 15px; border: 1px solid var(--gray); border-radius: 10px; background: var(--white); }
      td { display: flex; justify-content: space-between; align-items: center; padding: 10px; border-bottom: 1px solid #eee; }
      td:before { content: attr(data-label); font-weight: bold; width: 40%; font-size: 12px; }
      td:last-child { border-bottom: none; }
    }
  </style>
</head>
<body>

  <!-- Banner Alerts -->
  <?php if (isset($_SESSION['admin_success'])): ?>
  <div class="alert-banner alert-success" id="successAlert">
    <i class="fa-solid fa-check-circle"></i>
    <span><?php echo $_SESSION['admin_success']; ?></span>
    <i class="fa-solid fa-times close-alert"></i>
  </div>
  <?php unset($_SESSION['admin_success']); ?>
  <?php endif; ?>
  
  <?php if (isset($_SESSION['admin_error'])): ?>
  <div class="alert-banner alert-error" id="errorAlert">
    <i class="fa-solid fa-exclamation-triangle"></i>
    <span><?php echo $_SESSION['admin_error']; ?></span>
    <i class="fa-solid fa-times close-alert"></i>
  </div>
  <?php unset($_SESSION['admin_error']); ?>
  <?php endif; ?>

  <!-- Sidebar -->
  <aside class="sidebar">
    <div>
      <div class="sidebar-header">
        <img src="../assets/images/twitter-logo.png" alt="logo" class="sidebar-logo">
        <h2>Admin Panel</h2>
      </div>
      <nav class="sidebar-nav">
        <a href="dashboard.php"><i class="fa-solid fa-house"></i> Dashboard</a>
        <a href="users.php" class="active"><i class="fa-solid fa-users"></i> Manage Users</a>
        <a href="tweets.php"><i class="fa-brands fa-twitter"></i> Manage Tweets</a>
        <a href="comments.php"><i class="fa-solid fa-comments"></i> Manage Comments</a>
        <a href="trends.php"><i class="fa-solid fa-chart-line"></i> Manage Trends</a>
        <a href="replies.php"><i class="fa-solid fa-reply"></i> Manage Replies</a>
        <a href="quotes.php"><i class="fa-solid fa-retweet"></i> Manage Quotes/Retweets</a>
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

  <!-- Main -->
  <main class="main-content">
    <div class="topbar">
      <h1>Manage Users</h1>
      <button id="themeToggle" class="theme-toggle"><i class="fa-solid fa-moon"></i></button>
    </div>

    <!-- Live Search Form -->
    <div class="search-form">
      <input type="text" id="liveSearchInput" placeholder="🔍 Live search by username, name, or email..." autocomplete="off">
      <button onclick="performSearch()"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
      <button onclick="resetSearch()" class="reset-btn" style="background: var(--gray); color: white; border: none;"><i class="fa-solid fa-undo"></i> Reset</button>
    </div>
    
    <div id="searchInfo" class="search-info" style="display: none;">
      <span><i class="fa-solid fa-search"></i> Showing results for: <strong id="searchTerm"></strong></span>
      <span id="resultCount"></span>
    </div>
    
    <div id="searchLoading" class="search-loading">
      <i class="fas fa-spinner fa-pulse"></i> Searching...
    </div>

    <!-- Table Container for Live Results -->
    <div id="usersTableContainer">
      <table id="usersTable">
        <thead>
          <tr>
            <th>ID</th>
            <th>Avatar</th>
            <th>Username</th>
            <th>Name</th>
            <th>Email</th>
            <th>Status</th>
            <th>Stats</th>
            <th>Verified</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="usersTableBody">
          <?php foreach ($allUsers as $user): ?>
          <tr data-user-id="<?php echo $user['id']; ?>" class="<?php echo ($user['is_blocked'] == 1) ? 'blocked' : ''; ?>">
            <td data-label="ID"><?php echo $user['id']; ?></td>
            <td data-label="Avatar"><img src="../assets/images/users/<?php echo htmlspecialchars($user['img']); ?>" alt="avatar"></td>
            <td data-label="Username">
              <?php echo htmlspecialchars($user['username']); ?>
              <?php if ($user['is_blocked'] == 1): ?>
                <span class="blocked-badge">Blocked</span>
              <?php endif; ?>
            </td>
            <td data-label="Name"><?php echo htmlspecialchars($user['name']); ?></td>
            <td data-label="Email"><?php echo htmlspecialchars($user['email']); ?></td>
            <td data-label="Status">
              <?php if ($user['is_blocked'] == 1): ?>
                <span style="color: var(--danger);"><i class="fa-solid fa-ban"></i> Blocked</span>
              <?php else: ?>
                <span style="color: var(--success);"><i class="fa-solid fa-check-circle"></i> Active</span>
              <?php endif; ?>
            </td>
            <td data-label="Stats" class="stats">
              📝 <?php echo $user['tweet_count'] ?? 0; ?> tweets<br>
              🎬 <?php echo $user['reels_count'] ?? 0; ?> reels<br>
              👥 <?php echo $user['follower_count'] ?? 0; ?> followers
            </td>
            <td data-label="Verified"><?php echo isset($user['is_verified']) && $user['is_verified'] ? '✅' : '❌'; ?></td>
            <td data-label="Actions" class="actions">
              <a href="edit_user.php?id=<?php echo $user['id']; ?>"><i class="fa-solid fa-pen"></i> Edit</a>
              <?php if ($user['is_blocked'] == 1): ?>
                <a href="users.php?block=<?php echo $user['id']; ?>&action=unblock" class="unblock" onclick="return confirm('Unblock this user? They will be able to login and post again.');">
                  <i class="fa-solid fa-unlock-alt"></i> Unblock
                </a>
              <?php else: ?>
                <a href="users.php?block=<?php echo $user['id']; ?>&action=block" class="block" onclick="return confirm('Block this user? They will not be able to login or post any content.');">
                  <i class="fa-solid fa-ban"></i> Block
                </a>
              <?php endif; ?>
              <a href="users.php?delete=<?php echo $user['id']; ?>" class="delete" 
                 onclick="return confirm('⚠️ WARNING: This will delete ALL user data including:\n\n📝 Tweets, retweets, likes, comments, replies\n🎬 Reels, reel likes, reel comments, reel saves\n🖼️ All images and videos from tweets\n💬 Messages, notifications, bookmarks\n👥 Follow relationships, preferences\n\nThis CANNOT be undone!\n\nAre you absolutely sure?')">
                 <i class="fa-solid fa-trash"></i> Delete
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </main>

  <script>
    // All users data from PHP (embedded for live search)
    const allUsers = <?php echo json_encode($allUsers); ?>;
    
    // Perform live search
    function performSearch() {
      const searchInput = document.getElementById('liveSearchInput');
      const searchTerm = searchInput.value.trim().toLowerCase();
      const tbody = document.getElementById('usersTableBody');
      const searchInfo = document.getElementById('searchInfo');
      const searchTermSpan = document.getElementById('searchTerm');
      const resultCountSpan = document.getElementById('resultCount');
      
      if (searchTerm === '') {
        renderUsers(allUsers);
        searchInfo.style.display = 'none';
        return;
      }
      
      const filteredUsers = allUsers.filter(user => {
        return (user.username && user.username.toLowerCase().includes(searchTerm)) ||
               (user.name && user.name.toLowerCase().includes(searchTerm)) ||
               (user.email && user.email.toLowerCase().includes(searchTerm));
      });
      
      renderUsers(filteredUsers);
      
      searchTermSpan.textContent = searchInput.value;
      resultCountSpan.textContent = filteredUsers.length + ' user(s) found';
      searchInfo.style.display = 'flex';
    }
    
    function renderUsers(users) {
      const tbody = document.getElementById('usersTableBody');
      
      if (users.length === 0) {
        tbody.innerHTML = '<tr class="no-results"><td colspan="9" style="text-align:center; padding:40px;"><i class="fa-solid fa-user-slash" style="font-size:40px; opacity:0.5;"></i><br><br>No users found. Try a different search term. </tr>';
        return;
      }
      
      tbody.innerHTML = '';
      users.forEach(user => {
        const row = document.createElement('tr');
        row.setAttribute('data-user-id', user.id);
        if (user.is_blocked == 1) row.classList.add('blocked');
        row.innerHTML = `
          <td data-label="ID">${user.id}</td>
          <td data-label="Avatar"><img src="../assets/images/users/${escapeHtml(user.img)}" alt="avatar"></td>
          <td data-label="Username">
            ${escapeHtml(user.username)}
            ${user.is_blocked == 1 ? '<span class="blocked-badge">Blocked</span>' : ''}
          </td>
          <td data-label="Name">${escapeHtml(user.name)}</td>
          <td data-label="Email">${escapeHtml(user.email)}</td>
          <td data-label="Status">
            ${user.is_blocked == 1 ? 
              '<span style="color: var(--danger);"><i class="fa-solid fa-ban"></i> Blocked</span>' : 
              '<span style="color: var(--success);"><i class="fa-solid fa-check-circle"></i> Active</span>'}
          </td>
          <td data-label="Stats" class="stats">
            📝 ${user.tweet_count || 0} tweets<br>
            🎬 ${user.reels_count || 0} reels<br>
            👥 ${user.follower_count || 0} followers
          </td>
          <td data-label="Verified">${user.is_verified ? '✅' : '❌'}</td>
          <td data-label="Actions" class="actions">
            <a href="edit_user.php?id=${user.id}"><i class="fa-solid fa-pen"></i> Edit</a>
            ${user.is_blocked == 1 ? 
              `<a href="users.php?block=${user.id}&action=unblock" class="unblock" onclick="return confirm('Unblock this user? They will be able to login and post again.');">
                 <i class="fa-solid fa-unlock-alt"></i> Unblock
               </a>` : 
              `<a href="users.php?block=${user.id}&action=block" class="block" onclick="return confirm('Block this user? They will not be able to login or post any content.');">
                 <i class="fa-solid fa-ban"></i> Block
               </a>`
            }
            <a href="users.php?delete=${user.id}" class="delete" 
               onclick="return confirm('⚠️ WARNING: This will delete ALL user data including:\\n\\n📝 Tweets, retweets, likes, comments, replies\\n🎬 Reels, reel likes, reel comments, reel saves\\n🖼️ All images and videos from tweets\\n💬 Messages, notifications, bookmarks\\n👥 Follow relationships, preferences\\n\\nThis CANNOT be undone!\\n\\nAre you absolutely sure?')">
               <i class="fa-solid fa-trash"></i> Delete
            </a>
          </td>
        `;
        tbody.appendChild(row);
      });
    }
    
    function escapeHtml(str) {
      if (!str) return '';
      return str.replace(/[&<>]/g, function(m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        return m;
      });
    }
    
    function resetSearch() {
      document.getElementById('liveSearchInput').value = '';
      renderUsers(allUsers);
      document.getElementById('searchInfo').style.display = 'none';
    }
    
    document.getElementById('liveSearchInput').addEventListener('input', function() {
      performSearch();
    });
    
    document.getElementById('liveSearchInput').addEventListener('keypress', function(e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        performSearch();
      }
    });
    
    setTimeout(function() {
      const successAlert = document.getElementById('successAlert');
      const errorAlert = document.getElementById('errorAlert');
      
      if (successAlert) {
        successAlert.style.animation = 'fadeOut 0.5s ease forwards';
        setTimeout(function() { successAlert.style.display = 'none'; }, 500);
      }
      if (errorAlert) {
        errorAlert.style.animation = 'fadeOut 0.5s ease forwards';
        setTimeout(function() { errorAlert.style.display = 'none'; }, 500);
      }
    }, 5000);
    
    document.querySelectorAll('.close-alert').forEach(function(closeBtn) {
      closeBtn.addEventListener('click', function() { this.parentElement.style.display = 'none'; });
    });
    
    const body = document.body;
    const toggleBtn = document.getElementById('themeToggle');
    const icon = toggleBtn.querySelector('i');

    if (localStorage.getItem('theme') === 'dark') {
      body.classList.add('dark');
      icon.classList.replace('fa-moon', 'fa-sun');
    }

    toggleBtn.addEventListener('click', () => {
      body.classList.toggle('dark');
      const isDark = body.classList.contains('dark');
      icon.classList.toggle('fa-sun', isDark);
      icon.classList.toggle('fa-moon', !isDark);
      localStorage.setItem('theme', isDark ? 'dark' : 'light');
    });
  </script>
</body>
</html>