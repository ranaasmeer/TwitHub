<?php
// ✅ SAFETY CHECK: If $tweet is not set or empty, stop rendering to prevent warnings
if (!isset($tweet) || empty($tweet)) {
    return;
}

// ✅ BLOCK CHECK: Hide tweets from blocked users
$ownerId = null;
if (isset($tweet->user_id)) {
    $ownerId = $tweet->user_id;
} elseif (isset($tweet_user->id)) {
    $ownerId = $tweet_user->id;
}

if ($ownerId) {
    global $pdo;
    $checkBlockStmt = $pdo->prepare("SELECT is_blocked FROM users WHERE id = ?");
    $checkBlockStmt->execute([$ownerId]);
    $isBlocked = $checkBlockStmt->fetchColumn();
    
    if ($isBlocked == 1) {
        return;
    }
}

// ✅ Function to format tweet text with Show More/Less
if (!function_exists('formatTweetTextWithReadMore')) {
    function formatTweetTextWithReadMore($text, $tweetId, $isQuote = false) {
        $maxLength = 140;
        $plainText = strip_tags($text);
        $uniqueId = $isQuote ? 'quote-' . $tweetId : $tweetId;
        
        if (strlen($plainText) > $maxLength) {
            $truncated = substr($plainText, 0, $maxLength);
            $lastSpace = strrpos($truncated, ' ');
            if ($lastSpace !== false && $lastSpace > $maxLength - 8) {
                $truncated = substr($truncated, 0, $lastSpace);
            }
            $fullText = $text;
            
            return '
                <div class="tweet-text-container" data-tweet-id="' . $uniqueId . '">
                    <div class="tweet-text-wrapper">
                        <span class="tweet-text-short" id="tweet-short-' . $uniqueId . '">' . nl2br(Tweet::getTweetLinks(htmlspecialchars_decode($truncated))) . '... </span>
                        <span class="tweet-text-full" id="tweet-full-' . $uniqueId . '" style="display:none;">' . nl2br(Tweet::getTweetLinks(htmlspecialchars_decode($fullText))) . ' </span>
                        <button class="read-more-btn" data-id="' . $uniqueId . '">
                            <span class="btn-text">Show more</span>
                            <i class="fas fa-chevron-down btn-icon"></i>
                        </button>
                    </div>
                </div>
            ';
        } else {
            return '<div class="tweet-text-full tweet-text-no-truncate">' . nl2br(Tweet::getTweetLinks($text)) . '</div>';
        }
    }
}

$user_id = $_SESSION['user_id'];
$retweet_sign = false;
$retweet_comment = false;
$qoq = false;

if (Tweet::isTweet($tweet->id)) {

$tweet_user = User::getData($tweet->user_id) ;
$tweet_real = Tweet::getTweet($tweet->id);
$timeAgo = Tweet::getTimeAgo($tweet->post_on) ; 
$likes_count = Tweet::countLikes($tweet->id) ;
$user_like_it = Tweet::userLikeIt($user_id ,$tweet->id);
$retweets_count = Tweet::countRetweets($tweet->id) ;
$user_retweeted_it = Tweet::userRetweeetedIt($user_id ,$tweet->id);

} else if (Tweet::isRetweet($tweet->id)) {

$retweet = Tweet::getRetweet($tweet->id);

if ($retweet->retweet_msg == null) {

    if ($retweet->retweet_id == null) {
      
      $retweeted_tweet = Tweet::getTweet($retweet->tweet_id);
    $tweet_user = User::getData($retweeted_tweet->user_id) ;
    $tweet_real = Tweet::getTweet($retweet->tweet_id);
    $timeAgo = Tweet::getTimeAgo($tweet_real->post_on) ; 
    $likes_count = Tweet::countLikes($retweet->tweet_id) ;
    $user_like_it = Tweet::userLikeIt($user_id ,$retweet->tweet_id);
    $retweets_count = Tweet::countRetweets($retweet->tweet_id) ;
    $user_retweeted_it = Tweet::userRetweeetedIt($user_id ,$retweet->tweet_id); 
    $retweeted_user = User::getData($tweet->user_id);
    $retweet_sign = true;
    } else {

    $retweeted_tweet = Tweet::getRetweet($retweet->retweet_id);

        if($retweeted_tweet->tweet_id != null) {
        $tweet_user = User::getData($retweeted_tweet->user_id) ;
        $timeAgo = Tweet::getTimeAgo($retweeted_tweet->post_on) ; 
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

        $retweet_sign = true;
        $tweet_user = User::getData($retweeted_tweet->user_id) ;

        $timeAgo = Tweet::getTimeAgo($retweeted_tweet->post_on) ; 
        $likes_count = Tweet::countLikes($retweeted_tweet->post_id) ;
        $user_like_it = Tweet::userLikeIt($user_id ,$retweeted_tweet->post_id);
        $retweets_count = Tweet::countRetweets($retweeted_tweet->post_id) ;
        $user_retweeted_it = Tweet::userRetweeetedIt($user_id ,$retweeted_tweet->post_id);

        $qoq = true;
        $qoute = $retweeted_tweet->retweet_msg;
        $tweet_inner = Tweet::getRetweet($retweeted_tweet->retweet_id);
        $user_inner_tweet = User::getData($tweet_inner->user_id) ;
        $timeAgo_inner = Tweet::getTimeAgo($tweet_inner->post_on);
        $inner_qoute  = $tweet_inner->retweet_msg;
      
        $retweeted_user = User::getData($tweet->user_id);
        }
    }

} else {
if ($retweet->retweet_id == null) {
$tweet_user = User::getData($tweet->user_id) ;
$timeAgo = Tweet::getTimeAgo($tweet->post_on) ; 
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

$tweet_user = User::getData($tweet->user_id) ;
$timeAgo = Tweet::getTimeAgo($tweet->post_on) ; 
$likes_count = Tweet::countLikes($tweet->id) ;
$user_like_it = Tweet::userLikeIt($user_id ,$tweet->id);
$retweets_count = Tweet::countRetweets($tweet->id) ;
$user_retweeted_it = Tweet::userRetweeetedIt($user_id ,$tweet->id);
$qoute = $retweet->retweet_msg;
$qoq = true;

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

if($retweet_sign)
$comment_count = Tweet::countComments($retweeted_tweet->id);
else  $comment_count = Tweet::countComments($tweet->id); 

?>
<style>
/* Main Tweet Box */
.box-tweet {
    position: relative;
    background: #fff;
    transition: all 0.2s ease;
    max-width: 100%;
    overflow: hidden;
    box-sizing: border-box;
    padding-left: 9px;
    padding-right: 9px;
}

.box-tweet.feed {
    border-bottom: 2px solid #e6ecf0;
    margin-bottom: 15px;
    padding-bottom: 5px;
}

.box-tweet.feed:hover {
    background: #f8f9fa;
    border-radius: 8px;
    padding-left: 8px;
    padding-right: 8px;
}

.tweet-overlay {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    z-index: 1;
    cursor: pointer;
}

/* Professional Media Gallery Grid - no blank gaps */
.tweet-gallery {
    display: grid;
    gap: 3px;
    margin-top: 12px;
    border-radius: 16px;
    background: #f5f8fa;
    width: 100%;
    max-width: 100%;
    overflow: hidden;
    box-sizing: border-box;
}

/* Only visible media count is used here, so the grid never leaves empty spaces */
.tweet-gallery[data-count="1"] {
    grid-template-columns: 1fr;
}

.tweet-gallery[data-count="2"] {
    grid-template-columns: repeat(2, minmax(0, 1fr));
}

.tweet-gallery[data-count="3"] {
    grid-template-columns: repeat(2, minmax(0, 1fr));
}

.tweet-gallery[data-count="3"] .gallery-item:first-child {
    grid-row: span 2;
}

.tweet-gallery[data-count="4"] {
    grid-template-columns: repeat(2, minmax(0, 1fr));
}

.tweet-gallery[data-count="5"] {
    grid-template-columns: repeat(3, minmax(0, 1fr));
}

.tweet-gallery[data-count="5"] .gallery-item:first-child,
.tweet-gallery[data-count="5"] .gallery-item:nth-child(2) {
    grid-column: span 1;
}

.tweet-gallery[data-count="5"] .gallery-item:nth-child(1),
.tweet-gallery[data-count="5"] .gallery-item:nth-child(2) {
    aspect-ratio: 1.2 / 1;
}

.tweet-gallery[data-count="5"] .gallery-item:nth-child(n+3) {
    aspect-ratio: 1 / 1;
}

.gallery-item {
    position: relative;
    cursor: pointer;
    overflow: hidden;
    aspect-ratio: 1 / 1;
    background: #f5f8fa;
    width: 100%;
    min-width: 0;
    box-sizing: border-box;
}

.tweet-gallery[data-count="2"] .gallery-item {
    aspect-ratio: 1 / 1.05;
}

.tweet-gallery[data-count="3"] .gallery-item {
    aspect-ratio: 1 / 1;
}

.tweet-gallery[data-count="3"] .gallery-item:first-child {
    aspect-ratio: auto;
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

.gallery-item.more-overlay::before {
    content: '';
    position: absolute;
    inset: 0;
    background: rgba(0,0,0,0.55);
    z-index: 5;
    pointer-events: none;
}

.gallery-item.more-overlay .more-badge {
    position: absolute;
    inset: 0;
    z-index: 6;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 32px;
    font-weight: 800;
    line-height: 1;
    font-family: 'Poppins', sans-serif;
    pointer-events: none;
    text-shadow: 0 2px 8px rgba(0,0,0,0.35);
}

/* Video play icon overlay - fixed for Font Awesome 5/6 compatibility */
.gallery-item.video-item::before {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 54px;
    height: 54px;
    border-radius: 50%;
    background: rgba(0, 0, 0, 0.45);
    box-shadow: 0 4px 16px rgba(0,0,0,0.35);
    pointer-events: none;
    z-index: 3;
}

.gallery-item.video-item::after {
    content: '\f04b';
    font-family: 'Font Awesome 5 Free', 'Font Awesome 6 Free', 'FontAwesome';
    font-weight: 900;
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-42%, -50%);
    color: #ffffff;
    font-size: 24px;
    line-height: 1;
    opacity: 1;
    text-shadow: 0 2px 8px rgba(0,0,0,0.65);
    pointer-events: none;
    z-index: 4;
}

/* Single image/video styling */
.single-media {
    margin-top: 12px;
    border-radius: 16px;
    overflow: hidden;
    background: #f5f8fa;
}

.single-media img,
.single-media video {
    width: 100%;
    max-height: 400px;
    object-fit: contain;
}

.single-media img {
    cursor: pointer;
}

.single-media video {
    cursor: default;
}

/* Quoted tweet media */
.img-post-retweet {
    width: 100%;
    max-height: 250px;
    object-fit: contain;
    border-radius: 12px;
    background: #f5f8fa;
    display: block;
    margin: 0 auto;
    cursor: pointer;
}

.tweet-video {
    width: 100%;
    max-height: 400px;
    object-fit: contain;
    border-radius: 16px;
    background: #000;
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

/* Responsive */
@media (max-width: 768px) {
    .box-tweet {
        padding-left: 8px;
        padding-right: 8px;
    }

    .tweet-gallery,
    .tweet-gallery[data-count="5"] {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .tweet-gallery[data-count="5"] .gallery-item:first-child {
        grid-column: span 2;
        aspect-ratio: 16 / 9;
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
    .grid-reactions {
        gap: 15px;
    }
    .img-user-tweet {
        width: 40px;
        height: 40px;
    }
}

/* Other styles remain the same */
.verified-icon {
    color: #1DA1F2;
    font-size: 15px;
    margin-left: 4px;
    vertical-align: middle;
}

.tweet-text-container {
    margin-bottom: 8px;
    width: 100%;
    clear: both;
}

.tweet-text-wrapper {
    display: block;
    width: 100%;
}

.tweet-text-short,
.tweet-text-full {
    display: inline;
    word-wrap: break-word;
    white-space: normal;
    line-height: 1.5;
    font-size: 15px;
    color: #0f1419;
}

.read-more-btn {
    background: transparent;
    border: none;
    color: #1da1f2;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    padding: 0 4px;
    margin: 0;
    display: inline;
    position: relative;
    z-index: 100;
}

.read-more-btn:hover {
    text-decoration: underline;
}

.retweed-name {
    display: block;
    margin-bottom: 8px;
    margin-left: 48px;
    color: #657786;
    font-size: 13px;
    position: relative;
    z-index: 2;
}

.retweed-name i {
    margin-right: 5px;
}

.grid-tweet {
    display: flex;
    gap: 10px;
    position: relative;
    z-index: 2;
    width: 100%;
    max-width: 100%;
    overflow: hidden;
    box-sizing: border-box;
}

.grid-tweet > div:last-child {
    flex: 1;
    min-width: 0;
    width: calc(100% - 58px);
    max-width: calc(100% - 58px);
    overflow: hidden;
    box-sizing: border-box;
}

.img-user-tweet {
    width: 48px;
    height: 48px;
    border-radius: 50%;
    object-fit: cover;
    flex-shrink: 0;
}

.username-twitter {
    color: #657786;
    font-size: 13px;
    margin-left: 5px;
}

.grid-reactions {
    display: flex;
    gap: 20px;
    margin-top: 12px;
    position: relative;
    z-index: 10;
    flex-wrap: wrap;
}

.grid-box-reaction {
    display: flex;
    align-items: center;
}

.hover-reaction, 
.tweet-option {
    position: relative;
    z-index: 11;
}

.mt-counter p {
    margin: 0;
    font-size: 13px;
    font-weight: 400;
    color: #657786;
}

.comment-post {
    border: 1px solid #e6ecf0;
    border-radius: 20px;
    padding: 12px;
    margin-top: 8px;
    background-color: #f9f9f9;
    position: relative;
    z-index: 2;
}

.hover-reaction,
.tweet-option,
.read-more-btn,
.author-link {
    position: relative;
    z-index: 100;
}
</style>
         
<div class="box-tweet feed" style="position: relative; border-bottom: 2px solid #e6ecf0; margin-bottom: 15px; padding-bottom: 5px;">
    <a href="status/<?php echo $tweet_link; ?>" class="tweet-overlay"></a>

    <?php if ($retweet_sign) { ?>
        <span class="retweed-name"> 
            <i class="fa fa-retweet retweet-name-i" aria-hidden="true"></i> 
            <a style="position: relative; z-index:100; color:rgb(102, 117, 130);" href="<?php echo $retweeted_user->username; ?>"> 
                <?php if($retweeted_user->id == $user_id) echo "You";
                else echo $retweeted_user->name; ?> 
            </a> retweeted
        </span>
    <?php } ?>
    
    <div class="grid-tweet">
        <a style="position: relative; z-index:1000" href="<?php echo $tweet_user->username; ?>">
            <img src="assets/images/users/<?php echo $tweet_user->img; ?>" alt="" class="img-user-tweet" />
        </a>

        <div style="flex: 1; min-width: 0;">
            <p> 
                <a style="position: relative; z-index:1000; color:black" href="<?php echo $tweet_user->username; ?>">
                    <strong>
                        <?php echo $tweet_user->name; ?>
                        <?php if ($tweet_user->is_verified == 1) { ?>
                            <i class="fas fa-check-circle verified-icon" title="Verified"></i>
                        <?php } ?>
                    </strong>
                </a>
                <span class="username-twitter">@<?php echo $tweet_user->username; ?></span>
                <span class="username-twitter"><?php echo $timeAgo; ?></span>
            </p>
            
            <?php
            if ($retweet_comment || $qoq) {
                echo formatTweetTextWithReadMore($qoute, $tweet->id, true);
            } else {
                echo formatTweetTextWithReadMore($tweet_real->status, $tweet->id, false);
            }
            ?>
            
            <?php if ($retweet_comment == false && $qoq == false) { ?>

                <?php 
                // ✅ FIX: Get the source post ID (original tweet that contains the media)
                $source_id = Tweet::getSourcePostId($tweet->id);
                
                // Get all media (images and videos combined for unified gallery) from source
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
                        <div class="single-media">
                            <?php if ($all_media_items[0]['type'] == 'image'): ?>
                                <img src="<?php echo $all_media_items[0]['url']; ?>" alt="Tweet media" class="single-media-img" data-lightbox-index="0" data-lightbox-type="image" data-lightbox-url="<?php echo $all_media_items[0]['url']; ?>">
                            <?php else: ?>
                                <video class="single-media-video" controls preload="metadata" data-lightbox-index="0" data-lightbox-type="video" data-lightbox-url="<?php echo $all_media_items[0]['url']; ?>">
                                    <source src="<?php echo $all_media_items[0]['url']; ?>" type="video/mp4">
                                </video>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <!-- Multiple media gallery (images + videos mixed) -->
                        <div class="tweet-gallery" data-count="<?php echo min($media_count, 5); ?>">
                            <?php 
                            $display_count = min($media_count, 5);
                            for ($i = 0; $i < $display_count; $i++): 
                                $item = $all_media_items[$i];
                                $isVideo = ($item['type'] == 'video');
                            ?>
                                <div class="gallery-item <?php echo $isVideo ? 'video-item' : ''; ?> <?php echo ($i == 4 && $media_count > 5) ? 'more-overlay' : ''; ?>" 
                                     data-media-index="<?php echo $i; ?>"
                                     data-media-type="<?php echo $item['type']; ?>"
                                     data-media-url="<?php echo $item['url']; ?>"
                                     <?php if ($i == 4 && $media_count > 5): ?>
                                     data-remaining="<?php echo $media_count - 5; ?>"
                                     <?php endif; ?>>
                                    <?php if ($isVideo): ?>
                                        <video preload="metadata" muted>
                                            <source src="<?php echo $item['url']; ?>" type="video/mp4">
                                        </video>
                                    <?php else: ?>
                                        <img src="<?php echo $item['url']; ?>" alt="Gallery image">
                                    <?php endif; ?>
                                    <?php if ($i == 4 && $media_count > 5): ?>
                                        <span class="more-badge">+<?php echo $media_count - 5; ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endfor; ?>
                        </div>
                        <script type="application/json" class="tweet-all-media-json">
                            <?php echo json_encode($all_media_items); ?>
                        </script>
                    <?php endif; ?>
                <?php endif; ?>

            <?php } else { ?>

                <!-- Quoted tweet media -->
                <div class="mt-post-tweet comment-post" style="position: relative;">
                    <a href="status/<?php echo $tweet_inner->id; ?>">
                        <span class="" style="position:absolute; width:100%; height:100%; top:0;left: 0; z-index: 2;"></span>
                    </a>
                    <div class="grid-tweet py-3"> 
                        <a style="position: relative; z-index:1000" href="<?php echo $user_inner_tweet->username; ?>">
                            <img src="assets/images/users/<?php echo $user_inner_tweet->img; ?>" alt="" class="img-user-tweet" />
                        </a>

                        <div style="flex: 1; min-width: 0;">
                            <p> 
                                <a style="position: relative; z-index:1000; color:black" href="<?php echo $user_inner_tweet->username; ?>">
                                    <strong>
                                        <?php echo $user_inner_tweet->name; ?>
                                        <?php if (!empty($user_inner_tweet->is_verified) && $user_inner_tweet->is_verified == 1) { ?>
                                            <i class="fas fa-check-circle verified-icon" title="Verified"></i>
                                        <?php } ?>
                                    </strong>
                                </a>
                                <span class="username-twitter">@<?php echo $user_inner_tweet->username; ?></span>
                                <span class="username-twitter"><?php echo $timeAgo_inner; ?></span>
                            </p>
                            
                            <?php
                            if ($qoq) {
                                echo formatTweetTextWithReadMore($inner_qoute, $tweet_inner->id, true);
                            } else {
                                echo formatTweetTextWithReadMore($tweet_inner->status, $tweet_inner->id, true);
                            }
                            ?>
                            
                            <?php 
                            // ✅ FIX: Get the source ID for the quoted tweet
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
                            
                            if (empty($quoted_media_items) && !empty($tweet_inner->video)) {
                                $quoted_media_items[] = [
                                    'type' => 'video',
                                    'url' => 'assets/videos/tweets/' . $tweet_inner->video
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
                                    <div class="tweet-gallery" data-count="<?php echo min(count($quoted_media_items), 5); ?>">
                                        <?php 
                                        $display_count = min(count($quoted_media_items), 5);
                                        for ($i = 0; $i < $display_count; $i++): 
                                            $item = $quoted_media_items[$i];
                                        ?>
                                            <div class="gallery-item <?php echo $item['type'] == 'video' ? 'video-item' : ''; ?> <?php echo ($i == 4 && count($quoted_media_items) > 5) ? 'more-overlay' : ''; ?>"
                                                 data-media-index="<?php echo $i; ?>"
                                                 data-media-type="<?php echo $item['type']; ?>"
                                                 data-media-url="<?php echo $item['url']; ?>">
                                                <?php if ($item['type'] == 'video'): ?>
                                                    <video preload="metadata" muted>
                                                        <source src="<?php echo $item['url']; ?>" type="video/mp4">
                                                    </video>
                                                <?php else: ?>
                                                    <img src="<?php echo $item['url']; ?>" alt="Gallery image">
                                                <?php endif; ?>
                                                <?php if ($i == 4 && count($quoted_media_items) > 5): ?>
                                                    <span class="more-badge">+<?php echo count($quoted_media_items) - 5; ?></span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endfor; ?>
                                    </div>
                                    <script type="application/json" class="tweet-all-media-json">
                                        <?php echo json_encode($quoted_media_items); ?>
                                    </script>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php } ?>

            <!-- Reactions -->
            <div class="grid-reactions">
                <div class="grid-box-reaction">
                    <div class="hover-reaction hover-reaction-comment comment"
                        data-user="<?php echo $user_id; ?>" 
                        data-tweet="<?php 
                        if($retweet_sign)
                            echo $retweeted_tweet->id;
                        else echo $tweet->id; ?>">
                        <i class="far fa-comment"></i>
                        <div class="mt-counter likes-count d-inline-block">
                            <p><?php if($comment_count > 0) echo $comment_count; ?></p>
                        </div>
                    </div>
                </div>
                
                <div class="grid-box-reaction">
                    <div class="hover-reaction hover-reaction-retweet <?= $user_retweeted_it ? 'retweeted' : 'retweet' ?> option"
                        data-tweet="<?php echo $tweet->id; ?>" 
                        data-user="<?php echo $user_id; ?>"
                        data-retweeted="<?php echo $user_retweeted_it; ?>"
                        data-sign="<?php echo $retweet_sign; ?>"
                        data-tmp="<?php echo $retweet_comment; ?>"
                        data-qoq="<?php echo $qoq; ?>">
                        <i class="fas fa-retweet"></i>
                        <div class="mt-counter likes-count d-inline-block">
                            <p><?php if($retweets_count > 0) echo $retweets_count; ?></p>
                        </div>
                    </div>
                </div>
                
                <div class="grid-box-reaction">
                    <a class="hover-reaction hover-reaction-like <?= $user_like_it ? 'unlike-btn' : 'like-btn' ?>" 
                        data-tweet="<?php 
                        if($retweet_sign) {
                            if($retweet->tweet_id != null) {
                                echo $retweet->tweet_id;
                            } echo $retweet->retweet_id;
                        } else echo $tweet->id; ?>" 
                        data-user="<?php echo $user_id; ?>">
                        <i class="fa-heart <?= $user_like_it ? 'fas' : 'far mt-icon-reaction' ?>"></i>
                        <div class="mt-counter likes-count d-inline-block">
                            <p><?php if($likes_count > 0) echo $likes_count; ?></p>
                        </div>
                    </a>
                </div>

                <div class="tweet-option tweet-bookmark" data-tweet="<?php echo $tweet->id; ?>">
                    <?php
                    $stmt = Connect::connect()->prepare("
                        SELECT * FROM bookmarks WHERE user_id = :uid AND tweet_id = :tid
                    ");
                    $stmt->execute(['uid' => $_SESSION['user_id'], 'tid' => $tweet->id]);
                    $isBookmarked = $stmt->rowCount() > 0;
                    ?>
                    <i class="fa fa-bookmark <?php echo $isBookmarked ? 'bookmarked' : ''; ?>"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Unified Lightbox for Images and Videos -->
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

<div class="popupTweet"></div>
<div class="popupComment"></div>

<script>
$(document).ready(function() {
    // Handle Show More/Less buttons
    $(document).on('click', '.read-more-btn', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        var btn = $(this);
        var id = btn.data('id');
        var shortText = $('#tweet-short-' + id);
        var fullText = $('#tweet-full-' + id);
        var btnText = btn.find('.btn-text');
        var btnIcon = btn.find('.btn-icon');
        
        if (shortText.is(':visible')) {
            shortText.fadeOut(150, function() {
                fullText.fadeIn(150);
                btnText.text('Show less');
                btnIcon.removeClass('fa-chevron-down').addClass('fa-chevron-up');
                btn.addClass('expanded');
            });
        } else {
            fullText.fadeOut(150, function() {
                shortText.fadeIn(150);
                btnText.text('Show more');
                btnIcon.removeClass('fa-chevron-up').addClass('fa-chevron-down');
                btn.removeClass('expanded');
            });
        }
        return false;
    });
    
    // Handle clicks on the tweet box
    $(document).on('click', '.box-tweet', function(e) {
        if ($(e.target).closest('.read-more-btn').length ||
            $(e.target).closest('.hover-reaction').length ||
            $(e.target).closest('.tweet-option').length ||
            $(e.target).closest('.author-link').length ||
            $(e.target).is('a') ||
            $(e.target).closest('a').length ||
            $(e.target).is('video') ||
            $(e.target).closest('video').length ||
            $(e.target).is('img') ||
            $(e.target).closest('.gallery-item').length ||
            $(e.target).closest('.single-media').length ||
            $(e.target).closest('.comment-post').length) {
            return;
        }
        
        var statusUrl = $(this).find('.tweet-overlay').attr('href');
        if (statusUrl) {
            window.location.href = statusUrl;
        }
    });
    
    // ========== UNIFIED LIGHTBOX FOR IMAGES AND VIDEOS ==========
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
            if (e.key === 'ArrowLeft') {
                $('.lightbox-prev').click();
            } else if (e.key === 'ArrowRight') {
                $('.lightbox-next').click();
            } else if (e.key === 'Escape') {
                closeUnifiedLightbox();
            }
        }
    });
    
    // Handle click on gallery items (both images and videos)
    $(document).on('click', '.gallery-item', function(e) {
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
            }
        });
        
        let clickedIndex = $gallery.find('.gallery-item').index($item);
        if (clickedIndex >= 0 && mediaItems.length > 0) {
            openUnifiedLightbox(mediaItems, clickedIndex);
        }
    });
    
    // Handle click on single images
    $(document).on('click', '.single-media-img', function(e) {
        e.stopPropagation();
        const imgSrc = $(this).attr('src');
        if (imgSrc) {
            const mediaItems = [{ type: 'image', url: imgSrc }];
            openUnifiedLightbox(mediaItems, 0);
        }
    });
    
    // Single videos should NOT open the lightbox.
    // They play directly inside the tweet using the browser's native video controls.
    $(document).on('click', '.single-media-video', function(e) {
        e.stopPropagation();
    });
});
</script>