<?php
require_once 'parkease/config/database.php';

$database = new Database();
$db = $database->getConnection();

$error = '';
$success = false;

// Handle new review submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_review') {
	$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
	$parking_id = isset($_POST['parking_id']) ? (int)$_POST['parking_id'] : 0;
	$rating = isset($_POST['rating']) ? (int)$_POST['rating'] : 0;
	$comment = isset($_POST['comment']) ? trim($_POST['comment']) : '';
	$title = isset($_POST['title']) ? trim($_POST['title']) : 'Review';

	if ($user_id && $parking_id && $rating >= 1 && $rating <= 5 && strlen($comment) >= 10) {
		$insert = $db->prepare("INSERT INTO reviews (parking_id, user_id, rating, comment, created_at) VALUES (:parking_id, :user_id, :rating, :comment, NOW())");
		$insert->bindParam(':parking_id', $parking_id);
		$insert->bindParam(':user_id', $user_id);
		$insert->bindParam(':rating', $rating);
		$insert->bindParam(':comment', $comment);
		if ($insert->execute()) {
			$success = true;
		} else {
			$error = 'Unable to save review. Please try again.';
		}
	} else {
		if (strlen($comment) < 10) {
			$error = 'Review must be at least 10 characters long.';
		} else {
			$error = 'Please fill in all fields correctly.';
		}
	}
}

// Filters & pagination
$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$filter_rating = isset($_GET['rating']) ? (int)$_GET['rating'] : 0;
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'newest';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 10;
$offset = ($page - 1) * $perPage;

$where = [];
$params = [];

if ($q !== '') {
	$where[] = '(p.name LIKE :q OR u.first_name LIKE :q OR u.last_name LIKE :q OR r.comment LIKE :q)';
	$params[':q'] = "%$q%";
}

if ($filter_rating >= 1 && $filter_rating <= 5) {
	$where[] = 'r.rating = :rating';
	$params[':rating'] = $filter_rating;
}

$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Sorting
switch ($sort) {
	case 'oldest':
		$order_sql = 'r.created_at ASC';
		break;
	case 'rating_high':
		$order_sql = 'r.rating DESC, r.created_at DESC';
		break;
	case 'rating_low':
		$order_sql = 'r.rating ASC, r.created_at DESC';
		break;
	default:
		$order_sql = 'r.created_at DESC';
}

// Count total
$count_sql = "SELECT COUNT(*) FROM reviews r JOIN users u ON r.user_id = u.id JOIN parking_spaces p ON r.parking_id = p.id $where_sql";
$count_stmt = $db->prepare($count_sql);
foreach ($params as $k => $v) { $count_stmt->bindValue($k, $v); }
$count_stmt->execute();
$total = (int)$count_stmt->fetchColumn();

// Fetch reviews
$sql = "SELECT r.*, u.first_name, u.last_name, p.name as parking_name
		FROM reviews r
		JOIN users u ON r.user_id = u.id
		JOIN parking_spaces p ON r.parking_id = p.id
		$where_sql
		ORDER BY $order_sql
		LIMIT :limit OFFSET :offset";

$stmt = $db->prepare($sql);
foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
$stmt->bindValue(':limit', (int)$perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
$stmt->execute();
$reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch parking options for review form
$parks_stmt = $db->query("SELECT id, name FROM parking_spaces WHERE is_active = 1 ORDER BY name ASC");
$parking_options = $parks_stmt->fetchAll(PDO::FETCH_ASSOC);

// Helper for building query strings
function build_query($overrides = []) {
	$params = array_merge($_GET, $overrides);
	return http_build_query($params);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Community Reviews - ParkEase</title>
	<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
	<style>
		* { margin: 0; padding: 0; box-sizing: border-box; }
		
		body {
			font-family: 'Inter', sans-serif;
			background: linear-gradient(135deg, #F8FAFC 0%, #F0F4F8 100%);
			color: #111827;
			min-height: 100vh;
			padding: 40px 20px;
		}
		
		.container {
			max-width: 1200px;
			margin: 0 auto;
		}
		
		/* Header Section */
		.header {
			margin-bottom: 40px;
		}
		
		.back-link {
			display: inline-flex;
			align-items: center;
			gap: 8px;
			color: #4F6EF7;
			text-decoration: none;
			font-weight: 500;
			font-size: 14px;
			margin-bottom: 20px;
			transition: all 0.3s ease;
		}
		
		.back-link:hover {
			gap: 12px;
			color: #7C3AED;
		}
		
		.page-title {
			font-size: 36px;
			font-weight: 800;
			color: #111827;
			margin-bottom: 8px;
			background: linear-gradient(135deg, #4F6EF7, #7C3AED);
			-webkit-background-clip: text;
			-webkit-text-fill-color: transparent;
			background-clip: text;
		}
		
		.page-subtitle {
			color: #6B7280;
			font-size: 16px;
		}
		
		/* Stats Bar */
		.stats-bar {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
			gap: 16px;
			margin-bottom: 30px;
		}
		
		.stat-card {
			background: white;
			padding: 20px;
			border-radius: 12px;
			box-shadow: 0 4px 15px rgba(15, 23, 42, 0.08);
			text-align: center;
			border-left: 4px solid #4F6EF7;
		}
		
		.stat-number {
			font-size: 28px;
			font-weight: 700;
			color: #4F6EF7;
		}
		
		.stat-label {
			color: #6B7280;
			font-size: 14px;
			margin-top: 4px;
		}
		
		/* Main Card */
		.card {
			background: white;
			padding: 30px;
			border-radius: 16px;
			box-shadow: 0 10px 40px rgba(15, 23, 42, 0.1);
			margin-bottom: 24px;
			transition: box-shadow 0.3s ease;
		}
		
		.card:hover {
			box-shadow: 0 15px 50px rgba(15, 23, 42, 0.12);
		}
		
		/* Messages */
		.msg {
			padding: 16px 20px;
			border-radius: 12px;
			margin-bottom: 20px;
			display: flex;
			align-items: center;
			gap: 12px;
			font-weight: 500;
			animation: slideDown 0.3s ease;
		}
		
		@keyframes slideDown {
			from {
				opacity: 0;
				transform: translateY(-10px);
			}
			to {
				opacity: 1;
				transform: translateY(0);
			}
		}
		
		.msg.success {
			background: linear-gradient(135deg, #ECFDF5, #D1FAE5);
			color: #065F46;
			border-left: 4px solid #10B981;
		}
		
		.msg.error {
			background: linear-gradient(135deg, #FEF2F2, #FECACA);
			color: #7F1D1D;
			border-left: 4px solid #EF4444;
		}
		
		/* Filters Section */
		.filters-header {
			display: flex;
			justify-content: space-between;
			align-items: center;
			margin-bottom: 20px;
		}
		
		.filters-header h2 {
			font-size: 20px;
			color: #111827;
		}
		
		.filter-clear {
			color: #4F6EF7;
			cursor: pointer;
			font-size: 14px;
			text-decoration: none;
			transition: color 0.3s ease;
		}
		
		.filter-clear:hover {
			color: #7C3AED;
		}
		
		.filters {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
			gap: 12px;
			margin-bottom: 20px;
		}
		
		.filters input[type="text"],
		.filters select {
			padding: 12px 16px;
			border: 1.5px solid #E5E7EB;
			border-radius: 8px;
			font-size: 14px;
			font-family: 'Inter', sans-serif;
			transition: all 0.3s ease;
			background: white;
		}
		
		.filters input[type="text"]:focus,
		.filters select:focus {
			outline: none;
			border-color: #4F6EF7;
			box-shadow: 0 0 0 3px rgba(79, 110, 247, 0.1);
		}
		
		.filter-btn-group {
			display: flex;
			gap: 8px;
		}
		
		.filters button {
			background: linear-gradient(135deg, #4F6EF7, #7C3AED);
			color: white;
			border: none;
			padding: 12px 24px;
			border-radius: 8px;
			cursor: pointer;
			font-weight: 600;
			transition: all 0.3s ease;
			flex: 1;
		}
		
		.filters button:hover {
			transform: translateY(-2px);
			box-shadow: 0 8px 20px rgba(79, 110, 247, 0.3);
		}
		
		/* Reviews List */
		.reviews-list {
			display: flex;
			flex-direction: column;
			gap: 16px;
		}
		
		.review-card {
			border: 1.5px solid #E5E7EB;
			border-radius: 12px;
			padding: 20px;
			transition: all 0.3s ease;
			background: linear-gradient(135deg, #FAFBFC 0%, #F3F4F6 100%);
		}
		
		.review-card:hover {
			border-color: #4F6EF7;
			box-shadow: 0 8px 24px rgba(79, 110, 247, 0.15);
			transform: translateY(-2px);
		}
		
		.review-top {
			display: flex;
			justify-content: space-between;
			align-items: start;
			gap: 16px;
			margin-bottom: 12px;
		}
		
		.review-left {
			flex: 1;
		}
		
		.parking-name {
			font-weight: 700;
			color: #111827;
			font-size: 16px;
			margin-bottom: 4px;
		}
		
		.review-meta {
			color: #6B7280;
			font-size: 13px;
			display: flex;
			gap: 8px;
			align-items: center;
		}
		
		.review-meta-dot {
			width: 3px;
			height: 3px;
			background: #D1D5DB;
			border-radius: 50%;
		}
		
		.review-rating {
			display: flex;
			align-items: center;
			gap: 8px;
		}
		
		.stars {
			color: #F59E0B;
			font-size: 16px;
			letter-spacing: 2px;
		}
		
		.rating-number {
			background: #FEF3C7;
			color: #92400E;
			padding: 4px 8px;
			border-radius: 6px;
			font-weight: 600;
			font-size: 12px;
		}
		
		.review-comment {
			color: #374151;
			line-height: 1.6;
			margin-bottom: 12px;
			font-size: 14px;
		}
		
		.review-footer {
			display: flex;
			gap: 12px;
			padding-top: 12px;
			border-top: 1px solid #E5E7EB;
		}
		
		/* Empty State */
		.no-reviews {
			text-align: center;
			padding: 60px 20px;
		}
		
		.empty-icon {
			font-size: 60px;
			margin-bottom: 16px;
		}
		
		.no-reviews-text {
			color: #6B7280;
			font-size: 16px;
		}
		
		/* Pagination */
		.pagination {
			display: flex;
			justify-content: center;
			gap: 8px;
			margin-top: 24px;
			flex-wrap: wrap;
		}
		
		.page {
			padding: 10px 14px;
			background: white;
			border: 1.5px solid #E5E7EB;
			border-radius: 8px;
			cursor: pointer;
			text-decoration: none;
			color: #4F6EF7;
			font-weight: 600;
			font-size: 14px;
			transition: all 0.3s ease;
		}
		
		.page:hover {
			border-color: #4F6EF7;
			box-shadow: 0 4px 12px rgba(79, 110, 247, 0.1);
			transform: translateY(-2px);
		}
		
		.page.active {
			background: linear-gradient(135deg, #4F6EF7, #7C3AED);
			color: white;
			border-color: transparent;
		}
		
		/* Review Form Section */
		.form-header {
			margin-bottom: 24px;
		}
		
		.form-header h2 {
			font-size: 22px;
			color: #111827;
			margin-bottom: 4px;
		}
		
		.form-header p {
			color: #6B7280;
			font-size: 14px;
		}
		
		.form-row {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
			gap: 16px;
			margin-bottom: 16px;
		}
		
		.form-row textarea {
			grid-column: 1 / -1;
		}
		
		.form-row select,
		.form-row input,
		.form-row textarea {
			padding: 12px 16px;
			border: 1.5px solid #E5E7EB;
			border-radius: 8px;
			font-family: 'Inter', sans-serif;
			font-size: 14px;
			transition: all 0.3s ease;
		}
		
		.form-row select:focus,
		.form-row input:focus,
		.form-row textarea:focus {
			outline: none;
			border-color: #4F6EF7;
			box-shadow: 0 0 0 3px rgba(79, 110, 247, 0.1);
		}
		
		.form-row textarea {
			resize: vertical;
			min-height: 120px;
		}
		
		.form-actions {
			display: flex;
			gap: 12px;
		}
		
		.btn-submit {
			background: linear-gradient(135deg, #10B981, #059669);
			color: white;
			border: none;
			padding: 12px 28px;
			border-radius: 8px;
			cursor: pointer;
			font-weight: 600;
			font-size: 14px;
			transition: all 0.3s ease;
			flex: 1;
			max-width: 200px;
		}
		
		.btn-submit:hover {
			transform: translateY(-2px);
			box-shadow: 0 8px 20px rgba(16, 185, 129, 0.3);
		}
		
		.btn-submit:disabled {
			opacity: 0.6;
			cursor: not-allowed;
		}
		
		.sign-in-prompt {
			color: #6B7280;
			font-size: 14px;
		}
		
		.sign-in-prompt a {
			color: #4F6EF7;
			text-decoration: none;
			font-weight: 600;
		}
		
		.sign-in-prompt a:hover {
			text-decoration: underline;
		}
		
		/* Responsive Design */
		@media (max-width: 768px) {
			.page-title {
				font-size: 28px;
			}
			
			.filters {
				grid-template-columns: 1fr;
			}
			
			.filter-btn-group {
				flex-direction: column;
			}
			
			.filter-btn-group button {
				width: 100%;
			}
			
			.review-top {
				flex-direction: column;
			}
			
			.form-row {
				grid-template-columns: 1fr;
			}
			
			.stats-bar {
				grid-template-columns: 1fr 1fr;
			}
		}
		
		@media (max-width: 480px) {
			.page-title {
				font-size: 24px;
			}
			
			.card {
				padding: 20px;
			}
			
			.stats-bar {
				grid-template-columns: 1fr;
			}
		}
	</style>
</head>
<body>
	<div class="container">
		<div class="header">
			<a href="parkease/index.php" class="back-link">
				<span>←</span> Back to Dashboard
			</a>
			<h1 class="page-title">Community Reviews</h1>
			<p class="page-subtitle">Discover authentic feedback from ParkEase users</p>
		</div>

		<!-- Stats Section -->
		<?php
			$stats_sql = "SELECT 
				COUNT(*) as total_reviews,
				ROUND(AVG(rating), 1) as avg_rating,
				COUNT(DISTINCT parking_id) as reviewed_parkings
				FROM reviews";
			$stats_stmt = $db->query($stats_sql);
			$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
		?>
		<div class="stats-bar">
			<div class="stat-card">
				<div class="stat-number"><?php echo number_format($stats['total_reviews']); ?></div>
				<div class="stat-label">Total Reviews</div>
			</div>
			<div class="stat-card">
				<div class="stat-number"><?php echo $stats['avg_rating']; ?></div>
				<div class="stat-label">Average Rating</div>
			</div>
			<div class="stat-card">
				<div class="stat-number"><?php echo number_format($stats['reviewed_parkings']); ?></div>
				<div class="stat-label">Parking Lots Reviewed</div>
			</div>
		</div>

		<!-- Messages -->
		<?php if ($success): ?>
			<div class="msg success">
				<span>✓</span>
				<span>Review submitted successfully! Thank you for your feedback.</span>
			</div>
		<?php endif; ?>
		<?php if (!empty($error)): ?>
			<div class="msg error">
				<span>✕</span>
				<span><?php echo htmlspecialchars($error); ?></span>
			</div>
		<?php endif; ?>

		<!-- Reviews Section -->
		<div class="card">
			<div class="filters-header">
				<h2>Browse Reviews</h2>
				<?php if ($q || $filter_rating || $sort !== 'newest'): ?>
					<a href="reviews.php" class="filter-clear">Clear all filters</a>
				<?php endif; ?>
			</div>

			<form method="get" class="filters" action="reviews.php">
				<input type="text" name="q" placeholder="Search by parking, reviewer, or keyword..." value="<?php echo htmlspecialchars($q); ?>">
				
				<select name="rating">
					<option value="0">All Ratings</option>
					<?php for ($r = 5; $r >= 1; $r--): ?>
						<option value="<?php echo $r; ?>" <?php echo $filter_rating === $r ? 'selected' : ''; ?>>
							★ <?php echo $r; ?> Star<?php echo $r > 1 ? 's' : ''; ?>
						</option>
					<?php endfor; ?>
				</select>
				
				<select name="sort">
					<option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Newest First</option>
					<option value="oldest" <?php echo $sort === 'oldest' ? 'selected' : ''; ?>>Oldest First</option>
					<option value="rating_high" <?php echo $sort === 'rating_high' ? 'selected' : ''; ?>>Highest Rated</option>
					<option value="rating_low" <?php echo $sort === 'rating_low' ? 'selected' : ''; ?>>Lowest Rated</option>
				</select>

				<div class="filter-btn-group">
					<button type="submit">Search</button>
				</div>
			</form>

			<?php if (empty($reviews)): ?>
				<div class="no-reviews">
					<div class="empty-icon">💬</div>
					<p class="no-reviews-text">No reviews found matching your search.</p>
				</div>
			<?php else: ?>
				<div class="reviews-list">
					<?php foreach ($reviews as $review): ?>
						<div class="review-card">
							<div class="review-top">
								<div class="review-left">
									<div class="parking-name"><?php echo htmlspecialchars($review['parking_name']); ?></div>
									<div class="review-meta">
										<span><?php echo htmlspecialchars($review['first_name'] . ' ' . $review['last_name']); ?></span>
										<span class="review-meta-dot"></span>
										<span><?php echo date('M d, Y', strtotime($review['created_at'])); ?></span>
									</div>
								</div>
								<div class="review-rating">
									<span class="stars">
										<?php for ($i = 1; $i <= 5; $i++) { echo $i <= $review['rating'] ? '★' : '☆'; } ?>
									</span>
									<span class="rating-number"><?php echo $review['rating']; ?>.0</span>
								</div>
							</div>
							<p class="review-comment"><?php echo nl2br(htmlspecialchars($review['comment'])); ?></p>
							<div class="review-footer">
								<span style="color: #9CA3AF; font-size: 12px;">ID: <?php echo $review['id']; ?></span>
							</div>
						</div>
					<?php endforeach; ?>
				</div>

				<?php if ($total > $perPage): ?>
					<?php $totalPages = (int)ceil($total / $perPage); ?>
					<div class="pagination">
						<?php if ($page > 1): ?>
							<a class="page" href="?<?php echo build_query(['page' => 1]); ?>">First</a>
							<a class="page" href="?<?php echo build_query(['page' => $page - 1]); ?>">Previous</a>
						<?php endif; ?>

						<?php
							$start = max(1, $page - 2);
							$end = min($totalPages, $page + 2);
							if ($start > 1) echo '<span style="padding: 10px 8px;">...</span>';
							for ($p = $start; $p <= $end; $p++):
						?>
							<a class="page <?php echo $p === $page ? 'active' : ''; ?>" href="?<?php echo build_query(['page' => $p]); ?>"><?php echo $p; ?></a>
						<?php endfor; ?>
						<?php if ($end < $totalPages) echo '<span style="padding: 10px 8px;">...</span>'; ?>

						<?php if ($page < $totalPages): ?>
							<a class="page" href="?<?php echo build_query(['page' => $page + 1]); ?>">Next</a>
							<a class="page" href="?<?php echo build_query(['page' => $totalPages]); ?>">Last</a>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>

		<!-- Submit Review Section -->
		<div class="card">
			<div class="form-header">
				<h2>Share Your Experience</h2>
				<p>Help other users by leaving a genuine review</p>
			</div>

			<?php if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])): ?>
				<div class="sign-in-prompt">
					Please <a href="parkease/login.php">sign in</a> to leave a review. Don't have an account? <a href="parkease/register.php">Create one here</a>.
				</div>
			<?php else: ?>
				<form method="post" action="reviews.php">
					<input type="hidden" name="action" value="add_review">

					<div class="form-row">
						<select name="parking_id" required>
							<option value="">Select Parking Lot *</option>
							<?php foreach ($parking_options as $p): ?>
								<option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['name']); ?></option>
							<?php endforeach; ?>
						</select>

						<select name="rating" required>
							<option value="">Rating *</option>
							<?php for ($r = 5; $r >= 1; $r--): ?>
								<option value="<?php echo $r; ?>">
									<?php echo str_repeat('★', $r); ?> <?php echo $r; ?> Star<?php echo $r > 1 ? 's' : ''; ?>
								</option>
							<?php endfor; ?>
						</select>
					</div>

					<div class="form-row">
						<textarea name="comment" placeholder="Share your detailed experience (minimum 10 characters)..." required></textarea>
					</div>

					<div class="form-actions">
						<button type="submit" class="btn-submit">Post Review</button>
					</div>
				</form>
			<?php endif; ?>
		</div>	</div>
</body>
</html>