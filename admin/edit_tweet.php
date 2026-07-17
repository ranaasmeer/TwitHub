<?php
require_once '../core/init.php';

if (!isset($_SESSION['admin_id'])) { header('Location: index.php'); exit; }

$pdo = Connect::connect();
if (!isset($_GET['id'])) { header('Location: tweets.php'); exit; }

$tweetId = (int)$_GET['id'];
$stmt = $pdo->prepare("SELECT t.*, u.username, u.name FROM tweets t JOIN users u ON t.tweet_by = u.id WHERE t.post_id = ?");
$stmt->execute([$tweetId]);
$tweet = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$tweet) { header('Location: tweets.php'); exit; }

$errors = [];

// Process deletions ONLY on form submit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_update'])) {
    $status = trim($_POST['status']);
    if (empty($status)) $errors[] = 'Tweet content cannot be empty.';

    // Handle image deletions
    $imagesToDelete = isset($_POST['delete_images_list']) ? explode(',', $_POST['delete_images_list']) : [];
    $imagesToDelete = array_filter($imagesToDelete);
    
    foreach ($imagesToDelete as $imgToDelete) {
        $delStmt = $pdo->prepare("DELETE FROM tweet_media WHERE tweet_id = ? AND media_path = ?");
        $delStmt->execute([$tweetId, $imgToDelete]);
        
        if (!empty($tweet['img'])) {
            $oldImgs = explode(',', $tweet['img']);
            $newImgs = array_diff($oldImgs, [$imgToDelete]);
            $updateStmt = $pdo->prepare("UPDATE tweets SET img = ? WHERE post_id = ?");
            $updateStmt->execute([implode(',', $newImgs), $tweetId]);
        }
        
        $filePath = "../assets/images/tweets/" . $imgToDelete;
        if (file_exists($filePath)) unlink($filePath);
    }

    // Handle video deletions (multiple videos)
    $videosToDelete = isset($_POST['delete_videos_list']) ? explode(',', $_POST['delete_videos_list']) : [];
    $videosToDelete = array_filter($videosToDelete);
    
    foreach ($videosToDelete as $videoToDelete) {
        $delStmt = $pdo->prepare("DELETE FROM tweet_videos WHERE tweet_id = ? AND video_path = ?");
        $delStmt->execute([$tweetId, $videoToDelete]);
        
        if (!empty($tweet['video']) && $tweet['video'] == $videoToDelete) {
            $updateStmt = $pdo->prepare("UPDATE tweets SET video = NULL WHERE post_id = ?");
            $updateStmt->execute([$tweetId]);
        }
        
        $filePath = "../assets/videos/tweets/" . $videoToDelete;
        if (file_exists($filePath)) unlink($filePath);
    }

    // Upload new images to tweet_media table
    if (isset($_FILES['new_images']) && !empty($_FILES['new_images']['name'][0])) {
        $allowed = ['jpg','jpeg','png','gif','webp'];
        foreach ($_FILES['new_images']['name'] as $key => $name) {
            if (empty($name)) continue;
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed)) { $errors[] = "Invalid format: $name"; continue; }
            $newName = uniqid() . '.' . $ext;
            if (move_uploaded_file($_FILES['new_images']['tmp_name'][$key], "../assets/images/tweets/$newName")) {
                $insertStmt = $pdo->prepare("INSERT INTO tweet_media (tweet_id, media_path, media_type) VALUES (?, ?, 'image')");
                $insertStmt->execute([$tweetId, $newName]);
            }
        }
    }

    // Upload new videos to tweet_videos table (multiple videos)
    if (isset($_FILES['new_videos']) && !empty($_FILES['new_videos']['name'][0])) {
        $allowedVideo = ['mp4','webm','ogg','mov'];
        foreach ($_FILES['new_videos']['name'] as $key => $name) {
            if (empty($name)) continue;
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedVideo)) { $errors[] = "Invalid video format: $name"; continue; }
            $newName = uniqid() . '.' . $ext;
            if (move_uploaded_file($_FILES['new_videos']['tmp_name'][$key], "../assets/videos/tweets/$newName")) {
                $insertStmt = $pdo->prepare("INSERT INTO tweet_videos (tweet_id, video_path, video_order) VALUES (?, ?, ?)");
                $insertStmt->execute([$tweetId, $newName, $key]);
            }
        }
    }

    if (empty($errors)) {
        $updateStmt = $pdo->prepare("UPDATE tweets SET status = ? WHERE post_id = ?");
        $updateStmt->execute([$status, $tweetId]);
        header("Location: tweets.php");
        exit;
    }
}

// Fetch current images
$mediaStmt = $pdo->prepare("SELECT media_path FROM tweet_media WHERE tweet_id = ? AND media_type = 'image' ORDER BY id ASC");
$mediaStmt->execute([$tweetId]);
$currentImages = $mediaStmt->fetchAll(PDO::FETCH_COLUMN);
if (!empty($tweet['img'])) {
    $oldImages = explode(',', $tweet['img']);
    $currentImages = array_merge($currentImages, array_filter($oldImages));
    $currentImages = array_unique($currentImages);
}

// Fetch current videos from tweet_videos table
$videoStmt = $pdo->prepare("SELECT video_path FROM tweet_videos WHERE tweet_id = ? ORDER BY video_order ASC");
$videoStmt->execute([$tweetId]);
$currentVideos = $videoStmt->fetchAll(PDO::FETCH_COLUMN);

// If no videos in tweet_videos but has old video column, use that
if (empty($currentVideos) && !empty($tweet['video'])) {
    $currentVideos[] = $tweet['video'];
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Edit Tweet · Admin Panel</title>
<link rel="icon" href="../assets/images/twitter-logo.png">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/lightbox2/2.11.4/css/lightbox.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/lightbox2/2.11.4/js/lightbox.min.js"></script>
<style>
:root { --primary: #1DA1F2; --secondary: #14171A; --light: #f5f8fa; --white: #fff; --gray: #657786; --danger: #e0245e; --success: #17bf63; }
body.dark { --primary: #1DA1F2; --secondary: #e6ecf0; --light: #1a1d21; --white: #1c1f23; --gray: #8899a6; }
* { margin: 0; padding: 0; font-family: 'Poppins', sans-serif; box-sizing: border-box; }
body { display: flex; min-height: 100vh; background: var(--light); color: var(--secondary); transition: background 0.3s; }

/* Sidebar */
.sidebar { width: 250px; background: var(--secondary); color: var(--white); display: flex; flex-direction: column; justify-content: space-between; padding: 20px 0; position: fixed; left: 0; top: 0; bottom: 0; }
.sidebar-header { text-align: center; margin-bottom: 20px; }
.sidebar-logo { width: 60px; margin-bottom: 10px; }
.sidebar-nav a { color: var(--white); text-decoration: none; padding: 14px 25px; display: flex; align-items: center; gap: 10px; font-size: 15px; transition: all 0.3s; }
.sidebar-nav a:hover, .sidebar-nav a.active { background: var(--primary); border-radius: 0 20px 20px 0; }
.sidebar-footer { text-align: center; padding: 15px; }
.logout-btn { color: white; background: var(--danger); padding: 10px 20px; border-radius: 25px; text-decoration: none; display: inline-block; transition: 0.3s; }
.logout-btn:hover { background: #ff4d6d; }

/* Main Content */
.main-content { margin-left: 250px; flex: 1; padding: 30px; }
.topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; flex-wrap: wrap; gap: 15px; }
.theme-toggle { background: none; border: none; color: var(--primary); font-size: 22px; cursor: pointer; }

.form-card { background: var(--white); border-radius: 20px; padding: 30px; max-width: 800px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
.form-group { margin-bottom: 25px; }
label { font-weight: 600; display: block; margin-bottom: 8px; font-size: 14px; }
label i { margin-right: 6px; color: var(--primary); }
textarea { width: 100%; padding: 12px; border-radius: 12px; border: 1px solid #ddd; font-family: inherit; resize: vertical; background: var(--white); color: var(--secondary); }
textarea:focus { outline: none; border-color: var(--primary); }
input[type="file"] { font-size: 13px; padding: 8px 0; }

/* Images Preview with Delete Button */
.images-preview { display: flex; flex-wrap: wrap; gap: 15px; margin-top: 10px; }
.image-item { position: relative; display: inline-block; }
.image-item img { width: 100px; height: 100px; object-fit: cover; border-radius: 10px; cursor: pointer; border: 2px solid transparent; transition: 0.2s; }
.image-item img:hover { border-color: var(--primary); transform: scale(1.02); }
.image-item.marked-for-delete { opacity: 0.5; }
.image-item.marked-for-delete img { border-color: var(--danger); }
.delete-image-btn { position: absolute; top: -8px; right: -8px; background: var(--danger); color: white; border: none; border-radius: 50%; width: 24px; height: 24px; font-size: 12px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: 0.2s; }
.delete-image-btn:hover { transform: scale(1.1); background: #ff4d6d; }
.restore-image-btn { position: absolute; top: -8px; left: -8px; background: var(--success); color: white; border: none; border-radius: 50%; width: 24px; height: 24px; font-size: 12px; cursor: pointer; display: none; align-items: center; justify-content: center; }
.image-item.marked-for-delete .restore-image-btn { display: flex; }
.image-item.marked-for-delete .delete-image-btn { background: #888; }

/* Videos Preview */
.videos-preview { display: flex; flex-wrap: wrap; gap: 15px; margin-top: 10px; }
.video-item { position: relative; display: inline-block; }
.video-item video { width: 150px; border-radius: 10px; background: #000; }
.video-item.marked-for-delete { opacity: 0.5; }
.delete-video-btn { position: absolute; top: -8px; right: -8px; background: var(--danger); color: white; border: none; border-radius: 50%; width: 24px; height: 24px; font-size: 12px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: 0.2s; }
.restore-video-btn { position: absolute; top: -8px; left: -8px; background: var(--success); color: white; border: none; border-radius: 50%; width: 24px; height: 24px; font-size: 12px; cursor: pointer; display: none; align-items: center; justify-content: center; }
.video-item.marked-for-delete .restore-video-btn { display: flex; }
.video-item.marked-for-delete .delete-video-btn { background: #888; }

/* New Upload Preview */
.new-preview { display: flex; flex-wrap: wrap; gap: 15px; margin-top: 10px; }
.new-preview-item { position: relative; width: 100px; height: 100px; }
.new-preview-item img { width: 100%; height: 100%; object-fit: cover; border-radius: 10px; }
.new-preview-item video { width: 150px; border-radius: 10px; }
.remove-new { position: absolute; top: -8px; right: -8px; background: var(--danger); color: white; border: none; border-radius: 50%; width: 24px; height: 24px; cursor: pointer; display: flex; align-items: center; justify-content: center; }

.help-text { font-size: 12px; color: var(--gray); margin-top: 5px; }
.error-box { background: rgba(224,36,94,0.1); border-left: 4px solid var(--danger); padding: 12px 15px; border-radius: 8px; margin-bottom: 20px; }
.button-group { display: flex; gap: 15px; margin-top: 20px; }
.btn-cancel { background: var(--gray); color: white; border: none; padding: 12px 30px; border-radius: 30px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block; }
.btn-save { background: var(--primary); color: white; border: none; padding: 12px 30px; border-radius: 30px; font-weight: 600; cursor: pointer; transition: 0.2s; }
.btn-save:hover { background: #0d8ddb; transform: translateY(-2px); }

@media (max-width: 768px) { 
    .main-content { margin-left: 0; padding: 15px; } 
    .sidebar { display: none; }
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
    <div>
      <h1><i class="fa-regular fa-pen-to-square"></i> Edit Tweet #<?php echo $tweetId; ?></h1>
      <small>Posted by <?php echo htmlspecialchars($tweet['name']); ?> (@<?php echo htmlspecialchars($tweet['username']); ?>)</small>
    </div>
    <button id="themeToggle" class="theme-toggle"><i class="fa-solid fa-moon"></i></button>
  </div>

  <?php if (!empty($errors)): ?>
    <div class="error-box"><ul><?php foreach ($errors as $err) echo "<li>$err</li>"; ?></ul></div>
  <?php endif; ?>

  <form action="" method="POST" enctype="multipart/form-data" class="form-card" id="editForm" onsubmit="return prepareSubmit()">
    <div class="form-group">
      <label><i class="fa-regular fa-message"></i> Tweet Content</label>
      <textarea name="status" rows="4" required><?php echo htmlspecialchars($tweet['status']); ?></textarea>
    </div>

    <!-- Current Images with Mark for Deletion -->
    <div class="form-group">
      <label><i class="fa-regular fa-image"></i> Current Images (click to enlarge)</label>
      <div id="currentImagesContainer" class="images-preview">
        <?php if (!empty($currentImages)): ?>
          <?php foreach ($currentImages as $img): ?>
            <div class="image-item" data-image="<?php echo htmlspecialchars($img); ?>">
              <a href="../assets/images/tweets/<?php echo urlencode($img); ?>" data-lightbox="edit-images" data-title="Tweet image">
                <img src="../assets/images/tweets/<?php echo urlencode($img); ?>" alt="image">
              </a>
              <button type="button" class="delete-image-btn" onclick="markImageForDeletion(this)"><i class="fa-solid fa-trash"></i></button>
              <button type="button" class="restore-image-btn" onclick="unmarkImageForDeletion(this)"><i class="fa-solid fa-undo"></i></button>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <p class="help-text">No images uploaded yet.</p>
        <?php endif; ?>
      </div>
      <input type="hidden" name="delete_images_list" id="deleteImagesList" value="">
      <p class="help-text">Click the trash icon to mark an image for deletion. It will be removed when you save.</p>
    </div>

    <!-- New Image Upload with Preview -->
    <div class="form-group">
      <label><i class="fa-regular fa-images"></i> Add New Images</label>
      <input type="file" name="new_images[]" id="newImagesInput" accept="image/*" multiple>
      <div id="newImagesPreview" class="new-preview"></div>
      <p class="help-text">Select multiple images (JPG, PNG, GIF, WEBP).</p>
    </div>

    <!-- Current Videos with Mark for Deletion -->
    <div class="form-group">
      <label><i class="fa-regular fa-circle-play"></i> Current Videos</label>
      <div id="currentVideosContainer" class="videos-preview">
        <?php if (!empty($currentVideos)): ?>
          <?php foreach ($currentVideos as $video): ?>
            <div class="video-item" data-video="<?php echo htmlspecialchars($video); ?>">
              <video controls>
                <source src="../assets/videos/tweets/<?php echo htmlspecialchars($video); ?>" type="video/mp4">
              </video>
              <button type="button" class="delete-video-btn" onclick="markVideoForDeletion(this)"><i class="fa-solid fa-trash"></i></button>
              <button type="button" class="restore-video-btn" onclick="unmarkVideoForDeletion(this)"><i class="fa-solid fa-undo"></i></button>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <p class="help-text">No videos uploaded yet.</p>
        <?php endif; ?>
      </div>
      <input type="hidden" name="delete_videos_list" id="deleteVideosList" value="">
      <p class="help-text">Click the trash icon to mark a video for deletion. It will be removed when you save.</p>
    </div>

    <!-- New Video Upload with Preview (Multiple) -->
    <div class="form-group">
      <label><i class="fa-regular fa-video"></i> Add New Videos</label>
      <input type="file" name="new_videos[]" id="newVideosInput" accept="video/mp4,video/webm,video/ogg,video/quicktime" multiple>
      <div id="newVideosPreview" class="new-preview" style="display:none;"></div>
      <p class="help-text">Supported: MP4, WebM, OGG, MOV. You can upload multiple videos.</p>
    </div>

    <div class="button-group">
      <button type="submit" name="submit_update" class="btn-save"><i class="fa-regular fa-floppy-disk"></i> Save Changes</button>
      <a href="tweets.php" class="btn-cancel"><i class="fa-solid fa-times"></i> Cancel</a>
    </div>
  </form>
</main>

<script>
// Track images marked for deletion
let imagesToDelete = [];

function markImageForDeletion(btn) {
    const imageItem = $(btn).closest('.image-item');
    const imageName = imageItem.data('image');
    if (!imagesToDelete.includes(imageName)) {
        imagesToDelete.push(imageName);
    }
    imageItem.addClass('marked-for-delete');
    updateDeleteImagesList();
}

function unmarkImageForDeletion(btn) {
    const imageItem = $(btn).closest('.image-item');
    const imageName = imageItem.data('image');
    imagesToDelete = imagesToDelete.filter(name => name !== imageName);
    imageItem.removeClass('marked-for-delete');
    updateDeleteImagesList();
}

function updateDeleteImagesList() {
    $('#deleteImagesList').val(imagesToDelete.join(','));
}

// Track videos marked for deletion
let videosToDelete = [];

function markVideoForDeletion(btn) {
    const videoItem = $(btn).closest('.video-item');
    const videoName = videoItem.data('video');
    if (!videosToDelete.includes(videoName)) {
        videosToDelete.push(videoName);
    }
    videoItem.addClass('marked-for-delete');
    updateDeleteVideosList();
}

function unmarkVideoForDeletion(btn) {
    const videoItem = $(btn).closest('.video-item');
    const videoName = videoItem.data('video');
    videosToDelete = videosToDelete.filter(name => name !== videoName);
    videoItem.removeClass('marked-for-delete');
    updateDeleteVideosList();
}

function updateDeleteVideosList() {
    $('#deleteVideosList').val(videosToDelete.join(','));
}

function prepareSubmit() {
    updateDeleteImagesList();
    updateDeleteVideosList();
    return true;
}

// Preview new images before upload
$('#newImagesInput').on('change', function(e) {
    const files = Array.from(e.target.files);
    const $preview = $('#newImagesPreview');
    $preview.empty();
    files.forEach((file, index) => {
        const reader = new FileReader();
        reader.onload = function(ev) {
            const $item = $(`<div class="new-preview-item" data-index="${index}">
                <img src="${ev.target.result}" alt="Preview">
                <button type="button" class="remove-new" data-index="${index}"><i class="fa-solid fa-times"></i></button>
            </div>`);
            $item.find('.remove-new').on('click', function() {
                const dataTransfer = new DataTransfer();
                const currentFiles = Array.from($('#newImagesInput')[0].files);
                const newFiles = currentFiles.filter((_, i) => i != index);
                newFiles.forEach(f => dataTransfer.items.add(f));
                $('#newImagesInput')[0].files = dataTransfer.files;
                $item.remove();
                $('#newImagesInput').trigger('change');
            });
            $preview.append($item);
        };
        reader.readAsDataURL(file);
    });
});

// Preview new videos before upload
$('#newVideosInput').on('change', function(e) {
    const files = Array.from(e.target.files);
    const $preview = $('#newVideosPreview');
    $preview.empty().show();
    files.forEach((file, index) => {
        const reader = new FileReader();
        reader.onload = function(ev) {
            const $item = $(`<div class="new-preview-item" data-index="${index}">
                <video controls style="width:150px; border-radius:10px;">
                    <source src="${ev.target.result}" type="${file.type}">
                </video>
                <button type="button" class="remove-new" data-index="${index}"><i class="fa-solid fa-times"></i></button>
            </div>`);
            $item.find('.remove-new').on('click', function() {
                const dataTransfer = new DataTransfer();
                const currentFiles = Array.from($('#newVideosInput')[0].files);
                const newFiles = currentFiles.filter((_, i) => i != index);
                newFiles.forEach(f => dataTransfer.items.add(f));
                $('#newVideosInput')[0].files = dataTransfer.files;
                $item.remove();
                if (newFiles.length === 0) $('#newVideosPreview').hide();
                $('#newVideosInput').trigger('change');
            });
            $preview.append($item);
        };
        reader.readAsDataURL(file);
    });
});

// Lightbox Configuration
lightbox.option({
    'resizeDuration': 200,
    'wrapAround': true,
    'showImageNumberLabel': true,
    'albumLabel': 'Image %1 of %2',
    'fadeDuration': 300,
    'imageFadeDuration': 300
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
</script>
</body>
</html>