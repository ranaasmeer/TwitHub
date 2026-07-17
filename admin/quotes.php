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

// Fetch all quote/retweets with user and tweet info - CORRECTED SQL USING POSTS TABLE
$stmt = $pdo->prepare("
    SELECT r.post_id, r.retweet_msg, r.tweet_id, r.retweet_id,
           u_owner.id as user_id, u_owner.username, u_owner.name, u_owner.img AS avatar,
           t_orig.status AS original_tweet, t_orig.post_id as original_post_id,
           u_orig.username AS original_username, u_orig.name AS original_name,
           u_orig.img AS original_avatar
    FROM retweets r
    /* 1. Join the POSTS table to find the person who actually created this retweet */
    INNER JOIN posts p_owner ON r.post_id = p_owner.id
    /* 2. Join the USERS table to get that person's avatar and name */
    INNER JOIN users u_owner ON p_owner.user_id = u_owner.id
    /* 3. Join the TWEETS table to get the original content being shared */
    LEFT JOIN tweets t_orig ON r.tweet_id = t_orig.post_id
    /* 4. Join the USERS table again to get the original author's info */
    LEFT JOIN users u_orig ON t_orig.tweet_by = u_orig.id
    ORDER BY r.post_id DESC
");
$stmt->execute();
$allRetweets = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Manage Quotes/Retweets · Admin Panel</title>
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

    .search-box input, .search-box select {
      width: 100%;
      padding: 8px 14px;
      border-radius: 25px;
      border: 1px solid #ddd;
      font-size: 13px;
      background: var(--white);
      color: var(--secondary);
      transition: all 0.3s;
    }

    .search-box input:focus, .search-box select:focus {
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
    th, td { padding: 14px 18px; text-align: left; border-bottom: 1px solid #ddd; font-size: 14px; vertical-align: top; }
    th { background: var(--primary); color: #fff; }
    tr:hover { background: rgba(29,161,242,0.05); }
    .actions a { text-decoration: none; color: #fff; padding: 6px 12px; border-radius: 20px; font-size: 13px; display: inline-block; margin: 0 2px; }
    .edit-btn { background: var(--success); }
    .delete-btn { background: var(--danger); }
    .user-info { display: flex; align-items: center; gap: 10px; }
    .user-info img { width: 35px; height: 35px; border-radius: 50%; object-fit: cover; }
    .quote-message { max-width: 250px; word-wrap: break-word; }
    .original-tweet { max-width: 300px; word-wrap: break-word; background: rgba(29,161,242,0.05); padding: 10px; border-radius: 12px; margin-top: 5px; }
    .retweet-header { font-size: 12px; color: var(--gray); margin-bottom: 8px; display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
    .retweet-header i { font-size: 12px; }
    .retweet-header img { width: 18px; height: 18px; border-radius: 50%; object-fit: cover; }
    .original-tweet-header { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; }
    .original-tweet-header img { width: 20px; height: 20px; border-radius: 50%; object-fit: cover; }
    .original-tweet-header strong { font-size: 13px; }
    .original-tweet-header small { font-size: 11px; color: var(--gray); }
    .no-results td { text-align: center; padding: 40px; }

    /* Badge styles */
    .type-badge { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 10px; font-weight: 600; }
    .badge-quote { background: var(--primary); color: white; }
    .badge-retweet { background: #17bf63; color: white; }

    /* Scrollbar for suggestions */
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
      .quote-message, .original-tweet { max-width: 60%; }
      .retweet-header { font-size: 10px; }
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
        <a href="dashboard.php"><i class="fa-solid fa-house"></i> Dashboard</a>
        <a href="users.php"><i class="fa-solid fa-users"></i> Manage Users</a>
        <a href="tweets.php"><i class="fa-brands fa-twitter"></i> Manage Tweets</a>
        <a href="comments.php"><i class="fa-solid fa-comments"></i> Manage Comments</a>
        <a href="trends.php"><i class="fa-solid fa-chart-line"></i> Manage Trends</a>
        <a href="replies.php"><i class="fa-solid fa-reply"></i> Manage Replies</a>
        <a href="quotes.php" class="active"><i class="fa-solid fa-retweet"></i> Manage Quotes/Retweets</a>
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
      <h1>Manage Quotes/Retweets</h1>
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

      <!-- Filter by Type (Quote/Retweet) -->
      <div class="search-box">
        <label><i class="fa-solid fa-filter"></i> Filter by Type</label>
        <select id="typeFilter">
          <option value="all">All (Quotes & Retweets)</option>
          <option value="quote">Quotes Only</option>
          <option value="retweet">Retweets Only</option>
        </select>
      </div>

      <!-- Search by Quote Content -->
      <div class="search-box">
        <label><i class="fa-solid fa-magnifying-glass"></i> Search by Quote Message</label>
        <input type="text" id="contentSearchInput" placeholder="Search in quote message..." autocomplete="off">
      </div>
    </div>

    <!-- Search Info -->
    <div id="searchInfo" class="search-info" style="display: none;">
      <span><i class="fa-solid fa-search"></i> <span id="searchMessage"></span></span>
      <span id="resultCount"></span>
      <a href="#" id="clearSearchBtn" class="clear-search"><i class="fa-solid fa-times"></i> Clear</a>
    </div>

    <div id="quotesTableContainer">
      <table id="quotesTable">
        <thead>
          <tr>
            <th>ID</th>
            <th>User</th>
            <th>Quote/Retweet Info</th>
            <th>Original Tweet</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="quotesTableBody">
          <!-- Will be populated by JavaScript -->
        </tbody>
      </table>
    </div>
  </main>

  <script>
    // All quotes data from PHP (embedded for live search)
    const allQuotes = <?php echo json_encode($allRetweets); ?>;
    const allUsersList = <?php echo json_encode($allUsers); ?>;

    // DOM Elements
    const userSearchInput = document.getElementById('userSearchInput');
    const typeFilter = document.getElementById('typeFilter');
    const contentSearchInput = document.getElementById('contentSearchInput');
    const quotesTableBody = document.getElementById('quotesTableBody');
    const searchInfo = document.getElementById('searchInfo');
    const searchMessage = document.getElementById('searchMessage');
    const resultCountSpan = document.getElementById('resultCount');
    const clearSearchBtn = document.getElementById('clearSearchBtn');
    const userSuggestions = document.getElementById('userSuggestions');

    let currentUserFilter = null;
    let currentTypeFilter = 'all';
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

    // Get type badge
    function getTypeBadge(item) {
      if (item.retweet_msg && item.retweet_msg !== '') {
        return '<span class="type-badge badge-quote"><i class="fa-solid fa-quote-right"></i> Quote Tweet</span>';
      } else {
        return '<span class="type-badge badge-retweet"><i class="fa-solid fa-retweet"></i> Retweet</span>';
      }
    }

    // Get retweet/quoter header with light gray style
    function getActionHeader(item) {
      const userAvatar = item.avatar ? `../assets/images/users/${encodeURIComponent(item.avatar)}` : '../assets/images/users/default.png';
      
      if (item.retweet_msg && item.retweet_msg !== '') {
        return `
          <div class="retweet-header">
            <i class="fa-solid fa-quote-right" style="color: var(--primary);"></i>
            <img src="${userAvatar}" alt="avatar">
            <strong>${escapeHtml(item.name)}</strong>
            <span>@${escapeHtml(item.username)} quoted</span>
          </div>
        `;
      } else {
        return `
          <div class="retweet-header">
            <i class="fa-solid fa-retweet" style="color: #17bf63;"></i>
            <img src="${userAvatar}" alt="avatar">
            <strong>${escapeHtml(item.name)}</strong>
            <span>@${escapeHtml(item.username)} retweeted</span>
          </div>
        `;
      }
    }

    // Render quotes based on filters
    function renderQuotes() {
      let filteredQuotes = [...allQuotes];
      
      // Filter by user - FIXED: using .includes() instead of === for better search
      if (currentUserFilter) {
        const filterLower = currentUserFilter.toLowerCase();
        filteredQuotes = filteredQuotes.filter(quote => 
          (quote.name && quote.name.toLowerCase().includes(filterLower)) || 
          (quote.username && quote.username.toLowerCase().includes(filterLower))
        );
      }
      
      // Filter by type (Quote vs Retweet)
      if (currentTypeFilter === 'quote') {
        filteredQuotes = filteredQuotes.filter(quote => quote.retweet_msg && quote.retweet_msg !== '');
      } else if (currentTypeFilter === 'retweet') {
        filteredQuotes = filteredQuotes.filter(quote => !quote.retweet_msg || quote.retweet_msg === '');
      }
      
      // Filter by quote content
      if (currentContentFilter) {
        const contentLower = currentContentFilter.toLowerCase();
        filteredQuotes = filteredQuotes.filter(quote => 
          quote.retweet_msg && quote.retweet_msg.toLowerCase().includes(contentLower)
        );
      }
      
      // Update search info
      if (currentUserFilter || currentTypeFilter !== 'all' || currentContentFilter) {
        let message = '';
        if (currentUserFilter && currentTypeFilter !== 'all' && currentContentFilter) {
          message = `Quotes/Retweets by "${currentUserFilter}" | Type: ${currentTypeFilter === 'quote' ? 'Quotes' : 'Retweets'} | Containing "${currentContentFilter}"`;
        } else if (currentUserFilter && currentTypeFilter !== 'all') {
          message = `Quotes/Retweets by "${currentUserFilter}" | Type: ${currentTypeFilter === 'quote' ? 'Quotes Only' : 'Retweets Only'}`;
        } else if (currentUserFilter && currentContentFilter) {
          message = `Quotes/Retweets by "${currentUserFilter}" containing "${currentContentFilter}"`;
        } else if (currentUserFilter) {
          message = `Quotes/Retweets by "${currentUserFilter}"`;
        } else if (currentTypeFilter !== 'all' && currentContentFilter) {
          message = `${currentTypeFilter === 'quote' ? 'Quotes' : 'Retweets'} containing "${currentContentFilter}"`;
        } else if (currentTypeFilter !== 'all') {
          message = `${currentTypeFilter === 'quote' ? 'Quotes Only' : 'Retweets Only'}`;
        } else if (currentContentFilter) {
          message = `Quotes/Retweets containing "${currentContentFilter}"`;
        }
        searchMessage.textContent = message;
        resultCountSpan.textContent = filteredQuotes.length + ' item(s) found';
        searchInfo.style.display = 'flex';
      } else {
        searchInfo.style.display = 'none';
      }
      
      // Render table
      if (filteredQuotes.length === 0) {
        quotesTableBody.innerHTML = '<tr class="no-results"><td colspan="5" style="text-align:center; padding:40px;"><i class="fa-solid fa-retweet" style="font-size:40px; opacity:0.5;"></i><br><br>No quotes or retweets found matching your search.<br><small>Try different keywords or clear the search.</small><\/td><\/tr>';
        return;
      }
      
      quotesTableBody.innerHTML = '';
      filteredQuotes.forEach(quote => {
        const row = document.createElement('tr');
        const userAvatar = quote.avatar ? `../assets/images/users/${encodeURIComponent(quote.avatar)}` : '../assets/images/users/default.png';
        const originalAvatar = quote.original_avatar ? `../assets/images/users/${encodeURIComponent(quote.original_avatar)}` : '../assets/images/users/default.png';
        
        // Get quote message or "No message" for retweets
        const quoteMessage = quote.retweet_msg && quote.retweet_msg !== '' 
          ? `<div style="background: rgba(29,161,242,0.08); padding: 10px; border-radius: 12px; margin-top: 8px;">
               <i class="fa-solid fa-quote-left" style="color: var(--primary); font-size: 12px; margin-right: 5px;"></i>
               ${escapeHtml(quote.retweet_msg)}
             </div>`
          : '';
        
        row.innerHTML = `
          <td data-label="ID">${quote.post_id}</td>
          <td data-label="User">
            <div class="user-info">
              <img src="${userAvatar}" alt="avatar">
              <div>
                <strong>${escapeHtml(quote.name)}</strong><br>
                <small>@${escapeHtml(quote.username)}</small>
                <div style="margin-top: 5px;">${getTypeBadge(quote)}</div>
              </div>
            </div>
          </td>
          <td data-label="Quote/Retweet Info" class="quote-message">
            ${getActionHeader(quote)}
            ${quoteMessage}
          </td>
          <td data-label="Original Tweet" class="original-tweet">
            <div class="original-tweet-header">
              <img src="${originalAvatar}" alt="avatar">
              <div>
                <strong>${escapeHtml(quote.original_name)}</strong>
                <small>@${escapeHtml(quote.original_username)}</small>
              </div>
            </div>
            <div style="font-size: 13px; color: var(--secondary);">${escapeHtml(quote.original_tweet) || '<span style="color: var(--gray);">Original tweet may have been deleted</span>'}</div>
            <div style="margin-top: 5px;">
              <a href="../status/${quote.original_post_id}" target="_blank" style="color: var(--primary); font-size: 12px; text-decoration: none;">
                <i class="fa-solid fa-external-link-alt"></i> View original tweet
              </a>
            </div>
          </td>
          <td class="actions">
            <a href="edit_quote.php?id=${quote.post_id}" class="edit-btn"><i class="fa-solid fa-pen-to-square"></i> Edit</a>
            <a href="delete_quote.php?id=${quote.post_id}" class="delete-btn" onclick="return confirm('Delete this quote/retweet?');"><i class="fa-solid fa-trash"></i> Delete</a>
          </td>
        `;
        quotesTableBody.appendChild(row);
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
      renderQuotes();
    }

    // Clear all searches
    function clearSearch() {
      userSearchInput.value = '';
      typeFilter.value = 'all';
      contentSearchInput.value = '';
      currentUserFilter = null;
      currentTypeFilter = 'all';
      currentContentFilter = null;
      renderQuotes();
      userSuggestions.classList.remove('show');
    }

    // Event listeners
    typeFilter.addEventListener('change', function() {
      currentTypeFilter = this.value;
      renderQuotes();
    });

    contentSearchInput.addEventListener('input', function() {
      const query = this.value.trim();
      currentContentFilter = query === '' ? null : query;
      renderQuotes();
    });

    userSearchInput.addEventListener('input', function() {
      const query = this.value.trim();
      if (query === '') {
        currentUserFilter = null;
        renderQuotes();
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
    renderQuotes();

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