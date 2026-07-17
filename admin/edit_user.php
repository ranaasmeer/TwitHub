<?php
require_once '../core/init.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: index.php');
    exit;
}

$pdo = Connect::connect();

if (!isset($_GET['id'])) {
    header('Location: users.php');
    exit;
}

$userId = (int)$_GET['id'];

// Fetch user data
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    header('Location: users.php');
    exit;
}

// Handle form submission
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $bio = trim($_POST['bio']);
    $location = trim($_POST['location']);
    $website = trim($_POST['website']);
    $is_verified = isset($_POST['is_verified']) ? 1 : 0;

    if (!$username) $errors[] = 'Username is required.';
    if (!$name) $errors[] = 'Name is required.';
    if (!$email) $errors[] = 'Email is required.';

    // Handle avatar upload
    if (isset($_FILES['img']) && $_FILES['img']['error'] === 0) {
        $allowed = ['jpg','jpeg','png','gif'];
        $file_ext = pathinfo($_FILES['img']['name'], PATHINFO_EXTENSION);
        if (!in_array(strtolower($file_ext), $allowed)) {
            $errors[] = 'Avatar must be an image (jpg, png, gif).';
        } else {
            $img_name = uniqid() . '.' . $file_ext;
            move_uploaded_file($_FILES['img']['tmp_name'], "../assets/images/users/$img_name");

        }
    } else {
        $img_name = $user['img']; // keep old image if not uploaded
    }

    if (empty($errors)) {
        $stmt = $pdo->prepare("UPDATE users SET username=?, name=?, email=?, bio=?, location=?, website=?, is_verified=?, img=? WHERE id=?");
        $stmt->execute([$username, $name, $email, $bio, $location, $website, $is_verified, $img_name, $userId]);
        header("Location: users.php");
        exit;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Edit User · Admin Panel</title>
  <link rel="icon" href="../assets/images/twitter-logo.png" type="image/png">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    :root {
      --primary: #1DA1F2;
      --secondary: #14171A;
      --light: #f5f8fa;
      --white: #fff;
      --gray: #657786;
      --danger: #e0245e;
      --success: #17bf63;
    }
    body.dark {
      --primary: #1DA1F2;
      --secondary: #e6ecf0;
      --light: #1a1d21;
      --white: #1c1f23;
      --gray: #8899a6;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Poppins', sans-serif; transition: background 0.3s, color 0.3s; }
    body { display: flex; min-height: 100vh; background: var(--light); color: var(--secondary); }

    /* Sidebar */
    .sidebar { width: 250px; background: var(--secondary); color: var(--white); display: flex; flex-direction: column; justify-content: space-between; padding: 20px 0; position: fixed; left: 0; top: 0; bottom: 0; }
    .sidebar-header { text-align: center; margin-bottom: 20px; }
    .sidebar-logo { width: 60px; margin-bottom: 10px; }
    .sidebar-nav a { color: var(--white); text-decoration: none; padding: 14px 25px; display: flex; align-items: center; gap: 10px; font-size: 15px; transition: all 0.3s; }
    .sidebar-nav a:hover, .sidebar-nav a.active { background: var(--primary); border-radius: 0 20px 20px 0; }
    .sidebar-footer { text-align: center; padding: 15px; }
    .logout-btn { color: var(--white); text-decoration: none; background: var(--danger); padding: 10px 20px; border-radius: 25px; transition: 0.3s; }
    .logout-btn:hover { background: #ff4d6d; }

    /* Main */
    .main-content { margin-left: 250px; flex: 1; padding: 30px; }
    .topbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
    .theme-toggle { background: none; border: none; color: var(--primary); font-size: 22px; cursor: pointer; }

    h1 { margin-bottom: 20px; }

    /* Form */
    form { background: var(--white); padding: 30px; border-radius: 10px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); max-width: 700px; }
    form .form-group { margin-bottom: 15px; }
    form label { display: block; font-weight: 600; margin-bottom: 5px; }
    form input[type="text"],
    form input[type="email"],
    form textarea { width: 100%; padding: 10px 15px; border-radius: 8px; border: 1px solid #ccc; font-size: 14px; }
    form input[type="file"] { font-size: 14px; }
    form input[type="checkbox"] { margin-right: 5px; }
    form button { padding: 10px 25px; border: none; background: var(--primary); color: #fff; font-weight: 600; border-radius: 25px; cursor: pointer; }
    form button:hover { background: #0d8ddb; }

    .errors { margin-bottom: 20px; color: var(--danger); }
    .current-avatar img { width: 80px; height: 80px; border-radius: 50%; object-fit: cover; margin-bottom: 10px; }
  </style>
</head>
<body>

  <!-- Sidebar -->
  <aside class="sidebar">
    <div>
      <div class="sidebar-header">
        <img src="../assets/images/twitter-logo.png" alt="logo" class="sidebar-logo">
        <h2>Admin Panel</h2>
      </div>
      <nav class="sidebar-nav">
        <a href="dashboard.php"><i class="fa-solid fa-house"></i> Dashboard</a>
        <a href="users.php" class="active"><i class="fa-solid fa-users"></i> Manage Users</a>
        <a href="tweets.php"><i class="fa-brands fa-twitter"></i> Manage Tweets</a>
        <a href="comments.php"><i class="fa-solid fa-comments"></i> Manage Comments</a>
        <a href="trends.php"><i class="fa-solid fa-chart-line"></i> Manage Trends</a>
        <a href="replies.php"><i class="fa-solid fa-reply"></i> Manage Replies</a>
        <a href="quotes.php"><i class="fa-solid fa-retweet"></i> Manage Quotes/Retweets</a>
        <a href="bookmarks.php"><i class="fa-solid fa-bookmark"></i> Manage Bookmarks</a>
        <a href="user_analytics.php"><i class="fa-solid fa-chart-simple"></i> User Analytics</a>
      </nav>
    </div>
    <div class="sidebar-footer">
      <a href="logout.php" class="logout-btn"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
    </div>
  </aside>
  

  <!-- Main -->
  <main class="main-content">
    <div class="topbar">
      <h1>Edit User</h1>
      <button id="themeToggle" class="theme-toggle"><i class="fa-solid fa-moon"></i></button>
    </div>

    <?php if (!empty($errors)): ?>
      <div class="errors">
        <ul>
          <?php foreach ($errors as $err): ?>
            <li><?php echo htmlspecialchars($err); ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <form action="" method="POST" enctype="multipart/form-data">
      <div class="form-group">
        <label>Username</label>
        <input type="text" name="username" value="<?php echo htmlspecialchars($user['username']); ?>" required>
      </div>

      <div class="form-group">
        <label>Name</label>
        <input type="text" name="name" value="<?php echo htmlspecialchars($user['name']); ?>" required>
      </div>

      <div class="form-group">
        <label>Email</label>
        <input type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" required>
      </div>

      <div class="form-group">
        <label>Bio</label>
        <textarea name="bio" rows="3"><?php echo htmlspecialchars($user['bio']); ?></textarea>
      </div>

      <div class="form-group">
        <label>Location</label>
        <input type="text" name="location" value="<?php echo htmlspecialchars($user['location']); ?>">
      </div>

      <div class="form-group">
        <label>Website</label>
        <input type="text" name="website" value="<?php echo htmlspecialchars($user['website']); ?>">
      </div>

      <div class="form-group current-avatar">
        <label>Current Avatar</label><br>
        <img src="../assets/images/users/<?php echo htmlspecialchars($user['img']); ?>" alt="avatar">
      </div>

      <div class="form-group">
        <label>Change Avatar</label>
        <input type="file" name="img" accept="image/*">
      </div>

      <div class="form-group">
        <label><input type="checkbox" name="is_verified" <?php echo $user['is_verified'] ? 'checked' : ''; ?>> Verified</label>
      </div>

      <button type="submit">Update User</button>
    </form>
  </main>

  <script>
    const body = document.body;
    const toggleBtn = document.getElementById('themeToggle');
    const icon = toggleBtn.querySelector('i');

    if (localStorage.getItem('theme') === 'dark') {
      body.classList.add('dark');
      icon.classList.replace('fa-moon', 'fa-sun');
    }

    toggleBtn.addEventListener('click', () => {
      body.classList.toggle('dark');
      const isDark = body.classList.contains('dark');
      icon.classList.toggle('fa-sun', isDark);
      icon.classList.toggle('fa-moon', !isDark);
      localStorage.setItem('theme', isDark ? 'dark' : 'light');
    });
  </script>

</body>
</html>
