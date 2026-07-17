<?php
require_once '../core/init.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: index.php');
    exit;
}

$pdo = Connect::connect();

// Fetch stats
$counts = [];
$counts['users']     = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$counts['tweets']    = (int)$pdo->query("SELECT COUNT(*) FROM posts")->fetchColumn();
$counts['comments']  = (int)$pdo->query("SELECT COUNT(*) FROM comments")->fetchColumn();
$counts['trends']    = (int)$pdo->query("SELECT COUNT(*) FROM trends")->fetchColumn();
$counts['replies']   = (int)$pdo->query("SELECT COUNT(*) FROM replies")->fetchColumn();
$counts['retweets']  = (int)$pdo->query("SELECT COUNT(*) FROM retweets")->fetchColumn();
$counts['bookmarks'] = (int)$pdo->query("SELECT COUNT(*) FROM bookmarks")->fetchColumn();
$counts['reels']     = (int)$pdo->query("SELECT COUNT(*) FROM reels")->fetchColumn();

// Get verified users
$verifiedStmt = $pdo->query("SELECT COUNT(*) as count FROM users WHERE is_verified = 1");
$verifiedUsers = $verifiedStmt->fetch(PDO::FETCH_ASSOC);

// Get activity this week
$weekActivityStmt = $pdo->query("SELECT COUNT(*) as count FROM posts WHERE post_on >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
$weekActivity = $weekActivityStmt->fetch(PDO::FETCH_ASSOC);

// Get daily activity for last 7 days
$dailyStmt = $pdo->prepare("
    SELECT 
        DATE(post_on) as date, 
        COUNT(*) as count 
    FROM posts 
    WHERE post_on >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY DATE(post_on)
    ORDER BY date ASC
");
$dailyStmt->execute();
$dailyActivity = $dailyStmt->fetchAll(PDO::FETCH_ASSOC);

// Fill missing dates
$last7Days = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $last7Days[$date] = 0;
}
foreach ($dailyActivity as $day) {
    $last7Days[$day['date']] = $day['count'];
}

// Fetch top hashtags (last 24 hours) - KEEPING ORIGINAL CORRECT CALCULATION
$trendingStmt = $pdo->prepare("
    SELECT 
        hashtag,
        SUM(recent_count) AS recent_usage,
        SUM(count) AS total_usage
    FROM trends
    WHERE created_on >= NOW() - INTERVAL 1 DAY
      AND recent_count > 0
    GROUP BY hashtag
    ORDER BY recent_usage DESC
    LIMIT 7
");
$trendingStmt->execute();
$trendingHashtags = $trendingStmt->fetchAll(PDO::FETCH_ASSOC);

// Get top users (most posts)
$topUsersStmt = $pdo->query("
    SELECT u.username, u.name, u.img, u.is_verified, COUNT(p.id) as post_count 
    FROM users u 
    LEFT JOIN posts p ON u.id = p.user_id 
    GROUP BY u.id 
    ORDER BY post_count DESC 
    LIMIT 5
");
$topUsers = $topUsersStmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent comments
$recentCommentsStmt = $pdo->query("
    SELECT c.comment, c.time, u.username, u.name, u.img
    FROM comments c 
    JOIN users u ON c.user_id = u.id 
    ORDER BY c.time DESC 
    LIMIT 5
");
$recentComments = $recentCommentsStmt->fetchAll(PDO::FETCH_ASSOC);

// Get engagement stats
$engagementStmt = $pdo->query("
    SELECT 
        (SELECT COUNT(*) FROM likes) as total_likes,
        (SELECT COUNT(*) FROM comments) as total_comments,
        (SELECT COUNT(*) FROM retweets) as total_retweets
");
$engagement = $engagementStmt->fetch(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Admin Dashboard · TwitterClone</title>
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
      --warning: #ffad1f;
      --purple: #794bc4;
      --pink: #e1306c;
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
    .sidebar { width: 250px; background: var(--secondary); color: var(--white); display: flex; flex-direction: column; justify-content: space-between; padding: 20px 0; position: fixed; left: 0; top: 0; bottom: 0; z-index: 100; }
    .sidebar-header { text-align: center; margin-bottom: 20px; }
    .sidebar-logo { width: 60px; margin-bottom: 10px; }
    .sidebar-nav a { color: var(--white); text-decoration: none; padding: 14px 25px; display: flex; align-items: center; gap: 10px; font-size: 15px; transition: all 0.3s; }
    .sidebar-nav a:hover, .sidebar-nav a.active { background: var(--primary); border-radius: 0 20px 20px 0; }
    .sidebar-footer { text-align: center; padding: 15px; }
    .logout-btn { color: var(--white); text-decoration: none; background: var(--danger); padding: 10px 20px; border-radius: 25px; transition: 0.3s; display: inline-block; }
    .logout-btn:hover { background: #ff4d6d; transform: translateY(-2px); }

    /* Main */
    .main-content { margin-left: 250px; flex: 1; padding: 30px; }
    .topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; flex-wrap: wrap; gap: 15px; }
    .theme-toggle { background: none; border: none; color: var(--primary); font-size: 22px; cursor: pointer; padding: 8px; border-radius: 50%; transition: 0.2s; }
    .theme-toggle:hover { background: rgba(29,161,242,0.1); }
    .welcome-text { font-size: 14px; color: var(--gray); }

    /* Stats Cards */
    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 20px;
      margin-bottom: 30px;
    }
    .stat-card {
      background: var(--white);
      border-radius: 16px;
      padding: 20px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.08);
      transition: all 0.3s;
      position: relative;
      overflow: hidden;
    }
    .stat-card:hover { transform: translateY(-3px); box-shadow: 0 8px 25px rgba(0,0,0,0.1); }
    .stat-card .stat-icon { font-size: 32px; margin-bottom: 12px; }
    .stat-card .stat-value { font-size: 28px; font-weight: 700; margin-bottom: 4px; }
    .stat-card .stat-label { font-size: 13px; color: var(--gray); }
    .stat-card .stat-trend { font-size: 11px; margin-top: 8px; padding-top: 8px; border-top: 1px solid rgba(0,0,0,0.1); }
    .stat-trend.up { color: var(--success); }

    /* Icon colors */
    .stat-icon.users { color: var(--primary); }
    .stat-icon.verified { color: var(--success); }
    .stat-icon.tweets { color: var(--success); }
    .stat-icon.comments { color: var(--warning); }
    .stat-icon.replies { color: var(--purple); }
    .stat-icon.retweets { color: #17bf63; }
    .stat-icon.trends { color: #ff6b6b; }
    .stat-icon.reels { color: var(--pink); }
    .stat-icon.bookmarks { color: #f7ca18; }

    /* Charts Grid */
    .charts-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(450px, 1fr));
      gap: 25px;
      margin-bottom: 30px;
    }
    .chart-card {
      background: var(--white);
      border-radius: 16px;
      padding: 20px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    }
    .chart-card h3 {
      font-size: 16px;
      margin-bottom: 20px;
      color: var(--primary);
      display: flex;
      align-items: center;
      gap: 8px;
    }
    canvas { max-width: 100%; max-height: 300px; }

    /* Trending Hashtags */
    .trend-card {
      background: var(--white);
      border-radius: 16px;
      padding: 20px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.08);
      margin-bottom: 30px;
    }
    .trend-card h3 {
      font-size: 16px;
      margin-bottom: 15px;
      color: var(--primary);
      display: flex;
      align-items: center;
      gap: 8px;
      padding-bottom: 10px;
      border-bottom: 2px solid rgba(29,161,242,0.2);
    }
    .trend-list { list-style: none; }
    .trend-list li {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 12px 0;
      border-bottom: 1px solid rgba(0,0,0,0.05);
    }
    .trend-list li:last-child { border-bottom: none; }
    .trend-rank {
      font-weight: 700;
      font-size: 18px;
      color: var(--primary);
      width: 40px;
    }
    .trend-info { flex: 1; }
    .trend-name { font-weight: 600; font-size: 15px; }
    .trend-count { font-weight: 700; font-size: 16px; color: var(--primary); }
    .empty-trends { text-align: center; padding: 30px; color: var(--gray); }

    /* Activity Lists */
    .activity-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
      gap: 25px;
      margin-bottom: 30px;
    }
    .activity-card {
      background: var(--white);
      border-radius: 16px;
      padding: 20px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    }
    .activity-card h3 {
      font-size: 16px;
      margin-bottom: 15px;
      color: var(--primary);
      display: flex;
      align-items: center;
      gap: 8px;
      padding-bottom: 10px;
      border-bottom: 2px solid rgba(29,161,242,0.2);
    }
    .activity-list { list-style: none; }
    .activity-list li {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 12px 0;
      border-bottom: 1px solid rgba(0,0,0,0.05);
    }
    .activity-list li:last-child { border-bottom: none; }
    .user-avatar {
      width: 40px;
      height: 40px;
      border-radius: 50%;
      object-fit: cover;
      background: var(--light);
    }
    .user-info { flex: 1; }
    .user-name { font-weight: 600; font-size: 14px; display: flex; align-items: center; gap: 4px; }
    .verified-badge { color: var(--primary); font-size: 12px; }
    .user-username { font-size: 11px; color: var(--gray); }
    .activity-preview { font-size: 12px; color: var(--gray); margin-top: 4px; }
    .count-badge {
      background: var(--primary);
      color: white;
      padding: 4px 10px;
      border-radius: 20px;
      font-size: 12px;
      font-weight: 600;
    }

    @media (max-width: 768px) {
      .main-content { margin-left: 0; padding: 15px; }
      .sidebar { display: none; }
      .stats-grid { grid-template-columns: repeat(2, 1fr); }
      .charts-grid { grid-template-columns: 1fr; }
      .activity-grid { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>

  <!-- Sidebar -->
  <aside class="sidebar">
    <div>
      <div class="sidebar-header">
        <img src="../assets/images/twitter-logo.png" alt="logo" class="sidebar-logo">
        <h2>Admin Panel</h2>
      </div>
      <nav class="sidebar-nav">
        <a href="dashboard.php" class="active"><i class="fa-solid fa-house"></i> Dashboard</a>
        <a href="users.php"><i class="fa-solid fa-users"></i> Manage Users</a>
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

  <!-- Main Content -->
  <main class="main-content">
    <div class="topbar">
      <div>
        <h1>Dashboard Overview</h1>
        <div class="welcome-text">Welcome back, Admin! Here's what's happening on your platform.</div>
      </div>
      <div style="display: flex; align-items: center; gap: 15px;">
        <button id="themeToggle" class="theme-toggle"><i class="fa-solid fa-moon"></i></button>
      </div>
    </div>

    <!-- Stats Cards -->
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-icon users"><i class="fa-solid fa-users"></i></div>
        <div class="stat-value"><?php echo number_format($counts['users']); ?></div>
        <div class="stat-label">Total Users</div>
        <div class="stat-trend up"><i class="fa-solid fa-arrow-up"></i> <?php echo $weekActivity['count']; ?> active this week</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon verified"><i class="fa-solid fa-check-circle"></i></div>
        <div class="stat-value"><?php echo number_format($verifiedUsers['count']); ?></div>
        <div class="stat-label">Verified Users</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon tweets"><i class="fa-brands fa-twitter"></i></div>
        <div class="stat-value"><?php echo number_format($counts['tweets']); ?></div>
        <div class="stat-label">Total Posts</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon comments"><i class="fa-solid fa-comments"></i></div>
        <div class="stat-value"><?php echo number_format($counts['comments']); ?></div>
        <div class="stat-label">Total Comments</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon replies"><i class="fa-solid fa-reply"></i></div>
        <div class="stat-value"><?php echo number_format($counts['replies']); ?></div>
        <div class="stat-label">Total Replies</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon retweets"><i class="fa-solid fa-retweet"></i></div>
        <div class="stat-value"><?php echo number_format($counts['retweets']); ?></div>
        <div class="stat-label">Total Retweets</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon bookmarks"><i class="fa-solid fa-bookmark"></i></div>
        <div class="stat-value"><?php echo number_format($counts['bookmarks']); ?></div>
        <div class="stat-label">Total Bookmarks</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon reels"><i class="fa-solid fa-film"></i></div>
        <div class="stat-value"><?php echo number_format($counts['reels']); ?></div>
        <div class="stat-label">Total Reels</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon trends"><i class="fa-solid fa-chart-line"></i></div>
        <div class="stat-value"><?php echo number_format($counts['trends']); ?></div>
        <div class="stat-label">Total Trends</div>
      </div>
    </div>

    <!-- Charts Row -->
    <div class="charts-grid">
      <div class="chart-card">
        <h3><i class="fa-solid fa-chart-line"></i> Activity - Last 7 Days</h3>
        <canvas id="activityChart"></canvas>
      </div>
      <div class="chart-card">
        <h3><i class="fa-solid fa-chart-pie"></i> Engagement Breakdown</h3>
        <canvas id="engagementChart"></canvas>
      </div>
    </div>

    <!-- Trending Hashtags Section (using original correct calculation) -->
    <div class="trend-card">
      <h3><i class="fa-solid fa-fire"></i> 🔥 Top Trending Hashtags (Last 24h)</h3>
      <?php if (!empty($trendingHashtags)): ?>
        <ul class="trend-list">
          <?php $rank = 1; foreach ($trendingHashtags as $trend): ?>
            <li>
              <div class="trend-rank">#<?php echo $rank++; ?></div>
              <div class="trend-info">
                <div class="trend-name"><?php echo htmlspecialchars($trend['hashtag']); ?></div>
              </div>
              <div class="trend-count"><?php echo number_format($trend['recent_usage']); ?> uses</div>
            </li>
          <?php endforeach; ?>
        </ul>
        <canvas id="trendChart" style="margin-top: 20px; max-height: 250px;"></canvas>
      <?php else: ?>
        <div class="empty-trends">
          <i class="fa-solid fa-chart-line" style="font-size: 48px; opacity: 0.3;"></i>
          <p style="margin-top: 10px;">No trends found in the last 24 hours.</p>
          <p style="font-size: 12px;">When users post with hashtags, they will appear here.</p>
        </div>
      <?php endif; ?>
    </div>

    <!-- Recent Activity and Top Contributors -->
    <div class="activity-grid">
      <div class="activity-card">
        <h3><i class="fa-solid fa-trophy"></i> 🏆 Top Contributors</h3>
        <ul class="activity-list">
          <?php foreach ($topUsers as $user): ?>
            <li>
              <img src="../assets/images/users/<?php echo htmlspecialchars($user['img'] ?: 'default.jpg'); ?>" alt="avatar" class="user-avatar" onerror="this.src='../assets/images/users/default.jpg'">
              <div class="user-info">
                <div class="user-name">
                  <?php echo htmlspecialchars($user['name']); ?>
                  <?php if ($user['is_verified'] == 1): ?>
                    <i class="fas fa-check-circle verified-badge" title="Verified"></i>
                  <?php endif; ?>
                </div>
                <div class="user-username">@<?php echo htmlspecialchars($user['username']); ?></div>
              </div>
              <div class="count-badge"><?php echo $user['post_count']; ?> posts</div>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>

      <div class="activity-card">
        <h3><i class="fa-solid fa-clock"></i> 📝 Recent Comments</h3>
        <ul class="activity-list">
          <?php foreach ($recentComments as $comment): ?>
            <li>
              <img src="../assets/images/users/<?php echo htmlspecialchars($comment['img'] ?: 'default.jpg'); ?>" alt="avatar" class="user-avatar" onerror="this.src='../assets/images/users/default.jpg'">
              <div class="user-info">
                <div class="user-name"><?php echo htmlspecialchars($comment['name']); ?></div>
                <div class="user-username">@<?php echo htmlspecialchars($comment['username']); ?></div>
                <div class="activity-preview"><?php echo htmlspecialchars(substr($comment['comment'], 0, 60)); ?>...</div>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  </main>

  <script>
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
      updateChartColors();
    });

    // Daily Activity Data
    const dailyLabels = <?php 
      $labels = [];
      for ($i = 6; $i >= 0; $i--) {
        $labels[] = date('M d', strtotime("-$i days"));
      }
      echo json_encode($labels);
    ?>;
    const dailyData = <?php echo json_encode(array_values($last7Days)); ?>;

    // Engagement Data
    const engagementData = {
      likes: <?php echo $engagement['total_likes'] ?? 0; ?>,
      comments: <?php echo $engagement['total_comments'] ?? 0; ?>,
      retweets: <?php echo $engagement['total_retweets'] ?? 0; ?>
    };

    // Activity Chart
    const ctxActivity = document.getElementById('activityChart').getContext('2d');
    let activityChart = new Chart(ctxActivity, {
      type: 'line',
      data: {
        labels: dailyLabels,
        datasets: [{
          label: 'Posts',
          data: dailyData,
          borderColor: '#1DA1F2',
          backgroundColor: 'rgba(29, 161, 242, 0.1)',
          borderWidth: 2,
          fill: true,
          tension: 0.4,
          pointBackgroundColor: '#1DA1F2',
          pointBorderColor: '#fff',
          pointRadius: 4,
          pointHoverRadius: 6
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
          legend: { labels: { color: body.classList.contains('dark') ? '#fff' : '#14171A' } }
        },
        scales: {
          x: { ticks: { color: body.classList.contains('dark') ? '#fff' : '#14171A' }, grid: { color: body.classList.contains('dark') ? '#333' : '#eee' } },
          y: { beginAtZero: true, ticks: { color: body.classList.contains('dark') ? '#fff' : '#14171A' }, grid: { color: body.classList.contains('dark') ? '#333' : '#eee' } }
        }
      }
    });

    // Engagement Chart
    const ctxEngagement = document.getElementById('engagementChart').getContext('2d');
    let engagementChart = new Chart(ctxEngagement, {
      type: 'doughnut',
      data: {
        labels: ['Likes', 'Comments', 'Retweets'],
        datasets: [{
          data: [engagementData.likes, engagementData.comments, engagementData.retweets],
          backgroundColor: ['#e0245e', '#1DA1F2', '#17bf63'],
          borderWidth: 0,
          hoverOffset: 10
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
          legend: { labels: { color: body.classList.contains('dark') ? '#fff' : '#14171A' } }
        }
      }
    });

    // Trending Chart - Only create if there are trends
    <?php if (!empty($trendingHashtags)): ?>
    const trendLabels = <?php echo json_encode(array_column($trendingHashtags, 'hashtag')); ?>;
    const trendRecentData = <?php echo json_encode(array_column($trendingHashtags, 'recent_usage')); ?>;
    const trendTotalData = <?php echo json_encode(array_column($trendingHashtags, 'total_usage')); ?>;

    const ctxTrend = document.getElementById('trendChart').getContext('2d');
    let trendChart = new Chart(ctxTrend, {
      type: 'bar',
      data: {
        labels: trendLabels,
        datasets: [
          {
            label: 'Last 24h',
            data: trendRecentData,
            backgroundColor: 'rgba(29,161,242,0.8)',
            borderColor: '#1DA1F2',
            borderWidth: 1,
            borderRadius: 6
          },
          {
            label: 'Total',
            data: trendTotalData,
            backgroundColor: 'rgba(23,191,99,0.6)',
            borderColor: '#17BF63',
            borderWidth: 1,
            borderRadius: 6
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
          legend: { labels: { color: body.classList.contains('dark') ? '#fff' : '#14171A' } }
        },
        scales: {
          x: { ticks: { color: body.classList.contains('dark') ? '#fff' : '#14171A' }, grid: { color: body.classList.contains('dark') ? '#333' : '#eee' } },
          y: { beginAtZero: true, ticks: { color: body.classList.contains('dark') ? '#fff' : '#14171A' }, grid: { color: body.classList.contains('dark') ? '#333' : '#eee' } }
        }
      }
    });
    <?php endif; ?>

    function updateChartColors() {
      const isDark = body.classList.contains('dark');
      const textColor = isDark ? '#fff' : '#14171A';
      const gridColor = isDark ? '#333' : '#eee';
      
      activityChart.options.plugins.legend.labels.color = textColor;
      activityChart.options.scales.x.ticks.color = textColor;
      activityChart.options.scales.y.ticks.color = textColor;
      activityChart.options.scales.x.grid.color = gridColor;
      activityChart.options.scales.y.grid.color = gridColor;
      activityChart.update();
      
      engagementChart.options.plugins.legend.labels.color = textColor;
      engagementChart.update();
      
      <?php if (!empty($trendingHashtags)): ?>
      trendChart.options.plugins.legend.labels.color = textColor;
      trendChart.options.scales.x.ticks.color = textColor;
      trendChart.options.scales.y.ticks.color = textColor;
      trendChart.options.scales.x.grid.color = gridColor;
      trendChart.options.scales.y.grid.color = gridColor;
      trendChart.update();
      <?php endif; ?>
    }
  </script>
</body>
</html>