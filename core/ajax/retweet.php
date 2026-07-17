<?php 
	include '../init.php';
	$user_id = $_SESSION['user_id'];
	date_default_timezone_set("Africa/Cairo");

	// ========== MEDIA HELPER FUNCTIONS (Same as comment.php) ==========
	
	if (!function_exists('getQuotePopupMediaItems')) {
	    function getQuotePopupMediaItems($tweetObj) {
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

	if (!function_exists('renderQuotePopupMediaGrid')) {
	    function renderQuotePopupMediaGrid($tweetObj) {
	        $items = getQuotePopupMediaItems($tweetObj);
	        $count = count($items);
	        if ($count === 0) {
	            return;
	        }

	        $displayCount = min($count, 5);
	        ?>
	        <div class="quote-popup-media-grid" data-count="<?php echo $displayCount; ?>">
	            <?php for ($i = 0; $i < $displayCount; $i++):
	                $item = $items[$i];
	                $isVideo = ($item['type'] === 'video');
	                $isMore = ($i === 4 && $count > 5);
	            ?>
	                <div class="quote-popup-media-item <?php echo $isVideo ? 'video-item' : ''; ?> <?php echo $isMore ? 'more-overlay' : ''; ?>"
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
	        <script type="application/json" class="quote-popup-all-media-json"><?php echo json_encode($items); ?></script>
	        <?php
	    }
	}

	if (!function_exists('renderQuotePopupMediaAssets')) {
	    function renderQuotePopupMediaAssets() {
	        ?>
	        <style>
	            .quote-popup-media-grid {
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
	            .quote-popup-media-grid[data-count="1"] { grid-template-columns: 1fr; }
	            .quote-popup-media-grid[data-count="2"] { grid-template-columns: repeat(2, minmax(0, 1fr)); }
	            .quote-popup-media-grid[data-count="3"] { grid-template-columns: repeat(2, minmax(0, 1fr)); }
	            .quote-popup-media-grid[data-count="3"] .quote-popup-media-item:first-child { grid-row: span 2; }
	            .quote-popup-media-grid[data-count="4"] { grid-template-columns: repeat(2, minmax(0, 1fr)); }
	            .quote-popup-media-grid[data-count="5"] { grid-template-columns: repeat(3, minmax(0, 1fr)); }

	            .quote-popup-media-item {
	                position: relative;
	                overflow: hidden;
	                cursor: pointer;
	                min-width: 0;
	                width: 100%;
	                aspect-ratio: 1 / 1;
	                background: #f5f8fa;
	                box-sizing: border-box;
	            }
	            .quote-popup-media-grid[data-count="1"] .quote-popup-media-item { aspect-ratio: 16 / 9; max-height: 320px; }
	            .quote-popup-media-grid[data-count="2"] .quote-popup-media-item { aspect-ratio: 1 / 1.05; }
	            .quote-popup-media-grid[data-count="3"] .quote-popup-media-item:first-child { aspect-ratio: auto; }
	            .quote-popup-media-item img,
	            .quote-popup-media-item video {
	                width: 100%;
	                height: 100%;
	                object-fit: cover;
	                display: block;
	            }
	            .quote-popup-media-item.video-item::before {
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
	            .quote-popup-media-item.video-item::after {
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
	            .quote-popup-media-item.more-overlay::before {
	                content: '';
	                position: absolute;
	                inset: 0;
	                background: rgba(0,0,0,0.58);
	                z-index: 5;
	                pointer-events: none;
	            }
	            .quote-popup-media-item .more-badge {
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
	            .quote-popup-lightbox {
	                display: none;
	                position: fixed;
	                inset: 0;
	                background: rgba(0,0,0,0.94);
	                z-index: 1000000;
	                align-items: center;
	                justify-content: center;
	            }
	            .quote-popup-lightbox.active { display: flex; }
	            .quote-popup-lightbox img,
	            .quote-popup-lightbox video {
	                max-width: 90vw;
	                max-height: 85vh;
	                border-radius: 8px;
	                background: #000;
	            }
	            .quote-popup-lightbox .qp-close,
	            .quote-popup-lightbox .qp-prev,
	            .quote-popup-lightbox .qp-next {
	                position: absolute;
	                border: none;
	                color: #fff;
	                background: rgba(0,0,0,0.55);
	                cursor: pointer;
	                z-index: 1000001;
	            }
	            .quote-popup-lightbox .qp-close {
	                top: 18px;
	                right: 22px;
	                width: 40px;
	                height: 40px;
	                border-radius: 50%;
	                font-size: 28px;
	                line-height: 38px;
	            }
	            .quote-popup-lightbox .qp-prev,
	            .quote-popup-lightbox .qp-next {
	                top: 50%;
	                transform: translateY(-50%);
	                width: 42px;
	                height: 42px;
	                border-radius: 50%;
	                font-size: 20px;
	            }
	            .quote-popup-lightbox .qp-prev { left: 20px; }
	            .quote-popup-lightbox .qp-next { right: 20px; }
	            .quote-popup-lightbox .qp-counter {
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
	                .quote-popup-media-grid[data-count="5"] { grid-template-columns: repeat(2, minmax(0, 1fr)); }
	                .quote-popup-media-grid[data-count="5"] .quote-popup-media-item:first-child { grid-column: span 2; aspect-ratio: 16 / 9; }
	                .quote-popup-lightbox .qp-prev { left: 8px; }
	                .quote-popup-lightbox .qp-next { right: 8px; }
	            }
	        </style>
	        <div class="quote-popup-lightbox" id="quotePopupLightbox">
	            <button type="button" class="qp-close">&times;</button>
	            <button type="button" class="qp-prev"><i class="fas fa-chevron-left"></i></button>
	            <div class="qp-stage"></div>
	            <button type="button" class="qp-next"><i class="fas fa-chevron-right"></i></button>
	            <div class="qp-counter"></div>
	        </div>
	        <script>
	        (function(){
	            if (window.quotePopupMediaReady) return;
	            window.quotePopupMediaReady = true;

	            var items = [];
	            var index = 0;

	            function getLightbox(){ return document.getElementById('quotePopupLightbox'); }
	            function render(){
	                var lb = getLightbox();
	                if (!lb || !items[index]) return;
	                var stage = lb.querySelector('.qp-stage');
	                var counter = lb.querySelector('.qp-counter');
	                var prev = lb.querySelector('.qp-prev');
	                var next = lb.querySelector('.qp-next');
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
	                lb.querySelector('.qp-stage').innerHTML = '';
	                document.body.style.overflow = '';
	            }

	            document.addEventListener('click', function(e){
	                var mediaItem = e.target.closest('.quote-popup-media-item');
	                if (mediaItem) {
	                    e.preventDefault();
	                    e.stopPropagation();
	                    var grid = mediaItem.closest('.quote-popup-media-grid');
	                    var json = grid ? grid.nextElementSibling : null;
	                    var mediaItems = [];
	                    if (json && json.classList.contains('quote-popup-all-media-json')) {
	                        try { mediaItems = JSON.parse(json.textContent || '[]'); } catch(err) { mediaItems = []; }
	                    }
	                    if (!mediaItems.length && grid) {
	                        grid.querySelectorAll('.quote-popup-media-item').forEach(function(el){
	                            mediaItems.push({ type: el.dataset.mediaType || 'image', url: el.dataset.mediaUrl });
	                        });
	                    }
	                    var start = parseInt(mediaItem.dataset.mediaIndex || '0', 10);
	                    open(mediaItems, start);
	                    return;
	                }

	                if (e.target.closest('#quotePopupLightbox .qp-close')) { close(); return; }
	                if (e.target.closest('#quotePopupLightbox .qp-prev')) { if (index > 0) { index--; render(); } return; }
	                if (e.target.closest('#quotePopupLightbox .qp-next')) { if (index < items.length - 1) { index++; render(); } return; }
	                if (e.target.id === 'quotePopupLightbox') { close(); }
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
	
	// ========== EXISTING RETWEET LOGIC (Keep as is) ==========
	
	if(isset($_POST['qoute']) && !empty($_POST['qoute'])){
		$tweet_id  = $_POST['qoute'];
		$get_id    = $_POST['user_id'];
		$flag = $_POST['isQoute'];
		$qoq = $_POST['qoq'];
		$comment   = User::checkInput($_POST['comment']);

		$retweet = Tweet::getRetweet($tweet_id);
		

		// for notification
		if(isset($retweet->user_id))
		$for_user = $retweet->user_id;
		else $for_user = Tweet::getTweet($tweet_id)->user_id;	

		if($for_user != $user_id) {
			$data_notify = [
              'notify_for' => $for_user ,
			  'notify_from' => $user_id ,
			  'type' => 'qoute' ,
			   'time' => date("Y-m-d H:i:s") ,
			   'count' => '0' , 
			   'status' => '0'
			];
				
		} 


		// check if user retweeted it to avoid double retweet from quote btn
		if ($flag == false && $qoq == false && Tweet::isRetweet($tweet_id)) {
			$flag_retweeted = Tweet::userRetweeetedIt($user_id,$retweet->tweet_id);
		} else $flag_retweeted = Tweet::userRetweeetedIt($user_id,$tweet_id);
		

        //  if(!$flag_retweeted) {
			date_default_timezone_set("Africa/Cairo");

			$data = [
				'user_id' => $_SESSION['user_id'] , 
				'post_on' => date("Y-m-d H:i:s") ,
			];
			// create function can handle with all tables and return last inserted id
			$post_id =   User::create('posts' , $data);
			// qoq is check if this tweet quote of quote or not
			if ($comment != '') {
	
			// if flag true then the retweeted post is quote tweet and the fk is retweet_id
					if ($flag && !$qoq) {
						if(Tweet::isRetweet($tweet_id)) {

							$data_tweet = [
								'post_id' => $post_id ,
								'retweet_msg' => $comment , 
								'retweet_id' => $retweet->post_id ,
								'tweet_id' => null
							];
							// for notification
								if($for_user != $user_id) 
								$data_notify['target']= $post_id;
							
						} else {
                            $data_tweet = [
								'post_id' => $post_id ,
								'retweet_msg' => $comment , 
								'retweet_id' => $tweet_id ,
								'tweet_id' => null
							];
							// for notification
							if($for_user != $user_id) 
							$data_notify['target']= $post_id;
						}
						
					} else if ($qoq) {

							if ($retweet->retweet_msg == null ) {
							$data_tweet = [
								'post_id' => $post_id ,
								'retweet_msg' => $comment , 
								'retweet_id' => $retweet->post_id ,
								'tweet_id' => null
							];
							// for notification
							if($for_user != $user_id) 
							$data_notify['target']= $post_id;

						}	else {
							$data_tweet = [
								'post_id' => $post_id ,
								'retweet_msg' => $comment , 
								'retweet_id' => $tweet_id ,
								'tweet_id' => null
							];
							// for notification
							if($for_user != $user_id) 
							$data_notify['target']= $post_id;
						}
	
	
					} else {
							if(Tweet::isRetweet($tweet_id)) {
								$data_tweet = [
									'post_id' => $post_id ,
									'retweet_msg' => $comment , 
									'tweet_id' => $retweet->tweet_id ,
									'retweet_id' => null
								];
								// for notification
							if($for_user != $user_id) 
							$data_notify['target']= $post_id;
							} else {
								$data_tweet = [
									'post_id' => $post_id ,
									'retweet_msg' => $comment , 
									'tweet_id' => $tweet_id ,
									'retweet_id' => null
								]; 
								// for notification
							    if($for_user != $user_id) 
								$data_notify['target']= $post_id;
							}
					}
		} else if ($comment == '') {
	          
			 $data_notify['type'] = 'retweet';

			if ($flag) {
				if(Tweet::isRetweet($tweet_id)) {
					$data_tweet = [
						'post_id' => $post_id ,
						'retweet_msg' => null , 
						'retweet_id' => $retweet->post_id,
						'tweet_id' => null
					];
					// for notification
				if($for_user != $user_id) 
				$data_notify['target']=$retweet->post_id;
				} else {
					$data_tweet = [
						'post_id' => $post_id ,
						'retweet_msg' => null , 
						'retweet_id' => $tweet_id,
						'tweet_id' => null
					];
					// for notification
					if($for_user != $user_id) 
					$data_notify['target']= $tweet_id;
				}
			} else if ($qoq) {

	            if ($retweet->retweet_msg == null ) {
				$data_tweet = [
					'post_id' => $post_id ,
					'retweet_msg' => null , 
					'retweet_id' => $retweet->post_id ,
					'tweet_id' => null
				];
				// for notification
				if($for_user != $user_id) 
				$data_notify['target']=$retweet->post_id;

			   } else {
				$data_tweet = [
					'post_id' => $post_id ,
					'retweet_msg' => null , 
					'retweet_id' => $tweet_id ,
					'tweet_id' => null
				];
				// for notification
				if($for_user != $user_id) 
				$data_notify['target']= $tweet_id;

			   } 
	
	
			} else {
	
				$data_tweet = [
					'post_id' => $post_id ,
					'retweet_msg' => null , 
					'tweet_id' => $tweet_id,
					'retweet_id' => null
				];
				// for notification
				if($for_user != $user_id) 
				$data_notify['target']= $tweet_id;
			}
	
	
	
	
		}
			User::create('retweets' , $data_tweet);

			  // for notification
		if($for_user != $user_id) 
		Tweet::create('notifications' , $data_notify);

		//  }
		
		
		// echo `<div class="tmp d-none">
        //      `+ Tweet::countRetweets($tweet_id) +`            
		// </div>` ;

	}
	if(isset($_POST['retweet']) && !empty($_POST['retweet'])){
		$tweet_id  = $_POST['retweet'];
		$get_id    = $_POST['user_id'];
		$flag = $_POST['isQoute'];
		$qoq = $_POST['qoq'];
		$retweet = Tweet::getRetweet($tweet_id);

		// for notification
		if(isset($retweet->user_id))
		$for_user = $retweet->user_id;
		else $for_user = Tweet::getTweet($tweet_id)->user_id;	

		if($for_user != $user_id) {
			$data_notify = [
              'notify_for' => $for_user ,
			  'notify_from' => $user_id ,
			  'type' => 'retweet' ,
			   'time' => date("Y-m-d H:i:s") ,
			   'count' => '0' , 
			   'status' => '0'
			];
				
		} 

		date_default_timezone_set("Africa/Cairo");

        $data = [
            'user_id' => $_SESSION['user_id'] , 
            'post_on' => date("Y-m-d H:i:s") ,
        ];
        // create function can handle with all tables and return last inserted id
		$post_id =   User::create('posts' , $data);

		 // if flag true then the retweeted post is quote tweet and the fk is retweet_id
		 if ($flag) {
				if(Tweet::isRetweet($tweet_id)) {
				$data_tweet = [
					'post_id' => $post_id ,
					'retweet_msg' => null , 
					'retweet_id' => $retweet->post_id,
					'tweet_id' => null
				];
				// for notification
				if($for_user != $user_id) 
				$data_notify['target']= $retweet->post_id;

			} else {
				$data_tweet = [
					'post_id' => $post_id ,
					'retweet_msg' => null , 
					'retweet_id' => $tweet_id,
					'tweet_id' => null
				];
					// for notification
					if($for_user != $user_id) 
					$data_notify['target']=  $tweet_id;

			}
		} else if ($qoq) {

			if(Tweet::isRetweet($tweet_id) && $retweet->retweet_msg == null) {
				$data_tweet = [
					'post_id' => $post_id ,
					'retweet_msg' => null , 
					'retweet_id' => $retweet->post_id,
					'tweet_id' => null
				];
					// for notification
					if($for_user != $user_id) 
					$data_notify['target']= $retweet->post_id;
			} else {
				$data_tweet = [
					'post_id' => $post_id ,
					'retweet_msg' => null , 
					'retweet_id' => $tweet_id,
					'tweet_id' => null
				];
				// for notification
				if($for_user != $user_id) 
				$data_notify['target']=  $tweet_id;
			}


		} else {
			
			if (Tweet::isRetweet($tweet_id)) {
				   
				$data_tweet = [
					'post_id' => $post_id ,
					'retweet_msg' => null , 
					'tweet_id' => $retweet->tweet_id,
					'retweet_id' => null
				];
				// for notification
				if($for_user != $user_id) 
				$data_notify['target']= $retweet->tweet_id;

			} else {

				$data_tweet = [
					'post_id' => $post_id ,
					'retweet_msg' => null , 
					'tweet_id' => $tweet_id,
					'retweet_id' => null
				];
				// for notification
				if($for_user != $user_id) 
				$data_notify['target']= $tweet_id;
			}
		}

		User::create('retweets' , $data_tweet);
		
	    // for notification
		if($for_user != $user_id) 
		    Tweet::create('notifications' , $data_notify);
       
		
		echo `<div class="tmp d-none">
             `+ Tweet::countRetweets($tweet_id) +`            
		</div>` ;


	}
	if(isset($_POST['unretweet']) && !empty($_POST['unretweet'])){

        $tweet_id  = $_POST['unretweet'];
		$user_id    = $_POST['user_id'];
        
		$retweet = Tweet::getRetweet($tweet_id);

		// for notification
		if(isset($retweet->tweet_id)) {
		$for_user = Tweet::getTweet($retweet->tweet_id)->user_id; 
		$target = $retweet->tweet_id;
	   } else {
		   $for_user = Tweet::getRetweet($retweet->retweet_id)->user_id;	
	       $target = $retweet->retweet_id;
	   }
	
	
		if($for_user != $user_id) {
			$data = [
              'notify_for' => $for_user ,
			  'notify_from' => $user_id ,
			  'target' => $target , 
			  'type' => "'retweet'" ,
			];
	        
			// var_dump($data);
			// die();

			Tweet::delete('notifications' , $data);
			
		} 
		Tweet::undoRetweet($user_id , $tweet_id );
		
		echo `<div class="tmp d-none">
             `+ Tweet::countRetweets($tweet_id) +`            
		</div>` ;

	}
	if(isset($_POST['option']) && !empty($_POST['option'])){ 
		$tweet_id  = $_POST['option'];
		$get_id    = $_POST['user_id'];
		$user_retweeted_it = $_POST['retweeted'];
		$retweet_sign = $_POST['sign'];
		$retweet_comment = $_POST['tmp'];
		$qoq = $_POST['qoq'];
		if(isset($_POST['status']))
		 $status = $_POST['status'];
		 else $status = false;

		$flaga = false;
		if($retweet_sign && $user_retweeted_it) {
		$retweeted_user = Tweet::getRetweet($tweet_id); 
		    	if ($retweeted_user->user_id != $user_id) {
                       $flaga = true;
		     	} 

			    
			        
	    }
        // $tweet_id_retweeted = Tweet::likedTweetRealId($tweet_id);
		
		// if ($user_retweeted_it && !$retweet_sign) {
		// 	$retweet = Tweet::getRetweet($tweet_id);
		// 	$user_retweeted_itt =Tweet::userRetweeetedIt($user_id , $retweet->id);
		// } else {
			
		// 	$user_retweeted_itt =$user_retweeted_it;
		// }
	    //   $user_retweeted_it = Tweet::checkRetweet($user_id , $tweet_id);
		
		// $retweet = Tweet::getRetweet($tweet_id);
		// $user_retweeted_itt = Tweet::userRetweeetedIt($user_id ,$tweet_id);
	?>

                    <div class="retweet-div">
							<a href="#" 
							class="<?=$user_retweeted_it ? 'retweeted-i' : 'retweet-i' ?>"
							data-user="<?php echo $user_id; ?>"
							data-tweet="<?php 
							if(($user_retweeted_it && !$retweet_sign) || $flaga) {
								if($flaga == false)
							       echo Tweet::retweetRealId($tweet_id ,$user_id);
						     	else {
									if($retweeted_user->tweet_id != null)
										echo Tweet::retweetRealId($retweeted_user->tweet_id ,$user_id);
									else echo Tweet::retweetRealId($retweeted_user->retweet_id ,$user_id);
								 } 
							} else echo $tweet_id;  ?>"
							 data-qoq="<?php echo $qoq; ?>"
							 data-status="<?php echo $status; ?>"
							 >  
								<li ><i class="fas fa-retweet icon"></i> 
								<span class="option-text"><?php if($user_retweeted_it) echo 'Undo';  ?>
								Retweet</span></li>
							</a>
							<a href="#"
							class="qoute"
							data-user="<?php echo $get_id; ?>"
							data-tweet="<?php 
							$retweet = Tweet::getRetweet($tweet_id);
							// if(Tweet::isRetweet($tweet_id) && $retweet->retweet_msg != null)
							// echo $retweet->tweet_id;
							// else
							 echo $tweet_id; ?>"> 
								 <li><i class="fas fa-pencil-alt icon"></i> 
								 <span class="option-text"> Quote Tweet</span></li>
							</a>
                    </div>

<?php	} 

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
				
				// when qoute 

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
	
	// Render the media assets and popup
	renderQuotePopupMediaAssets();
?>

<style>
/* Quote popup readable text color fix */
.retweet-popup .username-twitter{
    color:#536471 !important;
}
.retweet-popup p,
.retweet-popup strong{
    color:#0f1419 !important;
}
.retweet-popup .comment-post{
    color:#0f1419 !important;
}
.retweet-popup .comment-post p,
.retweet-popup .comment-post strong{
    color:#0f1419 !important;
}
</style>

<div class="retweet-popup">
<div class="wrap5">
	<div class="retweet-popup-body-wrap">
		<div class="retweet-popup-heading">
			<h3>Quote Tweet</h3>
			<span><button class="close-retweet-popup"><i class="fa fa-times" aria-hidden="true"></i></button></span>
		</div>
		<div class="retweet-popup-input">
			<div class="retweet-popup-input-inner">
				<input class="retweet-msg" type="text" placeholder="Add a comment..."/>
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
  
              <div style="flex: 1;">
                <p style="margin-bottom: 4px;">
                  <strong style="color: #0f1419;"><?php echo $user_tweet->name; ?></strong>
                  <span class="username-twitter">@<?php echo $user_tweet->username; ?></span>
                  <span class="username-twitter">·</span>
                  <span class="username-twitter"><?php echo $timeAgo; ?></span>
                </p>
                <p style="color: #0f1419; margin-bottom: 8px;">
				<?php
                  if ($retweet_comment || $qoq)
                  echo Tweet::getTweetLinks($qoute);
                  else echo Tweet::getTweetLinks($tweet->status); ?>
				</p>
				
				<?php if ($retweet_comment == false && $qoq == false) { ?>
                    <?php renderQuotePopupMediaGrid($tweet); ?>
			    <?php } else { ?>
					<div class="comment-post" style="margin-top: 8px; background-color: rgba(255,255,255,0.03); border: 1px solid #2f3336; border-radius: 16px; padding: 12px;">
						<div class="grid-tweet" style="padding: 0; gap: 8px;">
							<div>
								<img src="assets/images/users/<?php echo $user_inner_tweet->img; ?>" alt="" class="img-user-tweet" style="width: 32px; height: 32px;" />
							</div>
							<div style="flex: 1;">
								<p style="margin-bottom: 4px;">
									<strong style="color: #0f1419;"><?php echo $user_inner_tweet->name; ?></strong>
									<span class="username-twitter">@<?php echo $user_inner_tweet->username; ?></span>
									<span class="username-twitter">·</span>
									<span class="username-twitter"><?php echo $timeAgo_inner; ?></span>
								</p>
								<p style="color: #0f1419; margin-bottom: 8px;">
									<?php 
										if ($qoq)
											echo $inner_qoute;
										else  
											echo Tweet::getTweetLinks($tweet_inner->status); 
									?>
								</p>
								<?php if($qoq == false) {
									renderQuotePopupMediaGrid($tweet_inner);
								} ?>
							</div>
						</div>
					</div>
				<?php } ?>
			</div>
		</div>

		<div class="retweet-popup-footer"> 
			<div class="retweet-popup-footer-right">
				<button class="qoute-it" 
				data-tweet="<?php echo $tweet_id;?>"
				data-user="<?php echo $user_id;?>"
				data-tmp="<?php echo $retweet_comment; ?>" 
				data-qoq="<?php echo $qoq; ?>" 
				type="submit"><i class="fas fa-pencil-alt" aria-hidden="true"></i>Quote</button>
			</div>
		</div> 
	</div>
</div>

<!-- Retweet PopUp ends-->
<?php }?>