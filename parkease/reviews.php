<?php
session_start();
require_once 'includes/user-access.php';
redirectOwnersFromPublicPages();
require_once 'config/database.php';

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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <title>Community Reviews - SpaceNode</title>
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
        
        /* Header Section */
        .header {
            margin-bottom: 40px;
            text-align: center;
        }
        
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: rgba(255,255,255,0.9);
            text-decoration: none;
            font-weight: 500;
            font-size: 14px;
            margin-bottom: 20px;
            transition: all 0.3s ease;
            padding: 10px 20px;
            background: rgba(255,255,255,0.08);
            backdrop-filter: blur(10px);
            border-radius: 50px;
            border: 1px solid rgba(255,255,255,0.15);
        }
        
        .back-link:hover {
            gap: 12px;
            background: rgba(255,255,255,0.15);
            border-color: rgba(255,255,255,0.3);
            transform: translateY(-2px);
        }
        
        .page-title {
            font-family: 'Outfit', sans-serif;
            font-size: clamp(36px, 6vw, 56px);
            font-weight: 800;
            margin-bottom: 12px;
            background: linear-gradient(135deg, #a5b4fc, #c4b5fd, #f0abfc);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            letter-spacing: -1px;
            text-shadow: 0 0 30px rgba(165,180,252,0.3);
        }
        
        .page-subtitle {
            color: rgba(255,255,255,0.7);
            font-size: 16px;
        }
        
        /* Glassmorphism Stats Bar */
        .stats-bar {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }
        
        .stat-card {
            background: rgba(255,255,255,0.08);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 24px;
            padding: 24px;
            text-align: center;
            transition: all 0.4s cubic-bezier(0.4,0,0.2,1);
            box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.3);
            position: relative;
            overflow: hidden;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(90deg, #4F6EF7, #C4B5FD, #F0ABFC);
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            background: rgba(255,255,255,0.12);
            border-color: rgba(255,255,255,0.35);
            box-shadow: 0 16px 48px 0 rgba(0, 0, 0, 0.4);
        }
        
        .stat-number {
            font-family: 'Outfit', sans-serif;
            font-size: 38px;
            font-weight: 800;
            background: linear-gradient(135deg, #a5b4fc, #c4b5fd);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 8px;
        }
        
        .stat-label {
            color: rgba(255,255,255,0.8);
            font-size: 14px;
            font-weight: 500;
        }
        
        /* Glassmorphism Main Card */
        .card {
            background: rgba(255,255,255,0.08);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 28px;
            padding: 32px;
            margin-bottom: 28px;
            transition: all 0.4s ease;
            box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.3);
        }
        
        .card:hover {
            background: rgba(255,255,255,0.1);
            border-color: rgba(255,255,255,0.3);
            box-shadow: 0 16px 48px 0 rgba(0, 0, 0, 0.4);
        }
        
        /* Glassmorphism Messages */
        .msg {
            padding: 16px 24px;
            border-radius: 20px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 500;
            animation: slideDown 0.4s cubic-bezier(0.4,0,0.2,1);
            backdrop-filter: blur(20px);
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-15px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .msg.success {
            background: rgba(16,185,129,0.15);
            border: 1px solid rgba(16,185,129,0.3);
            color: #6ee7b7;
        }
        
        .msg.error {
            background: rgba(239,68,68,0.12);
            border: 1px solid rgba(239,68,68,0.3);
            color: #fca5a5;
        }
        
        /* Filters Section */
        .filters-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 16px;
        }
        
        .filters-header h2 {
            font-family: 'Outfit', sans-serif;
            font-size: 22px;
            font-weight: 700;
            color: white;
            letter-spacing: -0.5px;
        }
        
        .filter-clear {
            color: rgba(165,180,252,0.9);
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
            font-weight: 500;
            padding: 8px 16px;
            background: rgba(255,255,255,0.08);
            backdrop-filter: blur(10px);
            border-radius: 50px;
            transition: all 0.3s ease;
            border: 1px solid rgba(255,255,255,0.15);
        }
        
        .filter-clear:hover {
            background: rgba(255,255,255,0.15);
            color: white;
            transform: translateY(-2px);
        }
        
        .filters {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 28px;
        }
        
        .filters input[type="text"],
        .filters select {
            padding: 14px 18px;
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 60px;
            font-size: 14px;
            font-family: 'DM Sans', sans-serif;
            transition: all 0.3s ease;
            background: rgba(255,255,255,0.05);
            backdrop-filter: blur(10px);
            color: white;
        }
        
        .filters input[type="text"]::placeholder {
            color: rgba(255,255,255,0.5);
        }
        
        .filters input[type="text"]:focus,
        .filters select:focus {
            outline: none;
            border-color: rgba(165,180,252,0.6);
            background: rgba(255,255,255,0.1);
            box-shadow: 0 0 0 3px rgba(79,110,247,0.2);
        }
        
        .filters select option {
            background: #1a1a2e;
            color: white;
        }
        
        .filter-btn-group {
            display: flex;
            gap: 12px;
        }
        
        .filters button {
            background: linear-gradient(135deg, #4F6EF7, #7C3AED);
            color: white;
            border: none;
            padding: 14px 28px;
            border-radius: 60px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            font-family: 'Outfit', sans-serif;
            transition: all 0.3s ease;
            flex: 1;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(79,110,247,0.3);
        }
        
        .filters button::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s ease;
        }
        
        .filters button:hover::before {
            left: 100%;
        }
        
        .filters button:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 28px rgba(79,110,247,0.4);
        }
        
        /* Glassmorphism Reviews List */
        .reviews-list {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        
        .review-card {
            background: rgba(255,255,255,0.06);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 24px;
            padding: 24px;
            transition: all 0.4s cubic-bezier(0.4,0,0.2,1);
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.2);
            animation: fadeInUp 0.5s ease forwards;
            opacity: 0;
        }
        
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
        
        .review-card:nth-child(1) { animation-delay: 0.05s; }
        .review-card:nth-child(2) { animation-delay: 0.1s; }
        .review-card:nth-child(3) { animation-delay: 0.15s; }
        .review-card:nth-child(4) { animation-delay: 0.2s; }
        .review-card:nth-child(5) { animation-delay: 0.25s; }
        .review-card:nth-child(6) { animation-delay: 0.3s; }
        .review-card:nth-child(7) { animation-delay: 0.35s; }
        .review-card:nth-child(8) { animation-delay: 0.4s; }
        .review-card:nth-child(9) { animation-delay: 0.45s; }
        .review-card:nth-child(10) { animation-delay: 0.5s; }
        
        .review-card:hover {
            border-color: rgba(165,180,252,0.4);
            box-shadow: 0 12px 32px rgba(79,110,247,0.2);
            transform: translateY(-3px);
            background: rgba(255,255,255,0.1);
        }
        
        .review-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }
        
        .review-left {
            flex: 1;
        }
        
        .parking-name {
            font-family: 'Outfit', sans-serif;
            font-weight: 700;
            color: white;
            font-size: 18px;
            margin-bottom: 6px;
            letter-spacing: -0.3px;
        }
        
        .review-meta {
            color: rgba(255,255,255,0.6);
            font-size: 13px;
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .review-meta-dot {
            width: 4px;
            height: 4px;
            background: rgba(255,255,255,0.4);
            border-radius: 50%;
        }
        
        .review-rating {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .stars {
            color: #FBBF24;
            font-size: 18px;
            letter-spacing: 3px;
            text-shadow: 0 0 8px rgba(251,191,36,0.3);
        }
        
        .rating-number {
            background: rgba(245,158,11,0.15);
            color: #FBBF24;
            padding: 4px 12px;
            border-radius: 50px;
            font-weight: 600;
            font-size: 13px;
            border: 1px solid rgba(245,158,11,0.3);
        }
        
        .review-comment {
            color: rgba(255,255,255,0.8);
            line-height: 1.7;
            margin-bottom: 16px;
            font-size: 14px;
        }
        
        .review-footer {
            display: flex;
            gap: 12px;
            padding-top: 12px;
            border-top: 1px solid rgba(255,255,255,0.1);
            font-size: 12px;
            color: rgba(255,255,255,0.4);
        }
        
        /* Empty State */
        .no-reviews {
            text-align: center;
            padding: 60px 20px;
        }
        
        .empty-icon {
            font-size: 64px;
            margin-bottom: 16px;
            opacity: 0.5;
        }
        
        .no-reviews-text {
            color: rgba(255,255,255,0.6);
            font-size: 16px;
        }
        
        /* Glassmorphism Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 32px;
            flex-wrap: wrap;
        }
        
        .page {
            padding: 10px 18px;
            background: rgba(255,255,255,0.08);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 50px;
            cursor: pointer;
            text-decoration: none;
            color: rgba(255,255,255,0.8);
            font-weight: 600;
            font-size: 14px;
            transition: all 0.3s ease;
            font-family: 'Outfit', sans-serif;
        }
        
        .page:hover {
            border-color: rgba(165,180,252,0.5);
            background: rgba(255,255,255,0.15);
            transform: translateY(-2px);
            color: white;
        }
        
        .page.active {
            background: linear-gradient(135deg, #4F6EF7, #7C3AED);
            color: white;
            border-color: transparent;
            box-shadow: 0 4px 15px rgba(79,110,247,0.3);
        }
        
        .pagination-dots {
            padding: 10px 8px;
            color: rgba(255,255,255,0.5);
        }
        
        /* Glassmorphism Review Form Section */
        .form-header {
            margin-bottom: 28px;
        }
        
        .form-header h2 {
            font-family: 'Outfit', sans-serif;
            font-size: 24px;
            font-weight: 700;
            color: white;
            margin-bottom: 6px;
            letter-spacing: -0.5px;
        }
        
        .form-header p {
            color: rgba(255,255,255,0.6);
            font-size: 14px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .form-row textarea {
            grid-column: 1 / -1;
        }
        
        .form-row select,
        .form-row input,
        .form-row textarea {
            padding: 14px 18px;
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 60px;
            font-family: 'DM Sans', sans-serif;
            font-size: 14px;
            transition: all 0.3s ease;
            background: rgba(255,255,255,0.05);
            backdrop-filter: blur(10px);
            color: white;
        }
        
        .form-row textarea {
            border-radius: 24px;
            resize: vertical;
            min-height: 120px;
        }
        
        .form-row select option {
            background: #1a1a2e;
            color: white;
        }
        
        .form-row select:focus,
        .form-row input:focus,
        .form-row textarea:focus {
            outline: none;
            border-color: rgba(165,180,252,0.6);
            background: rgba(255,255,255,0.1);
            box-shadow: 0 0 0 3px rgba(79,110,247,0.2);
        }
        
        .form-row input::placeholder,
        .form-row textarea::placeholder {
            color: rgba(255,255,255,0.5);
        }
        
        .form-actions {
            display: flex;
            gap: 16px;
        }
        
        .btn-submit {
            background: linear-gradient(135deg, #10B981, #059669);
            color: white;
            border: none;
            padding: 14px 32px;
            border-radius: 60px;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            font-family: 'Outfit', sans-serif;
            transition: all 0.3s ease;
            flex: 1;
            max-width: 220px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(16,185,129,0.3);
        }
        
        .btn-submit::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s ease;
        }
        
        .btn-submit:hover::before {
            left: 100%;
        }
        
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 28px rgba(16,185,129,0.4);
        }
        
        .btn-submit:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }
        
        .sign-in-prompt {
            color: rgba(255,255,255,0.6);
            font-size: 14px;
            text-align: center;
            padding: 30px;
            background: rgba(255,255,255,0.05);
            backdrop-filter: blur(12px);
            border-radius: 24px;
            border: 1px solid rgba(255,255,255,0.15);
        }
        
        .sign-in-prompt a {
            color: #a5b4fc;
            text-decoration: none;
            font-weight: 600;
        }
        
        .sign-in-prompt a:hover {
            color: #c4b5fd;
            text-decoration: underline;
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            body {
                padding: 24px 16px;
            }
            
            .page-title {
                font-size: 32px;
            }
            
            .card {
                padding: 24px;
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
                gap: 16px;
            }
            
            .stat-number {
                font-size: 28px;
            }
            
            .btn-submit {
                max-width: 100%;
            }
        }
        
        @media (max-width: 480px) {
            .page-title {
                font-size: 28px;
            }
            
            .card {
                padding: 20px;
            }
            
            .stats-bar {
                grid-template-columns: 1fr;
            }
            
            .stat-card {
                padding: 20px;
            }
            
            .review-card {
                padding: 18px;
            }
            
            .parking-name {
                font-size: 16px;
            }
        }
        
        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
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
        <div class="header">
            <a href="index.php" class="back-link">
                <span>←</span> Back to Dashboard
            </a>
            <h1 class="page-title">Community Reviews</h1>
            <p class="page-subtitle">Discover authentic feedback from SpaceNode users</p>
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
                <div class="stat-number"><?php echo $stats['avg_rating']; ?> ★</div>
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
                    <button type="submit">Search Reviews</button>
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
                                <span>Review ID: <?php echo $review['id']; ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($total > $perPage): ?>
                    <?php $totalPages = (int)ceil($total / $perPage); ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a class="page" href="?<?php echo build_query(['page' => 1]); ?>">First</a>
                            <a class="page" href="?<?php echo build_query(['page' => $page - 1]); ?>">← Prev</a>
                        <?php endif; ?>

                        <?php
                            $start = max(1, $page - 2);
                            $end = min($totalPages, $page + 2);
                            if ($start > 1) echo '<span class="pagination-dots">...</span>';
                            for ($p = $start; $p <= $end; $p++):
                        ?>
                            <a class="page <?php echo $p === $page ? 'active' : ''; ?>" href="?<?php echo build_query(['page' => $p]); ?>"><?php echo $p; ?></a>
                        <?php endfor; ?>
                        <?php if ($end < $totalPages) echo '<span class="pagination-dots">...</span>'; ?>

                        <?php if ($page < $totalPages): ?>
                            <a class="page" href="?<?php echo build_query(['page' => $page + 1]); ?>">Next →</a>
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
                    Please <a href="login.php">sign in</a> to leave a review. Don't have an account? <a href="register.php">Create one here</a>.
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
        </div>
    </div>
</body>
</html>