<?php 

include 'core/init.php';
  
$user_id = $_SESSION['user_id'];

$user = User::getData($user_id);
$who_users = Follow::whoToFollow($user_id);
$notify_count = User::CountNotification($user_id);

if (User::checkLogIn() === false) 
header('location: index.php');


// Count total unread messages for this user
$conn = Connect::connect();
$stmt = $conn->prepare("SELECT COUNT(*) AS unread_total FROM messages WHERE receiver_id = ? AND is_read = 0");
$stmt->execute([$user_id]);
$unreadMessages = $stmt->fetch(PDO::FETCH_OBJ)->unread_total ?? 0;

// Track active tab (for staying on password tab after error)
$active_tab = 'username'; // default

// Handle Username Update
if (isset($_POST['update_username'])) {
    $new_username = User::checkInput($_POST['username']);
    $errors = [];
    
    if (empty($new_username)) {
        $errors[] = "Username cannot be empty";
    } elseif (!preg_match("/^[a-zA-Z0-9_]*$/", $new_username)) {
        $errors[] = "Only letters, numbers, and underscores allowed in username";
    } elseif (strlen($new_username) > 20) {
        $errors[] = "Username must be less than 20 characters";
    } elseif (User::checkUserName($new_username) === true && $new_username !== $user->username) {
        $errors[] = "Username already taken";
    }
    
    if (empty($errors)) {
        $stmt = $conn->prepare("UPDATE users SET username = :username WHERE id = :id");
        $stmt->execute(['username' => $new_username, 'id' => $user_id]);
        $_SESSION['success_account'] = "Username updated successfully!";
        header("Location: account.php");
        exit();
    } else {
        $_SESSION['errors_account'] = $errors;
        $active_tab = 'username';
        header("Location: account.php?tab=username");
        exit();
    }
}

// Handle Password Change
if (isset($_POST['change_password'])) {
    $old_password = $_POST['old_password'];
    $new_password = $_POST['new_password'];
    $ver_password = $_POST['ver_password'];
    $errors = [];
    
    // Get user from database
    $stmt = $conn->prepare("SELECT password FROM users WHERE id = :id");
    $stmt->execute(['id' => $user_id]);
    $db_user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Verify old password (supports both bcrypt and MD5)
    $old_password_valid = false;
    
    // Check with bcrypt first
    if (password_verify($old_password, $db_user['password'])) {
        $old_password_valid = true;
    } 
    // Check with MD5 for backward compatibility
    elseif (md5($old_password) == $db_user['password']) {
        $old_password_valid = true;
    }
    
    if (!$old_password_valid) {
        $errors[] = "Current password is incorrect";
    }
    
    if (empty($new_password)) {
        $errors[] = "New password cannot be empty";
    } elseif (strlen($new_password) < 6) {
        $errors[] = "New password must be at least 6 characters";
    }
    
    if ($new_password !== $ver_password) {
        $errors[] = "New passwords do not match";
    }
    
    if (empty($errors)) {
        // Hash new password with bcrypt
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET password = :password WHERE id = :id");
        $stmt->execute(['password' => $hashed_password, 'id' => $user_id]);
        $_SESSION['success_password'] = "Password changed successfully!";
        header("Location: account.php?tab=password&success=1");
        exit();
    } else {
        $_SESSION['errors_password'] = $errors;
        header("Location: account.php?tab=password");
        exit();
    }
}

// Determine which tab to show based on URL parameter
if (isset($_GET['tab']) && $_GET['tab'] == 'password') {
    $active_tab = 'password';
} elseif (isset($_GET['tab']) && $_GET['tab'] == 'username') {
    $active_tab = 'username';
}

// Check if there are password errors or success to force password tab
if (isset($_SESSION['errors_password']) || isset($_SESSION['success_password'])) {
    $active_tab = 'password';
}

?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings | TwitterClone</title>
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link rel="stylesheet" href="assets/css/home_style.css?v=<?php echo time(); ?>">
    <link rel="shortcut icon" type="image/png" href="assets/images/twitter.svg"> 
   
    <style>
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
        
        /* ========== WHO TO FOLLOW STYLES ========== */
        .box-share {
            border: 1px solid #e6ecf0;
            border-radius: 12px;
            background: #fff;
            margin-bottom: 15px;
            margin-left: 20px;
            margin-right: 20px;
            overflow: hidden;
        }
        
        .box-share .txt-share {
            border-bottom: 1px solid #e6ecf0;
            padding: 12px 16px;
            margin: 0;
            font-size: 19px;
        }
        
        .grid-share {
            padding: 12px 16px;
            margin-top: 0;
            display: flex;
            align-items: center;
            gap: 12px;
            transition: 0.5s;
            border-bottom: 1px solid #e6ecf0;
        }
      
        .grid-share:last-child {
            border-bottom: none;
        }
      
        .grid-share > div:first-of-type {
            flex: 1;
        }
      
        .grid-share > div:last-child {
            margin-left: auto;
        }
      
        .grid-share p {
            margin: 0;
            line-height: 1.3;
        }
      
        .grid-share .username {
            font-size: 13px;
            color: #657786;
            margin-top: 2px;
            display: block;
        }
      
        .grid-share .img-share {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            object-fit: cover;
            flex-shrink: 0;
        }
        
        .follows-you {
            background: #e8f5fe;
            color: #1DA1F2;
            font-size: 11px;
            padding: 2px 8px;
            border-radius: 20px;
            margin-left: 6px;
            font-weight: 500;
        }
        
        .follow-btn {
            padding: 6px 16px;
            border-radius: 30px;
            font-size: 13px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.2s;
            border: none;
        }
        
        .follow-btn.follow {
            background: #1DA1F2;
            color: white;
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

        /* ========== PASSWORD FIELD WITH EYE ICON ========== */
        .password-field {
            position: relative;
            margin-bottom: 20px;
        }

        .password-field label {
            display: block;
            margin-bottom: 8px;
        }
        
        .password-field input {
            padding-right: 50px !important;
            width: 100%;
        }
        
        .toggle-password {
            position: absolute;
            right: 12px;
            top: 38px;
            transform: none;
            cursor: pointer;
            color: #657786;
            background: transparent;
            border: none;
            z-index: 10;
            font-size: 16px;
            padding: 0;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .toggle-password:hover {
            color: #1DA1F2;
        }
        
        /* ========== SUCCESS MESSAGES ========== */
        .alert-success-custom {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 15px;
            animation: slideIn 0.3s ease;
        }
        
        @keyframes slideIn {
            from {
                transform: translateY(-20px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        /* ========== SETTINGS TABS STYLING ========== */
        #v-pills-tab {
            gap: 10px;
        }
        
        #v-pills-tab .nav-link {
            border-radius: 30px;
            margin-bottom: 10px;
            transition: all 0.2s ease;
            color: black !important;
            text-align: center;
            padding: 12px 20px;
        }
        
        #v-pills-tab .nav-link.active {
            background-color: #1DA1F2;
            color: white !important;
        }
        
        #v-pills-tab .nav-link:not(.active):hover {
            background-color: #e8f5fe;
            color: #1DA1F2 !important;
        }
        
        /* Form input focus */
        .form-control:focus {
            border-color: #1DA1F2;
            box-shadow: 0 0 0 3px rgba(29, 161, 242, 0.1);
        }
        
        /* Alert styling */
        .alert {
            border-radius: 12px;
        }
    

        /* ========== TRENDING HASHTAGS BOX FIX ========== */
        .wrapper-right .box-share.mt-4,
        #mine .wrapper-right .box-share.mt-4 {
            border: 1px solid #e6ecf0 !important;
            border-radius: 12px !important;
            background: #fff !important;
            margin: 15px 20px !important;
            max-height: 300px !important;
            overflow-y: auto !important;
            overflow-x: hidden !important;
            padding: 0 !important;
        }

        .wrapper-right .box-share.mt-4 .txt-share,
        #mine .wrapper-right .box-share.mt-4 .txt-share {
            border-bottom: 1px solid #e6ecf0 !important;
            padding: 12px 16px !important;
            margin: 0 !important;
            font-size: 19px !important;
            font-weight: 700 !important;
            color: #14171a !important;
            background: #fff !important;
        }

        .wrapper-right .trending-list,
        #mine .wrapper-right .trending-list {
            list-style: none !important;
            margin: 0 !important;
            padding: 0 !important;
        }

        .wrapper-right .trending-list li,
        #mine .wrapper-right .trending-list li {
            font-size: 15px !important;
            padding: 14px 16px !important;
            border-bottom: 1px solid #e6ecf0 !important;
            transition: background-color 0.2s ease !important;
            cursor: pointer !important;
            margin: 0 !important;
            background: #fff !important;
        }

        .wrapper-right .trending-list li:hover,
        #mine .wrapper-right .trending-list li:hover {
            background-color: #f5f8fa !important;
        }

        .wrapper-right .trending-list li:last-child,
        #mine .wrapper-right .trending-list li:last-child {
            border-bottom: none !important;
        }

        .wrapper-right .trending-list a,
        #mine .wrapper-right .trending-list a {
            color: #0f1419 !important;
            font-size: 15px !important;
            text-decoration: none !important;
            font-weight: 700 !important;
            position: relative !important;
            z-index: 2 !important;
        }

        .wrapper-right .trending-list a:hover,
        #mine .wrapper-right .trending-list a:hover {
            color: #1DA1F2 !important;
            text-decoration: underline !important;
        }

        .wrapper-right .trend-count,
        #mine .wrapper-right .trend-count {
            color: #657786 !important;
            font-size: 13px !important;
            margin-left: 5px !important;
            font-weight: 400 !important;
        }

        .wrapper-right .trend-header,
        #mine .wrapper-right .trend-header {
            display: flex !important;
            align-items: center !important;
            gap: 8px !important;
            margin-bottom: 4px !important;
        }

        .wrapper-right .trend-type,
        #mine .wrapper-right .trend-type {
            font-weight: 600 !important;
            border-radius: 12px !important;
            padding: 2px 8px !important;
            color: #fff !important;
            font-size: 12px !important;
            background-color: #657786 !important;
            line-height: 1.3 !important;
        }

        .wrapper-right .trend-type.sports,
        #mine .wrapper-right .trend-type.sports { background-color: #1DA1F2 !important; }
        .wrapper-right .trend-type.politics,
        #mine .wrapper-right .trend-type.politics { background-color: #D93025 !important; }
        .wrapper-right .trend-type.entertainment,
        #mine .wrapper-right .trend-type.entertainment { background-color: #FF9800 !important; }
        .wrapper-right .trend-type.technology,
        #mine .wrapper-right .trend-type.technology { background-color: #4CAF50 !important; }
        .wrapper-right .trend-type.environment,
        #mine .wrapper-right .trend-type.environment { background-color: #2E7D32 !important; }
        .wrapper-right .trend-type.education,
        #mine .wrapper-right .trend-type.education { background-color: #9C27B0 !important; }
        .wrapper-right .trend-type.health,
        #mine .wrapper-right .trend-type.health { background-color: #E91E63 !important; }
        .wrapper-right .trend-type.general,
        #mine .wrapper-right .trend-type.general { background-color: #657786 !important; }

        .wrapper-right .trend-label,
        #mine .wrapper-right .trend-label {
            background-color: #e8f5fe !important;
            color: #1d9bf0 !important;
            font-size: 11px !important;
            font-weight: 700 !important;
            padding: 2px 6px !important;
            border-radius: 4px !important;
            text-transform: uppercase !important;
        }

        .wrapper-right .box-share.mt-4::-webkit-scrollbar,
        #mine .wrapper-right .box-share.mt-4::-webkit-scrollbar {
            width: 6px !important;
        }

        .wrapper-right .box-share.mt-4::-webkit-scrollbar-thumb,
        #mine .wrapper-right .box-share.mt-4::-webkit-scrollbar-thumb {
            background-color: #cfd9de !important;
            border-radius: 8px !important;
        }

        .wrapper-right .box-share.mt-4::-webkit-scrollbar-track,
        #mine .wrapper-right .box-share.mt-4::-webkit-scrollbar-track {
            background: #f7f9f9 !important;
        }
        /* ========== END TRENDING HASHTAGS BOX FIX ========== */
</style>
</head>
<body>
     
<div id="mine">
    <div class="wrapper-left">
        <div class="sidebar-left">
          <div class="grid-sidebar" style="margin-top: 12px">
            <div class="icon-sidebar-align">
              <img src="<?php echo BASE_URL . "/assets/images/twitter-logo.png"; ?>" alt="" height="30px" width="30px" />
            </div>
          </div>

          <a href="home.php">
          <div class="grid-sidebar" style="margin-top: 12px">
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
                    <a href="<?php echo BASE_URL . $user->username; ?>" style="margin-top: 4px"><strong>Profile</strong></a>
                </div>
            </div>
          </a>
          
          <a href="<?php echo BASE_URL . "account.php"; ?>">
            <div class="grid-sidebar">
                <div class="icon-sidebar-align">
                    <img src="<?php echo BASE_URL . "/includes/icons/tweetsetting.png"; ?>" alt="" height="26.25px" width="26.25px" />
                </div>
                <div class="wrapper-left-elements">
                    <a class="wrapper-left-active" href="<?php echo BASE_URL . "account.php"; ?>" style="margin-top: 4px"><strong>Settings</strong></a>
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
        <!-- CENTER COLUMN -->
        <div class="border-right">
            <div class="grid-toolbar-center">
                <div class="center-input-search">
                    <div class="container d-flex align-items-center py-2">
                        <a href="javascript: history.go(-1);" class="mr-3 text-dark">
                            <i class="fas fa-arrow-left" style="font-size:20px;"></i>
                        </a>
                        <h2 style="margin: 0; font-size: 20px; font-weight: 700;">
                            Account Settings
                        </h2>
                    </div>
                </div>
            </div>

            <div class="box-home feed" style="padding: 20px;">
                <div class="container-fluid" style="max-width: 600px; margin: 0 auto;">
                    <div class="nav flex-column nav-pills mb-4" id="v-pills-tab" role="tablist" aria-orientation="vertical">
                        <a style="color:black !important;" class="nav-link <?php echo ($active_tab == 'username') ? 'active' : ''; ?>" id="v-pills-home-tab" data-toggle="pill" href="#v-pills-home" role="tab" aria-controls="v-pills-home" aria-selected="true">Change Username</a>
                        <a style="color:black !important;" class="nav-link <?php echo ($active_tab == 'password') ? 'active' : ''; ?>" id="v-pills-profile-tab" data-toggle="pill" href="#v-pills-profile" role="tab" aria-controls="v-pills-profile" aria-selected="false">Change Password</a>
                    </div>
                    
                    <div class="tab-content" id="v-pills-tabContent">
                        <!-- CHANGE USERNAME FORM -->
                        <div class="tab-pane fade <?php echo ($active_tab == 'username') ? 'show active' : ''; ?>" id="v-pills-home" role="tabpanel" aria-labelledby="v-pills-home-tab">
                            <?php  
                            // Display username form errors here
                            if (isset($_SESSION['errors_account']) && !empty($_SESSION['errors_account'])) {
                                foreach ($_SESSION['errors_account'] as $error) { ?>
                                    <div class="alert alert-danger" role="alert">
                                        <p style="font-size: 15px;" class="text-center mb-0"><?php echo $error; ?></p>  
                                    </div> 
                                <?php }   
                                unset($_SESSION['errors_account']);
                            }
                            
                            if (isset($_SESSION['success_account'])) { ?>
                                <div class="alert-success-custom" role="alert">
                                    <p style="font-size: 15px;" class="text-center mb-0"><?php echo $_SESSION['success_account']; ?></p>  
                                </div> 
                            <?php unset($_SESSION['success_account']);
                            } ?>
                            
                            <form method="POST" action="" class="py-4">
                                <div class="form-group">
                                    <label for="username">Username</label>
                                    <input type="text" name="username" value="<?php echo $user->username; ?>" class="form-control" id="username" placeholder="Username" required>
                                    <small class="form-text text-muted">Only letters, numbers, and underscores allowed. Max 20 characters.</small>
                                </div>
                                
                                <div class="text-center">
                                    <button type="submit" name="update_username" class="btn btn-primary" style="background-color: #1DA1F2; border: none; border-radius: 30px; padding: 10px 30px;">Save Changes</button>
                                </div>
                            </form>
                        </div>
                        
                        <!-- CHANGE PASSWORD FORM -->
                        <div class="tab-pane fade <?php echo ($active_tab == 'password') ? 'show active' : ''; ?>" id="v-pills-profile" role="tabpanel" aria-labelledby="v-pills-profile-tab">
                            <?php  
                            // Display password form errors here
                            if (isset($_SESSION['errors_password']) && !empty($_SESSION['errors_password'])) {
                                foreach ($_SESSION['errors_password'] as $error) { ?>
                                    <div class="alert alert-danger" role="alert">
                                        <p style="font-size: 15px;" class="text-center mb-0"><?php echo $error; ?></p>  
                                    </div> 
                                <?php }   
                                unset($_SESSION['errors_password']);
                            }
                            
                            if (isset($_SESSION['success_password'])) { ?>
                                <div class="alert-success-custom" role="alert">
                                    <p style="font-size: 15px;" class="text-center mb-0"><?php echo $_SESSION['success_password']; ?></p>  
                                </div> 
                            <?php unset($_SESSION['success_password']);
                            } ?>
                            
                            <form method="POST" action="" class="py-4" id="passwordForm">
                                <div class="password-field">
                                    <label for="old_password">Current Password</label>
                                    <input type="password" name="old_password" class="form-control" id="old_password" placeholder="Current Password" required>
                                    <button type="button" class="toggle-password" data-target="old_password">
                                        <i class="far fa-eye-slash"></i>
                                    </button>
                                </div>
                                
                                <div class="password-field">
                                    <label for="new_password">New Password</label>
                                    <input type="password" name="new_password" class="form-control" id="new_password" placeholder="New Password" required>
                                    <button type="button" class="toggle-password" data-target="new_password">
                                        <i class="far fa-eye-slash"></i>
                                    </button>
                                    <small class="form-text text-muted">Password must be at least 6 characters</small>
                                </div>

                                <div class="password-field">
                                    <label for="ver_password">Confirm New Password</label>
                                    <input type="password" name="ver_password" class="form-control" id="ver_password" placeholder="Confirm New Password" required>
                                    <button type="button" class="toggle-password" data-target="ver_password">
                                        <i class="far fa-eye-slash"></i>
                                    </button>
                                </div>
                                
                                <div class="text-center">
                                    <button type="submit" name="change_password" class="btn btn-primary" style="background-color: #1DA1F2; border: none; border-radius: 30px; padding: 10px 30px;">Save Changes</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- RIGHT SIDEBAR -->
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
                foreach($who_users as $user) { 
                    $user_follow = Follow::isUserFollow($user_id , $user->id) ;
                ?>
                <div class="grid-share">
                    <a href="<?php echo $user->username; ?>">
                        <img src="assets/images/users/<?php echo $user->img; ?>" alt="" class="img-share" />
                    </a>
                    <div>
                        <a href="<?php echo $user->username; ?>" style="font-weight: 700; color: black; text-decoration: none;">  
                            <?php echo $user->name; ?>
                        </a>
                        <span class="username">
                            @<?php echo $user->username; ?>
                            <?php if (Follow::FollowsYou($user->id , $user_id)) { ?>
                                <span class="follows-you">Follows You</span>
                            <?php } ?>
                        </span>
                    </div>
                    <div>
                        <button class="follow-btn follow-btn-m 
                        <?= $user_follow ? 'following' : 'follow' ?>"
                        data-follow="<?php echo $user->id; ?>"
                        data-user="<?php echo $user_id; ?>"
                        data-profile="<?php echo $u_id ?? ''; ?>"
                        style="font-weight: 700;">
                        <?php if($user_follow) { ?> 
                            Following 
                        <?php } else { ?>  
                            Follow
                        <?php } ?> 
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

<script src="assets/js/search.js"></script>    
<script src="assets/js/follow.js"></script>
<script src="https://kit.fontawesome.com/38e12cc51b.js" crossorigin="anonymous"></script>
<script src="assets/js/jquery-3.5.1.min.js"></script>
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
    
    // Load more results
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
    
    // Close search results when clicking outside
    $(document).on('click', function(e) {
        if (!$(e.target).closest('.input-group').length) {
            $('.search-result').fadeOut(200);
        }
    });
    
    // ========== PASSWORD TOGGLE EYE ICON ==========
    $(document).on('click', '.toggle-password', function() {
        const targetId = $(this).data('target');
        const input = $('#' + targetId);
        const icon = $(this).find('i');
        
        if (input.attr('type') === 'password') {
            input.attr('type', 'text');
            icon.removeClass('fa-eye-slash').addClass('fa-eye');
        } else {
            input.attr('type', 'password');
            icon.removeClass('fa-eye').addClass('fa-eye-slash');
        }
    });
});
</script>

</body>
</html>
