<?php  
   
    if (isset($_GET['username']) === true && empty($_GET['username']) === false ) {
        include 'core/init.php';
        $username = User::checkInput($_GET['username']);
        $profileId = User::getIdByUsername($username);
        $profileData = User::getData($profileId);
        $user_id = $_SESSION['user_id'];
        $user = User::getData($user_id);
        $who_users = Follow::whoToFollow($user_id);
        $tweets = Tweet::tweetsUser($profileData->id);
        $liked_tweets = Tweet::likedTweets($profileData->id);
        $media_tweets = Tweet::mediaTweets($profileData->id);
        $notify_count = User::CountNotification($user_id);

      
        if (!$profileData)
            header('location: index.php');

            if (User::checkLogIn() === false) 
            header('location: index.php');    

    }
 
  // Count total unread messages for this user
$conn = Connect::connect();
$stmt = $conn->prepare("SELECT COUNT(*) AS unread_total FROM messages WHERE receiver_id = ? AND is_read = 0");
$stmt->execute([$user_id]);
$unreadMessages = $stmt->fetch(PDO::FETCH_OBJ)->unread_total ?? 0;


// ✅ Joined date for profile bio area
// Your current users table does not contain a join-date column, so this safely checks common columns first.
// If none exists, it falls back to the user's first post date.
$joinedText = '';
try {
    $joinedDateRaw = null;
    $possibleJoinColumns = ['created_at', 'created_on', 'joined_at', 'register_date', 'date_joined'];

    foreach ($possibleJoinColumns as $joinColumn) {
        $colCheck = $conn->prepare("SHOW COLUMNS FROM users LIKE ?");
        $colCheck->execute([$joinColumn]);
        if ($colCheck->rowCount() > 0 && isset($profileData->$joinColumn) && !empty($profileData->$joinColumn)) {
            $joinedDateRaw = $profileData->$joinColumn;
            break;
        }
    }

    // Fallback because your uploaded users table has no created/joined column.
    if (empty($joinedDateRaw)) {
        $firstPostStmt = $conn->prepare("SELECT MIN(post_on) AS first_post_date FROM posts WHERE user_id = ?");
        $firstPostStmt->execute([$profileData->id]);
        $joinedDateRaw = $firstPostStmt->fetch(PDO::FETCH_OBJ)->first_post_date ?? null;
    }

    if (!empty($joinedDateRaw) && strtotime($joinedDateRaw) !== false) {
        $joinedText = 'Joined ' . date('F Y', strtotime($joinedDateRaw));
    } else {
        $joinedText = 'Joined date unavailable';
    }
} catch (Exception $e) {
    $joinedText = 'Joined date unavailable';
}


// ✅ Tweets & Replies tab data: get comments and nested replies made by this profile user
$profileReplies = [];
try {
    $replyStmt = $conn->prepare("
        SELECT 
            'comment' AS reply_type,
            c.id AS reply_id,
            c.comment AS reply_text,
            c.time AS reply_time,
            c.post_id AS original_post_id,
            p.user_id AS original_user_id,
            u.username AS original_username,
            u.name AS original_name
        FROM comments c
        INNER JOIN posts p ON p.id = c.post_id
        INNER JOIN users u ON u.id = p.user_id
        WHERE c.user_id = :profile_id

        UNION ALL

        SELECT 
            'reply' AS reply_type,
            r.id AS reply_id,
            r.reply AS reply_text,
            r.time AS reply_time,
            c.post_id AS original_post_id,
            p.user_id AS original_user_id,
            u.username AS original_username,
            u.name AS original_name
        FROM replies r
        INNER JOIN comments c ON c.id = r.comment_id
        INNER JOIN posts p ON p.id = c.post_id
        INNER JOIN users u ON u.id = p.user_id
        WHERE r.user_id = :profile_id

        ORDER BY reply_time DESC
    ");
    $replyStmt->execute(['profile_id' => $profileData->id]);
    $profileReplies = $replyStmt->fetchAll(PDO::FETCH_OBJ);
} catch (Exception $e) {
    $profileReplies = [];
}

// ✅ Check if the profile being viewed is blocked (but not the logged-in user's own profile)
$isProfileBlocked = ($profileData && isset($profileData->is_blocked) && $profileData->is_blocked == 1 && $user->id != $profileData->id);

// ✅ Check if viewing own profile (to show/hide Likes tab)
$isOwnProfile = ($user->id == $profileData->id);
?>
 
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title> <?php echo $profileData->name; ?> (@<?php echo $profileData->username; ?>) | Twitter</title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link rel="stylesheet" href="assets/css/profile_style.css?v=<?php echo time(); ?>">
    <link rel="shortcut icon" type="image/png" href="assets/images/twitter.svg"> 
   
   <style>
    .tweet-option {
      cursor: pointer;
      margin-left: 12px;
    }

    .tweet-option i.fa-bookmark {
      color: #657786;
      transition: color 0.2s ease;
    }

    .tweet-option i.fa-bookmark.bookmarked {
      color: #f7ca18;
    }

    /* Who to follow box styling */
    .box-share {
        border: 1px solid #e6ecf0;
        border-radius: 12px;
        background: #fff;
        margin-bottom: 15px;
        overflow: hidden;
    }

    .box-share .txt-share {
        border-bottom: 1px solid #e6ecf0;
        padding: 12px 16px;
        margin: 0;
        font-size: 19px;
    }

    .grid-share {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 12px 16px;
        transition: background 0.2s ease;
        border-bottom: 1px solid #e6ecf0;
    }

    .grid-share:last-child {
        border-bottom: none;
    }

    .grid-share:hover {
        background: #f8f9fa;
    }

    .follow-btn {
        padding: 6px 16px;
        border-radius: 30px;
        font-size: 13px;
        font-weight: bold;
        cursor: pointer;
        transition: all 0.2s;
    }

    .follow-btn.follow {
        background: #1DA1F2;
        color: white;
        border: none;
    }

    .follow-btn.following {
        background: transparent;
        color: #1DA1F2;
        border: 1px solid #1DA1F2;
    }

    .follow-btn:hover {
        transform: translateY(-1px);
        opacity: 0.9;
    }
    
    /* Search Styles */
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
    
    .search-no-results i {
        font-size: 40px;
        margin-bottom: 10px;
        display: block;
        color: #e1e8ed;
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
        transition: all 0.2s ease;
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
    
    .sidebar-unread-badge {
        position: absolute;
        top: -8px;
        right: -8px;
        background: #1da1f2;
        color: white;
        font-size: 11px;
        border-radius: 50%;
        padding: 2px 6px;
        min-width: 18px;
        text-align: center;
    }

    /* Blocked Content Message */
    .blocked-content-container {
        text-align: center;
        padding: 60px 20px;
        background: #fff;
        border-radius: 16px;
        margin: 20px auto;
    }
    .blocked-content-icon {
        font-size: 60px;
        color: #e0245e;
        opacity: 0.5;
        margin-bottom: 15px;
    }
    .blocked-content-title {
        font-size: 20px;
        font-weight: 600;
        margin-bottom: 8px;
        color: #657786;
    }
    .blocked-content-message {
        font-size: 14px;
        color: #657786;
    }
  
    /* Profile Cover Styles */
    .profile-cover-wrap {
        width: 100%;
        height: clamp(180px, 28vw, 320px);
        border-radius: 0;
        overflow: hidden;
        position: relative;
        background: linear-gradient(135deg, #e8f5fe, #f5f8fa);
        border-bottom: 1px solid #e6ecf0;
    }

    .profile-cover-wrap::after {
        content: "";
        position: absolute;
        inset: 0;
        background: linear-gradient(180deg, rgba(0,0,0,0.05) 0%, rgba(0,0,0,0.10) 55%, rgba(0,0,0,0.20) 100%);
        pointer-events: none;
    }

    .profile-cover-wrap .home-img-cover {
        width: 100% !important;
        height: 100% !important;
        display: block !important;
        object-fit: cover !important;
        object-position: center center !important;
        border-radius: 0 !important;
        background: #f5f8fa;
    }

    .home-img-user {
        width: 134px !important;
        height: 134px !important;
        border-radius: 50% !important;
        object-fit: cover !important;
        border: 4px solid #fff !important;
        background: #fff !important;
        box-shadow: 0 4px 16px rgba(15, 20, 25, 0.12) !important;
    }

    /* ========== ENHANCED EDIT PROFILE MODAL STYLES ========== */
    .modal-content {
        border-radius: 28px !important;
        border: none !important;
        overflow: hidden !important;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25) !important;
    }

    .modal-header {
        background: #fff;
        padding: 20px 24px !important;
        border-bottom: 1px solid #eff3f4 !important;
    }

    .modal-header .modal-title {
        font-size: 20px;
        font-weight: 700;
        color: #0f1419;
    }

    .modal-header .close {
        font-size: 28px;
        font-weight: 400;
        color: #536471;
        opacity: 0.7;
        transition: all 0.2s;
        padding: 0;
        margin: -8px -8px -8px auto;
    }

    .modal-header .close:hover {
        opacity: 1;
        color: #1da1f2;
    }

    /* Cover Image Upload Area in Modal */
    .edit-cover-container {
        position: relative;
        width: 100%;
        height: 200px;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        overflow: hidden;
    }

    .edit-cover-preview {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .edit-cover-overlay {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.4);
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 16px;
        opacity: 0;
        transition: opacity 0.25s ease;
    }

    .edit-cover-container:hover .edit-cover-overlay {
        opacity: 1;
    }

    .cover-action-btn {
        width: 44px;
        height: 44px;
        border-radius: 50%;
        background: rgba(0, 0, 0, 0.75);
        backdrop-filter: blur(4px);
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all 0.2s;
        border: none;
        color: white;
        font-size: 18px;
    }

    .cover-action-btn:hover {
        background: #1da1f2;
        transform: scale(1.08);
    }

    .cover-action-btn.delete:hover {
        background: #e0245e;
    }

    /* Avatar Upload Area in Modal */
    .edit-avatar-container {
        position: relative;
        width: 112px;
        height: 112px;
        margin: -56px auto 0 24px;
        z-index: 10;
    }

    .edit-avatar-preview {
        width: 100%;
        height: 100%;
        border-radius: 50%;
        object-fit: cover;
        border: 4px solid #fff;
        box-shadow: 0 8px 20px rgba(0, 0, 0, 0.12);
        background: #fff;
    }

    .edit-avatar-overlay {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        border-radius: 50%;
        background: rgba(0, 0, 0, 0.55);
        display: flex;
        align-items: center;
        justify-content: center;
        opacity: 0;
        transition: opacity 0.2s ease;
        cursor: pointer;
    }

    .edit-avatar-container:hover .edit-avatar-overlay {
        opacity: 1;
    }

    .avatar-edit-icon {
        color: white;
        font-size: 24px;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 4px;
    }

    .avatar-edit-icon span {
        font-size: 11px;
        font-weight: 500;
    }

    /* Form Styles in Modal */
    .edit-profile-form {
        padding: 24px;
    }

    .form-field {
        margin-bottom: 20px;
    }

    .form-field label {
        font-weight: 600;
        font-size: 14px;
        color: #536471;
        margin-bottom: 8px;
        display: block;
    }

    .form-field input {
        width: 100%;
        padding: 12px 16px;
        border: 1.5px solid #e6ecf0;
        border-radius: 16px;
        font-size: 15px;
        transition: all 0.2s;
        background: #fff;
    }

    .form-field input:focus {
        outline: none;
        border-color: #1da1f2;
        box-shadow: 0 0 0 4px rgba(29, 161, 242, 0.1);
    }

    .form-field input::placeholder {
        color: #8899a6;
    }

    /* Alert Styles in Modal */
    .modal-alert {
        margin: 0 24px 16px 24px;
        padding: 12px 16px;
        border-radius: 16px;
        font-size: 13px;
    }

    .modal-alert-danger {
        background: #fee2e2;
        border: 1px solid #fecaca;
        color: #e0245e;
    }

    /* Modal Footer Buttons */
    .modal-footer-custom {
        padding: 16px 24px;
        border-top: 1px solid #eff3f4;
        display: flex;
        justify-content: flex-end;
        gap: 12px;
        background: #fff;
    }

    .btn-cancel-modal {
        padding: 8px 20px;
        border-radius: 30px;
        font-size: 14px;
        font-weight: 600;
        background: transparent;
        border: 1px solid #e6ecf0;
        color: #536471;
        transition: all 0.2s;
        cursor: pointer;
    }

    .btn-cancel-modal:hover {
        background: #f5f8fa;
        border-color: #cbd5e1;
    }

    .btn-save-modal {
        padding: 8px 24px;
        border-radius: 30px;
        font-size: 14px;
        font-weight: 600;
        background: linear-gradient(135deg, #1da1f2, #0c85d0);
        border: none;
        color: white;
        transition: all 0.2s;
        cursor: pointer;
    }

    .btn-save-modal:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(29, 161, 242, 0.3);
    }

    /* Hidden file inputs */
    #cover-input,
    #file-input {
        display: none;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .profile-cover-wrap {
            height: clamp(150px, 36vw, 230px);
        }
        .home-img-user {
            width: 100px !important;
            height: 100px !important;
        }
        .edit-cover-container {
            height: 160px;
        }
        .edit-avatar-container {
            width: 90px;
            height: 90px;
            margin-top: -45px;
        }
        .edit-profile-form {
            padding: 20px;
        }
    }

    @media (max-width: 480px) {
        .profile-cover-wrap {
            height: 130px;
        }
        .home-img-user {
            width: 80px !important;
            height: 80px !important;
            border-width: 3px !important;
        }
        .edit-cover-container {
            height: 140px;
        }
        .edit-avatar-container {
            width: 80px;
            height: 80px;
            margin-top: -40px;
            margin-left: 16px;
        }
        .cover-action-btn {
            width: 36px;
            height: 36px;
            font-size: 14px;
        }
    }


    /* ==========================================================
       SINGLE COMPACT EDIT PROFILE MODAL + IMAGE CROP ADJUSTER
       ========================================================== */
    #edit .modal-dialog.modal-lg{max-width:620px !important;}
    #edit .modal-content{border-radius:16px !important;}
    #edit .modal-header{padding:10px 14px !important;}
    #edit .modal-header .modal-title{font-size:15px !important;}
    #edit .modal-header .close{font-size:22px !important;margin:-6px -6px -6px auto !important;}
    #edit .modal-body{max-height:72vh;overflow-y:auto;}
    #edit .edit-cover-container{height:120px !important;}
    #edit .edit-avatar-container{width:72px !important;height:72px !important;margin:-36px auto 0 18px !important;}
    #edit .edit-avatar-preview{border-width:3px !important;}
    #edit .edit-profile-form{padding:14px !important;}
    #edit .form-field{margin-bottom:10px !important;}
    #edit .form-field label{font-size:12px !important;margin-bottom:4px !important;}
    #edit .form-field input{font-size:12px !important;padding:7px 10px !important;border-radius:10px !important;}
    #edit .cover-action-btn{width:32px !important;height:32px !important;font-size:12px !important;}
    #edit .avatar-edit-icon{font-size:14px !important;}
    #edit .avatar-edit-icon span{font-size:9px !important;}
    #edit .modal-footer-custom{padding:10px 14px !important;}
    #edit .btn-cancel-modal,#edit .btn-save-modal{font-size:12px !important;padding:6px 14px !important;}
    #edit .modal-alert{font-size:11px !important;padding:8px 10px !important;}

    .crop-editor-backdrop{
        position:fixed;inset:0;background:rgba(15,20,25,.62);z-index:20000;
        display:none;align-items:center;justify-content:center;padding:14px;
    }
    .crop-editor-card{
        width:min(560px,96vw);background:#fff;border-radius:18px;overflow:hidden;
        box-shadow:0 24px 70px rgba(0,0,0,.28);
    }
    .crop-editor-header{
        display:flex;align-items:center;justify-content:space-between;
        padding:10px 14px;border-bottom:1px solid #eff3f4;
    }
    .crop-editor-title{font-size:15px;font-weight:700;color:#0f1419;margin:0;}
    .crop-editor-close{border:0;background:transparent;font-size:22px;line-height:1;color:#536471;cursor:pointer;}
    .crop-editor-body{padding:14px;background:#f7f9f9;}
    .crop-frame{
        position:relative;margin:0 auto;background:#111;overflow:hidden;touch-action:none;
        box-shadow:0 0 0 999px rgba(0,0,0,.12), inset 0 0 0 2px rgba(255,255,255,.9);
    }
    .crop-frame.avatar{width:260px;height:260px;border-radius:50%;}
    .crop-frame.cover{width:min(480px,86vw);height:160px;border-radius:12px;}
    .crop-frame::before{
        content:"";position:absolute;inset:0;z-index:3;pointer-events:none;
        background:
            linear-gradient(to right, transparent 33.333%, rgba(255,255,255,.65) 33.333%, rgba(255,255,255,.65) 33.9%, transparent 33.9%, transparent 66.666%, rgba(255,255,255,.65) 66.666%, rgba(255,255,255,.65) 67.2%, transparent 67.2%),
            linear-gradient(to bottom, transparent 33.333%, rgba(255,255,255,.65) 33.333%, rgba(255,255,255,.65) 33.9%, transparent 33.9%, transparent 66.666%, rgba(255,255,255,.65) 66.666%, rgba(255,255,255,.65) 67.2%, transparent 67.2%);
    }
    .crop-frame img{position:absolute;left:0;top:0;max-width:none;user-select:none;-webkit-user-drag:none;cursor:grab;}
    .crop-frame img:active{cursor:grabbing;}
    .crop-help{font-size:12px;color:#536471;text-align:center;margin:10px 0 0;}
    .crop-zoom-wrap{display:flex;align-items:center;gap:10px;margin:12px auto 0;max-width:360px;color:#536471;font-size:12px;}
    .crop-zoom-wrap input{width:100%;}
    .crop-editor-footer{
        display:flex;justify-content:flex-end;gap:10px;padding:10px 14px;border-top:1px solid #eff3f4;background:#fff;
    }
    .crop-btn{border-radius:999px;border:1px solid #dbe3e8;background:#fff;padding:6px 14px;font-size:12px;font-weight:700;cursor:pointer;}
    .crop-btn.apply{border-color:#1da1f2;background:#1da1f2;color:#fff;}
    @media(max-width:480px){.crop-frame.avatar{width:220px;height:220px}.crop-frame.cover{height:135px}.crop-editor-body{padding:12px}}



    /* Joined date under bio */
    .profile-joined-date {
        color: #536471;
        font-size: 14px;
        margin-top: 8px;
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .profile-joined-date i {
        color: #536471;
        font-size: 14px;
    }

    /* ========== TWEETS & REPLIES TAB ========== */
    .profile-reply-card {
        border-bottom: 1px solid #e6ecf0;
        padding: 14px 12px;
        background: #fff;
        position: relative;
    }

    .profile-reply-card:hover {
        background: #f7f9f9;
    }

    .profile-reply-card .replying-to {
        color: #536471;
        font-size: 13px;
        margin: 2px 0 6px 0 !important;
    }

    .profile-reply-card .replying-to a {
        color: #1DA1F2 !important;
        text-decoration: none;
    }

    .profile-reply-card .replying-to a:hover {
        text-decoration: underline;
    }

    .profile-reply-card .reply-open-link {
        color: #536471 !important;
        font-size: 13px;
        display: inline-block;
        margin-top: 8px;
        position: relative;
        z-index: 5;
    }

    .profile-reply-card .reply-open-link:hover {
        color: #1DA1F2 !important;
        text-decoration: underline;
    }



    /* ========== PROFILE BIO ALIGNMENT FIX ========== */
    .home-title {
        position: relative !important;
        bottom: 55px !important;
        margin-left: 20px !important;
        margin-right: 20px !important;
        padding-right: 12px !important;
        display: block !important;
        clear: both !important;
    }

    .home-title h4 {
        position: static !important;
        margin: 0 0 2px 0 !important;
        line-height: 1.25 !important;
        word-break: break-word !important;
    }

    .home-title .user-handle,
    .home-title .bio,
    .home-title .profile-joined-date {
        position: static !important;
        top: auto !important;
        left: auto !important;
        right: auto !important;
        bottom: auto !important;
        display: block !important;
        width: 100% !important;
        margin: 0 !important;
        line-height: 1.45 !important;
        word-break: break-word !important;
        white-space: normal !important;
    }

    .home-title .user-handle {
        color: #536471 !important;
        margin-bottom: 8px !important;
    }

    .home-title .bio {
        color: #0f1419 !important;
        margin-bottom: 8px !important;
    }

    .home-title .profile-joined-date {
        color: #536471 !important;
        font-size: 14px !important;
        margin-top: 4px !important;
        margin-bottom: 0 !important;
        display: flex !important;
        align-items: center !important;
        gap: 6px !important;
    }

    .home-loc-link {
        margin-top: -42px !important;
        clear: both !important;
        align-items: center !important;
        row-gap: 6px !important;
    }

    .home-loc-link li {
        list-style: none !important;
        color: #536471 !important;
        word-break: break-word !important;
    }

    .home-follow {
        margin-top: 8px !important;
        clear: both !important;
    }

    @media (max-width: 768px) {
        .home-title {
            bottom: 42px !important;
            margin-left: 16px !important;
            margin-right: 16px !important;
        }

        .home-loc-link {
            margin-top: -30px !important;
        }
    }

    /* ========== PROFILE IMAGE / COVER LIGHTBOX ========== */
    .profile-lightbox-trigger {
        cursor: pointer !important;
    }

    .profile-cover-wrap .profile-lightbox-trigger:hover,
    .home-img-user.profile-lightbox-trigger:hover {
        filter: brightness(0.92);
        transition: filter 0.2s ease;
    }

    .profile-image-lightbox {
        display: none;
        position: fixed;
        inset: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.95);
        z-index: 100000;
        align-items: center;
        justify-content: center;
        padding: 24px;
    }

    .profile-image-lightbox.active {
        display: flex;
    }

    .profile-lightbox-img {
        max-width: 92vw;
        max-height: 88vh;
        object-fit: contain;
        border-radius: 10px;
        background: #111;
        box-shadow: 0 20px 60px rgba(0,0,0,0.45);
    }

    .profile-lightbox-close {
        position: fixed;
        top: 20px;
        right: 28px;
        width: 42px;
        height: 42px;
        border-radius: 50%;
        background: rgba(255,255,255,0.12);
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 28px;
        line-height: 1;
        cursor: pointer;
        z-index: 100001;
        transition: all 0.2s ease;
    }

    .profile-lightbox-close:hover {
        background: rgba(255,255,255,0.24);
        transform: scale(1.06);
    }

    @media (max-width: 768px) {
        .profile-image-lightbox {
            padding: 12px;
        }

        .profile-lightbox-close {
            top: 14px;
            right: 14px;
            width: 38px;
            height: 38px;
            font-size: 24px;
        }
    }

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
              <a href="home.php" style="margin-top: 4px;"><strong>Home</strong></a>
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
                <img src="<?php echo BASE_URL . "/includes/icons/tweetnotif.png"; ?>" alt="" height="26.25px" width="26.25px" />
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
                <a class="wrapper-left-active" href="<?php echo BASE_URL . $user->username; ?>" style="margin-top: 4px"><strong>Profile</strong></a>
              </div>
            </div>
          </a>
          <a href="<?php echo BASE_URL . "account.php"; ?>">
            <div class="grid-sidebar">
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
            <div class="center-input-search"></div>
          </div>

          <div class="box-fixed" id="box-fixed"></div>
  
          <div class="box-home feed">
            <div class="container">
              <div class="row position-fixed box-name">
                <div class="col-xs-2">
                  <a href="javascript: history.go(-1);"> <i style="font-size:20px;" class="fas fa-arrow-left arrow-style"></i> </a>
                </div>
                <div class="col-xs-10">
                  <span class="home-name"> <?php echo $profileData->name; ?></span>
                  <p class="home-tweets-num"><?php echo Tweet::countTweets($profileData->id); ?> Tweets</p>
                </div>
              </div>

              <div class="row mt-5">
                <div class="col-md-12">
                  <div class="profile-cover-wrap">
                    <img class="home-img-cover profile-lightbox-trigger" data-profile-lightbox-src="assets/images/users/<?php echo $profileData->imgCover; ?>" src="assets/images/users/<?php echo $profileData->imgCover; ?>" alt="">
                  </div>
                </div>
              </div>

              <div class="row justify-content-between">
                <img class="home-img-user profile-lightbox-trigger" data-profile-lightbox-src="assets/images/users/<?php echo $profileData->img; ?>" src="assets/images/users/<?php echo $profileData->img; ?>" alt="">
   
                <?php if ($user->id == $profileData->id) { ?>
                  <button class="home-edit-button" data-toggle="modal" data-target="#edit">Edit Profile</button>
                <?php } else { 
                  $user_follow = Follow::isUserFollow($user_id , $profileData->id);
                ?>
                  <button class="follow-btn <?= $user_follow ? 'following' : 'follow' ?>" data-follow="<?php echo $profileData->id; ?>"> 
                    <?php if($user_follow) { ?>
                      Following 
                    <?php } else { ?>  
                      Follow
                    <?php } ?>  
                  </button>
                <?php } ?> 
              </div>

              <div class="home-title">
                <h4>
                  <?php echo $profileData->name; ?>
                  <?php if ($profileData->is_verified == 1) { ?>
                    <i class="fas fa-check-circle verified-icon" title="Verified"></i>
                  <?php } ?>
                </h4>
                <p class="user-handle" style="color: gray;">@<?php echo $profileData->username; ?>
                <?php if (Follow::FollowsYou($profileData->id , $user_id)) { ?>
                  <span class="ml-1 follows-you">Follows You</span>
                <?php } ?>
                </p>
                <p class="bio"><?php echo $profileData->bio; ?></p>
                <p class="profile-joined-date">
                  <i class="far fa-calendar-alt"></i>
                  <span><?php echo htmlspecialchars($joinedText, ENT_QUOTES, 'UTF-8'); ?></span>
                </p>
              </div>

              <div class="row home-loc-link ml-2">
                <?php if (!empty($profileData->location)) { ?>
                  <div class="col-md-4">
                    <li><i class="fas fa-map-marker-alt"></i> <?php echo $profileData->location; ?></li>
                  </div>
                <?php } ?>
                <?php if (!empty($profileData->website)) { ?>
                  <div class="col-md-4">
                    <li><i class="fas fa-link"></i> 
                      <a href="<?php echo $profileData->website ;?>" target="_blank">
                        <?php echo parse_url($profileData->website, PHP_URL_HOST); ?>
                      </a> 
                    </li>
                  </div>
                <?php } ?>
              </div>

              <div class="row home-follow ml-2 mt-1">
                <div class="col-md-3">
                  <div class="count-following-i" data-follow="<?php echo $profileData->id; ?>">
                    <span class="home-follow-count count-following"><?php echo Follow::countFollowing($profileData->id); ?></span> Followings
                  </div>
                </div>
                <div class="col-md-3">
                  <div class="count-followers-i" data-follow="<?php echo $profileData->id; ?>"> 
                    <span class="home-follow-count count-followers"><?php echo Follow::countFollowers($profileData->id); ?></span> Followers
                  </div>
                </div>   
              </div>
              
              <div class="popupUsers"></div>

              <ul class="nav nav-tabs justify-content-center mt-4" id="myTab" role="tablist">
                <li class="nav-item">
                  <a class="nav-link active" id="home-tab" data-toggle="tab" href="#home" role="tab" aria-controls="home" aria-selected="true">Tweets</a>
                </li>
                <li class="nav-item">
                  <a class="nav-link" id="replies-tab" data-toggle="tab" href="#replies" role="tab" aria-controls="replies" aria-selected="false">Tweets & Replies</a>
                </li>
                <li class="nav-item">
                  <a class="nav-link" id="profile-tab" data-toggle="tab" href="#profile" role="tab" aria-controls="profile" aria-selected="false">Media</a>
                </li>
                <?php if ($isOwnProfile): ?>
                <li class="nav-item">
                  <a class="nav-link" id="contact-tab" data-toggle="tab" href="#contact" role="tab" aria-controls="contact" aria-selected="false">Likes</a>
                </li>
                <?php endif; ?>
              </ul>
                 
              <div class="tab-content" id="myTabContent">
                <?php if ($isProfileBlocked): ?>
                  <div class="blocked-content-container">
                    <div class="blocked-content-icon"><i class="fa-solid fa-ban"></i></div>
                    <div class="blocked-content-title">Content Not Available</div>
                    <div class="blocked-content-message">This user's tweets and media have been blocked by an administrator.</div>
                  </div>
                <?php else: ?>
                  <div class="tab-pane fade show active" id="home" role="tabpanel" aria-labelledby="home-tab">
                    <?php
                      if (!empty($tweets) && is_array($tweets)) {
                          $validTweets = array_filter($tweets, function($tweet) {
                              return isset($tweet) && (Tweet::isTweet($tweet->id) || Tweet::isRetweet($tweet->id));
                          });
                          foreach ($validTweets as $tweet) {
                              include 'includes/tweets.php';
                          }
                      } else {
                          echo '<p>No tweets to display.</p>';
                      }
                    ?>
                  </div>
                  <div class="tab-pane fade" id="replies" role="tabpanel" aria-labelledby="replies-tab">
                    <?php
                      $tweetsAndRepliesTimeline = [];

                      if (!empty($tweets) && is_array($tweets)) {
                          foreach ($tweets as $tweetItem) {
                              if (isset($tweetItem) && (Tweet::isTweet($tweetItem->id) || Tweet::isRetweet($tweetItem->id))) {
                                  $tweetsAndRepliesTimeline[] = [
                                      'type' => 'tweet',
                                      'time' => strtotime($tweetItem->post_on),
                                      'data' => $tweetItem
                                  ];
                              }
                          }
                      }

                      if (!empty($profileReplies) && is_array($profileReplies)) {
                          foreach ($profileReplies as $replyItem) {
                              $tweetsAndRepliesTimeline[] = [
                                  'type' => 'reply',
                                  'time' => strtotime($replyItem->reply_time),
                                  'data' => $replyItem
                              ];
                          }
                      }

                      usort($tweetsAndRepliesTimeline, function($a, $b) {
                          return $b['time'] <=> $a['time'];
                      });

                      if (!empty($tweetsAndRepliesTimeline)) {
                          foreach ($tweetsAndRepliesTimeline as $timelineItem) {
                              if ($timelineItem['type'] === 'tweet') {
                                  $tweet = $timelineItem['data'];
                                  include 'includes/tweets.php';
                              } else {
                                  $reply = $timelineItem['data'];
                                  ?>
                                  <div class="box-tweet feed profile-reply-card">
                                      <div class="grid-tweet">
                                          <div>
                                              <a href="<?php echo BASE_URL . $profileData->username; ?>">
                                                  <img class="img-user-tweet" src="assets/images/users/<?php echo $profileData->img; ?>" alt="">
                                              </a>
                                          </div>
                                          <div>
                                              <p>
                                                  <a href="<?php echo BASE_URL . $profileData->username; ?>" style="font-weight:700; color:#0f1419; text-decoration:none; position:relative; z-index:5;">
                                                      <?php echo htmlspecialchars($profileData->name); ?>
                                                  </a>
                                                  <?php if ($profileData->is_verified == 1) { ?>
                                                      <i class="fas fa-check-circle verified-icon" title="Verified"></i>
                                                  <?php } ?>
                                                  <span class="username-twitter">
                                                      @<?php echo htmlspecialchars($profileData->username); ?> · <?php echo Tweet::getTimeAgo($reply->reply_time); ?>
                                                  </span>
                                              </p>
                                              <p class="replying-to">
                                                  Replying to
                                                  <a href="<?php echo BASE_URL . htmlspecialchars($reply->original_username); ?>">
                                                      @<?php echo htmlspecialchars($reply->original_username); ?>
                                                  </a>
                                              </p>
                                              <div class="tweet-text-full tweet-text-no-truncate">
                                                  <?php echo nl2br(Tweet::getTweetLinks(htmlspecialchars($reply->reply_text, ENT_QUOTES, 'UTF-8'))); ?>
                                              </div>
                                              <a class="reply-open-link" href="status/<?php echo (int)$reply->original_post_id; ?>">
                                                  View conversation
                                              </a>
                                          </div>
                                      </div>
                                  </div>
                                  <?php
                              }
                          }
                      } else {
                          echo '<p style="padding:20px;">No tweets or replies to display.</p>';
                      }
                    ?>
                  </div>
                  <div class="tab-pane fade" id="profile" role="tabpanel" aria-labelledby="profile-tab">
                    <?php
                       $tweets = $media_tweets;
                       if (!empty($tweets) && is_array($tweets)) {
                           $validTweets = array_filter($tweets, function($tweet) {
                               return isset($tweet) && (Tweet::isTweet($tweet->id) || Tweet::isRetweet($tweet->id));
                           });
                           foreach ($validTweets as $tweet) {
                               include 'includes/tweets.php';
                           }
                       } else {
                           echo '<p>No tweets to display.</p>';
                       }
                    ?>
                  </div>
                  <?php if ($isOwnProfile): ?>
                    <div class="tab-pane fade" id="contact" role="tabpanel" aria-labelledby="contact-tab">
                      <?php
                        $tweets = $liked_tweets;
                        if (!empty($tweets) && is_array($tweets)) {
                            $validTweets = array_filter($tweets, function($tweet) {
                                return isset($tweet) && (Tweet::isTweet($tweet->id) || Tweet::isRetweet($tweet->id));
                            });
                            foreach ($validTweets as $tweet) {
                                include 'includes/tweets.php';
                            }
                        } else {
                            echo '<p>No tweets to display.</p>';
                        }
                      ?>
                    </div>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            </div>
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

          <div class="box-share">
            <p class="txt-share"><strong>Who to follow</strong></p>
            <?php 
              foreach($who_users as $suggested_user) { 
                  $user_follow = Follow::isUserFollow($user_id , $suggested_user->id);
            ?>
              <div class="grid-share">
                <a style="position: relative; z-index:5; color:black" href="<?php echo $suggested_user->username; ?>">
                  <img src="assets/images/users/<?php echo $suggested_user->img; ?>" alt="" class="img-share" />
                </a>
                <div>
                  <p>
                    <a style="position: relative; z-index:5; color:black" href="<?php echo $suggested_user->username; ?>">  
                      <strong><?php echo $suggested_user->name; ?></strong>
                    </a>
                  </p>
                  <p class="username">@<?php echo $suggested_user->username; ?>
                  <?php if (Follow::FollowsYou($suggested_user->id , $user_id)) { ?>
                    <span class="ml-1 follows-you">Follows You</span>
                  <?php } ?>
                  </p>
                </div>
                <div>
                  <button class="follow-btn follow-btn-m <?= $user_follow ? 'following' : 'follow' ?>"
                          data-follow="<?php echo $suggested_user->id; ?>"
                          data-user="<?php echo $user_id; ?>"
                          data-profile="<?php echo $profileData->id; ?>"
                          style="font-weight: 700;">
                    <?php echo $user_follow ? 'Following' : 'Follow'; ?>
                  </button>
                </div>
              </div>
            <?php } ?>
          </div>
          <?php
            $trends = Tweet::trendingHashtags();
            echo Tweet::getTrendingHashtagsBox($trends);
          ?>
        </div>
      </div>
    </div>

    <!-- Profile avatar / cover image lightbox -->
    <div class="profile-image-lightbox" id="profileImageLightbox" aria-hidden="true">
      <span class="profile-lightbox-close" id="profileLightboxClose">&times;</span>
      <img class="profile-lightbox-img" id="profileLightboxImage" src="" alt="Profile image preview">
    </div>

    <!-- ========== ENHANCED EDIT PROFILE MODAL ========== -->
    <div class="modal fade" id="edit" tabindex="-1" role="dialog" aria-labelledby="exampleModalCenterTitle" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Edit Profile</h5>
            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
              <span aria-hidden="true">&times;</span>
            </button>
          </div>
          
          <form method="POST" action="handle/handleUpdateData.php" enctype="multipart/form-data">
            <div class="modal-body" style="padding: 0;">
              
              <!-- Modern Cover Image Upload -->
              <div class="edit-cover-container">
                <img id="preview-cover" class="edit-cover-preview" src="assets/images/users/<?php echo $profileData->imgCover; ?>" alt="Cover">
                <div class="edit-cover-overlay">
                  <label for="cover-input" class="cover-action-btn" style="cursor: pointer;">
                    <i class="fas fa-camera"></i>
                  </label>
                  <a href="handle/handleDeleteCover.php" class="cover-action-btn delete" id="deleteCoverBtn">
                    <i class="fas fa-trash-alt"></i>
                  </a>
                </div>
                <input id="cover-input" type="file" name="cover" accept="image/*" style="display: none;">
              </div>

              <!-- Modern Avatar Upload -->
              <div class="edit-avatar-container">
                <img id="preview-user" class="edit-avatar-preview" src="assets/images/users/<?php echo $profileData->img; ?>" alt="Avatar">
                <div class="edit-avatar-overlay">
                  <label for="file-input" class="avatar-edit-icon" style="cursor: pointer;">
                    <i class="fas fa-camera"></i>
                    <span>Change</span>
                  </label>
                </div>
                <input id="file-input" name="image" type="file" accept="image/*" style="display: none;">
              </div>

              <!-- Error Alerts -->
              <?php if (isset($_SESSION['errors'])) { ?>
                <?php foreach ($_SESSION['errors'] as $error) { ?>
                  <div class="modal-alert modal-alert-danger"><?php echo $error; ?></div>
                <?php } 
                unset($_SESSION['errors']);
              } ?>

              <!-- Form Fields -->
              <div class="edit-profile-form">
                <div class="form-field">
                  <label>Name</label>
                  <input type="text" name="name" value="<?php echo htmlspecialchars($profileData->name ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Your name">
                </div>
                <div class="form-field">
                  <label>Bio</label>
                  <input type="text" name="bio" value="<?php echo htmlspecialchars($profileData->bio ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="Tell us about yourself">
                </div>
                <div class="form-field">
                  <label>Website</label>
                  <input type="text" name="website" value="<?php echo htmlspecialchars($profileData->website ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="https://yourwebsite.com">
                </div>
                <div class="form-field">
                  <label>Location</label>
                  <input type="text" name="location" value="<?php echo htmlspecialchars($profileData->location ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="City, Country">
                </div>
              </div>
            </div>
            
            <div class="modal-footer-custom">
              <button type="button" class="btn-cancel-modal" data-dismiss="modal">Cancel</button>
              <button type="submit" name="update" class="btn-save-modal">Save Changes</button>
            </div>
          </form>
        </div>
      </div>
    </div>
    <!-- End Enhanced Edit Profile Modal -->

    <!-- Image Crop / Position Adjuster -->
    <div class="crop-editor-backdrop" id="cropEditor" aria-hidden="true">
      <div class="crop-editor-card">
        <div class="crop-editor-header">
          <p class="crop-editor-title" id="cropEditorTitle">Adjust image</p>
          <button type="button" class="crop-editor-close" id="cropCloseBtn">&times;</button>
        </div>
        <div class="crop-editor-body">
          <div class="crop-frame avatar" id="cropFrame">
            <img id="cropImage" src="" alt="Crop preview">
          </div>
          <p class="crop-help">Drag image to choose the visible area. Use zoom if needed.</p>
          <div class="crop-zoom-wrap">
            <span>Zoom</span>
            <input type="range" id="cropZoom" min="1" max="3" step="0.01" value="1">
          </div>
        </div>
        <div class="crop-editor-footer">
          <button type="button" class="crop-btn" id="cropCancelBtn">Cancel</button>
          <button type="button" class="crop-btn apply" id="cropApplyBtn">Apply</button>
        </div>
      </div>
    </div>


    <script src="assets/js/search.js"></script>
    <script src="assets/js/bookmark.js?v=<?php echo time(); ?>"></script>
    <script src="assets/js/photo.js"></script>
    <script src="assets/js/follow.js?v=<?php echo time(); ?>"></script>
    <script src="assets/js/users.js?v=<?php echo time(); ?>"></script>
    <script type="text/javascript" src="assets/js/hashtag.js"></script>
    <script type="text/javascript" src="assets/js/like.js"></script>
    <script type="text/javascript" src="assets/js/comment.js?v=<?php echo time(); ?>"></script>
    <script type="text/javascript" src="assets/js/retweet.js?v=<?php echo time(); ?>"></script>
    <script src="https://kit.fontawesome.com/38e12cc51b.js" crossorigin="anonymous"></script>
    <script src="assets/js/popper.min.js"></script>
    <script src="assets/js/bootstrap.min.js"></script>

    <script>
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
        
        // Close search results when clicking outside
        $(document).on('click', function(e) {
            if (!$(e.target).closest('.input-group').length) {
                $('.search-result').fadeOut(200);
            }
        });
    });

    // Image preview functionality
    $(document).ready(function() {
        $('#cover-input').on('change', function(e) {
            const file = e.target.files && e.target.files[0];
            if (!file) return;

            const reader = new FileReader();
            reader.onload = function(event) {
                let $preview = $('#preview-cover');
                $preview.attr('src', event.target.result).css({
                    width: '100%',
                    height: '100%',
                    objectFit: 'cover',
                    objectPosition: 'center center'
                });
            };
            reader.readAsDataURL(file);
        });

        $('#file-input').on('change', function(e) {
            const file = e.target.files && e.target.files[0];
            if (!file) return;

            const reader = new FileReader();
            reader.onload = function(event) {
                let $preview = $('#preview-user');
                $preview.attr('src', event.target.result);
            };
            reader.readAsDataURL(file);
        });

        // Auto-show modal if there are errors
        <?php if (isset($_SESSION['errors'])) { ?>
            $('#edit').modal('show');
        <?php } ?>
    });
    </script>



    <script>
    // Image crop/adjust functionality for profile photo and cover photo
    $(document).ready(function() {
        let cropTarget = null;
        let cropFile = null;
        let naturalW = 0, naturalH = 0;
        let baseW = 0, baseH = 0;
        let posX = 0, posY = 0, zoomVal = 1;
        let dragging = false, startX = 0, startY = 0, startPosX = 0, startPosY = 0;

        const $editor = $('#cropEditor');
        const $frame = $('#cropFrame');
        const $img = $('#cropImage');
        const $zoom = $('#cropZoom');

        function openCropEditor(target, file){
            cropTarget = target;
            cropFile = file;
            $('#cropEditorTitle').text(target === 'avatar' ? 'Adjust profile photo' : 'Adjust cover photo');
            $frame.removeClass('avatar cover').addClass(target === 'avatar' ? 'avatar' : 'cover');
            $zoom.val(1);
            zoomVal = 1;

            const reader = new FileReader();
            reader.onload = function(e){
                $img.attr('src', e.target.result);
                $editor.css('display','flex').attr('aria-hidden','false');
            };
            reader.readAsDataURL(file);
        }

        function setupImage(){
            const imgEl = $img[0];
            naturalW = imgEl.naturalWidth;
            naturalH = imgEl.naturalHeight;
            const frameW = $frame.width();
            const frameH = $frame.height();
            const imgRatio = naturalW / naturalH;
            const frameRatio = frameW / frameH;

            if(imgRatio > frameRatio){
                baseH = frameH;
                baseW = frameH * imgRatio;
            }else{
                baseW = frameW;
                baseH = frameW / imgRatio;
            }
            posX = (frameW - baseW) / 2;
            posY = (frameH - baseH) / 2;
            updateCropImage();
        }

        function clampPosition(){
            const frameW = $frame.width();
            const frameH = $frame.height();
            const displayW = baseW * zoomVal;
            const displayH = baseH * zoomVal;

            if(displayW <= frameW){
                posX = (frameW - displayW) / 2;
            }else{
                posX = Math.min(0, Math.max(frameW - displayW, posX));
            }
            if(displayH <= frameH){
                posY = (frameH - displayH) / 2;
            }else{
                posY = Math.min(0, Math.max(frameH - displayH, posY));
            }
        }

        function updateCropImage(){
            clampPosition();
            $img.css({
                width: (baseW * zoomVal) + 'px',
                height: (baseH * zoomVal) + 'px',
                left: posX + 'px',
                top: posY + 'px'
            });
        }

        $img.on('load', setupImage);

        $zoom.on('input', function(){
            const oldZoom = zoomVal;
            const frameW = $frame.width();
            const frameH = $frame.height();
            const centerX = frameW / 2;
            const centerY = frameH / 2;
            zoomVal = parseFloat(this.value);
            posX = centerX - ((centerX - posX) / oldZoom) * zoomVal;
            posY = centerY - ((centerY - posY) / oldZoom) * zoomVal;
            updateCropImage();
        });

        function pointerPoint(e){
            const oe = e.originalEvent;
            if(oe.touches && oe.touches.length){ return {x: oe.touches[0].clientX, y: oe.touches[0].clientY}; }
            return {x: e.clientX, y: e.clientY};
        }

        $frame.on('mousedown touchstart', function(e){
            e.preventDefault();
            dragging = true;
            const p = pointerPoint(e);
            startX = p.x; startY = p.y;
            startPosX = posX; startPosY = posY;
        });

        $(document).on('mousemove touchmove', function(e){
            if(!dragging) return;
            e.preventDefault();
            const p = pointerPoint(e);
            posX = startPosX + (p.x - startX);
            posY = startPosY + (p.y - startY);
            updateCropImage();
        });

        $(document).on('mouseup touchend touchcancel', function(){ dragging = false; });

        function closeCropEditor(resetInput){
            $editor.hide().attr('aria-hidden','true');
            if(resetInput && cropTarget){
                const input = cropTarget === 'avatar' ? $('#file-input')[0] : $('#cover-input')[0];
                if(input) input.value = '';
            }
            cropTarget = null;
            cropFile = null;
            $img.attr('src','');
        }

        $('#cropCloseBtn, #cropCancelBtn').on('click', function(){ closeCropEditor(true); });

        $('#cropApplyBtn').on('click', function(){
            if(!cropTarget || !cropFile) return;
            const frameW = $frame.width();
            const frameH = $frame.height();
            const displayW = baseW * zoomVal;
            const displayH = baseH * zoomVal;
            const sx = Math.max(0, -posX * naturalW / displayW);
            const sy = Math.max(0, -posY * naturalH / displayH);
            const sw = Math.min(naturalW - sx, frameW * naturalW / displayW);
            const sh = Math.min(naturalH - sy, frameH * naturalH / displayH);

            const canvas = document.createElement('canvas');
            if(cropTarget === 'avatar'){
                canvas.width = 500;
                canvas.height = 500;
            }else{
                canvas.width = 1200;
                canvas.height = 400;
            }
            const ctx = canvas.getContext('2d');
            ctx.drawImage($img[0], sx, sy, sw, sh, 0, 0, canvas.width, canvas.height);

            canvas.toBlob(function(blob){
                const fileName = cropTarget === 'avatar' ? 'profile-cropped.jpg' : 'cover-cropped.jpg';
                const newFile = new File([blob], fileName, {type:'image/jpeg'});
                const dt = new DataTransfer();
                dt.items.add(newFile);
                const input = cropTarget === 'avatar' ? $('#file-input')[0] : $('#cover-input')[0];
                input.files = dt.files;

                const dataUrl = canvas.toDataURL('image/jpeg', .92);
                if(cropTarget === 'avatar'){
                    $('#preview-user').attr('src', dataUrl);
                }else{
                    $('#preview-cover').attr('src', dataUrl).css({width:'100%',height:'100%',objectFit:'cover',objectPosition:'center center'});
                }
                closeCropEditor(false);
            }, 'image/jpeg', .92);
        });

        $('#cover-input').off('change').on('change', function(e){
            const file = e.target.files && e.target.files[0];
            if(file) openCropEditor('cover', file);
        });

        $('#file-input').off('change').on('change', function(e){
            const file = e.target.files && e.target.files[0];
            if(file) openCropEditor('avatar', file);
        });
    });
    </script>

    <script>
    // ========== PROFILE AVATAR / COVER LIGHTBOX ==========
    $(document).ready(function() {
        function openProfileLightbox(src) {
            if (!src) return;
            $('#profileLightboxImage').attr('src', src);
            $('#profileImageLightbox').addClass('active').attr('aria-hidden', 'false');
            $('body').css('overflow', 'hidden');
        }

        function closeProfileLightbox() {
            $('#profileImageLightbox').removeClass('active').attr('aria-hidden', 'true');
            $('#profileLightboxImage').attr('src', '');
            $('body').css('overflow', '');
        }

        $(document).on('click', '.profile-lightbox-trigger', function(e) {
            e.preventDefault();
            e.stopPropagation();
            openProfileLightbox($(this).data('profile-lightbox-src') || $(this).attr('src'));
        });

        $('#profileLightboxClose').on('click', function(e) {
            e.preventDefault();
            closeProfileLightbox();
        });

        $('#profileImageLightbox').on('click', function(e) {
            if (e.target === this) {
                closeProfileLightbox();
            }
        });

        $(document).on('keydown', function(e) {
            if (e.key === 'Escape' && $('#profileImageLightbox').hasClass('active')) {
                closeProfileLightbox();
            }
        });
    });
    </script>

</body>
</html>