<?php
// admin/index.php
session_start();
if (isset($_SESSION['admin_id'])) {
    header('Location: dashboard.php');
    exit;
}
$err = $_SESSION['admin_err'] ?? null;
unset($_SESSION['admin_err']);
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Admin Login · TwitterClone</title>
  <link rel="stylesheet" href="assets/css/admin.css">
</head>
<body>
  <div class="container">
    <div class="card">
      <div class="logo">
        <img src="../assets/images/twitter-logo.png" alt="logo" style="height:34px">
        <div class="brand">TwitterClone Admin</div>
      </div>

      <h2>Sign in to admin</h2>

      <?php if ($err): ?>
        <div class="alert"><?php echo htmlspecialchars($err); ?></div>
      <?php endif; ?>

      <form action="login.php" method="post" autocomplete="off">
        <div class="form-group">
          <input class="form-control" name="username" required placeholder="Username" />
        </div>
        <div class="form-group" style="position:relative">
          <input id="password" class="form-control" name="password" required type="password" placeholder="Password" />
          <button type="button" id="togglePw" style="position:absolute; right:8px; top:8px; background:none;border:0;cursor:pointer">Show</button>
        </div>

        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:14px">
          <button class="btn" type="submit">Sign in</button>
          <div class="small">Only authorized admins</div>
        </div>
      </form>
    </div>
  </div>

<script src="assets/js/admin.js"></script>
</body>
</html>
