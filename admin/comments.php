<?php
require_once '../core/init.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: index.php');
    exit;
}

$pdo = Connect::connect();

// Fetch all users for suggestions
$usersStmt = $pdo->query("SELECT id, username, name FROM users ORDER BY username ASC");
$allUsers = $usersStmt->fetchAll(PDO::FETCH_ASSOC);

// Handle deletion
if (isset($_GET['delete_id'])) {
    $deleteId = (int)$_GET['delete_id'];
    $stmtDel = $pdo->prepare("DELETE FROM comments WHERE id = ?");
    $stmtDel->execute([$deleteId]);
    header('Location: comments.php');
    exit;
}

// Fetch all comments with user and tweet info
$stmt = $pdo->query("
    SELECT c.id, c.comment, c.time,
           u.id as user_id, u.username, u.name, u.img AS user_img,
           t.post_id as tweet_id, t.status AS tweet_text
    FROM comments c
    JOIN users u ON c.user_id = u.id
    JOIN tweets t ON c.post_id = t.post_id
    ORDER BY c.time DESC
");
$allComments = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Manage Comments · Admin Panel</title>
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

    /* Search Container */
    .search-container {
      display: flex;
      gap: 15px;
      margin-bottom: 25px;
      flex-wrap: wrap;
      max-width: 900px;
    }

    .search-box {
      flex: 1;
      min-width: 200px;
      position: relative;
    }

    .search-box label {
      display: block;
      font-size: 12px;
      font-weight: 600;
      margin-bottom: 5px;
      color: var(--primary);
    }

    .search-box label i {
      margin-right: 4px;
      font-size: 11px;
    }

    .search-input-wrapper {
      position: relative;
    }

    .search-box input {
      width: 100%;
      padding: 8px 14px;
      border-radius: 25px;
      border: 1px solid #ddd;
      font-size: 13px;
      background: var(--white);
      color: var(--secondary);
      transition: all 0.3s;
    }

    .search-box input:focus {
      outline: none;
      border-color: var(--primary);
      box-shadow: 0 0 0 2px rgba(29,161,242,0.1);
    }

    /* User Suggestions Dropdown */
    .suggestions-dropdown {
      position: absolute;
      top: 100%;
      left: 0;
      right: 0;
      background: var(--white);
      border-radius: 10px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.15);
      max-height: 200px;
      overflow-y: auto;
      z-index: 1000;
      display: none;
      margin-top: 4px;
    }

    .suggestions-dropdown.show {
      display: block;
    }

    .suggestion-item {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 8px 12px;
      cursor: pointer;
      transition: background 0.2s;
      border-bottom: 1px solid #eee;
    }

    .suggestion-item:last-child {
      border-bottom: none;
    }

    .suggestion-item:hover {
      background: rgba(29,161,242,0.1);
    }

    .suggestion-item .suggestion-info {
      flex: 1;
    }

    .suggestion-item .suggestion-name {
      font-weight: 600;
      font-size: 13px;
      color: var(--secondary);
    }

    .suggestion-item .suggestion-username {
      font-size: 11px;
      color: var(--gray);
    }

    /* Search Info */
    .search-info {
      font-size: 12px;
      color: var(--gray);
      margin-bottom: 15px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 10px;
      padding: 6px 12px;
      background: rgba(29,161,242,0.05);
      border-radius: 8px;
    }

    .search-info .clear-search {
      color: var(--danger);
      cursor: pointer;
      text-decoration: none;
      font-size: 12px;
    }

    .search-info .clear-search:hover {
      text-decoration: underline;
    }

    /* Table */
    table { width: 100%; border-collapse: collapse; background: var(--white); border-radius: 10px; overflow: hidden; box-shadow: 0 4px 10px rgba(0,0,0,0.05); }
    th, td { padding: 14px 18px; text-align: left; border-bottom: 1px solid #eee; font-size: 14px; vertical-align: top; }
    th { background: var(--primary); color: #fff; font-weight: 600; }
    tr:hover { background: rgba(29,161,242,0.05); }

    .user-info {
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .user-info img {
      width: 40px;
      height: 40px;
      border-radius: 50%;
      object-fit: cover;
    }

    .comment-content, .tweet-content {
      max-width: 350px;
      word-wrap: break-word;
      line-height: 1.4;
    }

    .view-link {
      display: inline-block;
      margin-top: 6px;
      font-size: 12px;
      color: var(--primary);
      text-decoration: none;
    }
    .view-link:hover {
      text-decoration: underline;
    }

    .delete-btn {
      color: #fff;
      background: var(--danger);
      padding: 6px 14px;
      border: none;
      border-radius: 20px;
      cursor: pointer;
      text-decoration: none;
      font-size: 12px;
      display: inline-block;
      transition: all 0.2s;
    }
    .delete-btn:hover {
      background: #c81e4d;
      transform: translateY(-1px);
    }

    .no-results td {
      text-align: center;
      padding: 40px;
    }

    /* Scrollbar */
    .suggestions-dropdown::-webkit-scrollbar { width: 5px; }
    .suggestions-dropdown::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 10px; }
    .suggestions-dropdown::-webkit-scrollbar-thumb { background: var(--primary); border-radius: 10px; }

    /* Responsive */
    @media (max-width: 768px) {
      .main-content { margin-left: 0; padding: 15px; }
      .sidebar { display: none; }
      .search-container { max-width: 100%; flex-direction: column; }
      .search-box { min-width: auto; }
      table, thead, tbody, th, td, tr { display: block; }
      thead { display: none; }
      tr { margin-bottom: 15px; border: 1px solid var(--gray); border-radius: 10px; background: var(--white); }
      td { display: flex; justify-content: space-between; align-items: center; padding: 10px; border-bottom: 1px solid #eee; }
      td:before { content: attr(data-label); font-weight: bold; width: 35%; font-size: 12px; }
      td:last-child { border-bottom: none; }
      .comment-content, .tweet-content { max-width: 55%; }
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
        <a href="comments.php" class="active"><i class="fa-solid fa-comments"></i> Manage Comments</a>
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

  <main class="main-content">
    <div class="topbar">
      <h1>Manage Comments</h1>
      <button id="themeToggle" class="theme-toggle"><i class="fa-solid fa-moon"></i></button>
    </div>

    <!-- Search Container -->
    <div class="search-container">
      <!-- Search by User with Suggestions -->
      <div class="search-box">
        <label><i class="fa-solid fa-user"></i> Search by User</label>
        <div class="search-input-wrapper">
          <input type="text" id="userSearchInput" placeholder="Type username or name..." autocomplete="off">
          <div id="userSuggestions" class="suggestions-dropdown"></div>
        </div>
      </div>

      <!-- Search by Comment Content -->
      <div class="search-box">
        <label><i class="fa-solid fa-magnifying-glass"></i> Search by Comment Content</label>
        <input type="text" id="contentSearchInput" placeholder="Search in comment text..." autocomplete="off">
      </div>
    </div>

    <!-- Search Info -->
    <div id="searchInfo" class="search-info" style="display: none;">
      <span><i class="fa-solid fa-search"></i> <span id="searchMessage"></span></span>
      <span id="resultCount"></span>
      <a href="#" id="clearSearchBtn" class="clear-search"><i class="fa-solid fa-times"></i> Clear</a>
    </div>

    <div id="commentsTableContainer">
      <table id="commentsTable">
        <thead>
          <tr>
            <th>ID</th>
            <th>User</th>
            <th>Comment</th>
            <th>Original Tweet</th>
            <th>Time</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody id="commentsTableBody">
          <!-- Will be populated by JavaScript -->
        </tbody>
      </table>
    </div>
  </main>

  <script>
    // All comments data from PHP (embedded for live search)
    const allComments = <?php echo json_encode($allComments); ?>;
    const allUsersList = <?php echo json_encode($allUsers); ?>;

    // DOM Elements
    const userSearchInput = document.getElementById('userSearchInput');
    const contentSearchInput = document.getElementById('contentSearchInput');
    const commentsTableBody = document.getElementById('commentsTableBody');
    const searchInfo = document.getElementById('searchInfo');
    const searchMessage = document.getElementById('searchMessage');
    const resultCountSpan = document.getElementById('resultCount');
    const clearSearchBtn = document.getElementById('clearSearchBtn');
    const userSuggestions = document.getElementById('userSuggestions');

    let currentUserFilter = null;
    let currentContentFilter = null;

    // Helper function to escape HTML
    function escapeHtml(str) {
      if (!str) return '';
      return str.replace(/[&<>]/g, function(m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        return m;
      });
    }

    // Format date
    function formatDate(dateString) {
      if (!dateString) return '—';
      const date = new Date(dateString);
      return date.toLocaleString();
    }

    // Render comments based on filters
    function renderComments() {
      let filteredComments = [...allComments];
      
      // Filter by user
      if (currentUserFilter) {
        const filterLower = currentUserFilter.toLowerCase();
        filteredComments = filteredComments.filter(comment => 
          (comment.name && comment.name.toLowerCase().includes(filterLower)) || 
          (comment.username && comment.username.toLowerCase().includes(filterLower))
        );
      }
      
      // Filter by comment content
      if (currentContentFilter) {
        const contentLower = currentContentFilter.toLowerCase();
        filteredComments = filteredComments.filter(comment => 
          comment.comment && comment.comment.toLowerCase().includes(contentLower)
        );
      }
      
      // Update search info
      if (currentUserFilter || currentContentFilter) {
        let message = '';
        if (currentUserFilter && currentContentFilter) {
          message = `Comments by "${currentUserFilter}" containing "${currentContentFilter}"`;
        } else if (currentUserFilter) {
          message = `Comments by "${currentUserFilter}"`;
        } else if (currentContentFilter) {
          message = `Comments containing "${currentContentFilter}"`;
        }
        searchMessage.textContent = message;
        resultCountSpan.textContent = filteredComments.length + ' comment(s) found';
        searchInfo.style.display = 'flex';
      } else {
        searchInfo.style.display = 'none';
      }
      
      // Render table
      if (filteredComments.length === 0) {
        commentsTableBody.innerHTML = '<tr class="no-results"><td colspan="6" style="text-align:center; padding:40px;"><i class="fa-regular fa-comment" style="font-size:40px; opacity:0.5;"></i><br><br>No comments found matching your search.<br><small>Try different keywords or clear the search.</small></td></tr>';
        return;
      }
      
      commentsTableBody.innerHTML = '';
      filteredComments.forEach(comment => {
        const userAvatar = comment.user_img ? `../assets/images/users/${encodeURIComponent(comment.user_img)}` : '../assets/images/users/default.png';
        const timeFormatted = formatDate(comment.time);
        
        const row = document.createElement('tr');
        row.innerHTML = `
          <td data-label="ID">${comment.id}</td>
          <td data-label="User">
            <div class="user-info">
              <img src="${userAvatar}" alt="avatar">
              <div>
                <strong>${escapeHtml(comment.name)}</strong><br>
                <small>@${escapeHtml(comment.username)}</small>
              </div>
            </div>
          </td>
          <td data-label="Comment" class="comment-content">
            <div>${escapeHtml(comment.comment)}</div>
            <a href="../status/${comment.tweet_id}?comment_id=${comment.id}" target="_blank" class="view-link">
              <i class="fa-solid fa-external-link-alt"></i> View on tweet
            </a>
          </td>
          <td data-label="Original Tweet" class="tweet-content">
            <div style="font-size: 13px;">${escapeHtml(comment.tweet_text) || '<span style="color: var(--gray);">Tweet may have been deleted</span>'}</div>
            <a href="../status/${comment.tweet_id}" target="_blank" class="view-link">
              <i class="fa-solid fa-external-link-alt"></i> View original tweet
            </a>
          </td>
          <td data-label="Time">${timeFormatted}</td>
          <td data-label="Action">
            <a href="comments.php?delete_id=${comment.id}" class="delete-btn" onclick="return confirm('Are you sure you want to delete this comment? This will also delete all replies to this comment.');">
              <i class="fa-solid fa-trash"></i> Delete
            </a>
          </td>
        `;
        commentsTableBody.appendChild(row);
      });
    }

    // Show user suggestions
    function showSuggestions() {
      const query = userSearchInput.value.trim().toLowerCase();
      
      if (query === '') {
        userSuggestions.classList.remove('show');
        return;
      }
      
      const matchedUsers = allUsersList.filter(user => 
        (user.name && user.name.toLowerCase().includes(query)) || 
        (user.username && user.username.toLowerCase().includes(query))
      ).slice(0, 8);
      
      if (matchedUsers.length === 0) {
        userSuggestions.classList.remove('show');
        return;
      }
      
      userSuggestions.innerHTML = matchedUsers.map(user => `
        <div class="suggestion-item" onclick="selectUser('${escapeHtml(user.username)}', '${escapeHtml(user.name)}')">
          <div class="suggestion-info">
            <div class="suggestion-name">${escapeHtml(user.name)}</div>
            <div class="suggestion-username">@${escapeHtml(user.username)}</div>
          </div>
        </div>
      `).join('');
      
      userSuggestions.classList.add('show');
    }

    // Select user from suggestion
    function selectUser(username, name) {
      userSearchInput.value = name;
      userSuggestions.classList.remove('show');
      currentUserFilter = username;
      renderComments();
    }

    // Clear all searches
    function clearSearch() {
      userSearchInput.value = '';
      contentSearchInput.value = '';
      currentUserFilter = null;
      currentContentFilter = null;
      renderComments();
      userSuggestions.classList.remove('show');
    }

    // Event listeners
    contentSearchInput.addEventListener('input', function() {
      const query = this.value.trim();
      currentContentFilter = query === '' ? null : query;
      renderComments();
    });

    userSearchInput.addEventListener('input', function() {
      const query = this.value.trim();
      if (query === '') {
        currentUserFilter = null;
        renderComments();
        userSuggestions.classList.remove('show');
      } else {
        showSuggestions();
      }
    });

    // Close suggestions when clicking outside
    document.addEventListener('click', function(e) {
      if (!userSearchInput.contains(e.target) && !userSuggestions.contains(e.target)) {
        userSuggestions.classList.remove('show');
      }
    });

    // Clear search button
    clearSearchBtn.addEventListener('click', function(e) {
      e.preventDefault();
      clearSearch();
    });

    // Initial render
    renderComments();

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