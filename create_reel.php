<?php
require_once 'core/init.php';

if (!User::checkLogIn()) {
    header("Location: index.php");
    exit;
}

$pdo = Connect::connect();
$user_id = $_SESSION['user_id'];
$user = User::getData($user_id);

// FFmpeg path for Windows
define('FFMPEG_PATH', 'C:\\ffmpeg\\ffmpeg.exe');

// Function to remove audio from video using FFmpeg
function removeAudioFromVideo($inputPath, $outputPath) {
    if (!function_exists('exec')) {
        return false;
    }
    
    $ffmpeg = FFMPEG_PATH;
    // Remove audio track (-an) and copy video codec (-c:v copy)
    $cmd = "\"$ffmpeg\" -i " . escapeshellarg($inputPath) . " -an -c:v copy " . escapeshellarg($outputPath) . " -y 2>&1";
    exec($cmd, $output, $returnCode);
    
    return $returnCode === 0;
}

// Function to check if video has audio track
function hasVideoAudio($filePath) {
    if (function_exists('exec')) {
        $ffmpeg = FFMPEG_PATH;
        $cmd = "\"$ffmpeg\" -i " . escapeshellarg($filePath) . " 2>&1";
        exec($cmd, $output);
        $outputStr = implode("\n", $output);
        
        if (preg_match('/Audio:\s*(\w+)/', $outputStr, $matches)) {
            return true;
        }
    }
    return false;
}

// Function to get video duration using ffmpeg
function getVideoDuration($filePath) {
    if (function_exists('exec')) {
        $ffmpeg = FFMPEG_PATH;
        $cmd = "\"$ffmpeg\" -i " . escapeshellarg($filePath) . " 2>&1";
        exec($cmd, $output);
        
        foreach ($output as $line) {
            if (preg_match('/Duration: (\d{2}):(\d{2}):(\d{2}\.\d+)/', $line, $matches)) {
                $hours = $matches[1];
                $minutes = $matches[2];
                $seconds = $matches[3];
                return ($hours * 3600) + ($minutes * 60) + $seconds;
            }
        }
    }
    return 0;
}

// Handle reel upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_reel'])) {
    $caption = trim($_POST['caption'] ?? '');
    $music_id = isset($_POST['music_id']) && $_POST['music_id'] !== '' ? (int)$_POST['music_id'] : null;
    $music_start = (int)($_POST['music_start'] ?? 0);
    $music_end = isset($_POST['music_end']) && $_POST['music_end'] !== '' ? (int)$_POST['music_end'] : null;
    $video_muted = (isset($_POST['video_muted']) && $_POST['video_muted'] == '1') ? 1 : 0;
    
    // Handle media upload
    $mediaPath = null;
    $mediaType = null;
    $duration = 0;
    $hasAudio = false;
    $originalMediaPath = null;
    
    if (isset($_FILES['reel_media']) && $_FILES['reel_media']['error'] === 0) {
        $file = $_FILES['reel_media'];
        $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowedVideo = ['mp4', 'mov', 'webm', 'avi', 'm4v'];
        $allowedImage = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        
        if (in_array($fileExt, $allowedVideo)) {
            $mediaType = 'video';
            $maxSize = 50 * 1024 * 1024; // 50MB max for video
            if ($file['size'] > $maxSize) {
                $_SESSION['error'] = "Video file must be less than 50MB";
                header("Location: create_reel.php");
                exit;
            }
            
            // Get video duration and check for existing audio
            $duration = getVideoDuration($file['tmp_name']);
            $hasAudio = hasVideoAudio($file['tmp_name']);
            
            if ($duration > 60) {
                $_SESSION['error'] = "Reel must be 60 seconds or less. Current: " . round($duration) . " seconds";
                header("Location: create_reel.php");
                exit;
            }
            
            // RULE 1: If user mutes the video, they MUST select background music
            if ($video_muted == 1 && !$music_id) {
                $_SESSION['error'] = "You must select background music when muting your video";
                header("Location: create_reel.php");
                exit;
            }
            
            // RULE 2: If user wants to add music without muting, they must mute first
            if ($hasAudio && $music_id && $video_muted != 1) {
                $_SESSION['error'] = "Please mute your video first to add background music";
                header("Location: create_reel.php");
                exit;
            }
            
            // Save the uploaded file temporarily
            $tempFileName = 'temp-' . uniqid() . '.' . $fileExt;
            $tempUploadPath = 'assets/reels/' . $tempFileName;
            
            if (!file_exists('assets/reels')) {
                mkdir('assets/reels', 0777, true);
            }
            
            if (!move_uploaded_file($file['tmp_name'], $tempUploadPath)) {
                $_SESSION['error'] = "Failed to upload file";
                header("Location: create_reel.php");
                exit;
            }
            
            // If video is muted, remove the audio track
            if ($video_muted == 1) {
                $finalFileName = 'reel-' . uniqid() . '.mp4';
                $finalUploadPath = 'assets/reels/' . $finalFileName;
                
                // Remove audio from video
                if (removeAudioFromVideo($tempUploadPath, $finalUploadPath)) {
                    $mediaPath = $finalFileName;
                    // Delete the temporary file
                    if (file_exists($tempUploadPath)) {
                        unlink($tempUploadPath);
                    }
                } else {
                    // If FFmpeg fails, use the original file (but mark as muted in DB)
                    $mediaPath = $tempFileName;
                    $_SESSION['warning'] = "Could not remove audio, but video will be marked as muted";
                }
            } else {
                // Keep original video with audio
                $mediaPath = $tempFileName;
            }
            
        } elseif (in_array($fileExt, $allowedImage)) {
            $mediaType = 'image';
            $maxSize = 10 * 1024 * 1024; // 10MB for images
            if ($file['size'] > $maxSize) {
                $_SESSION['error'] = "Image file must be less than 10MB";
                header("Location: create_reel.php");
                exit;
            }
            $duration = 5; // Default 5 seconds for images
            $hasAudio = false;
            
            // For images, if user selects music, it's fine (no audio to mute)
            $newFileName = 'reel-' . uniqid() . '.' . $fileExt;
            $uploadPath = 'assets/reels/' . $newFileName;
            
            if (!file_exists('assets/reels')) {
                mkdir('assets/reels', 0777, true);
            }
            
            if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
                $mediaPath = $newFileName;
            } else {
                $_SESSION['error'] = "Failed to upload file";
                header("Location: create_reel.php");
                exit;
            }
        } else {
            $_SESSION['error'] = "Invalid file format";
            header("Location: create_reel.php");
            exit;
        }
    } else {
        $_SESSION['error'] = "Please select a video or image";
        header("Location: create_reel.php");
        exit;
    }
    
    // Save to database
    $stmt = $pdo->prepare("
        INSERT INTO reels (user_id, media_path, media_type, caption, music_id, music_start_time, music_end_time, duration, video_muted, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$user_id, $mediaPath, $mediaType, $caption, $music_id, $music_start, $music_end, $duration, $video_muted]);
    
    // Update music usage count
    if ($music_id) {
        $stmt = $pdo->prepare("UPDATE music_library SET usage_count = usage_count + 1 WHERE id = ?");
        $stmt->execute([$music_id]);
    }
    
    $_SESSION['success'] = "Reel created successfully!";
    header("Location: reels.php");
    exit;
}

// Get music library for selection
$stmt = $pdo->query("SELECT * FROM music_library ORDER BY usage_count DESC LIMIT 100");
$musicLibrary = $stmt->fetchAll(PDO::FETCH_OBJ);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Create Reel</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script src="https://unpkg.com/wavesurfer.js@7.4.0/dist/wavesurfer.min.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: #0a0a0c;
            color: white;
            min-height: 100vh;
        }
        
        .container {
            max-width: 500px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .header {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .back-btn {
            width: 40px;
            height: 40px;
            background: rgba(255,255,255,0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            color: white;
        }
        
        .back-btn:hover {
            background: rgba(255,255,255,0.2);
        }
        
        h1 {
            font-size: 24px;
            font-weight: 700;
        }
        
        /* Media Preview */
        .media-preview {
            background: #1a1a1f;
            border-radius: 20px;
            overflow: hidden;
            margin-bottom: 20px;
            aspect-ratio: 9 / 16;
            position: relative;
        }
        
        .media-preview video,
        .media-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .remove-media {
            position: absolute;
            top: 12px;
            right: 12px;
            width: 36px;
            height: 36px;
            background: rgba(0,0,0,0.7);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
            z-index: 10;
        }
        
        .remove-media:hover {
            background: rgba(231, 76, 60, 0.8);
            transform: scale(1.1);
        }
        
        /* Mute Video Toggle */
        .mute-toggle {
            position: absolute;
            bottom: 12px;
            left: 12px;
            background: rgba(0,0,0,0.7);
            border-radius: 30px;
            padding: 8px 16px;
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            z-index: 10;
            transition: all 0.2s;
            border: 1px solid rgba(255,255,255,0.2);
        }
        
        .mute-toggle:hover {
            background: rgba(0,0,0,0.9);
        }
        
        .mute-toggle i {
            font-size: 14px;
        }
        
        .mute-toggle span {
            font-size: 12px;
        }
        
        .mute-toggle.muted {
            background: rgba(46, 204, 113, 0.8);
        }
        
        .upload-area {
            border: 2px dashed rgba(255,255,255,0.2);
            border-radius: 20px;
            padding: 40px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
            margin-bottom: 20px;
        }
        
        .upload-area:hover {
            border-color: #1da1f2;
            background: rgba(29,161,242,0.05);
        }
        
        .upload-area i {
            font-size: 48px;
            color: #a0a0a8;
            margin-bottom: 12px;
        }
        
        .upload-area p {
            color: #a0a0a8;
            font-size: 14px;
        }
        
        /* Form Groups */
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            font-size: 14px;
        }
        
        textarea, select {
            width: 100%;
            background: #1a1a1f;
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 12px;
            padding: 12px 16px;
            color: white;
            font-size: 14px;
            font-family: inherit;
            resize: vertical;
        }
        
        textarea:focus, select:focus {
            outline: none;
            border-color: #1da1f2;
        }
        
        /* Music Section */
        .music-section {
            background: #1a1a1f;
            border-radius: 16px;
            padding: 16px;
            margin-bottom: 20px;
        }
        
        .music-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
        }
        
        .music-header i {
            font-size: 24px;
            color: #1da1f2;
        }
        
        .music-search {
            position: relative;
            margin-bottom: 16px;
        }
        
        .music-search input {
            width: 100%;
            background: rgba(255,255,255,0.1);
            border: none;
            border-radius: 30px;
            padding: 10px 16px 10px 40px;
            color: white;
            font-size: 14px;
        }
        
        .music-search i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #a0a0a8;
            font-size: 14px;
        }
        
       .music-list {
    max-height: 250px;
    overflow-y: auto;
    overflow-x: hidden;
    border-radius: 12px;
}

/* Custom Scrollbar for WebKit browsers */
.music-list::-webkit-scrollbar {
    width: 4px;
}

.music-list::-webkit-scrollbar-track {
    background: rgba(255,255,255,0.05);
    border-radius: 10px;
}

.music-list::-webkit-scrollbar-thumb {
    background: #1da1f2;
    border-radius: 10px;
}

.music-list::-webkit-scrollbar-thumb:hover {
    background: #0d8bd9;
}

/* Firefox scrollbar */
.music-list {
    scrollbar-width: thin;
    scrollbar-color: #1da1f2 rgba(255,255,255,0.05);
}
        
        .music-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .music-item.disabled {
            opacity: 0.5;
            cursor: not-allowed;
            pointer-events: none;
        }
        
        .music-item:hover {
            background: rgba(255,255,255,0.05);
        }
        
        .music-item.selected {
            background: rgba(29,161,242,0.15);
            border: 1px solid rgba(29,161,242,0.3);
        }
        
        .music-cover {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, #1da1f2, #0d8bd9);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }
        
        .music-cover img {
            width: 100%;
            height: 100%;
            border-radius: 8px;
            object-fit: cover;
        }
        
        .play-preview {
            position: absolute;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.2s;
            cursor: pointer;
        }
        
        .music-cover:hover .play-preview {
            opacity: 1;
        }
        
        .play-preview i {
            font-size: 20px;
            color: white;
        }
        
        .music-info {
            flex: 1;
        }
        
        .music-title {
            font-weight: 600;
            font-size: 14px;
        }
        
        .music-artist {
            font-size: 12px;
            color: #a0a0a8;
        }
        
        .music-duration {
            font-size: 12px;
            color: #a0a0a8;
        }
        
        /* Music Warning */
        .music-warning {
            background: rgba(241, 196, 15, 0.15);
            border: 1px solid #f1c40f;
            border-radius: 10px;
            padding: 10px;
            margin-top: 12px;
            font-size: 12px;
            color: #f1c40f;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .music-required {
            background: rgba(231, 76, 60, 0.15);
            border: 1px solid #e74c3c;
            border-radius: 10px;
            padding: 10px;
            margin-top: 12px;
            font-size: 12px;
            color: #e74c3c;
            display: none;
            align-items: center;
            gap: 8px;
        }
        
        .music-required.show {
            display: flex;
        }
        
        /* Music Player */
        .music-player {
            margin-top: 16px;
            padding: 16px;
            background: rgba(0,0,0,0.3);
            border-radius: 12px;
            display: none;
        }
        
        .music-player.show {
            display: block;
        }
        
        .player-controls {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 12px;
        }
        
        .play-pause-btn {
            width: 40px;
            height: 40px;
            background: #1da1f2;
            border: none;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }
        
        .play-pause-btn:hover {
            transform: scale(1.05);
        }
        
        .waveform-container {
            flex: 1;
            height: 60px;
        }
        
        .time-info {
            font-size: 12px;
            color: #a0a0a8;
            text-align: center;
            margin-top: 8px;
        }
        
        /* Trim Controls */
        .music-trim {
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid rgba(255,255,255,0.1);
            display: none;
        }
        
        .music-trim.show {
            display: block;
        }
        
        .trim-controls {
            display: flex;
            gap: 12px;
            margin-top: 12px;
            flex-wrap: wrap;
        }
        
        .trim-controls input {
            flex: 1;
            background: rgba(255,255,255,0.1);
            border: none;
            border-radius: 20px;
            padding: 10px 12px;
            color: white;
            font-size: 13px;
        }
        
        /* Alert Messages */
        .alert {
            border-radius: 12px;
            padding: 12px 16px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
        }
        
        .alert-success {
            background: rgba(46, 204, 113, 0.2);
            border: 1px solid #2ecc71;
            color: #2ecc71;
        }
        
        .alert-error {
            background: rgba(231, 76, 60, 0.2);
            border: 1px solid #e74c3c;
            color: #e74c3c;
        }
        
        .alert-warning {
            background: rgba(241, 196, 15, 0.2);
            border: 1px solid #f1c40f;
            color: #f1c40f;
        }
        
        /* Submit Button */
        .submit-btn {
            width: 100%;
            background: linear-gradient(135deg, #1da1f2, #0d8bd9);
            border: none;
            border-radius: 30px;
            padding: 14px;
            color: white;
            font-weight: 600;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(29,161,242,0.3);
        }
        
        .duration-badge {
            position: absolute;
            bottom: 10px;
            right: 10px;
            background: rgba(0,0,0,0.7);
            padding: 4px 8px;
            border-radius: 8px;
            font-size: 12px;
        }
        
        .has-audio-badge {
            position: absolute;
            bottom: 10px;
            right: 70px;
            background: rgba(0,0,0,0.7);
            padding: 4px 8px;
            border-radius: 8px;
            font-size: 11px;
        }
        
        .has-audio-badge i {
            color: #f1c40f;
            margin-right: 4px;
        }
        
        @media (max-width: 480px) {
            .container {
                padding: 16px;
            }
            
            .trim-controls input {
                font-size: 12px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <a href="reels.php" class="back-btn">
                <i class="fas fa-arrow-left"></i>
            </a>
            <h1>Create Reel</h1>
        </div>
        
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['warning'])): ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle"></i> <?php echo $_SESSION['warning']; unset($_SESSION['warning']); ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" enctype="multipart/form-data" id="reelForm">
            <!-- Media Upload -->
            <div class="media-preview" id="mediaPreview" style="display: none;">
                <div class="remove-media" id="removeMedia">
                    <i class="fas fa-times"></i>
                </div>
                <div class="mute-toggle" id="muteToggle">
                    <i class="fas fa-volume-up"></i>
                    <span>Mute Video</span>
                </div>
                <video id="videoPreview" controls style="display: none;"></video>
                <img id="imagePreview" style="display: none;">
                <div class="duration-badge" id="durationBadge" style="display: none;"></div>
                <div class="has-audio-badge" id="hasAudioBadge" style="display: none;">
                    <i class="fas fa-music"></i> Has Audio
                </div>
            </div>
            
            <div class="upload-area" id="uploadArea">
                <i class="fas fa-cloud-upload-alt"></i>
                <p>Click or drag to upload</p>
                <p style="font-size: 12px; margin-top: 8px;">MP4, MOV, WebM (Max 60s, 50MB) or JPG, PNG (Max 10MB)</p>
                <input type="file" name="reel_media" id="reelMedia" accept="video/mp4,video/webm,video/quicktime,image/jpeg,image/png,image/gif,image/webp" style="display: none;">
            </div>
            
            <!-- Hidden input for mute status -->
            <input type="hidden" name="video_muted" id="videoMuted" value="0">
            
            <!-- Caption -->
            <div class="form-group">
                <label>Caption (optional)</label>
                <textarea name="caption" rows="3" placeholder="Write something..."></textarea>
            </div>
            
            <!-- Music Section -->
            <div class="music-section">
                <div class="music-header">
                    <i class="fas fa-music"></i>
                    <span style="font-weight: 600;">Add Music</span>
                </div>
                
                <div class="music-search">
                    <i class="fas fa-search"></i>
                    <input type="text" id="musicSearch" placeholder="Search for music...">
                </div>
                
                <div class="music-list" id="musicList">
                    <div class="music-item" data-id="" data-path="">
                        <div class="music-cover">
                            <i class="fas fa-music"></i>
                        </div>
                        <div class="music-info">
                            <div class="music-title">Original Audio</div>
                            <div class="music-artist">Use original video sound</div>
                        </div>
                    </div>
                    
                    <?php foreach ($musicLibrary as $music): ?>
                    <div class="music-item" data-id="<?php echo $music->id; ?>" data-path="<?php echo htmlspecialchars($music->file_path); ?>" data-duration="<?php echo $music->duration; ?>">
                        <div class="music-cover">
                            <?php if ($music->cover_art): ?>
                                <img src="<?php echo $music->cover_art; ?>" alt="">
                            <?php else: ?>
                                <i class="fas fa-music"></i>
                            <?php endif; ?>
                            <div class="play-preview">
                                <i class="fas fa-play"></i>
                            </div>
                        </div>
                        <div class="music-info">
                            <div class="music-title"><?php echo htmlspecialchars($music->title); ?></div>
                            <div class="music-artist"><?php echo htmlspecialchars($music->artist ?: 'Unknown Artist'); ?></div>
                        </div>
                        <div class="music-duration"><?php echo gmdate("i:s", $music->duration); ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Warning message for muting -->
                <div class="music-warning" id="musicWarning" style="display: none;">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span>Please mute your video first to add background music</span>
                </div>
                
                <!-- Required music message when muted -->
                <div class="music-required" id="musicRequired">
                    <i class="fas fa-exclamation-circle"></i>
                    <span>You must select background music when muting your video</span>
                </div>
                
                <!-- Music Player with Waveform -->
                <div class="music-player" id="musicPlayer">
                    <div class="player-controls">
                        <button type="button" class="play-pause-btn" id="playPauseBtn">
                            <i class="fas fa-play"></i>
                        </button>
                        <div class="waveform-container" id="waveformContainer"></div>
                    </div>
                    <div class="time-info" id="timeInfo">0:00 / 0:00</div>
                </div>
                
                <!-- Trim Controls -->
                <div class="music-trim" id="musicTrim">
                    <label style="font-size: 13px;">Trim Music</label>
                    <div class="trim-controls">
                        <input type="number" id="musicStart" placeholder="Start (seconds)" step="0.5" min="0">
                        <input type="number" id="musicEnd" placeholder="End (seconds)" step="0.5">
                    </div>
                    <p style="font-size: 11px; color: #a0a0a8; margin-top: 8px;">Leave empty to use full track</p>
                </div>
            </div>
            
            <button type="submit" name="create_reel" class="submit-btn" id="submitBtn">Post Reel</button>
        </form>
    </div>
    
    <script>
        // ==================== DOM Elements ====================
        const mediaInput = document.getElementById('reelMedia');
        const uploadArea = document.getElementById('uploadArea');
        const mediaPreview = document.getElementById('mediaPreview');
        const videoPreview = document.getElementById('videoPreview');
        const imagePreview = document.getElementById('imagePreview');
        const durationBadge = document.getElementById('durationBadge');
        const hasAudioBadge = document.getElementById('hasAudioBadge');
        const removeMedia = document.getElementById('removeMedia');
        const muteToggle = document.getElementById('muteToggle');
        const videoMuted = document.getElementById('videoMuted');
        const musicWarning = document.getElementById('musicWarning');
        const musicRequired = document.getElementById('musicRequired');
        const submitBtn = document.getElementById('submitBtn');
        
        let videoHasAudio = false;
        let isVideoMuted = false;
        let selectedMusicId = null;
        let selectedMusicPath = null;
        let selectedMusicDuration = 0;
        let wavesurfer = null;
        let isPlaying = false;
        let currentAudio = null;
        
        const musicItems = document.querySelectorAll('.music-item');
        const musicPlayer = document.getElementById('musicPlayer');
        const musicTrim = document.getElementById('musicTrim');
        const playPauseBtn = document.getElementById('playPauseBtn');
        const timeInfo = document.getElementById('timeInfo');
        const musicStart = document.getElementById('musicStart');
        const musicEnd = document.getElementById('musicEnd');
        
        // ==================== File Upload Handling ====================
        uploadArea.addEventListener('click', () => mediaInput.click());
        
        uploadArea.addEventListener('dragover', (e) => {
            e.preventDefault();
            uploadArea.style.borderColor = '#1da1f2';
        });
        
        uploadArea.addEventListener('dragleave', () => {
            uploadArea.style.borderColor = 'rgba(255,255,255,0.2)';
        });
        
        uploadArea.addEventListener('drop', (e) => {
            e.preventDefault();
            uploadArea.style.borderColor = 'rgba(255,255,255,0.2)';
            const file = e.dataTransfer.files[0];
            if (file) handleFile(file);
        });
        
        mediaInput.addEventListener('change', (e) => {
            if (e.target.files[0]) handleFile(e.target.files[0]);
        });
        
        removeMedia.addEventListener('click', () => {
            resetMediaSelection();
        });
        
        function resetMediaSelection() {
            mediaPreview.style.display = 'none';
            uploadArea.style.display = 'block';
            mediaInput.value = '';
            if (videoPreview) {
                videoPreview.pause();
                videoPreview.src = '';
            }
            if (imagePreview) imagePreview.src = '';
            videoHasAudio = false;
            isVideoMuted = false;
            videoMuted.value = '0';
            muteToggle.classList.remove('muted');
            muteToggle.innerHTML = '<i class="fas fa-volume-up"></i><span>Mute Video</span>';
            muteToggle.style.background = 'rgba(0,0,0,0.7)';
            
            // Re-enable music items
            document.querySelectorAll('.music-item').forEach(item => {
                item.classList.remove('disabled');
            });
            musicWarning.style.display = 'none';
            musicRequired.classList.remove('show');
            
            // Clear music selection
            if (selectedMusicId) {
                selectedMusicId = null;
                selectedMusicPath = null;
                musicTrim.classList.remove('show');
                if (wavesurfer) {
                    wavesurfer.destroy();
                    wavesurfer = null;
                }
                musicPlayer.classList.remove('show');
                document.querySelectorAll('.music-item').forEach(i => i.classList.remove('selected'));
            }
        }
        
        function handleFile(file) {
            const fileType = file.type;
            const url = URL.createObjectURL(file);
            
            if (fileType.startsWith('video/')) {
                videoPreview.style.display = 'block';
                imagePreview.style.display = 'none';
                videoPreview.src = url;
                videoPreview.load();
                
                videoPreview.onloadedmetadata = () => {
                    const duration = videoPreview.duration;
                    if (duration > 60) {
                        alert('Video must be 60 seconds or less!');
                        resetMediaSelection();
                        return;
                    }
                    durationBadge.style.display = 'block';
                    durationBadge.textContent = `${Math.floor(duration / 60)}:${Math.floor(duration % 60).toString().padStart(2, '0')}`;
                    
                    // Check for audio (using a flag - in real scenario this would come from server)
                    // For now, assume all videos have audio
                    videoHasAudio = true;
                    hasAudioBadge.style.display = 'block';
                    
                    // If video has audio, require mute before adding music
                    if (videoHasAudio) {
                        document.querySelectorAll('.music-item').forEach(item => {
                            if (item.dataset.id && item.dataset.id !== '') {
                                item.classList.add('disabled');
                            }
                        });
                        musicWarning.style.display = 'flex';
                    }
                };
            } else if (fileType.startsWith('image/')) {
                videoPreview.style.display = 'none';
                imagePreview.style.display = 'block';
                imagePreview.src = url;
                durationBadge.style.display = 'block';
                durationBadge.textContent = '5s (image)';
                hasAudioBadge.style.display = 'none';
                videoHasAudio = false;
                
                // Images don't have audio, so music is allowed
                document.querySelectorAll('.music-item').forEach(item => {
                    item.classList.remove('disabled');
                });
                musicWarning.style.display = 'none';
            } else {
                alert('Unsupported file type');
                return;
            }
            
            mediaPreview.style.display = 'block';
            uploadArea.style.display = 'none';
        }
        
        // ==================== Mute Toggle ====================
        muteToggle.addEventListener('click', () => {
            if (!videoHasAudio) {
                alert('This video has no audio track to mute');
                return;
            }
            
            isVideoMuted = !isVideoMuted;
            videoMuted.value = isVideoMuted ? '1' : '0';
            
            if (isVideoMuted) {
                muteToggle.classList.add('muted');
                muteToggle.innerHTML = '<i class="fas fa-volume-mute"></i><span>Muted</span>';
                muteToggle.style.background = 'rgba(46, 204, 113, 0.8)';
                videoPreview.muted = true;
                
                // Enable music selection
                document.querySelectorAll('.music-item').forEach(item => {
                    item.classList.remove('disabled');
                });
                musicWarning.style.display = 'none';
                
                // Show required music message
                if (!selectedMusicId) {
                    musicRequired.classList.add('show');
                }
            } else {
                muteToggle.classList.remove('muted');
                muteToggle.innerHTML = '<i class="fas fa-volume-up"></i><span>Mute Video</span>';
                muteToggle.style.background = 'rgba(0,0,0,0.7)';
                videoPreview.muted = false;
                
                // Disable music selection
                document.querySelectorAll('.music-item').forEach(item => {
                    if (item.dataset.id && item.dataset.id !== '') {
                        item.classList.add('disabled');
                    }
                });
                musicWarning.style.display = 'flex';
                musicRequired.classList.remove('show');
                
                // Clear music selection if any
                if (selectedMusicId) {
                    selectedMusicId = null;
                    selectedMusicPath = null;
                    musicTrim.classList.remove('show');
                    if (wavesurfer) {
                        wavesurfer.destroy();
                        wavesurfer = null;
                    }
                    musicPlayer.classList.remove('show');
                    document.querySelectorAll('.music-item').forEach(i => i.classList.remove('selected'));
                }
            }
        });
        
        // ==================== Music Selection ====================
        musicItems.forEach(item => {
            item.addEventListener('click', () => {
                // Check if music is disabled (video not muted)
                if (item.classList.contains('disabled')) {
                    alert('Please mute your video first to add background music');
                    return;
                }
                
                musicItems.forEach(i => i.classList.remove('selected'));
                item.classList.add('selected');
                
                selectedMusicId = item.dataset.id;
                selectedMusicPath = item.dataset.path;
                selectedMusicDuration = parseFloat(item.dataset.duration) || 0;
                
                if (selectedMusicId && selectedMusicId !== '') {
                    musicTrim.classList.add('show');
                    loadMusicForPreview(selectedMusicPath);
                    // Hide required music message since music is selected
                    musicRequired.classList.remove('show');
                } else {
                    musicTrim.classList.remove('show');
                    if (wavesurfer) {
                        wavesurfer.destroy();
                        wavesurfer = null;
                    }
                    musicPlayer.classList.remove('show');
                    
                    // If muted and no music selected, show required message
                    if (isVideoMuted) {
                        musicRequired.classList.add('show');
                    }
                }
            });
            
            const playBtn = item.querySelector('.play-preview');
            if (playBtn) {
                playBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    if (item.classList.contains('disabled')) {
                        alert('Please mute your video first to preview music');
                        return;
                    }
                    const path = item.dataset.path;
                    if (path) {
                        previewMusic(path);
                    }
                });
            }
        });
        
        function loadMusicForPreview(musicPath) {
            if (!musicPath) return;
            
            const musicUrl = `assets/music/${musicPath}`;
            
            if (wavesurfer) {
                wavesurfer.destroy();
            }
            
            wavesurfer = WaveSurfer.create({
                container: '#waveformContainer',
                waveColor: '#a0a0a8',
                progressColor: '#1da1f2',
                cursorColor: '#1da1f2',
                barWidth: 2,
                barRadius: 3,
                height: 50,
                responsive: true,
                normalize: true
            });
            
            wavesurfer.load(musicUrl);
            
            wavesurfer.on('ready', () => {
                musicPlayer.classList.add('show');
                const duration = wavesurfer.getDuration();
                timeInfo.textContent = `0:00 / ${formatTime(duration)}`;
                musicStart.max = duration;
                musicEnd.max = duration;
                musicEnd.placeholder = `End (max ${Math.floor(duration)}s)`;
            });
            
            wavesurfer.on('audioprocess', () => {
                const currentTime = wavesurfer.getCurrentTime();
                const duration = wavesurfer.getDuration();
                timeInfo.textContent = `${formatTime(currentTime)} / ${formatTime(duration)}`;
            });
            
            wavesurfer.on('finish', () => {
                isPlaying = false;
                playPauseBtn.innerHTML = '<i class="fas fa-play"></i>';
            });
        }
        
        function previewMusic(musicPath) {
            if (currentAudio && !currentAudio.paused) {
                currentAudio.pause();
                currentAudio = null;
            }
            
            const musicUrl = `assets/music/${musicPath}`;
            currentAudio = new Audio(musicUrl);
            currentAudio.play();
            
            currentAudio.addEventListener('ended', () => {
                currentAudio = null;
            });
            
            setTimeout(() => {
                if (currentAudio) {
                    currentAudio.pause();
                    currentAudio = null;
                }
            }, 5000);
        }
        
        playPauseBtn.addEventListener('click', () => {
            if (wavesurfer) {
                if (isPlaying) {
                    wavesurfer.pause();
                    playPauseBtn.innerHTML = '<i class="fas fa-play"></i>';
                } else {
                    wavesurfer.play();
                    playPauseBtn.innerHTML = '<i class="fas fa-pause"></i>';
                }
                isPlaying = !isPlaying;
            }
        });
        
        function formatTime(seconds) {
            const mins = Math.floor(seconds / 60);
            const secs = Math.floor(seconds % 60);
            return `${mins}:${secs.toString().padStart(2, '0')}`;
        }
        
        // Validate trim times
        musicStart.addEventListener('input', () => {
            let start = parseFloat(musicStart.value);
            const end = parseFloat(musicEnd.value);
            const duration = selectedMusicDuration;
            
            if (isNaN(start)) start = 0;
            if (start < 0) start = 0;
            if (end && !isNaN(end) && start >= end) {
                musicStart.value = end - 0.5;
            }
            if (start > duration) {
                musicStart.value = duration - 1;
            }
        });
        
        musicEnd.addEventListener('input', () => {
            let end = parseFloat(musicEnd.value);
            const start = parseFloat(musicStart.value);
            const duration = selectedMusicDuration;
            
            if (end && !isNaN(end)) {
                if (start && end <= start) {
                    musicEnd.value = start + 0.5;
                }
                if (end > duration) {
                    musicEnd.value = duration;
                }
            }
        });
        
        // ==================== Search Music ====================
        const searchInput = document.getElementById('musicSearch');
        searchInput.addEventListener('input', () => {
            const searchTerm = searchInput.value.toLowerCase();
            musicItems.forEach(item => {
                const title = item.querySelector('.music-title')?.textContent.toLowerCase() || '';
                const artist = item.querySelector('.music-artist')?.textContent.toLowerCase() || '';
                if (title.includes(searchTerm) || artist.includes(searchTerm)) {
                    item.style.display = 'flex';
                } else {
                    item.style.display = 'none';
                }
            });
        });
        
        // ==================== Form Submission Validation ====================
        const form = document.getElementById('reelForm');
        
        form.addEventListener('submit', (e) => {
            // Check if media is selected
            if (!mediaInput.files.length && mediaPreview.style.display !== 'block') {
                e.preventDefault();
                alert('Please select a video or image to upload');
                return;
            }
            // 2. ONLY if the user has manually toggled MUTE on, check for music
    // We check the actual hidden input value
    if (videoMuted.value === '1' && (!selectedMusicId || selectedMusicId === '')) {
        e.preventDefault();
        alert('You must select background music when muting your video');
        musicRequired.classList.add('show');
        return;
    }
            
            // RULE: If video is muted, user MUST select background music
            if (isVideoMuted && (!selectedMusicId || selectedMusicId === '')) {
                e.preventDefault();
                alert('You must select background music when muting your video');
                musicRequired.classList.add('show');
                return;
            }
            
            // Add music fields if music is selected
            if (selectedMusicId && selectedMusicId !== '') {
                const musicIdInput = document.createElement('input');
                musicIdInput.type = 'hidden';
                musicIdInput.name = 'music_id';
                musicIdInput.value = selectedMusicId;
                form.appendChild(musicIdInput);
                
                const startInput = document.createElement('input');
                startInput.type = 'hidden';
                startInput.name = 'music_start';
                startInput.value = musicStart.value || 0;
                form.appendChild(startInput);
                
                const endInput = document.createElement('input');
                endInput.type = 'hidden';
                endInput.name = 'music_end';
                endInput.value = musicEnd.value || '';
                form.appendChild(endInput);
            }
        });
    </script>
</body>
</html>