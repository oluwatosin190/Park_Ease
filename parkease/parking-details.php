<?php
session_start();
require_once 'config/database.php';

// Function to sanitize output
function sanitize($input) {
    return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
}

// Function to get image URL safely
function getImageUrl($image, $default = 'img/parking-placeholder.jpg') {
    if (!empty($image)) {
        $image_path = 'uploads/parking/' . $image;
        return file_exists($image_path) ? $image_path : $default;
    }
    return $default;
}

// Check if user is logged in and get user type
$is_owner = false;
if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
    $user_type = isset($_SESSION['user_type']) ? $_SESSION['user_type'] : '';
    $is_owner = ($user_type === 'owner');
}

$database = new Database();
$db = $database->getConnection();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    header('Location: index.php');
    exit();
}

// Get parking space details
$query = "SELECT ps.*, 
          u.first_name as owner_name,
          u.company_name,
          COALESCE(AVG(r.rating), 0) as avg_rating,
          COUNT(DISTINCT r.id) as review_count
          FROM parking_spaces ps
          JOIN users u ON ps.owner_id = u.id
          LEFT JOIN reviews r ON ps.id = r.parking_id
          WHERE ps.id = :id AND ps.is_active = 1
          GROUP BY ps.id";

try {
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    $space = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Parking details query error: " . $e->getMessage());
    header('Location: index.php');
    exit();
}

if (!$space) {
    header('Location: index.php');
    exit();
}

// Get images safely
$images = !empty($space['images']) ? json_decode($space['images'], true) : [];
$valid_images = [];
foreach ($images as $image) {
    if (file_exists('uploads/parking/' . $image)) {
        $valid_images[] = $image;
    }
}
$images = $valid_images;

// Get reviews
$reviews_query = "SELECT r.*, u.first_name, u.last_name 
                  FROM reviews r
                  JOIN users u ON r.user_id = u.id
                  WHERE r.parking_id = :id
                  ORDER BY r.created_at DESC";
try {
    $reviews_stmt = $db->prepare($reviews_query);
    $reviews_stmt->bindParam(':id', $id, PDO::PARAM_INT);
    $reviews_stmt->execute();
    $reviews = $reviews_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Reviews query error: " . $e->getMessage());
    $reviews = [];
}

$amenities = !empty($space['amenities']) ? json_decode($space['amenities'], true) : [];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta name="description" content="<?php echo sanitize($space['name']); ?> - Parking space in <?php echo sanitize($space['city']); ?>">
    <meta name="robots" content="index, follow">
    <title><?php echo sanitize($space['name']); ?> - SpaceNode</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800;900&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'DM Sans', sans-serif;
            background: radial-gradient(ellipse at 0% 0%, #1a1a2e 0%, #16213e 40%, #0f0f23 100%);
            min-height: 100vh;
            padding: 40px 20px;
            position: relative;
            overflow-x: hidden;
        }
        
        /* Animated mesh gradient overlay */
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: radial-gradient(circle at 20% 50%, rgba(79,110,247,0.15) 0%, transparent 50%),
                        radial-gradient(circle at 80% 80%, rgba(124,58,237,0.15) 0%, transparent 50%),
                        radial-gradient(circle at 40% 20%, rgba(236,72,153,0.1) 0%, transparent 50%);
            pointer-events: none;
            z-index: 0;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            position: relative;
            z-index: 1;
        }
        
        /* Glassmorphism Back Link */
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 24px;
            color: rgba(255,255,255,0.9);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            padding: 10px 20px;
            background: rgba(255,255,255,0.08);
            backdrop-filter: blur(10px);
            border-radius: 50px;
            border: 1px solid rgba(255,255,255,0.15);
            transition: all 0.3s ease;
        }
        
        .back-link:hover {
            background: rgba(255,255,255,0.15);
            transform: translateY(-2px);
            border-color: rgba(255,255,255,0.3);
        }
        
        .back-link i {
            font-size: 14px;
        }
        
        /* Glassmorphism Parking Header */
        .parking-header {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 40px;
            margin-bottom: 40px;
            background: rgba(255,255,255,0.06);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 32px;
            padding: 32px;
            transition: all 0.4s ease;
            box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.2);
        }
        
        .parking-header:hover {
            background: rgba(255,255,255,0.08);
            box-shadow: 0 16px 48px 0 rgba(0, 0, 0, 0.3);
        }
        
        /* Image Section */
        .image-section {
            width: 100%;
        }
        
        .parking-image {
            width: 100%;
            height: 350px;
            border-radius: 24px;
            overflow: hidden;
            position: relative;
            background: linear-gradient(135deg, #1a1a2e, #16213e);
            box-shadow: 0 8px 32px rgba(0,0,0,0.2);
        }
        
        .main-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s ease;
        }
        
        .main-image:hover {
            transform: scale(1.02);
        }
        
        /* Image Gallery */
        .image-gallery {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-top: 16px;
        }
        
        .gallery-thumb {
            width: 100%;
            height: 85px;
            border-radius: 16px;
            cursor: pointer;
            object-fit: cover;
            border: 2px solid transparent;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .gallery-thumb:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(79,110,247,0.2);
        }
        
        .gallery-thumb.active {
            border-color: #a5b4fc;
            transform: scale(0.98);
            box-shadow: 0 0 0 2px rgba(165,180,252,0.4);
        }
        
        .no-image {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            color: rgba(255,255,255,0.5);
            font-size: 16px;
            background: rgba(255,255,255,0.05);
        }
        
        .no-image svg {
            width: 60px;
            height: 60px;
            margin-bottom: 10px;
            opacity: 0.4;
        }
        
        /* Parking Info */
        .parking-info h1 {
            font-family: 'Outfit', sans-serif;
            font-size: 32px;
            font-weight: 700;
            background: linear-gradient(135deg, #fff, #a5b4fc);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 12px;
            letter-spacing: -0.5px;
        }
        
        .parking-location {
            display: flex;
            align-items: center;
            gap: 8px;
            color: rgba(255,255,255,0.7);
            margin-bottom: 20px;
            font-size: 14px;
        }
        
        .rating {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 24px;
            padding: 12px 0;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            flex-wrap: wrap;
        }
        
        .stars {
            color: #FBBF24;
            font-size: 18px;
            letter-spacing: 2px;
        }
        
        .rating-number {
            font-weight: 600;
            color: white;
        }
        
        .review-count {
            color: rgba(255,255,255,0.6);
            font-size: 14px;
        }
        
        /* Glassmorphism Price Box */
        .price-box {
            background: rgba(255,255,255,0.05);
            backdrop-filter: blur(10px);
            padding: 24px;
            border-radius: 24px;
            margin-bottom: 24px;
            border: 1px solid rgba(255,255,255,0.1);
        }
        
        .price-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
        }
        
        .price-item {
            text-align: center;
        }
        
        .price-item .label {
            font-size: 12px;
            color: rgba(255,255,255,0.5);
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .price-item .value {
            font-family: 'Outfit', sans-serif;
            font-size: 26px;
            font-weight: 800;
            background: linear-gradient(135deg, #a5b4fc, #c4b5fd);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .price-item .value::before {
            content: '₦';
            font-size: 16px;
            font-weight: 600;
        }
        
        .description {
            margin: 20px 0;
            line-height: 1.7;
            color: rgba(255,255,255,0.8);
            font-size: 14px;
        }
        
        .capacity {
            margin: 20px 0;
            padding: 16px;
            background: rgba(79,110,247,0.1);
            border-radius: 60px;
            color: #a5b4fc;
            font-weight: 600;
            text-align: center;
            border: 1px solid rgba(79,110,247,0.2);
            backdrop-filter: blur(5px);
        }
        
        /* Glassmorphism Book Button */
        .btn-book {
            background: linear-gradient(135deg, #4F6EF7, #7C3AED);
            color: white;
            border: none;
            padding: 16px 30px;
            border-radius: 60px;
            font-size: 16px;
            font-weight: 600;
            font-family: 'Outfit', sans-serif;
            cursor: pointer;
            width: 100%;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(79,110,247,0.3);
        }
        
        .btn-book::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s ease;
        }
        
        .btn-book:hover::before {
            left: 100%;
        }
        
        .btn-book:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 12px 30px rgba(79,110,247,0.4);
        }
        
        .btn-book:disabled {
            background: rgba(156,163,175,0.6);
            cursor: not-allowed;
            opacity: 0.7;
            box-shadow: none;
        }
        
        /* Glassmorphism Amenities Section */
        .amenities-section {
            background: rgba(255,255,255,0.06);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 28px;
            padding: 32px;
            margin-bottom: 30px;
            transition: all 0.3s ease;
        }
        
        .amenities-section:hover {
            background: rgba(255,255,255,0.08);
            box-shadow: 0 8px 32px rgba(0,0,0,0.2);
        }
        
        .amenities-section h2 {
            font-family: 'Outfit', sans-serif;
            font-size: 22px;
            font-weight: 600;
            color: white;
            margin-bottom: 24px;
            letter-spacing: -0.3px;
        }
        
        .amenities-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            gap: 12px;
        }
        
        .amenity-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 16px;
            background: rgba(255,255,255,0.05);
            border-radius: 50px;
            color: rgba(255,255,255,0.8);
            font-size: 14px;
            transition: all 0.3s ease;
            border: 1px solid rgba(255,255,255,0.1);
        }
        
        .amenity-item:hover {
            background: rgba(79,110,247,0.1);
            border-color: rgba(165,180,252,0.3);
            transform: translateX(5px);
        }
        
        .amenity-item::before {
            content: '✓';
            color: #4ade80;
            font-weight: 600;
            margin-right: 5px;
        }
        
        /* Glassmorphism Reviews Section */
        .reviews-section {
            background: rgba(255,255,255,0.06);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 28px;
            padding: 32px;
            transition: all 0.3s ease;
        }
        
        .reviews-section:hover {
            background: rgba(255,255,255,0.08);
            box-shadow: 0 8px 32px rgba(0,0,0,0.2);
        }
        
        .reviews-section h2 {
            font-family: 'Outfit', sans-serif;
            font-size: 22px;
            font-weight: 600;
            color: white;
            margin-bottom: 24px;
            letter-spacing: -0.3px;
        }
        
        .review-card {
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 20px;
            padding: 20px;
            margin-bottom: 16px;
            transition: all 0.3s ease;
        }
        
        .review-card:hover {
            background: rgba(255,255,255,0.08);
            border-color: rgba(165,180,252,0.3);
            transform: translateX(5px);
        }
        
        .review-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .reviewer-name {
            font-weight: 600;
            color: white;
            font-size: 15px;
        }
        
        .review-date {
            color: rgba(255,255,255,0.5);
            font-size: 12px;
        }
        
        .review-rating {
            color: #FBBF24;
            margin-bottom: 12px;
            font-size: 14px;
            letter-spacing: 2px;
        }
        
        .review-comment {
            color: rgba(255,255,255,0.8);
            line-height: 1.6;
            font-size: 14px;
        }
        
        .no-reviews {
            text-align: center;
            padding: 50px 20px;
            color: rgba(255,255,255,0.6);
        }
        
        .no-reviews svg {
            width: 70px;
            height: 70px;
            margin-bottom: 15px;
            opacity: 0.4;
            stroke: rgba(255,255,255,0.5);
        }
        
        /* Responsive Design */
        @media (max-width: 1024px) {
            .price-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }
        
        @media (max-width: 768px) {
            body {
                padding: 20px 15px;
            }
            
            .parking-header {
                grid-template-columns: 1fr;
                gap: 25px;
                padding: 24px;
            }
            
            .parking-image {
                height: 280px;
            }
            
            .image-gallery {
                grid-template-columns: repeat(4, 1fr);
            }
            
            .gallery-thumb {
                height: 65px;
            }
            
            .price-grid {
                grid-template-columns: 1fr;
                gap: 12px;
            }
            
            .parking-info h1 {
                font-size: 26px;
            }
            
            .amenities-section, .reviews-section {
                padding: 24px;
            }
            
            .amenities-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 480px) {
            .parking-header {
                padding: 20px;
            }
            
            .parking-image {
                height: 220px;
            }
            
            .gallery-thumb {
                height: 55px;
            }
            
            .price-item .value {
                font-size: 22px;
            }
            
            .review-header {
                flex-direction: column;
                align-items: flex-start;
            }
        }
        
        /* Animation for content */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .parking-header, .amenities-section, .reviews-section {
            animation: fadeInUp 0.6s ease forwards;
            opacity: 0;
        }
        
        .parking-header { animation-delay: 0.05s; }
        .amenities-section { animation-delay: 0.1s; }
        .reviews-section { animation-delay: 0.15s; }
        
        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 6px;
        }
        
        ::-webkit-scrollbar-track {
            background: rgba(255,255,255,0.05);
            border-radius: 10px;
        }
        
        ::-webkit-scrollbar-thumb {
            background: rgba(165,180,252,0.4);
            border-radius: 10px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: rgba(165,180,252,0.6);
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Dynamic Back Link based on user type -->
        <?php if ($is_owner): ?>
            <a href="dashboard.php" class="back-link">
                <i class="fas fa-tachometer-alt"></i> Back to Dashboard
            </a>
        <?php else: ?>
            <a href="index.php" class="back-link">
                <i class="fas fa-home"></i> Back to Home
            </a>
        <?php endif; ?>
        
        <div class="parking-header">
            <div>
                <div class="parking-image">
                    <?php if (!empty($images)): ?>
                        <img src="<?php echo getImageUrl($images[0]); ?>" 
                             alt="<?php echo sanitize($space['name']); ?>" 
                             class="main-image"
                             id="mainImage"
                             onerror="this.src='img/parking-placeholder.jpg';">
                    <?php else: ?>
                        <div class="no-image">
                            <i class="fas fa-image fa-3x"></i>
                            <p>No image available</p>
                        </div>
                    <?php endif; ?>
                </div>
                
                <?php if (count($images) > 1): ?>
                <div class="image-gallery">
                    <?php foreach ($images as $index => $image): ?>
                        <img src="<?php echo getImageUrl($image); ?>" 
                             class="gallery-thumb <?php echo $index == 0 ? 'active' : ''; ?>"
                             onclick="changeMainImage('<?php echo getImageUrl($image); ?>', this)"
                             onerror="this.style.display='none';"
                             alt="Parking space image <?php echo $index + 1; ?>">
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="parking-info">
                <h1><?php echo sanitize($space['name']); ?></h1>
                <div class="parking-location">
                    <i class="fas fa-map-marker-alt"></i>
                    <?php echo sanitize($space['address'] . ', ' . $space['city']); ?>
                </div>
                
                <div class="rating">
                    <span class="stars"><i class="fas fa-star"></i> <i class="fas fa-star"></i> <i class="fas fa-star"></i> <i class="fas fa-star"></i> <i class="fas fa-star-half-alt"></i></span>
                    <span class="rating-number"><?php echo number_format($space['avg_rating'], 1); ?></span>
                    <span class="review-count">(<?php echo (int)$space['review_count']; ?> reviews)</span>
                </div>
                
                <div class="price-box">
                    <div class="price-grid">
                        <?php if (!empty($space['hourly_rate'])): ?>
                        <div class="price-item">
                            <div class="label"><i class="far fa-clock"></i> Hourly</div>
                            <div class="value"><?php echo number_format($space['hourly_rate'], 0); ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($space['daily_rate'])): ?>
                        <div class="price-item">
                            <div class="label"><i class="far fa-calendar-day"></i> Daily</div>
                            <div class="value"><?php echo number_format($space['daily_rate'], 0); ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($space['monthly_rate'])): ?>
                        <div class="price-item">
                            <div class="label"><i class="far fa-calendar-alt"></i> Monthly</div>
                            <div class="value"><?php echo number_format($space['monthly_rate'], 0); ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="description">
                    <?php echo nl2br(sanitize($space['description'] ?: 'No description provided.')); ?>
                </div>
                
                <div class="capacity" id="capacity-<?php echo (int)$space['id']; ?>">
                    <i class="fas fa-parking"></i> Capacity: <span id="available-spots-<?php echo (int)$space['id']; ?>"><?php echo (int)$space['available_spots']; ?></span>/<?php echo (int)$space['total_spots']; ?> spots available
                </div>
                
               <!-- Book button - hidden for owners, visible for parkers and non-logged users -->
                    <?php if (!$is_owner): ?>
                        <?php if (($space['available_spots'] ?? 0) > 0): ?>
                            <?php if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])): ?>
                                <button class="btn-book" onclick="window.location.href='book.php?id=<?php echo (int)$space['id']; ?>'">
                                    <i class="fas fa-calendar-check"></i> Book Now (<?php echo (int)$space['available_spots']; ?> spots left)
                                </button>
                                <!-- Chat with Owner button — uses $space not $parking -->
                                <button 
                                    onclick="ParkChat.openWith(<?= (int)$space['owner_id'] ?>, <?= (int)$space['id'] ?>)"
                                    class="btn btn-primary" 
                                    style="background: linear-gradient(135deg, #4F6EF7, #7C3AED); border: none; padding: 10px 20px; border-radius: 12px; color: #fff; cursor: pointer; font-family: 'DM Sans', sans-serif; font-size: 14px; display: inline-flex; align-items: center; gap: 8px; margin-top: 10px;">
                                    <i class="fas fa-comments"></i> Chat with Owner
                                </button>
                            <?php else: ?>
                                <button class="btn-book" onclick="window.location.href='login.php?redirect=parking-details.php?id=<?php echo (int)$space['id']; ?>'">
                                    <i class="fas fa-sign-in-alt"></i> Sign in to Book
                                </button>
                            <?php endif; ?>
                        <?php else: ?>
                            <button class="btn-book" disabled>
                                <i class="fas fa-times-circle"></i> Fully Booked
                            </button>
                        <?php endif; ?>
                    <?php endif; ?> 
            </div>
        </div>
        
        <?php if (!empty($amenities)): ?>
        <div class="amenities-section">
            <h2><i class="fas fa-concierge-bell"></i> Amenities</h2>
            <div class="amenities-grid">
                <?php foreach ($amenities as $amenity): ?>
                    <div class="amenity-item"><?php echo sanitize($amenity); ?></div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="reviews-section">
            <h2><i class="fas fa-star"></i> Reviews</h2>
            <?php if (empty($reviews)): ?>
                <div class="no-reviews">
                    <i class="fas fa-comment-dots fa-3x"></i>
                    <p style="margin-top: 10px;">
                        <?php if ($is_owner): ?>
                            No reviews yet. Reviews will appear here when customers leave feedback.
                        <?php else: ?>
                            No reviews yet. Be the first to leave a review!
                        <?php endif; ?>
                    </p>
                </div>
            <?php else: ?>
                <?php foreach ($reviews as $review): ?>
                <div class="review-card">
                    <div class="review-header">
                        <span class="reviewer-name"><i class="fas fa-user-circle"></i> <?php echo sanitize(($review['first_name'] ?? '') . ' ' . ($review['last_name'] ?? '')); ?></span>
                        <span class="review-date"><i class="far fa-calendar-alt"></i> <?php echo date('M d, Y', strtotime($review['created_at'] ?? 'now')); ?></span>
                    </div>
                    <div class="review-rating">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <?php echo $i <= ($review['rating'] ?? 0) ? '<i class="fas fa-star"></i>' : '<i class="far fa-star"></i>'; ?>
                        <?php endfor; ?>
                    </div>
                    <p class="review-comment"><i class="fas fa-quote-left"></i> <?php echo nl2br(sanitize($review['comment'] ?? '')); ?></p>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Image gallery functionality
        function changeMainImage(src, element) {
            const mainImage = document.getElementById('mainImage');
            if (mainImage) {
                mainImage.src = src;
            }
            document.querySelectorAll('.gallery-thumb').forEach(thumb => {
                thumb.classList.remove('active');
            });
            element.classList.add('active');
        }

        // Check for availability updates every 30 seconds
        let availabilityInterval;
        
        function checkAvailability() {
            fetch('api/get-availability.php?id=<?php echo (int)$space['id']; ?>')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const availableElement = document.getElementById('available-spots-<?php echo (int)$space['id']; ?>');
                        if (availableElement) {
                            availableElement.textContent = data.available_spots;
                        }
                        
                        // Update book button if needed (only if not owner)
                        <?php if (!$is_owner): ?>
                        const bookBtn = document.querySelector('.btn-book');
                        if (bookBtn && data.available_spots !== undefined) {
                            if (data.available_spots <= 0) {
                                bookBtn.disabled = true;
                                bookBtn.innerHTML = '<i class="fas fa-times-circle"></i> Fully Booked';
                                bookBtn.style.background = 'rgba(156,163,175,0.6)';
                            } else {
                                bookBtn.disabled = false;
                                const isLoggedIn = <?php echo isset($_SESSION['user_id']) && !empty($_SESSION['user_id']) ? 'true' : 'false'; ?>;
                                if (isLoggedIn) {
                                    bookBtn.innerHTML = '<i class="fas fa-calendar-check"></i> Book Now (' + data.available_spots + ' spots left)';
                                } else {
                                    bookBtn.innerHTML = '<i class="fas fa-sign-in-alt"></i> Sign in to Book';
                                }
                                bookBtn.style.background = 'linear-gradient(135deg, #4F6EF7, #7C3AED)';
                            }
                        }
                        <?php endif; ?>
                    }
                })
                .catch(error => {
                    console.error('Availability check failed:', error);
                });
        }
        
        // Start checking every 30 seconds
        availabilityInterval = setInterval(checkAvailability, 30000);
        
        // Clean up interval when page unloads
        window.addEventListener('beforeunload', function() {
            if (availabilityInterval) {
                clearInterval(availabilityInterval);
            }
        });
    </script>
<?php include __DIR__ . '/chat/widget.php'; ?>  <!-- ADD THIS LINE -->
</body>
</html>