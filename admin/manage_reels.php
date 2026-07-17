<?php
require_once '../core/init.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: index.php');
    exit;
}

$pdo = Connect::connect();

// Handle Delete Reel
if (isset($_GET['delete'])) {
    $reel_id = (int)$_GET['delete'];
    
    // Get reel details before deleting
    $stmt = $pdo->prepare("SELECT user_id, media_path, media_type FROM reels WHERE id = ?");
    $stmt->execute([$reel_id]);
    $reel = $stmt->fetch(PDO::FETCH_OBJ);
    
    if ($reel) {
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
            $mediaPath = "../assets/reels/" . $reel->media_path;
            if (file_exists($mediaPath)) {
                unlink($mediaPath);
            }
            
            // Delete the reel
            $stmt = $pdo->prepare("DELETE FROM reels WHERE id = ?");
            $stmt->execute([$reel_id]);
            
            $_SESSION['success'] = "Reel deleted successfully!";
        } catch (Exception $e) {
            $_SESSION['error'] = "Failed to delete reel: " . $e->getMessage();
        }
    } else {
        $_SESSION['error'] = "Reel not found";
    }
    
    header("Location: manage_reels.php");
    exit;
}

// Handle Bulk Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_delete'])) {
    $reel_ids = $_POST['reel_ids'] ?? [];
    
    if (!empty($reel_ids)) {
        $deleted_count = 0;
        foreach ($reel_ids as $reel_id) {
            $reel_id = (int)$reel_id;
            
            // Get reel details
            $stmt = $pdo->prepare("SELECT media_path FROM reels WHERE id = ?");
            $stmt->execute([$reel_id]);
            $reel = $stmt->fetch(PDO::FETCH_OBJ);
            
            if ($reel) {
                // Delete media file
                $mediaPath = "../assets/reels/" . $reel->media_path;
                if (file_exists($mediaPath)) {
                    unlink($mediaPath);
                }
                
                // Delete from database
                $stmt = $pdo->prepare("DELETE FROM reels WHERE id = ?");
                $stmt->execute([$reel_id]);
                $deleted_count++;
            }
        }
        $_SESSION['success'] = "$deleted_count reels deleted successfully!";
    } else {
        $_SESSION['error'] = "No reels selected for deletion";
    }
    
    header("Location: manage_reels.php");
    exit;
}

// Handle Filter/Search
$filter_type = isset($_GET['type']) ? $_GET['type'] : 'all';
$filter_sort = isset($_GET['sort']) ? $_GET['sort'] : 'latest';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Build query
$sql = "
    SELECT r.*, 
           u.username, u.name, u.img as user_img,
           (SELECT COUNT(*) FROM reel_likes WHERE reel_id = r.id) as likes_count,
           (SELECT COUNT(*) FROM reels_comments WHERE reel_id = r.id) as comments_count,
           (SELECT COUNT(*) FROM reels_saves WHERE reel_id = r.id) as saves_count,
           r.views_count,
           m.title as music_title
    FROM reels r
    JOIN users u ON u.id = r.user_id
    LEFT JOIN music_library m ON m.id = r.music_id
    WHERE 1=1
";

$params = [];

if ($filter_type === 'video') {
    $sql .= " AND r.media_type = 'video'";
} elseif ($filter_type === 'image') {
    $sql .= " AND r.media_type = 'image'";
}

if (!empty($search)) {
    $sql .= " AND (r.caption LIKE ? OR u.username LIKE ? OR u.name LIKE ?)";
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

if ($filter_sort === 'latest') {
    $sql .= " ORDER BY r.created_at DESC";
} elseif ($filter_sort === 'oldest') {
    $sql .= " ORDER BY r.created_at ASC";
} elseif ($filter_sort === 'most_liked') {
    $sql .= " ORDER BY likes_count DESC";
} elseif ($filter_sort === 'most_viewed') {
    $sql .= " ORDER BY r.views_count DESC";
} elseif ($filter_sort === 'most_commented') {
    $sql .= " ORDER BY comments_count DESC";
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$reels = $stmt->fetchAll(PDO::FETCH_OBJ);

// Get statistics
$stmt = $pdo->query("SELECT COUNT(*) as total, 
    SUM(CASE WHEN media_type = 'video' THEN 1 ELSE 0 END) as videos,
    SUM(CASE WHEN media_type = 'image' THEN 1 ELSE 0 END) as images,
    SUM(views_count) as total_views,
    SUM(likes_count) as total_likes,
    SUM(comments_count) as total_comments,
    SUM(saves_count) as total_saves
    FROM reels");
$stats = $stmt->fetch(PDO::FETCH_OBJ);
?>

<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Manage Reels · Admin Panel</title>
<link rel="icon" href="../assets/images/twitter-logo.png" type="image/png">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root { --primary: #1DA1F2; --secondary: #14171A; --light: #f5f8fa; --white: #fff; --gray: #657786; --danger: #e0245e; --success: #17bf63; --warning: #f5a623; --info: #17a2b8; }
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
.stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 20px; margin-bottom: 30px; }
.stat-card { background: var(--white); border-radius: 15px; padding: 20px; display: flex; align-items: center; gap: 15px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
.stat-icon { width: 50px; height: 50px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 24px; }
.stat-icon.blue { background: rgba(29, 161, 242, 0.1); color: var(--primary); }
.stat-icon.green { background: rgba(23, 191, 99, 0.1); color: var(--success); }
.stat-icon.red { background: rgba(224, 36, 94, 0.1); color: var(--danger); }
.stat-icon.orange { background: rgba(245, 166, 35, 0.1); color: var(--warning); }
.stat-info h3 { font-size: 28px; font-weight: 700; }
.stat-info p { font-size: 13px; color: var(--gray); }

/* Filter Bar */
.filter-bar { background: var(--white); border-radius: 12px; padding: 15px 20px; margin-bottom: 20px; display: flex; flex-wrap: wrap; gap: 15px; align-items: center; justify-content: space-between; }
.filter-group { display: flex; gap: 10px; flex-wrap: wrap; }
.filter-select, .search-input { padding: 8px 15px; border: 1px solid #ddd; border-radius: 25px; background: var(--white); color: var(--secondary); }
.search-input { min-width: 200px; }
.btn-primary { background: var(--primary); color: white; border: none; padding: 8px 20px; border-radius: 25px; cursor: pointer; font-weight: 600; transition: 0.3s; }
.btn-primary:hover { opacity: 0.9; }
.btn-danger { background: var(--danger); color: white; border: none; padding: 8px 20px; border-radius: 25px; cursor: pointer; font-weight: 600; }
.btn-warning { background: var(--warning); color: white; border: none; padding: 8px 20px; border-radius: 25px; cursor: pointer; font-weight: 600; }
.btn-sm { padding: 4px 10px; font-size: 12px; border-radius: 5px; }

/* Table */
table { width: 100%; border-collapse: collapse; background: var(--white); border-radius: 10px; overflow: hidden; box-shadow: 0 4px 10px rgba(0,0,0,0.05); }
table th, table td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #eee; font-size: 14px; }
table th { background: var(--primary); color: #fff; font-weight: 600; }
table td img.media-preview { width: 60px; height: 100px; object-fit: cover; border-radius: 8px; }
table td video.media-preview { width: 60px; height: 100px; object-fit: cover; border-radius: 8px; }
.user-cell { display: flex; align-items: center; gap: 10px; }
.user-avatar { width: 35px; height: 35px; border-radius: 50%; object-fit: cover; }
.stats-cell { display: flex; gap: 10px; flex-wrap: wrap; }
.stats-cell span { display: flex; align-items: center; gap: 4px; }
.stats-cell i { font-size: 12px; }
.actions { display: flex; gap: 8px; }
.actions a { text-decoration: none; }
.alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
.alert-success { background: rgba(23, 191, 99, 0.2); border: 1px solid var(--success); color: var(--success); }
.alert-error { background: rgba(224, 36, 94, 0.2); border: 1px solid var(--danger); color: var(--danger); }
.badge { display: inline-block; padding: 2px 8px; border-radius: 20px; font-size: 11px; font-weight: 600; }
.badge-video { background: rgba(29, 161, 242, 0.1); color: var(--primary); }
.badge-image { background: rgba(23, 191, 99, 0.1); color: var(--success); }
.checkbox { width: 18px; height: 18px; cursor: pointer; }

@media (max-width: 768px) {
    .main-content { margin-left: 0; padding: 15px; }
    .sidebar { transform: translateX(-100%); transition: transform 0.3s; z-index: 100; }
    .sidebar.open { transform: translateX(0); }
    table, table tbody, table tr, table td { display: block; width: 100%; }
    table thead { display: none; }
    table td { padding: 10px; border-bottom: 1px solid #eee; }
    .stats-grid { grid-template-columns: repeat(2, 1fr); }
}
</style>
</head>
<body>
<aside class="sidebar" id="sidebar">
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
      <a href="trends.php"><i class="fa-solid fa-chart-line"></i> Manage Trends</a>
      <a href="replies.php"><i class="fa-solid fa-reply"></i> Manage Replies</a>
      <a href="quotes.php"><i class="fa-solid fa-retweet"></i> Manage Quotes/Retweets</a>
      <a href="bookmarks.php"><i class="fa-solid fa-bookmark"></i> Manage Bookmarks</a>
      <a href="user_analytics.php"><i class="fa-solid fa-chart-simple"></i> User Analytics</a>
      <a href="manage_music.php"><i class="fa-solid fa-music"></i> Music Library</a>
      <a href="manage_reels.php" class="active"><i class="fa-solid fa-film"></i> Manage Reels</a>
    </nav>
  </div>
  <div class="sidebar-footer">
    <a href="logout.php" class="logout-btn"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
  </div>
</aside>

<main class="main-content">
  <div class="topbar">
    <div>
      <button id="menuToggle" class="btn-primary" style="display: none; margin-right: 10px;"><i class="fa-solid fa-bars"></i></button>
      <h1><i class="fa-solid fa-film"></i> Manage Reels</h1>
      <p style="color: var(--gray); margin-top: 5px;">View and manage all user-generated reels</p>
    </div>
    <div>
      <button id="themeToggle" class="theme-toggle"><i class="fa-solid fa-moon"></i></button>
    </div>
  </div>

  <?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success">
      <i class="fa-solid fa-check-circle"></i> <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
    </div>
  <?php endif; ?>
  
  <?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-error">
      <i class="fa-solid fa-exclamation-circle"></i> <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
    </div>
  <?php endif; ?>

  <!-- Statistics Cards -->
  <div class="stats-grid">
    <div class="stat-card">
      <div class="stat-icon blue"><i class="fa-solid fa-film"></i></div>
      <div class="stat-info">
        <h3><?php echo number_format($stats->total ?? 0); ?></h3>
        <p>Total Reels</p>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-icon blue"><i class="fa-solid fa-video"></i></div>
      <div class="stat-info">
        <h3><?php echo number_format($stats->videos ?? 0); ?></h3>
        <p>Videos</p>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-icon green"><i class="fa-solid fa-image"></i></div>
      <div class="stat-info">
        <h3><?php echo number_format($stats->images ?? 0); ?></h3>
        <p>Images</p>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-icon red"><i class="fa-solid fa-heart"></i></div>
      <div class="stat-info">
        <h3><?php echo number_format($stats->total_likes ?? 0); ?></h3>
        <p>Total Likes</p>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-icon orange"><i class="fa-solid fa-eye"></i></div>
      <div class="stat-info">
        <h3><?php echo number_format($stats->total_views ?? 0); ?></h3>
        <p>Total Views</p>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-icon blue"><i class="fa-solid fa-bookmark"></i></div>
      <div class="stat-info">
        <h3><?php echo number_format($stats->total_saves ?? 0); ?></h3>
        <p>Total Saves</p>
      </div>
    </div>
  </div>

  <!-- Filter Bar -->
  <div class="filter-bar">
    <div class="filter-group">
      <form method="GET" action="" style="display: flex; gap: 10px; flex-wrap: wrap;">
        <select name="type" class="filter-select" onchange="this.form.submit()">
          <option value="all" <?php echo $filter_type == 'all' ? 'selected' : ''; ?>>All Types</option>
          <option value="video" <?php echo $filter_type == 'video' ? 'selected' : ''; ?>>Videos</option>
          <option value="image" <?php echo $filter_type == 'image' ? 'selected' : ''; ?>>Images</option>
        </select>
        
        <select name="sort" class="filter-select" onchange="this.form.submit()">
          <option value="latest" <?php echo $filter_sort == 'latest' ? 'selected' : ''; ?>>Latest First</option>
          <option value="oldest" <?php echo $filter_sort == 'oldest' ? 'selected' : ''; ?>>Oldest First</option>
          <option value="most_liked" <?php echo $filter_sort == 'most_liked' ? 'selected' : ''; ?>>Most Liked</option>
          <option value="most_viewed" <?php echo $filter_sort == 'most_viewed' ? 'selected' : ''; ?>>Most Viewed</option>
          <option value="most_commented" <?php echo $filter_sort == 'most_commented' ? 'selected' : ''; ?>>Most Commented</option>
        </select>
        
        <input type="text" name="search" class="search-input" placeholder="Search by caption or user..." value="<?php echo htmlspecialchars($search); ?>">
        <button type="submit" class="btn-primary"><i class="fa-solid fa-search"></i> Search</button>
        <?php if (!empty($search) || $filter_type != 'all'): ?>
          <a href="manage_reels.php" class="btn-warning"><i class="fa-solid fa-times"></i> Clear</a>
        <?php endif; ?>
      </form>
    </div>
    
    <!-- Bulk Delete Form - Separate from table -->
    <form method="POST" action="" id="bulkDeleteForm" onsubmit="return confirmBulkDelete()">
      <button type="submit" name="bulk_delete" class="btn-danger"><i class="fa-solid fa-trash"></i> Delete Selected</button>
    </form>
  </div>

  <!-- Table Form -->
  <form method="POST" action="" id="tableForm">
    <input type="hidden" name="bulk_delete" value="1">
    <table>
      <thead>
        <tr>
          <th><input type="checkbox" id="selectAll" class="checkbox"></th>
          <th>ID</th>
          <th>Media</th>
          <th>Caption</th>
          <th>User</th>
          <th>Type</th>
          <th>Stats</th>
          <th>Music</th>
          <th>Created</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($reels): ?>
          <?php foreach ($reels as $reel): ?>
            <tr>
              <td><input type="checkbox" name="reel_ids[]" value="<?php echo $reel->id; ?>" class="checkbox reel-checkbox"></td>
              <td><?php echo $reel->id; ?></td>
              <td>
                <?php if ($reel->media_type === 'video'): ?>
                  <video class="media-preview" muted>
                    <source src="../assets/reels/<?php echo $reel->media_path; ?>" type="video/mp4">
                  </video>
                <?php else: ?>
                  <img class="media-preview" src="../assets/reels/<?php echo $reel->media_path; ?>" alt="reel">
                <?php endif; ?>
              </td>
              <td style="max-width: 200px;">
                <?php echo htmlspecialchars(substr($reel->caption ?? '', 0, 50)); ?>
                <?php if (strlen($reel->caption ?? '') > 50) echo '...'; ?>
              </td>
              <td>
                <div class="user-cell">
                  <img class="user-avatar" src="../assets/images/users/<?php echo $reel->user_img; ?>" alt="">
                  <div>
                    <strong><?php echo htmlspecialchars($reel->name); ?></strong><br>
                    <small>@<?php echo htmlspecialchars($reel->username); ?></small>
                  </div>
                </div>
              </td>
              <td>
                <span class="badge <?php echo $reel->media_type === 'video' ? 'badge-video' : 'badge-image'; ?>">
                  <i class="fa-solid fa-<?php echo $reel->media_type === 'video' ? 'video' : 'image'; ?>"></i>
                  <?php echo ucfirst($reel->media_type); ?>
                </span>
              </td>
              <td class="stats-cell">
                <span title="Likes"><i class="fa-solid fa-heart" style="color: #e0245e;"></i> <?php echo number_format($reel->likes_count); ?></span>
                <span title="Comments"><i class="fa-solid fa-comment" style="color: #1da1f2;"></i> <?php echo number_format($reel->comments_count); ?></span>
                <span title="Saves"><i class="fa-solid fa-bookmark" style="color: #f9a825;"></i> <?php echo number_format($reel->saves_count); ?></span>
                <span title="Views"><i class="fa-solid fa-eye"></i> <?php echo number_format($reel->views_count); ?></span>
              </td>
              <td>
                <?php if ($reel->music_title): ?>
                  <i class="fa-solid fa-music" style="color: #1da1f2;"></i> <?php echo htmlspecialchars(substr($reel->music_title, 0, 20)); ?>
                <?php else: ?>
                  <span style="color: var(--gray);">No music</span>
                <?php endif; ?>
              </td>
              <td><?php echo date('M d, Y', strtotime($reel->created_at)); ?></td>
              <td class="actions">
                <a href="../reels.php?reel=<?php echo $reel->id; ?>&user=<?php echo $reel->user_id; ?>&tab=reels" target="_blank">
                  <button type="button" class="btn-primary btn-sm"><i class="fa-solid fa-eye"></i> View</button>
                </a>
                <a href="manage_reels.php?delete=<?php echo $reel->id; ?>" onclick="return confirm('Are you sure you want to delete this reel? This will remove all likes, comments, saves, and the media file.');">
                  <button type="button" class="btn-danger btn-sm"><i class="fa-solid fa-trash"></i> Delete</button>
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr>
            <td colspan="10" style="text-align: center; padding: 40px;">
              <i class="fa-solid fa-film" style="font-size: 48px; opacity: 0.5;"></i>
              <h4 style="margin-top: 10px;">No Reels Found</h4>
              <p style="color: var(--gray);">Try adjusting your filters or search criteria</p>
            </td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </form>
</main>

<script>
// Theme toggle
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

// Mobile sidebar toggle
const menuToggle = document.getElementById('menuToggle');
const sidebar = document.getElementById('sidebar');
if (window.innerWidth <= 768) {
  menuToggle.style.display = 'inline-block';
}
menuToggle.addEventListener('click', () => {
  sidebar.classList.toggle('open');
});

// Close sidebar when clicking outside on mobile
document.addEventListener('click', (e) => {
  if (window.innerWidth <= 768 && sidebar.classList.contains('open')) {
    if (!sidebar.contains(e.target) && !menuToggle.contains(e.target)) {
      sidebar.classList.remove('open');
    }
  }
});

// Select All functionality
const selectAll = document.getElementById('selectAll');
const checkboxes = document.querySelectorAll('.reel-checkbox');

if (selectAll) {
  selectAll.addEventListener('click', () => {
    checkboxes.forEach(checkbox => {
      checkbox.checked = selectAll.checked;
    });
  });
}

// Confirm bulk delete
function confirmBulkDelete() {
  const selected = document.querySelectorAll('.reel-checkbox:checked');
  if (selected.length === 0) {
    alert('Please select at least one reel to delete');
    return false;
  }
  return confirm(`Are you sure you want to delete ${selected.length} reel(s)? This action cannot be undone.`);
}

// Video hover preview
document.querySelectorAll('.media-preview').forEach(video => {
  if (video.tagName === 'VIDEO') {
    video.addEventListener('mouseenter', () => {
      video.play().catch(() => {});
    });
    video.addEventListener('mouseleave', () => {
      video.pause();
      video.currentTime = 0;
    });
  }
});
</script>
</body>
</html>