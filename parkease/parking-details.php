<?php
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

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

$stmt = $db->prepare($query);
$stmt->bindParam(':id', $id);
$stmt->execute();
$space = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$space) {
    header('Location: index.php');
    exit();
}

// Get images
$images = !empty($space['images']) ? json_decode($space['images'], true) : [];

// Get reviews
$reviews_query = "SELECT r.*, u.first_name, u.last_name 
                  FROM reviews r
                  JOIN users u ON r.user_id = u.id
                  WHERE r.parking_id = :id
                  ORDER BY r.created_at DESC";
$reviews_stmt = $db->prepare($reviews_query);
$reviews_stmt->bindParam(':id', $id);
$reviews_stmt->execute();
$reviews = $reviews_stmt->fetchAll(PDO::FETCH_ASSOC);

$amenities = json_decode($space['amenities'], true) ?: [];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($space['name']); ?> - ParkEase</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
        font-family: 'Inter', sans-serif;
        background: #F9FAFB;
        padding: 40px 20px;
    }
    .container {
        max-width: 1200px;
        margin: 0 auto;
    }
    .back-link {
        display: inline-block;
        margin-bottom: 20px;
        color: #4F6EF7;
        text-decoration: none;
        font-size: 14px;
        font-weight: 500;
    }
    .back-link:hover {
        text-decoration: underline;
    }
    
    /* Parking Header Layout */
    .parking-header {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 40px;
        margin-bottom: 40px;
        background: white;
        border-radius: 20px;
        padding: 30px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.05);
    }
    
    /* Main Image Container */
    .image-section {
        width: 100%;
    }
    
    .parking-image {
        width: 100%;
        height: 350px;
        border-radius: 16px;
        overflow: hidden;
        position: relative;
        background: #F3F4F6;
        box-shadow: 0 4px 10px rgba(0,0,0,0.1);
    }
    
    .main-image {
        width: 100%;
        height: 100%;
        object-fit: cover; 
        transition: transform 0.3s ease;
    }
    
    .main-image:hover {
        transform: scale(1.02);
    }
    
    /* Image Gallery */
    .image-gallery {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 10px;
        margin-top: 15px;
    }
    
    .gallery-thumb {
        width: 100%;
        height: 80px;
        border-radius: 8px;
        cursor: pointer;
        object-fit: cover; 
        border: 2px solid transparent;
        transition: all 0.3s ease;
        box-shadow: 0 2px 5px rgba(0,0,0,0.1);
    }
    
    .gallery-thumb:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 10px rgba(0,0,0,0.15);
    }
    
    .gallery-thumb.active {
        border-color: #4F6EF7;
        transform: scale(0.98);
    }
    /* the image placeholder */
    .no-image {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        height: 100%;
        color: #9CA3AF;
        font-size: 16px;
        background: #F3F4F6;
    }
    
    .no-image svg {
        width: 60px;
        height: 60px;
        margin-bottom: 10px;
        opacity: 0.5;
    }
    
    /* Parking Info Section */
    .parking-info h1 {
        font-size: 32px;
        font-weight: 700;
        color: #111827;
        margin-bottom: 10px;
    }
    
    .parking-location {
        display: flex;
        align-items: center;
        gap: 5px;
        color: #6B7280;
        margin-bottom: 20px;
        font-size: 16px;
    }
    
    .parking-location svg {
        flex-shrink: 0;
    }
    
    .rating {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 20px;
        padding: 10px 0;
        border-bottom: 1px solid #E5E7EB;
    }
    
    .stars {
        color: #F59E0B;
        font-size: 18px;
    }
    
    .rating-number {
        font-weight: 600;
        color: #111827;
    }
    
    .review-count {
        color: #6B7280;
        font-size: 14px;
    }
    
    /* Price Box */
    .price-box {
        background: #F3F4F6;
        padding: 20px;
        border-radius: 12px;
        margin-bottom: 20px;
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
        color: #6B7280;
        margin-bottom: 5px;
    }
    
    .price-item .value {
        font-size: 24px;
        font-weight: 700;
        color: #4F6EF7;
    }
    
    .price-item .value::before {
        content: '₦';
        font-size: 16px;
        font-weight: 600;
    }
    
    .description {
        margin: 20px 0;
        line-height: 1.6;
        color: #374151;
    }
    
    .capacity {
        margin: 20px 0;
        padding: 15px;
        background: #EEF2FF;
        border-radius: 10px;
        color: #4F6EF7;
        font-weight: 600;
    }
    
    .btn-book {
        background: linear-gradient(135deg, #4F6EF7, #7C3AED);
        color: white;
        border: none;
        padding: 15px 30px;
        border-radius: 10px;
        font-size: 16px;
        font-weight: 600;
        cursor: pointer;
        width: 100%;
        transition: transform 0.3s, box-shadow 0.3s;
    }
    
    .btn-book:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 20px rgba(79,110,247,0.3);
    }
    
    /* Amenities Section */
    .amenities-section {
        background: white;
        border-radius: 16px;
        padding: 30px;
        margin-bottom: 30px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.05);
    }
    
    .amenities-section h2 {
        font-size: 20px;
        margin-bottom: 20px;
        color: #111827;
    }
    
    .amenities-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
        gap: 15px;
    }
    
    .amenity-item {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 10px;
        background: #F9FAFB;
        border-radius: 8px;
        color: #374151;
        font-size: 14px;
        transition: background 0.3s;
    }
    
    .amenity-item:hover {
        background: #EEF2FF;
    }
    
    .amenity-item::before {
        content: '✓';
        color: #4F6EF7;
        font-weight: 600;
    }
    
    /* Reviews Section */
    .reviews-section {
        background: white;
        border-radius: 16px;
        padding: 30px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.05);
    }
    
    .reviews-section h2 {
        font-size: 20px;
        margin-bottom: 20px;
        color: #111827;
    }
    
    .review-card {
        border: 1px solid #E5E7EB;
        border-radius: 12px;
        padding: 20px;
        margin-bottom: 15px;
        transition: box-shadow 0.3s;
    }
    
    .review-card:hover {
        box-shadow: 0 4px 15px rgba(0,0,0,0.05);
    }
    
    .review-header {
        display: flex;
        justify-content: space-between;
        margin-bottom: 10px;
    }
    
    .reviewer-name {
        font-weight: 600;
        color: #111827;
    }
    
    .review-date {
        color: #6B7280;
        font-size: 12px;
    }
    
    .review-rating {
        color: #F59E0B;
        margin-bottom: 10px;
        font-size: 14px;
    }
    
    .review-comment {
        color: #374151;
        line-height: 1.5;
    }
    
    .no-reviews {
        text-align: center;
        padding: 40px;
        color: #6B7280;
    }
    
    .no-reviews svg {
        width: 60px;
        height: 60px;
        margin-bottom: 10px;
        opacity: 0.5;
    }
    
    /* Responsive Design */
    @media (max-width: 768px) {
        .parking-header {
            grid-template-columns: 1fr;
        }
        
        .parking-image {
            height: 250px;
        }
        
        .image-gallery {
            grid-template-columns: repeat(4, 1fr);
        }
        
        .gallery-thumb {
            height: 60px;
        }
        
        .price-grid {
            grid-template-columns: 1fr;
            gap: 10px;
        }
    }
</style>
</head>
<body>
    <div class="container">
        <a href="index.php" class="back-link">← Back to search</a>
        
        <div class="parking-header">
            <div>
                <div class="parking-image">
                    <?php if (!empty($images)): ?>
                        <img src="uploads/parking/<?php echo htmlspecialchars($images[0]); ?>" 
                             alt="<?php echo htmlspecialchars($space['name']); ?>" 
                             class="main-image"
                             id="mainImage"
                             onerror="this.src='img/parking-placeholder.jpg';">
                    <?php else: ?>
                        <div class="no-image">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                <rect x="2" y="2" width="20" height="20" rx="2.18"/>
                                <path d="M7 2v20M17 2v20M2 12h20M2 7h5M2 17h5M17 17h5M17 7h5"/>
                            </svg>
                            <p>No image available</p>
                        </div>
                    <?php endif; ?>
                </div>
                
                <?php if (count($images) > 1): ?>
                <div class="image-gallery">
                    <?php foreach ($images as $index => $image): ?>
                        <img src="uploads/parking/<?php echo htmlspecialchars($image); ?>" 
                             class="gallery-thumb <?php echo $index == 0 ? 'active' : ''; ?>"
                             onclick="document.getElementById('mainImage').src = this.src;
                                      document.querySelectorAll('.gallery-thumb').forEach(t => t.classList.remove('active'));
                                      this.classList.add('active');"
                             onerror="this.style.display='none';">
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="parking-info">
                <h1><?php echo htmlspecialchars($space['name']); ?></h1>
                <div class="parking-location">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
                        <circle cx="12" cy="10" r="3"/>
                    </svg>
                    <?php echo htmlspecialchars($space['address'] . ', ' . $space['city']); ?>
                </div>
                
                <div class="rating">
                    <span class="stars">★</span>
                    <span class="rating-number"><?php echo number_format($space['avg_rating'], 1); ?></span>
                    <span class="review-count">(<?php echo $space['review_count']; ?> reviews)</span>
                </div>
                
                <div class="price-box">
                    <div class="price-grid">
                        <?php if ($space['hourly_rate']): ?>
                        <div class="price-item">
                            <div class="label">Hourly</div>
                            <div class="value"><?php echo number_format($space['hourly_rate'], 0); ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if ($space['daily_rate']): ?>
                        <div class="price-item">
                            <div class="label">Daily</div>
                            <div class="value"><?php echo number_format($space['daily_rate'], 0); ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if ($space['monthly_rate']): ?>
                        <div class="price-item">
                            <div class="label">Monthly</div>
                            <div class="value"><?php echo number_format($space['monthly_rate'], 0); ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="description">
                    <?php echo nl2br(htmlspecialchars($space['description'] ?: 'No description provided.')); ?>
                </div>
                
                <div class="capacity">
                    📍 Capacity: <?php echo $space['available_spots']; ?>/<?php echo $space['total_spots']; ?> spots available
                </div>
                
                <?php if (isset($_SESSION['user_id'])): ?>
                    <button class="btn-book" onclick="window.location.href='book.php?id=<?php echo $space['id']; ?>'">
                        Book Now
                    </button>
                <?php else: ?>
                    <button class="btn-book" onclick="window.location.href='login.php?redirect=parking-details.php?id=<?php echo $space['id']; ?>'">
                        Sign in to Book
                    </button>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if (!empty($amenities)): ?>
        <div class="amenities-section">
            <h2>Amenities</h2>
            <div class="amenities-grid">
                <?php foreach ($amenities as $amenity): ?>
                    <div class="amenity-item"><?php echo htmlspecialchars($amenity); ?></div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="reviews-section">
            <h2>Reviews</h2>
            <?php if (empty($reviews)): ?>
                <div class="no-reviews">
                    <svg width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                    </svg>
                    <p style="margin-top: 10px;">No reviews yet. Be the first to leave a review!</p>
                </div>
            <?php else: ?>
                <?php foreach ($reviews as $review): ?>
                <div class="review-card">
                    <div class="review-header">
                        <span class="reviewer-name"><?php echo htmlspecialchars($review['first_name'] . ' ' . $review['last_name']); ?></span>
                        <span class="review-date"><?php echo date('M d, Y', strtotime($review['created_at'])); ?></span>
                    </div>
                    <div class="review-rating">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <?php echo $i <= $review['rating'] ? '★' : '☆'; ?>
                        <?php endfor; ?>
                    </div>
                    <p class="review-comment"><?php echo nl2br(htmlspecialchars($review['comment'])); ?></p>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Image gallery functionality
        function changeMainImage(src, element) {
            document.getElementById('mainImage').src = src;
            document.querySelectorAll('.gallery-thumb').forEach(thumb => {
                thumb.classList.remove('active');
            });
            element.classList.add('active');
        }
    </script>
</body>
</html>