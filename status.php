<?php
   include 'core/init.php';
  
   $user_id = $_SESSION['user_id'];
  
   $user = User::getData($user_id);
   
   if (User::checkLogIn() === false) 
   header('location: index.php');


$tweet_id = $_GET['post_id'];

// ✅ CASE 1: It is a real tweet
$tweet = Tweet::getData($tweet_id);

// ✅ CASE 2: It is a comment → get parent tweet
if (!$tweet) {
    $comment = Tweet::getComment($tweet_id);
    if ($comment) {
        $tweet = Tweet::getData($comment->post_id);
        $tweet_id = $comment->post_id;
    }
}

// ✅ CASE 3: It is a reply → get parent comment → then parent tweet
if (!$tweet) {
    $reply = Tweet::getReply($tweet_id);
    if ($reply) {
        $parent_comment = Tweet::getComment($reply->comment_id);
        if ($parent_comment) {
            $tweet = Tweet::getData($parent_comment->post_id);
            $tweet_id = $parent_comment->post_id;
        }
    }
}

// ✅ If still false → stop (invalid ID)
if (!$tweet) {
    die("Invalid tweet/comment/reply ID");
}

   $who_users = Follow::whoToFollow($user_id);
   $notify_count = User::CountNotification($user_id);
 
 
   
   // Count total unread messages for this user
$conn = Connect::connect();
$stmt = $conn->prepare("SELECT COUNT(*) AS unread_total FROM messages WHERE receiver_id = ? AND is_read = 0");
$stmt->execute([$user_id]);
$unreadMessages = $stmt->fetch(PDO::FETCH_OBJ)->unread_total ?? 0;
?>
    
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Status | TwitterClone</title>
    <base href="<?php echo BASE_URL; ?>">
    <link rel="shortcut icon" type="image/png" href="assets/images/twitter.svg"> 
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link rel="stylesheet" href="assets/css/home_style.css?v=<?php echo time(); ?>">
    <style>
.toggle-replies {
  color: #1da1f2;
  font-weight: 500;
  margin-top: 5px;
  display: block;
}
.toggle-replies:hover {
  text-decoration: underline;
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
/* Styling for Comment and Reply dropdowns */
.comment-options, .reply-options {
    position: relative;
    float: right;
    z-index: 10;
}

.dropdown-menu-comment, .dropdown-menu-reply {
    display: none;
    position: absolute;
    right: 0;
    top: 20px;
    background: #fff;
    border: 1px solid #e1e8ed;
    border-radius: 4px;
    min-width: 120px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    z-index: 100;
}

.dropdown-menu-comment a, .dropdown-menu-reply a {
    display: block;
    padding: 10px;
    color: #e0245e;
    text-decoration: none;
    font-size: 14px;
}

.dropdown-menu-comment a:hover, .dropdown-menu-reply a:hover {
    background-color: #f5f8fa;
}

/* ========== COMPACT GALLERY STYLES ========== */
/* Single image - smaller and minimal */
.img-post-tweet {
    width: 100%;
    max-width: 100%;
    max-height: 350px;
    object-fit: contain;
    border-radius: 12px;
    background: #f5f8fa;
    cursor: pointer;
    transition: opacity 0.2s ease;
    position: relative;
    z-index: 5;
}

.img-post-tweet:hover {
    opacity: 0.95;
}

/* Container for images - minimal margin */
.mt-post-tweet {
    display: flex;
    justify-content: center;
    align-items: center;
    border-radius: 12px;
    overflow: hidden;
    background: #f5f8fa;
    margin-top: 8px;
    width: 100%;
}

/* Gallery styles for multiple images - compact */
.tweet-gallery {
    display: grid;
    gap: 3px;
    margin-top: 8px;
    border-radius: 12px;
    background: #f5f8fa;
    width: 100%;
    overflow: hidden;
    position: relative;
    z-index: 5;
}

/* Default responsive grid - smaller min width */
.tweet-gallery {
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
}

/* 2 images - side by side */
.tweet-gallery[data-count="2"] {
    grid-template-columns: repeat(2, 1fr);
}

/* 3 images - first two side by side, third takes full width but smaller */
.tweet-gallery[data-count="3"] {
    grid-template-columns: repeat(2, 1fr);
}
.tweet-gallery[data-count="3"] .gallery-item:last-child {
    grid-column: 1 / -1;
    aspect-ratio: 2/1;
    max-height: 220px;
}
.tweet-gallery[data-count="3"] .gallery-item:last-child img {
    object-fit: cover;
}

/* 4 images - 2x2 grid */
.tweet-gallery[data-count="4"] {
    grid-template-columns: repeat(2, 1fr);
}

/* 5 images - first row 3, second row 2 */
.tweet-gallery[data-count="5"] {
    grid-template-columns: repeat(3, 1fr);
}
.tweet-gallery[data-count="5"] .gallery-item:nth-child(4),
.tweet-gallery[data-count="5"] .gallery-item:nth-child(5) {
    grid-column: span 1;
}

/* 6 images - 3x2 grid */
.tweet-gallery[data-count="6"] {
    grid-template-columns: repeat(3, 1fr);
}

/* 7+ images - continue with 3 columns */
.tweet-gallery[data-count="7"],
.tweet-gallery[data-count="8"],
.tweet-gallery[data-count="9"] {
    grid-template-columns: repeat(3, 1fr);
}

.gallery-item {
    position: relative;
    cursor: pointer;
    overflow: hidden;
    aspect-ratio: 1/1;
    background: #f5f8fa;
    width: 100%;
    z-index: 5;
}

.gallery-item img,
.gallery-item video {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    transition: transform 0.2s ease;
}

.gallery-item:hover img,
.gallery-item:hover video {
    transform: scale(1.02);
}

/* Video play icon overlay - always visible for grid videos */
.gallery-item.video-item::before {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    width: 54px;
    height: 54px;
    transform: translate(-50%, -50%);
    border-radius: 50%;
    background: rgba(0, 0, 0, 0.48);
    box-shadow: 0 4px 16px rgba(0,0,0,0.35);
    pointer-events: none;
    z-index: 7;
}

/* CSS triangle play icon, no Font Awesome dependency needed */
.gallery-item.video-item::after {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-35%, -50%);
    width: 0;
    height: 0;
    border-top: 13px solid transparent;
    border-bottom: 13px solid transparent;
    border-left: 19px solid #fff;
    pointer-events: none;
    z-index: 8;
    filter: drop-shadow(0 2px 5px rgba(0,0,0,0.55));
}

.gallery-item.more-overlay {
    position: relative;
}

/* More badge uses a child span now, so it does not override video play icon */
.gallery-item.more-overlay .more-badge {
    position: absolute;
    inset: 0;
    background: rgba(0,0,0,0.62);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    font-weight: bold;
    color: white;
    font-family: 'Poppins', sans-serif;
    z-index: 12;
    pointer-events: none;
}
/* ========== UNIFIED LIGHTBOX ========== */
.unified-lightbox {
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

.unified-lightbox.active {
    display: flex;
}

.lightbox-container {
    position: relative;
    max-width: 90%;
    max-height: 90%;
}

.lightbox-image {
    max-width: 90vw;
    max-height: 85vh;
    object-fit: contain;
    border-radius: 8px;
    display: none;
}

.lightbox-video {
    max-width: 90vw;
    max-height: 85vh;
    border-radius: 8px;
    background: #000;
    display: none;
}

.lightbox-prev, .lightbox-next {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    background: rgba(0,0,0,0.6);
    color: white;
    border: none;
    width: 45px;
    height: 45px;
    border-radius: 50%;
    cursor: pointer;
    font-size: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
    z-index: 100001;
}

.lightbox-prev:hover, .lightbox-next:hover {
    background: rgba(0,0,0,0.8);
    transform: translateY(-50%) scale(1.1);
}

.lightbox-prev { left: 20px; }
.lightbox-next { right: 20px; }

.lightbox-close {
    position: absolute;
    top: 20px;
    right: 30px;
    color: white;
    font-size: 30px;
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

.lightbox-close:hover {
    background: rgba(255,255,255,0.2);
    transform: scale(1.1);
}

.lightbox-counter {
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

.lightbox-type-badge {
    position: absolute;
    top: 20px;
    left: 20px;
    background: rgba(0,0,0,0.6);
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 12px;
    color: white;
    z-index: 100001;
}

/* Quoted tweet images - smaller */
.img-post-retweet {
    width: 100%;
    max-height: 200px;
    object-fit: contain;
    border-radius: 12px;
    background: #f5f8fa;
    display: block;
    margin: 0 auto;
    cursor: pointer;
}

.tweet-video {
    width: 100%;
    max-height: 350px;
    object-fit: contain;
    border-radius: 12px;
    background: #000;
}

/* Post timestamp styling */
.post-timestamp {
    color: #657786;
    font-size: 13px;
    margin: 12px -15px 12px -15px;
    padding: 0 15px 12px 15px;
    border-bottom: 1px solid #e1e8ed;
    clear: both;
}

.post-timestamp i {
    margin-right: 6px;
    font-size: 12px;
}

@media (max-width: 768px) {
    .tweet-gallery {
        grid-template-columns: repeat(auto-fit, minmax(90px, 1fr));
    }
    .lightbox-prev, .lightbox-next {
        width: 35px;
        height: 35px;
        font-size: 16px;
    }
    .lightbox-close {
        width: 35px;
        height: 35px;
        font-size: 24px;
        top: 15px;
        right: 15px;
    }
    .img-post-tweet {
        max-height: 280px;
    }
    .tweet-gallery[data-count="3"] .gallery-item:last-child {
        max-height: 180px;
    }
}

@media (max-width: 480px) {
    .tweet-gallery {
        grid-template-columns: repeat(auto-fit, minmax(80px, 1fr));
    }
    .tweet-gallery[data-count="3"] .gallery-item:last-child {
        max-height: 150px;
    }
    .img-post-tweet {
        max-height: 250px;
    }
}

/* ========== SHARE ICON + RESPONSIVE REACTIONS FIX ========== */
.grid-reactions {
    display: grid !important;
    grid-template-columns: repeat(5, minmax(38px, 1fr)) !important;
    align-items: center !important;
    justify-items: center !important;
    gap: 8px !important;
    width: 100% !important;
    max-width: 100% !important;
}

.grid-box-reaction {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    min-width: 0 !important;
}

.tweet-share {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 34px !important;
    height: 34px !important;
    border-radius: 50% !important;
    cursor: pointer !important;
    color: #657786 !important;
    transition: all 0.2s ease !important;
    position: relative !important;
    z-index: 20 !important;
}

.tweet-share:hover {
    background: rgba(29, 161, 242, 0.10) !important;
    color: #1da1f2 !important;
}

.tweet-share i {
    font-size: 17px !important;
    line-height: 1 !important;
}

.share-toast {
    position: fixed;
    left: 50%;
    bottom: 28px;
    transform: translateX(-50%);
    background: #0f1419;
    color: #fff;
    padding: 10px 16px;
    border-radius: 999px;
    font-size: 13px;
    font-weight: 600;
    z-index: 200000;
    display: none;
    box-shadow: 0 6px 20px rgba(0,0,0,0.25);
}

@media (max-width: 480px) {
    .grid-reactions {
        grid-template-columns: repeat(5, minmax(30px, 1fr)) !important;
        gap: 4px !important;
    }

    .tweet-share {
        width: 32px !important;
        height: 32px !important;
    }

    .tweet-share i {
        font-size: 16px !important;
    }
}
/* ========== END SHARE ICON FIX ========== */


/* ========== FORCE VISIBLE SHARE ICON STATUS PAGE ========== */
.grid-reactions {
    display: grid !important;
    grid-template-columns: repeat(5, minmax(34px, 1fr)) !important;
    align-items: center !important;
    justify-items: center !important;
    gap: 6px !important;
    width: 100% !important;
}

.grid-reactions .grid-box-reaction {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    min-width: 0 !important;
}

.tweet-share {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 36px !important;
    height: 36px !important;
    border-radius: 50% !important;
    color: #657786 !important;
    cursor: pointer !important;
    position: relative !important;
    z-index: 9999 !important;
    pointer-events: auto !important;
}

.tweet-share:hover {
    background: rgba(29, 161, 242, 0.12) !important;
    color: #1da1f2 !important;
}

.tweet-share i {
    font-size: 18px !important;
    color: inherit !important;
    display: inline-block !important;
}

.share-toast {
    position: fixed;
    left: 50%;
    bottom: 28px;
    transform: translateX(-50%);
    background: #0f1419;
    color: #fff;
    padding: 10px 16px;
    border-radius: 999px;
    font-size: 13px;
    font-weight: 600;
    z-index: 200000;
    display: none;
    box-shadow: 0 6px 20px rgba(0,0,0,0.25);
}

@media (max-width: 480px) {
    .grid-reactions {
        grid-template-columns: repeat(5, minmax(28px, 1fr)) !important;
        gap: 3px !important;
    }
}
/* ========== END FORCE SHARE ICON ========== */


/* =====================================================
   PROFESSIONAL COMMENT + REPLY CARD POLISH
   Visual styling only. No PHP/JS/functionality changed.
   ===================================================== */

.comments{
    background:#fff !important;
    padding:10px 0 18px 0 !important;
}

.comments .box-comment.feed,
.comments .box-reply.feed{
    position:relative !important;
    background:#fff !important;
    border:1px solid #e6ecf0 !important;
    border-radius:16px !important;
    margin:10px 14px !important;
    padding:12px 12px !important;
    box-shadow:0 2px 8px rgba(15,20,25,0.04) !important;
    transition:background .2s ease, box-shadow .2s ease, transform .2s ease !important;
    cursor:default !important;
}

.comments .box-comment.feed:hover,
.comments .box-reply.feed:hover{
    background:#fbfdff !important;
    box-shadow:0 6px 18px rgba(15,20,25,0.08) !important;
    transform:translateY(-1px) !important;
}

.comments .box-reply.feed{
    margin-left:42px !important;
    border-left:3px solid #d6ecfb !important;
    background:#fcfdff !important;
}

.comments .box-comment .grid-tweet,
.comments .box-reply .grid-tweet{
    margin-left:0 !important;
    margin-right:0 !important;
    grid-template-columns:48px minmax(0,1fr) !important;
    gap:10px !important;
    align-items:flex-start !important;
}

.comments .box-comment .img-user-tweet,
.comments .box-reply .img-user-tweet{
    width:42px !important;
    height:42px !important;
    border-radius:50% !important;
    object-fit:cover !important;
    border:2px solid #fff !important;
    box-shadow:0 1px 5px rgba(15,20,25,0.12) !important;
}

.comments .box-reply .img-user-tweet{
    width:36px !important;
    height:36px !important;
}

.comments .box-comment p,
.comments .box-reply p{
    margin:0 !important;
    line-height:1.42 !important;
}

.comments .box-comment p:first-child,
.comments .box-reply p:first-child{
    display:flex !important;
    align-items:center !important;
    flex-wrap:wrap !important;
    gap:4px !important;
    padding-right:28px !important;
    margin-bottom:5px !important;
}

.comments .box-comment strong,
.comments .box-reply strong{
    font-size:14px !important;
    font-weight:700 !important;
    color:#0f1419 !important;
}

.comments .box-comment .username-twitter,
.comments .box-reply .username-twitter{
    font-size:13px !important;
    color:#657786 !important;
}

.comments .box-comment p:nth-of-type(2),
.comments .box-reply p:nth-of-type(2){
    font-size:14px !important;
    color:#0f1419 !important;
    line-height:1.5 !important;
    margin-top:3px !important;
    word-break:break-word !important;
}

.comments .grid-reactions{
    margin-top:8px !important;
    width:auto !important;
    max-width:160px !important;
    display:flex !important;
    align-items:center !important;
    gap:14px !important;
}

.comments .grid-box-reaction-rep{
    display:flex !important;
    align-items:center !important;
    padding-top:0 !important;
    font-size:13px !important;
}

.comments .hover-reaction-rep{
    height:30px !important;
    min-width:30px !important;
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    border-radius:999px !important;
    color:#657786 !important;
    transition:all .2s ease !important;
}

.comments .hover-reaction-rep:hover{
    background:rgba(29,161,242,0.10) !important;
    color:#1da1f2 !important;
}

.comments .mt-counter p,
.comments .likes-count p{
    font-size:12px !important;
    color:#657786 !important;
    margin-left:3px !important;
}

.comment-options,
.reply-options{
    position:absolute !important;
    top:10px !important;
    right:12px !important;
    float:none !important;
    z-index:30 !important;
}

.comment-options > i,
.reply-options > i{
    width:28px !important;
    height:28px !important;
    display:flex !important;
    align-items:center !important;
    justify-content:center !important;
    border-radius:50% !important;
    color:#657786 !important;
    padding:0 !important;
    transition:all .2s ease !important;
}

.comment-options > i:hover,
.reply-options > i:hover{
    background:rgba(29,161,242,0.10) !important;
    color:#1da1f2 !important;
}

.dropdown-menu-comment,
.dropdown-menu-reply{
    border:1px solid #e6ecf0 !important;
    border-radius:12px !important;
    overflow:hidden !important;
    min-width:135px !important;
    box-shadow:0 8px 24px rgba(15,20,25,0.14) !important;
}

.dropdown-menu-comment a,
.dropdown-menu-reply a{
    font-size:13px !important;
    padding:10px 12px !important;
}

.toggle-replies{
    display:inline-flex !important;
    align-items:center !important;
    gap:6px !important;
    margin:8px 18px 8px auto !important;
    padding:6px 12px !important;
    border-radius:999px !important;
    background:rgba(29,161,242,0.08) !important;
    color:#1da1f2 !important;
    font-size:13px !important;
    font-weight:600 !important;
    width:max-content !important;
}

.toggle-replies:hover{
    background:rgba(29,161,242,0.14) !important;
    text-decoration:none !important;
}

/* soft thread connector for replies */
.replies-container{
    position:relative !important;
}

.replies-container::before{
    content:"" !important;
    position:absolute !important;
    top:0 !important;
    bottom:6px !important;
    left:30px !important;
    width:2px !important;
    background:#d6ecfb !important;
    border-radius:999px !important;
}

@media (max-width:768px){
    .comments .box-comment.feed,
    .comments .box-reply.feed{
        margin:8px 8px !important;
        border-radius:14px !important;
        padding:10px !important;
    }

    .comments .box-reply.feed{
        margin-left:28px !important;
    }

    .comments .box-comment .grid-tweet,
    .comments .box-reply .grid-tweet{
        grid-template-columns:40px minmax(0,1fr) !important;
        gap:8px !important;
    }

    .comments .box-comment .img-user-tweet,
    .comments .box-reply .img-user-tweet{
        width:36px !important;
        height:36px !important;
    }

    .comments .box-comment p:nth-of-type(2),
    .comments .box-reply p:nth-of-type(2){
        font-size:13px !important;
    }

    .replies-container::before{
        left:22px !important;
    }
}


/* =====================================================
   SAFE REPLY-IN-COMMENT VISUAL FIX
   No PHP/HTML structure changed.
   Makes replies visually attached to parent comment card.
   ===================================================== */

.comments .box-comment.feed{
    margin-bottom:0 !important;
    border-bottom-left-radius:0 !important;
    border-bottom-right-radius:0 !important;
    border-bottom:0 !important;
}

.comments .box-comment.feed + .replies-container{
    margin:0 14px 0 14px !important;
    padding:0 12px 12px 60px !important;
    background:#fff !important;
    border-left:1px solid #e6ecf0 !important;
    border-right:1px solid #e6ecf0 !important;
    border-bottom:1px solid #e6ecf0 !important;
    border-bottom-left-radius:16px !important;
    border-bottom-right-radius:16px !important;
    box-shadow:0 2px 8px rgba(15,20,25,0.04) !important;
    position:relative !important;
}

.comments .box-comment.feed + .replies-container::before{
    content:"" !important;
    position:absolute !important;
    top:0 !important;
    bottom:12px !important;
    left:38px !important;
    width:2px !important;
    background:#d6ecfb !important;
    border-radius:999px !important;
}

.comments .box-comment.feed + .replies-container .box-reply.feed{
    margin:8px 0 0 0 !important;
    padding:10px !important;
    background:#f8fbfd !important;
    border:1px solid #edf1f4 !important;
    border-left:0 !important;
    border-radius:12px !important;
    box-shadow:none !important;
    transform:none !important;
}

.comments .box-comment.feed + .replies-container .box-reply.feed:hover{
    background:#f4faff !important;
    box-shadow:none !important;
    transform:none !important;
}

.comments .box-comment.feed + .replies-container + .toggle-replies{
    margin:0 14px 10px auto !important;
    border-radius:0 0 16px 16px !important;
}

.comments .box-comment.feed:not(:has(+ .replies-container)){
    border-bottom:1px solid #e6ecf0 !important;
    border-bottom-left-radius:16px !important;
    border-bottom-right-radius:16px !important;
    margin-bottom:10px !important;
}

@media (max-width:768px){
    .comments .box-comment.feed + .replies-container{
        margin-left:8px !important;
        margin-right:8px !important;
        padding-left:48px !important;
    }

    .comments .box-comment.feed + .replies-container::before{
        left:30px !important;
    }
}


/* ========== POST DELETE DROPDOWN VISIBILITY FIX ========== */
/* Keeps the post Delete menu above the right sidebar and inside the post area */
.box-tweet.feed,
.grid-posts,
.border-right {
    overflow: visible !important;
}

.box-tweet.feed .tweet-options {
    position: absolute !important;
    top: 5px !important;
    right: 12px !important;
    z-index: 300000 !important;
}

.box-tweet.feed .tweet-options > i {
    width: 30px !important;
    height: 30px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    border-radius: 50% !important;
    color: #657786 !important;
    background: transparent !important;
}

.box-tweet.feed .tweet-options > i:hover {
    background: rgba(29, 161, 242, 0.10) !important;
    color: #1da1f2 !important;
}

.box-tweet.feed .tweet-options .dropdown-menu {
    position: absolute !important;
    top: 32px !important;
    right: 0 !important;
    left: auto !important;
    transform: none !important;
    min-width: 135px !important;
    max-width: 160px !important;
    background: #fff !important;
    border: 1px solid #e6ecf0 !important;
    border-radius: 12px !important;
    overflow: hidden !important;
    box-shadow: 0 8px 24px rgba(15,20,25,0.16) !important;
    z-index: 300001 !important;
}

.box-tweet.feed .tweet-options .dropdown-menu .dropdown-item {
    display: flex !important;
    align-items: center !important;
    width: 100% !important;
    padding: 10px 14px !important;
    color: #e0245e !important;
    background: #fff !important;
    text-decoration: none !important;
    font-size: 14px !important;
    font-weight: 500 !important;
    white-space: nowrap !important;
}

.box-tweet.feed .tweet-options .dropdown-menu .dropdown-item:hover {
    background: #f5f8fa !important;
}
@media (max-width: 768px) {
    .box-tweet.feed .tweet-options {
        right: 10px !important;
    }
}
/* ========== END POST DELETE DROPDOWN VISIBILITY FIX ========== */

</style>
   
</head>
<body>
<script src="assets/js/jquery-3.5.1.min.js"></script>
  
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
              
                
                <div class="container" style="border-bottom: 1px solid #E9ECEF;">
                 
                  <div class="row">
                       <div class="col-xs-1">
                 <a href="javascript: history.go(-1);"> <i style="font-size:20px;" class="fas fa-arrow-left arrow-style"></i> </a>
                       </div>
                       <div class="col-xs-10 mt-1">
                           <p class="tweet-name" style="font-weight:700"> Tweet</p>
                      </div>
                  </div>
                  <div class="part-2"></div>
                </div>
                
             
            </div>
          </div> 
          
          <div class="box-fixed" id="box-fixed"></div>
          
          <?php 

                $retweet_sign = false;
                $retweet_comment =false;
                $qoq = false;

            if (Tweet::isTweet($tweet->id)) {

              $tweet_user = User::getData($tweet->user_id) ;
              $tweet_real = Tweet::getTweet($tweet->id);
              $timeAgo = Tweet::getTimeAgo($tweet->post_on) ; 
              $fullDate = date('F j, Y \a\t g:i A', strtotime($tweet->post_on));
              $likes_count = Tweet::countLikes($tweet->id) ;
              $user_like_it = Tweet::userLikeIt($user_id ,$tweet->id);
              $retweets_count = Tweet::countRetweets($tweet->id) ;
              $user_retweeted_it = Tweet::userRetweeetedIt($user_id ,$tweet->id);

            } else if (Tweet::isRetweet($tweet->id)) {

              $retweet = Tweet::getRetweet($tweet->id);

              if ($retweet->retweet_msg == null) {
                
                    if ($retweet->retweet_id == null) {
                      
                      // if retweeted normal tweet
                      $retweeted_tweet = Tweet::getTweet($retweet->tweet_id);
                    $tweet_user = User::getData($retweeted_tweet->user_id) ;
                    $tweet_real = Tweet::getTweet($retweet->tweet_id);
                    $timeAgo = Tweet::getTimeAgo($tweet_real->post_on) ; 
                    $fullDate = date('F j, Y \a\t g:i A', strtotime($tweet_real->post_on));
                    $likes_count = Tweet::countLikes($retweet->tweet_id) ;
                    $user_like_it = Tweet::userLikeIt($user_id ,$retweet->tweet_id);
                    $retweets_count = Tweet::countRetweets($retweet->tweet_id) ;
                    $user_retweeted_it = Tweet::userRetweeetedIt($user_id ,$retweet->tweet_id); 
                    $retweeted_user = User::getData($tweet->user_id);
                    $retweet_sign = true;
                    } else {

                      // this condtion if user retweeted quoted tweet or quote of quote tweet


                    $retweeted_tweet = Tweet::getRetweet($retweet->retweet_id);

                        if($retweeted_tweet->tweet_id != null) {
                        // here it's retweeted quoted
                        // if($retweeted_tweet->) 
                        $tweet_user = User::getData($retweeted_tweet->user_id) ;
                        $timeAgo = Tweet::getTimeAgo($retweeted_tweet->post_on) ; 
                        $fullDate = date('F j, Y \a\t g:i A', strtotime($retweeted_tweet->post_on));
                        $likes_count = Tweet::countLikes($retweeted_tweet->post_id) ;
                        $user_like_it = Tweet::userLikeIt($user_id ,$retweeted_tweet->post_id);
                        $retweets_count = Tweet::countRetweets($retweeted_tweet->post_id) ;
                        $user_retweeted_it = Tweet::userRetweeetedIt($user_id ,$retweeted_tweet->post_id);
                      
                        
                        $tweet_inner = Tweet::getTweet($retweeted_tweet->tweet_id);
                        $user_inner_tweet = User::getData($tweet_inner->user_id) ;
                        $timeAgo_inner = Tweet::getTimeAgo($tweet_inner->post_on); 
                        $retweeted_user = User::getData($tweet->user_id);
                        $retweet_sign = true;

                        $qoute = $retweeted_tweet->retweet_msg;
                        $retweet_comment = true;
                        } else {
                            // here is retweeted quoted of quoted

                        $retweet_sign = true;
                        $tweet_user = User::getData($retweeted_tweet->user_id) ;

                         $timeAgo = Tweet::getTimeAgo($retweeted_tweet->post_on) ; 
                        $fullDate = date('F j, Y \a\t g:i A', strtotime($retweeted_tweet->post_on));
                        $likes_count = Tweet::countLikes($retweeted_tweet->post_id) ;
                        $user_like_it = Tweet::userLikeIt($user_id ,$retweeted_tweet->post_id);
                        $retweets_count = Tweet::countRetweets($retweeted_tweet->post_id) ;
                        $user_retweeted_it = Tweet::userRetweeetedIt($user_id ,$retweeted_tweet->post_id);
 
                        $qoq = true; // stand for quote of quote
                        $qoute = $retweeted_tweet->retweet_msg;
                        $tweet_inner = Tweet::getRetweet($retweeted_tweet->retweet_id);
                        $user_inner_tweet = User::getData($tweet_inner->user_id) ;
                        $timeAgo_inner = Tweet::getTimeAgo($tweet_inner->post_on);
                        $inner_qoute  = $tweet_inner->retweet_msg;
                      
                        

                        $retweeted_user = User::getData($tweet->user_id);

                        }
                    }

            } else {
              // quote tweet condtion
              if ($retweet->retweet_id == null) {
              $tweet_user = User::getData($tweet->user_id) ;
              $timeAgo = Tweet::getTimeAgo($tweet->post_on) ; 
              $fullDate = date('F j, Y \a\t g:i A', strtotime($tweet->post_on));
              $likes_count = Tweet::countLikes($tweet->id) ;
              $user_like_it = Tweet::userLikeIt($user_id ,$tweet->id);
              $retweets_count = Tweet::countRetweets($tweet->id) ;
              $user_retweeted_it = Tweet::userRetweeetedIt($user_id ,$tweet->id);
              $qoute = $retweet->retweet_msg;
              $retweet_comment = true;
          

              $tweet_inner = Tweet::getTweet($retweet->tweet_id);
              $user_inner_tweet = User::getData($tweet_inner->user_id) ;
              $timeAgo_inner = Tweet::getTimeAgo($tweet_inner->post_on); 
            } else {

            // this condtion for quote of quote which retweet_id not null and retweet msg not null
            $tweet_user = User::getData($tweet->user_id) ;
            $timeAgo = Tweet::getTimeAgo($tweet->post_on) ; 
            $fullDate = date('F j, Y \a\t g:i A', strtotime($tweet->post_on));
            $likes_count = Tweet::countLikes($tweet->id) ;
            $user_like_it = Tweet::userLikeIt($user_id ,$tweet->id);
            $retweets_count = Tweet::countRetweets($tweet->id) ;
            $user_retweeted_it = Tweet::userRetweeetedIt($user_id ,$tweet->id);
            $qoute = $retweet->retweet_msg;
            $qoq = true; // stand for quote of quote
            
            $tweet_inner = Tweet::getRetweet($retweet->retweet_id);
            $user_inner_tweet = User::getData($tweet_inner->user_id) ;
            $timeAgo_inner = Tweet::getTimeAgo($tweet_inner->post_on);
            $inner_qoute = $tweet_inner->retweet_msg;
            if($inner_qoute == null) {
                            
              $tweet_innerr = Tweet::getRetweet($tweet_inner->retweet_id);
              $inner_qoute = $tweet_innerr->retweet_msg;

            }

            }

            }

            } 
             $tweet_link = $tweet->id;
               
            //  show real tweet comments if retweeted tweet 
              if ($retweet_sign)
              $comments = Tweet::comments($retweeted_tweet->id);
              else  $comments = Tweet::comments($tweet_id);

            
            if($retweet_sign)
             $comment_count = Tweet::countComments($retweeted_tweet->id);
             else  $comment_count = Tweet::countComments($tweet->id); 
                     

// ✅ Share link for this status post
$share_tweet_id = $retweet_sign && isset($retweeted_tweet->id) ? $retweeted_tweet->id : $tweet->id;
$share_base_url = rtrim(BASE_URL, '/') . '/';
$share_url = $share_base_url . 'status/' . $share_tweet_id;

            
            ?>
             

              
          <div class="box-tweet feed" style="position: relative;" >
<?php 
// Safely check if $tweet_real exists and has no video
if (!isset($tweet_real) || empty($tweet_real->video)) { ?>
  <a href="status/<?php echo $tweet->id; ?>">
      <span style="position:absolute; width:100%; height:100%; top:0; left:0; z-index:1;"></span>
  </a>
<?php } ?>


            <?php if ($retweet_sign) { ?>
            <span class="retweed-name"> <i class="fa fa-retweet retweet-name-i" aria-hidden="true"></i> 
            <a style="position: relative; z-index:100; color:rgb(102, 117, 130);" href="<?php echo $retweeted_user->name; ?> "> <?php  if($retweeted_user->id == $user_id) echo "You";
        else echo $retweeted_user->name; ?> </a>  retweeted</span>
             <?php } ?>
            <div class="grid-tweet">
                <a style="position: relative; z-index:1000" href="<?php echo $tweet_user->username;  ?>">
                <img
                src="assets/images/users/<?php echo $tweet_user->img; ?>"
                alt=""
                class="img-user-tweet"
                />
                </a >

                <div>
                <p> 
                <a style="position: relative; z-index:1000; color:black" href="<?php echo $tweet_user->username;  ?>">
                <strong> <?php echo $tweet_user->name ?> </strong> 
                </a>
                  <span class="username-twitter">@<?php echo $tweet_user->username ?> </span>
                  <span class="username-twitter"><?php echo $timeAgo ?></span>
                </p>
<?php
$real_owner_id = $tweet_user->id ?? 0;
if (!empty($tweet_real)) {
    $real_owner_id = $tweet_real->user_id ?? $real_owner_id;
}
$real_tweet_id = $tweet_real->id ?? $tweet->id;
?>

<?php if ($real_owner_id == $user_id): ?>
<div class="tweet-options" style="position:absolute; top:5px; right:10px; z-index:1000;">
    <i class="fas fa-ellipsis-h" style="cursor:pointer;"></i>
    <div class="dropdown-menu" style="display:none; position:absolute; top:20px; right:0; background:#fff; border:1px solid #ccc; border-radius:4px; min-width:100px; box-shadow:0 2px 5px rgba(0,0,0,0.2);">
        <a href="#" class="dropdown-item delete-post" data-tweet-id="<?php echo $real_tweet_id; ?>" style="display:block; padding:8px 12px; color:#333; text-decoration:none;">Delete</a>
    </div>
</div>
<?php endif; ?>


                <p>
                  <?php
                  // check if it's quote or normal tweet
                  if ($retweet_comment || $qoq)
                  echo  Tweet::getTweetLinks($qoute);
                  else echo  Tweet::getTweetLinks($tweet_real->status); ?>
                </p>

                
<?php if ($retweet_comment == false && $qoq == false) { ?>

  <?php 
  // ✅ FIX: Get the source post ID (original tweet that contains the media)
  $source_id = Tweet::getSourcePostId($tweet->id);
  
  // Get all media items (images and videos combined)
  $all_media_items = [];
  
  // Get images from tweet_media table using source_id
  $media_list = Tweet::getTweetMedia($source_id);
  foreach ($media_list as $media) {
      $all_media_items[] = [
          'type' => 'image',
          'path' => $media->media_path,
          'url' => 'assets/images/tweets/' . $media->media_path
      ];
  }
  
  // Get videos from tweet_videos table using source_id
  $video_list = Tweet::getTweetVideos($source_id);
  foreach ($video_list as $video) {
      $all_media_items[] = [
          'type' => 'video',
          'path' => $video->video_path,
          'url' => 'assets/videos/tweets/' . $video->video_path
      ];
  }
  
  // If no media in tables but has old img, use the old img
  if (empty($all_media_items) && !empty($tweet_real->img)) {
      $all_media_items[] = [
          'type' => 'image',
          'path' => $tweet_real->img,
          'url' => 'assets/images/tweets/' . $tweet_real->img
      ];
  }
  
  // If no media in tables but has old video, use the old video
  if (empty($all_media_items) && !empty($tweet_real->video)) {
      $all_media_items[] = [
          'type' => 'video',
          'path' => $tweet_real->video,
          'url' => 'assets/videos/tweets/' . $tweet_real->video
      ];
  }
  
  $media_count = count($all_media_items);
  ?>
  
  <?php if ($media_count > 0): ?>
      <?php if ($media_count == 1): ?>
          <!-- Single media display (image or video) -->
          <div class="mt-post-tweet">
              <?php if ($all_media_items[0]['type'] == 'image'): ?>
                  <img src="<?php echo $all_media_items[0]['url']; ?>" alt="Tweet media" class="img-post-tweet single-media-img" data-lightbox-index="0" data-lightbox-type="image" data-lightbox-url="<?php echo $all_media_items[0]['url']; ?>">
              <?php else: ?>
                  <video class="img-post-tweet" controls preload="metadata" data-lightbox-index="0" data-lightbox-type="video" data-lightbox-url="<?php echo $all_media_items[0]['url']; ?>">
                      <source src="<?php echo $all_media_items[0]['url']; ?>" type="video/mp4">
                  </video>
              <?php endif; ?>
          </div>
      <?php else: ?>
          <!-- Multiple media gallery (images + videos mixed) -->
          <div class="tweet-gallery" data-count="<?php echo min($media_count, 9); ?>">
              <?php 
              $display_count = min($media_count, 9);
              for ($i = 0; $i < $display_count; $i++): 
                  $item = $all_media_items[$i];
                  $isVideo = ($item['type'] == 'video');
              ?>
                  <div class="gallery-item <?php echo $isVideo ? 'video-item' : ''; ?> <?php echo ($i == 8 && $media_count > 9) ? 'more-overlay' : ''; ?>" 
                       data-media-index="<?php echo $i; ?>"
                       data-media-type="<?php echo $item['type']; ?>"
                       data-media-url="<?php echo $item['url']; ?>"
                       <?php if ($i == 8 && $media_count > 9): ?>
                       data-remaining="<?php echo $media_count - 8; ?>"
                       <?php endif; ?>>
                      <?php if ($isVideo): ?>
                          <video preload="metadata" muted>
                              <source src="<?php echo $item['url']; ?>" type="video/mp4">
                          </video>
                      <?php else: ?>
                          <img src="<?php echo $item['url']; ?>" alt="Gallery image">
                      <?php endif; ?>
                      <?php if ($i == 8 && $media_count > 9): ?>
                          <span class="more-badge">+<?php echo $media_count - 8; ?></span>
                      <?php endif; ?>
                  </div>
              <?php endfor; ?>
          </div>
      <?php endif; ?>
  <?php endif; ?>

<?php } else { ?>

                  <div class="mt-post-tweet comment-post" style="position: relative;">
                 
                    <a href="status/<?php echo $tweet_inner->id; ?>">
                          <span class="" style="position:absolute; width:100%; height:100%; top:0;left: 0; z-index: 2;"></span>
                       </a>
                  <div class="grid-tweet py-3 "  > 
                 
                  <a style="position: relative; z-index:1000" href="<?php echo $user_inner_tweet->username;  ?>">
                    <img
                    src="assets/images/users/<?php echo $user_inner_tweet->img; ?>"
                    alt=""
                    class="img-user-tweet"
                    />
                    </a >

                    <div>
                    <p> 
                    <a style="position: relative; z-index:1000; color:black" href="<?php echo $user_inner_tweet->username;  ?>">
                    <strong> <?php echo $user_inner_tweet->name ?> </strong> 
                    </a>
                  <span class="username-twitter">@<?php echo $user_inner_tweet->username ?> </span>
                  <span class="username-twitter"><?php echo $timeAgo_inner ?></span>
                </p>
                <p>
                  <?php
                    if ($qoq)
                    echo Tweet::getTweetLinks($inner_qoute);
                    else  echo  Tweet::getTweetLinks($tweet_inner->status); ?>
                </p>
                <?php   // don't show img if quote of quote
                if ($qoq == false) { 
                
                // ✅ FIX: Get the source ID for quoted tweet
                $quoted_source_id = Tweet::getSourcePostId($tweet_inner->id);
                
                // Get quoted media items from source
                $quoted_media_items = [];
                
                $quoted_media_list = Tweet::getTweetMedia($quoted_source_id);
                foreach ($quoted_media_list as $media) {
                    $quoted_media_items[] = [
                        'type' => 'image',
                        'url' => 'assets/images/tweets/' . $media->media_path
                    ];
                }
                
                $quoted_video_list = Tweet::getTweetVideos($quoted_source_id);
                foreach ($quoted_video_list as $video) {
                    $quoted_media_items[] = [
                        'type' => 'video',
                        'url' => 'assets/videos/tweets/' . $video->video_path
                    ];
                }
                
                if (empty($quoted_media_items) && !empty($tweet_inner->img)) {
                    $quoted_media_items[] = [
                        'type' => 'image',
                        'url' => 'assets/images/tweets/' . $tweet_inner->img
                    ];
                }
                ?>
                
                <?php if (!empty($quoted_media_items)): ?>
                    <?php if (count($quoted_media_items) == 1): ?>
                        <?php if ($quoted_media_items[0]['type'] == 'image'): ?>
                            <div class="mt-post-tweet">
                                <img src="<?php echo $quoted_media_items[0]['url']; ?>" alt="" class="img-post-retweet" />
                            </div>
                        <?php else: ?>
                            <div class="mt-post-tweet">
                                <video class="tweet-video" controls preload="metadata">
                                    <source src="<?php echo $quoted_media_items[0]['url']; ?>" type="video/mp4">
                                </video>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="tweet-gallery" data-count="<?php echo min(count($quoted_media_items), 9); ?>">
                            <?php 
                            $display_count = min(count($quoted_media_items), 9);
                            for ($i = 0; $i < $display_count; $i++): 
                                $item = $quoted_media_items[$i];
                            ?>
                                <div class="gallery-item <?php echo $item['type'] == 'video' ? 'video-item' : ''; ?> <?php echo ($i == 8 && count($quoted_media_items) > 9) ? 'more-overlay' : ''; ?>"
                                     data-media-index="<?php echo $i; ?>"
                                     data-media-type="<?php echo $item['type']; ?>"
                                     data-media-url="<?php echo $item['url']; ?>"
                                     <?php if ($i == 8 && count($quoted_media_items) > 9): ?>
                                     data-remaining="<?php echo count($quoted_media_items) - 8; ?>"
                                     <?php endif; ?>>
                                    <?php if ($item['type'] == 'video'): ?>
                                        <video preload="metadata" muted>
                                            <source src="<?php echo $item['url']; ?>" type="video/mp4">
                                        </video>
                                    <?php else: ?>
                                        <img src="<?php echo $item['url']; ?>" alt="Gallery image">
                                    <?php endif; ?>
                                    <?php if ($i == 8 && count($quoted_media_items) > 9): ?>
                                        <span class="more-badge">+<?php echo count($quoted_media_items) - 8; ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endfor; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
                
                <?php } ?>
              </div> 

            </div>
                         

                </div>

                <?php } ?>

                
                <!-- POST TIMESTAMP - DATE AND TIME DISPLAYED BELOW MEDIA -->
                <div class="post-timestamp">
                    <i class="far fa-calendar-alt"></i> <?php echo $fullDate; ?>
                </div>

                <div class="row home-follow pt-3">
                       
                        <?php if($retweets_count > 0)  { ?>
                            <div class="col-md-2 users-count" >
                            <i class="retweets-u"
                            data-tweet="<?php 
                            if($retweet_sign)
                                echo $retweeted_tweet->id;
                            else  echo $tweet->id; ?>"> 
                     <span class="home-follow-count"> <?php echo $retweets_count ; ?> </span> Retweets</i>
                        </div> 
                        <?php } ?> 
                        <?php if($likes_count > 0)  { ?>
                        <div class="col-md-2 users-count">
                            <div class="likes-u" 
                            data-tweet="<?php 
                            if($retweet_sign)
                                echo $retweeted_tweet->id;
                            else  echo $tweet->id; ?>">
                             <span class="home-follow-count">  <?php echo $likes_count ; ?>  </span> Likes</div>
                        </div>   
                        <?php } ?> 
                  </div>

                <div class="grid-reactions">
                  <div class="grid-box-reaction">
                    <div class="hover-reaction hover-reaction-comment comment"
                    data-user = "<?php echo $user_id; ?>" 
                    data-tweet = "<?php 
                    if($retweet_sign)
                       echo $retweeted_tweet->id;
                   else  echo $tweet->id; ?>">
                     
                      <i class="far fa-comment"></i>
                      <div class="mt-counter likes-count d-inline-block">
                        <p> <?php if($comment_count > 0) echo $comment_count; ?> </p>
                      </div>
                    </div>
                  </div>
                  <div class="grid-box-reaction">
                
                    <div  class="hover-reaction hover-reaction-retweet
                    <?= $user_retweeted_it ? 'retweeted' : 'retweet' ?> option"
                    data-tweet="<?php
                     echo $tweet->id ;
                     ?>" 
                    data-user="<?php echo $user_id; ?>
                    "
                    data-retweeted = "<?php echo $user_retweeted_it; ?>"
                    data-sign = "<?php echo $retweet_sign; ?>"
                    data-tmp="<?php echo $retweet_comment; ?>"
                    data-qoq="<?php echo $qoq; ?>"
                    data-status="<?php echo true; ?>">

                      <i class="fas fa-retweet"></i>
                      <div class="mt-counter likes-count d-inline-block">
                        <p><?php if($retweets_count > 0)  echo $retweets_count ; ?></p>
                      </div>
                      
                    </div>
                    
                    <div class="options">
                
                                
                      </div> 

                  </div>
                  <div  class="grid-box-reaction"  >
                    <a class="hover-reaction hover-reaction-like 
                    <?= $user_like_it ? 'unlike-btn' : 'like-btn' ?> " 
                    data-tweet="<?php 
                     if($retweet_sign) {
                              if($retweet->tweet_id != null) {
                                echo $retweet->tweet_id;
                              } echo $retweet->retweet_id;
                     }  else echo $tweet->id ;
                    
                     ?>" 
                    data-user="<?php echo $user_id; ?>">
                    
                    
                      <i class="fa-heart <?= $user_like_it ? 'fas' : 'far mt-icon-reaction' ?>"></i>
                      <div class="mt-counter likes-count d-inline-block">
                      <p> <?php if($likes_count > 0)  echo $likes_count ; ?> </p>
                      </div>
                </a>
                    
                    
                </div>

<!-- BOOKMARK ICON -->
<div class="grid-box-reaction">
  <div class="tweet-option tweet-bookmark" data-tweet="<?php echo $tweet->id; ?>" data-user="<?php echo $user_id; ?>">
    <?php
      $stmt = Connect::connect()->prepare("SELECT id FROM bookmarks WHERE user_id = :uid AND tweet_id = :tid");
      $stmt->execute(['uid' => $_SESSION['user_id'], 'tid' => $tweet->id]);
      $isBookmarked = $stmt->rowCount() > 0;
      $faClass = $isBookmarked ? 'fas' : 'far';
    ?>
    <i class="<?php echo $faClass; ?> fa-bookmark mt-icon-reaction <?php echo $isBookmarked ? 'bookmarked' : ''; ?>"></i>
  </div>
  <div class="mt-counter">
    <p></p>
  </div>
</div>

<!-- SHARE ICON -->
<div class="grid-box-reaction">
  <div class="tweet-option tweet-share status-share-btn"
       title="Share post"
       data-share-url="<?php echo htmlspecialchars($share_url, ENT_QUOTES, 'UTF-8'); ?>">
    <i class="fas fa-share-alt mt-icon-reaction"></i>
  </div>
  <div class="mt-counter">
    <p></p>
  </div>
</div>

<!-- SHARE ICON -->
<div class="grid-box-reaction">
  <div class="tweet-option tweet-share"
       title="Share post"
       data-share-url="<?php echo htmlspecialchars($share_url, ENT_QUOTES, 'UTF-8'); ?>">
    <i class="fa-solid fa-arrow-up-from-bracket mt-icon-reaction"></i>
  </div>
  <div class="mt-counter">
    <p></p>
  </div>
</div>

                </div>
              </div> 
              
            </div>

           

            
          </div>
             
          <div class="comments">

            
          <!-- comments place --> 
          <?php foreach($comments as $comment) { 
                     $tweet_user = User::getData($comment->user_id) ;
                     $timeAgo = Tweet::getTimeAgo($comment->time);
                     $replies = Tweet::replies($comment->id);
                     $reply_count = Tweet::countReplies($comment->id);
              ?>

          <div class="box-comment feed py-2"  >
                
          
            <div class="grid-tweet">
              <div>
                <img
                  src="assets/images/users/<?php echo $tweet_user->img; ?>"
                  alt=""
                  class="img-user-tweet"
                />
              </div>
  
              <div>
                <p>
                  <strong> <?php echo $tweet_user->name ?> </strong>
                  <span class="username-twitter">@<?php echo $tweet_user->username ?> </span>
                  <span class="username-twitter"><?php echo $timeAgo ?></span>
        <?php if ($comment->user_id == $user_id): ?>
        <div class="comment-options">
            <i class="fas fa-ellipsis-h" style="color: #657786; cursor: pointer; padding: 5px;"></i>
            <div class="dropdown-menu-comment">
                <a href="#" class="delete-comment" data-comment-id="<?php echo $comment->id; ?>">
                    <i class="far fa-trash-alt"></i> Delete
                </a>
            </div>
        </div>
        <?php endif; ?>
                </p>
                <p>
                  <?php
                 echo  Tweet::getTweetLinks($comment->comment); ?>
                </p>
                    
                <div class="grid-reactions">
                  <div class="grid-box-reaction-rep">
                    <div class="hover-reaction-rep hover-reaction-comment reply"
                    data-user = "<?php echo $user_id; ?>" 
                    data-tweet = "<?php 
                    echo $comment->id; ?>">
                     
                      <i class="far fa-comment"></i>
                      <div class="mt-counter likes-count d-inline-block">
                        <p > <?php if($reply_count > 0) echo $reply_count; ?> </p>
                      </div>
                    </div>
                  </div>
                  
                

                  </div>

              </div> 
            
              
            </div> 
          
        </div> 

        
                        <!-- replies -->
<?php if (!empty($replies)) : ?>
  <div class="replies-container" id="replies-<?php echo $comment->id; ?>">

    <?php 
      $visibleLimit = 0;
      $count = 0;
      foreach ($replies as $reply): 
        $count++;
        $reply_user = User::getData($reply->user_id);
        $reply_time = Tweet::getTimeAgo($reply->time);
    ?>
      <div class="box-reply feed reply-item <?php echo ($count > $visibleLimit) ? 'd-none' : ''; ?>">
        <div class="grid-tweet">
          <div>
            <img src="assets/images/users/<?php echo $reply_user->img; ?>" class="img-user-tweet">
          </div>

          <div>
            <p>
              <strong><?php echo $reply_user->name ?></strong>
              <span class="username-twitter">@<?php echo $reply_user->username ?></span>
              <span class="username-twitter"><?php echo $reply_time ?></span>
        <?php if ($reply->user_id == $user_id): ?>
        <div class="reply-options">
            <i class="fas fa-ellipsis-h" style="color: #657786; cursor: pointer; padding: 5px;"></i>
            <div class="dropdown-menu-reply">
                <a href="#" class="delete-reply" data-reply-id="<?php echo $reply->id; ?>">
                    <i class="far fa-trash-alt"></i> Delete
                </a>
            </div>
        </div>
        <?php endif; ?>
            </p>

            <p><?php echo Tweet::getTweetLinks($reply->reply); ?></p>

            <div class="grid-reactions">
              <div class="grid-box-reaction-rep">
                <div class="hover-reaction-rep hover-reaction-comment reply"
                     data-user="<?php echo $user_id; ?>"
                     data-tweet="<?php echo $reply->id; ?>">
                  <i class="far fa-comment"></i>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>

  </div>

  <?php if (count($replies) > $visibleLimit): ?>
    <p class="toggle-replies text-primary"
       data-comment-id="<?php echo $comment->id; ?>"
       style="cursor:pointer; text-align:right; margin-right:20px;">
       Show more replies (<?php echo count($replies); ?>)
    </p>
  <?php endif; ?>
<?php endif; ?>


            <?php } ?>
         
          <div class="popupTweet">

          </div>
          <div class="popupComment">

           </div>
           <div class="popupUsers">

           </div>
            
           </div>


         



        </div> 

      
        <div class="wrapper-right">
            <div style="width: 90%;" class="container">

          <div class="input-group py-2 m-auto pr-5 position-relative">

          <i id="icon-search" class="fas fa-search tryy"></i>
          <input type="text" class="form-control search-input"  placeholder="Search Twitter">
          <div class="search-result">


          </div>
          </div>
          </div>

          
       


                
          <div class="box-share">
            <p class="txt-share"><strong>Who to follow</strong></p>
            <?php 
            foreach($who_users as $user) { 
               $user_follow = Follow::isUserFollow($user_id , $user->id) ;
               ?>
          <div class="grid-share">
          <a style="position: relative; z-index:5; color:black" href="<?php echo $user->username;  ?>">
                      <img
                        src="assets/images/users/<?php echo $user->img; ?>"
                        alt=""
                        class="img-share"
                      />
                    </a>
                    <div>
                      <p>
                      <a style="position: relative; z-index:5; color:black" href="<?php echo $user->username;  ?>">  
                      <strong><?php echo $user->name; ?></strong>
                      </a>
                    </p>
                      <p class="username">@<?php echo $user->username; ?>
                      <?php if (Follow::FollowsYou($user->id , $user_id)) { ?>
                  <span class="ml-1 follows-you">Follows You</span></p>
                  <?php } ?></p></p>
                    </div>
                    <div>
                      <button class="follow-btn follow-btn-m 
                      <?= $user_follow ? 'following' : 'follow' ?>"
                      data-follow="<?php echo $user->id; ?>"
                      data-user="<?php echo $user_id; ?>"
                      data-profile="<?php echo $u_id; ?>"
                      style="font-weight: 700;">
                      <?php if($user_follow) { ?>
                        Following 
                      <?php } else {  ?>  
                          Follow
                        <?php }  ?> 
                      </button>
                    </div>
                  </div>

                  <?php }?>
         
          
          </div>
  
  
  
        </div>
      </div>
      </div> 
      
<!-- Unified Lightbox -->
<div id="unifiedLightbox" class="unified-lightbox">
    <button class="lightbox-close">&times;</button>
    <button class="lightbox-prev"><i class="fas fa-chevron-left"></i></button>
    <div class="lightbox-container">
        <img id="lightboxImage" class="lightbox-image" src="">
        <video id="lightboxVideo" class="lightbox-video" controls>
            <source src="" type="video/mp4">
        </video>
    </div>
    <button class="lightbox-next"><i class="fas fa-chevron-right"></i></button>
    <div class="lightbox-counter" id="lightboxCounter"></div>
    <div class="lightbox-type-badge" id="lightboxTypeBadge"></div>
</div>

<div class="share-toast" id="shareToast">Link copied</div>

<script>
$(document).ready(function() {

    // Share post link: native share on mobile, copy link on desktop
    $(document).on('click', '.tweet-share', function(e) {
        e.preventDefault();
        e.stopPropagation();

        const shareUrl = $(this).data('share-url');
        const shareTitle = 'Check this post';

        if (!shareUrl) return;

        if (navigator.share) {
            navigator.share({
                title: shareTitle,
                text: shareTitle,
                url: shareUrl
            }).catch(function() {});
            return;
        }

        function showShareToast(message) {
            const $toast = $('#shareToast');
            $toast.text(message).fadeIn(150);
            setTimeout(function() {
                $toast.fadeOut(200);
            }, 1800);
        }

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(shareUrl).then(function() {
                showShareToast('Share link copied');
            }).catch(function() {
                prompt('Copy this share link:', shareUrl);
            });
        } else {
            const tempInput = $('<input>');
            $('body').append(tempInput);
            tempInput.val(shareUrl).select();
            try {
                document.execCommand('copy');
                showShareToast('Share link copied');
            } catch (err) {
                prompt('Copy this share link:', shareUrl);
            }
            tempInput.remove();
        }
    });

    // Dropdown menu handlers
    document.querySelectorAll('.tweet-options').forEach(container => {
        const icon = container.querySelector('i');
        const menu = container.querySelector('.dropdown-menu');

        icon.addEventListener('click', (e) => {
            e.stopPropagation();
            menu.style.display = (menu.style.display === 'block') ? 'none' : 'block';
        });
    });

    document.addEventListener('click', () => {
        document.querySelectorAll('.tweet-options .dropdown-menu').forEach(menu => {
            menu.style.display = 'none';
        });
    });
    
    // ========== UNIFIED LIGHTBOX ==========
    let currentLightboxItems = [];
    let currentLightboxIndex = 0;
    
    function openUnifiedLightbox(items, startIndex) {
        currentLightboxItems = items;
        currentLightboxIndex = startIndex;
        updateUnifiedLightbox();
        $('#unifiedLightbox').addClass('active');
        $('body').css('overflow', 'hidden');
    }
    
    function updateUnifiedLightbox() {
        const item = currentLightboxItems[currentLightboxIndex];
        const $img = $('#lightboxImage');
        const $video = $('#lightboxVideo');
        const $badge = $('#lightboxTypeBadge');
        
        if (item.type === 'image') {
            $img.attr('src', item.url).show();
            $video.hide();
            $video[0].pause();
            $badge.html('<i class="fa-regular fa-image"></i> Photo');
        } else {
            $video.show();
            $video.attr('src', item.url);
            $video[0].load();
            $img.hide();
            $badge.html('<i class="fa-regular fa-circle-play"></i> Video');
        }
        
        $('#lightboxCounter').text((currentLightboxIndex + 1) + ' of ' + currentLightboxItems.length);
        
        if (currentLightboxItems.length <= 1) {
            $('.lightbox-prev, .lightbox-next').hide();
        } else {
            $('.lightbox-prev, .lightbox-next').show();
        }
    }
    
    function closeUnifiedLightbox() {
        $('#unifiedLightbox').removeClass('active');
        $('body').css('overflow', '');
        const $video = $('#lightboxVideo');
        $video[0].pause();
        $video.attr('src', '');
    }
    
    $('.lightbox-next').on('click', function(e) {
        e.stopPropagation();
        if (currentLightboxIndex < currentLightboxItems.length - 1) {
            currentLightboxIndex++;
            updateUnifiedLightbox();
        }
    });
    
    $('.lightbox-prev').on('click', function(e) {
        e.stopPropagation();
        if (currentLightboxIndex > 0) {
            currentLightboxIndex--;
            updateUnifiedLightbox();
        }
    });
    
    $('.lightbox-close').on('click', function(e) {
        e.stopPropagation();
        closeUnifiedLightbox();
    });
    
    $('#unifiedLightbox').on('click', function(e) {
        if (e.target === this) {
            closeUnifiedLightbox();
        }
    });
    
    $(document).on('keydown', function(e) {
        if ($('#unifiedLightbox').hasClass('active')) {
            if (e.key === 'ArrowLeft') $('.lightbox-prev').click();
            else if (e.key === 'ArrowRight') $('.lightbox-next').click();
            else if (e.key === 'Escape') closeUnifiedLightbox();
        }
    });
    
    // Handle click on gallery items (images and videos mixed)
    $(document).on('click', '.gallery-item', function(e) {
        e.preventDefault();
        e.stopPropagation();
        const $item = $(this);
        const $gallery = $item.closest('.tweet-gallery');
        const mediaItems = [];
        
        $gallery.find('.gallery-item').each(function() {
            const mediaType = $(this).data('media-type');
            const mediaUrl = $(this).data('media-url');
            if (mediaUrl) {
                mediaItems.push({
                    type: mediaType || ($(this).hasClass('video-item') ? 'video' : 'image'),
                    url: mediaUrl
                });
            } else {
                const imgSrc = $(this).find('img').attr('src');
                if (imgSrc) {
                    mediaItems.push({
                        type: 'image',
                        url: imgSrc
                    });
                }
            }
        });
        
        let clickedIndex = $gallery.find('.gallery-item').index($item);
        if (clickedIndex >= 0 && mediaItems.length > 0) {
            openUnifiedLightbox(mediaItems, clickedIndex);
        }
    });
    
    // Handle click on single images
    $(document).on('click', '.single-media-img, .img-post-tweet', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        if (!$(this).closest('.tweet-gallery').length && $(this).is('img')) {
            const imgSrc = $(this).attr('src');
            if (imgSrc) {
                const mediaItems = [{ type: 'image', url: imgSrc }];
                openUnifiedLightbox(mediaItems, 0);
            }
        }
    });
    
    // Handle click on single video
    $(document).on('click', '.single-media-video', function(e) {
        e.stopPropagation();
        const videoSrc = $(this).find('source').attr('src');
        if (videoSrc) {
            const mediaItems = [{ type: 'video', url: videoSrc }];
            openUnifiedLightbox(mediaItems, 0);
        }
    });
});
</script>

      
<script>
$(document).ready(function() {
    // Share post link: status page
    $(document).on('click', '.status-share-btn', function(e) {
        e.preventDefault();
        e.stopPropagation();

        const shareUrl = $(this).data('share-url');
        if (!shareUrl) return;

        function showShareToast(message) {
            const $toast = $('#shareToast');
            $toast.text(message).fadeIn(150);
            setTimeout(function() {
                $toast.fadeOut(200);
            }, 1800);
        }

        if (navigator.share) {
            navigator.share({
                title: 'Check this post',
                text: 'Check this post',
                url: shareUrl
            }).catch(function() {});
            return;
        }

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(shareUrl).then(function() {
                showShareToast('Share link copied');
            }).catch(function() {
                prompt('Copy this share link:', shareUrl);
            });
        } else {
            const tempInput = $('<input>');
            $('body').append(tempInput);
            tempInput.val(shareUrl).select();
            try {
                document.execCommand('copy');
                showShareToast('Share link copied');
            } catch (err) {
                prompt('Copy this share link:', shareUrl);
            }
            tempInput.remove();
        }
    });
});
</script>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content text-center">
      <div class="modal-body">
        <h5 class="mb-3">Delete post?</h5>
        <p>Are you sure you want to delete this post? This action cannot be undone.</p>
        <div class="mt-4">
          <button type="button" class="btn btn-secondary mr-2" data-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-danger confirm-delete">Delete</button>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
$(document).ready(function() {
  let deleteTweetId = null;

  $(document).on('click', '.delete-post', function(e) {
    e.preventDefault();
    deleteTweetId = $(this).data('tweet-id');
    $('#deleteConfirmModal').modal('show');
  });

  $('.confirm-delete').click(function() {
    $.ajax({
      url: 'includes/delete_tweet.php',
      type: 'POST',
      data: { tweet_id: deleteTweetId },
      success: function(response) {
        $('#deleteConfirmModal').modal('hide');
        if (response.trim() === 'deleted') {
          $('body').append('<div class="alert alert-success position-fixed" style="top:20px;right:20px;z-index:2000;">Post deleted!</div>');
          setTimeout(() => { window.location.href = 'home.php'; }, 1000);
        } else {
          alert('Error deleting post');
        }
      }
    });
  });
});
</script>

      <script src="assets/js/search.js"></script>
      <script type="text/javascript" src="assets/js/hashtag.js"></script>
      <script type="text/javascript" src="assets/js/like.js"></script>
      <script type="text/javascript" src="assets/js/users.js"></script>
      <script type="text/javascript" src="assets/js/comment.js?v=<?php echo time(); ?>"></script>
      <script type="text/javascript" src="assets/js/retweet.js?v=<?php echo time(); ?>"></script>
      <script type="text/javascript" src="assets/js/follow.js?v=<?php echo time(); ?>"></script>
      <script src="https://kit.fontawesome.com/38e12cc51b.js" crossorigin="anonymous"></script>
      <script src="assets/js/bookmark.js?v=<?php echo time(); ?>"></script>
      <script src="assets/js/popper.min.js"></script>
      <script src="assets/js/bootstrap.min.js"></script>
      
<script>
$(document).ready(function() {
  $(document).on('click', '.toggle-replies', function() {
    const $this = $(this);
    const commentId = $this.data('comment-id');
    const container = $('#replies-' + commentId);
    const hiddenReplies = container.find('.reply-item.d-none');

    if (hiddenReplies.length > 0) {
      hiddenReplies.removeClass('d-none');
      $this.text('Show less replies');
    } else {
      container.find('.reply-item').addClass('d-none');
      const totalReplies = container.find('.reply-item').length;
      $this.text('Show more replies (' + totalReplies + ')');
    }
  });
});
</script>

</body>
</html>