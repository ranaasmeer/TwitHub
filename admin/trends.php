<?php
require_once '../core/init.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: index.php');
    exit;
}

$pdo = Connect::connect();

// Handle deletion
if (isset($_GET['delete'])) {
    $trendId = (int)$_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM trends WHERE id = ?");
    $stmt->execute([$trendId]);
    header('Location: trends.php');
    exit;
}

// Handle bulk delete
if (isset($_POST['bulk_delete']) && isset($_POST['selected_trends'])) {
    $selected = $_POST['selected_trends'];
    $placeholders = implode(',', array_fill(0, count($selected), '?'));
    $stmt = $pdo->prepare("DELETE FROM trends WHERE id IN ($placeholders)");
    $stmt->execute($selected);
    header('Location: trends.php');
    exit;
}

// Handle editing
$editing = false;
if (isset($_GET['edit'])) {
    $editing = true;
    $editId = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM trends WHERE id = ?");
    $stmt->execute([$editId]);
    $trendToEdit = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$trendToEdit) {
        header('Location: trends.php');
        exit;
    }
}

$errors = [];
$success = '';

// Handle form submission (add)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_trend'])) {
    $hashtag = trim($_POST['hashtag']);
    $type = trim($_POST['type']);

    if (!$hashtag) $errors[] = "Hashtag cannot be empty.";

    if (empty($errors)) {
        $stmt = $pdo->prepare("INSERT INTO trends (hashtag, type, count, recent_count) VALUES (?, ?, 0, 0)");
        try {
            $stmt->execute([$hashtag, $type]);
            $success = "Trend added successfully.";
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) $errors[] = "Hashtag already exists.";
            else $errors[] = $e->getMessage();
        }
    }
}

// Handle update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_trend'])) {
    $hashtag = trim($_POST['hashtag']);
    $type = trim($_POST['type']);
    $trendId = (int)$_POST['edit_id'];

    if (!$hashtag) $errors[] = "Hashtag cannot be empty.";

    if (empty($errors)) {
        $stmt = $pdo->prepare("UPDATE trends SET hashtag=?, type=? WHERE id=?");
        $stmt->execute([$hashtag, $type, $trendId]);
        $success = "Trend updated successfully.";
        header('Location: trends.php');
        exit;
    }
}

// Get search/filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$typeFilter = isset($_GET['type']) ? trim($_GET['type']) : '';
$sortBy = isset($_GET['sort']) ? trim($_GET['sort']) : 'count';
$sortOrder = isset($_GET['order']) && $_GET['order'] === 'asc' ? 'ASC' : 'DESC';

// Build query for trends
$sql = "SELECT * FROM trends WHERE 1=1";
$params = [];

if (!empty($search)) {
    $sql .= " AND hashtag LIKE ?";
    $params[] = "%$search%";
}
if (!empty($typeFilter)) {
    $sql .= " AND type = ?";
    $params[] = $typeFilter;
}

$allowedSorts = ['count', 'recent_count', 'created_on', 'hashtag', 'id'];
if (in_array($sortBy, $allowedSorts)) {
    $sql .= " ORDER BY $sortBy $sortOrder";
} else {
    $sql .= " ORDER BY count DESC";
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$trends = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get real-time recent counts for last 24 hours using POSTS table
$now = date('Y-m-d H:i:s');
$last24h = date('Y-m-d H:i:s', strtotime('-24 hours'));

// Calculate real-time recent counts for each trend using posts table
foreach ($trends as &$trend) {
    $hashtag = $trend['hashtag'];
    
    // Total count - count all tweets containing this hashtag from tweets table
    $totalStmt = $pdo->prepare("SELECT COUNT(*) as count FROM tweets WHERE status LIKE CONCAT('%', ?, '%')");
    $totalStmt->execute([$hashtag]);
    $totalCount = $totalStmt->fetch(PDO::FETCH_ASSOC);
    $trend['calculated_total'] = $totalCount['count'];
    
    // Recent count (last 24h) - Need to join tweets with posts to get date
    // First get tweet IDs from tweets table, then join with posts table for date
    $recentStmt = $pdo->prepare("
        SELECT COUNT(*) as count 
        FROM tweets t
        INNER JOIN posts p ON t.post_id = p.id
        WHERE t.status LIKE CONCAT('%', ?, '%') 
        AND p.post_on BETWEEN ? AND ?
    ");
    $recentStmt->execute([$hashtag, $last24h, $now]);
    $recentCount = $recentStmt->fetch(PDO::FETCH_ASSOC);
    $trend['calculated_recent'] = $recentCount['count'];
    
    // Update the database counts to match (keeps DB in sync)
    if ($trend['count'] != $trend['calculated_total'] || $trend['recent_count'] != $trend['calculated_recent']) {
        $updateStmt = $pdo->prepare("UPDATE trends SET count = ?, recent_count = ? WHERE id = ?");
        $updateStmt->execute([$trend['calculated_total'], $trend['calculated_recent'], $trend['id']]);
        $trend['count'] = $trend['calculated_total'];
        $trend['recent_count'] = $trend['calculated_recent'];
    }
}

// Get statistics from real data
$statsStmt = $pdo->query("SELECT COUNT(*) as total, SUM(count) as total_usage, AVG(count) as avg_usage FROM trends");
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// Get top trend from real data
$topStmt = $pdo->query("
    SELECT hashtag, count FROM trends ORDER BY count DESC LIMIT 1
");
$topTrend = $topStmt->fetch(PDO::FETCH_ASSOC);

// Get total tweets with hashtags from tweets table
$totalHashtagStmt = $pdo->query("SELECT COUNT(*) as total FROM tweets WHERE status LIKE '#%'");
$totalHashtagTweets = $totalHashtagStmt->fetch(PDO::FETCH_ASSOC);

// Get posts in last 24 hours count
$recentPostsStmt = $pdo->prepare("SELECT COUNT(*) as total FROM posts WHERE post_on BETWEEN ? AND ?");
$recentPostsStmt->execute([$last24h, $now]);
$recentPosts = $recentPostsStmt->fetch(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Manage Trends · Admin Panel</title>
  <link rel="icon" href="../assets/images/twitter-logo.png" type="image/png">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    :root { --primary: #1DA1F2; --secondary: #14171A; --light: #f5f8fa; --white: #fff; --gray: #657786; --danger: #e0245e; --success: #17bf63; --warning: #ffad1f; }
    body.dark { --primary: #1DA1F2; --secondary: #e6ecf0; --light: #1a1d21; --white: #1c1f23; --gray: #8899a6; }
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

    /* Stats Cards */
    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
      gap: 20px;
      margin-bottom: 30px;
    }
    .stat-card {
      background: var(--white);
      border-radius: 16px;
      padding: 20px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.08);
      transition: all 0.3s;
    }
    .stat-card:hover { transform: translateY(-3px); box-shadow: 0 4px 16px rgba(0,0,0,0.12); }
    .stat-card .stat-icon { font-size: 32px; color: var(--primary); margin-bottom: 12px; }
    .stat-card .stat-value { font-size: 28px; font-weight: 700; color: var(--secondary); }
    .stat-card .stat-label { font-size: 13px; color: var(--gray); margin-top: 5px; }
    .stat-card .stat-trend { font-size: 12px; margin-top: 8px; color: var(--success); }
    .stat-card .stat-sub { font-size: 11px; color: var(--gray); margin-top: 5px; }

    /* Search and Filter Bar */
    .search-filter-bar {
      background: var(--white);
      border-radius: 12px;
      padding: 20px;
      margin-bottom: 25px;
      display: flex;
      flex-wrap: wrap;
      gap: 15px;
      align-items: flex-end;
    }
    .search-group { flex: 2; min-width: 200px; }
    .filter-group { flex: 1; min-width: 150px; }
    .search-group label, .filter-group label { font-size: 12px; font-weight: 600; margin-bottom: 5px; display: block; color: var(--primary); }
    .search-group input, .filter-group select {
      width: 100%;
      padding: 10px 14px;
      border-radius: 25px;
      border: 1px solid #ddd;
      font-size: 13px;
      background: var(--light);
      color: var(--secondary);
    }
    .search-group input:focus, .filter-group select:focus {
      outline: none;
      border-color: var(--primary);
      box-shadow: 0 0 0 2px rgba(29,161,242,0.1);
    }
    .reset-btn {
      background: var(--gray);
      color: white;
      padding: 10px 20px;
      border-radius: 25px;
      text-decoration: none;
      font-size: 13px;
      font-weight: 600;
      transition: all 0.2s;
      display: inline-flex;
      align-items: center;
      gap: 8px;
    }
    .reset-btn:hover { background: #5a6e7e; transform: translateY(-1px); }

    /* Add Trend Form */
    .form-card {
      background: var(--white);
      border-radius: 12px;
      padding: 25px;
      margin-bottom: 30px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    }
    .form-card h3 { margin-bottom: 20px; color: var(--primary); font-size: 18px; }
    .form-grid { display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; }
    .form-group { flex: 1; min-width: 180px; }
    .form-group label { display: block; font-size: 12px; font-weight: 600; margin-bottom: 5px; color: var(--gray); }
    .form-group input, .form-group select {
      width: 100%;
      padding: 10px 14px;
      border-radius: 25px;
      border: 1px solid #ddd;
      font-size: 13px;
    }
    .form-group input:focus, .form-group select:focus {
      outline: none;
      border-color: var(--primary);
    }
    .btn-submit { background: var(--primary); color: white; padding: 10px 28px; border: none; border-radius: 25px; cursor: pointer; font-weight: 600; transition: 0.2s; }
    .btn-submit:hover { background: #0d8ddb; transform: translateY(-1px); }

    /* Table */
    table { width: 100%; border-collapse: collapse; background: var(--white); border-radius: 12px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
    th, td { padding: 14px 16px; text-align: left; border-bottom: 1px solid #eee; font-size: 13px; vertical-align: middle; }
    th { background: var(--primary); color: #fff; font-weight: 600; }
    tr:hover { background: rgba(29,161,242,0.05); }
    .action-btn { padding: 6px 12px; border-radius: 20px; text-decoration: none; font-size: 12px; display: inline-block; margin: 0 3px; transition: 0.2s; }
    .edit-btn { background: var(--success); color: #fff; }
    .edit-btn:hover { background: #13a254; transform: translateY(-1px); }
    .delete-btn { background: var(--danger); color: #fff; }
    .delete-btn:hover { background: #c81f4e; transform: translateY(-1px); }
    .checkbox-col { width: 40px; text-align: center; }
    .checkbox-col input { width: 18px; height: 18px; cursor: pointer; }

    .type-badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 600; }
    .type-General { background: #e8f5fe; color: #1da1f2; }
    .type-Trending { background: #feefe8; color: #ff9800; }
    body.dark .type-General { background: #1d3a4f; color: #1da1f2; }
    body.dark .type-Trending { background: #4f2a1d; color: #ff9800; }

    .trend-count { font-weight: 700; color: var(--primary); }
    .trend-recent { font-weight: 600; }
    .trend-recent.hot { color: var(--warning); }

    .sort-link { color: white; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; }
    .sort-link:hover { text-decoration: underline; }

    .bulk-actions { margin-top: 20px; display: flex; justify-content: flex-end; gap: 10px; }
    .bulk-delete-btn { background: var(--danger); color: white; padding: 8px 20px; border: none; border-radius: 25px; cursor: pointer; font-size: 13px; font-weight: 600; transition: 0.2s; }
    .bulk-delete-btn:hover { background: #c81f4e; transform: translateY(-1px); }
    .refresh-btn { background: var(--primary); color: white; padding: 8px 20px; border: none; border-radius: 25px; cursor: pointer; font-size: 13px; font-weight: 600; transition: 0.2s; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; }
    .refresh-btn:hover { background: #0d8ddb; transform: translateY(-1px); }

    .no-results { text-align: center; padding: 40px; color: var(--gray); }

    .errors { margin-bottom: 20px; padding: 12px 15px; background: rgba(224,36,94,0.1); border-left: 4px solid var(--danger); border-radius: 8px; }
    .errors ul { margin: 0; padding-left: 20px; }
    .success { margin-bottom: 20px; padding: 12px 15px; background: rgba(23,191,99,0.1); border-left: 4px solid var(--success); border-radius: 8px; color: var(--success); }

    .info-badge {
      background: var(--gray);
      color: white;
      font-size: 10px;
      padding: 2px 6px;
      border-radius: 12px;
      margin-left: 8px;
      font-weight: normal;
    }

    @media (max-width: 768px) {
      .main-content { margin-left: 0; padding: 15px; }
      .sidebar { display: none; }
      .stats-grid { grid-template-columns: 1fr; }
      .search-filter-bar { flex-direction: column; }
      .form-grid { flex-direction: column; }
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
  <aside class="sidebar">
    <div>
      <div class="sidebar-header">
        <img src="../assets/images/twitter-logo.png" alt="logo" class="sidebar-logo">
        <h2>Admin Panel</h2>
      </div>
      <nav class="sidebar-nav">
        <a href="dashboard.php"><i class="fa-solid fa-house"></i> Dashboard</a>
        <a href="users.php"><i class="fa-solid fa-users"></i> Manage Users</a>
        <a href="tweets.php"><i class="fa-brands fa-twitter"></i> Manage Tweets</a>
        <a href="comments.php"><i class="fa-solid fa-comments"></i> Manage Comments</a>
        <a href="trends.php" class="active"><i class="fa-solid fa-chart-line"></i> Manage Trends</a>
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

  <main class="main-content">
    <div class="topbar">
      <h1><i class="fa-solid fa-chart-line"></i> Manage Trends</h1>
      <button id="themeToggle" class="theme-toggle"><i class="fa-solid fa-moon"></i></button>
    </div>

    <!-- Statistics Cards -->
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-icon"><i class="fa-solid fa-hashtag"></i></div>
        <div class="stat-value"><?php echo number_format($stats['total'] ?? 0); ?></div>
        <div class="stat-label">Total Trends</div>
        <div class="stat-sub"><?php echo number_format($totalHashtagTweets['total'] ?? 0); ?> hashtag tweets</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon"><i class="fa-solid fa-chart-simple"></i></div>
        <div class="stat-value"><?php echo number_format($stats['total_usage'] ?? 0); ?></div>
        <div class="stat-label">Total Mentions</div>
        <div class="stat-trend"><i class="fa-solid fa-arrow-up"></i> All time</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon"><i class="fa-solid fa-chart-line"></i></div>
        <div class="stat-value"><?php echo round($stats['avg_usage'] ?? 0); ?></div>
        <div class="stat-label">Avg Mentions per Trend</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon"><i class="fa-solid fa-trophy"></i></div>
        <div class="stat-value"><?php echo htmlspecialchars($topTrend['hashtag'] ?? '—'); ?></div>
        <div class="stat-label">Top Trending</div>
        <div class="stat-trend"><?php echo number_format($topTrend['count'] ?? 0); ?> mentions</div>
      </div>
    </div>

    <!-- Add Trend Form -->
    <div class="form-card">
      <h3><i class="fa-solid fa-plus-circle"></i> Add New Trend</h3>
      <form action="" method="POST" class="form-grid">
        <div class="form-group">
          <label>Hashtag <span class="info-badge">include #</span></label>
          <input type="text" name="hashtag" placeholder="#example" required>
        </div>
        <div class="form-group">
          <label>Type</label>
          <select name="type">
            <option value="General">General</option>
            <option value="Trending">Trending</option>
          </select>
        </div>
        <button type="submit" name="add_trend" class="btn-submit"><i class="fa-solid fa-plus"></i> Add Trend</button>
      </form>
    </div>

    <!-- Edit Trend Form (if editing) -->
    <?php if ($editing && isset($trendToEdit)): ?>
    <div class="form-card">
      <h3><i class="fa-solid fa-pen-to-square"></i> Edit Trend</h3>
      <form action="" method="POST" class="form-grid">
        <div class="form-group">
          <label>Hashtag</label>
          <input type="text" name="hashtag" value="<?php echo htmlspecialchars($trendToEdit['hashtag']); ?>" required>
        </div>
        <div class="form-group">
          <label>Type</label>
          <select name="type">
            <option value="General" <?php echo $trendToEdit['type'] == 'General' ? 'selected' : ''; ?>>General</option>
            <option value="Trending" <?php echo $trendToEdit['type'] == 'Trending' ? 'selected' : ''; ?>>Trending</option>
          </select>
        </div>
        <input type="hidden" name="edit_id" value="<?php echo $trendToEdit['id']; ?>">
        <button type="submit" name="update_trend" class="btn-submit"><i class="fa-solid fa-save"></i> Update Trend</button>
        <a href="trends.php" class="reset-btn" style="background: var(--gray); text-decoration: none;"><i class="fa-solid fa-times"></i> Cancel</a>
      </form>
    </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
      <div class="errors"><ul><?php foreach ($errors as $err) echo "<li>$err</li>"; ?></ul></div>
    <?php endif; ?>
    <?php if (!empty($success)): ?>
      <div class="success"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <!-- Search and Filter Bar -->
    <div class="search-filter-bar">
      <div class="search-group">
        <label><i class="fa-solid fa-search"></i> Search Hashtag</label>
        <form method="GET" style="display: flex; gap: 10px;">
          <input type="text" name="search" placeholder="Search hashtag..." value="<?php echo htmlspecialchars($search); ?>">
          <button type="submit" class="btn-submit" style="padding: 10px 20px;"><i class="fa-solid fa-magnifying-glass"></i></button>
        </form>
      </div>
      <div class="filter-group">
        <label><i class="fa-solid fa-filter"></i> Filter by Type</label>
        <form method="GET" style="display: flex; gap: 10px;">
          <select name="type" onchange="this.form.submit()">
            <option value="">All Types</option>
            <option value="General" <?php echo $typeFilter == 'General' ? 'selected' : ''; ?>>General</option>
            <option value="Trending" <?php echo $typeFilter == 'Trending' ? 'selected' : ''; ?>>Trending</option>
          </select>
          <?php if (!empty($search)): ?>
            <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
          <?php endif; ?>
        </form>
      </div>
      <div style="display: flex; gap: 10px;">
        <a href="trends.php" class="reset-btn"><i class="fa-solid fa-undo"></i> Reset</a>
        <a href="trends.php" class="refresh-btn"><i class="fa-solid fa-rotate-right"></i> Refresh Data</a>
      </div>
    </div>

    <!-- Trends Table -->
    <form method="POST" id="bulkForm">
      <table>
        <thead>
          <tr>
            <th class="checkbox-col"><input type="checkbox" id="selectAll" onclick="toggleAll(this)"></th>
            <th><a href="?sort=id&order=<?php echo $sortOrder === 'DESC' ? 'asc' : 'desc'; ?><?php echo !empty($search) ? '&search='.urlencode($search) : ''; ?><?php echo !empty($typeFilter) ? '&type='.urlencode($typeFilter) : ''; ?>" class="sort-link">ID <i class="fa-solid fa-sort"></i></a></th>
            <th><a href="?sort=hashtag&order=<?php echo $sortOrder === 'DESC' ? 'asc' : 'desc'; ?><?php echo !empty($search) ? '&search='.urlencode($search) : ''; ?><?php echo !empty($typeFilter) ? '&type='.urlencode($typeFilter) : ''; ?>" class="sort-link">Hashtag <i class="fa-solid fa-sort"></i></a></th>
            <th><a href="?sort=count&order=<?php echo $sortOrder === 'DESC' ? 'asc' : 'desc'; ?><?php echo !empty($search) ? '&search='.urlencode($search) : ''; ?><?php echo !empty($typeFilter) ? '&type='.urlencode($typeFilter) : ''; ?>" class="sort-link">Total Count <i class="fa-solid fa-sort"></i></a></th>
            <th><a href="?sort=recent_count&order=<?php echo $sortOrder === 'DESC' ? 'asc' : 'desc'; ?><?php echo !empty($search) ? '&search='.urlencode($search) : ''; ?><?php echo !empty($typeFilter) ? '&type='.urlencode($typeFilter) : ''; ?>" class="sort-link">Last 24h <i class="fa-solid fa-sort"></i></a></th>
            <th>Type</th>
            <th><a href="?sort=created_on&order=<?php echo $sortOrder === 'DESC' ? 'asc' : 'desc'; ?><?php echo !empty($search) ? '&search='.urlencode($search) : ''; ?><?php echo !empty($typeFilter) ? '&type='.urlencode($typeFilter) : ''; ?>" class="sort-link">Created <i class="fa-solid fa-sort"></i></a></th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($trends)): ?>
            <?php foreach ($trends as $trend): 
              $isHot = $trend['calculated_recent'] > 5;
            ?>
              <tr>
                <td class="checkbox-col" data-label="Select"><input type="checkbox" name="selected_trends[]" value="<?php echo $trend['id']; ?>"></td>
                <td data-label="ID"><?php echo $trend['id']; ?></td>
                <td data-label="Hashtag"><strong><?php echo htmlspecialchars($trend['hashtag']); ?></strong></td>
                <td data-label="Total Count" class="trend-count"><?php echo number_format($trend['calculated_total']); ?></td>
                <td data-label="Last 24h" class="trend-recent <?php echo $isHot ? 'hot' : ''; ?>">
                  <?php 
                    $recentCount = $trend['calculated_recent'];
                    if ($recentCount == 0) {
                        echo '<span style="color: var(--gray);">0</span>';
                    } else {
                        echo '<span style="color: var(--success); font-weight: 600;">+' . number_format($recentCount) . '</span>';
                    }
                  ?>
                </td>
                <td data-label="Type"><span class="type-badge type-<?php echo htmlspecialchars($trend['type']); ?>"><?php echo htmlspecialchars($trend['type']); ?></span></td>
                <td data-label="Created"><?php echo date('M d, Y', strtotime($trend['created_on'])); ?></td>
                <td data-label="Actions">
                  <a href="trends.php?edit=<?php echo $trend['id']; ?>" class="action-btn edit-btn"><i class="fa-solid fa-pen"></i> Edit</a>
                  <a href="trends.php?delete=<?php echo $trend['id']; ?>" class="action-btn delete-btn" onclick="return confirm('Are you sure you want to delete this trend?');"><i class="fa-solid fa-trash"></i> Delete</a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr><td colspan="8" class="no-results"><i class="fa-solid fa-chart-line" style="font-size: 40px; opacity: 0.5;"></i><br><br>No trends found. Add your first trend above!</i></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
      
      <?php if (!empty($trends)): ?>
      <div class="bulk-actions">
        <button type="submit" name="bulk_delete" class="bulk-delete-btn" onclick="return confirm('Delete selected trends? This action cannot be undone.');">
          <i class="fa-solid fa-trash"></i> Delete Selected
        </button>
      </div>
      <?php endif; ?>
    </form>
  </main>

  <script>
    // Select All checkbox functionality
    function toggleAll(source) {
      const checkboxes = document.querySelectorAll('input[name="selected_trends[]"]');
      checkboxes.forEach(checkbox => checkbox.checked = source.checked);
    }

    // Dark Mode Toggle
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