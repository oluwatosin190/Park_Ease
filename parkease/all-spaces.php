<?php
session_start();
require_once 'includes/user-access.php';
redirectOwnersFromPublicPages();
require_once 'config/database.php';

// Function to sanitize output
function sanitize($input) {
    return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
}

$database = new Database();
$db = $database->getConnection();

// Get filter parameters with validation
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$type = isset($_GET['type']) ? sanitize($_GET['type']) : '';
$min_price = isset($_GET['min_price']) ? (int)$_GET['min_price'] : 0;
$max_price = isset($_GET['max_price']) ? (int)$_GET['max_price'] : 10000;
$sort = isset($_GET['sort']) ? sanitize($_GET['sort']) : 'newest';
$available_only = isset($_GET['available']) ? true : false;

// Validate sort options
$allowed_sorts = ['newest', 'price_low', 'price_high', 'rating', 'popular'];
if (!in_array($sort, $allowed_sorts)) {
    $sort = 'newest';
}

// Validate price range
if ($min_price < 0) $min_price = 0;
if ($max_price < $min_price) $max_price = $min_price + 1000;
if ($max_price > 10000) $max_price = 10000;

// Build the query with prepared statements
$query = "SELECT ps.*, 
          COALESCE(AVG(r.rating), 0) as avg_rating,
          COUNT(DISTINCT r.id) as review_count,
          u.first_name as owner_name
          FROM parking_spaces ps
          JOIN users u ON ps.owner_id = u.id
          LEFT JOIN reviews r ON ps.id = r.parking_id
          WHERE ps.is_active = 1";

$params = [];

if (!empty($search)) {
    $query .= " AND (ps.name LIKE :search OR ps.city LIKE :search OR ps.address LIKE :search)";
    $params[':search'] = "%$search%";
}

if (!empty($type)) {
    $query .= " AND ps.parking_type = :type";
    $params[':type'] = $type;
}

if ($min_price > 0) {
    $query .= " AND ps.hourly_rate >= :min_price";
    $params[':min_price'] = $min_price;
}

if ($max_price < 10000) {
    $query .= " AND ps.hourly_rate <= :max_price";
    $params[':max_price'] = $max_price;
}

if ($available_only) {
    $query .= " AND ps.available_spots > 0";
}

$query .= " GROUP BY ps.id";

// Apply sorting with safe mapping
switch ($sort) {
    case 'price_low':
        $query .= " ORDER BY ps.hourly_rate ASC";
        break;
    case 'price_high':
        $query .= " ORDER BY ps.hourly_rate DESC";
        break;
    case 'rating':
        $query .= " ORDER BY avg_rating DESC";
        break;
    case 'popular':
        $query .= " ORDER BY review_count DESC";
        break;
    default:
        $query .= " ORDER BY ps.created_at DESC";
}

try {
    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $spaces = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("All spaces query error: " . $e->getMessage());
    $spaces = [];
}

// Get unique cities for filter
$cities_query = "SELECT DISTINCT city FROM parking_spaces WHERE is_active = 1 ORDER BY city";
try {
    $cities_stmt = $db->prepare($cities_query);
    $cities_stmt->execute();
    $cities = $cities_stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    error_log("Cities query error: " . $e->getMessage());
    $cities = [];
}

// Helper function to safely get image URL
function getImageUrl($images_json, $default = 'img/parking-placeholder.jpg') {
    if (!empty($images_json)) {
        $images = json_decode($images_json, true);
        if (!empty($images) && isset($images[0])) {
            $image_path = 'uploads/parking/' . $images[0];
            return file_exists($image_path) ? $image_path : $default;
        }
    }
    return $default;
}

// Helper function to safely get amenities
function getAmenities($amenities_json) {
    if (!empty($amenities_json)) {
        $amenities = json_decode($amenities_json, true);
        return is_array($amenities) ? $amenities : [];
    }
    return [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta name="description" content="Find and book parking spaces near you. Search by location, price, and availability.">
    <meta name="robots" content="index, follow">
    <title>Find Parking Spaces - SpaceNode</title>
    
    <!-- Include all CSS assets (navbar, footer, global styles) -->
    <?php require_once 'includes/header-assets.php'; ?>
    
    <style>
        /* ============================================
           PAGE-SPECIFIC STYLES (Hero, Filters, Cards)
           These are separate from navbar/footer styles
        ============================================ */
        
        /* Glassmorphism Hero Section */
        .hero-section {
            position: relative;
            background: linear-gradient(135deg, #0F172A 0%, #1E1B4B 100%);
            padding: 100px 20px;
            overflow: hidden;
        }

        .hero-section::before {
            content: '';
            position: absolute;
            top: -100px;
            left: -100px;
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, rgba(79,110,247,0.3) 0%, transparent 70%);
            pointer-events: none;
        }

        .hero-section::after {
            content: '';
            position: absolute;
            bottom: -100px;
            right: -100px;
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, rgba(124,58,237,0.25) 0%, transparent 70%);
            pointer-events: none;
        }

        .hero-content {
            max-width: 800px;
            margin: 0 auto;
            text-align: center;
            position: relative;
            z-index: 2;
        }

        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255,255,255,0.12);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255,255,255,0.25);
            border-radius: 50px;
            padding: 8px 20px;
            font-size: 13px;
            color: rgba(255,255,255,0.95);
            font-weight: 500;
            margin-bottom: 25px;
        }

        .hero-badge-dot {
            width: 7px;
            height: 7px;
            background: #4ade80;
            border-radius: 50%;
            box-shadow: 0 0 8px #4ade80;
            animation: pulse 2s infinite;
        }

        @keyframes pulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:0.6;transform:scale(1.3)} }

        .hero-content h1 {
            font-family: var(--font-display);
            font-size: clamp(36px, 6vw, 56px);
            font-weight: 900;
            color: #fff;
            line-height: 1.1;
            margin-bottom: 20px;
            letter-spacing: -1.5px;
            text-shadow: 0 4px 30px rgba(0,0,0,0.25);
        }

        .hero-content h1 span {
            background: linear-gradient(90deg, #93c5fd, #c4b5fd);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .hero-content p {
            font-size: 17px;
            color: rgba(255,255,255,0.85);
            max-width: 550px;
            margin: 0 auto 35px;
            line-height: 1.65;
        }

        /* Glassmorphism Search Container */
        .search-container {
            background: rgba(255,255,255,0.12);
            backdrop-filter: blur(20px) saturate(180%);
            -webkit-backdrop-filter: blur(20px);
            border-radius: 80px;
            padding: 5px;
            display: flex;
            gap: 10px;
            max-width: 600px;
            margin: 0 auto;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2), inset 0 1px 0 rgba(255,255,255,0.25);
            border: 1px solid rgba(255,255,255,0.3);
        }

        .search-input {
            flex: 1;
            padding: 18px 25px;
            border: none;
            border-radius: 80px;
            font-size: 15px;
            outline: none;
            background: rgba(255,255,255,0.9);
            font-family: var(--font-body);
        }

        .search-input::placeholder {
            color: var(--muted);
        }

        .search-btn {
            background: linear-gradient(135deg, var(--blue), var(--purple));
            color: white;
            border: none;
            padding: 15px 40px;
            border-radius: 80px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            white-space: nowrap;
            font-family: var(--font-display);
            box-shadow: 0 4px 20px rgba(79,110,247,0.3);
        }

        .search-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(79,110,247,0.4);
        }

        /* Main Container */
        .main-container {
            max-width: 1400px;
            margin: 40px auto 60px;
            padding: 0 24px;
        }

        /* Glassmorphism Filter Sidebar */
        .filter-sidebar {
            background: rgba(255,255,255,0.75);
            backdrop-filter: blur(20px) saturate(180%);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.6);
            border-radius: 28px;
            padding: 30px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.08), inset 0 1px 0 rgba(255,255,255,0.8);
            position: sticky;
            top: 100px;
            height: fit-content;
        }

        .filter-title {
            font-family: var(--font-display);
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--text);
        }

        .filter-title i {
            color: var(--blue);
        }

        .filter-group {
            margin-bottom: 25px;
        }

        .filter-label {
            font-weight: 600;
            margin-bottom: 12px;
            color: var(--text);
            font-size: 14px;
        }

        .filter-option {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
            cursor: pointer;
            color: #4B5563;
            font-size: 14px;
            padding: 8px 12px;
            border-radius: 12px;
            transition: all 0.2s;
        }

        .filter-option:hover {
            background: rgba(79,110,247,0.08);
        }

        .filter-option input[type="checkbox"],
        .filter-option input[type="radio"] {
            width: 18px;
            height: 18px;
            accent-color: var(--blue);
        }

        .price-slider {
            width: 100%;
            height: 6px;
            border-radius: 3px;
            background: rgba(0,0,0,0.1);
            outline: none;
            -webkit-appearance: none;
        }

        .price-slider::-webkit-slider-thumb {
            -webkit-appearance: none;
            width: 20px;
            height: 20px;
            background: var(--blue);
            border-radius: 50%;
            cursor: pointer;
            transition: transform 0.3s;
            box-shadow: 0 2px 10px rgba(79,110,247,0.5);
        }

        .price-slider::-webkit-slider-thumb:hover {
            transform: scale(1.2);
        }

        .price-values {
            display: flex;
            justify-content: space-between;
            margin-top: 10px;
            font-size: 13px;
            color: var(--muted);
        }

        .btn-reset {
            width: 100%;
            padding: 12px;
            background: rgba(255,255,255,0.9);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(229,231,235,0.6);
            border-radius: 50px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            color: var(--text);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-family: var(--font-body);
        }

        .btn-reset:hover {
            background: rgba(79,110,247,0.1);
            border-color: var(--blue);
            color: var(--blue);
            transform: translateY(-2px);
        }

        /* Content Area Layout */
        .content-area {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 30px;
        }

        /* Glassmorphism Results Header */
        .results-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            background: rgba(255,255,255,0.75);
            backdrop-filter: blur(20px) saturate(180%);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.6);
            border-radius: 80px;
            padding: 16px 30px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.05), inset 0 1px 0 rgba(255,255,255,0.8);
        }

        .results-count {
            font-size: 14px;
            color: var(--muted);
        }

        .results-count strong {
            color: var(--blue);
            font-size: 20px;
            font-weight: 800;
        }

        .sort-select {
            padding: 10px 24px;
            border: 1px solid rgba(229,231,235,0.7);
            border-radius: 50px;
            font-size: 13px;
            outline: none;
            cursor: pointer;
            background: rgba(255,255,255,0.9);
            color: var(--text);
            font-weight: 500;
            transition: all 0.3s;
            font-family: var(--font-body);
        }

        .sort-select:hover {
            border-color: var(--blue);
            box-shadow: 0 0 0 3px rgba(79,110,247,0.1);
        }

        /* Glassmorphism Spaces Grid */
        .spaces-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 28px;
        }

        .space-card {
            background: rgba(255,255,255,0.75);
            backdrop-filter: blur(24px) saturate(200%);
            -webkit-backdrop-filter: blur(24px);
            border: 1px solid rgba(255,255,255,0.7);
            border-radius: 28px;
            overflow: hidden;
            transition: all 0.4s cubic-bezier(0.4,0,0.2,1);
            box-shadow: 0 8px 32px rgba(0,0,0,0.08), inset 0 1px 0 rgba(255,255,255,0.8);
            opacity: 0;
            transform: translateY(28px);
            position: relative;
        }

        .space-card::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 1px;
            background: linear-gradient(90deg, transparent 0%, rgba(255,255,255,0.9) 30%, rgba(255,255,255,1) 50%, rgba(255,255,255,0.9) 70%, transparent 100%);
            border-radius: 28px 28px 0 0;
            pointer-events: none;
        }

        .space-card.animate-in {
            opacity: 1;
            transform: translateY(0);
        }

        .space-card:hover {
            transform: translateY(-10px);
            background: rgba(255,255,255,0.88);
            backdrop-filter: blur(32px) saturate(220%);
            box-shadow: 0 28px 56px rgba(79,110,247,0.2), 0 8px 20px rgba(0,0,0,0.06);
            border-color: rgba(255,255,255,0.9);
        }

        .space-image {
            position: relative;
            height: 220px;
            overflow: hidden;
            background: linear-gradient(135deg, #1a2035 0%, #263352 100%);
        }

        .space-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.6s cubic-bezier(0.4,0,0.2,1);
        }

        .space-card:hover .space-image img {
            transform: scale(1.08);
        }

        .space-badge {
            position: absolute;
            top: 16px;
            right: 16px;
            padding: 6px 16px;
            border-radius: 50px;
            font-weight: 600;
            font-size: 12px;
            backdrop-filter: blur(8px);
            z-index: 2;
        }

        .badge-available {
            background: rgba(220,252,231,0.95);
            color: #16A34A;
            border: 1px solid rgba(22,163,74,0.2);
        }

        .badge-full {
            background: rgba(254,226,226,0.95);
            color: #DC2626;
            border: 1px solid rgba(220,38,38,0.2);
        }

        .space-type {
            position: absolute;
            bottom: 16px;
            left: 16px;
            background: rgba(0,0,0,0.55);
            backdrop-filter: blur(8px);
            color: white;
            padding: 6px 16px;
            border-radius: 50px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border: 1px solid rgba(255,255,255,0.2);
            z-index: 2;
        }

        .space-rating {
            position: absolute;
            bottom: 16px;
            right: 16px;
            background: rgba(0,0,0,0.55);
            backdrop-filter: blur(8px);
            color: #fff;
            border-radius: 50px;
            padding: 6px 12px;
            font-size: 13px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 4px;
            border: 1px solid rgba(255,255,255,0.2);
            z-index: 2;
        }

        .space-rating i {
            color: #FBBF24;
        }

        .space-content {
            padding: 20px;
        }

        .space-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
        }

        .space-name {
            font-family: var(--font-display);
            font-size: 18px;
            font-weight: 700;
            color: var(--text);
            line-height: 1.3;
            flex: 1;
            margin-right: 15px;
            letter-spacing: -0.2px;
        }

        .space-location {
            display: flex;
            align-items: center;
            gap: 6px;
            color: var(--muted);
            font-size: 13px;
            margin-bottom: 16px;
        }

        .space-location i {
            color: var(--blue);
            font-size: 12px;
        }

        /* Amenities Tags - Glassmorphism */
        .space-amenities {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 16px;
        }

        .amenity-tag {
            background: rgba(248,250,252,0.9);
            backdrop-filter: blur(4px);
            border: 1px solid rgba(229,231,235,0.6);
            color: #475569;
            padding: 5px 12px;
            border-radius: 50px;
            font-size: 11px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 5px;
            transition: all 0.2s;
        }

        .amenity-tag:hover {
            background: rgba(79,110,247,0.08);
            color: var(--blue);
            border-color: rgba(79,110,247,0.2);
        }

        .amenity-tag i {
            font-size: 10px;
            color: var(--green);
        }

        /* Stats Section */
        .space-stats {
            display: flex;
            justify-content: space-between;
            padding: 14px 0;
            border-top: 1px solid rgba(229,231,235,0.5);
            border-bottom: 1px solid rgba(229,231,235,0.5);
            margin-bottom: 16px;
        }

        .stat-item {
            text-align: center;
            flex: 1;
        }

        .stat-value {
            font-size: 18px;
            font-weight: 800;
            color: var(--blue);
            margin-bottom: 4px;
            font-family: var(--font-display);
        }

        .stat-label {
            font-size: 10px;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }

        /* Price Cards */
        .space-prices {
            display: flex;
            gap: 12px;
            margin-bottom: 20px;
        }

        .price-item {
            flex: 1;
            text-align: center;
            background: rgba(255,255,255,0.6);
            backdrop-filter: blur(8px);
            padding: 10px;
            border-radius: 16px;
            transition: all 0.2s;
            border: 1px solid rgba(255,255,255,0.4);
        }

        .price-item:hover {
            background: rgba(79,110,247,0.08);
            border-color: rgba(79,110,247,0.3);
        }

        .price-label {
            font-size: 10px;
            color: var(--muted);
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }

        .price-amount {
            font-size: 16px;
            font-weight: 800;
            color: var(--blue);
            font-family: var(--font-display);
        }

        .price-amount::before {
            content: '₦';
            font-size: 12px;
            margin-right: 2px;
            font-weight: 600;
        }

        /* Book Button - Glassmorphism */
        .btn-book {
            display: block;
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, var(--blue), var(--purple));
            color: white;
            text-align: center;
            text-decoration: none;
            border-radius: 50px;
            font-weight: 600;
            transition: all 0.3s;
            border: none;
            cursor: pointer;
            font-size: 14px;
            font-family: var(--font-body);
            box-shadow: 0 4px 18px rgba(79,110,247,0.32);
            position: relative;
            overflow: hidden;
        }

        .btn-book::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(255,255,255,0) 0%, rgba(255,255,255,0.15) 100%);
            opacity: 0;
            transition: opacity 0.3s;
        }

        .btn-book:hover::before {
            opacity: 1;
        }

        .btn-book:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 28px rgba(79,110,247,0.42);
        }

        .btn-book.disabled {
            background: #9CA3AF;
            cursor: not-allowed;
            pointer-events: none;
            opacity: 0.7;
            box-shadow: none;
        }

        /* Empty State - Glassmorphism */
        .empty-state {
            text-align: center;
            padding: 80px 40px;
            background: rgba(255,255,255,0.75);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.6);
            border-radius: 28px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.08);
        }

        .empty-state i {
            font-size: 64px;
            color: #9CA3AF;
            margin-bottom: 20px;
        }

        .empty-state h3 {
            font-family: var(--font-display);
            font-size: 24px;
            color: var(--text);
            margin-bottom: 10px;
        }

        .empty-state p {
            color: var(--muted);
            margin-bottom: 25px;
        }

        .empty-state .btn-primary {
            display: inline-block;
            padding: 14px 36px;
            background: linear-gradient(135deg, var(--blue), var(--purple));
            color: white;
            text-decoration: none;
            border-radius: 50px;
            font-weight: 600;
            transition: all 0.3s;
            box-shadow: 0 4px 18px rgba(79,110,247,0.3);
        }

        .empty-state .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 28px rgba(79,110,247,0.4);
        }

        /* Pagination - Glassmorphism */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 50px;
        }

        .page-link {
            width: 45px;
            height: 45px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(255,255,255,0.7);
            backdrop-filter: blur(8px);
            border: 1px solid rgba(255,255,255,0.5);
            border-radius: 14px;
            color: var(--text);
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }

        .page-link:hover,
        .page-link.active {
            background: linear-gradient(135deg, var(--blue), var(--purple));
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(79,110,247,0.3);
            border-color: transparent;
        }

        /* Loading Spinner */
        .loading-spinner {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 1000;
            background: rgba(255,255,255,0.9);
            backdrop-filter: blur(12px);
            padding: 20px;
            border-radius: 50%;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }

        .spinner {
            width: 50px;
            height: 50px;
            border: 3px solid rgba(79,110,247,0.2);
            border-top: 3px solid var(--blue);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Card Animation Delays */
        .space-card:nth-child(1) { animation-delay: 0.05s; }
        .space-card:nth-child(2) { animation-delay: 0.1s; }
        .space-card:nth-child(3) { animation-delay: 0.15s; }
        .space-card:nth-child(4) { animation-delay: 0.2s; }
        .space-card:nth-child(5) { animation-delay: 0.25s; }
        .space-card:nth-child(6) { animation-delay: 0.3s; }

        /* Responsive Design */
        @media (max-width: 1024px) {
            .content-area {
                grid-template-columns: 1fr;
            }
            
            .filter-sidebar {
                position: static;
                margin-bottom: 30px;
            }
            
            .hero-content h1 {
                font-size: 42px;
            }
        }

        @media (max-width: 768px) {
            .hero-section {
                padding: 60px 20px;
            }
            
            .hero-content h1 {
                font-size: 32px;
            }
            
            .hero-content p {
                font-size: 15px;
            }
            
            .search-container {
                flex-direction: column;
                border-radius: 30px;
            }
            
            .search-input {
                text-align: center;
                border-radius: 30px;
            }
            
            .search-btn {
                width: 100%;
                border-radius: 30px;
            }
            
            .results-header {
                flex-direction: column;
                gap: 15px;
                border-radius: 24px;
                text-align: center;
            }
            
            .spaces-grid {
                grid-template-columns: 1fr;
            }
            
            .main-container {
                padding: 0 16px;
            }
        }

        @media (max-width: 480px) {
            .hero-content h1 {
                font-size: 28px;
            }
            
            .space-name {
                font-size: 16px;
            }
        }
    </style>
</head>
<body>
    <!-- Include Navbar Component -->
    <?php require_once 'includes/navbar.php'; ?>

    <!-- Glassmorphism Hero Section -->
    <section class="hero-section">
        <div class="hero-content">
            <div class="hero-badge">
                <span class="hero-badge-dot"></span>
                <?php echo count($spaces); ?>+ Parking Spaces Available
            </div>
            <h1>Find Your <span>Perfect Parking</span> Space</h1>
            <p>Discover secure, affordable parking spots near you. Book instantly and save time with our smart parking platform.</p>
            
            <form action="" method="GET" class="search-container">
                <input type="text" name="search" class="search-input" placeholder="Search by location, city, or address..." value="<?php echo sanitize($search); ?>">
                <button type="submit" class="search-btn">
                    <i class="fas fa-search"></i> Search Parking
                </button>
            </form>
        </div>
    </section>

    <!-- Main Content -->
    <div class="main-container">
        <div class="content-area">
            <!-- Glassmorphism Filter Sidebar -->
            <aside class="filter-sidebar">
                <div class="filter-title">
                    <i class="fas fa-sliders-h"></i>
                    Filter Spaces
                </div>

                <form action="" method="GET" id="filterForm">
                    <?php if (!empty($search)): ?>
                        <input type="hidden" name="search" value="<?php echo sanitize($search); ?>">
                    <?php endif; ?>

                    <!-- Parking Type -->
                    <div class="filter-group">
                        <div class="filter-label">Parking Type</div>
                        <label class="filter-option">
                            <input type="radio" name="type" value="" <?php echo empty($type) ? 'checked' : ''; ?> onchange="this.form.submit()">
                            <span>All Types</span>
                        </label>
                        <label class="filter-option">
                            <input type="radio" name="type" value="covered_garage" <?php echo $type == 'covered_garage' ? 'checked' : ''; ?> onchange="this.form.submit()">
                            <span>Covered Garage</span>
                        </label>
                        <label class="filter-option">
                            <input type="radio" name="type" value="open_lot" <?php echo $type == 'open_lot' ? 'checked' : ''; ?> onchange="this.form.submit()">
                            <span>Open Lot</span>
                        </label>
                        <label class="filter-option">
                            <input type="radio" name="type" value="underground" <?php echo $type == 'underground' ? 'checked' : ''; ?> onchange="this.form.submit()">
                            <span>Underground</span>
                        </label>
                        <label class="filter-option">
                            <input type="radio" name="type" value="street_parking" <?php echo $type == 'street_parking' ? 'checked' : ''; ?> onchange="this.form.submit()">
                            <span>Street Parking</span>
                        </label>
                    </div>

                    <!-- Price Range -->
                    <div class="filter-group">
                        <div class="filter-label">Price Range (₦/hour)</div>
                        <input type="range" name="max_price" class="price-slider" min="0" max="10000" step="500" value="<?php echo $max_price; ?>" onchange="updatePrice(this.value)">
                        <div class="price-values">
                            <span>₦0</span>
                            <span id="priceDisplay">₦<?php echo number_format($max_price, 0); ?></span>
                            <span>₦10k+</span>
                        </div>
                    </div>

                    <!-- Availability -->
                    <div class="filter-group">
                        <label class="filter-option">
                            <input type="checkbox" name="available" value="1" <?php echo $available_only ? 'checked' : ''; ?> onchange="this.form.submit()">
                            <span>Show only available spaces</span>
                        </label>
                    </div>

                    <!-- Reset Button -->
                    <button type="button" class="btn-reset" onclick="resetFilters()">
                        <i class="fas fa-undo-alt"></i> Reset Filters
                    </button>
                </form>
            </aside>

            <!-- Results Area -->
            <div>
                <!-- Glassmorphism Results Header -->
                <div class="results-header">
                    <div class="results-count">
                        <strong><?php echo count($spaces); ?></strong> parking spaces found
                    </div>
                    
                    <form action="" method="GET" id="sortForm">
                        <?php foreach ($_GET as $key => $value): ?>
                            <?php if ($key != 'sort' && $key != 'max_price'): ?>
                                <input type="hidden" name="<?php echo sanitize($key); ?>" value="<?php echo sanitize($value); ?>">
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <input type="hidden" name="max_price" value="<?php echo $max_price; ?>">
                        
                        <select name="sort" class="sort-select" onchange="this.form.submit()">
                            <option value="newest" <?php echo $sort == 'newest' ? 'selected' : ''; ?>>Newest First</option>
                            <option value="price_low" <?php echo $sort == 'price_low' ? 'selected' : ''; ?>>Price: Low to High</option>
                            <option value="price_high" <?php echo $sort == 'price_high' ? 'selected' : ''; ?>>Price: High to Low</option>
                            <option value="rating" <?php echo $sort == 'rating' ? 'selected' : ''; ?>>Highest Rated</option>
                            <option value="popular" <?php echo $sort == 'popular' ? 'selected' : ''; ?>>Most Popular</option>
                        </select>
                    </form>
                </div>

                <!-- Spaces Grid -->
                <?php if (empty($spaces)): ?>
                    <div class="empty-state">
                        <i class="fas fa-search"></i>
                        <h3>No parking spaces found</h3>
                        <p>Try adjusting your filters or search criteria to find available parking spots.</p>
                        <a href="all-spaces.php" class="btn-primary">Clear All Filters</a>
                    </div>
                <?php else: ?>
                    <div class="spaces-grid">
                        <?php foreach ($spaces as $index => $space): 
                            $image = getImageUrl($space['images'] ?? '');
                            $amenities = getAmenities($space['amenities'] ?? '');
                            $display_amenities = array_slice($amenities, 0, 3);
                            $type_display = str_replace('_', ' ', $space['parking_type'] ?? '');
                            $type_display = ucwords($type_display);
                            $status_class = ($space['available_spots'] ?? 0) > 0 ? 'badge-available' : 'badge-full';
                            $status_text = ($space['available_spots'] ?? 0) > 0 ? ($space['available_spots'] . ' spots left') : 'Fully Booked';
                        ?>
                        <div class="space-card">
                            <div class="space-image">
                                <img src="<?php echo sanitize($image); ?>" alt="<?php echo sanitize($space['name'] ?? ''); ?>" loading="lazy" onerror="this.src='img/parking-placeholder.jpg'">
                                <span class="space-badge <?php echo $status_class; ?>"><?php echo sanitize($status_text); ?></span>
                                <span class="space-type"><?php echo sanitize($type_display); ?></span>
                                <span class="space-rating">
                                    <i class="fas fa-star"></i>
                                    <?php echo number_format($space['avg_rating'] ?? 0, 1); ?>
                                </span>
                            </div>
                            
                            <div class="space-content">
                                <div class="space-header">
                                    <h3 class="space-name"><?php echo sanitize($space['name'] ?? ''); ?></h3>
                                </div>
                                
                                <div class="space-location">
                                    <i class="fas fa-map-marker-alt"></i>
                                    <?php echo sanitize($space['city'] ?? ''); ?>, <?php echo sanitize($space['address'] ?? ''); ?>
                                </div>
                                
                                <?php if (!empty($display_amenities)): ?>
                                <div class="space-amenities">
                                    <?php foreach ($display_amenities as $amenity): ?>
                                        <span class="amenity-tag">
                                            <i class="fas fa-check-circle"></i>
                                            <?php echo sanitize($amenity); ?>
                                        </span>
                                    <?php endforeach; ?>
                                    <?php if (count($amenities) > 3): ?>
                                        <span class="amenity-tag">+<?php echo count($amenities) - 3; ?> more</span>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                                
                                <div class="space-stats">
                                    <div class="stat-item">
                                        <div class="stat-value"><?php echo (int)($space['total_spots'] ?? 0); ?></div>
                                        <div class="stat-label">Total Spots</div>
                                    </div>
                                    <div class="stat-item">
                                        <div class="stat-value"><?php echo (int)($space['available_spots'] ?? 0); ?></div>
                                        <div class="stat-label">Available</div>
                                    </div>
                                    <div class="stat-item">
                                        <div class="stat-value"><?php echo (int)($space['review_count'] ?? 0); ?></div>
                                        <div class="stat-label">Reviews</div>
                                    </div>
                                </div>
                                
                                <div class="space-prices">
                                    <?php if (!empty($space['hourly_rate'])): ?>
                                    <div class="price-item">
                                        <div class="price-label">Hourly Rate</div>
                                        <div class="price-amount"><?php echo number_format($space['hourly_rate'], 0); ?></div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($space['daily_rate'])): ?>
                                    <div class="price-item">
                                        <div class="price-label">Daily Rate</div>
                                        <div class="price-amount"><?php echo number_format($space['daily_rate'], 0); ?></div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if (($space['available_spots'] ?? 0) > 0): ?>
                                    <a href="parking-details.php?id=<?php echo (int)$space['id']; ?>" class="btn-book">
                                        <i class="fas fa-calendar-check"></i> Book Now
                                    </a>
                                <?php else: ?>
                                    <button class="btn-book disabled" disabled>
                                        <i class="fas fa-times-circle"></i> Fully Booked
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Pagination -->
                    <div class="pagination">
                        <a href="#" class="page-link"><i class="fas fa-chevron-left"></i></a>
                        <a href="#" class="page-link active">1</a>
                        <a href="#" class="page-link">2</a>
                        <a href="#" class="page-link">3</a>
                        <a href="#" class="page-link">4</a>
                        <a href="#" class="page-link">5</a>
                        <a href="#" class="page-link"><i class="fas fa-chevron-right"></i></a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Include Footer Component -->
    <?php require_once 'includes/footer.php'; ?>

    <!-- Loading Spinner -->
    <div class="loading-spinner" id="loadingSpinner">
        <div class="spinner"></div>
    </div>

    <script>
        // Update price display
        function updatePrice(value) {
            document.getElementById('priceDisplay').textContent = '₦' + parseInt(value).toLocaleString();
            document.getElementById('filterForm').submit();
        }

        // Reset filters
        function resetFilters() {
            window.location.href = 'all-spaces.php';
        }

        // Show loading spinner on form submit
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function() {
                document.getElementById('loadingSpinner').style.display = 'block';
            });
        });

        // Scroll animations for cards
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('animate-in');
                }
            });
        }, { threshold: 0.1, rootMargin: '0px 0px -40px 0px' });

        document.querySelectorAll('.space-card').forEach(el => observer.observe(el));
    </script>
</body>
</html>