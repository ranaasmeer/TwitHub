<?php
  require_once 'core/init.php';

  if (!User::checkLogIn()) {
      header("Location: index.php");
      exit;
  }

  $pdo = Connect::connect();
  $viewer_id = $_SESSION['user_id'];
  
  // Get current user info for profile button
  $stmt = $pdo->prepare("SELECT username, name, img FROM users WHERE id = ?");
  $stmt->execute([$viewer_id]);
  $currentUser = $stmt->fetch(PDO::FETCH_OBJ);

  // ==================== CHECK IF VIEWING SPECIFIC USER'S REELS FROM PROFILE ====================
  $specificUserId = isset($_GET['user']) ? (int)$_GET['user'] : 0;
  $specificReelId = isset($_GET['reel']) ? (int)$_GET['reel'] : 0;
  $sourceTab = isset($_GET['tab']) ? $_GET['tab'] : 'reels';

  // ==================== UPDATE USER PREFERENCES BASED ON INTERACTIONS ====================
  
  // Function to update hashtag interests when user likes a reel
  function updateHashtagInterests($pdo, $user_id, $caption) {
      preg_match_all('/#([a-zA-Z0-9_]+)/', $caption, $matches);
      $hashtags = $matches[1];
      
      foreach ($hashtags as $hashtag) {
          $stmt = $pdo->prepare("
              INSERT INTO user_hashtag_interests (user_id, hashtag, interaction_count, last_interaction) 
              VALUES (?, ?, 1, NOW()) 
              ON DUPLICATE KEY UPDATE 
              interaction_count = interaction_count + 1, 
              last_interaction = NOW()
          ");
          $stmt->execute([$user_id, $hashtag]);
      }
  }
  
  // Function to DECREASE hashtag interests (when unliking/unsaving)
  function decreaseHashtagInterests($pdo, $user_id, $caption, $decrement = 1) {
      preg_match_all('/#([a-zA-Z0-9_]+)/', $caption, $matches);
      $hashtags = $matches[1];
      
      foreach ($hashtags as $hashtag) {
          $stmt = $pdo->prepare("
              UPDATE user_hashtag_interests 
              SET interaction_count = GREATEST(interaction_count - ?, 0),
                  last_interaction = NOW()
              WHERE user_id = ? AND hashtag = ?
          ");
          $stmt->execute([$decrement, $user_id, $hashtag]);
          
          // Delete if zero
          $stmt = $pdo->prepare("
              DELETE FROM user_hashtag_interests 
              WHERE user_id = ? AND hashtag = ? AND interaction_count <= 0
          ");
          $stmt->execute([$user_id, $hashtag]);
      }
  }
  
  // Function to DECREASE category preference (when unliking/unsaving)
  function decreaseCategoryPreference($pdo, $user_id, $category, $decrement = 1) {
      $stmt = $pdo->prepare("
          UPDATE user_category_preferences 
          SET score = GREATEST(score - ?, 0)
          WHERE user_id = ? AND category = ?
      ");
      $stmt->execute([$decrement, $user_id, $category]);
      
      // Delete if zero
      $stmt = $pdo->prepare("
          DELETE FROM user_category_preferences 
          WHERE user_id = ? AND category = ? AND score <= 0
      ");
      $stmt->execute([$user_id, $category]);
  }
  
  // Function to update category preferences (INCREASE)
  function updateCategoryPreference($pdo, $user_id, $category, $increment = 1) {
      $stmt = $pdo->prepare("
          INSERT INTO user_category_preferences (user_id, category, score) 
          VALUES (?, ?, ?) 
          ON DUPLICATE KEY UPDATE 
          score = score + ?
      ");
      $stmt->execute([$user_id, $category, $increment, $increment]);
  }

  // -------------------- AJAX Handlers --------------------
  
  // Handle reel like - UPDATE to track preferences (WITH INCREASE AND DECREASE)
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['like_reel'])) {
      header('Content-Type: application/json; charset=utf-8');
      $reel_id = (int)($_POST['reel_id'] ?? 0);
      
      if ($reel_id > 0) {
          try {
              // Get reel caption for hashtag tracking
              $stmt = $pdo->prepare("SELECT caption, media_type FROM reels WHERE id = ?");
              $stmt->execute([$reel_id]);
              $reelData = $stmt->fetch(PDO::FETCH_OBJ);
              
              
              $stmt = $pdo->prepare("SELECT id FROM reel_likes WHERE reel_id = ? AND user_id = ?");
              $stmt->execute([$reel_id, $viewer_id]);
              $existing = $stmt->fetch();
              
              if ($existing) {
                  // UNLIKE - Remove like and DECREMENT preferences
                  $stmt = $pdo->prepare("DELETE FROM reel_likes WHERE reel_id = ? AND user_id = ?");
                  $stmt->execute([$reel_id, $viewer_id]);
                  $liked = false;
                  
                  // Decrease hashtag interests
                  if ($reelData && !empty($reelData->caption)) {
                      decreaseHashtagInterests($pdo, $viewer_id, $reelData->caption);
                  }
                  // Decrease category preference
                  if ($reelData) {
                      decreaseCategoryPreference($pdo, $viewer_id, $reelData->media_type, 2);
                  }
              } else {
                  // LIKE - Add like and INCREASE preferences
                  $stmt = $pdo->prepare("INSERT INTO reel_likes (reel_id, user_id, created_at) VALUES (?, ?, NOW())");
                  $stmt->execute([$reel_id, $viewer_id]);
                  $liked = true;
                  
                  // Update hashtag interests when user likes a reel
                  if ($reelData && !empty($reelData->caption)) {
                      updateHashtagInterests($pdo, $viewer_id, $reelData->caption);
                  }
                  // Update category preference based on media type
                  if ($reelData) {
                      updateCategoryPreference($pdo, $viewer_id, $reelData->media_type, 2);
                  }
              }
              
              $stmt = $pdo->prepare("SELECT COUNT(*) FROM reel_likes WHERE reel_id = ?");
              $stmt->execute([$reel_id]);
              $likeCount = $stmt->fetchColumn();
              
              $stmt = $pdo->prepare("UPDATE reels SET likes_count = ? WHERE id = ?");
              $stmt->execute([$likeCount, $reel_id]);
              
              echo json_encode(['success' => true, 'liked' => $liked, 'like_count' => $likeCount]);
          } catch (Exception $e) {
              echo json_encode(['success' => false, 'error' => $e->getMessage()]);
          }
      } else {
          echo json_encode(['success' => false, 'error' => 'Invalid reel']);
      }
      exit;
  }

  // ==================== SAVE REEL AJAX HANDLER (WITH INCREASE AND DECREASE) ====================
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_reel'])) {
      header('Content-Type: application/json; charset=utf-8');
      $reel_id = (int)($_POST['reel_id'] ?? 0);
      
      if ($reel_id > 0) {
          try {
              // Get reel caption for hashtag tracking
              $stmt = $pdo->prepare("SELECT caption FROM reels WHERE id = ?");
              $stmt->execute([$reel_id]);
              $reelData = $stmt->fetch(PDO::FETCH_OBJ);
              
              
              
              
              $stmt = $pdo->prepare("SELECT id FROM reels_saves WHERE reel_id = ? AND user_id = ?");
              $stmt->execute([$reel_id, $viewer_id]);
              $existing = $stmt->fetch();
              
              if ($existing) {
                  // UNSAVE - Remove save and DECREMENT preferences
                  $stmt = $pdo->prepare("DELETE FROM reels_saves WHERE reel_id = ? AND user_id = ?");
                  $stmt->execute([$reel_id, $viewer_id]);
                  $saved = false;
                  
                  // Decrease hashtag interests (weighted less than likes)
                  if ($reelData && !empty($reelData->caption)) {
                      decreaseHashtagInterests($pdo, $viewer_id, $reelData->caption, 0.5);
                  }
              } else {
                  // SAVE - Add save and INCREASE preferences
                  $stmt = $pdo->prepare("INSERT INTO reels_saves (reel_id, user_id, created_at) VALUES (?, ?, NOW())");
                  $stmt->execute([$reel_id, $viewer_id]);
                  $saved = true;
                  
                  // Update hashtag interests when user saves a reel (weighted slightly less than like)
                  if ($reelData && !empty($reelData->caption)) {
                      updateHashtagInterests($pdo, $viewer_id, $reelData->caption);
                  }
              }
              
              $stmt = $pdo->prepare("SELECT COUNT(*) FROM reels_saves WHERE reel_id = ?");
              $stmt->execute([$reel_id]);
              $saveCount = $stmt->fetchColumn();
              
              $stmt = $pdo->prepare("UPDATE reels SET saves_count = ? WHERE id = ?");
              $stmt->execute([$saveCount, $reel_id]);
              
              echo json_encode(['success' => true, 'saved' => $saved, 'save_count' => $saveCount]);
          } catch (Exception $e) {
              echo json_encode(['success' => false, 'error' => $e->getMessage()]);
          }
      } else {
          echo json_encode(['success' => false, 'error' => 'Invalid reel']);
      }
      exit;
  }

  // Handle reel view tracking
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['track_reel_view'])) {
      header('Content-Type: application/json; charset=utf-8');
      $reel_id = (int)($_POST['reel_id'] ?? 0);
      
      if ($reel_id > 0) {
          try {
              
              
              
              $stmt = $pdo->prepare("SELECT id FROM reel_views WHERE user_id = ? AND reel_id = ?");
              $stmt->execute([$viewer_id, $reel_id]);
              $existing = $stmt->fetch();
              
              if (!$existing) {
                  $stmt = $pdo->prepare("INSERT INTO reel_views (user_id, reel_id, viewed_at) VALUES (?, ?, NOW())");
                  $stmt->execute([$viewer_id, $reel_id]);
                  
                  $stmt2 = $pdo->prepare("UPDATE reels SET views_count = views_count + 1 WHERE id = ?");
                  $stmt2->execute([$reel_id]);
              }
              
          } catch (Exception $e) {
              error_log("View tracking error: " . $e->getMessage());
          }
      }
      echo json_encode(['success' => true]);
      exit;
  }
  // Handle user search for sidebar
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['search_users'])) {
    header('Content-Type: application/json; charset=utf-8');
    $query = trim($_POST['query'] ?? '');
    
    if (!empty($query)) {
        $stmt = $pdo->prepare("
            SELECT id, username, name, img, is_verified 
            FROM users 
            WHERE name LIKE ? OR username LIKE ?
            LIMIT 20
        ");
        $stmt->execute(['%' . $query . '%', '%' . $query . '%']);
        $users = $stmt->fetchAll(PDO::FETCH_OBJ);
        
        echo json_encode(['users' => $users, 'success' => true]);
    } else {
        echo json_encode(['users' => [], 'success' => true]);
    }
    exit;
}

  // Handle follow/unfollow AJAX
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['follow_action'])) {
      header('Content-Type: application/json; charset=utf-8');
      $action = $_POST['follow_action'];
      $target_user = (int) ($_POST['user_id'] ?? 0);
      if ($target_user <= 0 || $target_user === $viewer_id) {
          echo json_encode(['ok' => false, 'msg' => 'Invalid user']);
          exit;
      }

      try {
          if ($action === 'follow') {
              $stmt = $pdo->prepare("SELECT COUNT(*) FROM follow WHERE follower_id = :f AND following_id = :t");
              $stmt->execute(['f' => $viewer_id, 't' => $target_user]);
              if ($stmt->fetchColumn() == 0) {
                  $ins = $pdo->prepare("INSERT INTO follow (follower_id, following_id, time) VALUES (:f, :t, NOW())");
                  $ins->execute(['f' => $viewer_id, 't' => $target_user]);
              }
              echo json_encode(['ok' => true, 'following' => true]);
              exit;
          } elseif ($action === 'unfollow') {
              $del = $pdo->prepare("DELETE FROM follow WHERE follower_id = :f AND following_id = :t");
              $del->execute(['f' => $viewer_id, 't' => $target_user]);
              echo json_encode(['ok' => true, 'following' => false]);
              exit;
          }
      } catch (PDOException $e) {
          echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
          exit;
      }
  }

  // Handle load more reels - UPDATED with feed mode support
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['load_more'])) {
      header('Content-Type: application/json; charset=utf-8');
      $exclude_ids = isset($_POST['exclude_ids']) ? json_decode($_POST['exclude_ids'], true) : [];
      $filter = isset($_POST['filter']) ? $_POST['filter'] : 'all';
      $search = isset($_POST['search']) ? trim($_POST['search']) : '';
      $feedMode = isset($_POST['feed_mode']) ? $_POST['feed_mode'] : 'personalized';
      
      $reelsData = getPersonalizedReels($pdo, $viewer_id, $exclude_ids, $filter, $search, 5, $feedMode);
      
      if (empty($reelsData['reels'])) {
          echo json_encode(['reels' => [], 'end' => true]);
          exit;
      }
      
      $html = renderReelsHTML($reelsData['reels'], $viewer_id, $reelsData['followMap']);
      echo json_encode(['reels' => $html, 'end' => false, 'new_ids' => $reelsData['new_ids']]);
      exit;
  }

  // Handle refresh reels - UPDATED with feed mode support
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['refresh_reels'])) {
      header('Content-Type: application/json; charset=utf-8');
      $filter = isset($_POST['filter']) ? $_POST['filter'] : 'all';
      $search = isset($_POST['search']) ? trim($_POST['search']) : '';
      $feedMode = isset($_POST['feed_mode']) ? $_POST['feed_mode'] : 'personalized';
      
      $reelsData = getPersonalizedReels($pdo, $viewer_id, [], $filter, $search, 10, $feedMode);
      
      if (empty($reelsData['reels'])) {
          echo json_encode(['reels' => '<div class="empty-state"><i class="fas fa-film"></i><h4>No Reels Found</h4><p>Try different filters or search hashtags</p></div>', 'new_ids' => []]);
          exit;
      }
      
      $html = renderReelsHTML($reelsData['reels'], $viewer_id, $reelsData['followMap']);
      echo json_encode(['reels' => $html, 'new_ids' => $reelsData['new_ids']]);
      exit;
  }

  // ==================== COMMENT SYSTEM AJAX HANDLERS ====================
  
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['get_comments'])) {
      header('Content-Type: application/json; charset=utf-8');
      $reel_id = (int)($_POST['reel_id'] ?? 0);
      
      if ($reel_id > 0) {
          try {
              $html = getReelCommentsHTML($pdo, $reel_id, $viewer_id);
              echo json_encode(['html' => $html, 'success' => true]);
          } catch (Exception $e) {
              echo json_encode(['html' => '<div class="empty-comments">Error loading comments</div>', 'success' => false]);
          }
      } else {
          echo json_encode(['html' => '<div class="empty-comments">Invalid post</div>', 'success' => false]);
      }
      exit;
  }

  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_comment'])) {
      header('Content-Type: application/json; charset=utf-8');
      $reel_id = (int)($_POST['reel_id'] ?? 0);
      $comment = trim($_POST['comment'] ?? '');
      
      if ($reel_id > 0 && !empty($comment) && strlen($comment) <= 280) {
          try {
              
              
              $stmt = $pdo->prepare("INSERT INTO reels_comments (reel_id, user_id, comment, time) VALUES (?, ?, ?, NOW())");
              $stmt->execute([$reel_id, $viewer_id, $comment]);
              
              $comment_id = $pdo->lastInsertId();
              $stmt = $pdo->prepare("
                  SELECT c.*, u.username, u.name, u.img 
                  FROM reels_comments c
                  JOIN users u ON u.id = c.user_id
                  WHERE c.id = ?
              ");
              $stmt->execute([$comment_id]);
              $newComment = $stmt->fetch(PDO::FETCH_OBJ);
              
              $html = renderCommentHTML($newComment, $viewer_id);
              
              $stmt = $pdo->prepare("UPDATE reels SET comments_count = comments_count + 1 WHERE id = ?");
              $stmt->execute([$reel_id]);
              
              echo json_encode(['success' => true, 'html' => $html, 'comment_id' => $comment_id]);
          } catch (Exception $e) {
              echo json_encode(['success' => false, 'error' => $e->getMessage()]);
          }
      } else {
          echo json_encode(['success' => false, 'error' => 'Invalid comment']);
      }
      exit;
  }

  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_reply'])) {
      header('Content-Type: application/json; charset=utf-8');
      $parent_id = (int)($_POST['parent_id'] ?? 0);
      $parent_type = $_POST['parent_type'] ?? 'comment';
      $reply = trim($_POST['reply'] ?? '');
      
      if ($parent_id > 0 && !empty($reply) && strlen($reply) <= 280) {
          try {
              
              if ($parent_type === 'comment') {
                  $comment_id = $parent_id;
              } else {
                  $stmt = $pdo->prepare("SELECT comment_id FROM reels_replies WHERE id = ?");
                  $stmt->execute([$parent_id]);
                  $parentReply = $stmt->fetch(PDO::FETCH_OBJ);
                  $comment_id = $parentReply ? $parentReply->comment_id : $parent_id;
              }
              
              $stmt = $pdo->prepare("INSERT INTO reels_replies (comment_id, user_id, reply, time, parent_id, parent_type) VALUES (?, ?, ?, NOW(), ?, ?)");
              $stmt->execute([$comment_id, $viewer_id, $reply, $parent_id, $parent_type]);
              
              $reply_id = $pdo->lastInsertId();
              $stmt = $pdo->prepare("
                  SELECT r.*, u.username, u.name, u.img 
                  FROM reels_replies r
                  JOIN users u ON u.id = r.user_id
                  WHERE r.id = ?
              ");
              $stmt->execute([$reply_id]);
              $newReply = $stmt->fetch(PDO::FETCH_OBJ);
              
              $replyHtml = renderReplyHTML($newReply, $viewer_id, ($parent_type === 'reply' ? 1 : 0));
              
              echo json_encode(['success' => true, 'html' => $replyHtml, 'reply_id' => $reply_id, 'parent_id' => $parent_id, 'parent_type' => $parent_type]);
          } catch (Exception $e) {
              echo json_encode(['success' => false, 'error' => $e->getMessage()]);
          }
      } else {
          echo json_encode(['success' => false, 'error' => 'Invalid reply']);
      }
      exit;
  }

  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['like_comment'])) {
      header('Content-Type: application/json; charset=utf-8');
      $comment_id = (int)($_POST['comment_id'] ?? 0);
      
      if ($comment_id > 0) {
          try {
              
              $stmt = $pdo->prepare("SELECT id FROM reels_comment_likes WHERE comment_id = ? AND user_id = ?");
              $stmt->execute([$comment_id, $viewer_id]);
              $existing = $stmt->fetch();
              
              if ($existing) {
                  $stmt = $pdo->prepare("DELETE FROM reels_comment_likes WHERE comment_id = ? AND user_id = ?");
                  $stmt->execute([$comment_id, $viewer_id]);
                  $liked = false;
              } else {
                  $stmt = $pdo->prepare("INSERT INTO reels_comment_likes (comment_id, user_id, created_at) VALUES (?, ?, NOW())");
                  $stmt->execute([$comment_id, $viewer_id]);
                  $liked = true;
              }
              
              $stmt = $pdo->prepare("SELECT COUNT(*) FROM reels_comment_likes WHERE comment_id = ?");
              $stmt->execute([$comment_id]);
              $likeCount = $stmt->fetchColumn();
              
              $stmt = $pdo->prepare("UPDATE reels_comments SET likes_count = ? WHERE id = ?");
              $stmt->execute([$likeCount, $comment_id]);
              
              echo json_encode(['success' => true, 'liked' => $liked, 'like_count' => $likeCount]);
          } catch (Exception $e) {
              echo json_encode(['success' => false, 'error' => $e->getMessage()]);
          }
      } else {
          echo json_encode(['success' => false, 'error' => 'Invalid comment']);
      }
      exit;
  }

  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['like_reply'])) {
      header('Content-Type: application/json; charset=utf-8');
      $reply_id = (int)($_POST['reply_id'] ?? 0);
      
      if ($reply_id > 0) {
          try {
              
              $stmt = $pdo->prepare("SELECT id FROM reels_reply_likes WHERE reply_id = ? AND user_id = ?");
              $stmt->execute([$reply_id, $viewer_id]);
              $existing = $stmt->fetch();
              
              if ($existing) {
                  $stmt = $pdo->prepare("DELETE FROM reels_reply_likes WHERE reply_id = ? AND user_id = ?");
                  $stmt->execute([$reply_id, $viewer_id]);
                  $liked = false;
              } else {
                  $stmt = $pdo->prepare("INSERT INTO reels_reply_likes (reply_id, user_id, created_at) VALUES (?, ?, NOW())");
                  $stmt->execute([$reply_id, $viewer_id]);
                  $liked = true;
              }
              
              $stmt = $pdo->prepare("SELECT COUNT(*) FROM reels_reply_likes WHERE reply_id = ?");
              $stmt->execute([$reply_id]);
              $likeCount = $stmt->fetchColumn();
              
              $stmt = $pdo->prepare("UPDATE reels_replies SET likes_count = ? WHERE id = ?");
              $stmt->execute([$likeCount, $reply_id]);
              
              echo json_encode(['success' => true, 'liked' => $liked, 'like_count' => $likeCount]);
          } catch (Exception $e) {
              echo json_encode(['success' => false, 'error' => $e->getMessage()]);
          }
      } else {
          echo json_encode(['success' => false, 'error' => 'Invalid reply']);
      }
      exit;
  }

  // ==================== DELETE COMMENT HANDLER ====================
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_comment'])) {
      header('Content-Type: application/json; charset=utf-8');
      $comment_id = (int)$_POST['comment_id'];
      $stmt = $pdo->prepare("SELECT user_id, reel_id FROM reels_comments WHERE id = ?");
      $stmt->execute([$comment_id]);
      $comment = $stmt->fetch(PDO::FETCH_OBJ);

      if ($comment && $comment->user_id == $viewer_id) {
          $pdo->prepare("DELETE FROM reels_reply_likes WHERE reply_id IN (SELECT id FROM reels_replies WHERE comment_id = ?)")->execute([$comment_id]);
          $pdo->prepare("DELETE FROM reels_replies WHERE comment_id = ?")->execute([$comment_id]);
          $pdo->prepare("DELETE FROM reels_comment_likes WHERE comment_id = ?")->execute([$comment_id]);
          $pdo->prepare("DELETE FROM reels_comments WHERE id = ?")->execute([$comment_id]);
          $pdo->prepare("UPDATE reels SET comments_count = GREATEST(comments_count - 1, 0) WHERE id = ?")->execute([$comment->reel_id]);
          echo json_encode(['success' => true]);
      } else {
          echo json_encode(['success' => false, 'error' => 'Unauthorized']);
      }
      exit;
  }

  // ==================== DELETE REPLY HANDLER ====================
  function getAllReplyDescendants($pdo, $parent_id, &$ids = []) {
      $stmt = $pdo->prepare("SELECT id FROM reels_replies WHERE parent_id = ? AND parent_type = 'reply'");
      $stmt->execute([$parent_id]);
      $children = $stmt->fetchAll(PDO::FETCH_COLUMN);
      foreach ($children as $id) { 
          $ids[] = $id; 
          getAllReplyDescendants($pdo, $id, $ids); 
      }
      return $ids;
  }

  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_reply'])) {
      header('Content-Type: application/json; charset=utf-8');
      $reply_id = (int)$_POST['reply_id'];
      $stmt = $pdo->prepare("SELECT user_id FROM reels_replies WHERE id = ?");
      $stmt->execute([$reply_id]);
      $target_reply = $stmt->fetch(PDO::FETCH_OBJ);

      if ($target_reply && $target_reply->user_id == $viewer_id) {
          $descendant_ids = getAllReplyDescendants($pdo, $reply_id);
          $all_ids_to_delete = array_merge([$reply_id], $descendant_ids);
          $id_list = implode(',', array_map('intval', $all_ids_to_delete));
          $pdo->prepare("DELETE FROM reels_reply_likes WHERE reply_id IN ($id_list)")->execute();
          $pdo->prepare("DELETE FROM reels_replies WHERE id IN ($id_list)")->execute();
          echo json_encode(['success' => true]);
      } else {
          echo json_encode(['success' => false, 'error' => 'Unauthorized']);
      }
      exit;
  }

  // -------------------- Core Functions --------------------
  
  // ==================== UPDATED: PERSONALIZED REEL RECOMMENDATION FUNCTION WITH FEED MODES ====================
  function getPersonalizedReels($pdo, $user_id, $exclude_ids = [], $filter = 'all', $search = '', $limit = 10, $feedMode = 'personalized') {
      $exclude_condition = !empty($exclude_ids) ? "AND r.id NOT IN (" . implode(',', array_map('intval', $exclude_ids)) . ")" : "";
      
      // Get users that the current user follows
      $stmt = $pdo->prepare("SELECT following_id FROM follow WHERE follower_id = ?");
      $stmt->execute([$user_id]);
      $following = $stmt->fetchAll(PDO::FETCH_COLUMN);
      $followingList = !empty($following) ? implode(',', $following) : '0';
      
      $stmt = $pdo->prepare("
    SELECT 
        hashtag,
        interaction_count,
        last_interaction,
        (
            (interaction_count * 0.7) +
            (
                CASE 
                    WHEN last_interaction > DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 30
                    WHEN last_interaction > DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 10
                    ELSE 0
                END
            )
        ) AS score
    FROM user_hashtag_interests
    WHERE user_id = ?
      AND last_interaction > DATE_SUB(NOW(), INTERVAL 60 DAY)
    ORDER BY score DESC
    LIMIT 10
");
$stmt->execute([$user_id]);
      $hashtagInterests = $stmt->fetchAll(PDO::FETCH_OBJ);
      
      // Get user's category preferences
      $stmt = $pdo->prepare("
          SELECT category, score 
          FROM user_category_preferences 
          WHERE user_id = ? 
          ORDER BY score DESC
      ");
      $stmt->execute([$user_id]);
      $categoryPrefs = $stmt->fetchAll(PDO::FETCH_OBJ);
      
      // Build hashtag search conditions for SQL
      $hashtagConditions = "";
      $hashtagParams = [];
      if (!empty($hashtagInterests)) {
          $hashtagConditions = " OR (";
          $hashtagParts = [];
          foreach ($hashtagInterests as $index => $interest) {
              $hashtagParts[] = "r.caption LIKE ?";
              $hashtagParams[] = '%#' . $interest->hashtag . '%';
          }
          $hashtagConditions .= implode(" OR ", $hashtagParts) . ")";
      }
      
      // Build category conditions
      $categoryCondition = "";
      if (!empty($categoryPrefs) && $filter === 'all') {
          $categoryCondition = " OR r.media_type IN (";
          $catParts = [];
          foreach ($categoryPrefs as $pref) {
              $catParts[] = "?";
              $hashtagParams[] = $pref->category;
          }
          $categoryCondition .= implode(",", $catParts) . ")";
      }
      
      // Different scoring based on feed mode
      if ($feedMode === 'following') {
          // UPDATED FOLLOWING FEED: Added Freshness Bonus to show newest reels first
          $sql = "
              SELECT r.*, 
                     u.username, u.name, u.img as user_img, u.is_verified,
                     (SELECT COUNT(*) FROM reel_likes WHERE reel_id = r.id) as likes_count,
                     (SELECT COUNT(*) FROM reels_comments WHERE reel_id = r.id) as comments_count,
                     (SELECT COUNT(*) FROM reels_saves WHERE reel_id = r.id) as saves_count,
                     r.views_count, r.engagement_score,
                     m.title as music_title, m.artist as music_artist, m.file_path as music_path,
                     (SELECT COUNT(*) FROM reels_saves WHERE reel_id = r.id AND user_id = ?) as is_saved,
                     (SELECT COUNT(*) FROM follow WHERE follower_id = ? AND following_id = r.user_id) as is_following,
                     -- SCORE: Engagement + Base 50 + Freshness Bonus (newest reels get higher score)
                     (r.likes_count * 0.08) + (r.comments_count * 0.05) + 50 + 
                     (CASE 
                         WHEN r.created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 50  -- Brand new (last 24 hours)
                         WHEN r.created_at > DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 20   -- Recent (last 7 days)
                         ELSE 0 
                     END) as priority_score
              FROM reels r
              JOIN users u ON u.id = r.user_id
              LEFT JOIN music_library m ON m.id = r.music_id
              WHERE r.user_id IN ({$followingList})
                AND r.user_id != ?
                {$exclude_condition}
          ";
          
          $params = [$user_id, $user_id, $user_id];
          
      } else {
          // RE-WEIGHTED PERSONALIZED FEED: Followed users get moderate boost (35 points instead of 100)
          $sql = "
              SELECT r.*, 
                     u.username, u.name, u.img as user_img, u.is_verified,
                     (SELECT COUNT(*) FROM reel_likes WHERE reel_id = r.id) as likes_count,
                     (SELECT COUNT(*) FROM reels_comments WHERE reel_id = r.id) as comments_count,
                     (SELECT COUNT(*) FROM reels_saves WHERE reel_id = r.id) as saves_count,
                     r.views_count,
                     m.title as music_title, m.artist as music_artist, m.file_path as music_path,
                     (SELECT COUNT(*) FROM reels_saves WHERE reel_id = r.id AND user_id = ?) as is_saved,
                     (SELECT COUNT(*) FROM follow WHERE follower_id = ? AND following_id = r.user_id) as is_following,
                     CASE 
                         WHEN r.user_id IN ({$followingList}) THEN 35
                         WHEN r.likes_count > 1000 THEN 25
                         WHEN r.likes_count > 500 THEN 20
                         WHEN r.comments_count > 50 THEN 15
                         ELSE 5
                     END +
                     COALESCE((SELECT SUM(interaction_count) FROM user_hashtag_interests uhi 
                               WHERE uhi.user_id = ? AND r.caption LIKE CONCAT('%#', uhi.hashtag, '%')), 0) +
                     (r.likes_count * 0.03) +
                     (r.comments_count * 0.02) as priority_score
              FROM reels r
              JOIN users u ON u.id = r.user_id
              LEFT JOIN music_library m ON m.id = r.music_id
              WHERE r.user_id != ?
              {$exclude_condition}
          ";
          
          $params = [$user_id, $user_id, $user_id, $user_id];
      }
      
      // Add search filter
      if (!empty($search)) {
          $sql .= " AND (r.caption LIKE ? OR u.username LIKE ? OR u.name LIKE ?)";
          $params[] = '%' . strtolower($search) . '%';
          $params[] = '%' . strtolower($search) . '%';
          $params[] = '%' . strtolower($search) . '%';
      }
      
      // Add media type filter
      if ($filter === 'video') {
          $sql .= " AND r.media_type = 'video'";
      } elseif ($filter === 'image') {
          $sql .= " AND r.media_type = 'image'";
      }
      
      $sql .= " ORDER BY priority_score DESC, r.created_at DESC LIMIT " . ($limit * 2);
      
      $stmt = $pdo->prepare($sql);
      $stmt->execute($params);
      $reels = $stmt->fetchAll(PDO::FETCH_OBJ);
      
      if (empty($reels)) {
          if ($feedMode === 'following') {
              return ['reels' => [], 'new_ids' => [], 'followMap' => []];
          }
          // Fallback to regular random reels if no personalized results
          return getFallbackReels($pdo, $user_id, $exclude_ids, $filter, $search, $limit);
      }
      
      // Remove duplicates and limit
      $uniqueReels = [];
$seenIds = [];
foreach ($reels as $reel) {
    if (!in_array($reel->id, $seenIds)) {
        $seenIds[] = $reel->id;
        $uniqueReels[] = $reel;
    }
}

// ========== ADD JITTER FOR VARIETY ==========
// Apply 30% random swing to prevent static ordering
foreach ($uniqueReels as $reel) {
    // Jitter between 0.7 and 1.3 (30% variation)
    $jitter = 0.7 + (mt_rand() / mt_getrandmax() * 0.6);
    // Combine priority_score with engagement_score, then apply jitter
    $reel->final_sort_score = ($reel->priority_score + ($reel->engagement_score ?? 0)) * $jitter;
}

// Sort by the jittered score (higher = better)
usort($uniqueReels, function($a, $b) {
    return $b->final_sort_score <=> $a->final_sort_score;
});
// ========== END JITTER CODE ==========

// Take first $limit reels
$reels = array_slice($uniqueReels, 0, $limit);
$new_ids = array_column($reels, 'id');
      
      // Build follow map
      $authorIds = array_column($reels, 'user_id');
      $followMap = [];
      if (!empty($authorIds)) {
          $in = str_repeat('?,', count($authorIds) - 1) . '?';
          $stmt = $pdo->prepare("SELECT following_id FROM follow WHERE follower_id = ? AND following_id IN ($in)");
          $stmt->execute(array_merge([$user_id], $authorIds));
          $frows = $stmt->fetchAll(PDO::FETCH_COLUMN);
          foreach ($frows as $fid) {
              $followMap[(int)$fid] = true;
          }
      }
      
      return ['reels' => $reels, 'new_ids' => $new_ids, 'followMap' => $followMap];
  }
  
  // Fallback function when personalized feed is empty
  function getFallbackReels($pdo, $user_id, $exclude_ids = [], $filter = 'all', $search = '', $limit = 10) {
      $exclude_condition = !empty($exclude_ids) ? "AND r.id NOT IN (" . implode(',', array_map('intval', $exclude_ids)) . ")" : "";
      
      $sql = "
          SELECT r.*, 
                 u.username, u.name, u.img as user_img, u.is_verified,
                 (SELECT COUNT(*) FROM reel_likes WHERE reel_id = r.id) as likes_count,
                 (SELECT COUNT(*) FROM reels_comments WHERE reel_id = r.id) as comments_count,
                 (SELECT COUNT(*) FROM reels_saves WHERE reel_id = r.id) as saves_count,
                 r.views_count,
                 m.title as music_title, m.artist as music_artist, m.file_path as music_path,
                 (SELECT COUNT(*) FROM reels_saves WHERE reel_id = r.id AND user_id = ?) as is_saved
          FROM reels r
          JOIN users u ON u.id = r.user_id
          LEFT JOIN music_library m ON m.id = r.music_id
          WHERE r.user_id != ?
          {$exclude_condition}
      ";
      
      $params = [$user_id, $user_id];
      
      if ($filter === 'video') {
          $sql .= " AND r.media_type = 'video'";
      } elseif ($filter === 'image') {
          $sql .= " AND r.media_type = 'image'";
      }
      
      if (!empty($search)) {
          $sql .= " AND (r.caption LIKE ? OR u.username LIKE ? OR u.name LIKE ?)";
          $params[] = '%' . strtolower($search) . '%';
          $params[] = '%' . strtolower($search) . '%';
          $params[] = '%' . strtolower($search) . '%';
      }
      
      $sql .= " ORDER BY r.likes_count DESC, r.created_at DESC LIMIT " . ($limit * 2);
      
      $stmt = $pdo->prepare($sql);
      $stmt->execute($params);
      $reels = $stmt->fetchAll(PDO::FETCH_OBJ);
      
      if (empty($reels)) {
          return ['reels' => [], 'new_ids' => [], 'followMap' => []];
      }
      
      shuffle($reels);
      $reels = array_slice($reels, 0, $limit);
      $new_ids = array_column($reels, 'id');
      
      $authorIds = array_column($reels, 'user_id');
      $followMap = [];
      if (!empty($authorIds)) {
          $in = str_repeat('?,', count($authorIds) - 1) . '?';
          $stmt = $pdo->prepare("SELECT following_id FROM follow WHERE follower_id = ? AND following_id IN ($in)");
          $stmt->execute(array_merge([$user_id], $authorIds));
          $frows = $stmt->fetchAll(PDO::FETCH_COLUMN);
          foreach ($frows as $fid) {
              $followMap[(int)$fid] = true;
          }
      }
      
      return ['reels' => $reels, 'new_ids' => $new_ids, 'followMap' => $followMap];
  }
  
  function getCollectionReels($pdo, $viewer_id, $target_user_id, $tab) {
      $sql = "
          SELECT r.*, 
                 u.username, u.name, u.img as user_img, u.is_verified,
                 (SELECT COUNT(*) FROM reel_likes WHERE reel_id = r.id) as likes_count,
                 (SELECT COUNT(*) FROM reels_comments WHERE reel_id = r.id) as comments_count,
                 (SELECT COUNT(*) FROM reels_saves WHERE reel_id = r.id) as saves_count,
                 r.views_count,
                 m.title as music_title, m.artist as music_artist, m.file_path as music_path,
                 (SELECT COUNT(*) FROM follow WHERE follower_id = ? AND following_id = r.user_id) as is_following,
                 (SELECT COUNT(*) FROM reels_saves WHERE reel_id = r.id AND user_id = ?) as is_saved
          FROM reels r
          JOIN users u ON u.id = r.user_id
          LEFT JOIN music_library m ON m.id = r.music_id ";

      if ($tab === 'liked') {
          $sql .= "JOIN reel_likes rl ON rl.reel_id = r.id WHERE rl.user_id = ? ORDER BY rl.created_at DESC";
          $params = [$viewer_id, $viewer_id, $target_user_id];
      } elseif ($tab === 'saved') {
          $sql .= "JOIN reels_saves rs ON rs.reel_id = r.id WHERE rs.user_id = ? ORDER BY rs.created_at DESC";
          $params = [$viewer_id, $viewer_id, $target_user_id];
      } else {
          $sql .= "WHERE r.user_id = ? ORDER BY r.created_at DESC";
          $params = [$viewer_id, $viewer_id, $target_user_id];
      }

      $stmt = $pdo->prepare($sql);
      $stmt->execute($params);
      $reels = $stmt->fetchAll(PDO::FETCH_OBJ);

      $followMap = [];
      foreach ($reels as $reel) {
          if ($reel->is_following) $followMap[(int)$reel->user_id] = true;
      }
      return ['reels' => $reels, 'followMap' => $followMap];
  }

  function formatCaption($text, $postId) {
      $maxLength = 15;
      $plainText = strip_tags($text);
      
      if (strlen($plainText) > $maxLength) {
          $truncated = substr($plainText, 0, $maxLength);
          return '
              <div class="caption-container" data-caption-id="' . $postId . '">
                  <span class="caption-short">' . nl2br($truncated) . '...</span>
                  <span class="caption-full" style="display:none;">' . nl2br($text) . '</span>
                  <button class="read-more-caption" onclick="toggleReelCaption(' . $postId . ')">Read more</button>
              </div>
          ';
      }
      return '<div class="caption-text">' . nl2br($text) . '</div>';
  }

  function renderMusicBar($reel) {
      if (empty($reel->music_id) || empty($reel->music_title)) {
          return '';
      }
      
      $musicUrl = "assets/music/" . $reel->music_path;
      $startTime = $reel->music_start_time ?: 0;
      $endTime = $reel->music_end_time ?: '';
      
      return '
          <div class="music-bar" data-music-url="' . $musicUrl . '" data-start="' . $startTime . '" data-end="' . $endTime . '" data-music-id="' . $reel->music_id . '">
              <i class="fas fa-music"></i>
              <span class="music-title">' . htmlspecialchars($reel->music_title) . '</span>
              <span class="music-artist">' . htmlspecialchars($reel->music_artist ?: 'Unknown') . '</span>
              <div class="music-wave">
                  <span></span><span></span><span></span><span></span><span></span>
              </div>
          </div>
      ';
  }

  function renderReelsHTML($reels, $viewer_id, $followMap) {
      if (empty($reels)) {
          return '<div class="empty-state"><i class="fas fa-film"></i><h4>No Reels Found</h4><p>Try different filters or search hashtags</p></div>';
      }
      
      $html = '';
      foreach ($reels as $index => $reel):
          $isFollowing = isset($followMap[(int)$reel->user_id]);
          $mediaPath = "assets/reels/" . $reel->media_path;
          $mediaType = $reel->media_type;
          $safeCaption = htmlspecialchars($reel->caption ?: '');
          $timeAgo = timeAgo($reel->created_at);
          $userLiked = checkUserLikedReel($viewer_id, $reel->id);
          $userSaved = isset($reel->is_saved) ? $reel->is_saved : checkUserSavedReel($viewer_id, $reel->id);
          $videoMuted = $reel->video_muted ?? 0;
          $hasMusic = !empty($reel->music_id);
          $saveCount = $reel->saves_count ?? 0;
          
          $linkedCaption = preg_replace_callback('/#([a-zA-Z0-9_]+)/', function($m) {
              return '<a href="#" class="hashtag-link" data-hashtag="' . htmlspecialchars($m[1]) . '">#' . htmlspecialchars($m[1]) . '</a>';
          }, $safeCaption);
          
          $html .= '<div class="reel" data-index="' . $index . '" data-reel-id="' . $reel->id . '" data-video-muted="' . $videoMuted . '" data-has-music="' . ($hasMusic ? 'true' : 'false') . '">';
          $html .= '<div class="media">';
          if ($mediaType === 'video') {
              $html .= '<video muted="' . ($videoMuted ? 'true' : 'false') . '" playsinline preload="metadata" data-reelid="' . $reel->id . '">';
              $html .= '<source src="' . $mediaPath . '" type="video/mp4">';
              $html .= '</video>';
          } else {
              $html .= '<img src="' . $mediaPath . '" alt="Reel image">';
          }
          
              $html .= '<div class="sound-toggle" data-reel="' . $reel->id . '" data-music-control="true">';
              $html .= '<i class="fas fa-volume-up"></i>';
              $html .= '</div>';
          
          $html .= '</div>';
          $html .= '<div class="reel-overlay"></div>';
          
          $html .= '<div class="info">';
          $html .= '<div class="info-content">';
          $html .= '<div class="avatar" style="position: relative;">';
          $html .= '<a href="prof.php?username=' . htmlspecialchars($reel->username) . '">';
          $html .= '<img class="avatar-img" src="assets/images/users/' . htmlspecialchars($reel->user_img) . '" alt="' . htmlspecialchars($reel->name) . '">';
          $html .= '</a>';
          if ($reel->user_id != $viewer_id) {
              $html .= '<button class="follow-overlay ' . ($isFollowing ? 'following' : '') . '" data-user="' . $reel->user_id . '">';
              $html .= '<i class="fas ' . ($isFollowing ? 'fa-check' : 'fa-plus') . '"></i>';
              $html .= '</button>';
          }
          $html .= '</div>';
          $html .= '<div class="author-details">';
          $html .= '<div class="author-name">';
          $html .= '<a href="prof.php?username=' . htmlspecialchars($reel->username) . '">' . htmlspecialchars($reel->name) . '</a>';
          if (!empty($reel->is_verified)) {
              $html .= '<i class="fas fa-check-circle verified-badge"></i>';
          }
          $html .= '</div>';
          $html .= '<div class="username">@' . htmlspecialchars($reel->username) . '</div>';
          $html .= '<div class="post-date" data-time="' . $reel->created_at . '">' . $timeAgo . '</div>';
          
          if (!empty($reel->caption)) {
              $html .= '<div class="caption-wrapper">';
              $html .= formatCaption($linkedCaption, $reel->id);
              $html .= '</div>';
          }
          
          $html .= '</div></div></div>';
          
          $html .= '<div class="actions-sidebar">';
          $html .= '<div class="action-btn like-btn ' . ($userLiked ? 'liked' : '') . '" data-reel="' . $reel->id . '">';
          $html .= '<i class="' . ($userLiked ? 'fas' : 'far') . ' fa-heart"></i>';
          $html .= '<span class="count">' . $reel->likes_count . '</span>';
          $html .= '</div>';
          $html .= '<div class="action-btn comment-btn" data-reel="' . $reel->id . '">';
          $html .= '<i class="far fa-comment"></i>';
          $html .= '<span class="count">' . $reel->comments_count . '</span>';
          $html .= '</div>';
          $html .= '<div class="action-btn save-btn ' . ($userSaved ? 'saved' : '') . '" data-reel="' . $reel->id . '">';
          $html .= '<i class="' . ($userSaved ? 'fas' : 'far') . ' fa-bookmark"></i>';
          $html .= '<span class="count">' . $saveCount . '</span>';
          $html .= '</div>';
          $html .= '<div class="action-btn share-btn" data-reel="' . $reel->id . '" data-user="' . $reel->user_id . '">';
$html .= '<i class="fas fa-share-alt"></i>';
$html .= '</div>';
          $html .= '<div class="action-btn view-count" style="cursor: default;">';
          $html .= '<i class="fas fa-eye"></i>';
          $html .= '<span class="count">' . number_format($reel->views_count ?? 0) . '</span>';
          $html .= '</div>';
          $html .= '</div>';
          
          if (!empty($reel->music_id)) {
              $html .= renderMusicBar($reel);
          }
          
          $html .= '<div class="indicators">';
          $html .= '<i class="fas fa-film"></i> <span class="current-index">' . ($index + 1) . '</span> / <span class="total-count">' . count($reels) . '</span>';
          $html .= '</div>';
          $html .= '</div>';
      endforeach;
      
      return $html;
  }

  function checkUserLikedReel($user_id, $reel_id) {
      $pdo = Connect::connect();
      $stmt = $pdo->prepare("SELECT id FROM reel_likes WHERE reel_id = ? AND user_id = ?");
      $stmt->execute([$reel_id, $user_id]);
      return $stmt->fetch() ? true : false;
  }

  function checkUserSavedReel($user_id, $reel_id) {
      $pdo = Connect::connect();
      $stmt = $pdo->prepare("SELECT id FROM reels_saves WHERE reel_id = ? AND user_id = ?");
      $stmt->execute([$reel_id, $user_id]);
      return $stmt->fetch() ? true : false;
  }

  // ==================== COMMENT FUNCTIONS ====================
  
  function renderCommentHTML($comment, $user_id) {
      $timeAgo = timeAgo($comment->time);
      
      $pdo = Connect::connect();
      $stmt = $pdo->prepare("SELECT COUNT(*) FROM reels_comment_likes WHERE comment_id = ?");
      $stmt->execute([$comment->id]);
      $likeCount = $stmt->fetchColumn();
      
      $stmt = $pdo->prepare("SELECT id FROM reels_comment_likes WHERE comment_id = ? AND user_id = ?");
      $stmt->execute([$comment->id, $user_id]);
      $userLiked = $stmt->fetch() ? true : false;
      
      $stmt = $pdo->prepare("SELECT COUNT(*) FROM reels_replies WHERE parent_id = ? AND parent_type = 'comment'");
      $stmt->execute([$comment->id]);
      $replyCount = $stmt->fetchColumn();
      
      $html = '<div class="comment-item" data-comment-id="' . $comment->id . '">';
      
      // Add three-dot menu for comment owner (RIGHT side)
      if ($comment->user_id == $user_id) {
          $html .= '
          <div class="comment-menu-container">
              <button class="comment-menu-btn"><i class="fas fa-ellipsis-v"></i></button>
              <div class="comment-dropdown" style="display:none;">
                  <button class="delete-comment-action" data-id="'.$comment->id.'"><i class="fas fa-trash"></i> Delete</button>
              </div>
          </div>';
      }
      
      $html .= '<img src="assets/images/users/' . htmlspecialchars($comment->img) . '" class="comment-avatar" alt="">';
      $html .= '<div class="comment-content">';
      $html .= '<div class="comment-author">';
      $html .= '<strong>' . htmlspecialchars($comment->name) . '</strong>';
      $html .= '<span>@' . htmlspecialchars($comment->username) . ' · <span class="comment-time" data-time="' . $comment->time . '">' . $timeAgo . '</span></span>';
      $html .= '</div>';
      $html .= '<div class="comment-text">' . nl2br(htmlspecialchars($comment->comment)) . '</div>';
      $html .= '<div class="comment-actions">';
      $html .= '<button class="like-comment ' . ($userLiked ? 'liked' : '') . '" data-id="' . $comment->id . '">';
      $html .= '<i class="' . ($userLiked ? 'fas' : 'far') . ' fa-heart"></i> <span class="count">' . $likeCount . '</span>';
      $html .= '</button>';
      $html .= '<button class="reply-to-comment" data-id="' . $comment->id . '">Reply</button>';
      if ($replyCount > 0) {
          $html .= '<button class="toggle-replies" data-id="' . $comment->id . '">Show replies (' . $replyCount . ')</button>';
      }
      $html .= '</div>';
      $html .= '<div class="reply-form" id="reply-form-comment-' . $comment->id . '" style="display:none;">';
      $html .= '<input type="text" placeholder="Write a reply..." id="reply-input-comment-' . $comment->id . '">';
      $html .= '<button class="submit-reply" data-parent-id="' . $comment->id . '" data-parent-type="comment">Reply</button>';
      $html .= '</div>';
      $html .= '<div class="replies-list" id="replies-list-' . $comment->id . '"></div>';
      $html .= '</div></div>';
      
      return $html;
  }

  function renderReplyHTML($reply, $user_id, $level = 0) {
      $timeAgo = timeAgo($reply->time);
      $maxLevel = 3;
      $indent = min($level * 20, 60);
      
      $pdo = Connect::connect();
      $stmt = $pdo->prepare("SELECT COUNT(*) FROM reels_reply_likes WHERE reply_id = ?");
      $stmt->execute([$reply->id]);
      $likeCount = $stmt->fetchColumn();
      
      $stmt = $pdo->prepare("SELECT id FROM reels_reply_likes WHERE reply_id = ? AND user_id = ?");
      $stmt->execute([$reply->id, $user_id]);
      $userLiked = $stmt->fetch() ? true : false;
      
      $stmt = $pdo->prepare("SELECT COUNT(*) FROM reels_replies WHERE parent_id = ? AND parent_type = 'reply'");
      $stmt->execute([$reply->id]);
      $childReplyCount = $stmt->fetchColumn();
      
      $html = '<div class="reply-item" data-reply-id="' . $reply->id . '" style="margin-left: ' . $indent . 'px;">';
      
      // Add three-dot menu for reply owner (RIGHT side)
      if ($reply->user_id == $user_id) {
          $html .= '
          <div class="comment-menu-container">
              <button class="comment-menu-btn"><i class="fas fa-ellipsis-v"></i></button>
              <div class="comment-dropdown" style="display:none;">
                  <button class="delete-reply-action" data-id="'.$reply->id.'"><i class="fas fa-trash"></i> Delete</button>
              </div>
          </div>';
      }
      
      $html .= '<img src="assets/images/users/' . htmlspecialchars($reply->img) . '" class="reply-avatar" alt="">';
      $html .= '<div class="reply-content">';
      $html .= '<div class="reply-author">';
      $html .= '<strong>' . htmlspecialchars($reply->name) . '</strong>';
      $html .= '<span>@' . htmlspecialchars($reply->username) . ' · <span class="reply-time" data-time="' . $reply->time . '">' . $timeAgo . '</span></span>';
      $html .= '</div>';
      $html .= '<div class="reply-text">' . nl2br(htmlspecialchars($reply->reply)) . '</div>';
      $html .= '<div class="reply-actions">';
      $html .= '<button class="like-reply ' . ($userLiked ? 'liked' : '') . '" data-id="' . $reply->id . '">';
      $html .= '<i class="' . ($userLiked ? 'fas' : 'far') . ' fa-heart"></i> <span class="count">' . $likeCount . '</span>';
      $html .= '</button>';
      if ($level < $maxLevel) {
          $html .= '<button class="reply-to-reply" data-id="' . $reply->id . '">Reply</button>';
      }
      if ($childReplyCount > 0) {
          $html .= '<button class="toggle-child-replies" data-id="' . $reply->id . '">Show replies (' . $childReplyCount . ')</button>';
      }
      $html .= '</div>';
      if ($level < $maxLevel) {
          $html .= '<div class="reply-form" id="reply-form-reply-' . $reply->id . '" style="display:none;">';
          $html .= '<input type="text" placeholder="Write a reply..." id="reply-input-reply-' . $reply->id . '">';
          $html .= '<button class="submit-reply" data-parent-id="' . $reply->id . '" data-parent-type="reply">Reply</button>';
          $html .= '</div>';
      }
      $html .= '<div class="child-replies-list" id="child-replies-' . $reply->id . '"></div>';
      $html .= '</div></div>';
      
      return $html;
  }

  function loadNestedReplies($pdo, $parent_id, $parent_type, $user_id, $level = 0) {
      $stmt = $pdo->prepare("
          SELECT r.*, u.username, u.name, u.img 
          FROM reels_replies r
          JOIN users u ON u.id = r.user_id
          WHERE r.parent_id = ? AND r.parent_type = ?
          ORDER BY r.time ASC
      ");
      $stmt->execute([$parent_id, $parent_type]);
      $replies = $stmt->fetchAll(PDO::FETCH_OBJ);
      
      if (empty($replies)) {
          return '';
      }
      
      $html = '';
      foreach ($replies as $reply) {
          $html .= renderReplyHTML($reply, $user_id, $level);
          $html .= loadNestedReplies($pdo, $reply->id, 'reply', $user_id, $level + 1);
      }
      
      return $html;
  }

  function getReelCommentsHTML($pdo, $reel_id, $user_id) {
      $stmt = $pdo->prepare("
          SELECT c.*, u.username, u.name, u.img 
          FROM reels_comments c
          JOIN users u ON u.id = c.user_id
          WHERE c.reel_id = ?
          ORDER BY c.time DESC
      ");
      $stmt->execute([$reel_id]);
      $comments = $stmt->fetchAll(PDO::FETCH_OBJ);
      
      if (empty($comments)) {
          return '<div class="empty-comments">No comments yet. Be the first to comment!</div>';
      }
      
      $html = '';
      foreach ($comments as $comment) {
          $html .= renderCommentHTML($comment, $user_id);
          
          $repliesHtml = loadNestedReplies($pdo, $comment->id, 'comment', $user_id, 0);
          if (!empty($repliesHtml)) {
              $html = str_replace('<div class="replies-list" id="replies-list-' . $comment->id . '"></div>', 
                                  '<div class="replies-list" id="replies-list-' . $comment->id . '">' . $repliesHtml . '</div>', 
                                  $html);
          }
      }
      
      return $html;
  }

  function timeAgo($time) {
      $diff = time() - strtotime($time);
      if ($diff < 60) return "just now";
      if ($diff < 3600) return floor($diff / 60) . "m";
      if ($diff < 86400) return floor($diff / 3600) . "h";
      return floor($diff / 86400) . "d";
  }

  // -------------------- Get Initial Reels --------------------
  
  // Check feed mode from session or GET
  $currentFeedMode = isset($_GET['feed']) ? $_GET['feed'] : (isset($_SESSION['feed_mode']) ? $_SESSION['feed_mode'] : 'personalized');
  $_SESSION['feed_mode'] = $currentFeedMode;
  
  if ($specificReelId > 0 && ($specificUserId > 0 || $sourceTab !== 'all')) {
      // Collection mode (Liked/Saved/User Profile)
      $collectionData = getCollectionReels($pdo, $viewer_id, $specificUserId, $sourceTab);
      $initialReels = $collectionData['reels'];
      $followMap = $collectionData['followMap'];
      
      if (!empty($initialReels)) {
          $startIndex = 0;
          foreach ($initialReels as $index => $reel) {
              if ($reel->id == $specificReelId) {
                  $startIndex = $index;
                  break;
              }
          }
          $exclude_ids = array_column($initialReels, 'id');
          $startIndexJS = $startIndex;
          $isUserModeJS = 'true';
      } else {
          $initialReels = []; $exclude_ids = []; $followMap = []; $startIndexJS = 0; $isUserModeJS = 'false';
      }
      $currentFeedMode = 'profile';
  } else {
      // Personalized or Following feed mode
      $initialData = getPersonalizedReels($pdo, $viewer_id, [], 'all', '', 10, $currentFeedMode);
      $initialReels = $initialData['reels'];
      $exclude_ids = $initialData['new_ids'];
      $followMap = $initialData['followMap'] ?? [];
      $startIndexJS = 0;
      $isUserModeJS = 'false';
  }
  
  $stmt = $pdo->prepare("SELECT COUNT(*) AS unread_total FROM messages WHERE receiver_id = ? AND is_read = 0");
  $stmt->execute([$viewer_id]);
  $unreadMessages = $stmt->fetch(PDO::FETCH_OBJ)->unread_total ?? 0;
  $notify_count = User::CountNotification($viewer_id);
  $who_users = Follow::whoToFollow($viewer_id);
  
  // Check if user follows anyone
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM follow WHERE follower_id = ?");
  $stmt->execute([$viewer_id]);
  $followingCount = $stmt->fetchColumn();
  
  // Pass user mode and start index to JavaScript
  $isUserModeJS = ($specificUserId > 0) ? 'true' : 'false';
  $startIndexJS = isset($startIndexJS) ? $startIndexJS : 0;
?>

<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Reels</title>
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#0f1113">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<style>
:root {
    --bg-dark: #0a0a0c;
    --card-dark: #121215;
    --surface-dark: #1a1a1f;
    --text-primary: #ffffff;
    --text-secondary: #a0a0a8;
    --accent-primary: #1da1f2;
    --save-color: #f9a825;
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: 'Inter', system-ui, -apple-system, sans-serif;
    background: linear-gradient(135deg, #0a0a0c 0%, #121215 100%);
    color: var(--text-primary);
    overflow: hidden;
    height: 100vh;
}

/* ==================== FEED MODE BUTTONS - STUCK TO LEFT EDGE OF REEL VIEWER ==================== */
.feed-mode-container {
    position: fixed;
    left: 50%;
    top: 50%;
    transform: translate(-50%, -50%);
    z-index: 101;
    display: flex;
    flex-direction: column;
    gap: 16px;
    width: auto;
}

/* Desktop: Buttons stick to left edge of the reel viewer */
@media (min-width: 768px) {
    .viewer {
        width: 450px;
        height: 90vh;
        top: 10vh;
        left: 50%;
        transform: translateX(-50%);
    }
    
    .feed-mode-container {
        position: fixed;
        left: calc(50% - 225px - 60px); /* Half of viewer width + margin */
        top: 50%;
        transform: translateY(-50%);
        right: auto;
        flex-direction: column;
        gap: 16px;
    }
}

/* Mobile: Buttons still stick to left of viewer */
@media (max-width: 767px) {
    .viewer {
        width: 92vw;
        left: 50%;
        transform: translateX(-50%);
    }
    
    .feed-mode-container {
        position: fixed;
        left: calc(50% - 46vw - 50px);
        top: 50%;
        transform: translateY(-50%);
        flex-direction: column;
        gap: 12px;
    }
}

.feed-mode-btn {
    width: 56px;
    height: 56px;
    border-radius: 50%;
    background: rgba(18, 18, 21, 0.95);
    backdrop-filter: blur(20px);
    border: 1px solid rgba(255,255,255,0.08);
    color: var(--text-secondary);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 4px;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
}

.feed-mode-btn i {
    font-size: 22px;
}

.feed-mode-btn span {
    font-size: 10px;
    font-weight: 600;
}

.feed-mode-btn.active {
    background: var(--accent-primary);
    color: white;
    border-color: var(--accent-primary);
    box-shadow: 0 0 20px rgba(29, 161, 242, 0.3);
}

.feed-mode-btn:hover:not(.active) {
    background: rgba(29, 161, 242, 0.2);
    color: var(--accent-primary);
    transform: scale(1.05);
}

/* Left Sidebar with Search */
.left-sidebar {
    position: fixed;
    left: 0;
    top: 0;
    width: 280px;
    height: 100vh;
    background: rgba(18, 18, 21, 0.98);
    backdrop-filter: blur(20px);
    border-right: 1px solid rgba(255,255,255,0.1);
    z-index: 150;
    transform: translateX(-100%);
    transition: transform 0.3s ease;
    display: flex;
    flex-direction: column;
}

.left-sidebar.open {
    transform: translateX(0);
}

.sidebar-header {
    padding: 20px;
    border-bottom: 1px solid rgba(255,255,255,0.1);
}

.sidebar-header h3 {
    font-size: 18px;
    font-weight: 600;
    margin-bottom: 15px;
}

.sidebar-search {
    position: relative;
}

.sidebar-search input {
    width: 100%;
    background: rgba(255,255,255,0.1);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 30px;
    padding: 12px 16px 12px 40px;
    color: white;
    font-size: 14px;
}

.sidebar-search i {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-secondary);
    font-size: 14px;
}

.sidebar-search input:focus {
    outline: none;
    border-color: var(--accent-primary);
}

.search-results {
    flex: 1;
    overflow-y: auto;
    padding: 10px 0;
}

.search-result-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 20px;
    cursor: pointer;
    transition: all 0.2s;
}

.search-result-item:hover {
    background: rgba(255,255,255,0.05);
}

.search-result-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    object-fit: cover;
}

.search-result-info {
    flex: 1;
}

.search-result-name {
    font-weight: 600;
    font-size: 14px;
}

.search-result-username {
    font-size: 12px;
    color: var(--text-secondary);
}

.search-result-badge {
    color: var(--accent-primary);
    font-size: 12px;
}

.sidebar-close {
    position: absolute;
    top: 15px;
    right: 15px;
    background: none;
    border: none;
    color: white;
    font-size: 20px;
    cursor: pointer;
    display: none;
}

/* Sidebar Toggle Button */
.sidebar-toggle {
    position: fixed;
    top: 20px;
    left: 20px;
    width: 40px;
    height: 40px;
    background: rgba(0,0,0,0.5);
    backdrop-filter: blur(8px);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    z-index: 60;
    border: 1px solid rgba(255,255,255,0.15);
}

.sidebar-toggle i {
    font-size: 20px;
    color: white;
}

/* Swipe Indicators */
.swipe-indicator {
    position: fixed;
    left: 50%;
    transform: translateX(-50%);
    background: rgba(0,0,0,0.5);
    color: white;
    padding: 8px 16px;
    border-radius: 30px;
    font-size: 12px;
    z-index: 100;
    pointer-events: none;
    opacity: 0;
    transition: opacity 0.3s;
}

.swipe-indicator.up {
    top: 100px;
}

.swipe-indicator.down {
    bottom: 100px;
}

::-webkit-scrollbar {
    width: 4px;
}
::-webkit-scrollbar-track {
    background: rgba(255, 255, 255, 0.05);
}
::-webkit-scrollbar-thumb {
    background: rgba(255, 255, 255, 0.2);
    border-radius: 4px;
}

.header {
    position: fixed;
    top: 16px;
    left: 50%;
    transform: translateX(-50%);
    z-index: 100;
    width: 90%;
    max-width: 700px;
    background: rgba(18, 18, 21, 0.95);
    backdrop-filter: blur(20px);
    border-radius: 60px;
    padding: 8px 16px;
    box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.3);
    border: 1px solid rgba(255,255,255,0.08);
    display: flex;
    align-items: center;
    gap: 10px;
}

.header .search-section {
    flex: 1;
    display: flex;
    gap: 10px;
    align-items: center;
}

.input-group {
    flex: 2;
    position: relative;
}

.input-icon {
    position: absolute;
    left: 16px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-secondary);
    font-size: 14px;
}

.input {
    width: 100%;
    background: var(--card-dark);
    border: 1px solid rgba(255,255,255,0.08);
    padding: 12px 16px 12px 42px;
    border-radius: 40px;
    color: var(--text-primary);
    font-size: 14px;
    transition: all 0.3s ease;
}

.input:focus {
    outline: none;
    border-color: var(--accent-primary);
}

.select {
    background: var(--card-dark);
    border: 1px solid rgba(255,255,255,0.08);
    padding: 12px 16px;
    border-radius: 40px;
    color: var(--text-primary);
    font-size: 13px;
    cursor: pointer;
}

.header-buttons {
    display: flex;
    gap: 8px;
    align-items: center;
}

.create-reel-btn, .profile-btn {
    background: linear-gradient(135deg, #1da1f2 0%, #0d8bd9 100%);
    border: none;
    border-radius: 40px;
    padding: 10px 20px;
    color: white;
    font-weight: 600;
    font-size: 14px;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
    transition: all 0.3s ease;
    white-space: nowrap;
}

.profile-btn {
    background: rgba(255,255,255,0.1);
    backdrop-filter: blur(10px);
    padding: 6px 12px;
}

.profile-btn:hover {
    background: rgba(255,255,255,0.2);
    transform: translateY(-2px);
}

.profile-btn img {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    object-fit: cover;
}

.create-reel-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(29, 161, 242, 0.3);
}

.viewer {
    position: fixed;
    top: 90px;
    left: 50%;
    transform: translateX(-50%);
    width: 400px;
    max-width: 92vw;
    height: calc(100vh - 90px);
    border-radius: 24px;
    overflow: hidden;
    z-index: 40;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
    background: #000;
}
.indicators {
    display: none !important;
}

@media (min-width: 768px) {
    .left-sidebar {
        transform: translateX(0);
    }
    
    .sidebar-toggle {
        display: none;
    }
    
    .sidebar-close {
        display: none;
    }
}

@media (max-width: 767px) {
    .left-sidebar {
        width: 85%;
        max-width: 320px;
    }
    
    .sidebar-close {
        display: block;
    }
    
    .feed-mode-btn {
        width: 48px;
        height: 48px;
    }
    
    .feed-mode-btn i {
        font-size: 18px;
    }
    
    .feed-mode-btn span {
        font-size: 9px;
    }
}

.reel {
    position: absolute;
    inset: 0;
    border-radius: 24px;
    overflow: hidden;
    transition: transform 400ms cubic-bezier(0.2, 0.9, 0.4, 1.1), opacity 300ms ease;
    background: #000;
}

.media {
    position: absolute;
    inset: 0;
    background: #000;
    display: flex;
    align-items: center;
    justify-content: center;
}

.media video,
.media img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    background: #000;
}
.video-portrait {
    object-fit: cover !important;
}

.reel-overlay {
    position: absolute;
    inset: 0;
    background: linear-gradient(180deg, rgba(0,0,0,0.2) 0%, rgba(0,0,0,0.6) 100%);
    pointer-events: none;
}

.sound-toggle {
    position: absolute;
    top: 20px;
    left: 20px;
    width: 40px;
    height: 40px;
    background: rgba(0,0,0,0.6);
    backdrop-filter: blur(8px);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    z-index: 25;
    transition: all 0.2s;
    border: 1px solid rgba(255,255,255,0.2);
}

.sound-toggle:hover {
    transform: scale(1.1);
    background: rgba(0,0,0,0.8);
}

.sound-toggle i {
    font-size: 18px;
    color: white;
}

.info {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    padding: 24px 20px 50px 20px;
    background: linear-gradient(0deg, rgba(0,0,0,0.85) 0%, rgba(0,0,0,0) 100%);
    z-index: 20;
}

.info-content {
    display: flex;
    gap: 14px;
    align-items: flex-start;
}

.avatar-img {
    width: 52px;
    height: 52px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid rgba(255,255,255,0.2);
    cursor: pointer;
}

.author-details {
    flex: 1;
}

.author-name {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 6px;
}

.author-name a {
    color: var(--text-primary);
    text-decoration: none;
    font-weight: 700;
    font-size: 15px;
}

.verified-badge {
    color: var(--accent-primary);
    font-size: 14px;
}

.username {
    color: var(--text-secondary);
    font-size: 13px;
}

.post-date {
    color: var(--text-secondary);
    font-size: 11px;
    margin-top: 4px;
}

.caption-wrapper {
    margin-top: 12px;
}

.caption-text {
    font-size: 14px;
    line-height: 1.5;
    color: #e0e0e0;
}

.caption-container {
    font-size: 14px;
    line-height: 1.5;
    color: #e0e0e0;
}

.caption-short {
    display: inline;
}

.caption-full {
    display: none;
}

.read-more-caption {
    background: none;
    border: none;
    color: var(--accent-primary);
    font-size: 13px;
    cursor: pointer;
    margin-left: 4px;
}

.read-more-caption:hover {
    text-decoration: underline;
}

.caption-text a,
.caption-container a {
    color: var(--accent-primary);
    text-decoration: none;
}

.music-bar {
    position: absolute;
    bottom: 12px;
    left: 16px;
    right: 20px;
    background: rgba(0,0,0,0.6);
    backdrop-filter: blur(10px);
    border-radius: 30px;
    padding: 10px 16px;
    display: flex;
    align-items: center;
    gap: 10px;
    cursor: pointer;
    transition: all 0.2s;
    z-index: 15;
}

.music-bar:hover {
    background: rgba(0,0,0,0.8);
    transform: scale(1.02);
}

.music-bar i {
    color: #1da1f2;
    font-size: 14px;
}

.music-bar .music-title {
    font-size: 12px;
    font-weight: 500;
    color: white;
}

.music-bar .music-artist {
    font-size: 11px;
    color: #a0a0a8;
}

.music-wave {
    display: flex;
    align-items: center;
    gap: 2px;
    margin-left: auto;
}

.music-wave span {
    width: 3px;
    height: 12px;
    background: #1da1f2;
    border-radius: 2px;
    animation: wave 1s ease-in-out infinite;
}

.music-wave span:nth-child(1) { animation-delay: 0s; }
.music-wave span:nth-child(2) { animation-delay: 0.1s; }
.music-wave span:nth-child(3) { animation-delay: 0.2s; }
.music-wave span:nth-child(4) { animation-delay: 0.3s; }
.music-wave span:nth-child(5) { animation-delay: 0.4s; }

@keyframes wave {
    0%, 100% { height: 6px; }
    50% { height: 16px; }
}

.follow-overlay {
    position: absolute;
    bottom: -4px;
    right: -4px;
    width: 22px;
    height: 22px;
    background: var(--accent-primary);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s ease;
    border: 2px solid #000;
    z-index: 35;
}

.follow-overlay i {
    font-size: 10px;
    color: white;
}

.follow-overlay.following {
    background: transparent;
    border: 1.5px solid var(--accent-primary);
}

.follow-overlay.following i {
    color: var(--accent-primary);
}

.actions-sidebar {
    position: absolute;
    right: 12px;
    bottom: 154px;
    z-index: 30;
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.action-btn {
    width: 48px;
    height: 48px;
    background: rgba(0, 0, 0, 0.6);
    backdrop-filter: blur(8px);
    border-radius: 50%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 4px;
    cursor: pointer;
    transition: all 0.3s ease;
    border: 1px solid rgba(255,255,255,0.1);
}

.action-btn:hover {
    transform: scale(1.1);
    background: rgba(29, 161, 242, 0.8);
}

.action-btn i {
    font-size: 22px;
    color: white;
}

.action-btn .count {
    font-size: 11px;
    font-weight: 600;
    color: white;
}

.action-btn.liked i {
    color: #e74c3c;
}

.action-btn.saved i {
    color: #f9a825;
}

.action-btn.view-count {
    cursor: default;
}

.action-btn.view-count:hover {
    transform: none;
    background: rgba(0, 0, 0, 0.6);
}

.indicators {
    position: absolute;
    bottom: 20px;
    left: 20px;
    z-index: 30;
    font-size: 12px;
    color: var(--text-secondary);
    background: rgba(0,0,0,0.5);
    padding: 4px 12px;
    border-radius: 20px;
}

.empty-state {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    text-align: center;
    color: var(--text-secondary);
}

.empty-state i {
    font-size: 64px;
    margin-bottom: 16px;
    opacity: 0.5;
}

.empty-state .follow-suggestion {
    margin-top: 20px;
    background: rgba(255,255,255,0.1);
    padding: 12px 20px;
    border-radius: 30px;
    font-size: 14px;
    cursor: pointer;
    transition: all 0.2s;
}

.empty-state .follow-suggestion:hover {
    background: rgba(255,255,255,0.2);
}

.loading-indicator {
    position: fixed;
    bottom: 30px;
    left: 50%;
    transform: translateX(-50%);
    background: rgba(0,0,0,0.7);
    backdrop-filter: blur(8px);
    padding: 8px 16px;
    border-radius: 30px;
    font-size: 13px;
    z-index: 100;
    opacity: 0;
    transition: opacity 0.3s;
}

.loading-indicator.show {
    opacity: 1;
}

.comments-panel {
    position: fixed;
    top: 0;
    right: -100%;
    width: 380px;
    max-width: 85vw;
    height: 100vh;
    background: rgba(18, 18, 21, 0.98);
    backdrop-filter: blur(20px);
    z-index: 200;
    transition: right 0.3s cubic-bezier(0.2, 0.9, 0.4, 1.1);
    display: flex;
    flex-direction: column;
    border-left: 1px solid rgba(255,255,255,0.1);
}

.comments-panel.open {
    right: 0;
}

.panel-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px;
    border-bottom: 1px solid rgba(255,255,255,0.1);
}

.panel-header h3 {
    font-size: 18px;
    font-weight: 600;
}

.close-panel {
    background: rgba(255,255,255,0.1);
    border: none;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    cursor: pointer;
    transition: all 0.2s;
}

.close-panel i {
    color: white;
    font-size: 18px;
}

.close-panel:hover {
    background: rgba(255,255,255,0.2);
    transform: rotate(90deg);
}

.comments-list {
    flex: 1;
    overflow-y: auto;
    padding: 20px;
}

.comment-form {
    padding: 16px 20px;
    border-top: 1px solid rgba(255,255,255,0.1);
    display: flex;
    gap: 12px;
    background: rgba(18,18,21,0.95);
}

.comment-form input {
    flex: 1;
    background: rgba(255,255,255,0.1);
    border: none;
    border-radius: 25px;
    padding: 12px 16px;
    color: white;
    font-size: 14px;
}

.comment-form input:focus {
    outline: none;
    background: rgba(255,255,255,0.15);
}

.comment-form button {
    background: var(--accent-primary);
    border: none;
    border-radius: 25px;
    padding: 0 20px;
    color: white;
    font-weight: 600;
    cursor: pointer;
}

/* ==================== COMMENT ITEM STYLES WITH THREE-DOT MENU ==================== */
.comment-item {
    display: flex;
    gap: 10px;
    margin-bottom: 20px;
    position: relative;
    align-items: flex-start;
}

.reply-item {
    display: flex;
    gap: 8px;
    margin-bottom: 12px;
    position: relative;
    align-items: flex-start;
}

.comment-content {
    flex: 1;
}

.reply-content {
    flex: 1;
}

.comment-menu-container {
    position: absolute;
    right: 0;
    top: 0;
    z-index: 5;
    flex-shrink: 0;
}

.comment-menu-btn {
    background: none;
    border: none;
    color: #a0a0a8;
    cursor: pointer;
    padding: 5px 8px;
    font-size: 12px;
    transition: all 0.2s;
}

.comment-menu-btn:hover {
    color: var(--accent-primary);
}

.comment-dropdown {
    position: absolute;
    right: 0;
    top: 25px;
    background: #1a1a1f;
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 8px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.5);
    min-width: 100px;
    z-index: 100;
}

.comment-dropdown button {
    display: block;
    width: 100%;
    padding: 8px 12px;
    background: none;
    border: none;
    color: #ff4444;
    text-align: left;
    cursor: pointer;
    font-size: 12px;
}

.comment-dropdown button:hover {
    background: rgba(255,255,255,0.05);
}

.comment-avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    object-fit: cover;
}

.comment-author {
    font-size: 13px;
    margin-bottom: 4px;
}

.comment-author strong {
    font-weight: 700;
}

.comment-author span {
    color: #a0a0a8;
    font-weight: normal;
    font-size: 11px;
    margin-left: 8px;
}

.comment-text {
    font-size: 13px;
    line-height: 1.4;
    margin-bottom: 8px;
}

.comment-actions {
    display: flex;
    gap: 16px;
    margin-top: 6px;
}

.comment-actions button {
    background: none;
    border: none;
    color: #a0a0a8;
    font-size: 11px;
    cursor: pointer;
}

.comment-actions button:hover {
    color: #1da1f2;
}

.comment-actions button.liked {
    color: #ff4444;
}

.reply-form {
    margin-top: 8px;
    display: flex;
    gap: 8px;
}

.reply-form input {
    flex: 1;
    background: rgba(255,255,255,0.1);
    border: none;
    border-radius: 20px;
    padding: 8px 12px;
    color: white;
    font-size: 12px;
}

.reply-form button {
    background: var(--accent-primary);
    border: none;
    border-radius: 20px;
    padding: 8px 16px;
    color: white;
    font-size: 12px;
    cursor: pointer;
}

.reply-avatar {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    object-fit: cover;
}

.reply-author {
    font-size: 12px;
    margin-bottom: 2px;
}

.reply-author strong {
    font-weight: 700;
}

.reply-author span {
    color: #a0a0a8;
    font-weight: normal;
    font-size: 10px;
    margin-left: 8px;
}

.reply-text {
    font-size: 12px;
    line-height: 1.4;
    margin-bottom: 4px;
}

.reply-actions {
    display: flex;
    gap: 12px;
    margin-top: 4px;
}

.reply-actions button {
    background: none;
    border: none;
    color: #a0a0a8;
    font-size: 10px;
    cursor: pointer;
}

.replies-list {
    margin-top: 8px;
}

.child-replies-list {
    margin-left: 20px;
}

.toggle-replies, .toggle-child-replies {
    margin-left: 0px;
    margin-top: 0px;
}

.empty-comments {
    text-align: center;
    color: #a0a0a8;
    padding: 40px 20px;
}

@media (max-width: 480px) {
    .viewer {
        width: 100vw;
        height: calc(100vh - 80px);
        border-radius: 0;
        top: 80px;
    }
    .reel {
        border-radius: 0;
    }
    .header {
        width: 95%;
        flex-wrap: wrap;
        border-radius: 20px;
    }
    .header .search-section {
        flex: 1;
        min-width: 200px;
    }
    .create-reel-btn span, .profile-btn span {
        display: none;
    }
    .create-reel-btn, .profile-btn {
        padding: 10px 14px;
    }
    .actions-sidebar {
        right: 8px;
        bottom: 130px;
        gap: 24px;
    }
    .action-btn {
        width: 42px;
        height: 42px;
    }
    .avatar-img {
        width: 44px;
        height: 44px;
    }
    .sound-toggle {
        top: 12px;
        left: 12px;
        width: 36px;
        height: 36px;
    }
    .info {
        padding-bottom: 60px;
    }
    .music-bar {
        bottom: 20px;
        right: 20px;
    }
    .comments-panel {
        width: 100%;
    }
    .child-replies-list {
        margin-left: 10px;
    }
    
    .feed-mode-container {
        left: calc(50% - 50vw - 40px);
        gap: 8px;
    }
    
    .feed-mode-btn {
        width: 42px;
        height: 42px;
    }
    
    .feed-mode-btn i {
        font-size: 16px;
    }
    
    .feed-mode-btn span {
        display: none;
    }
}

@media (max-width: 380px) {
    .select {
        display: none;
    }
}


/* =====================================================
   SEARCH SIDEBAR TOGGLE + 1300PX TOP LEFT MENU FIX
   ===================================================== */
.left-sidebar{
    transform:translateX(-100%) !important;
}
.left-sidebar.open{
    transform:translateX(0) !important;
}
.sidebar-toggle{
    display:flex !important;
}
.sidebar-close{
    display:block !important;
}

.reels-responsive-menu{
    display:none;
}

@media (min-width:1301px){
    .sidebar-toggle{
        display:flex !important;
    }
    .feed-mode-container{
        display:flex !important;
    }
}

@media (max-width:1300px){
    .sidebar-toggle,
    .feed-mode-container{
        display:none !important;
    }

    .reels-responsive-menu{
        position:fixed;
        top:14px;
        left:14px;
        z-index:1000;
        display:flex;
        align-items:flex-start;
        gap:8px;
    }

    .reels-menu-arrow{
        width:42px;
        height:42px;
        border-radius:50%;
        border:1px solid rgba(255,255,255,.16);
        background:rgba(18,18,21,.92);
        color:#fff;
        display:flex;
        align-items:center;
        justify-content:center;
        cursor:pointer;
        backdrop-filter:blur(14px);
        box-shadow:0 8px 22px rgba(0,0,0,.28);
        transition:all .25s ease;
    }

    .reels-menu-arrow i{
        font-size:15px;
        transition:transform .25s ease;
    }

    .reels-responsive-menu.open .reels-menu-arrow i{
        transform:rotate(90deg);
    }

    .reels-menu-dropdown{
        display:none;
        flex-direction:column;
        gap:8px;
        padding:8px;
        border-radius:18px;
        background:rgba(18,18,21,.94);
        border:1px solid rgba(255,255,255,.12);
        backdrop-filter:blur(18px);
        box-shadow:0 14px 34px rgba(0,0,0,.35);
    }

    .reels-responsive-menu.open .reels-menu-dropdown{
        display:flex;
    }

    .reels-menu-item{
        width:46px;
        height:46px;
        border-radius:50%;
        border:1px solid rgba(255,255,255,.10);
        background:rgba(255,255,255,.07);
        color:#d9d9df;
        display:flex;
        flex-direction:column;
        align-items:center;
        justify-content:center;
        gap:2px;
        cursor:pointer;
        transition:all .22s ease;
    }

    .reels-menu-item i{
        font-size:15px;
    }

    .reels-menu-item span{
        font-size:7px;
        font-weight:700;
        line-height:1;
    }

    .reels-menu-item:hover,
    .reels-menu-item.active{
        background:#1da1f2;
        color:#fff;
        border-color:#1da1f2;
    }

    .left-sidebar{
        width:300px;
        max-width:86vw;
        z-index:1200 !important;
    }
}

@media (max-width:480px){
    .reels-responsive-menu{
        top:10px;
        left:10px;
    }
    .reels-menu-arrow{
        width:38px;
        height:38px;
    }
    .reels-menu-item{
        width:42px;
        height:42px;
    }
}

</style>
</head>
<body>
<!-- Responsive Top Left Menu for Reels Controls -->
<div class="reels-responsive-menu" id="reelsResponsiveMenu">
    <button type="button" class="reels-menu-arrow" id="reelsMenuArrow" aria-label="Open reels menu">
        <i class="fas fa-chevron-right"></i>
    </button>
    <div class="reels-menu-dropdown" id="reelsMenuDropdown">
        <button type="button" class="reels-menu-item" id="reelsMenuSearch" title="Search Profiles">
            <i class="fas fa-search"></i>
            <span>Search</span>
        </button>
        <button type="button" class="reels-menu-item <?php echo $currentFeedMode === 'personalized' ? 'active' : ''; ?>" data-menu-mode="personalized" title="For You">
            <i class="fas fa-magic"></i>
            <span>For You</span>
        </button>
        <button type="button" class="reels-menu-item <?php echo $currentFeedMode === 'following' ? 'active' : ''; ?>" data-menu-mode="following" title="Following">
            <i class="fas fa-user-friends"></i>
            <span>Following</span>
        </button>
    </div>
</div>


<!-- ==================== FEED MODE BUTTONS - STUCK TO LEFT EDGE OF REEL VIEWER ==================== -->
<div class="feed-mode-container">
    <button class="feed-mode-btn <?php echo $currentFeedMode === 'personalized' ? 'active' : ''; ?>" data-mode="personalized" title="For You">
        <i class="fas fa-magic"></i>
        <span>For You</span>
    </button>
    <button class="feed-mode-btn <?php echo $currentFeedMode === 'following' ? 'active' : ''; ?>" data-mode="following" title="Following">
        <i class="fas fa-user-friends"></i>
        <span>Following</span>
    </button>
</div>

<!-- Sidebar Toggle Button -->
<div class="sidebar-toggle" id="sidebarToggle">
    <i class="fas fa-search"></i>
</div>

<!-- Left Sidebar with Search -->
<div class="left-sidebar" id="leftSidebar">
    <button class="sidebar-close" id="sidebarClose">
        <i class="fas fa-times"></i>
    </button>
    <div class="sidebar-header">
        <h3><i class="fas fa-search"></i> Search Users</h3>
        <div class="sidebar-search">
            <i class="fas fa-search"></i>
            <input type="text" id="sidebarSearchInput" placeholder="Search by name or username...">
        </div>
    </div>
    <div class="search-results" id="sidebarSearchResults">
        <div style="text-align: center; padding: 20px; color: var(--text-secondary);">
            <i class="fas fa-search" style="font-size: 24px; margin-bottom: 10px; opacity: 0.5;"></i>
            <p>Search for users to view their reels</p>
        </div>
    </div>
</div>

<div class="header">
    <div class="search-section">
        <div class="input-group">
            <i class="fas fa-search input-icon"></i>
            <input name="search" id="searchInput" class="input" placeholder="Search reels by hashtag..." value="">
        </div>
        <select name="type" id="typeSelect" class="select">
            <option value="">All</option>
            <option value="video">Videos</option>
            <option value="image">Images</option>
        </select>
    </div>
    <div class="header-buttons">
        <a href="create_reel.php" class="create-reel-btn">
            <i class="fas fa-plus"></i>
            <span>Create</span>
        </a>
        <a href="prof.php?username=<?php echo htmlspecialchars($currentUser->username); ?>" class="profile-btn">
            <img src="assets/images/users/<?php echo htmlspecialchars($currentUser->img); ?>" alt="Profile">
            <span>Profile</span>
        </a>
    </div>
</div>

<div class="viewer" id="viewer">
    <?php if (empty($initialReels)): ?>
        <?php if ($currentFeedMode === 'following' && $followingCount == 0): ?>
            <div class="empty-state">
                <i class="fas fa-user-friends"></i>
                <h4>No Following Feed</h4>
                <p>You're not following anyone yet!</p>
                <div class="follow-suggestion" onclick="window.location.href='explore.php'">
                    <i class="fas fa-compass"></i> Explore & Follow Users
                </div>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-film"></i>
                <h4>No Reels Found</h4>
                <p>Be the first to create a reel!</p>
                <a href="create_reel.php" style="margin-top: 16px; display: inline-block; background: #1da1f2; padding: 10px 20px; border-radius: 30px; color: white; text-decoration: none;">Create Reel</a>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <?php echo renderReelsHTML($initialReels, $viewer_id, $followMap); ?>
    <?php endif; ?>
</div>

<div class="comments-panel" id="commentsPanel">
    <div class="panel-header">
        <h3>Comments</h3>
        <button class="close-panel" id="closeCommentsPanel">
            <i class="fas fa-times"></i>
        </button>
    </div>
    <div class="comments-list" id="commentsList">
        <div class="empty-comments">Select a video to view comments</div>
    </div>
    <form class="comment-form" id="commentForm">
        <input type="text" id="commentInput" placeholder="Add a comment..." required>
        <button type="submit">Post</button>
    </form>
</div>

<div class="loading-indicator" id="loadingIndicator">Loading more...</div>

<script>
// ==================== Global Variables ====================
let reels = Array.from(document.querySelectorAll('.reel'));
let current = <?php echo $startIndexJS; ?>;
let viewer = document.getElementById('viewer');
let viewedReelIds = <?php echo json_encode($exclude_ids); ?>;
let isLoading = false;
let currentFilter = 'all';
let currentSearch = '';
let currentFeedMode = '<?php echo $currentFeedMode; ?>';
let watchStartTimes = {};
let watchIntervals = {};
let currentReelId = null;

// Media & Sync Variables
let currentMusicAudio = null;
let currentMusicReelId = null;
let isGlobalMuted = false;
let musicStartTime = 0;
let musicEndTime = null;
let lastVideoTime = 0;

// User mode tracking
let isUserMode = <?php echo $isUserModeJS; ?>;

// Following count for empty state
let followingCount = <?php echo $followingCount; ?>;

// Sidebar elements
const sidebarToggle = document.getElementById('sidebarToggle');
const leftSidebar = document.getElementById('leftSidebar');
const sidebarClose = document.getElementById('sidebarClose');
const sidebarSearchInput = document.getElementById('sidebarSearchInput');
const sidebarSearchResults = document.getElementById('sidebarSearchResults');

let searchTimeout2 = null;

// ==================== Responsive Top Left Reels Menu ====================
const reelsResponsiveMenu = document.getElementById('reelsResponsiveMenu');
const reelsMenuArrow = document.getElementById('reelsMenuArrow');
const reelsMenuSearch = document.getElementById('reelsMenuSearch');

function toggleSidebar(){
    if(leftSidebar.classList.contains('open')){
        closeSidebar();
    }else{
        openSidebar();
    }
}

if(sidebarToggle){
    sidebarToggle.addEventListener('click', function(e){
        e.stopPropagation();
        toggleSidebar();
    });
}

if(reelsMenuArrow){
    reelsMenuArrow.addEventListener('click', function(e){
        e.stopPropagation();
        reelsResponsiveMenu.classList.toggle('open');
    });
}

if(reelsMenuSearch){
    reelsMenuSearch.addEventListener('click', function(e){
        e.stopPropagation();
        openSidebar();
        if(reelsResponsiveMenu) reelsResponsiveMenu.classList.remove('open');
        setTimeout(function(){
            if(sidebarSearchInput) sidebarSearchInput.focus();
        }, 250);
    });
}

document.querySelectorAll('[data-menu-mode]').forEach(function(btn){
    btn.addEventListener('click', function(e){
        e.stopPropagation();
        switchFeedMode(btn.dataset.menuMode);
        if(reelsResponsiveMenu) reelsResponsiveMenu.classList.remove('open');
    });
});

document.addEventListener('click', function(e){
    if(reelsResponsiveMenu && reelsResponsiveMenu.classList.contains('open') && !reelsResponsiveMenu.contains(e.target)){
        reelsResponsiveMenu.classList.remove('open');
    }
    if(leftSidebar && leftSidebar.classList.contains('open') && !leftSidebar.contains(e.target)){
        const clickedSidebarToggle = sidebarToggle && sidebarToggle.contains(e.target);
        const clickedMenuSearch = reelsMenuSearch && reelsMenuSearch.contains(e.target);
        if(!clickedSidebarToggle && !clickedMenuSearch){
            closeSidebar();
        }
    }
});


// ==================== Feed Mode Switching ====================
async function switchFeedMode(mode) {
    if (mode === currentFeedMode) return;
    if (isUserMode) return;
    
    currentFeedMode = mode;
    isLoading = true;
    document.getElementById('loadingIndicator').classList.add('show');
    
    // Update URL without reload
    const url = new URL(window.location.href);
    url.searchParams.set('feed', mode);
    window.history.pushState({}, '', url);
    
    try {
        const response = await fetch(window.location.pathname, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                'refresh_reels': 1,
                'filter': currentFilter,
                'search': currentSearch,
                'feed_mode': mode
            })
        });
        
        const data = await response.json();
        
        if (data.reels) {
            viewer.innerHTML = data.reels;
            viewedReelIds = data.new_ids || [];
            reels = Array.from(document.querySelectorAll('.reel'));
            current = 0;
            updatePositions();
            attachReelEventListeners();
            initSoundToggles();
        } else {
            // Show empty state for following feed with no content
            if (mode === 'following' && followingCount === 0) {
                viewer.innerHTML = `
                    <div class="empty-state">
                        <i class="fas fa-user-friends"></i>
                        <h4>No Following Feed</h4>
                        <p>You're not following anyone yet!</p>
                        <div class="follow-suggestion" onclick="window.location.href='explore.php'">
                            <i class="fas fa-compass"></i> Explore & Follow Users
                        </div>
                    </div>
                `;
                reels = [];
            } else {
                viewer.innerHTML = '<div class="empty-state"><i class="fas fa-film"></i><h4>No Reels Found</h4><p>Try different filters or search hashtags</p></div>';
                reels = [];
            }
        }
    } catch (error) {
        console.error('Error switching feed mode:', error);
    }
    
    isLoading = false;
    document.getElementById('loadingIndicator').classList.remove('show');
    
    // Update active button state
    document.querySelectorAll('.feed-mode-btn').forEach(btn => {
        if (btn.dataset.mode === mode) {
            btn.classList.add('active');
        } else {
            btn.classList.remove('active');
        }
    });
    document.querySelectorAll('[data-menu-mode]').forEach(btn => {
        if (btn.dataset.menuMode === mode) {
            btn.classList.add('active');
        } else {
            btn.classList.remove('active');
        }
    });
}

// Feed mode button listeners
document.querySelectorAll('.feed-mode-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        switchFeedMode(btn.dataset.mode);
    });
});

// ==================== Sidebar Functions ====================
function openSidebar() {
    leftSidebar.classList.add('open');
}

function closeSidebar() {
    leftSidebar.classList.remove('open');
}

// sidebar toggle handled by responsive menu script
if (sidebarClose) {
    sidebarClose.addEventListener('click', closeSidebar);
}

// Close sidebar when clicking outside on mobile
document.addEventListener('click', (e) => {
    if (window.innerWidth <= 768 && leftSidebar.classList.contains('open')) {
        if (!leftSidebar.contains(e.target) && !sidebarToggle.contains(e.target)) {
            closeSidebar();
        }
    }
});

// Search users in sidebar
async function searchUsers(searchTerm) {
    if (!searchTerm.trim()) {
        sidebarSearchResults.innerHTML = `
            <div style="text-align: center; padding: 20px; color: var(--text-secondary);">
                <i class="fas fa-search" style="font-size: 24px; margin-bottom: 10px; opacity: 0.5;"></i>
                <p>Search for users to view their reels</p>
            </div>
        `;
        return;
    }
    
    sidebarSearchResults.innerHTML = `
        <div style="text-align: center; padding: 20px; color: var(--text-secondary);">
            <i class="fas fa-spinner fa-spin"></i> Searching...
        </div>
    `;
    
    try {
        const response = await fetch(window.location.pathname, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ 'search_users': 1, 'query': searchTerm })
        });
        const data = await response.json();
        
        if (data.users && data.users.length > 0) {
            let html = '';
            data.users.forEach(user => {
                html += `
                    <div class="search-result-item" data-user-id="${user.id}" data-username="${user.username}">
                        <img class="search-result-avatar" src="assets/images/users/${user.img}" alt="${user.name}">
                        <div class="search-result-info">
                            <div class="search-result-name">${escapeHtml(user.name)}</div>
                            <div class="search-result-username">@${escapeHtml(user.username)}</div>
                        </div>
                        ${user.is_verified ? '<i class="fas fa-check-circle search-result-badge"></i>' : ''}
                    </div>
                `;
            });
            sidebarSearchResults.innerHTML = html;
            
           // Add click handlers to search results
document.querySelectorAll('.search-result-item').forEach(item => {
    item.addEventListener('click', () => {
        const username = item.dataset.username;
        // This will take you to the user's profile page
        window.location.href = `prof.php?username=${username}`;
    });
});
        } else {
            sidebarSearchResults.innerHTML = `
                <div style="text-align: center; padding: 20px; color: var(--text-secondary);">
                    <i class="fas fa-user-slash" style="font-size: 24px; margin-bottom: 10px; opacity: 0.5;"></i>
                    <p>No users found</p>
                </div>
            `;
        }
    } catch (error) {
        console.error('Search error:', error);
        sidebarSearchResults.innerHTML = `
            <div style="text-align: center; padding: 20px; color: var(--text-secondary);">
                <i class="fas fa-exclamation-triangle" style="font-size: 24px; margin-bottom: 10px;"></i>
                <p>Error searching users</p>
            </div>
        `;
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Debounced search
sidebarSearchInput.addEventListener('input', () => {
    clearTimeout(searchTimeout2);
    searchTimeout2 = setTimeout(() => {
        searchUsers(sidebarSearchInput.value);
    }, 500);
});

// ==================== Real-time Time Updates ====================
function updateAllTimes() {
    document.querySelectorAll('.post-date[data-time], .comment-time[data-time], .reply-time[data-time]').forEach(el => {
        const timeStr = el.getAttribute('data-time');
        if (timeStr) {
            const timeAgo = getTimeAgo(timeStr);
            el.textContent = timeAgo;
        }
    });
}

function getTimeAgo(timeStr) {
    const time = new Date(timeStr).getTime();
    const now = new Date().getTime();
    const diff = Math.floor((now - time) / 1000);
    
    if (diff < 60) return "just now";
    if (diff < 3600) return Math.floor(diff / 60) + "m";
    if (diff < 86400) return Math.floor(diff / 3600) + "h";
    return Math.floor(diff / 86400) + "d";
}

setInterval(updateAllTimes, 60000);

// ==================== Swipe to Change Reel ====================
let touchStartY = 0;
let touchEndY = 0;
let isSwiping = false;

const swipeUpIndicator = document.createElement('div');
swipeUpIndicator.className = 'swipe-indicator up';
swipeUpIndicator.innerHTML = '<i class="fas fa-chevron-up"></i> Swipe up for next';
const swipeDownIndicator = document.createElement('div');
swipeDownIndicator.className = 'swipe-indicator down';
swipeDownIndicator.innerHTML = '<i class="fas fa-chevron-down"></i> Swipe down for previous';

viewer.addEventListener('touchstart', (e) => {
    touchStartY = e.touches[0].clientY;
    isSwiping = true;
});

viewer.addEventListener('touchmove', (e) => {
    if (!isSwiping) return;
    const currentY = e.touches[0].clientY;
    const diff = currentY - touchStartY;
    
    if (Math.abs(diff) > 30) {
        if (diff > 0) {
            swipeDownIndicator.style.opacity = '0.7';
            swipeUpIndicator.style.opacity = '0';
        } else {
            swipeUpIndicator.style.opacity = '0.7';
            swipeDownIndicator.style.opacity = '0';
        }
    }
});

viewer.addEventListener('touchend', (e) => {
    touchEndY = e.changedTouches[0].clientY;
    const swipeDistance = touchEndY - touchStartY;
    
    swipeUpIndicator.style.opacity = '0';
    swipeDownIndicator.style.opacity = '0';
    
    if (Math.abs(swipeDistance) > 50) {
        if (swipeDistance > 0) {
            prevReel();
        } else {
            nextReel();
        }
    }
    
    isSwiping = false;
    touchStartY = 0;
    touchEndY = 0;
});

viewer.parentElement.appendChild(swipeUpIndicator);
viewer.parentElement.appendChild(swipeDownIndicator);

let wheelTimeout;
viewer.addEventListener('wheel', (e) => {
    e.preventDefault();
    if (wheelTimeout) return;
    
    if (e.deltaY > 0) {
        nextReel();
        swipeUpIndicator.style.opacity = '0.5';
        setTimeout(() => { swipeUpIndicator.style.opacity = '0'; }, 300);
    } else if (e.deltaY < 0) {
        prevReel();
        swipeDownIndicator.style.opacity = '0.5';
        setTimeout(() => { swipeDownIndicator.style.opacity = '0'; }, 300);
    }
    
    wheelTimeout = setTimeout(() => {
        wheelTimeout = null;
    }, 500);
}, { passive: false });

// ==================== Unified Media Controller (Sync & Mute) ====================

function updateSoundIcons() {
    document.querySelectorAll('.sound-toggle i').forEach(icon => {
        if (isGlobalMuted) {
            icon.classList.remove('fa-volume-up');
            icon.classList.add('fa-volume-mute');
        } else {
            icon.classList.remove('fa-volume-mute');
            icon.classList.add('fa-volume-up');
        }
    });
}

function toggleGlobalMute() {
    isGlobalMuted = !isGlobalMuted;
    
    if (currentMusicAudio) {
        currentMusicAudio.muted = isGlobalMuted;
    }
    
    const currentVideo = reels[current]?.querySelector('video');
    if (currentVideo) {
        currentVideo.muted = isGlobalMuted;
    }
    
    updateSoundIcons();
}

function syncPlayback(play = true) {
    const currentVideo = reels[current]?.querySelector('video');
    
    if (play) {
        if (currentVideo) currentVideo.play().catch(() => {});
        if (currentMusicAudio) {
            if (currentVideo) {
                const targetTime = musicStartTime + currentVideo.currentTime;
                if (Math.abs(currentMusicAudio.currentTime - targetTime) > 0.5) {
                    currentMusicAudio.currentTime = targetTime;
                }
            }
            currentMusicAudio.play().catch(() => {});
        }
    } else {
        if (currentVideo) currentVideo.pause();
        if (currentMusicAudio) currentMusicAudio.pause();
    }
}

function syncLoopHandler() {
    const video = this;
    const reelId = video.closest('.reel')?.dataset.reelId;
    
    if (currentMusicAudio && currentMusicReelId === reelId) {
        if (video.currentTime < 0.1 && lastVideoTime > video.duration - 0.1) {
            currentMusicAudio.currentTime = musicStartTime;
        } 
        else if (video.currentTime < 0.1 && lastVideoTime > 0.5) {
            currentMusicAudio.currentTime = musicStartTime;
        }
        else if (!video.paused && currentMusicAudio.paused) {
            currentMusicAudio.play().catch(() => {});
        }
        else if (video.paused && !currentMusicAudio.paused) {
            currentMusicAudio.pause();
        }
        
        lastVideoTime = video.currentTime;
    }
}

function playMusicForReel(reelId, musicUrl, startTime, endTime) {
    if (currentMusicAudio) {
        currentMusicAudio.pause();
        currentMusicAudio = null;
    }
    
    if (!musicUrl || musicUrl === '') {
        currentMusicReelId = null;
        return;
    }
    
    console.log('Playing music for reel:', reelId, 'URL:', musicUrl);
    
    currentMusicAudio = new Audio(musicUrl);
    currentMusicReelId = reelId;
    musicStartTime = parseFloat(startTime) || 0;
    musicEndTime = endTime ? parseFloat(endTime) : null;
    
    currentMusicAudio.muted = isGlobalMuted;
    currentMusicAudio.currentTime = musicStartTime;
    
    currentMusicAudio.onerror = function() {
        console.error('Error loading music:', musicUrl);
        currentMusicAudio = null;
        currentMusicReelId = null;
    };
    
    if (musicEndTime && musicEndTime > 0) {
        currentMusicAudio.addEventListener('timeupdate', function onUpdate() {
            if (currentMusicAudio && currentMusicAudio.currentTime >= musicEndTime) {
                currentMusicAudio.currentTime = musicStartTime;
            }
        });
    }
    
    currentMusicAudio.addEventListener('ended', () => {
        if (musicEndTime && musicEndTime > 0) {
            currentMusicAudio.currentTime = musicStartTime;
            currentMusicAudio.play().catch(() => {});
        } else {
            currentMusicAudio = null;
            currentMusicReelId = null;
        }
    });
    
    const currentVideo = reels[current]?.querySelector('video');
   if (!currentVideo || !currentVideo.paused) {
        currentMusicAudio.play().catch(error => {
            console.log("Autoplay prevented. Music will start on user interaction.");
        });
    }
    updateSoundIcons();
}

function stopMusic() {
    if (currentMusicAudio) {
        currentMusicAudio.pause();
        currentMusicAudio = null;
        currentMusicReelId = null;
    }
    lastVideoTime = 0;
}

function restartMusic() {
    if (currentMusicAudio && currentMusicReelId) {
        currentMusicAudio.currentTime = musicStartTime;
        const currentVideo = reels[current]?.querySelector('video');
        if (currentVideo && !currentVideo.paused) {
            currentMusicAudio.play().catch(() => {});
        }
    }
}

// ==================== Watch Time Tracking ====================
function startWatchTracking(reelId) {
    if (watchIntervals[reelId]) clearTimeout(watchIntervals[reelId]);
    watchStartTimes[reelId] = Date.now();
    
    watchIntervals[reelId] = setTimeout(() => {
        sendViewTracking(reelId);
    }, 3000);
}

function stopWatchTracking(reelId) {
    if (watchIntervals[reelId]) {
        clearTimeout(watchIntervals[reelId]);
        delete watchIntervals[reelId];
    }
    if (watchStartTimes[reelId]) {
        delete watchStartTimes[reelId];
    }
}

function sendViewTracking(reelId) {
    fetch(window.location.pathname, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ 'track_reel_view': 1, 'reel_id': reelId })
    }).catch(() => {});
}

// ==================== Reel Navigation ====================
function updatePositions() {
    reels = Array.from(document.querySelectorAll('.reel'));
    reels.forEach((el, idx) => {
        el.style.transform = `translateY(${(idx - current) * 100}%)`;
        el.style.opacity = idx === current ? '1' : '0.95';
        
        const video = el.querySelector('video');
        const reelId = el.dataset.reelId;
        
        if (idx === current) {
            if (video) {
                video.currentTime = 0;
                video.muted = isGlobalMuted;
                video.removeEventListener('timeupdate', syncLoopHandler);
                video.addEventListener('timeupdate', syncLoopHandler);
                lastVideoTime = 0;
                video.removeEventListener('ended', videoEndedHandler);
                video.addEventListener('ended', videoEndedHandler);
            }
            
            const musicBar = el.querySelector('.music-bar');
            if (musicBar && musicBar.dataset.musicUrl) {
                playMusicForReel(
                    reelId, 
                    musicBar.dataset.musicUrl, 
                    musicBar.dataset.start, 
                    musicBar.dataset.end
                );
            } else {
                stopMusic();
            }
            
            if (video && video.paused) {
                video.play().catch(() => {});
            }
            
            startWatchTracking(reelId);
        } else {
            if (video) {
                video.pause();
                video.removeEventListener('timeupdate', syncLoopHandler);
                video.removeEventListener('ended', videoEndedHandler);
            }
            if (idx !== current && reelId === currentMusicReelId) {
                stopMusic();
            }
            stopWatchTracking(reelId);
        }
    });
    
    const indicators = document.querySelectorAll('.indicators .current-index');
    indicators.forEach((el, idx) => {
        if (idx === current) {
            el.textContent = current + 1;
        }
    });
}

function videoEndedHandler() {
    const currentVideo = reels[current]?.querySelector('video');
    const reelId = reels[current]?.dataset.reelId;
    
    if (currentVideo) {
        console.log('Video ended, restarting from beginning');
        currentVideo.currentTime = 0;
        currentVideo.play().catch(() => {});
        
        if (currentMusicAudio && currentMusicReelId === reelId) {
            currentMusicAudio.currentTime = musicStartTime;
            currentMusicAudio.play().catch(() => {});
        }
        
        lastVideoTime = 0;
    }
}

function nextReel() {
    if (current < reels.length - 1) {
        stopMusic();
        current++;
        updatePositions();
        
        if (!isUserMode && current >= reels.length - 3 && currentFeedMode !== 'following') {
            loadMoreReels();
        }
    } else {
        if (isUserMode) {
            showToast("You've reached the end");
        } else if (currentFeedMode === 'following' && current >= reels.length - 1) {
            showToast("No more reels from people you follow");
        }
    }
}

function prevReel() {
    if (current > 0) {
        stopMusic();
        current--;
        updatePositions();
    } else {
        if (isUserMode) {
            showToast("You're at the first reel");
        }
    }
}

// ==================== Load More Reels ====================
async function loadMoreReels() {
    if (isLoading) return;
    if (isUserMode) return;
    if (currentFeedMode === 'following' && current >= reels.length - 1) return;
    
    isLoading = true;
    document.getElementById('loadingIndicator').classList.add('show');
    
    try {
        const response = await fetch(window.location.pathname, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                'load_more': 1,
                'exclude_ids': JSON.stringify(viewedReelIds),
                'filter': currentFilter,
                'search': currentSearch,
                'feed_mode': currentFeedMode
            })
        });
        
        const data = await response.json();
        
        if (!data.end && data.reels) {
            viewer.insertAdjacentHTML('beforeend', data.reels);
            // ADD THIS LINE HERE:
    viewer.querySelectorAll('video').forEach(v => applySmartFit(v));
            if (data.new_ids) viewedReelIds.push(...data.new_ids);
            reels = Array.from(document.querySelectorAll('.reel'));
            updatePositions();
            attachReelEventListeners();
            initSoundToggles();
        }
    } catch (error) {
        console.error('Error loading reels:', error);
    }
    
    isLoading = false;
    document.getElementById('loadingIndicator').classList.remove('show');
}

// ==================== Refresh Reels ====================
async function refreshReels() {
    isLoading = true;
    document.getElementById('loadingIndicator').classList.add('show');
    
    try {
        const response = await fetch(window.location.pathname, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                'refresh_reels': 1,
                'filter': currentFilter,
                'search': currentSearch,
                'feed_mode': currentFeedMode
            })
        });
        
        const data = await response.json();
        
        if (data.reels) {
            viewer.innerHTML = data.reels;
            // ADD THIS LINE HERE:
    viewer.querySelectorAll('video').forEach(v => applySmartFit(v));

            viewedReelIds = data.new_ids || [];
            reels = Array.from(document.querySelectorAll('.reel'));
            current = 0;
            updatePositions();
            attachReelEventListeners();
            initSoundToggles();
        }
    } catch (error) {
        console.error('Error refreshing reels:', error);
    }
    
    isLoading = false;
    document.getElementById('loadingIndicator').classList.remove('show');
}

// ==================== Like Reel ====================
async function likeReel(btn, reelId) {
    const isLiked = btn.classList.contains('liked');
    const icon = btn.querySelector('i');
    const countSpan = btn.querySelector('.count');
    
    try {
        const response = await fetch(window.location.pathname, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ 'like_reel': 1, 'reel_id': reelId })
        });
        const data = await response.json();
        
        if (data.success) {
            if (data.liked) {
                btn.classList.add('liked');
                icon.classList.remove('far');
                icon.classList.add('fas');
            } else {
                btn.classList.remove('liked');
                icon.classList.remove('fas');
                icon.classList.add('far');
            }
            countSpan.textContent = data.like_count;
        }
    } catch (error) {
        console.error('Like error:', error);
    }
}

// ==================== Save Reel ====================
async function saveReel(btn, reelId) {
    const isSaved = btn.classList.contains('saved');
    const icon = btn.querySelector('i');
    const countSpan = btn.querySelector('.count');
    
    try {
        const response = await fetch(window.location.pathname, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ 'save_reel': 1, 'reel_id': reelId })
        });
        const data = await response.json();
        
        if (data.success) {
            if (data.saved) {
                btn.classList.add('saved');
                icon.classList.remove('far');
                icon.classList.add('fas');
            } else {
                btn.classList.remove('saved');
                icon.classList.remove('fas');
                icon.classList.add('far');
            }
            countSpan.textContent = data.save_count;
            showToast(data.saved ? 'Saved to collection' : 'Removed from saves');
        }
    } catch (error) {
        console.error('Save error:', error);
    }
}

// ==================== Follow ====================
async function followUser(btn, userId) {
    const isFollowing = btn.classList.contains('following');
    const formData = new FormData();
    formData.append('follow_action', isFollowing ? 'unfollow' : 'follow');
    formData.append('user_id', userId);
    
    try {
        const response = await fetch(window.location.pathname, { method: 'POST', body: formData });
        const data = await response.json();
        if (data.ok) {
            if (data.following) {
                btn.classList.add('following');
                btn.innerHTML = '<i class="fas fa-check"></i>';
                followingCount++;
            } else {
                btn.classList.remove('following');
                btn.innerHTML = '<i class="fas fa-plus"></i>';
                followingCount--;
            }
        }
    } catch (error) {
        console.error('Follow error:', error);
    }
}

// ==================== Share ====================
function shareReel(reelId, userId) {
    const fullUrl = window.location.origin + '/TwitterClone/reels.php?reel=' + reelId + '&user=' + userId;
    if (navigator.share) {
        navigator.share({ title: 'Check this reel!', url: fullUrl }).catch(() => copyToClipboard(fullUrl));
    } else {
        copyToClipboard(fullUrl);
    }
}

function copyToClipboard(text) {
    navigator.clipboard.writeText(text);
    showToast('Link copied!');
}

function showToast(message) {
    const toast = document.createElement('div');
    toast.textContent = message;
    toast.style.cssText = 'position:fixed;bottom:100px;left:50%;transform:translateX(-50%);background:rgba(0,0,0,0.8);color:white;padding:8px 16px;border-radius:30px;z-index:2000;font-size:13px';
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 2000);
}

// ==================== Comments Panel ====================
const commentsPanel = document.getElementById('commentsPanel');
const commentsList = document.getElementById('commentsList');
const commentForm = document.getElementById('commentForm');
const commentInput = document.getElementById('commentInput');

async function openCommentsPanel(reelId) {
    currentReelId = reelId;
    commentForm.dataset.reelId = reelId;
    commentsPanel.classList.add('open');
    
    commentsList.innerHTML = '<div class="empty-comments">Loading comments...</div>';
    
    try {
        const response = await fetch(window.location.pathname, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ 'get_comments': 1, 'reel_id': reelId })
        });
        const data = await response.json();
        commentsList.innerHTML = data.html;
        attachCommentEventListeners();
        updateAllTimes();
    } catch (error) {
        commentsList.innerHTML = '<div class="empty-comments">Failed to load comments</div>';
    }
}

function closeCommentsPanel() {
    commentsPanel.classList.remove('open');
    currentReelId = null;
}

document.getElementById('closeCommentsPanel').addEventListener('click', closeCommentsPanel);

commentForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const reelId = commentForm.dataset.reelId;
    const comment = commentInput.value.trim();
    
    if (!comment || !reelId) return;
    
    try {
        const response = await fetch(window.location.pathname, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ 'add_comment': 1, 'reel_id': reelId, 'comment': comment })
        });
        const data = await response.json();
        
        if (data.success) {
            commentInput.value = '';
            const newComment = data.html;
            commentsList.insertAdjacentHTML('afterbegin', newComment);
            attachCommentEventListeners();
            updateAllTimes();
            
            const commentBtn = document.querySelector(`.comment-btn[data-reel="${reelId}"]`);
            if (commentBtn) {
                const countSpan = commentBtn.querySelector('.count');
                countSpan.textContent = parseInt(countSpan.textContent) + 1;
            }
        }
    } catch (error) {
        console.error('Comment error:', error);
    }
});

// ==================== Comment Event Handlers ====================
function attachCommentEventListeners() {
    document.querySelectorAll('.like-comment').forEach(btn => {
        btn.removeEventListener('click', handleCommentLike);
        btn.addEventListener('click', handleCommentLike);
    });
    
    document.querySelectorAll('.like-reply').forEach(btn => {
        btn.removeEventListener('click', handleReplyLike);
        btn.addEventListener('click', handleReplyLike);
    });
    
    document.querySelectorAll('.reply-to-comment').forEach(btn => {
        btn.removeEventListener('click', showReplyForm);
        btn.addEventListener('click', showReplyForm);
    });
    
    document.querySelectorAll('.reply-to-reply').forEach(btn => {
        btn.removeEventListener('click', showReplyToReplyForm);
        btn.addEventListener('click', showReplyToReplyForm);
    });
    
    document.querySelectorAll('.submit-reply').forEach(btn => {
        btn.removeEventListener('click', submitReply);
        btn.addEventListener('click', submitReply);
    });
    
    document.querySelectorAll('.toggle-replies').forEach(btn => {
        btn.removeEventListener('click', toggleReplies);
        btn.addEventListener('click', toggleReplies);
    });
    
    document.querySelectorAll('.toggle-child-replies').forEach(btn => {
        btn.removeEventListener('click', toggleChildReplies);
        btn.addEventListener('click', toggleChildReplies);
    });
    
    // ==================== DELETE COMMENT & REPLY LISTENERS ====================
    // Toggle comment menu
    document.querySelectorAll('.comment-menu-btn').forEach(btn => {
        btn.removeEventListener('click', toggleCommentMenu);
        btn.addEventListener('click', toggleCommentMenu);
    });
    
    // Delete comment action
    document.querySelectorAll('.delete-comment-action').forEach(btn => {
        btn.removeEventListener('click', deleteCommentHandler);
        btn.addEventListener('click', deleteCommentHandler);
    });
    
    // Delete reply action
    document.querySelectorAll('.delete-reply-action').forEach(btn => {
        btn.removeEventListener('click', deleteReplyHandler);
        btn.addEventListener('click', deleteReplyHandler);
    });
}

// Toggle comment dropdown menu
function toggleCommentMenu(e) {
    e.stopPropagation();
    const dropdown = this.nextElementSibling;
    // Close all other dropdowns
    document.querySelectorAll('.comment-dropdown').forEach(d => {
        if (d !== dropdown) d.style.display = 'none';
    });
    dropdown.style.display = dropdown.style.display === 'none' ? 'block' : 'none';
}

// Delete comment handler
async function deleteCommentHandler(e) {
    e.stopPropagation();
    if (!confirm("Delete this comment and ALL its replies?")) return;
    
    const commentId = this.dataset.id;
    try {
        const response = await fetch(window.location.pathname, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ 'delete_comment': 1, 'comment_id': commentId })
        });
        const data = await response.json();
        
        if (data.success) {
            const commentElement = document.querySelector(`.comment-item[data-comment-id="${commentId}"]`);
            if (commentElement) commentElement.remove();
            showToast("Comment deleted");
        } else {
            showToast("Error deleting comment");
        }
    } catch (error) {
        console.error('Delete comment error:', error);
        showToast("Error deleting comment");
    }
}

// Delete reply handler
async function deleteReplyHandler(e) {
    e.stopPropagation();
    if (!confirm("Delete this reply and its sub-replies?")) return;
    
    const replyId = this.dataset.id;
    try {
        const response = await fetch(window.location.pathname, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ 'delete_reply': 1, 'reply_id': replyId })
        });
        const data = await response.json();
        
        if (data.success) {
            const replyElement = document.querySelector(`.reply-item[data-reply-id="${replyId}"]`);
            if (replyElement) replyElement.remove();
            showToast("Reply deleted");
        } else {
            showToast("Error deleting reply");
        }
    } catch (error) {
        console.error('Delete reply error:', error);
        showToast("Error deleting reply");
    }
}

// Close dropdowns when clicking outside
document.addEventListener('click', () => {
    document.querySelectorAll('.comment-dropdown').forEach(d => d.style.display = 'none');
});

async function handleCommentLike(e) {
    e.stopPropagation();
    const btn = this;
    const commentId = btn.dataset.id;
    const icon = btn.querySelector('i');
    const countSpan = btn.querySelector('.count');
    
    try {
        const response = await fetch(window.location.pathname, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ 'like_comment': 1, 'comment_id': commentId })
        });
        const data = await response.json();
        
        if (data.success) {
            if (data.liked) {
                btn.classList.add('liked');
                icon.classList.remove('far');
                icon.classList.add('fas');
            } else {
                btn.classList.remove('liked');
                icon.classList.remove('fas');
                icon.classList.add('far');
            }
            countSpan.textContent = data.like_count;
        }
    } catch (error) {
        console.error('Like error:', error);
    }
}

async function handleReplyLike(e) {
    e.stopPropagation();
    const btn = this;
    const replyId = btn.dataset.id;
    const icon = btn.querySelector('i');
    const countSpan = btn.querySelector('.count');
    
    try {
        const response = await fetch(window.location.pathname, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ 'like_reply': 1, 'reply_id': replyId })
        });
        const data = await response.json();
        
        if (data.success) {
            if (data.liked) {
                btn.classList.add('liked');
                icon.classList.remove('far');
                icon.classList.add('fas');
            } else {
                btn.classList.remove('liked');
                icon.classList.remove('fas');
                icon.classList.add('far');
            }
            countSpan.textContent = data.like_count;
        }
    } catch (error) {
        console.error('Like error:', error);
    }
}

function showReplyForm(e) {
    e.stopPropagation();
    const commentId = this.dataset.id;
    const form = document.getElementById(`reply-form-comment-${commentId}`);
    if (form) {
        form.style.display = form.style.display === 'none' ? 'flex' : 'none';
    }
}

function showReplyToReplyForm(e) {
    e.stopPropagation();
    const replyId = this.dataset.id;
    const form = document.getElementById(`reply-form-reply-${replyId}`);
    if (form) {
        form.style.display = form.style.display === 'none' ? 'flex' : 'none';
    }
}

async function submitReply(e) {
    e.stopPropagation();
    const parentId = this.dataset.parentId;
    const parentType = this.dataset.parentType;
    const input = document.getElementById(`reply-input-${parentType}-${parentId}`);
    const reply = input.value.trim();
    
    if (!reply) return;
    
    try {
        const response = await fetch(window.location.pathname, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ 
                'add_reply': 1, 
                'parent_id': parentId, 
                'parent_type': parentType, 
                'reply': reply 
            })
        });
        const data = await response.json();
        
        if (data.success) {
            input.value = '';
            
            if (parentType === 'reply') {
                const childRepliesList = document.getElementById(`child-replies-${parentId}`);
                if (childRepliesList) {
                    childRepliesList.insertAdjacentHTML('beforeend', data.html);
                    const form = document.getElementById(`reply-form-reply-${parentId}`);
                    if (form) form.style.display = 'none';
                    
                    const replyItem = document.querySelector(`.reply-item[data-reply-id="${parentId}"]`);
                    if (replyItem) {
                        const toggleBtn = replyItem.querySelector('.toggle-child-replies');
                        const replyCount = childRepliesList.children.length;
                        if (!toggleBtn && replyCount > 0) {
                            const newToggleBtn = document.createElement('button');
                            newToggleBtn.className = 'toggle-child-replies';
                            newToggleBtn.setAttribute('data-id', parentId);
                            newToggleBtn.textContent = `Show replies (${replyCount})`;
                            newToggleBtn.addEventListener('click', toggleChildReplies);
                            replyItem.querySelector('.reply-actions').appendChild(newToggleBtn);
                        } else if (toggleBtn) {
                            toggleBtn.textContent = `Hide replies (${replyCount})`;
                        }
                    }
                }
            } else {
                const repliesList = document.getElementById(`replies-list-${parentId}`);
                if (repliesList) {
                    repliesList.insertAdjacentHTML('beforeend', data.html);
                    const form = document.getElementById(`reply-form-comment-${parentId}`);
                    if (form) form.style.display = 'none';
                    
                    const commentItem = document.querySelector(`.comment-item[data-comment-id="${parentId}"]`);
                    if (commentItem) {
                        const toggleBtn = commentItem.querySelector('.toggle-replies');
                        const replyCount = repliesList.children.length;
                        if (!toggleBtn && replyCount > 0) {
                            const newToggleBtn = document.createElement('button');
                            newToggleBtn.className = 'toggle-replies';
                            newToggleBtn.setAttribute('data-id', parentId);
                            newToggleBtn.textContent = `Show replies (${replyCount})`;
                            newToggleBtn.addEventListener('click', toggleReplies);
                            commentItem.querySelector('.comment-actions').appendChild(newToggleBtn);
                        } else if (toggleBtn) {
                            toggleBtn.textContent = `Hide replies (${replyCount})`;
                        }
                    }
                }
            }
            updateAllTimes();
        }
    } catch (error) {
        console.error('Reply error:', error);
    }
}

function toggleReplies(e) {
    e.stopPropagation();
    const commentId = this.dataset.id;
    const repliesList = document.getElementById(`replies-list-${commentId}`);
    const btn = this;
    
    if (repliesList) {
        const isHidden = repliesList.style.display === 'none';
        repliesList.style.display = isHidden ? 'block' : 'none';
        const replyCount = repliesList.children.length;
        btn.textContent = isHidden ? `Hide replies (${replyCount})` : `Show replies (${replyCount})`;
    }
}

function toggleChildReplies(e) {
    e.stopPropagation();
    const replyId = this.dataset.id;
    const childRepliesList = document.getElementById(`child-replies-${replyId}`);
    const btn = this;
    
    if (childRepliesList) {
        const isHidden = childRepliesList.style.display === 'none';
        childRepliesList.style.display = isHidden ? 'block' : 'none';
        const replyCount = childRepliesList.children.length;
        btn.textContent = isHidden ? `Hide replies (${replyCount})` : `Show replies (${replyCount})`;
    }
}

// ==================== Reel Event Listeners ====================
function attachReelEventListeners() {
    document.querySelectorAll('.like-btn').forEach(btn => {
        btn.removeEventListener('click', reelLikeHandler);
        btn.addEventListener('click', reelLikeHandler);
    });
    
    document.querySelectorAll('.save-btn').forEach(btn => {
        btn.removeEventListener('click', reelSaveHandler);
        btn.addEventListener('click', reelSaveHandler);
    });
    
    document.querySelectorAll('.follow-overlay').forEach(btn => {
        btn.removeEventListener('click', followHandler);
        btn.addEventListener('click', followHandler);
    });
    
    document.querySelectorAll('.share-btn').forEach(btn => {
        btn.removeEventListener('click', shareHandler);
        btn.addEventListener('click', shareHandler);
    });
    
    document.querySelectorAll('.comment-btn').forEach(btn => {
        btn.removeEventListener('click', commentHandler);
        btn.addEventListener('click', commentHandler);
    });
    
    document.querySelectorAll('.hashtag-link').forEach(link => {
        link.removeEventListener('click', hashtagHandler);
        link.addEventListener('click', hashtagHandler);
    });
}

function reelLikeHandler(e) {
    e.stopPropagation();
    likeReel(this, this.dataset.reel);
}

function reelSaveHandler(e) {
    e.stopPropagation();
    saveReel(this, this.dataset.reel);
}

function followHandler(e) {
    e.stopPropagation();
    e.preventDefault();
    followUser(this, this.dataset.user);
}

function shareHandler(e) {
    e.stopPropagation();
    const reelId = this.dataset.reel;
    const userId = this.dataset.user;
    shareReel(reelId, userId);
}

function commentHandler(e) {
    e.stopPropagation();
    openCommentsPanel(this.dataset.reel);
}

function hashtagHandler(e) {
    e.preventDefault();
    e.stopPropagation();
    document.getElementById('searchInput').value = this.dataset.hashtag;
    currentSearch = this.dataset.hashtag;
    refreshReels();
}

// ==================== Sound Toggle for Music ====================
function initSoundToggles() {
    document.querySelectorAll('.sound-toggle').forEach(toggle => {
        toggle.removeEventListener('click', musicToggleHandler);
        toggle.addEventListener('click', musicToggleHandler);
    });
}

function musicToggleHandler(e) {
    e.stopPropagation();
    e.preventDefault();
    toggleGlobalMute();
}

// ==================== Caption Read More/Less ====================
function toggleReelCaption(postId) {
    const container = document.querySelector(`.caption-container[data-caption-id="${postId}"]`);
    if (!container) return;
    
    const shortSpan = container.querySelector('.caption-short');
    const fullSpan = container.querySelector('.caption-full');
    const btn = container.querySelector('.read-more-caption');
    
    if (shortSpan && fullSpan && btn) {
        if (shortSpan.style.display !== 'none') {
            shortSpan.style.display = 'none';
            fullSpan.style.display = 'inline';
            btn.textContent = 'Read less';
        } else {
            shortSpan.style.display = 'inline';
            fullSpan.style.display = 'none';
            btn.textContent = 'Read more';
        }
    }
}

// ==================== Scroll Handler ====================
let isScrolling = false;

viewer.addEventListener('scroll', () => {
    if (isScrolling) return;
    isScrolling = true;
    
    const scrollTop = viewer.scrollTop;
    const reelHeight = viewer.clientHeight;
    const newIndex = Math.round(scrollTop / reelHeight);
    
    if (newIndex !== current && Math.abs(newIndex - current) === 1) {
        if (newIndex > current) {
            nextReel();
        } else if (newIndex < current) {
            prevReel();
        }
    }
    
    setTimeout(() => { isScrolling = false; }, 100);
});

// ==================== Keyboard Controls ====================
document.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowDown') nextReel();
    if (e.key === 'ArrowUp') prevReel();
    if (e.key === 'm' || e.key === 'M') {
        toggleGlobalMute();
    }
});

// ==================== Tap to Play/Pause Video ====================
viewer.addEventListener('click', (e) => {
    if (e.target.closest('.action-btn') || e.target.closest('.follow-overlay') || 
        e.target.closest('.music-bar') || e.target.closest('.sound-toggle') ||
        e.target.closest('.read-more-caption') || e.target.closest('.hashtag-link') ||
        e.target.closest('.avatar-img') || e.target.closest('.author-name a')) {
        return;
    }
    
    const currentVideo = reels[current]?.querySelector('video');
    if (currentVideo) {
        if (currentVideo.paused) {
            syncPlayback(true);
        } else {
            syncPlayback(false);
        }
    }
    else if (currentMusicAudio) {
        // NEW: If it's an image, toggle the music only
        if (currentMusicAudio.paused) {
            currentMusicAudio.play();
        } else {
            currentMusicAudio.pause();
        }
    }
});

// ==================== Search & Filter ====================
let searchTimeout1;

document.getElementById('searchInput').addEventListener('input', () => {
    clearTimeout(searchTimeout1);
    searchTimeout1 = setTimeout(() => {
        currentSearch = document.getElementById('searchInput').value;
        currentFilter = document.getElementById('typeSelect').value || 'all';
        refreshReels();
    }, 500);
});

document.getElementById('typeSelect').addEventListener('change', () => {
    currentFilter = document.getElementById('typeSelect').value || 'all';
    refreshReels();
});

// ==================== Initialize ====================
setTimeout(() => {
    const scrollPosition = current * viewer.clientHeight;
    viewer.scrollTop = scrollPosition;
    updatePositions();
}, 100);

updatePositions();
attachReelEventListeners();
initSoundToggles();

window.addEventListener('beforeunload', () => {
    Object.keys(watchIntervals).forEach(reelId => stopWatchTracking(reelId));
    if (currentMusicAudio) {
        currentMusicAudio.pause();
        currentMusicAudio = null;
    }
});

console.log('Reels System Initialized with Swipe Navigation and User Search Sidebar!');
console.log('User Mode:', isUserMode);
console.log('Feed Mode:', currentFeedMode);
console.log('Starting at index:', current);
function applySmartFit(element) {
    if (!element) return;

    if (element.tagName === 'VIDEO') {
        element.addEventListener('loadedmetadata', function() {
            const aspect = this.videoHeight / this.videoWidth;
            if (aspect > 1.2) this.classList.add('video-portrait');
        });
    } else if (element.tagName === 'IMG') {
        element.addEventListener('load', function() {
            const aspect = this.naturalHeight / this.naturalWidth;
            if (aspect > 1.2) this.classList.add('video-portrait');
        });
        // In case image is already cached
        if (element.complete) {
            const aspect = element.naturalHeight / element.naturalWidth;
            if (aspect > 1.2) element.classList.add('video-portrait');
        }
    }
}

// Update the initial call at the bottom to include images:
document.querySelectorAll('.media video, .media img').forEach(el => {
    applySmartFit(el);
});
</script>
</body>
</html>