<?php
require_once '../core/init.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: index.php');
    exit;
}

$pdo = Connect::connect();

// Function to get accurate audio duration using FFmpeg
function getAudioDuration($filePath) {
    if (!function_exists('exec')) {
        return 0;
    }
    
    // Update this path to your FFmpeg location
    $ffmpeg = 'C:\\ffmpeg\\ffmpeg.exe';
    
    if (!file_exists($ffmpeg)) {
        // Try to find ffmpeg in PATH
        $ffmpeg = 'ffmpeg';
    }
    
    $cmd = "\"$ffmpeg\" -i " . escapeshellarg($filePath) . " 2>&1";
    exec($cmd, $output);
    
    foreach ($output as $line) {
        if (preg_match('/Duration: (\d{2}):(\d{2}):(\d{2}\.\d+)/', $line, $matches)) {
            $hours = (int)$matches[1];
            $minutes = (int)$matches[2];
            $seconds = (float)$matches[3];
            $totalSeconds = ($hours * 3600) + ($minutes * 60) + $seconds;
            return (int)round($totalSeconds);
        }
    }
    return 0;
}

// Handle Add Music
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_music'])) {
    $title = trim($_POST['title'] ?? '');
    $artist = trim($_POST['artist'] ?? '');
    $duration = 0; // Will be auto-detected
    $cover_art = null;
    $file_path = null;
    
    if (empty($title) || empty($artist)) {
        $_SESSION['error'] = "Title and artist are required";
        header("Location: manage_music.php");
        exit;
    }
    
    // Handle music file upload
    if (isset($_FILES['music_file']) && $_FILES['music_file']['error'] === 0) {
        $file = $_FILES['music_file'];
        $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowedAudio = ['mp3', 'wav', 'ogg', 'm4a'];
        
        if (!in_array($fileExt, $allowedAudio)) {
            $_SESSION['error'] = "Invalid audio format. Allowed: MP3, WAV, OGG, M4A";
            header("Location: manage_music.php");
            exit;
        }
        
        $maxSize = 10 * 1024 * 1024; // 10MB max
        if ($file['size'] > $maxSize) {
            $_SESSION['error'] = "Audio file must be less than 10MB";
            header("Location: manage_music.php");
            exit;
        }
        
        // Generate unique filename
        $newFileName = 'music-' . uniqid() . '.' . $fileExt;
        $uploadPath = '../assets/music/' . $newFileName;
        
        if (!file_exists('../assets/music')) {
            mkdir('../assets/music', 0777, true);
        }
        
        if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
            $file_path = $newFileName;
            
            // Auto-detect duration using FFmpeg
            $duration = getAudioDuration($uploadPath);
            
            if ($duration <= 0) {
                // Fallback: estimate based on file size (rough)
                $duration = round(($file['size'] * 8) / (128 * 1024));
                $duration = max(10, min(600, $duration));
                $_SESSION['warning'] = "Could not detect duration accurately. Estimated: " . gmdate("i:s", $duration);
            }
        } else {
            $_SESSION['error'] = "Failed to upload audio file";
            header("Location: manage_music.php");
            exit;
        }
    } else {
        $_SESSION['error'] = "Please select an audio file";
        header("Location: manage_music.php");
        exit;
    }
    
    // Handle cover art upload
    if (isset($_FILES['cover_art']) && $_FILES['cover_art']['error'] === 0) {
        $coverFile = $_FILES['cover_art'];
        $coverExt = strtolower(pathinfo($coverFile['name'], PATHINFO_EXTENSION));
        $allowedImages = ['jpg', 'jpeg', 'png', 'webp'];
        
        if (in_array($coverExt, $allowedImages)) {
            $coverFileName = 'cover-' . uniqid() . '.' . $coverExt;
            $coverUploadPath = '../assets/music/covers/' . $coverFileName;
            
            if (!file_exists('../assets/music/covers')) {
                mkdir('../assets/music/covers', 0777, true);
            }
            
            if (move_uploaded_file($coverFile['tmp_name'], $coverUploadPath)) {
                $cover_art = 'assets/music/covers/' . $coverFileName;
            }
        }
    }
    
    // Insert into database
    $stmt = $pdo->prepare("
        INSERT INTO music_library (title, artist, file_path, cover_art, duration, usage_count, created_at)
        VALUES (?, ?, ?, ?, ?, 0, NOW())
    ");
    
    if ($stmt->execute([$title, $artist, $file_path, $cover_art, $duration])) {
        $_SESSION['success'] = "Music added successfully! Duration: " . gmdate("i:s", $duration);
    } else {
        $_SESSION['error'] = "Failed to add music to database";
        // Delete uploaded file if database insert fails
        if ($file_path && file_exists('../assets/music/' . $file_path)) {
            unlink('../assets/music/' . $file_path);
        }
    }
    
    header("Location: manage_music.php");
    exit;
}

// Handle Delete Music
if (isset($_GET['delete'])) {
    $music_id = (int)$_GET['delete'];
    
    // Get file paths before deleting
    $stmt = $pdo->prepare("SELECT file_path, cover_art FROM music_library WHERE id = ?");
    $stmt->execute([$music_id]);
    $music = $stmt->fetch(PDO::FETCH_OBJ);
    
    if ($music) {
        // Delete audio file
        if ($music->file_path && file_exists('../assets/music/' . $music->file_path)) {
            unlink('../assets/music/' . $music->file_path);
        }
        
        // Delete cover art if exists
        if ($music->cover_art && file_exists('../' . $music->cover_art)) {
            unlink('../' . $music->cover_art);
        }
        
        // Delete from database
        $stmt = $pdo->prepare("DELETE FROM music_library WHERE id = ?");
        if ($stmt->execute([$music_id])) {
            $_SESSION['success'] = "Music deleted successfully!";
        } else {
            $_SESSION['error'] = "Failed to delete music";
        }
    } else {
        $_SESSION['error'] = "Music not found";
    }
    
    header("Location: manage_music.php");
    exit;
}

// Handle Edit Music
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_music'])) {
    $music_id = (int)$_POST['music_id'];
    $title = trim($_POST['title'] ?? '');
    $artist = trim($_POST['artist'] ?? '');
    
    if (empty($title) || empty($artist)) {
        $_SESSION['error'] = "Title and artist are required";
        header("Location: manage_music.php");
        exit;
    }
    
    // Update basic info (duration will be updated if new file uploaded)
    $stmt = $pdo->prepare("UPDATE music_library SET title = ?, artist = ? WHERE id = ?");
    $stmt->execute([$title, $artist, $music_id]);
    
    // Handle new music file upload (optional)
    if (isset($_FILES['music_file']) && $_FILES['music_file']['error'] === 0) {
        $file = $_FILES['music_file'];
        $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowedAudio = ['mp3', 'wav', 'ogg', 'm4a'];
        
        if (in_array($fileExt, $allowedAudio) && $file['size'] <= 10 * 1024 * 1024) {
            // Get old file path
            $stmt = $pdo->prepare("SELECT file_path FROM music_library WHERE id = ?");
            $stmt->execute([$music_id]);
            $oldMusic = $stmt->fetch(PDO::FETCH_OBJ);
            
            // Delete old file
            if ($oldMusic && $oldMusic->file_path && file_exists('../assets/music/' . $oldMusic->file_path)) {
                unlink('../assets/music/' . $oldMusic->file_path);
            }
            
            // Upload new file
            $newFileName = 'music-' . uniqid() . '.' . $fileExt;
            $uploadPath = '../assets/music/' . $newFileName;
            
            if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
                $stmt = $pdo->prepare("UPDATE music_library SET file_path = ? WHERE id = ?");
                $stmt->execute([$newFileName, $music_id]);
                
                // Auto-detect duration for new file
                $newDuration = getAudioDuration($uploadPath);
                if ($newDuration > 0) {
                    $stmt = $pdo->prepare("UPDATE music_library SET duration = ? WHERE id = ?");
                    $stmt->execute([$newDuration, $music_id]);
                    $_SESSION['success'] = "Music updated successfully! New duration: " . gmdate("i:s", $newDuration);
                } else {
                    $_SESSION['success'] = "Music updated successfully! (Duration could not be auto-detected)";
                }
            } else {
                $_SESSION['error'] = "Failed to upload new audio file";
            }
        }
    }
    
    // Handle new cover art upload (optional)
    if (isset($_FILES['cover_art']) && $_FILES['cover_art']['error'] === 0) {
        $coverFile = $_FILES['cover_art'];
        $coverExt = strtolower(pathinfo($coverFile['name'], PATHINFO_EXTENSION));
        $allowedImages = ['jpg', 'jpeg', 'png', 'webp'];
        
        if (in_array($coverExt, $allowedImages)) {
            // Get old cover art
            $stmt = $pdo->prepare("SELECT cover_art FROM music_library WHERE id = ?");
            $stmt->execute([$music_id]);
            $oldMusic = $stmt->fetch(PDO::FETCH_OBJ);
            
            // Delete old cover
            if ($oldMusic && $oldMusic->cover_art && file_exists('../' . $oldMusic->cover_art)) {
                unlink('../' . $oldMusic->cover_art);
            }
            
            // Upload new cover
            $coverFileName = 'cover-' . uniqid() . '.' . $coverExt;
            $coverUploadPath = '../assets/music/covers/' . $coverFileName;
            
            if (!file_exists('../assets/music/covers')) {
                mkdir('../assets/music/covers', 0777, true);
            }
            
            if (move_uploaded_file($coverFile['tmp_name'], $coverUploadPath)) {
                $cover_art = 'assets/music/covers/' . $coverFileName;
                $stmt = $pdo->prepare("UPDATE music_library SET cover_art = ? WHERE id = ?");
                $stmt->execute([$cover_art, $music_id]);
            }
        }
    }
    
    if (!isset($_SESSION['success'])) {
        $_SESSION['success'] = "Music updated successfully!";
    }
    
    header("Location: manage_music.php");
    exit;
}

// Fetch all music
$stmt = $pdo->query("SELECT * FROM music_library ORDER BY usage_count DESC, created_at DESC");
$musicLibrary = $stmt->fetchAll(PDO::FETCH_OBJ);
?>

<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Manage Music Library · Admin Panel</title>
<link rel="icon" href="../assets/images/twitter-logo.png" type="image/png">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root { --primary: #1DA1F2; --secondary: #14171A; --light: #f5f8fa; --white: #fff; --gray: #657786; --danger: #e0245e; --success: #17bf63; --warning: #f5a623; }
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

/* Button */
.btn-primary { background: var(--primary); color: white; border: none; padding: 10px 20px; border-radius: 25px; cursor: pointer; font-weight: 600; transition: 0.3s; }
.btn-primary:hover { opacity: 0.9; transform: translateY(-2px); }
.btn-danger { background: var(--danger); color: white; border: none; padding: 6px 12px; border-radius: 5px; cursor: pointer; }
.btn-warning { background: var(--warning); color: white; border: none; padding: 6px 12px; border-radius: 5px; cursor: pointer; }
.btn-sm { padding: 4px 10px; font-size: 12px; }

/* Modal */
.modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center; }
.modal.active { display: flex; }
.modal-content { background: var(--white); border-radius: 15px; padding: 25px; width: 90%; max-width: 500px; max-height: 85vh; overflow-y: auto; }
.modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
.modal-header h3 { font-size: 20px; }
.close-modal { background: none; border: none; font-size: 24px; cursor: pointer; color: var(--gray); }
.form-group { margin-bottom: 15px; }
.form-group label { display: block; margin-bottom: 5px; font-weight: 600; font-size: 14px; }
.form-group input, .form-group textarea { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 8px; background: var(--white); color: var(--secondary); }
.form-group input:focus { outline: none; border-color: var(--primary); }

/* Table */
table { width: 100%; border-collapse: collapse; background: var(--white); border-radius: 10px; overflow: hidden; box-shadow: 0 4px 10px rgba(0,0,0,0.05); }
table th, table td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #eee; font-size: 14px; }
table th { background: var(--primary); color: #fff; font-weight: 600; }
table td img.cover-preview { width: 40px; height: 40px; border-radius: 8px; object-fit: cover; }
.music-preview { display: flex; align-items: center; gap: 10px; }
.music-preview audio { height: 35px; }
.actions button { margin-right: 5px; padding: 5px 10px; border: none; border-radius: 5px; cursor: pointer; font-size: 12px; }
.usage-badge { background: var(--primary); color: white; padding: 2px 8px; border-radius: 20px; font-size: 12px; font-weight: 600; }
.alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
.alert-success { background: rgba(23, 191, 99, 0.2); border: 1px solid var(--success); color: var(--success); }
.alert-error { background: rgba(224, 36, 94, 0.2); border: 1px solid var(--danger); color: var(--danger); }
.alert-warning { background: rgba(245, 166, 35, 0.2); border: 1px solid var(--warning); color: var(--warning); }

@media (max-width: 768px) {
    .main-content { margin-left: 0; padding: 15px; }
    .sidebar { transform: translateX(-100%); transition: transform 0.3s; z-index: 100; }
    .sidebar.open { transform: translateX(0); }
    table, table tbody, table tr, table td { display: block; width: 100%; }
    table thead { display: none; }
    table td { padding: 10px; border-bottom: 1px solid #eee; }
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
      <a href="manage_music.php" class="active"><i class="fa-solid fa-music"></i> Music Library</a>
      <a href="manage_reels.php"><i class="fa-solid fa-film"></i> Manage Reels</a>
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
      <h1><i class="fa-solid fa-music"></i> Music Library</h1>
      <p style="color: var(--gray); margin-top: 5px;">Manage background music for reels</p>
    </div>
    <div>
      <button id="themeToggle" class="theme-toggle"><i class="fa-solid fa-moon"></i></button>
      <button id="addMusicBtn" class="btn-primary" style="margin-left: 10px;"><i class="fa-solid fa-plus"></i> Add Music</button>
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
  
  <?php if (isset($_SESSION['warning'])): ?>
    <div class="alert alert-warning">
      <i class="fa-solid fa-triangle-exclamation"></i> <?php echo $_SESSION['warning']; unset($_SESSION['warning']); ?>
    </div>
  <?php endif; ?>

  <table>
    <thead>
      <tr>
        <th>ID</th>
        <th>Cover</th>
        <th>Title</th>
        <th>Artist</th>
        <th>Duration</th>
        <th>Preview</th>
        <th>Usage</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if ($musicLibrary): ?>
        <?php foreach ($musicLibrary as $music): ?>
          <tr>
            <td><?php echo $music->id; ?></td>
            <td>
              <?php if ($music->cover_art): ?>
                <img src="../<?php echo $music->cover_art; ?>" class="cover-preview" alt="cover">
              <?php else: ?>
                <i class="fa-solid fa-music" style="font-size: 30px; color: var(--primary);"></i>
              <?php endif; ?>
            </td>
            <td><strong><?php echo htmlspecialchars($music->title); ?></strong></td>
            <td><?php echo htmlspecialchars($music->artist ?: 'Unknown'); ?></td>
            <td><?php echo gmdate("i:s", $music->duration); ?></td>
            <td class="music-preview">
              <audio controls preload="none">
                <source src="../assets/music/<?php echo $music->file_path; ?>" type="audio/mpeg">
              </audio>
            </td>
            <td><span class="usage-badge"><?php echo $music->usage_count; ?> uses</span></td>
            <td class="actions">
              <button class="btn-warning btn-sm edit-music" data-id="<?php echo $music->id; ?>" data-title="<?php echo htmlspecialchars($music->title); ?>" data-artist="<?php echo htmlspecialchars($music->artist); ?>" data-duration="<?php echo $music->duration; ?>"><i class="fa-solid fa-pen"></i> Edit</button>
              <a href="manage_music.php?delete=<?php echo $music->id; ?>" onclick="return confirm('Are you sure you want to delete this music? This will remove it from all reels that use it.');"><button class="btn-danger btn-sm"><i class="fa-solid fa-trash"></i> Delete</button></a>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php else: ?>
        <tr><td colspan="8" style="text-align:center;">No music found. Click "Add Music" to upload.</td></tr>
      <?php endif; ?>
    </tbody>
  追赶
</main>

<!-- Add Music Modal -->
<div id="addMusicModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h3><i class="fa-solid fa-plus"></i> Add New Music</h3>
      <button class="close-modal">&times;</button>
    </div>
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="add_music" value="1">
      
      <div class="form-group">
        <label>Title *</label>
        <input type="text" name="title" required placeholder="e.g., Blinding Lights">
      </div>
      
      <div class="form-group">
        <label>Artist *</label>
        <input type="text" name="artist" required placeholder="e.g., The Weeknd">
      </div>
      
      <div class="form-group">
        <label>Audio File * (MP3, WAV, OGG, M4A - Max 10MB)</label>
        <input type="file" name="music_file" accept="audio/mp3,audio/wav,audio/ogg,audio/m4a" required>
        <small style="color: var(--gray);">Duration will be auto-detected from the audio file</small>
      </div>
      
      <div class="form-group">
        <label>Cover Art (JPG, PNG, WEBP - Optional)</label>
        <input type="file" name="cover_art" accept="image/jpeg,image/png,image/webp">
      </div>
      
      <button type="submit" class="btn-primary" style="width: 100%;"><i class="fa-solid fa-upload"></i> Upload Music</button>
    </form>
  </div>
</div>

<!-- Edit Music Modal -->
<div id="editMusicModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h3><i class="fa-solid fa-pen"></i> Edit Music</h3>
      <button class="close-modal">&times;</button>
    </div>
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="edit_music" value="1">
      <input type="hidden" name="music_id" id="edit_music_id">
      
      <div class="form-group">
        <label>Title *</label>
        <input type="text" name="title" id="edit_title" required>
      </div>
      
      <div class="form-group">
        <label>Artist *</label>
        <input type="text" name="artist" id="edit_artist" required>
      </div>
      
      <div class="form-group">
        <label>Replace Audio File (Optional - MP3, WAV, OGG, M4A)</label>
        <input type="file" name="music_file" accept="audio/mp3,audio/wav,audio/ogg,audio/m4a">
        <small style="color: var(--gray);">Leave empty to keep existing audio. Duration will auto-update if new file uploaded.</small>
      </div>
      
      <div class="form-group">
        <label>Replace Cover Art (Optional - JPG, PNG, WEBP)</label>
        <input type="file" name="cover_art" accept="image/jpeg,image/png,image/webp">
        <small style="color: var(--gray);">Leave empty to keep existing cover</small>
      </div>
      
      <button type="submit" class="btn-primary" style="width: 100%;"><i class="fa-solid fa-save"></i> Save Changes</button>
    </form>
  </div>
</div>

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

// Add Music Modal
const addModal = document.getElementById('addMusicModal');
const addBtn = document.getElementById('addMusicBtn');
const closeBtns = document.querySelectorAll('.close-modal');

addBtn.addEventListener('click', () => {
  addModal.classList.add('active');
});

// Edit Music Modal
const editModal = document.getElementById('editMusicModal');
const editBtns = document.querySelectorAll('.edit-music');

editBtns.forEach(btn => {
  btn.addEventListener('click', () => {
    const id = btn.dataset.id;
    const title = btn.dataset.title;
    const artist = btn.dataset.artist;
    
    document.getElementById('edit_music_id').value = id;
    document.getElementById('edit_title').value = title;
    document.getElementById('edit_artist').value = artist;
    
    editModal.classList.add('active');
  });
});

// Close modals
closeBtns.forEach(btn => {
  btn.addEventListener('click', () => {
    addModal.classList.remove('active');
    editModal.classList.remove('active');
  });
});

// Close modal when clicking outside
window.addEventListener('click', (e) => {
  if (e.target === addModal) addModal.classList.remove('active');
  if (e.target === editModal) editModal.classList.remove('active');
});
</script>
</body>
</html>