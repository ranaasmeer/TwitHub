<?php 

class Tweet extends User {
    
    protected static $pdo;
      
      public static function tweets($user_id) {
        $stmt = self::connect()->prepare("SELECT * from `posts`
        WHERE user_id = :user_id OR user_id IN (SELECT following_id from `follow` WHERE follower_id = :user_id)
        ORDER BY post_on DESC");
        $stmt->bindParam(":user_id" , $user_id , PDO::PARAM_STR);
        $stmt->execute();
       return $stmt->fetchAll(PDO::FETCH_OBJ);
      }
      public static function tweetsUser($user_id) {
        $stmt = self::connect()->prepare("SELECT * from `posts`
        WHERE user_id = :user_id
        ORDER BY post_on DESC");
        $stmt->bindParam(":user_id" , $user_id , PDO::PARAM_STR);
        $stmt->execute();
       return $stmt->fetchAll(PDO::FETCH_OBJ);
      }
      public static function likedTweets($user_id) {
        $stmt = self::connect()->prepare("SELECT * from `posts`
        WHERE id IN (SELECT post_id from `likes` WHERE user_id = :user_id)
        ORDER BY post_on DESC");
        $stmt->bindParam(":user_id" , $user_id , PDO::PARAM_STR);
        $stmt->execute();
       return $stmt->fetchAll(PDO::FETCH_OBJ);
      }
public static function mediaTweets($user_id) {
    $stmt = self::connect()->prepare("
        SELECT * FROM `posts`
        WHERE id IN (
            SELECT post_id FROM `tweets` 
            WHERE user_id = :user_id 
            AND (img IS NOT NULL OR video IS NOT NULL)
        )
        ORDER BY post_on DESC
    ");
    $stmt->bindParam(":user_id", $user_id, PDO::PARAM_STR);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_OBJ);
}

      public static function comments($tweet_id) {
        $stmt = self::connect()->prepare("SELECT * from `comments`
        WHERE post_id = :tweet_id
        ORDER BY time");
        $stmt->bindParam(":tweet_id" , $tweet_id , PDO::PARAM_STR);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_OBJ);
      }

      public static function replies($comment_id) {
        $stmt = self::connect()->prepare("SELECT * from `replies`
        WHERE comment_id = :comment_id
        ORDER BY time");
        $stmt->bindParam(":comment_id" , $comment_id , PDO::PARAM_STR);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_OBJ);
      }

      public static function isTweet($tweet_id){
            
        $stmt = self::connect()->prepare("SELECT * FROM `tweets` 
        WHERE `post_id` = :tweet_id");
        $stmt->bindParam(":tweet_id", $tweet_id, PDO::PARAM_INT);
        $stmt->execute(); 

        if ($stmt->rowCount() > 0) {
            return true;
        } else return false;
    }
    public static function isRetweet($tweet_id){
            
        $stmt = self::connect()->prepare("SELECT * FROM `retweets` 
        WHERE `post_id` = :tweet_id");
        $stmt->bindParam(":tweet_id", $tweet_id, PDO::PARAM_INT);
        $stmt->execute(); 

        if ($stmt->rowCount() > 0) {
            return true;
        } else return false;

    }

        public static function getTimeAgo($timestamp){
            date_default_timezone_set("Africa/Cairo");
               
            $time_ago        = strtotime($timestamp);
            $current_time = strtotime(date("Y-m-d H:i:s")); 
            // $current_time    = time();
            $time_difference = $current_time - $time_ago;
            $seconds         = $time_difference;
            
            $minutes = round($seconds / 60); // value 60 is seconds  
            $hours   = round($seconds / 3600); //value 3600 is 60 minutes * 60 sec  
            $days    = round($seconds / 86400); //86400 = 24 * 60 * 60;  
            $weeks   = round($seconds / 604800); // 7*24*60*60;  
            $months  = round($seconds / 2629440); //((365+365+365+365+366)/5/12)*24*60*60  
            $years   = round($seconds / 31553280); //(365+365+365+365+366)/5 * 24 * 60 * 60
                   
            if ($seconds <= 60){
        
            return "just now";
        
            } else if ($minutes <= 60){
        
            if ($minutes == 1){
        
                return "one minute ago";
        
            } else {
        
                return "$minutes minutes ago";
        
            }
        
            } else if ($hours <= 24){
        
            if ($hours == 1){
        
                return "an hour ago";
        
            } else {
        
                return "$hours hrs ago";
        
            }
        
            } else if ($days <= 7){
        
            if ($days == 1){
        
                return "yesterday";
        
            } else {
        
                return "$days days ago";
        
            }
        
            } else if ($weeks <= 4.3){
        
            if ($weeks == 1){
        
                return "a week ago";
        
            } else {
        
                return "$weeks weeks ago";
        
            }
        
            } else if ($months <= 12){
        
            if ($months == 1){
        
                return "a month ago";
        
            } else {
        
                return "$months months ago";
        
            }
        
            } else {
            
            if ($years == 1){
        
                return "one year ago";
        
            } else {
        
                return "$years years ago";
        
            }
            }
        }
        


        public static function getTrendByHash($hashtag){
            $stmt = self::connect()->prepare("SELECT * FROM `trends` 
            WHERE `hashtag` LIKE :hashtag LIMIT 5");
            $stmt->bindValue(":hashtag", $hashtag.'%');
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_OBJ);
        }
    
        public static function getMention($mension){
            $stmt = self::connect()->prepare("SELECT `id`,`username`,`name`,`img` FROM `users` 
            WHERE `username` LIKE :mension OR `name` LIKE :mension LIMIT 5");
            $stmt->bindValue("mension", $mension.'%');
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_OBJ);
    
        }
        public static function HashtagExist($hash){
            
            $stmt = self::connect()->prepare("SELECT * FROM `trends` 
            WHERE `hashtag` = '$hash' ");
            //  $stmt->bindParam(":hashtag", $hash, PDO::PARAM_INT);
            $stmt->execute(); 
    
            if ($stmt->rowCount() > 0) {
                return true;
            } else return false;
    
        }

public static function addTrend($text) {
    $pdo = self::connect();

    // Find all hashtags in the tweet
    preg_match_all("/#+([a-zA-Z0-9_]+)/i", $text, $matches);
    if (!empty($matches[1])) {
        foreach ($matches[1] as $hashtag) {
            $hashtag = '#' . strtolower($hashtag);
            
            // Calculate REAL count from existing tweets (not just increment)
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as real_count 
                FROM tweets t
                JOIN posts p ON t.post_id = p.id
                WHERE t.status LIKE :hashtag
            ");
            $stmt->execute(['hashtag' => '%' . $hashtag . '%']);
            $realCount = $stmt->fetch(PDO::FETCH_OBJ)->real_count;
            
            // Count recent tweets (last 24 hours)
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as recent_count 
                FROM tweets t
                JOIN posts p ON t.post_id = p.id
                WHERE t.status LIKE :hashtag 
                AND p.post_on >= NOW() - INTERVAL 1 DAY
            ");
            $stmt->execute(['hashtag' => '%' . $hashtag . '%']);
            $recentCount = $stmt->fetch(PDO::FETCH_OBJ)->recent_count;
            
            if ($realCount > 0) {
                // Update with REAL counts (not incremental)
                $stmt = $pdo->prepare("
                    INSERT INTO trends (hashtag, count, recent_count, created_on)
                    VALUES (:hashtag, :count, :recent_count, NOW())
                    ON DUPLICATE KEY UPDATE 
                    count = :count,
                    recent_count = :recent_count,
                    created_on = NOW()
                ");
                $stmt->execute([
                    'hashtag' => $hashtag,
                    'count' => $realCount,
                    'recent_count' => $recentCount
                ]);
            } else {
                // Delete trend if no tweets exist
                $pdo->prepare("DELETE FROM trends WHERE hashtag = :hashtag")->execute(['hashtag' => $hashtag]);
            }
        }
    }
}






       
        
        public static function getTweetLinks($tweet){
            $tweet = preg_replace("/(https?:\/\/)([\w]+.)([\w\.]+)/", "<a href='$0' target='_blink'>$0</a>", $tweet);
            $tweet = preg_replace(
    "/#([\w]+)/",
    "<a class='hash-tweet' href='hashtag.php?tag=$1' style='color:#1DA1F2;'>#$1</a>",
    $tweet
);
		
            $tweet = preg_replace("/@([\w]+)/", "<a class='hash-tweet' href=' ".BASE_URL."$1'>$0</a>", $tweet);
            return $tweet;		
        }
        public static function hashtagAndMentionTweet($tweet){
            $tweet = preg_replace("/(https?:\/\/)([\w]+.)([\w\.]+)/", "<a href='$0' target='_blink'>$0</a>", $tweet);
            $tweet = preg_replace("/#([\w]+)/", "<a class='hash-tweet' href='#'>$0</a>", $tweet);		
            $tweet = preg_replace("/@([\w]+)/", "<a class='hash-tweet' href='#'>$0</a>", $tweet);
            return $tweet;		
        }
        
        public static function countLikes($post_id) {
            $stmt = self::connect()->prepare("SELECT COUNT(post_id) as count FROM `likes`
            WHERE post_id = :post_id");
            $stmt->bindParam(":post_id" , $post_id , PDO::PARAM_STR);
            $stmt->execute();
            $count = $stmt->fetch(PDO::FETCH_OBJ);
            return $count->count;
        }
        public static function countTweets($user_id) {
            $stmt = self::connect()->prepare("SELECT COUNT(user_id) as count FROM `posts`
            WHERE user_id = :user_id");
            $stmt->bindParam(":user_id" , $user_id , PDO::PARAM_STR);
            $stmt->execute();
            $count = $stmt->fetch(PDO::FETCH_OBJ);
            return $count->count;
        }

        public static function countComments($post_id) {
            $stmt = self::connect()->prepare("SELECT COUNT(post_id) as count FROM `comments`
            WHERE post_id = :post_id");
            $stmt->bindParam(":post_id" , $post_id , PDO::PARAM_STR);
            $stmt->execute();
            $count = $stmt->fetch(PDO::FETCH_OBJ);
            return $count->count;
        }
        public static function countReplies($comment_id) {
            $stmt = self::connect()->prepare("SELECT COUNT(comment_id) as count FROM `replies`
            WHERE comment_id = :comment_id");
            $stmt->bindParam(":comment_id" , $comment_id , PDO::PARAM_STR);
            $stmt->execute();
            $count = $stmt->fetch(PDO::FETCH_OBJ);
            return $count->count;
        }

        public static function countRetweets($tweet_id) {
            $stmt = self::connect()->prepare("SELECT COUNT(*) as count FROM `retweets`
            WHERE (`tweet_id` = :tweet_id or `retweet_id` = :tweet_id)  and retweet_msg is null 
            GROUP BY tweet_id , retweet_id");
            $stmt->bindParam(":tweet_id" , $tweet_id , PDO::PARAM_STR);
            $stmt->execute();
            if ($stmt->rowCount() > 0) {
                $count = $stmt->fetch(PDO::FETCH_OBJ);
                return $count->count;
            } else return false;
            
        }

        public static function unLike($user_id, $tweet_id){
            
            $stmt = self::connect()->prepare("DELETE FROM `likes` 
            WHERE `user_id` = :user_id and `post_id` = :tweet_id");
            $stmt->bindParam(":user_id", $user_id, PDO::PARAM_INT);
            $stmt->bindParam(":tweet_id", $tweet_id, PDO::PARAM_INT);
            $stmt->execute(); 

            if ($stmt->rowCount() > 0) {
                return true;
            } else return false;

        }

        public static function userLikeIt( $user_id ,$tweet_id){
            
            $stmt = self::connect()->prepare("SELECT `post_id` , `user_id` FROM `likes` 
            WHERE `user_id` = :user_id and `post_id` = :tweet_id");
            $stmt->bindParam(":user_id", $user_id, PDO::PARAM_INT);
            $stmt->bindParam(":tweet_id", $tweet_id, PDO::PARAM_INT);
            $stmt->execute(); 

            if ($stmt->rowCount() > 0) {
                return true;
            } else return false;

        }
        public static function usersLiked($tweet_id){
            
            $stmt = self::connect()->prepare("SELECT `post_id` , `user_id` FROM `likes` 
            WHERE  `post_id` = :tweet_id");
            $stmt->bindParam(":tweet_id", $tweet_id, PDO::PARAM_INT);
            $stmt->execute(); 

            return $stmt->fetchAll(PDO::FETCH_OBJ);

        }

        public static function userRetweeetedIt($user_id ,$tweet_id){
            
            $stmt = self::connect()->prepare("SELECT `id` , `user_id` FROM `posts` JOIN `retweets`
            on id = post_id
            WHERE `user_id` = :user_id and (`tweet_id` = :tweet_id or `retweet_id` = :tweet_id)  and retweet_msg is NULL");
            $stmt->bindParam(":user_id", $user_id, PDO::PARAM_INT);
            $stmt->bindParam(":tweet_id", $tweet_id, PDO::PARAM_INT);
            $stmt->execute(); 

            if ($stmt->rowCount() > 0) {
                return true;
            } else return false;

        } 
        public static function usersRetweeeted($tweet_id){
            
            $stmt = self::connect()->prepare("SELECT `id` , `user_id` FROM `posts` JOIN `retweets`
            on id = post_id
            WHERE (`tweet_id` = :tweet_id or `retweet_id` = :tweet_id)  and retweet_msg is NULL");
            // $stmt->bindParam(":user_id", $user_id, PDO::PARAM_INT);
            $stmt->bindParam(":tweet_id", $tweet_id, PDO::PARAM_INT);
            $stmt->execute(); 

            return $stmt->fetchAll(PDO::FETCH_OBJ);

        }
        public static function checkRetweet($user_id ,$tweet_id){
            
            $stmt = self::connect()->prepare("SELECT `id` , `user_id` FROM `posts` JOIN `retweets`
            on id = post_id
            WHERE `user_id` = :user_id and `post_id` = :tweet_id  and retweet_msg is NULL");
            $stmt->bindParam(":user_id", $user_id, PDO::PARAM_INT);
            $stmt->bindParam(":tweet_id", $tweet_id, PDO::PARAM_INT);
            $stmt->execute(); 

            if ($stmt->rowCount() > 0) {
                return true;
            } else return false;

        }


        

        public static function undoRetweet($user_id , $tweet_id) {
            
            $stmt = self::connect()->prepare("DELETE FROM `posts` 
            WHERE `user_id` = :user_id and `id` = :tweet_id
            ");
            // and id not in (SELECT post_id from `retweets` WHERE retweet_msg is not null)
            $stmt->bindParam(":user_id", $user_id, PDO::PARAM_INT);
            $stmt->bindParam(":tweet_id", $tweet_id, PDO::PARAM_INT);
            $stmt->execute(); 

            if ($stmt->rowCount() > 0) {
                return true;
            } else return false;
        }

        public static function retweetRealId($tweet_id , $user_id) {
            $stmt = self::connect()->prepare("SELECT post_id FROM retweets JOIN posts
            on id = post_id
            WHERE (tweet_id = :tweet_id or  retweet_id = :tweet_id) and `user_id` = :user_id");
            $stmt->bindParam(":tweet_id" , $tweet_id , PDO::PARAM_STR);
            $stmt->bindParam(":user_id" , $user_id , PDO::PARAM_STR);
            $stmt->execute();
            $id = $stmt->fetch(PDO::FETCH_OBJ);
            return $id->post_id;
        }

       
        
        
        public static function likedTweetRealId($tweet_id) {
            $stmt = self::connect()->prepare("SELECT tweet_id FROM retweets 
            WHERE post_id = :tweet_id");
            $stmt->bindParam(":tweet_id" , $tweet_id , PDO::PARAM_STR);
            $stmt->execute();
            $id = $stmt->fetch(PDO::FETCH_OBJ);
            return $id->tweet_id;
        }
        
        // public static function getRealId($tweet_id) {
        //     $stmt = self::connect()->prepare("SELECT post_id FROM retweets 
        //     WHERE post_id = :tweet_id");
        //     $stmt->bindParam(":tweet_id" , $tweet_id , PDO::PARAM_STR);
        //     $stmt->execute();
        //     $id = $stmt->fetch(PDO::FETCH_OBJ);
        //     return $id->tweet_id;
        // }

        public static function getTweet($tweet_id){
            $stmt = self::connect()->prepare("SELECT * FROM `tweets` JOIN `posts` 
            on posts.id = tweets.post_id 
            WHERE `post_id` = :tweet_id");
            $stmt->bindParam(":tweet_id", $tweet_id, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetch(PDO::FETCH_OBJ);
        }
        public static function getComment($tweet_id){
            $stmt = self::connect()->prepare("SELECT * FROM `comments` 
            WHERE `id` = :tweet_id");
            $stmt->bindParam(":tweet_id", $tweet_id, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetch(PDO::FETCH_OBJ);
        }
        public static function getRetweet($tweet_id){
            $stmt = self::connect()->prepare("SELECT * FROM `retweets` JOIN `posts` 
            on id = post_id 
            WHERE `post_id` = :tweet_id");
            $stmt->bindParam(":tweet_id", $tweet_id, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetch(PDO::FETCH_OBJ);
        } 
        public static function getData($id) {
            $stmt = self::connect()->prepare("SELECT * from `posts` WHERE `id` = :id");
            $stmt->bindParam(":id" , $id , PDO::PARAM_STR);
            $stmt->execute();
        return $stmt->fetch(PDO::FETCH_OBJ);
     }  

     public static function includeHeader($title) {
        global $tweets;
        $tweets = $title;
        include 'includes/tweets.php';
    }

public static function getTrendingHashtagsBox($trends) {
    // Helper to detect hashtag category
    function detectHashtagType($hashtag) {
        $text = strtolower(ltrim($hashtag, '#'));

        if (preg_match('/\b(psl|cricket|sports|match|goal|team|fifa)\b/', $text)) {
            return 'Sports';
        } elseif (preg_match('/\b(election|vote|pm|minister|govt|politic|pti|pmln)\b/', $text)) {
            return 'Politics';
        } elseif (preg_match('/\b(movie|film|drama|music|song|actor|showbiz|bollywood|hollywood)\b/', $text)) {
            return 'Entertainment';
        } elseif (preg_match('/\b(tech|ai|app|startup|software|gadget|computer|it)\b/', $text)) {
            return 'Technology';
        } elseif (preg_match('/\b(earth|climate|weather|environment|nature|disaster)\b/', $text)) {
            return 'Environment';
        } elseif (preg_match('/\b(education|university|school|exam|student)\b/', $text)) {
            return 'Education';
        } elseif (preg_match('/\b(health|covid|vaccine|doctor|hospital|medicine)\b/', $text)) {
            return 'Health';
        } else {
            return 'General';
        }
    }

    // Start building output
    ob_start();
    ?>

    <!-- Trending Hashtags -->
    <div class="box-share mt-4">
        <p class="txt-share"><strong>Trending Hashtags (Last 24 Hours)</strong></p>
        <ul class="trending-list">
            <?php foreach ($trends as $trend): 
                $type = detectHashtagType($trend->hashtag); 
                $colorClass = strtolower($type);
            ?>
                <li>
                    <div class="trend-header">
                        <span class="trend-type <?php echo $colorClass; ?>">
                            <?php echo $type; ?>
                        </span>
                        <span class="trend-label">· Trending</span>
                    </div>
                    <a href="hashtag.php?tag=<?php echo htmlspecialchars(ltrim($trend->hashtag, '#')); ?>">
                        <?php echo htmlspecialchars($trend->hashtag); ?>
                    </a>

                    <!-- 📊 Updated counts display -->
                    <div class="trend-counts">
                        <span class="trend-recent">
                            🔥 <?php echo intval($trend->recent_count); ?> in last 24h
                        </span>
                        <span class="trend-total">
                            · 🌍 <?php echo intval($trend->count); ?> total
                        </span>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <style>
        .trending-list {
            list-style: none;
            margin: 0;
            padding: 0;
        }
        .trending-list li {
            margin-bottom: 15px;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }
        .trend-header {
            font-size: 13px;
            color: #555;
        }
        .trend-type {
            font-weight: bold;
            padding: 2px 6px;
            border-radius: 10px;
        }
        .trend-counts {
            font-size: 13px;
            color: #666;
            margin-top: 3px;
        }
        .trend-recent {
            color: #1d9bf0; /* Twitter blue for current activity */
            font-weight: 600;
        }
        .trend-total {
            color: #999;
            margin-left: 3px;
        }
    </style>

    <?php
    return ob_get_clean();
}

public static function trendingHashtags() {
    $pdo = self::connect();

    $stmt = $pdo->prepare("
        SELECT hashtag, count, recent_count, created_on
        FROM trends
        WHERE created_on >= NOW() - INTERVAL 1 DAY
        ORDER BY recent_count DESC, count DESC
        LIMIT 10
    ");
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_OBJ);
}











public static function displayTweetsByHashtag($tag, $user_id) {
    $pdo = self::connect(); // use self::connect()

    $sql = "
      SELECT 
        posts.id AS id,                -- ✅ Added this line
        tweets.*, 
        posts.user_id, 
        posts.post_on, 
        users.name, 
        users.username, 
        users.img AS user_img
      FROM tweets
      JOIN posts ON tweets.post_id = posts.id
      JOIN users ON posts.user_id = users.id
      WHERE tweets.status LIKE :tag
      ORDER BY posts.post_on DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':tag', "%#{$tag}%", PDO::PARAM_STR);
    $stmt->execute();

    $tweets = $stmt->fetchAll(PDO::FETCH_OBJ);

    if ($tweets) {
        foreach ($tweets as $tweet) {
            include 'includes/tweets.php';
        }
    } else {
        echo "<p style='padding:20px;'>No tweets found for this hashtag.</p>";
    }
}











public static function displayExplorePosts($user_id, $offset = 0) {
    $pdo = self::connect();
    $limit = 20;
    $offset = max(0, (int)$offset);
    $user_id = (int)$user_id;

    /*
     * Personalized For You feed
     * UPDATED:
     * - Normal tweets supported
     * - Retweets supported
     * - Quote tweets supported
     * - Multimedia retweets/quotes supported through source_post_id logic
     * Rendering is unchanged: includes/tweets.php still handles display.
     */

    $tableExists = function($table) use ($pdo) {
        try {
            $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
            $stmt->execute([$table]);
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            return false;
        }
    };

    $columnExists = function($table, $column) use ($pdo) {
        try {
            $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
            $stmt->execute([$column]);
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            return false;
        }
    };

    // ========== 1. READ USER BEHAVIOR ==========
    $preferenceStatuses = [];
    $preferredUserIds = [];
    $likedPhotoCount = 0;
    $likedVideoCount = 0;

    // Helper source expression used in behavior queries.
    $sourceExpr = "COALESCE(t.post_id, rt.tweet_id, rt.retweet_id, p.id)";

    // Posts user liked - now works with tweets, retweets and quotes
    try {
        $stmt = $pdo->prepare("
            SELECT 
                COALESCE(t.status, src_t.status, quoted_t.status, rt.retweet_msg) AS status,
                p.user_id,
                COALESCE(t.img, src_t.img, quoted_t.img) AS img,
                COALESCE(t.video, src_t.video, quoted_t.video) AS video
            FROM likes l
            INNER JOIN posts p ON p.id = l.post_id
            LEFT JOIN tweets t ON t.post_id = p.id
            LEFT JOIN retweets rt ON rt.post_id = p.id
            LEFT JOIN tweets src_t ON src_t.post_id = rt.tweet_id
            LEFT JOIN retweets quoted_rt ON quoted_rt.post_id = rt.retweet_id
            LEFT JOIN tweets quoted_t ON quoted_t.post_id = quoted_rt.tweet_id
            WHERE l.user_id = :user_id
              AND (t.post_id IS NOT NULL OR rt.post_id IS NOT NULL)
            ORDER BY p.post_on DESC
            LIMIT 120
        ");
        $stmt->execute([':user_id' => $user_id]);
        foreach ($stmt->fetchAll(PDO::FETCH_OBJ) as $row) {
            if (!empty($row->status)) $preferenceStatuses[] = $row->status;
            if (!empty($row->user_id)) $preferredUserIds[] = (int)$row->user_id;
            if (!empty($row->img)) $likedPhotoCount++;
            if (!empty($row->video)) $likedVideoCount++;
        }
    } catch (Exception $e) {}

    // Posts user commented on - now works with tweets, retweets and quotes
    if ($tableExists('comments')) {
        try {
            $stmt = $pdo->prepare("
                SELECT 
                    COALESCE(t.status, src_t.status, quoted_t.status, rt.retweet_msg) AS status,
                    p.user_id
                FROM comments c
                INNER JOIN posts p ON p.id = c.post_id
                LEFT JOIN tweets t ON t.post_id = p.id
                LEFT JOIN retweets rt ON rt.post_id = p.id
                LEFT JOIN tweets src_t ON src_t.post_id = rt.tweet_id
                LEFT JOIN retweets quoted_rt ON quoted_rt.post_id = rt.retweet_id
                LEFT JOIN tweets quoted_t ON quoted_t.post_id = quoted_rt.tweet_id
                WHERE c.user_id = :user_id
                  AND (t.post_id IS NOT NULL OR rt.post_id IS NOT NULL)
                ORDER BY c.time DESC
                LIMIT 80
            ");
            $stmt->execute([':user_id' => $user_id]);
            foreach ($stmt->fetchAll(PDO::FETCH_OBJ) as $row) {
                if (!empty($row->status)) $preferenceStatuses[] = $row->status;
                if (!empty($row->user_id)) $preferredUserIds[] = (int)$row->user_id;
            }
        } catch (Exception $e) {}
    }

    // Posts user retweeted/shared
    if ($tableExists('retweets')) {
        try {
            $stmt = $pdo->prepare("
                SELECT 
                    COALESCE(src_t.status, quoted_t.status, rt.retweet_msg) AS status,
                    COALESCE(src_p.user_id, quoted_p.user_id, p.user_id) AS user_id
                FROM retweets rt
                INNER JOIN posts p ON p.id = rt.post_id
                LEFT JOIN tweets src_t ON src_t.post_id = rt.tweet_id
                LEFT JOIN posts src_p ON src_p.id = src_t.post_id
                LEFT JOIN retweets quoted_rt ON quoted_rt.post_id = rt.retweet_id
                LEFT JOIN tweets quoted_t ON quoted_t.post_id = quoted_rt.tweet_id
                LEFT JOIN posts quoted_p ON quoted_p.id = quoted_t.post_id
                WHERE p.user_id = :user_id
                ORDER BY p.post_on DESC
                LIMIT 80
            ");
            $stmt->execute([':user_id' => $user_id]);
            foreach ($stmt->fetchAll(PDO::FETCH_OBJ) as $row) {
                if (!empty($row->status)) $preferenceStatuses[] = $row->status;
                if (!empty($row->user_id)) $preferredUserIds[] = (int)$row->user_id;
            }
        } catch (Exception $e) {}
    }

    // Posts from followed users - now includes their tweets, retweets and quotes
    try {
        $stmt = $pdo->prepare("
            SELECT 
                COALESCE(t.status, src_t.status, quoted_t.status, rt.retweet_msg) AS status,
                p.user_id,
                COALESCE(t.img, src_t.img, quoted_t.img) AS img,
                COALESCE(t.video, src_t.video, quoted_t.video) AS video
            FROM follow f
            INNER JOIN posts p ON p.user_id = f.following_id
            LEFT JOIN tweets t ON t.post_id = p.id
            LEFT JOIN retweets rt ON rt.post_id = p.id
            LEFT JOIN tweets src_t ON src_t.post_id = rt.tweet_id
            LEFT JOIN retweets quoted_rt ON quoted_rt.post_id = rt.retweet_id
            LEFT JOIN tweets quoted_t ON quoted_t.post_id = quoted_rt.tweet_id
            WHERE f.follower_id = :user_id
              AND (t.post_id IS NOT NULL OR rt.post_id IS NOT NULL)
            ORDER BY p.post_on DESC
            LIMIT 160
        ");
        $stmt->execute([':user_id' => $user_id]);
        foreach ($stmt->fetchAll(PDO::FETCH_OBJ) as $row) {
            if (!empty($row->status)) $preferenceStatuses[] = $row->status;
            if (!empty($row->user_id)) $preferredUserIds[] = (int)$row->user_id;
            if (!empty($row->img)) $likedPhotoCount++;
            if (!empty($row->video)) $likedVideoCount++;
        }
    } catch (Exception $e) {}

    // Direct followed users get an author boost too
    try {
        $stmt = $pdo->prepare("SELECT following_id FROM follow WHERE follower_id = :user_id LIMIT 200");
        $stmt->execute([':user_id' => $user_id]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $fid) {
            $preferredUserIds[] = (int)$fid;
        }
    } catch (Exception $e) {}

    // ========== 2. EXTRACT HASHTAGS + KEYWORDS ==========
    $hashtagScores = [];
    $keywordScores = [];

    $stopWords = [
        'the','and','for','you','are','this','that','with','from','have','your','will','what','when','where','there',
        'here','then','than','they','them','was','were','has','had','not','but','all','can','our','out','get','new',
        'hai','hain','tha','thi','the','aur','kya','kia','kay','ka','ki','ko','se','sy','mein','main','mujy','mujhe',
        'ke','to','is','in','on','a','an','of','or','as','it','be','by','at','if','so'
    ];

    foreach ($preferenceStatuses as $status) {
        $text = strtolower(strip_tags((string)$status));

        if (preg_match_all('/#([a-zA-Z0-9_]+)/', $text, $matches)) {
            foreach ($matches[1] as $tag) {
                $tag = trim($tag);
                if ($tag !== '' && strlen($tag) >= 2) {
                    $hashtagScores[$tag] = ($hashtagScores[$tag] ?? 0) + 1;
                }
            }
        }

        $plain = preg_replace('/https?:\/\/\S+/i', ' ', $text);
        $plain = preg_replace('/[^a-zA-Z0-9_#]+/', ' ', $plain);
        $words = preg_split('/\s+/', $plain);

        foreach ($words as $word) {
            $word = trim($word, "# \t\n\r\0\x0B");
            if (strlen($word) < 3 || is_numeric($word) || in_array($word, $stopWords, true)) {
                continue;
            }
            $keywordScores[$word] = ($keywordScores[$word] ?? 0) + 1;
        }
    }

    arsort($hashtagScores);
    arsort($keywordScores);

    $topHashtags = array_slice(array_keys($hashtagScores), 0, 12);
    $topKeywords = array_slice(array_keys($keywordScores), 0, 12);
    $preferredUserIds = array_values(array_unique(array_filter($preferredUserIds, function($id) use ($user_id) {
        return (int)$id > 0 && (int)$id !== $user_id;
    })));
    $preferredUserIds = array_slice($preferredUserIds, 0, 60);

    // ========== 3. BUILD LIVE SCORING ==========
    $scoreParts = [];

    if (!empty($preferredUserIds)) {
        $authorPlaceholders = [];
        foreach ($preferredUserIds as $i => $uid) {
            $param = ":pref_user_$i";
            $authorPlaceholders[] = $param;
        }
        $scoreParts[] = "CASE WHEN p.user_id IN (" . implode(',', $authorPlaceholders) . ") THEN 16 ELSE 0 END";
    }

    foreach ($topHashtags as $i => $tag) {
        $scoreParts[] = "CASE WHEN LOWER(COALESCE(t.status, src_t.status, quoted_t.status, rt.retweet_msg, '')) LIKE :hash_$i THEN " . max(6, 18 - $i) . " ELSE 0 END";
    }

    foreach ($topKeywords as $i => $word) {
        $scoreParts[] = "CASE WHEN LOWER(COALESCE(t.status, src_t.status, quoted_t.status, rt.retweet_msg, '')) LIKE :word_$i THEN " . max(3, 10 - (int)floor($i / 2)) . " ELSE 0 END";
    }

    $hasTweetMedia = $tableExists('tweet_media');
    $hasTweetVideos = $tableExists('tweet_videos');

    $imageCondition = "(
        (t.img IS NOT NULL AND t.img != '') OR
        (src_t.img IS NOT NULL AND src_t.img != '') OR
        (quoted_t.img IS NOT NULL AND quoted_t.img != '')
    )";
    if ($hasTweetMedia) {
        $imageCondition .= " OR EXISTS (SELECT 1 FROM tweet_media tm_pref WHERE tm_pref.tweet_id = $sourceExpr AND (tm_pref.media_type = 'image' OR tm_pref.media_type IS NULL OR tm_pref.media_type = ''))";
    }

    $videoCondition = "(
        (t.video IS NOT NULL AND t.video != '') OR
        (src_t.video IS NOT NULL AND src_t.video != '') OR
        (quoted_t.video IS NOT NULL AND quoted_t.video != '')
    )";
    if ($hasTweetMedia) {
        $videoCondition .= " OR EXISTS (SELECT 1 FROM tweet_media tmv_pref WHERE tmv_pref.tweet_id = $sourceExpr AND tmv_pref.media_type = 'video')";
    }
    if ($hasTweetVideos) {
        $videoCondition .= " OR EXISTS (SELECT 1 FROM tweet_videos tv_pref WHERE tv_pref.tweet_id = $sourceExpr)";
    }

    if ($likedPhotoCount > $likedVideoCount && $likedPhotoCount > 0) {
        $scoreParts[] = "CASE WHEN ($imageCondition) THEN 5 ELSE 0 END";
    } elseif ($likedVideoCount > $likedPhotoCount && $likedVideoCount > 0) {
        $scoreParts[] = "CASE WHEN ($videoCondition) THEN 5 ELSE 0 END";
    }

    $scoreParts[] = "CASE WHEN p.user_id IN (SELECT following_id FROM follow WHERE follower_id = :follow_score_user) THEN 8 ELSE 0 END";

    $scoreParts[] = "CASE
        WHEN p.post_on >= NOW() - INTERVAL 1 DAY THEN 14
        WHEN p.post_on >= NOW() - INTERVAL 7 DAY THEN 9
        WHEN p.post_on >= NOW() - INTERVAL 30 DAY THEN 4
        ELSE 0
    END";

    $likesExpr = "(SELECT COUNT(*) FROM likes l WHERE l.post_id = p.id)";
    $commentsExpr = "(SELECT COUNT(*) FROM comments c WHERE c.post_id = p.id)";
    $retweetsExpr = "(SELECT COUNT(*) FROM retweets r WHERE (r.tweet_id = p.id OR r.retweet_id = p.id))";

    $scoreParts[] = "LEAST((($likesExpr) * 2) + (($commentsExpr) * 3) + (($retweetsExpr) * 4), 35)";
    $scoreParts[] = "((CRC32(CONCAT(p.id, '-', DATE_FORMAT(NOW(), '%Y%m%d%H'), '-', :refresh_user)) % 100) / 100)";

    $relevanceScore = implode(" + ", $scoreParts);

    // ========== 4. MAIN QUERY ==========
    $where = [
        "p.user_id != :current_user_id",
        "(t.post_id IS NOT NULL OR rt.post_id IS NOT NULL)"
    ];

    if ($columnExists('users', 'is_blocked')) {
        $where[] = "(u.is_blocked IS NULL OR u.is_blocked = 0)";
    }

    $whereSql = "WHERE " . implode(" AND ", $where);

    $sql = "
        SELECT 
            p.id AS id,
            p.user_id,
            p.post_on,

            t.post_id AS tweet_post_id,
            t.status,
            t.img,
            t.video,

            rt.post_id AS retweet_post_id,
            rt.retweet_msg,
            rt.tweet_id,
            rt.retweet_id,

            u.name,
            u.username,
            u.img AS user_img,

            $sourceExpr AS source_post_id,

            CASE 
                WHEN t.post_id IS NOT NULL THEN 'tweet'
                WHEN rt.post_id IS NOT NULL AND rt.retweet_msg IS NULL THEN 'retweet'
                WHEN rt.post_id IS NOT NULL AND rt.retweet_msg IS NOT NULL THEN 'quote'
                ELSE 'unknown'
            END AS post_type,

            ($relevanceScore) AS relevance_score

        FROM posts p
        LEFT JOIN tweets t ON t.post_id = p.id
        LEFT JOIN retweets rt ON rt.post_id = p.id
        LEFT JOIN tweets src_t ON src_t.post_id = rt.tweet_id
        LEFT JOIN retweets quoted_rt ON quoted_rt.post_id = rt.retweet_id
        LEFT JOIN tweets quoted_t ON quoted_t.post_id = quoted_rt.tweet_id
        INNER JOIN users u ON p.user_id = u.id

        $whereSql
        ORDER BY relevance_score DESC, p.post_on DESC, p.id DESC
        LIMIT :limit OFFSET :offset
    ";

    try {
        $stmt = $pdo->prepare($sql);

        $stmt->bindValue(':current_user_id', $user_id, PDO::PARAM_INT);
        $stmt->bindValue(':follow_score_user', $user_id, PDO::PARAM_INT);
        $stmt->bindValue(':refresh_user', $user_id, PDO::PARAM_INT);

        foreach ($preferredUserIds as $i => $uid) {
            $stmt->bindValue(":pref_user_$i", (int)$uid, PDO::PARAM_INT);
        }

        foreach ($topHashtags as $i => $tag) {
            $stmt->bindValue(":hash_$i", '%#' . strtolower($tag) . '%', PDO::PARAM_STR);
        }

        foreach ($topKeywords as $i => $word) {
            $stmt->bindValue(":word_$i", '%' . strtolower($word) . '%', PDO::PARAM_STR);
        }

        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $tweets = $stmt->fetchAll(PDO::FETCH_OBJ);
    } catch (Exception $e) {
        $tweets = [];
    }

    // ========== 5. FALLBACK FOR NEW USERS ==========
    if (empty($tweets) && $offset === 0) {
        try {
            $fallbackSql = "
                SELECT 
                    p.id AS id,
                    p.user_id,
                    p.post_on,

                    t.post_id AS tweet_post_id,
                    t.status,
                    t.img,
                    t.video,

                    rt.post_id AS retweet_post_id,
                    rt.retweet_msg,
                    rt.tweet_id,
                    rt.retweet_id,

                    u.name,
                    u.username,
                    u.img AS user_img,

                    $sourceExpr AS source_post_id,

                    CASE 
                        WHEN t.post_id IS NOT NULL THEN 'tweet'
                        WHEN rt.post_id IS NOT NULL AND rt.retweet_msg IS NULL THEN 'retweet'
                        WHEN rt.post_id IS NOT NULL AND rt.retweet_msg IS NOT NULL THEN 'quote'
                        ELSE 'unknown'
                    END AS post_type,

                    ((SELECT COUNT(*) FROM likes l WHERE l.post_id = p.id) +
                     (SELECT COUNT(*) FROM comments c WHERE c.post_id = p.id) +
                     (SELECT COUNT(*) FROM retweets r WHERE (r.tweet_id = p.id OR r.retweet_id = p.id))) AS relevance_score
                FROM posts p
                LEFT JOIN tweets t ON t.post_id = p.id
                LEFT JOIN retweets rt ON rt.post_id = p.id
                LEFT JOIN tweets src_t ON src_t.post_id = rt.tweet_id
                LEFT JOIN retweets quoted_rt ON quoted_rt.post_id = rt.retweet_id
                LEFT JOIN tweets quoted_t ON quoted_t.post_id = quoted_rt.tweet_id
                INNER JOIN users u ON p.user_id = u.id
                WHERE p.user_id != :user_id
                  AND (t.post_id IS NOT NULL OR rt.post_id IS NOT NULL)
                ORDER BY (p.post_on >= NOW() - INTERVAL 7 DAY) DESC, relevance_score DESC, p.post_on DESC, p.id DESC
                LIMIT :limit OFFSET :offset
            ";
            $stmt = $pdo->prepare($fallbackSql);
            $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $tweets = $stmt->fetchAll(PDO::FETCH_OBJ);
        } catch (Exception $e) {
            $tweets = [];
        }
    }

    // ========== 6. DISPLAY ==========
    if (!empty($tweets)) {
        foreach ($tweets as $tweet) {
            include dirname(__DIR__, 2) . '/includes/tweets.php';
        }
    }
}


public static function displayHomePosts($user_id, $offset = 0) {
    $pdo = self::connect();

    $sql = "
        SELECT 
            posts.id AS id,
            posts.user_id,
            posts.post_on,

            tweets.post_id AS tweet_post_id,
            tweets.status,
            tweets.img,
            tweets.video,

            retweets.post_id AS retweet_post_id,
            retweets.retweet_msg,
            retweets.tweet_id,
            retweets.retweet_id,

            users.name,
            users.username,
            users.img AS user_img,

            CASE 
                WHEN tweets.post_id IS NOT NULL THEN 'tweet'
                WHEN retweets.post_id IS NOT NULL AND retweets.retweet_msg IS NULL THEN 'retweet'
                WHEN retweets.post_id IS NOT NULL AND retweets.retweet_msg IS NOT NULL THEN 'quote'
            END AS post_type

        FROM posts
        LEFT JOIN tweets 
            ON tweets.post_id = posts.id
        LEFT JOIN retweets 
            ON retweets.post_id = posts.id
        JOIN users 
            ON posts.user_id = users.id

        WHERE 
            (
                posts.user_id = :user_id 
                OR posts.user_id IN (
                    SELECT following_id 
                    FROM follow 
                    WHERE follower_id = :user_id
                )
            )
            AND (
                tweets.post_id IS NOT NULL 
                OR retweets.post_id IS NOT NULL
            )

        ORDER BY posts.post_on DESC
        LIMIT 20 OFFSET :offset
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
    $stmt->execute();

    $tweets = $stmt->fetchAll(PDO::FETCH_OBJ);

    if ($tweets) {
        foreach ($tweets as $tweet) {
            include dirname(__DIR__, 2) . '/includes/tweets.php';
        }
    }
}






// ✅ Toggle Bookmark (Add/Remove)
public static function toggleBookmark($user_id, $tweet_id) {
    // ✅ Always use existing PDO via Connect class
    $pdo = Connect::connect();

    // Check if tweet is already bookmarked
    $stmt = $pdo->prepare("SELECT id FROM bookmarks WHERE user_id = :user_id AND tweet_id = :tweet_id");
    $stmt->execute(['user_id' => $user_id, 'tweet_id' => $tweet_id]);

    if ($stmt->rowCount() > 0) {
        // 🔹 Remove bookmark
        $delete = $pdo->prepare("DELETE FROM bookmarks WHERE user_id = :user_id AND tweet_id = :tweet_id");
        $delete->execute(['user_id' => $user_id, 'tweet_id' => $tweet_id]);
        return "removed";
    } else {
        // 🔹 Add bookmark
        $insert = $pdo->prepare("INSERT INTO bookmarks (user_id, tweet_id, created_at) VALUES (:user_id, :tweet_id, NOW())");
        $insert->execute(['user_id' => $user_id, 'tweet_id' => $tweet_id]);
        return "added";
    }
}


// ✅ Get All Bookmarked Tweets (for bookmarks.php)
public static function getBookmarks($user_id) {
    $pdo = Connect::connect();

    $stmt = $pdo->prepare("
        SELECT 
            p.id AS id,                 -- ✅ renamed for compatibility with tweets.php
            t.status,
            t.img,
            t.video,
            u.id AS user_id,
            u.name,
            u.username,
            u.img AS user_img,
            p.post_on
        FROM bookmarks b
        INNER JOIN tweets t ON b.tweet_id = t.post_id
        INNER JOIN posts p ON t.post_id = p.id
        INNER JOIN users u ON p.user_id = u.id
        WHERE b.user_id = :user_id
        ORDER BY b.created_at DESC
    ");
    $stmt->execute(['user_id' => $user_id]);
    return $stmt->fetchAll(PDO::FETCH_OBJ);
}




public static function getReply($reply_id) {
    $pdo = Connect::connect();

    $stmt = $pdo->prepare("SELECT * FROM replies WHERE id = :id");
    $stmt->bindParam(":id", $reply_id, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetch(PDO::FETCH_OBJ);
}




// ========== ADD THESE METHODS FOR DELETE FUNCTIONALITY ==========

// 1. Get all replies for a specific comment (for cascade deletion)
public static function getRepliesByCommentId($comment_id) {
    $pdo = self::connect();
    $stmt = $pdo->prepare("SELECT * FROM replies WHERE comment_id = :comment_id");
    $stmt->bindParam(":comment_id", $comment_id, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_OBJ);
}

// 2. Get child replies for a specific reply (for nested replies)
public static function getRepliesByParentId($parent_id) {
    $pdo = self::connect();
    $stmt = $pdo->prepare("SELECT * FROM replies WHERE parent_id = :parent_id");
    $stmt->bindParam(":parent_id", $parent_id, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_OBJ);
}

// 3. Generic delete method for any table
public static function deleteRecord($table, $id) {
    $pdo = self::connect();
    $stmt = $pdo->prepare("DELETE FROM $table WHERE id = :id");
    $stmt->bindParam(":id", $id, PDO::PARAM_INT);
    return $stmt->execute();
}

 



public static function getTweetMedia($tweet_id) {
    $pdo = self::connect();
    $stmt = $pdo->prepare("SELECT * FROM tweet_media WHERE tweet_id = :tweet_id ORDER BY media_order ASC");
    $stmt->execute(['tweet_id' => $tweet_id]);
    return $stmt->fetchAll(PDO::FETCH_OBJ);
}





// Get multiple videos for a tweet
public static function getTweetVideos($tweet_id) {
    $pdo = self::connect();
    $stmt = $pdo->prepare("SELECT * FROM tweet_videos WHERE tweet_id = :tweet_id ORDER BY video_order ASC");
    $stmt->execute(['tweet_id' => $tweet_id]);
    return $stmt->fetchAll(PDO::FETCH_OBJ);
}




/**
 * Find the original source post ID that contains the media
 * Follows retweet/quote chain to get the original tweet
 * @param int $post_id The current post ID
 * @return int The source post ID where media is stored
 */
public static function getSourcePostId($post_id) {
    $pdo = self::connect();
    
    // Check if this is a retweet or quote
    $stmt = $pdo->prepare("SELECT tweet_id, retweet_id FROM retweets WHERE post_id = :post_id");
    $stmt->execute(['post_id' => $post_id]);
    $retweet = $stmt->fetch(PDO::FETCH_OBJ);

    if ($retweet) {
        // If it's a quote/retweet of a quote, follow the chain
        if ($retweet->retweet_id != null) {
            return self::getSourcePostId($retweet->retweet_id);
        }
        // If it's a retweet of a normal tweet
        if ($retweet->tweet_id != null) {
            return $retweet->tweet_id;
        }
    }
    
    // If it's a real tweet, return its own ID
    return $post_id;
}


}
