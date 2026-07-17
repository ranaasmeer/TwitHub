<?php
include '../core/init.php';

if (!isset($_SESSION['user_id'])) {
    exit;
}

$user_id = $_SESSION['user_id'];
$search = isset($_POST['search']) ? $_POST['search'] : '';
$offset = isset($_POST['offset']) ? (int)$_POST['offset'] : 0;

if (strlen($search) < 2) {
    exit;
}

$pdo = Connect::connect();

// Get total count for "load more" button
$stmt = $pdo->prepare("
    SELECT COUNT(*) as total
    FROM users 
    WHERE username LIKE :search 
    OR name LIKE :search
");
$stmt->execute(['search' => '%' . $search . '%']);
$total = $stmt->fetch(PDO::FETCH_OBJ)->total;

// Get paginated results - FIXED: Use bindValue for offset as integer
$stmt = $pdo->prepare("
    SELECT id, username, name, img, bio 
    FROM users 
    WHERE username LIKE :search 
    OR name LIKE :search 
    LIMIT 10 OFFSET :offset
");
$stmt->bindValue(':search', '%' . $search . '%', PDO::PARAM_STR);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();

$results = $stmt->fetchAll(PDO::FETCH_OBJ);
$hasMore = ($offset + 10) < $total;

foreach ($results as $user) {
    $followsYou = Follow::FollowsYou($user->id, $user_id);
    ?>
    <a href="<?php echo BASE_URL . $user->username; ?>" class="search-result-item">
        <img src="assets/images/users/<?php echo $user->img; ?>" alt="<?php echo htmlspecialchars($user->name); ?>" class="search-result-img">
        <div class="search-result-info">
            <div class="search-result-name">
                <?php echo htmlspecialchars($user->name); ?>
                <?php if ($followsYou): ?>
                    <span class="search-result-badge">Follows you</span>
                <?php endif; ?>
            </div>
            <div class="search-result-username">@<?php echo htmlspecialchars($user->username); ?></div>
            <?php if (!empty($user->bio)): ?>
                <div class="search-result-bio"><?php echo htmlspecialchars(substr($user->bio, 0, 60)); ?></div>
            <?php endif; ?>
        </div>
    </a>
    <?php
}

if (empty($results) && $offset == 0) {
    echo '<div class="search-no-results"><i class="fas fa-user-slash"></i> No users found</div>';
} elseif ($hasMore) {
    echo '<div class="search-load-more" data-offset="' . ($offset + 10) . '" data-query="' . htmlspecialchars($search) . '">
            <button class="load-more-search"><i class="fas fa-chevron-down"></i> Load more users</button>
          </div>';
}
?>