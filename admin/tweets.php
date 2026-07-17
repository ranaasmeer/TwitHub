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

// Fetch all tweets with media info (including multiple videos)
$stmt = $pdo->query("
    SELECT t.*, u.username, u.name,
           (SELECT GROUP_CONCAT(media_path) FROM tweet_media WHERE tweet_id = t.post_id AND media_type = 'image') as media_images,
           (SELECT COUNT(*) FROM tweet_media WHERE tweet_id = t.post_id AND media_type = 'image') as media_count,
           (SELECT GROUP_CONCAT(video_path) FROM tweet_videos WHERE tweet_id = t.post_id) as video_paths,
           (SELECT COUNT(*) FROM tweet_videos WHERE tweet_id = t.post_id) as video_count
    FROM tweets t 
    JOIN users u ON t.tweet_by = u.id 
    ORDER BY t.post_id DESC
");
$allTweets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Process images and videos for each tweet
foreach ($allTweets as &$tweet) {
    $allImages = [];
    $allVideos = [];
    
    // Get images from tweet_media table
    if (!empty($tweet['media_images'])) {
        $mediaImages = explode(',', $tweet['media_images']);
        foreach ($mediaImages as $img) {
            if (!empty($img)) $allImages[] = $img;
        }
    }
    
    // Get from old img column
    if (!empty($tweet['img'])) {
        $oldImages = explode(',', $tweet['img']);
        foreach ($oldImages as $img) {
            if (!empty($img)) $allImages[] = $img;
        }
    }
    
    // Get videos from tweet_videos table
    if (!empty($tweet['video_paths'])) {
        $videoPaths = explode(',', $tweet['video_paths']);
        foreach ($videoPaths as $video) {
            if (!empty($video)) $allVideos[] = $video;
        }
    }
    
    // Add single video from old video column
    if (!empty($tweet['video']) && empty($allVideos)) {
        $allVideos[] = $tweet['video'];
    }
    
    $tweet['all_images'] = array_unique($allImages);
    $tweet['all_videos'] = array_unique($allVideos);
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Manage Tweets · Admin Panel</title>
<link rel="icon" href="../assets/images/twitter-logo.png" type="image/png">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/lightbox2/2.11.4/css/lightbox.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/lightbox2/2.11.4/js/lightbox.min.js"></script>
<style>
:root { --primary: #1DA1F2; --secondary: #14171A; --light: #f5f8fa; --white: #fff; --gray: #657786; --danger: #e0245e; --success: #17bf63; }
body.dark { --primary: #1DA1F2; --secondary: #e6ecf0; --light: #1a1d21; --white: #1c1f23; --gray: #8899a6; }
* { margin: 0; padding: 0; font-family: 'Poppins', sans-serif; transition: background 0.3s, color 0.3s; }
body { display: flex; min-height: 100vh; background: var(--light); color: var(--secondary); }

/* Sidebar */
.sidebar { width: 250px; background: var(--secondary); color: var(--white); display: flex; flex-direction: column; justify-content: space-between; padding: 20px 0; position: fixed; left: 0; top: 0; bottom: 0; }
.sidebar-header { text-align: center; margin-bottom: 20px; }
.sidebar-logo { width: 60px; margin-bottom: 10px; }
.sidebar-nav a { color: var(--white); text-decoration: none; padding: 14px 25px; display: flex; align-items: center; gap: 10px; font-size: 15px; transition: all 0.3s; }
.sidebar-nav a:hover, .sidebar-nav a.active { background: var(--primary); border-radius: 0 20px 20px 0; }
.sidebar-footer { text-align: center; padding: 15px; }
.logout-btn { color: var(--white); background: var(--danger); padding: 10px 20px; border-radius: 25px; text-decoration: none; display: inline-block; transition: 0.3s; }
.logout-btn:hover { background: #ff4d6d; }

/* Main Content */
.main-content { margin-left: 250px; flex: 1; padding: 30px; }
.topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; flex-wrap: wrap; gap: 15px; }
.theme-toggle { background: none; border: none; color: var(--primary); font-size: 22px; cursor: pointer; }

/* Search Container */
.search-container {
  display: flex;
  gap: 15px;
  margin-bottom: 25px;
  flex-wrap: wrap;
  max-width: 700px;
}

.search-box {
  flex: 1;
  min-width: 250px;
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

/* Table */
.table-responsive { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; background: var(--white); border-radius: 12px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #eee; font-size: 14px; vertical-align: top; }
th { background: var(--primary); color: #fff; font-weight: 600; }

/* Images Container */
.images-container { display: flex; flex-wrap: wrap; gap: 6px; }
.images-container a { display: block; }
.images-container img { width: 60px; height: 60px; object-fit: cover; border-radius: 6px; cursor: pointer; border: 1px solid #e1e8ed; transition: 0.2s; }
.images-container img:hover { transform: scale(1.05); box-shadow: 0 2px 8px rgba(0,0,0,0.1); }

/* Videos Container */
.videos-container { display: flex; flex-wrap: wrap; gap: 6px; }
.video-thumb { width: 60px; height: 60px; background: #000; border-radius: 6px; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: 0.2s; position: relative; }
.video-thumb:hover { transform: scale(1.05); }
.video-thumb i { font-size: 30px; color: white; }
.video-thumb video { width: 100%; height: 100%; object-fit: cover; position: absolute; top: 0; left: 0; opacity: 0; }
.video-thumb.multiple-videos::after {
    content: '+';
    position: absolute;
    bottom: 2px;
    right: 5px;
    background: rgba(0,0,0,0.7);
    color: white;
    font-size: 12px;
    padding: 2px 5px;
    border-radius: 10px;
}

.status-text { max-width: 280px; word-wrap: break-word; line-height: 1.4; }
.actions { display: flex; gap: 8px; flex-wrap: wrap; }
.edit-btn, .delete-btn { padding: 5px 10px; border: none; border-radius: 18px; cursor: pointer; font-size: 11px; transition: 0.2s; }
.edit-btn { background: var(--success); color: #fff; }
.edit-btn:hover { background: #13a254; transform: translateY(-1px); }
.delete-btn { background: var(--danger); color: #fff; }
.delete-btn:hover { background: #c81f4e; transform: translateY(-1px); }
.badge { display: inline-block; padding: 2px 8px; border-radius: 20px; font-size: 11px; font-weight: 600; }
.badge-retweet { background: #17bf63; color: white; }
.badge-quote { background: var(--primary); color: white; }
.badge-tweet { background: var(--gray); color: white; }
.no-results td { text-align: center; padding: 40px; }

/* Lightbox Video */
.lightbox-video { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.95); z-index: 100000; justify-content: center; align-items: center; }
.lightbox-video.active { display: flex; }
.lightbox-video video { max-width: 90%; max-height: 90%; border-radius: 8px; }
.lightbox-video .close-video { position: absolute; top: 20px; right: 30px; color: white; font-size: 35px; cursor: pointer; background: rgba(0,0,0,0.5); width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; transition: 0.2s; }
.lightbox-video .close-video:hover { background: rgba(255,255,255,0.2); transform: scale(1.1); }

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
}
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
      <a href="users.php"><i class="fa-solid fa-users"></i> Users</a>
      <a href="tweets.php" class="active"><i class="fa-brands fa-twitter"></i> Tweets</a>
      <a href="comments.php"><i class="fa-solid fa-comments"></i> Comments</a>
      <a href="trends.php"><i class="fa-solid fa-chart-line"></i> Trends</a>
      <a href="replies.php"><i class="fa-solid fa-reply"></i> Replies</a>
      <a href="quotes.php"><i class="fa-solid fa-retweet"></i> Quotes</a>
      <a href="bookmarks.php"><i class="fa-solid fa-bookmark"></i> Bookmarks</a>
      <a href="user_analytics.php"><i class="fa-solid fa-chart-simple"></i> Analytics</a>
    </nav>
  </div>
  <div class="sidebar-footer">
    <a href="logout.php" class="logout-btn"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
  </div>
</aside>

<main class="main-content">
  <div class="topbar">
    <h1><i class="fa-brands fa-twitter"></i> Manage Tweets</h1>
    <button id="themeToggle" class="theme-toggle"><i class="fa-solid fa-moon"></i></button>
  </div>

  <!-- Search Container -->
  <div class="search-container">
    <div class="search-box">
      <label><i class="fa-solid fa-user"></i> Search by User</label>
      <div class="search-input-wrapper">
        <input type="text" id="userSearchInput" placeholder="Type username or name..." autocomplete="off">
        <div id="userSuggestions" class="suggestions-dropdown"></div>
      </div>
    </div>
    <div class="search-box">
      <label><i class="fa-solid fa-magnifying-glass"></i> Search by Content</label>
      <input type="text" id="contentSearchInput" placeholder="Search in tweet content..." autocomplete="off">
    </div>
  </div>

  <!-- Search Info -->
  <div id="searchInfo" class="search-info" style="display: none;">
    <span><i class="fa-solid fa-search"></i> <span id="searchMessage"></span></span>
    <span id="resultCount"></span>
    <a href="#" id="clearSearchBtn" class="clear-search"><i class="fa-solid fa-times"></i> Clear</a>
  </div>

  <div class="table-responsive">
    <table id="tweetsTable">
      <thead>
        <tr><th>ID</th><th>Posted By</th><th>Content</th><th>Images</th><th>Videos</th><th>Media Type</th><th>Actions</th></tr>
      </thead>
      <tbody id="tweetsTableBody">
        <!-- Populated by JavaScript -->
      </tbody>
    </table>
  </div>
</main>

<!-- Video Lightbox -->
<div id="videoLightbox" class="lightbox-video">
    <span class="close-video" onclick="closeVideoLightbox()">&times;</span>
    <video id="lightboxVideo" controls>
        <source src="" type="video/mp4">
    </video>
</div>

<script>
// All tweets data from PHP
const allTweets = <?php echo json_encode($allTweets); ?>;
const allUsersList = <?php echo json_encode($allUsers); ?>;

// DOM Elements
const userSearchInput = document.getElementById('userSearchInput');
const contentSearchInput = document.getElementById('contentSearchInput');
const tweetsTableBody = document.getElementById('tweetsTableBody');
const searchInfo = document.getElementById('searchInfo');
const searchMessage = document.getElementById('searchMessage');
const resultCountSpan = document.getElementById('resultCount');
const clearSearchBtn = document.getElementById('clearSearchBtn');
const userSuggestions = document.getElementById('userSuggestions');

let currentUserFilter = null;
let currentContentFilter = null;

function escapeHtml(str) {
  if (!str) return '';
  return str.replace(/[&<>]/g, function(m) {
    if (m === '&') return '&amp;';
    if (m === '<') return '&lt;';
    if (m === '>') return '&gt;';
    return m;
  });
}

function nl2br(str) {
  if (!str) return '';
  return str.replace(/\n/g, '<br>');
}

function renderImages(images) {
  if (!images || images.length === 0) return '—';
  let html = '<div class="images-container">';
  images.forEach(img => {
    html += `<a href="../assets/images/tweets/${encodeURIComponent(img)}" data-lightbox="tweet-gallery" data-title="Tweet image">
               <img src="../assets/images/tweets/${encodeURIComponent(img)}" alt="image">
             </a>`;
  });
  html += '</div>';
  return html;
}

function renderVideos(videos) {
  if (!videos || videos.length === 0) return '—';
  let html = '<div class="videos-container">';
  videos.forEach((video, index) => {
    const isMultiple = videos.length > 1;
    html += `<div class="video-thumb ${isMultiple ? 'multiple-videos' : ''}" onclick="openVideoLightbox('../assets/videos/tweets/${encodeURIComponent(video)}')">
               <i class="fa-solid fa-play"></i>
               <video preload="metadata" muted>
                 <source src="../assets/videos/tweets/${encodeURIComponent(video)}" type="video/mp4">
               </video>
             </div>`;
  });
  html += '</div>';
  return html;
}

function renderTweets() {
  let filteredTweets = [...allTweets];
  
  if (currentUserFilter) {
    const filterLower = currentUserFilter.toLowerCase();
    filteredTweets = filteredTweets.filter(tweet => 
      (tweet.name && tweet.name.toLowerCase().includes(filterLower)) || 
      (tweet.username && tweet.username.toLowerCase().includes(filterLower))
    );
  }
  
  if (currentContentFilter) {
    const contentLower = currentContentFilter.toLowerCase();
    filteredTweets = filteredTweets.filter(tweet => 
      tweet.status && tweet.status.toLowerCase().includes(contentLower)
    );
  }
  
  if (currentUserFilter || currentContentFilter) {
    let message = '';
    if (currentUserFilter && currentContentFilter) {
      message = `Tweets by "${currentUserFilter}" containing "${currentContentFilter}"`;
    } else if (currentUserFilter) {
      message = `Tweets by "${currentUserFilter}"`;
    } else if (currentContentFilter) {
      message = `Tweets containing "${currentContentFilter}"`;
    }
    searchMessage.textContent = message;
    resultCountSpan.textContent = filteredTweets.length + ' tweet(s) found';
    searchInfo.style.display = 'flex';
  } else {
    searchInfo.style.display = 'none';
  }
  
  if (filteredTweets.length === 0) {
    tweetsTableBody.innerHTML = '<tr class="no-results"><td colspan="7" style="text-align:center; padding:40px;"><i class="fa-solid fa-newspaper" style="font-size:40px; opacity:0.5;"></i><br><br>No tweets found.<br><small>Try different keywords or clear the search.</small></td></tr>';
    return;
  }
  
  tweetsTableBody.innerHTML = '';
  filteredTweets.forEach(tweet => {
    const allImages = tweet.all_images || [];
    const allVideos = tweet.all_videos || [];
    const hasImages = allImages.length > 0;
    const hasVideos = allVideos.length > 0;
    
    let imagesHtml = hasImages ? renderImages(allImages) : '—';
    let videosHtml = hasVideos ? renderVideos(allVideos) : '—';
    
    let mediaTypeHtml = '';
    if (hasVideos && hasImages) {
      mediaTypeHtml = `<span class="badge badge-tweet"><i class="fa-regular fa-image"></i> ${allImages.length} photo(s) | <i class="fa-regular fa-video"></i> ${allVideos.length} video(s)</span>`;
    } else if (hasVideos) {
      mediaTypeHtml = `<span class="badge badge-tweet"><i class="fa-regular fa-video"></i> ${allVideos.length} video(s)</span>`;
    } else if (hasImages) {
      mediaTypeHtml = `<span class="badge badge-tweet"><i class="fa-regular fa-image"></i> ${allImages.length} photo(s)</span>`;
    } else {
      mediaTypeHtml = `<span class="badge badge-tweet"><i class="fa-regular fa-file-lines"></i> Text only</span>`;
    }
    
    if (tweet.retweet_id) {
      mediaTypeHtml += `<div><span class="badge badge-retweet"><i class="fa-solid fa-retweet"></i> Retweet</span></div>`;
    }
    if (tweet.quoted_tweet_id) {
      mediaTypeHtml += `<div><span class="badge badge-quote"><i class="fa-solid fa-quote-right"></i> Quote</span></div>`;
    }
    
    const row = document.createElement('tr');
    row.setAttribute('data-tweet-id', tweet.post_id);
    row.innerHTML = `
      <td data-label="ID">${tweet.post_id}</td>
      <td data-label="Posted By"><strong>${escapeHtml(tweet.name)}</strong><br><small>@${escapeHtml(tweet.username)}</small></td>
      <td data-label="Content" class="status-text">${nl2br(escapeHtml(tweet.status))}</td>
      <td data-label="Images">${imagesHtml}</td>
      <td data-label="Videos">${videosHtml}</td>
      <td data-label="Media Type">${mediaTypeHtml}</td>
      <td class="actions">
        <a href="edit_tweet.php?id=${tweet.post_id}"><button class="edit-btn"><i class="fa-solid fa-pen"></i> Edit</button></a>
        <a href="delete_tweet.php?id=${tweet.post_id}" onclick="return confirm('Delete this tweet permanently?');"><button class="delete-btn"><i class="fa-solid fa-trash"></i> Delete</button></a>
      </td>
    `;
    tweetsTableBody.appendChild(row);
  });
  
  lightbox.option({
    'resizeDuration': 200,
    'wrapAround': true,
    'showImageNumberLabel': true,
    'albumLabel': 'Image %1 of %2',
    'fadeDuration': 300,
    'imageFadeDuration': 300
  });
}

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
    <div class="suggestion-item" onclick="selectUser('${escapeHtml(user.name)}')">
      <div class="suggestion-info">
        <div class="suggestion-name">${escapeHtml(user.name)}</div>
        <div class="suggestion-username">@${escapeHtml(user.username)}</div>
      </div>
    </div>
  `).join('');
  userSuggestions.classList.add('show');
}

function selectUser(userName) {
  userSearchInput.value = userName;
  userSuggestions.classList.remove('show');
  currentUserFilter = userName;
  renderTweets();
}

function clearSearch() {
  userSearchInput.value = '';
  contentSearchInput.value = '';
  currentUserFilter = null;
  currentContentFilter = null;
  renderTweets();
  userSuggestions.classList.remove('show');
}

contentSearchInput.addEventListener('input', function() {
  const query = this.value.trim();
  currentContentFilter = query === '' ? null : query;
  renderTweets();
});

userSearchInput.addEventListener('input', function() {
  const query = this.value.trim();
  if (query === '') {
    currentUserFilter = null;
    renderTweets();
    userSuggestions.classList.remove('show');
  } else {
    showSuggestions();
  }
});

document.addEventListener('click', function(e) {
  if (!userSearchInput.contains(e.target) && !userSuggestions.contains(e.target)) {
    userSuggestions.classList.remove('show');
  }
});

clearSearchBtn.addEventListener('click', function(e) {
  e.preventDefault();
  clearSearch();
});

renderTweets();

function openVideoLightbox(videoSrc) {
    document.getElementById('lightboxVideo').src = videoSrc;
    document.getElementById('videoLightbox').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeVideoLightbox() {
    document.getElementById('lightboxVideo').pause();
    document.getElementById('lightboxVideo').src = '';
    document.getElementById('videoLightbox').classList.remove('active');
    document.body.style.overflow = '';
}

document.getElementById('videoLightbox').addEventListener('click', function(e) {
    if (e.target === this) closeVideoLightbox();
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.getElementById('videoLightbox').classList.contains('active')) closeVideoLightbox();
});

// Theme Toggle
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

lightbox.option({
    'resizeDuration': 200,
    'wrapAround': true,
    'showImageNumberLabel': true,
    'albumLabel': 'Image %1 of %2',
    'fadeDuration': 300,
    'imageFadeDuration': 300
});
</script>
</body>
</html>