<?php

   include 'core/init.php';
  
   $user_id = $_SESSION['user_id'];

   $user = User::getData($user_id);
   
   if (User::checkLogIn() === false) 
   header('location: index.php');


   $who_users = Follow::whoToFollow($user_id);
   $notify_count = User::CountNotification($user_id);


// Count total unread messages for this user
$conn = Connect::connect();
$stmt = $conn->prepare("SELECT COUNT(*) AS unread_total FROM messages WHERE receiver_id = ? AND is_read = 0");
$stmt->execute([$user_id]);
$unreadMessages = $stmt->fetch(PDO::FETCH_OBJ)->unread_total ?? 0;



   // Fetch trending hashtags ordered by count descending
$sql_trends = "SELECT hashtag, count, type 
               FROM trends 
               WHERE created_on >= NOW() - INTERVAL 1 DAY 
               ORDER BY count DESC 
               LIMIT 10";

$stmt_trends = Connect::connect()->prepare($sql_trends);
$stmt_trends->execute();
$trends = $stmt_trends->fetchAll(PDO::FETCH_OBJ);

 
?>
    

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Home | TwitterClone</title>
    
    <link rel="shortcut icon" type="image/png" href="assets/images/twitter.svg"> 
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link rel="stylesheet" href="assets/css/home_style.css?v=<?php echo time(); ?>">
    
    <!-- Emoji Picker Library CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/emoji-picker-element@1.18.2/index.css">
    
    <style>
.trending-list {
  list-style: none;
  margin: 0;
  padding: 0;
}

.trending-list li {
  font-size: 18px;
  padding: 14px 20px;
  border-bottom: 1px solid #e6ecf0;
  transition: background-color 0.2s ease;
  cursor: pointer;
}

.trending-list li:hover {
  background-color: #e9ecef;
}

.trending-list li:first-child {
  border-top: 1px solid #e6ecf0;
}

.trending-list li:last-child {
  border-bottom: 1px solid #e6ecf0;
}

.trending-list a {
  color: #000000;
  font-size: 17px;
  text-decoration: none;
  font-weight: 600;
  position: relative;
}

.trending-list a:hover {
  color: #1DA1F2;
  text-decoration: underline;
}

.trend-count {
  color: #657786;
  font-size: 14px;
  margin-left: 5px;
}

.trend-header {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 3px;
}

.trend-type {
  font-weight: 600;
  border-radius: 12px;
  padding: 2px 8px;
  color: #fff;
  font-size: 13px;
  background-color: #1DA1F2;
}

.trend-type.sports { background-color: #1DA1F2; }
.trend-type.politics { background-color: #D93025; }
.trend-type.entertainment { background-color: #FF9800; }
.trend-type.technology { background-color: #4CAF50; }
.trend-type.environment { background-color: #2E7D32; }
.trend-type.education { background-color: #9C27B0; }
.trend-type.health { background-color: #E91E63; }
.trend-type.general { background-color: #657786; }

.trend-label {
  background-color: #e8f5fe;
  color: #1d9bf0;
  font-size: 12px;
  font-weight: 600;
  padding: 2px 6px;
  border-radius: 4px;
  text-transform: uppercase;
}

.box-share.mt-4 {
    max-height: 300px;
    overflow-y: auto;
    padding-right: 10px;
}

.box-share.mt-4::-webkit-scrollbar {
    width: 6px;
}

.box-share.mt-4::-webkit-scrollbar-thumb {
    background-color: #ccc;
    border-radius: 3px;
}

.box-share.mt-4::-webkit-scrollbar-track {
    background: #f1f1f1;
}

.sidebar-unread-badge {
  position: absolute;
  top: -4px;
  right: -6px;
  background: #1da1f2;
  color: #fff;
  font-size: 11px;
  font-weight: 600;
  border-radius: 50%;
  padding: 2px 6px;
  min-width: 16px;
  text-align: center;
  line-height: 1.2;
  box-shadow: 0 0 6px rgba(29,161,242,0.5);
}

/* ========== FIXED ALIGNMENT - Same styling for all icons ========== */

/* Unify the bottom bar to be one single row */
.bottom {
    display: flex;
    align-items: center;
    justify-content: space-between;
    width: 100%;
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px solid #e6ecf0;
    flex-wrap: wrap;
}

/* Container for Image, Video, and Emoji icons - all same style */
.bottom-container {
    display: flex;
    align-items: center;
    gap: 8px;
}

/* Make every icon container (label and button) exactly the same size */
.uni, .emoji-trigger {
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    cursor: pointer;
    background: transparent;
    border: none;
    color: #1da1f2;
    transition: all 0.2s;
    margin: 0;
    padding: 0;
    font-size: 19px;
}

.uni:hover, .emoji-trigger:hover {
    background: rgba(29, 161, 242, 0.1);
    transform: scale(1.05);
}

.uni i, .emoji-trigger i {
    font-size: 19px;
}

/* Container for Counter and Tweet Button */
.tweet-actions {
    display: flex;
    align-items: center;
    gap: 15px;
}

/* Character Counter */
.char-counter {
    margin: 0;
    font-size: 14px;
    color: #657786;
}

.char-counter.near-limit {
    color: #ff9900;
}

.char-counter.limit-reached {
    color: #ff0000;
}

/* Emoji Picker Styles */
.emoji-wrapper {
    position: relative;
    display: inline-block;
}

#emojiPickerContainer {
    position: absolute;
    left: 0;
    z-index: 9999;
    display: none;
}

/* Tweet Button */
.submit {
    background: linear-gradient(135deg, #1DA1F2, #0c85d0);
    color: white;
    border: none;
    padding: 6px 18px;
    border-radius: 30px;
    font-weight: bold;
    font-size: 14px;
    cursor: pointer;
    transition: all 0.3s ease;
}

.submit:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(29, 161, 242, 0.3);
}

.submit:disabled {
    opacity: 0.5;
    cursor: not-allowed;
    transform: none;
}

/* ========== AUTO-HEIGHT TEXTAREA STYLES - FIXED ========== */

/* 1. The Textarea itself */
.text-whathappen {
    resize: none;            /* No manual resizing */
    overflow-y: hidden;      /* No scrollbar initially */
    min-height: 50px;        /* Starting height (approx 2 lines) */
    height: auto;
    display: block;
    width: 100%;
    border: none;
    outline: none;
    font-size: 18px;
    font-family: inherit;
    line-height: 1.5;
    padding: 8px 0;
    background: transparent;
    max-height: 300px;       /* Max height before scroll appears */
    overflow-y: auto;        /* Show scrollbar when max-height is reached */
}

/* 2. The Container wrappers */
/* We must ensure these allow the content to push the bottom border down */
#whathappen, 
#whathappen .container, 
#whathappen .part-1, 
#whathappen .text, 
#whathappen .inner {
    height: auto !important;    /* Allow height to be determined by children */
    min-height: min-content;            /* Ensure block flow so bottom is pushed */
}

/* 3. The Inner section (Profile Pic + Textarea) */
.inner {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    width: 100%;
}

.inner img {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    object-fit: cover;
    flex-shrink: 0;
}

.inner label {
    flex: 1;
    display: flex;
}

/* Scrollbar styling for textarea when max-height reached */
.text-whathappen::-webkit-scrollbar {
    width: 6px;
}

.text-whathappen::-webkit-scrollbar-track {
    background: #e1e8ed;
    border-radius: 3px;
}

.text-whathappen::-webkit-scrollbar-thumb {
    background: #1DA1F2;
    border-radius: 3px;
}

/* ========== END AUTO-HEIGHT TEXTAREA STYLES ========== */

/* Tweet Text Read More/Less */
.tweet-text-container {
    position: relative;
}

.tweet-text {
    word-wrap: break-word;
    white-space: pre-wrap;
}

.tweet-text.collapsed {
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.read-more-btn {
    background: none;
    border: none;
    color: #1da1f2;
    font-size: 13px;
    cursor: pointer;
    padding: 0;
    margin-top: 5px;
    display: inline-block;
}

.read-more-btn:hover {
    text-decoration: underline;
}

.hash-box ul {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    list-style: none;
    padding: 0;
    margin: 10px 0 0 0;
}

/* Remove old conflicting styles */
#whathappen .bottom-container {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

/* ========== ENHANCED MEDIA PREVIEW STYLES ========== */
.media-preview-container {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 12px;
    position: relative;
}

.media-preview-item {
    position: relative;
    width: calc(25% - 6px);
    min-width: 80px;
    border-radius: 12px;
    overflow: hidden;
    background: #f5f8fa;
}

.media-preview-item img,
.media-preview-item video {
    width: 100%;
    aspect-ratio: 1/1;
    object-fit: cover;
}

.media-preview-item .remove-media {
    position: absolute;
    top: 4px;
    right: 4px;
    background: rgba(0,0,0,0.7);
    color: white;
    border: none;
    border-radius: 50%;
    width: 24px;
    height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-size: 12px;
    transition: all 0.2s;
    z-index: 10;
}

.media-preview-item .remove-media:hover {
    background: rgba(224,36,94,0.9);
    transform: scale(1.05);
}

/* Video Preview Overlay */
.media-preview-item.video-preview-overlay::after {
    content: '\f144';
    font-family: 'Font Awesome 6 Free';
    font-weight: 900;
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    color: white;
    font-size: 30px;
    opacity: 0.8;
    text-shadow: 0 0 5px rgba(0,0,0,0.5);
    pointer-events: none;
}

/* ========== MEDIA PREVIEW SLIDER/CAROUSEL ========== */
.preview-slider-modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.95);
    z-index: 100000;
    justify-content: center;
    align-items: center;
}

.preview-slider-modal.active {
    display: flex;
}

.preview-slider-container {
    position: relative;
    max-width: 90%;
    max-height: 90%;
}

.preview-slider-image {
    max-width: 90vw;
    max-height: 85vh;
    object-fit: contain;
    border-radius: 8px;
}

.preview-slider-video {
    max-width: 90vw;
    max-height: 85vh;
    border-radius: 8px;
    background: #000;
}

.preview-slider-prev, .preview-slider-next {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    background: rgba(0,0,0,0.6);
    color: white;
    border: none;
    width: 50px;
    height: 50px;
    border-radius: 50%;
    cursor: pointer;
    font-size: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
    z-index: 100001;
}

.preview-slider-prev:hover, .preview-slider-next:hover {
    background: rgba(0,0,0,0.8);
    transform: translateY(-50%) scale(1.1);
}

.preview-slider-prev { left: 20px; }
.preview-slider-next { right: 20px; }

.preview-slider-close {
    position: absolute;
    top: 20px;
    right: 30px;
    color: white;
    font-size: 35px;
    cursor: pointer;
    background: rgba(0,0,0,0.5);
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
    z-index: 100001;
}

.preview-slider-close:hover {
    background: rgba(255,255,255,0.2);
    transform: scale(1.1);
}

.preview-slider-counter {
    position: absolute;
    bottom: 20px;
    left: 50%;
    transform: translateX(-50%);
    color: white;
    background: rgba(0,0,0,0.6);
    padding: 5px 15px;
    border-radius: 20px;
    font-size: 14px;
    z-index: 100001;
}

/* ========== INFINITE SCROLL STYLES ========== */
.home-feed-container {
    width: 100%;
}

.loading-spinner {
    text-align: center;
    padding: 20px;
    color: #1da1f2;
}

.no-more-posts {
    text-align: center;
    padding: 30px 20px;
    color: #657786;
    font-size: 14px;
    border-top: 1px solid #e6ecf0;
}

/* ========== SEARCH STYLES ========== */
.input-group {
    position: relative;
    width: 100%;
}

.search-input {
    width: 100%;
    padding: 12px 40px 12px 45px;
    border: 2px solid #e1e8ed;
    border-radius: 50px;
    font-size: 15px;
    background: #f5f8fa;
    transition: all 0.3s ease;
}

.search-input:focus {
    outline: none;
    border-color: #1DA1F2;
    background: #ffffff;
    box-shadow: 0 0 0 3px rgba(29, 161, 242, 0.1);
}

.search-input::placeholder {
    color: #8899a6;
    font-size: 14px;
}

#icon-search {
    position: absolute;
    left: 15px;
    top: 50%;
    transform: translateY(-50%);
    color: #657786;
    font-size: 18px;
    z-index: 10;
    pointer-events: none;
}

.search-result {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: white;
    border-radius: 16px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.15);
    margin-top: 8px;
    z-index: 1000;
    max-height: 400px;
    overflow-y: auto;
    display: none;
}

.search-result-item {
    display: flex;
    align-items: center;
    padding: 12px 16px;
    cursor: pointer;
    transition: background 0.2s ease;
    border-bottom: 1px solid #e6ecf0;
    text-decoration: none;
}

.search-result-item:last-child {
    border-bottom: none;
}

.search-result-item:hover {
    background: #f5f8fa;
}

.search-result-img {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    object-fit: cover;
    margin-right: 12px;
}

.search-result-info {
    flex: 1;
}

.search-result-name {
    font-weight: 700;
    font-size: 15px;
    color: #14171a;
    margin-bottom: 2px;
}

.search-result-username {
    font-size: 13px;
    color: #657786;
}

.search-result-bio {
    font-size: 12px;
    color: #8899a6;
    margin-top: 2px;
}

.search-result-badge {
    background: #e8f5fe;
    color: #1DA1F2;
    font-size: 11px;
    padding: 2px 8px;
    border-radius: 20px;
    margin-left: 8px;
    font-weight: 500;
}

.search-loading {
    padding: 20px;
    text-align: center;
    color: #657786;
}

.search-loading i {
    animation: spin 1s linear infinite;
}

@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

.search-no-results {
    padding: 30px 20px;
    text-align: center;
    color: #657786;
}

.search-load-more {
    padding: 12px;
    text-align: center;
    border-top: 1px solid #e6ecf0;
}

.load-more-search {
    background: transparent;
    border: none;
    color: #1DA1F2;
    font-weight: 600;
    font-size: 14px;
    cursor: pointer;
    padding: 8px 16px;
    border-radius: 30px;
    width: 100%;
}

.load-more-search:hover {
    background: rgba(29, 161, 242, 0.1);
}

.search-result::-webkit-scrollbar {
    width: 6px;
}

.search-result::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 10px;
}

.search-result::-webkit-scrollbar-thumb {
    background: #1DA1F2;
    border-radius: 10px;
}


/* ========== RESPONSIVE TWEET BOX ICON FIX ========== */
/* This block intentionally comes last so it overrides older/conflicting CSS. */
#whathappen .bottom {
    display: grid !important;
    grid-template-columns: minmax(0, 1fr) auto;
    grid-template-areas:
        "icons actions"
        "preview preview"
        "hashtags hashtags"
        "errors errors";

    align-items: center !important;
    column-gap: 16px !important;
    row-gap: 6px !important;

    width: 100% !important;

    margin-top: 2px !important;
    padding-top: 6px !important;
    padding-bottom: 2px !important;

    border-top: 1px solid #e6ecf0 !important;
}

#whathappen .bottom-container {
    grid-area: icons;
    display: flex !important;
    align-items: center !important;
    justify-content: flex-start !important;
    flex-wrap: wrap !important;
    gap: 12px !important;
    min-width: 0;
}

#whathappen .tweet-actions {
    grid-area: actions;
    display: flex !important;
    align-items: center !important;
    justify-content: flex-end !important;
    gap: 14px !important;
    white-space: nowrap;
}

#whathappen .media-preview-container {
    grid-area: preview;
    width: 100% !important;
    display: flex !important;
    flex-wrap: wrap !important;
    gap: 10px !important;
    margin-top: 0 !important;
}

#whathappen .hash-box {
    grid-area: hashtags;
    width: 100%;
}

#whathappen .alert {
    grid-area: errors;
    width: 100%;
    margin: 0;
}

/* Equal clickable area, but enough room for 0/5 counter so icons do not touch. */
#whathappen .uni,
#whathappen .emoji-trigger {
    min-width: 44px !important;
    height: 40px !important;
    padding: 0 10px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 6px !important;
    border-radius: 999px !important;
    cursor: pointer !important;
    background: transparent !important;
    border: 0 !important;
    color: #1da1f2 !important;
    margin: 0 !important;
    line-height: 1 !important;
    flex: 0 0 auto !important;
}

#whathappen .uni:hover,
#whathappen .emoji-trigger:hover {
    background: rgba(29, 161, 242, 0.10) !important;
}

#whathappen .uni i,
#whathappen .emoji-trigger i {
    font-size: 20px !important;
    line-height: 1 !important;
    flex: 0 0 auto !important;
}

#whathappen .upload-count,
#whathappen .video-upload-count {
    display: inline-block !important;
    font-size: 12px !important;
    font-weight: 600 !important;
    color: #657786 !important;
    line-height: 1 !important;
    margin: 0 !important;
}

#whathappen .emoji-wrapper {
    position: relative !important;
    display: inline-flex !important;
    flex: 0 0 auto !important;
}

#whathappen #emojiPickerContainer {
    position: absolute !important;
    left: 0 !important;
    z-index: 99999 !important;
}

@media (max-width: 768px) {
    #whathappen .container {
        padding-left: 12px !important;
        padding-right: 12px !important;
    }

    #whathappen .bottom {
        grid-template-columns: 1fr !important;
        grid-template-areas:
            "icons"
            "preview"
            "hashtags"
            "actions"
            "errors";
        row-gap: 14px !important;
    }

    #whathappen .bottom-container,
    #whathappen .tweet-actions {
        width: 100% !important;
        justify-content: space-between !important;
    }

    #whathappen .bottom-container {
        gap: 10px !important;
    }

    #whathappen .tweet-actions {
        border-top: 1px solid #eef2f6;
        padding-top: 10px;
    }

    #whathappen .submit {
        min-width: 96px;
    }

    #whathappen .media-preview-item {
        width: calc(50% - 5px) !important;
        min-width: 120px !important;
    }
}

@media (max-width: 420px) {
    #whathappen .inner {
        gap: 8px !important;
    }

    #whathappen .inner img {
        width: 40px !important;
        height: 40px !important;
    }

    #whathappen .text-whathappen {
        font-size: 16px !important;
    }

    #whathappen .bottom-container {
        justify-content: flex-start !important;
        gap: 8px !important;
    }

    #whathappen .uni,
    #whathappen .emoji-trigger {
        min-width: 42px !important;
        height: 38px !important;
        padding: 0 9px !important;
    }

    #whathappen .media-preview-item {
        width: 100% !important;
        min-width: 100% !important;
    }
}

/* ========== FINAL COMPOSER CLEANUP: no blank row + same mobile icon gap ========== */
/* Hide empty rows so the tweet box does not show a blank line before media is selected. */
#whathappen .media-preview-container:empty {
    display: none !important;
    margin: 0 !important;
    padding: 0 !important;
    min-height: 0 !important;
}

/* Hide hashtag area when it has no generated hashtag items. */
#whathappen .hash-box:has(ul:empty) {
    display: none !important;
    margin: 0 !important;
    padding: 0 !important;
    min-height: 0 !important;
}

/* Keep icon spacing consistent on desktop, tablet, and mobile. */
#whathappen .bottom-container {
    gap: 12px !important;
}

@media (max-width: 768px) {
    #whathappen .bottom {
        row-gap: 10px !important;
    }

    #whathappen .bottom-container {
        gap: 12px !important;
        justify-content: flex-start !important;
    }

    #whathappen .tweet-actions {
        border-top: 0 !important;
        padding-top: 0 !important;
        margin-top: 0 !important;
    }
}

@media (max-width: 420px) {
    #whathappen .bottom-container {
        gap: 12px !important;
        justify-content: flex-start !important;
    }
}

/* ========== END RESPONSIVE TWEET BOX ICON FIX ========== */


/* ========== MENTION / HASHTAG EMPTY ROW FIX ========== */
/* By default do NOT reserve a grid row for empty mention/hashtag suggestions. */
#whathappen .bottom {
    grid-template-areas:
        "icons actions"
        "preview preview"
        "errors errors" !important;
}

#whathappen .hash-box {
    display: none !important;
    margin: 0 !important;
    padding: 0 !important;
    min-height: 0 !important;
}

#whathappen .hash-box ul {
    margin: 0 !important;
    padding: 0 !important;
}

/* When JS detects real suggestion items, show the row again. */
#whathappen .bottom.has-hashtags {
    grid-template-areas:
        "icons actions"
        "preview preview"
        "hashtags hashtags"
        "errors errors" !important;
}

#whathappen .bottom.has-hashtags .hash-box {
    display: block !important;
    grid-area: hashtags !important;
    width: 100% !important;
    margin-top: 6px !important;
}

#whathappen .bottom.has-hashtags .hash-box ul {
    display: flex !important;
    flex-wrap: wrap !important;
    gap: 8px !important;
}

/* Mobile version: also remove the empty hashtag row until needed. */
@media (max-width: 768px) {
    #whathappen .bottom {
        grid-template-areas:
            "icons"
            "preview"
            "actions"
            "errors" !important;
    }

    #whathappen .bottom.has-hashtags {
        grid-template-areas:
            "icons"
            "preview"
            "hashtags"
            "actions"
            "errors" !important;
    }
}
/* ========== END MENTION / HASHTAG EMPTY ROW FIX ========== */

</style>

   
</head>
<body>
  <!-- This is a modal for welcome the new signup account! -->

  <script src="assets/js/jquery-3.5.1.min.js"></script>
     
    <?php  if (isset($_SESSION['welcome'])) { ?>
      <script>
       $(document).ready(function(){
        // Open modal on page load
        $("#welcome").modal('show');
      
 
       });
      </script>
    

      <!-- Modal -->
<div class="modal fade" id="welcome" tabindex="-1" role="dialog" aria-labelledby="exampleModalCenterTitle" aria-hidden="true">
  <div  class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content">
      <div class="">
        <div class="text-center">
         <span  class="modal-title font-weight-bold text-center" id="exampleModalLongTitle">
          <span style="font-size: 20px;">Welcome <span style="color:#207ce5"><?php echo $user->name; ?></span>  </span>  
         </span>
        </div>
        <!-- <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button> -->
      </div>
      <div class="modal-body">
        <div class="text-center">
       
        <h4 style="font-weight: 600; " >You've Signed up Successfully!</h4>
 
        </div>
        <p>This is Twitter clone is developed by <span style="font-weight: 700;">Hannan Mahmood Bhatti from Balochni</span>  for Final year project.</p>
        <p>This includes tweet , retweet , quote or even quote the quoted tweet , like tweet and nested comments and replies.
          You can mention or add hashtag to your tweet , change password or username and chat with friends and can watch feeds.
          Follow or unfollow people. get notificaction if any action happen. Search users by name or username. and more!
        </p>
        <p>By default you followed
          <a style="color:#207ce5;" href="usman">@Usman</a> 
            to see their tweets.</p>
      </div>
      
    </div>
  </div>
</div>

      <?php unset($_SESSION['welcome']); } ?>

      <!-- End welcome -->

    <div id="mine">
 
    <div class="wrapper-left">
        <div class="sidebar-left">
          <div class="grid-sidebar" style="margin-top: 12px">
            <div class="icon-sidebar-align">
              <img src="<?php echo BASE_URL . "/assets/images/twitter-logo.png"; ?>" alt="" height="30px" width="30px" />
            </div>
          </div>

          <a href="home.php">
          <div class="grid-sidebar bg-active" style="margin-top: 12px">
            <div class="icon-sidebar-align">
              <img src="<?php echo BASE_URL . "/includes/icons/tweethome.png"; ?>" alt="" height="26.25px" width="26.25px" />
            </div>
            <div class="wrapper-left-elements">
              <a class="wrapper-left-active" href="home.php" style="margin-top: 4px;"><strong>Home</strong></a>
            </div>
          </div>
          </a>
          <a href="explore.php">
  <div class="grid-sidebar" style="margin-top: 12px">
    <div class="icon-sidebar-align">
      <img src="<?php echo BASE_URL . "/includes/icons/tweetsearch.png"; ?>" alt="" height="26.25px" width="26.25px" />
    </div>
    <div class="wrapper-left-elements">
      <a href="explore.php" style="margin-top: 4px;"><strong>Explore</strong></a>
    </div>
  </div>
</a>
<a href="reels.php">
  <div class="grid-sidebar <?php echo basename($_SERVER['PHP_SELF']) == 'reels.php' ? 'bg-active' : ''; ?>" style="margin-top: 12px">
    <div class="icon-sidebar-align">
      <img src="<?php echo BASE_URL . "/includes/icons/tweetreels.png"; ?>" alt="Reels" height="26.25px" width="26.25px" />
    </div>
    <div class="wrapper-left-elements">
      <a href="reels.php" class="<?php echo basename($_SERVER['PHP_SELF']) == 'reels.php' ? 'wrapper-left-active' : ''; ?>" style="margin-top: 4px;">
        <strong>Reels</strong>
      </a>
    </div>
  </div>
</a>

<a href="messages.php">
  <div class="grid-sidebar <?php echo basename($_SERVER['PHP_SELF']) == 'messages.php' ? 'bg-active' : ''; ?>" 
       style="margin-top: 12px; position: relative;">
    <div class="icon-sidebar-align" style="position: relative;">
      <img src="<?php echo BASE_URL . "/includes/icons/tweetmessage.png"; ?>" 
           alt="Messages" 
           height="26.25px" 
           width="26.25px" />
      
      <?php if (!empty($unreadMessages) && $unreadMessages > 0): ?>
        <span class="sidebar-unread-badge"><?php echo $unreadMessages; ?></span>
      <?php endif; ?>
    </div>

    <div class="wrapper-left-elements">
      <a href="messages.php" 
         class="<?php echo basename($_SERVER['PHP_SELF']) == 'messages.php' ? 'wrapper-left-active' : ''; ?>" 
         style="margin-top: 4px;">
        <strong>Messages</strong>
      </a>
    </div>
  </div>
</a>


  
           <a href="notification.php">
          <div class="grid-sidebar">
            <div class="icon-sidebar-align position-relative">
                <?php if ($notify_count > 0) { ?>
              <i class="notify-count"><?php echo $notify_count; ?></i> 
              <?php } ?>
              <img
                src="<?php echo BASE_URL . "/includes/icons/tweetnotif.png"; ?>"
                alt=""
                height="26.25px"
                width="26.25px"
              />
            </div>
  
            <div class="wrapper-left-elements">
              <a href="notification.php" style="margin-top: 4px"><strong>Notifications</strong></a>
            </div>
          </div>
          </a>
          <!-- BOOKMARKS -->
<a href="bookmarks.php">
  <div class="grid-sidebar">
    <div class="icon-sidebar-align">
      <img src="<?php echo BASE_URL . "/includes/icons/tweetbookmark.png"; ?>" 
           alt="Bookmarks" 
           height="26.25px" 
           width="26.25px" />
    </div>
    <div class="wrapper-left-elements">
      <a href="bookmarks.php" style="margin-top: 4px;"><strong>Bookmarks</strong></a>
    </div>
  </div>
</a>

        
            <a href="<?php echo BASE_URL . $user->username; ?>">
          <div class="grid-sidebar">
            <div class="icon-sidebar-align">
              <img src="<?php echo BASE_URL . "/includes/icons/tweetprof.png"; ?>" alt="" height="26.25px" width="26.25px" />
            </div>
  
            <div class="wrapper-left-elements">
              <!-- <a href="/twitter/<?php echo $user->username; ?>"  style="margin-top: 4px"><strong>Profile</strong></a> -->
              <a  href="<?php echo BASE_URL . $user->username; ?>"  style="margin-top: 4px"><strong>Profile</strong></a>
            
            </div>
          </div>
          </a>
          <a href="<?php echo BASE_URL . "account.php"; ?>">
          <div class="grid-sidebar ">
            <div class="icon-sidebar-align">
              <img src="<?php echo BASE_URL . "/includes/icons/tweetsetting.png"; ?>" alt="" height="26.25px" width="26.25px" />
            </div>
  
            <div class="wrapper-left-elements">
              <a href="<?php echo BASE_URL . "account.php"; ?>" style="margin-top: 4px"><strong>Settings</strong></a>
            </div>
           
            
          </div>
          </a>
          <a href="includes/logout.php">
          <div class="grid-sidebar">
            <div class="icon-sidebar-align">
            <i style="font-size: 26px;" class="fas fa-sign-out-alt"></i>
            </div>
  
            <div class="wrapper-left-elements">
              <a href="includes/logout.php" style="margin-top: 4px"><strong>Logout</strong></a>
            </div>
          </div>
          </a>
          <button class="button-twittear">
            <strong>Tweet</strong>
          </button>
  
         <?php include 'includes/profile_dropdown.php'; ?>
        </div>
      </div>
          

      <div class="grid-posts">
        <div class="border-right">
          <div class="grid-toolbar-center">
            <div class="center-input-search">
              <div class="input-group-login" id="whathappen">
                
                <div class="container">
                  <div class="part-1">
                    <div class="header">
                      <div class="home">
                        <h2>Home</h2>
                      </div>
                    </div>
            
                    <div class="text">
                      <form class="" action="handle/handleTweet.php" method="post" enctype="multipart/form-data" id="tweetForm">
                        <div class="inner">
            
                            <img src="assets/images/users/<?php echo $user->img ?>" alt="profile photo">
                        
                          <label>
            
                            <textarea class="text-whathappen" name="status" id="tweetStatus" rows="1" cols="80" placeholder="What's happening?" maxlength="280"></textarea>
                        
                        </label>
                        </div> 
                            
                         <!-- tmp image upload place -->
                        <div class="position-relative upload-photo"> 
                          <img class="img-upload-tmp" src="assets/images/tweets/tweet-60666d6b426a1.jpg" alt="">
                          <div class="icon-bg">
                          <i id="#upload-delete-tmp" class="fas fa-times position-absolute upload-delete"></i>  
                          </div>
                        </div>

                        <div class="bottom"> 
                          
                          <!-- Icons Group (Left Side) -->
                          <div class="bottom-container">
                              <!-- Multiple Image Upload -->
                              <label for="tweet_media" class="uni" title="Upload Images (Max 5)">
                                  <i class="fa fa-image"></i> <span class="upload-count">0/5</span>
                              </label>
                              <input class="tweet_media" id="tweet_media" type="file" name="tweet_media[]" accept="image/*" multiple style="display:none;">

                              <!-- Video Upload -->
                              <label for="tweet_video" class="uni" title="Upload Videos (Max 5)">
                                  <i class="fa fa-video"></i> <span class="video-upload-count">0/5</span>
                              </label>
                              <input class="tweet_video" id="tweet_video" type="file" name="tweet_video[]" accept="video/mp4,video/webm,video/quicktime" multiple style="display:none;">

                              <!-- Emoji Picker -->
                              <div class="emoji-wrapper">
                                  <button type="button" class="emoji-trigger" id="emojiTrigger" title="Add Emoji">
                                      <i class="far fa-smile"></i>
                                  </button>
                                  <div id="emojiPickerContainer"></div>
                              </div>
                          </div>

                          <!-- Media Preview Container (Images + Videos) -->
                          <div class="media-preview-container" id="mediaPreviewContainer"></div>

                          <!-- Action Group (Right Side) -->
                          <div class="tweet-actions">
                              <span class="char-counter" id="charCount">280</span>
                              <input type="submit" name="tweet" value="Tweet" class="submit" id="tweetSubmitBtn">
                          </div>
                          
                          <div class="hash-box">
                              <ul style="margin-bottom: 0;"></ul>
                          </div>
                          
                          <?php if (isset($_SESSION['errors_tweet'])) { 
                            foreach($_SESSION['errors_tweet'] as $t) {?>
                            <div class="alert alert-danger">
                              <span class="item2-pair"> <?php echo $t; ?> </span>
                            </div>
                          <?php } } unset($_SESSION['errors_tweet']); ?>
                          
                      </div>
                      </form>
                    </div>
                  </div>
                  <div class="part-2">
            
                  </div>
            
                </div>
                
                
              </div>
            </div>
          </div>
          <div class="box-fixed" id="box-fixed"></div>
            
          <!-- Home Feed Container with Infinite Scroll -->
          <div class="home-feed-container">
              <?php Tweet::displayHomePosts($user_id, 0); ?>
          </div>

          <!-- Loading spinner -->
          <div class="loading-spinner" style="display:none;">
              <i class="fas fa-spinner fa-pulse"></i> Loading more...
          </div>

          <!-- No more posts message -->
          <div class="no-more-posts" style="display:none;">
              ✨ You've seen all posts! ✨
          </div>

        </div>


      <div class="wrapper-right">
  <div style="width: 90%;" class="container">
    <div class="input-group py-2 m-auto pr-5 position-relative">
      <i id="icon-search" class="fas fa-search tryy"></i>
      <input type="text" class="form-control search-input" id="searchInput" placeholder="Search Twitter">
      <div class="search-result"></div>
    </div>
  </div>

  <!-- Who to follow -->
  <div class="box-share">
    <p class="txt-share"><strong>Who to follow</strong></p>
    <?php 
    foreach($who_users as $user) { 
      $user_follow = Follow::isUserFollow($user_id , $user->id);
    ?>
      <div class="grid-share">
        <a style="position: relative; z-index:5; color:black" href="<?php echo $user->username; ?>">
          <img src="assets/images/users/<?php echo $user->img; ?>" alt="" class="img-share" />
        </a>
        <div>
          <p>
            <a style="position: relative; z-index:5; color:black" href="<?php echo $user->username; ?>">  
              <strong><?php echo $user->name; ?></strong>
            </a>
          </p>
          <p class="username">@<?php echo $user->username; ?>
            <?php if (Follow::FollowsYou($user->id , $user_id)) { ?>
              <span class="ml-1 follows-you">Follows You</span>
            <?php } ?>
          </p>
        </div>
        <div>
          <button class="follow-btn follow-btn-m <?= $user_follow ? 'following' : 'follow' ?>"
                  data-follow="<?php echo $user->id; ?>"
                  data-user="<?php echo $user_id; ?>"
                  data-profile="<?php echo $u_id; ?>"
                  style="font-weight: 700;">
            <?php echo $user_follow ? 'Following' : 'Follow'; ?>
          </button>
        </div>
      </div>
    <?php } ?>
  </div>

<!-- Trending Hashtags -->
          <?php
$trends = Tweet::trendingHashtags();
echo Tweet::getTrendingHashtagsBox($trends);
?>>

</div> <!-- ✅ End of wrapper-right -->
</div> <!-- ✅ End of grid-posts -->
</div> <!-- ✅ End of mine -->

<!-- Preview Slider Modal -->
<div id="previewSliderModal" class="preview-slider-modal">
    <button class="preview-slider-close">&times;</button>
    <button class="preview-slider-prev"><i class="fas fa-chevron-left"></i></button>
    <div class="preview-slider-container">
        <img id="previewSliderImage" class="preview-slider-image" src="" style="display: none;">
        <video id="previewSliderVideo" class="preview-slider-video" controls style="display: none;">
            <source src="" type="video/mp4">
        </video>
    </div>
    <button class="preview-slider-next"><i class="fas fa-chevron-right"></i></button>
    <div class="preview-slider-counter" id="previewSliderCounter"></div>
</div>

<!-- JS -->
<script src="assets/js/search.js"></script>
<script src="assets/js/photo.js?v=<?php echo time(); ?>"></script>
<script src="assets/js/hashtag.js"></script>
<script src="assets/js/like.js"></script>
<script src="assets/js/comment.js?v=<?php echo time(); ?>"></script>
<script src="assets/js/retweet.js?v=<?php echo time(); ?>"></script>
<script src="assets/js/follow.js?v=<?php echo time(); ?>"></script>
<script src="https://kit.fontawesome.com/38e12cc51b.js" crossorigin="anonymous"></script>
<script src="assets/js/jquery-3.5.1.min.js"></script>
<script src="assets/js/popper.min.js"></script>
<script src="assets/js/bootstrap.min.js"></script>
<script src="assets/js/bookmark.js?v=<?php echo time(); ?>"></script>

<!-- Smart Emoji Picker JS -->
<script type="module">
    import { Picker } from 'https://cdn.jsdelivr.net/npm/emoji-picker-element@1.18.2/index.js';
    
    const picker = new Picker();
    const pickerContainer = document.getElementById('emojiPickerContainer');
    pickerContainer.appendChild(picker);
    
    const emojiTrigger = document.getElementById('emojiTrigger');
    
    emojiTrigger.addEventListener('click', (e) => {
        e.stopPropagation();
        const isHidden = pickerContainer.style.display === 'none' || pickerContainer.style.display === '';
        
        if (isHidden) {
            const rect = emojiTrigger.getBoundingClientRect();
            const pickerHeight = 400;
            const spaceBelow = window.innerHeight - rect.bottom;
            
            if (spaceBelow > pickerHeight) {
                pickerContainer.style.top = '100%';
                pickerContainer.style.bottom = 'auto';
            } else {
                pickerContainer.style.bottom = '100%';
                pickerContainer.style.top = 'auto';
            }
            
            pickerContainer.style.display = 'block';
        } else {
            pickerContainer.style.display = 'none';
        }
    });
    
    picker.addEventListener('emoji-click', (event) => {
        const emoji = event.detail.unicode;
        const textarea = document.getElementById('tweetStatus');
        const start = textarea.selectionStart;
        const end = textarea.selectionEnd;
        const currentText = textarea.value;
        const newText = currentText.substring(0, start) + emoji + currentText.substring(end);
        
        if (newText.length <= 280) {
            textarea.value = newText;
            textarea.setSelectionRange(start + emoji.length, start + emoji.length);
            textarea.dispatchEvent(new Event('input'));
            textarea.focus();
        }
        
        pickerContainer.style.display = 'none';
    });
    
    document.addEventListener('click', (e) => {
        if (!pickerContainer.contains(e.target) && e.target !== emojiTrigger) {
            pickerContainer.style.display = 'none';
        }
    });
</script>

<script>
// ========== AUTO-HEIGHT TEXTAREA WITH CONTAINER EXPANSION ==========
function autoResizeTextareaWithContainer() {
    const textarea = document.getElementById('tweetStatus');
    if (textarea) {
        // Reset height to auto so it can shrink if text is deleted
        textarea.style.height = 'auto';
        
        // Set height to match the internal content height
        // This pushes the ".bottom" (icons) and the entire box down
        textarea.style.height = (textarea.scrollHeight) + 'px';
    }
}

// ========== TEXTAREA EVENT LISTENERS WITH CONTAINER FIX ==========
$(document).ready(function() {
    const $textarea = $('#tweetStatus');
    const $charCount = $('#charCount');
    const $submitBtn = $('#tweetSubmitBtn');
    const $mediaInput = $('#tweet_media');
    const $videoInput = $('#tweet_video');
    const maxChars = 280;
    
    // Store all media files (images + videos)
    let allMediaFiles = []; // { type: 'image', file: file, preview: url } or { type: 'video', file: file, preview: url }
    let currentPreviewIndex = 0;
    
    // Apply auto-resize on input event
    $textarea.on('input', function() {
        // Reset height to auto so it can shrink if text is deleted
        this.style.height = 'auto';
        
        // Set height to match the internal content height
        // This pushes the ".bottom" (icons) and the entire box down
        this.style.height = (this.scrollHeight) + 'px';
        
        // Update character counter
        updateCharCount();
    });
    
    // Also trigger on paste to handle large blocks of text immediately
    $textarea.on('paste', function() {
        setTimeout(() => {
            this.style.height = 'auto';
            this.style.height = (this.scrollHeight) + 'px';
            updateCharCount();
        }, 0);
    });
    
    // Initial resize
    setTimeout(function() {
        autoResizeTextareaWithContainer();
    }, 100);
    
    function checkCanSubmit() {
        const hasText = $textarea.val().trim().length > 0;
        const hasMedia = allMediaFiles.length > 0;
        const remaining = maxChars - $textarea.val().length;
        
        const canSubmit = (hasText || hasMedia) && remaining >= 0;
        
        $submitBtn.prop('disabled', !canSubmit);
        $submitBtn.css('opacity', canSubmit ? '1' : '0.5');
        
        return canSubmit;
    }
    
    function updateCharCount() {
        const currentLength = $textarea.val().length;
        const remaining = maxChars - currentLength;
        $charCount.text(remaining);
        
        if (remaining < 0) {
            $charCount.addClass('limit-reached');
        } else if (remaining < 20) {
            $charCount.addClass('near-limit');
        } else {
            $charCount.removeClass('limit-reached near-limit');
        }
        
        if (currentLength > maxChars) {
            $textarea.val($textarea.val().substring(0, maxChars));
            $charCount.text(0);
            autoResizeTextareaWithContainer();
        }
        
        checkCanSubmit();
    }
    
    function updateMediaPreview() {
        const $previewContainer = $('#mediaPreviewContainer');
        $previewContainer.empty();
        
        const imageCount = allMediaFiles.filter(m => m.type === 'image').length;
        const videoCount = allMediaFiles.filter(m => m.type === 'video').length;
        
        $('.upload-count').text(imageCount + '/5');
        $('.video-upload-count').text(videoCount + '/5');
        
        if (allMediaFiles.length === 0) return;
        
        allMediaFiles.forEach((item, index) => {
            const isVideo = item.type === 'video';
            const previewHtml = `
                <div class="media-preview-item ${isVideo ? 'video-preview-overlay' : ''}" data-index="${index}" data-type="${item.type}">
                    ${isVideo ? 
                        `<video src="${item.preview}" preload="metadata" muted></video>` :
                        `<img src="${item.preview}" alt="Preview ${index + 1}">`
                    }
                    <button type="button" class="remove-media" data-index="${index}">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            `;
            $previewContainer.append(previewHtml);
        });
        
        checkCanSubmit();
    }
    
    // Handle image files
    $mediaInput.on('change', function(e) {
        const files = Array.from(e.target.files);
        const currentImageCount = allMediaFiles.filter(m => m.type === 'image').length;
        
        if (currentImageCount + files.length > 5) {
            alert(`You can only upload up to 5 images per tweet. You already have ${currentImageCount} image(s).`);
            this.value = '';
            return;
        }
        
        files.forEach(file => {
            if (file.type.startsWith('image/')) {
                const reader = new FileReader();
                reader.onload = function(ev) {
                    allMediaFiles.push({
                        type: 'image',
                        file: file,
                        preview: ev.target.result
                    });
                    updateMediaPreview();
                };
                reader.readAsDataURL(file);
            }
        });
        
        this.value = '';
    });
    
    // Handle video files
    $videoInput.on('change', function(e) {
        const files = Array.from(e.target.files);
        const currentVideoCount = allMediaFiles.filter(m => m.type === 'video').length;
        
        if (currentVideoCount + files.length > 5) {
            alert(`You can only upload up to 5 videos per tweet. You already have ${currentVideoCount} video(s).`);
            this.value = '';
            return;
        }
        
        files.forEach(file => {
            if (file.type.startsWith('video/')) {
                const reader = new FileReader();
                reader.onload = function(ev) {
                    allMediaFiles.push({
                        type: 'video',
                        file: file,
                        preview: ev.target.result
                    });
                    updateMediaPreview();
                };
                reader.readAsDataURL(file);
            }
        });
        
        this.value = '';
    });
    
    // Remove media from preview
    $(document).on('click', '.remove-media', function(e) {
        e.preventDefault();
        e.stopPropagation();
        const index = $(this).closest('.media-preview-item').data('index');
        allMediaFiles.splice(index, 1);
        updateMediaPreview();
        
        // Update file inputs
        const imageFiles = allMediaFiles.filter(m => m.type === 'image').map(m => m.file);
        const videoFiles = allMediaFiles.filter(m => m.type === 'video').map(m => m.file);
        
        const imageDataTransfer = new DataTransfer();
        imageFiles.forEach(file => imageDataTransfer.items.add(file));
        $mediaInput[0].files = imageDataTransfer.files;
        
        const videoDataTransfer = new DataTransfer();
        videoFiles.forEach(file => videoDataTransfer.items.add(file));
        $videoInput[0].files = videoDataTransfer.files;
    });
    
    // Open preview slider when clicking on preview item
    $(document).on('click', '.media-preview-item', function(e) {
        if ($(e.target).hasClass('remove-media') || $(e.target).closest('.remove-media').length) {
            return;
        }
        currentPreviewIndex = $(this).data('index');
        openPreviewSlider();
    });
    
    // Preview Slider Functions
    function openPreviewSlider() {
        updatePreviewSlider();
        $('#previewSliderModal').addClass('active');
        $('body').css('overflow', 'hidden');
    }
    
    function updatePreviewSlider() {
        const item = allMediaFiles[currentPreviewIndex];
        const $img = $('#previewSliderImage');
        const $video = $('#previewSliderVideo');
        
        if (item.type === 'image') {
            $img.attr('src', item.preview).show();
            $video.hide();
            $video[0].pause();
        } else {
            $video.show();
            $video.attr('src', item.preview);
            $video[0].load();
            $img.hide();
        }
        
        $('#previewSliderCounter').text((currentPreviewIndex + 1) + ' of ' + allMediaFiles.length);
        
        if (allMediaFiles.length <= 1) {
            $('.preview-slider-prev, .preview-slider-next').hide();
        } else {
            $('.preview-slider-prev, .preview-slider-next').show();
        }
    }
    
    function closePreviewSlider() {
        $('#previewSliderModal').removeClass('active');
        $('body').css('overflow', '');
        const $video = $('#previewSliderVideo');
        $video[0].pause();
        $video.attr('src', '');
    }
    
    $('.preview-slider-next').on('click', function(e) {
        e.stopPropagation();
        if (currentPreviewIndex < allMediaFiles.length - 1) {
            currentPreviewIndex++;
            updatePreviewSlider();
        }
    });
    
    $('.preview-slider-prev').on('click', function(e) {
        e.stopPropagation();
        if (currentPreviewIndex > 0) {
            currentPreviewIndex--;
            updatePreviewSlider();
        }
    });
    
    $('.preview-slider-close').on('click', function(e) {
        e.stopPropagation();
        closePreviewSlider();
    });
    
    $('#previewSliderModal').on('click', function(e) {
        if (e.target === this) {
            closePreviewSlider();
        }
    });
    
    $(document).on('keydown', function(e) {
        if ($('#previewSliderModal').hasClass('active')) {
            if (e.key === 'ArrowLeft') {
                $('.preview-slider-prev').click();
            } else if (e.key === 'ArrowRight') {
                $('.preview-slider-next').click();
            } else if (e.key === 'Escape') {
                closePreviewSlider();
            }
        }
    });
    
    // Form submission - update file inputs before submit
    $('#tweetForm').on('submit', function(e) {
        const imageFiles = allMediaFiles.filter(m => m.type === 'image').map(m => m.file);
        const videoFiles = allMediaFiles.filter(m => m.type === 'video').map(m => m.file);
        
        const imageDataTransfer = new DataTransfer();
        imageFiles.forEach(file => imageDataTransfer.items.add(file));
        $mediaInput[0].files = imageDataTransfer.files;
        
        const videoDataTransfer = new DataTransfer();
        videoFiles.forEach(file => videoDataTransfer.items.add(file));
        $videoInput[0].files = videoDataTransfer.files;
        
        return true;
    });
    
    // Character counter event listeners
    $textarea.on('input', function() {
        updateCharCount();
    });
    $textarea.on('paste', function() {
        setTimeout(updateCharCount, 10);
    });
    
    updateCharCount();
    
    // Trigger file inputs
    let mediaClickInProgress = false;
    let videoClickInProgress = false;
    
    $('label[for="tweet_media"]').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        if (!mediaClickInProgress) {
            mediaClickInProgress = true;
            $('#tweet_media').trigger('click');
            setTimeout(function() {
                mediaClickInProgress = false;
            }, 500);
        }
        return false;
    });
    
    $('label[for="tweet_video"]').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        if (!videoClickInProgress) {
            videoClickInProgress = true;
            $('#tweet_video').trigger('click');
            setTimeout(function() {
                videoClickInProgress = false;
            }, 500);
        }
        return false;
    });
    
    $('#tweet_media, #tweet_video').on('click', function(e) {
        e.stopPropagation();
    });
});

// Tweet Text Read More/Less Function
function toggleTweetText(btn, tweetId) {
    const tweetText = document.getElementById(`tweet-text-${tweetId}`);
    if (tweetText.classList.contains('collapsed')) {
        tweetText.classList.remove('collapsed');
        btn.textContent = 'Show less';
    } else {
        tweetText.classList.add('collapsed');
        btn.textContent = 'Show more';
    }
}

// ========== INFINITE SCROLL FOR HOME PAGE ==========
$(document).ready(function() {
    let offset = 20;
    let loading = false;
    let hasMore = true;
    let initialTweetsCount = $('.box-tweet.feed').length;
    
    if (initialTweetsCount < 20) {
        hasMore = false;
    }
    
    $(window).on('scroll', function() {
        if ($(window).scrollTop() + $(window).height() >= $(document).height() - 300) {
            if (!loading && hasMore) {
                loading = true;
                $('.loading-spinner').show();
                
                $.ajax({
                    url: 'includes/load_more_home.php',
                    type: 'POST',
                    data: { offset: offset },
                    success: function(response) {
                        $('.loading-spinner').hide();
                        
                        if ($.trim(response) !== '') {
                            $('.home-feed-container').append(response);
                            offset += 20;
                            loading = false;
                        } else {
                            hasMore = false;
                            $('.no-more-posts').show();
                            loading = false;
                        }
                    },
                    error: function() {
                        $('.loading-spinner').hide();
                        loading = false;
                        console.log('Error loading more posts');
                    }
                });
            }
        }
    });
});

// ========== SEARCH FUNCTIONALITY ==========
$(document).ready(function() {
    let searchTimeout;
    let currentQuery = '';
    
    $('#searchInput').on('input', function() {
        clearTimeout(searchTimeout);
        const query = $(this).val().trim();
        currentQuery = query;
        
        const $searchResult = $('.search-result');
        
        if (query.length < 2) {
            $searchResult.fadeOut(200);
            return;
        }
        
        searchTimeout = setTimeout(function() {
            $searchResult.html('<div class="search-loading"><i class="fas fa-spinner fa-pulse"></i> Searching...</div>').fadeIn(200);
            
            $.ajax({
                url: 'includes/search_ajax.php',
                type: 'POST',
                data: { search: query, offset: 0 },
                success: function(response) {
                    if (response.trim() === '') {
                        $searchResult.html('<div class="search-no-results"><i class="fas fa-user-slash"></i> No users found</div>');
                    } else {
                        $searchResult.html(response);
                    }
                },
                error: function() {
                    $searchResult.html('<div class="search-no-results"><i class="fas fa-exclamation-triangle"></i> Error searching</div>');
                }
            });
        }, 300);
    });
    
    $(document).on('click', '.load-more-search', function() {
        const button = $(this);
        const loadMoreDiv = button.closest('.search-load-more');
        const offset = loadMoreDiv.data('offset');
        
        button.html('<i class="fas fa-spinner fa-pulse"></i> Loading...');
        
        $.ajax({
            url: 'includes/search_ajax.php',
            type: 'POST',
            data: { search: currentQuery, offset: offset },
            success: function(response) {
                loadMoreDiv.remove();
                $('.search-result').append(response);
            },
            error: function() {
                button.html('<i class="fas fa-exclamation-triangle"></i> Error loading');
            }
        });
    });
    
    $(document).on('click', function(e) {
        if (!$(e.target).closest('.input-group').length) {
            $('.search-result').fadeOut(200);
        }
    });
});
</script>


<script>
// ========== MENTION / HASHTAG EMPTY ROW FIX ==========
// The suggestion script can empty the <ul>, but the second file's CSS grid
// was still reserving a blank "hashtags" row. This observer keeps that row
// hidden unless the list contains real suggestion items/text.
$(document).ready(function() {
    const $bottom = $('#whathappen .bottom');
    const $hashBox = $('#whathappen .hash-box');
    const hashList = document.querySelector('#whathappen .hash-box ul');

    function updateHashBoxVisibility() {
        if (!hashList) return;

        const hasListItems = hashList.querySelectorAll('li, a, button, span').length > 0;
        const hasText = hashList.textContent.trim().length > 0;

        if (hasListItems || hasText) {
            $bottom.addClass('has-hashtags');
            $hashBox.show();
        } else {
            $bottom.removeClass('has-hashtags');
            $hashBox.hide();
            $(hashList).empty();
        }
    }

    updateHashBoxVisibility();

    if (hashList) {
        const observer = new MutationObserver(updateHashBoxVisibility);
        observer.observe(hashList, {
            childList: true,
            subtree: true,
            characterData: true
        });
    }

    $('#tweetStatus').on('input keyup blur', function() {
        setTimeout(updateHashBoxVisibility, 0);
    });
});
// ========== END MENTION / HASHTAG EMPTY ROW FIX ==========
</script>

</body>
</html>