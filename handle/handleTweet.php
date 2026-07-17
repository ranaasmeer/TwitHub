<?php 
include '../core/init.php';
require_once '../core/classes/validation/Validator.php';
require_once '../core/classes/image.php';

use validation\Validator;

if (User::checkLogIn() === false) 
header('location: index.php'); 

if (isset($_POST['tweet'])) {

    $status = User::checkInput($_POST['status']);
    $media_files = $_FILES['tweet_media'];
    $video_files = $_FILES['tweet_video'];  // Now supports multiple videos
    
    // Check if there's any content
    $hasText = !empty($status);
    $hasImages = !empty($media_files['name'][0]);
    $hasVideos = false;
    
    // Check if any video files were uploaded
    if (!empty($video_files['name'][0])) {
        $hasVideos = true;
    }
    
    if (!$hasText && !$hasImages && !$hasVideos) {
        $_SESSION['errors_tweet'] = ['Status, image, or video is required'];
        header('location: ../home.php'); 
        die();
    }

    $v = new Validator;
    $v->rules('status', $status, ['string', 'max:280']); // Changed to 280 chars (Twitter standard)
    $errors = $v->errors;
    
    // Validate multiple videos
    $uploaded_videos = [];
    if ($hasVideos) {
        $total_videos = count($video_files['name']);
        
        if ($total_videos > 5) {
            $_SESSION['errors_tweet'] = ['Maximum 5 videos allowed per tweet'];
            header('location: ../home.php');
            exit();
        }
        
        $allowed_video_ext = ['mp4', 'mov', 'webm', 'avi', 'mkv', 'mpeg'];
        $max_video_size = 100000000; // 100MB per video
        
        for ($i = 0; $i < $total_videos; $i++) {
            if (empty($video_files['name'][$i])) continue;
            
            $video_ext = strtolower(pathinfo($video_files['name'][$i], PATHINFO_EXTENSION));
            
            if (!in_array($video_ext, $allowed_video_ext)) {
                $errors[] = "Video " . ($i+1) . ": Only MP4, MOV, WEBM, AVI, MKV, or MPEG video formats are allowed.";
            } elseif ($video_files['size'][$i] > $max_video_size) {
                $errors[] = "Video " . ($i+1) . " must be smaller than 100MB. Your file is " . round($video_files['size'][$i] / 1048576, 2) . "MB.";
            }
        }
    }
    
    // Validate multiple images
    $uploaded_media = [];
    if ($hasImages) {
        $total_files = count($media_files['name']);
        
        if ($total_files > 5) {
            $_SESSION['errors_tweet'] = ['Maximum 5 images allowed per tweet'];
            header('location: ../home.php');
            exit();
        }
        
        for ($i = 0; $i < $total_files; $i++) {
            $file = [
                'name' => $media_files['name'][$i],
                'type' => $media_files['type'][$i],
                'tmp_name' => $media_files['tmp_name'][$i],
                'error' => $media_files['error'][$i],
                'size' => $media_files['size'][$i]
            ];
            
            $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed_image_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            
            if (in_array($file_ext, $allowed_image_ext)) {
                $image = new Image($file, "tweet");
                $uploaded_media[] = [
                    'path' => $image->new_name,
                    'type' => 'image'
                ];
                $image->upload();
            } else {
                $errors[] = "File " . ($i+1) . " is not a valid image format";
            }
        }
    }

    // Upload videos
    if ($hasVideos && empty($errors)) {
        // Create folder if not exists
        if (!file_exists("../assets/videos/tweets")) {
            mkdir("../assets/videos/tweets", 0777, true);
        }
        
        for ($i = 0; $i < $total_videos; $i++) {
            if (empty($video_files['name'][$i])) continue;
            
            $video_ext = strtolower(pathinfo($video_files['name'][$i], PATHINFO_EXTENSION));
            $new_video_name = 'tweet-video-' . uniqid('', true) . '.' . $video_ext;
            
            if (move_uploaded_file($video_files['tmp_name'][$i], "../assets/videos/tweets/" . $new_video_name)) {
                $uploaded_videos[] = [
                    'path' => $new_video_name,
                    'order' => $i
                ];
            } else {
                $errors[] = 'Failed to upload video ' . ($i+1) . '. Please check file permissions.';
            }
        }
    }

    if ($errors == []) {
        date_default_timezone_set("Africa/Cairo");
        
        // Create post
        $data = [
            'user_id' => $_SESSION['user_id'], 
            'post_on' => date("Y-m-d H:i:s"),
        ];
        $post_id = User::create('posts', $data);
        
        // Create tweet (store first image and first video for backward compatibility)
        $firstImage = !empty($uploaded_media) ? $uploaded_media[0]['path'] : null;
        $firstVideo = !empty($uploaded_videos) ? $uploaded_videos[0]['path'] : null;
        
        $data_tweet = [
            'post_id' => $post_id,
            'tweet_by' => $_SESSION['user_id'],
            'status' => $status,
            'img' => $firstImage,
            'video' => $firstVideo
        ];
        User::create('tweets', $data_tweet);
        
        // Save multiple images to tweet_media table
        if (!empty($uploaded_media)) {
            foreach ($uploaded_media as $order => $media) {
                $media_data = [
                    'tweet_id' => $post_id,
                    'media_path' => $media['path'],
                    'media_type' => $media['type'],
                    'media_order' => $order
                ];
                User::create('tweet_media', $media_data);
            }
        }
        
        // Save multiple videos to tweet_videos table
        if (!empty($uploaded_videos)) {
            foreach ($uploaded_videos as $video) {
                $video_data = [
                    'tweet_id' => $post_id,
                    'video_path' => $video['path'],
                    'video_order' => $video['order']
                ];
                User::create('tweet_videos', $video_data);
            }
        }
        
        // Notify users if mentioned
        preg_match_all("/@+([a-zA-Z0-9_]+)/i", $status, $mention);
        $user_id = $_SESSION['user_id'];
        foreach($mention[1] as $men) {
            $id = User::getIdByUsername($men);
            if($id != $user_id) {
                $data_notify = [
                    'notify_for' => $id,
                    'notify_from' => $user_id,
                    'target' => $post_id,
                    'type' => 'mention',
                    'time' => date("Y-m-d H:i:s"),
                    'count' => '0',
                    'status' => '0'
                ];
                Tweet::create('notifications', $data_notify);
            }
        }
        
        // Add trends
        preg_match_all("/#+([a-zA-Z0-9_]+)/i", $status, $hashtag);
        if(!empty($hashtag)) { 
            Tweet::addTrend($status);
        }
        
        header('location: ../home.php');
    } else {
        $_SESSION['errors_tweet'] = $errors;
        header('location: ../home.php');
    }
} else {
    header('location: ../home.php');
}
?>