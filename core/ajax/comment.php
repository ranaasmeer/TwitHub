<?php 
	include '../init.php';
	$user_id = $_SESSION['user_id'];
	// ========== ADD DELETE HANDLERS HERE (BEFORE YOUR EXISTING CODE) ==========

// Delete Comment Handler
if(isset($_POST['delete_comment']) && !empty($_POST['delete_comment'])){
    $comment_id = $_POST['delete_comment'];
    
    // Check if comment exists and user owns it
    $comment = Tweet::getComment($comment_id);
    
    if($comment && $comment->user_id == $user_id) {
        // Delete all replies to this comment
        $replies = Tweet::getRepliesByCommentId($comment_id);
        foreach($replies as $reply) {
            // Delete child replies first
            deleteChildReplies($reply->id);
            // Delete the reply
            Tweet::deleteRecord('replies', $reply->id);
        }
        
        // Delete the comment
        Tweet::deleteRecord('comments', $comment_id);
        
        echo json_encode(['success' => true, 'message' => 'Comment deleted successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    }
    exit;
}

// Delete Reply Handler
if(isset($_POST['delete_reply']) && !empty($_POST['delete_reply'])){
    $reply_id = $_POST['delete_reply'];
    
    // Check if reply exists and user owns it
    $reply = Tweet::getReply($reply_id);
    
    if($reply && $reply->user_id == $user_id) {
        // Delete all child replies recursively
        deleteChildReplies($reply_id);
        
        // Delete the reply
        Tweet::deleteRecord('replies', $reply_id);
        
        echo json_encode(['success' => true, 'message' => 'Reply deleted successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    }
    exit;
}

// Helper function to delete all child replies recursively
function deleteChildReplies($reply_id) {
    $child_replies = Tweet::getRepliesByParentId($reply_id);
    foreach($child_replies as $child) {
        deleteChildReplies($child->id);
        Tweet::deleteRecord('replies', $child->id);
    }
}


// ✅ Collect all tweet media like tweets.php (images + videos + old single fields)
if (!function_exists('getCommentPopupMediaItems')) {
    function getCommentPopupMediaItems($tweetObj) {
        $items = [];
        if (!$tweetObj || empty($tweetObj->id)) {
            return $items;
        }

        if (method_exists('Tweet', 'getTweetMedia')) {
            $media_list = Tweet::getTweetMedia($tweetObj->id);
            foreach ($media_list as $media) {
                if (!empty($media->media_path)) {
                    $items[] = [
                        'type' => 'image',
                        'url'  => 'assets/images/tweets/' . $media->media_path
                    ];
                }
            }
        }

        if (method_exists('Tweet', 'getTweetVideos')) {
            $video_list = Tweet::getTweetVideos($tweetObj->id);
            foreach ($video_list as $video) {
                if (!empty($video->video_path)) {
                    $items[] = [
                        'type' => 'video',
                        'url'  => 'assets/videos/tweets/' . $video->video_path
                    ];
                }
            }
        }

        // fallback for old single image/video columns
        if (empty($items) && !empty($tweetObj->img)) {
            $items[] = [
                'type' => 'image',
                'url'  => 'assets/images/tweets/' . $tweetObj->img
            ];
        }

        if (empty($items) && !empty($tweetObj->video)) {
            $items[] = [
                'type' => 'video',
                'url'  => 'assets/videos/tweets/' . $tweetObj->video
            ];
        }

        return $items;
    }
}

// ✅ Render professional mixed media grid in comment popup
if (!function_exists('renderCommentPopupMediaGrid')) {
    function renderCommentPopupMediaGrid($tweetObj) {
        $items = getCommentPopupMediaItems($tweetObj);
        $count = count($items);
        if ($count === 0) {
            return;
        }

        $displayCount = min($count, 5);
        ?>
        <div class="comment-popup-media-grid" data-count="<?php echo $displayCount; ?>">
            <?php for ($i = 0; $i < $displayCount; $i++):
                $item = $items[$i];
                $isVideo = ($item['type'] === 'video');
                $isMore = ($i === 4 && $count > 5);
            ?>
                <div class="comment-popup-media-item <?php echo $isVideo ? 'video-item' : ''; ?> <?php echo $isMore ? 'more-overlay' : ''; ?>"
                     data-media-index="<?php echo $i; ?>"
                     data-media-type="<?php echo htmlspecialchars($item['type'], ENT_QUOTES); ?>"
                     data-media-url="<?php echo htmlspecialchars($item['url'], ENT_QUOTES); ?>">
                    <?php if ($isVideo): ?>
                        <video preload="metadata" muted playsinline>
                            <source src="<?php echo htmlspecialchars($item['url'], ENT_QUOTES); ?>" type="video/mp4">
                        </video>
                    <?php else: ?>
                        <img src="<?php echo htmlspecialchars($item['url'], ENT_QUOTES); ?>" alt="Tweet media">
                    <?php endif; ?>

                    <?php if ($isMore): ?>
                        <span class="more-badge">+<?php echo $count - 5; ?></span>
                    <?php endif; ?>
                </div>
            <?php endfor; ?>
        </div>
        <script type="application/json" class="comment-popup-all-media-json"><?php echo json_encode($items); ?></script>
        <?php
    }
}

if (!function_exists('renderCommentPopupMediaAssets')) {
    function renderCommentPopupMediaAssets() {
        ?>
        <style>
            .comment-popup-media-grid {
                display: grid;
                gap: 3px;
                margin-top: 10px;
                width: 100%;
                max-width: 100%;
                overflow: hidden;
                border-radius: 14px;
                background: #f5f8fa;
                box-sizing: border-box;
            }
            .comment-popup-media-grid[data-count="1"] { grid-template-columns: 1fr; }
            .comment-popup-media-grid[data-count="2"] { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .comment-popup-media-grid[data-count="3"] { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .comment-popup-media-grid[data-count="3"] .comment-popup-media-item:first-child { grid-row: span 2; }
            .comment-popup-media-grid[data-count="4"] { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .comment-popup-media-grid[data-count="5"] { grid-template-columns: repeat(3, minmax(0, 1fr)); }

            .comment-popup-media-item {
                position: relative;
                overflow: hidden;
                cursor: pointer;
                min-width: 0;
                width: 100%;
                aspect-ratio: 1 / 1;
                background: #f5f8fa;
                box-sizing: border-box;
            }
            .comment-popup-media-grid[data-count="1"] .comment-popup-media-item { aspect-ratio: 16 / 9; max-height: 320px; }
            .comment-popup-media-grid[data-count="2"] .comment-popup-media-item { aspect-ratio: 1 / 1.05; }
            .comment-popup-media-grid[data-count="3"] .comment-popup-media-item:first-child { aspect-ratio: auto; }
            .comment-popup-media-item img,
            .comment-popup-media-item video {
                width: 100%;
                height: 100%;
                object-fit: cover;
                display: block;
            }
            .comment-popup-media-item.video-item::before {
                content: '';
                position: absolute;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                width: 46px;
                height: 46px;
                border-radius: 50%;
                background: rgba(0,0,0,0.48);
                z-index: 3;
                pointer-events: none;
            }
            .comment-popup-media-item.video-item::after {
                content: '\f04b';
                font-family: 'Font Awesome 5 Free', 'Font Awesome 6 Free', 'FontAwesome';
                font-weight: 900;
                position: absolute;
                top: 50%;
                left: 50%;
                transform: translate(-42%, -50%);
                color: #fff;
                font-size: 20px;
                z-index: 4;
                pointer-events: none;
            }
            .comment-popup-media-item.more-overlay::before {
                content: '';
                position: absolute;
                inset: 0;
                background: rgba(0,0,0,0.58);
                z-index: 5;
                pointer-events: none;
            }
            .comment-popup-media-item .more-badge {
                position: absolute;
                inset: 0;
                z-index: 6;
                display: flex;
                align-items: center;
                justify-content: center;
                color: #fff;
                font-size: 30px;
                font-weight: 800;
                pointer-events: none;
                text-shadow: 0 2px 8px rgba(0,0,0,0.35);
            }
            .comment-popup-lightbox {
                display: none;
                position: fixed;
                inset: 0;
                background: rgba(0,0,0,0.94);
                z-index: 1000000;
                align-items: center;
                justify-content: center;
            }
            .comment-popup-lightbox.active { display: flex; }
            .comment-popup-lightbox img,
            .comment-popup-lightbox video {
                max-width: 90vw;
                max-height: 85vh;
                border-radius: 8px;
                background: #000;
            }
            .comment-popup-lightbox .cp-close,
            .comment-popup-lightbox .cp-prev,
            .comment-popup-lightbox .cp-next {
                position: absolute;
                border: none;
                color: #fff;
                background: rgba(0,0,0,0.55);
                cursor: pointer;
                z-index: 1000001;
            }
            .comment-popup-lightbox .cp-close {
                top: 18px;
                right: 22px;
                width: 40px;
                height: 40px;
                border-radius: 50%;
                font-size: 28px;
                line-height: 38px;
            }
            .comment-popup-lightbox .cp-prev,
            .comment-popup-lightbox .cp-next {
                top: 50%;
                transform: translateY(-50%);
                width: 42px;
                height: 42px;
                border-radius: 50%;
                font-size: 20px;
            }
            .comment-popup-lightbox .cp-prev { left: 20px; }
            .comment-popup-lightbox .cp-next { right: 20px; }
            .comment-popup-lightbox .cp-counter {
                position: absolute;
                bottom: 18px;
                left: 50%;
                transform: translateX(-50%);
                color: #fff;
                background: rgba(0,0,0,0.55);
                padding: 5px 14px;
                border-radius: 20px;
                font-size: 13px;
            }
            @media (max-width: 600px) {
                .comment-popup-media-grid[data-count="5"] { grid-template-columns: repeat(2, minmax(0, 1fr)); }
                .comment-popup-media-grid[data-count="5"] .comment-popup-media-item:first-child { grid-column: span 2; aspect-ratio: 16 / 9; }
                .comment-popup-lightbox .cp-prev { left: 8px; }
                .comment-popup-lightbox .cp-next { right: 8px; }
            }
        </style>
        <div class="comment-popup-lightbox" id="commentPopupLightbox">
            <button type="button" class="cp-close">&times;</button>
            <button type="button" class="cp-prev"><i class="fas fa-chevron-left"></i></button>
            <div class="cp-stage"></div>
            <button type="button" class="cp-next"><i class="fas fa-chevron-right"></i></button>
            <div class="cp-counter"></div>
        </div>
        <script>
        (function(){
            if (window.commentPopupMediaReady) return;
            window.commentPopupMediaReady = true;

            var items = [];
            var index = 0;

            function getLightbox(){ return document.getElementById('commentPopupLightbox'); }
            function render(){
                var lb = getLightbox();
                if (!lb || !items[index]) return;
                var stage = lb.querySelector('.cp-stage');
                var counter = lb.querySelector('.cp-counter');
                var prev = lb.querySelector('.cp-prev');
                var next = lb.querySelector('.cp-next');
                stage.innerHTML = '';
                var item = items[index];
                if (item.type === 'video') {
                    var video = document.createElement('video');
                    video.controls = true;
                    video.src = item.url;
                    stage.appendChild(video);
                } else {
                    var img = document.createElement('img');
                    img.src = item.url;
                    stage.appendChild(img);
                }
                counter.textContent = (index + 1) + ' of ' + items.length;
                prev.style.display = items.length > 1 ? 'block' : 'none';
                next.style.display = items.length > 1 ? 'block' : 'none';
            }
            function open(mediaItems, startIndex){
                items = mediaItems;
                index = startIndex || 0;
                var lb = getLightbox();
                if (!lb) return;
                render();
                lb.classList.add('active');
                document.body.style.overflow = 'hidden';
            }
            function close(){
                var lb = getLightbox();
                if (!lb) return;
                lb.classList.remove('active');
                lb.querySelector('.cp-stage').innerHTML = '';
                document.body.style.overflow = '';
            }

            document.addEventListener('click', function(e){
                var mediaItem = e.target.closest('.comment-popup-media-item');
                if (mediaItem) {
                    e.preventDefault();
                    e.stopPropagation();
                    var grid = mediaItem.closest('.comment-popup-media-grid');
                    var json = grid ? grid.nextElementSibling : null;
                    var mediaItems = [];
                    if (json && json.classList.contains('comment-popup-all-media-json')) {
                        try { mediaItems = JSON.parse(json.textContent || '[]'); } catch(err) { mediaItems = []; }
                    }
                    if (!mediaItems.length && grid) {
                        grid.querySelectorAll('.comment-popup-media-item').forEach(function(el){
                            mediaItems.push({ type: el.dataset.mediaType || 'image', url: el.dataset.mediaUrl });
                        });
                    }
                    var start = parseInt(mediaItem.dataset.mediaIndex || '0', 10);
                    open(mediaItems, start);
                    return;
                }

                if (e.target.closest('#commentPopupLightbox .cp-close')) { close(); return; }
                if (e.target.closest('#commentPopupLightbox .cp-prev')) { if (index > 0) { index--; render(); } return; }
                if (e.target.closest('#commentPopupLightbox .cp-next')) { if (index < items.length - 1) { index++; render(); } return; }
                if (e.target.id === 'commentPopupLightbox') { close(); }
            });

            document.addEventListener('keydown', function(e){
                var lb = getLightbox();
                if (!lb || !lb.classList.contains('active')) return;
                if (e.key === 'Escape') close();
                if (e.key === 'ArrowLeft' && index > 0) { index--; render(); }
                if (e.key === 'ArrowRight' && index < items.length - 1) { index++; render(); }
            });
        })();
        </script>
        <?php
    }
}
	// Comment place
	if(isset($_POST['qoute']) && !empty($_POST['qoute'])){
		$tweet_id  = $_POST['qoute'];
		$get_id    = $_POST['user_id'];
		// $flag = $_POST['isQoute'];
		// $qoq = $_POST['qoq'];
		$comment   = User::checkInput($_POST['comment']);
        date_default_timezone_set("Africa/Cairo");
		// $retweet = Tweet::getRetweet($tweet_id);
		

        //  if(!$flag_retweeted) {
			

			$data = [
				'user_id' => $_SESSION['user_id'] , 
                'post_id' => $tweet_id , 
                'comment' => $comment , 
				'time' => date("Y-m-d H:i:s") ,
			];
		    if ($comment != '') {
				$for_user = Tweet::getData($tweet_id)->user_id;
		
					if($for_user != $user_id) {
						$data_notify = [
						'notify_for' => $for_user ,
						'notify_from' => $user_id ,
						'target' => $tweet_id , 
						'type' => 'comment' ,
						'time' => date("Y-m-d H:i:s") ,
						'count' => '0' , 
						'status' => '0'
						];
				
						Tweet::create('notifications' , $data_notify);
						
					} 

		     User::create('comments' , $data);
		  
			//  $comments = Tweet::comments($tweet_id);
			//  foreach($comments as $comment) {
			// 	$tweet_user = User::getData($comment->user_id) ;
            //      echo '<div class="box-comment feed py-2"  >
                
          
			// 	 <div class="grid-tweet">
			// 	   <div>
			// 		 <img
			// 		   src="assets/images/users/'. $tweet_user->img.' "
			// 		   alt=""
			// 		   class="img-user-tweet"
			// 		 />
			// 	   </div>
	   
			// 	   <div>
			// 		 <p>
			// 		   <strong> '. $tweet_user->name .' </strong>
			// 		   <span class="username-twitter">@ '.$tweet_user->username.'  </span>
			// 		   <span class="username-twitter"> $timeAgo </span>
			// 		 </p>
			// 		 <p>
					  
			// 		  '.  Tweet::getTweetLinks($comment->comment) .'
			// 		 </p>
			// 	   </div> 
			   
			// 	 </div>  </div> ';
			//  }



			}
	}

if(isset($_POST['reply']) && !empty($_POST['reply'])){

    $clicked_id = $_POST['reply']; // Could be comment ID OR reply ID
    $user_id    = $_SESSION['user_id'];
    $comment    = User::checkInput($_POST['comment']);

    if ($comment == '') exit;

    date_default_timezone_set("Africa/Cairo");

    // ✅ Check if the clicked ID is a comment
    $parent = Tweet::getComment($clicked_id);

    if (!$parent) {
        // ✅ If not a comment → check reply table
        $parent = Tweet::getReply($clicked_id);

        if (!$parent) {
            exit("Invalid parent ID");
        }

        // ✅ Replying to a reply → We attach to the original comment
        $main_comment_id = $parent->comment_id;

    } else {
        // ✅ Replying to a comment
        $main_comment_id = $parent->id;
    }

    // ✅ Insert reply safely
    $data = [
        'user_id' => $user_id,
        'comment_id' => $main_comment_id, // ✅ ALWAYS a comment id
        'reply' => $comment,
        'time' => date("Y-m-d H:i:s"),
    ];

    User::create('replies', $data);

    // ✅ Send notification
    $for_user = $parent->user_id;
    $target   = $parent->post_id ?? $parent->comment_id;

    if ($for_user != $user_id) {

        $data_notify = [
            'notify_for' => $for_user,
            'notify_from' => $user_id,
            'target' => $target,
            'type' => 'reply',
            'time' => date("Y-m-d H:i:s"),
            'count' => '0',
            'status' => '0'
        ];

        Tweet::create('notifications', $data_notify);
    }
}

        // Comment on Post popup
	if(isset($_POST['showPopup']) && !empty($_POST['showPopup'])){
		$tweet_id   = $_POST['showPopup'];
		$user       = User::getData($user_id);
		$retweet_comment = false;
		$qoq = false;
		if (Tweet::isRetweet($tweet_id)) {
		$retweet =Tweet::getRetweet($tweet_id);
		if ($retweet->retweet_id == null) {

				// when the retweetd tweet is normal tweet
				
			if ($retweet->retweet_msg != null) {
				
				// when quote 

                $user_tweet = User::getData($retweet->user_id) ;
				 $timeAgo = Tweet::getTimeAgo($retweet->post_on) ; 
				 $qoute = $retweet->retweet_msg;
                 $retweet_comment = true;
           

              $tweet_inner = Tweet::getTweet($retweet->tweet_id);
              $user_inner_tweet = User::getData($tweet_inner->user_id) ;
              $timeAgo_inner = Tweet::getTimeAgo($tweet_inner->post_on); 


			} else {
				// when normal retweet

				$tweet      = Tweet::getTweet($retweet->tweet_id);
		    	$user_tweet = User::getData($tweet->user_id);
		    	$timeAgo = Tweet::getTimeAgo($tweet->post_on) ; 
			}
		} else {
			// if tweet_id = null and retweeted_id not null then it's retweet od quote
			// so we have to get the retweeted tweet first

			// here condtion of retweeted a quoted tweet
		
			if ($retweet->retweet_msg == null) {
				
				$retweeted_tweet = Tweet::getRetweet($retweet->retweet_id);

				if($retweeted_tweet->tweet_id != null) {
						$user_tweet = User::getData($retweeted_tweet->user_id) ;
						$timeAgo = Tweet::getTimeAgo($retweeted_tweet->post_on) ; 

						$retweet_inner = Tweet::getRetweet($retweet->retweet_id);

						$qoute = $retweet_inner->retweet_msg;
						$retweet_comment = true;
				

					
					$tweet_inner = Tweet::getTweet($retweet_inner->tweet_id);
					$user_inner_tweet = User::getData($tweet_inner->user_id) ;
					$timeAgo_inner = Tweet::getTimeAgo($tweet_inner->post_on); 

				} else {
					// hereeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee

					     $user_tweet = User::getData($retweeted_tweet->user_id) ;
						$timeAgo = Tweet::getTimeAgo($retweeted_tweet->post_on) ; 

						$retweet_inner = Tweet::getRetweet($retweet->retweet_id);

						$qoute = $retweet_inner->retweet_msg;
						$retweet_comment = true;
				        $qoq = true;

					
					$tweet_inner = Tweet::getRetweet($retweeted_tweet->retweet_id);
					// $tweet_inner = Tweet::getRetweet($tweet_inner->retweet_id);
					$user_inner_tweet = User::getData($tweet_inner->user_id) ;
					$timeAgo_inner = Tweet::getTimeAgo($tweet_inner->post_on); 
                    $inner_qoute = $tweet_inner->retweet_msg;

				}
			} else {

				// here must handle the quote of quote display

				$user_tweet = User::getData($retweet->user_id) ;
				$timeAgo = Tweet::getTimeAgo($retweet->post_on) ; 
				// $likes_count = Tweet::countLikes($tweet->id) ;
				// $user_like_it = Tweet::userLikeIt($user_id ,$tweet->id);
				// $retweets_count = Tweet::countRetweets($tweet->id) ;
				// $user_retweeted_it = Tweet::userRetweeetedIt($user_id ,$tweet->id);
				$qoute = $retweet->retweet_msg;
				$qoq = true; // stand for quote of quote
				
				$tweet_inner = Tweet::getRetweet($retweet->retweet_id);
				$user_inner_tweet = User::getData($tweet_inner->user_id) ;
				$timeAgo_inner = Tweet::getTimeAgo($tweet_inner->post_on);
				$inner_qoute = $tweet_inner->retweet_msg;
			}
			
		}	

	} else {

		 // when normal tweet

		$tweet      = Tweet::getTweet($tweet_id);
		$user_tweet = User::getData($tweet->user_id);
		$timeAgo = Tweet::getTimeAgo($tweet->post_on) ;
		

	}
	
?>
<?php renderCommentPopupMediaAssets(); ?>
<div class="retweet-popup">
<div class="wrap5">
	<div class="retweet-popup-body-wrap">
		<div class="retweet-popup-heading">
			<h3>Reply Tweet</h3>
			<span><button class="close-retweet-popup"><i class="fa fa-times" aria-hidden="true"></i></button></span>
		</div>
		<div class="retweet-popup-input">
			<div class="retweet-popup-input-inner">
				<input  class="retweet-msg" type="text" placeholder="Add Comment.."/>
			</div>
		</div>
		
				
		<div class="grid-tweet py-2">
              <div>
                <img
                  src="assets/images/users/<?php echo $user_tweet->img; ?>"
                  alt=""
                  class="img-user-tweet"
                />
              </div>
  
              <div>
                <p>
                  <strong> <?php echo $user_tweet->name ?> </strong>
                  <span class="username-twitter">@<?php echo $user_tweet->username ?> </span>
                  <span class="username-twitter"><?php echo $timeAgo ?></span>
                </p>
                <p>
				<?php
                  // check if it's quote or normal tweet
                  if ($retweet_comment || $qoq)
                  echo  Tweet::getTweetLinks($qoute);
                  else echo  Tweet::getTweetLinks($tweet->status); ?>
				</p>
				
				<?php if ($retweet_comment == false && $qoq == false) { ?>
                    <?php renderCommentPopupMediaGrid($tweet); ?>
			   <?php }  else { ?>

				<div  class="mt-post-tweet comment-post">

				<div class="grid-tweet py-3  ">
				<div>
				<img
				src="assets/images/users/<?php echo $user_inner_tweet->img; ?>"
				alt=""
				class="img-user-tweet"
				/>
				</div>

				<div>
				<p>
				<strong> <?php echo $user_inner_tweet->name ?> </strong>
				<span class="username-twitter">@<?php echo $user_inner_tweet->username ?> </span>
				<span class="username-twitter"><?php echo $timeAgo_inner ?></span>
				</p>
				<p>
				<?php 
				    if ($qoq)
                    echo $inner_qoute;
                    else  echo  Tweet::getTweetLinks($tweet_inner->status); ?>
				</p>
				<?php
				if($qoq == false) {
                    renderCommentPopupMediaGrid($tweet_inner);
                } ?>

</div>
</div>
	   

</div>

<?php } ?>
			   

	</div>
</div>


		<div class="retweet-popup-footer"> 
			<div class="retweet-popup-footer-right">
				<button class="comment-it" 
				data-tweet="<?php echo $tweet_id;?>"
				data-user="<?php echo $user_id;?>"
				data-tmp="<?php echo $retweet_comment; ?>" 
				data-qoq="<?php echo $qoq; ?>" 
			 type="submit"><i class="fas fa-pencil-alt" aria-hidden="true"></i>Reply</button>
			</div>
		</div> 
		

</div>

<!-- Post Comment PopUp ends-->

<?php }  

// ✅ Replying to comment or reply popup
if(isset($_POST['showReply']) && !empty($_POST['showReply'])){

    $comment_id = $_POST['showReply'];
    $user       = User::getData($user_id);

    // ✅ Check if ID belongs to comment or reply
    $commentObj = Tweet::getComment($comment_id);

    if (!$commentObj) {
        // try reply table
        $commentObj = Tweet::getReply($comment_id);
    }

    if (!$commentObj) {
        exit("Invalid comment or reply ID");
    }

    // ✅ Correct user + time
    $user_tweet = User::getData($commentObj->user_id);
    $timeAgo    = Tweet::getTimeAgo($commentObj->time);

    // ✅ Correct text depending on table
    $commentText = $commentObj->comment ?? $commentObj->reply;
?>
<div class="retweet-popup">
<div class="wrap5">
<div class="retweet-popup-body-wrap">
	<div class="retweet-popup-heading">
		<h3>Reply Comment</h3>
		<span><button class="close-retweet-popup"><i class="fa fa-times"></i></button></span>
	</div>
	<div class="retweet-popup-input">
		<div class="retweet-popup-input-inner">
			<input class="retweet-msg" type="text" placeholder="Add Reply.."/>
		</div>
	</div>

	<div class="grid-tweet py-2">
		<div>
			<img src="assets/images/users/<?php echo $user_tweet->img; ?>" class="img-user-tweet">
		</div>

		<div>
			<p>
				<strong><?php echo $user_tweet->name ?></strong>
				<span class="username-twitter">@<?php echo $user_tweet->username ?></span>
				<span class="username-twitter"><?php echo $timeAgo ?></span>
			</p>

			<p><?php echo Tweet::getTweetLinks($commentText); ?></p>
		</div>
	</div>

	<div class="retweet-popup-footer"> 
		<div class="retweet-popup-footer-right">
			<button class="reply-it" 
				data-tweet="<?php echo $comment_id;?>"
				data-user="<?php echo $user_id;?>"
				type="submit">
				<i class="fas fa-pencil-alt"></i> Reply
			</button>
		</div>
	</div>
</div>
<?php 
}
?>



