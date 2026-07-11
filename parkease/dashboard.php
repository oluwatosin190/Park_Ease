<?php
session_start();
require_once 'config/database.php';

// Function to sanitize output
function sanitize($input) {
    return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
}

// Function to get image URL safely
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

// Check if user is logged in
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$database = new Database();
$db = $database->getConnection();

$user_id = (int)$_SESSION['user_id'];
$user_type = isset($_SESSION['user_type']) ? $_SESSION['user_type'] : '';

// Get user details
$query = "SELECT id, first_name, last_name, email, phone, user_type, created_at, is_active FROM users WHERE id = :id";
$stmt = $db->prepare($query);
$stmt->bindParam(':id', $user_id, PDO::PARAM_INT);
$stmt->execute();
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    session_destroy();
    header('Location: login.php');
    exit();
}

// Check if account is active
if ($user['is_active'] != 1) {
    session_destroy();
    header('Location: login.php?error=account_disabled');
    exit();
}

// Get total earnings/spent
if ($user_type == 'parker') {
    $total_query = "SELECT COALESCE(SUM(total_amount), 0) as total 
                    FROM reservations 
                    WHERE user_id = :user_id AND payment_status = 'paid'";
    $total_stmt = $db->prepare($total_query);
    $total_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
} else {
    $total_query = "SELECT COALESCE(SUM(r.total_amount), 0) as total 
                    FROM reservations r
                    JOIN parking_spaces ps ON r.parking_id = ps.id
                    WHERE ps.owner_id = :owner_id AND r.payment_status = 'paid'";
    $total_stmt = $db->prepare($total_query);
    $total_stmt->bindParam(':owner_id', $user_id, PDO::PARAM_INT);
}

$total_stmt->execute();
$total_earned = (float)$total_stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Get user's reservations (for parkers) or parking spaces (for owners)
if ($user_type == 'parker') {
    $items_query = "SELECT r.*, p.name as parking_name, p.address, p.city 
                    FROM reservations r 
                    JOIN parking_spaces p ON r.parking_id = p.id 
                    WHERE r.user_id = :user_id 
                    ORDER BY r.created_at DESC 
                    LIMIT 5";
    $items_stmt = $db->prepare($items_query);
    $items_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
} else {
    $items_query = "SELECT * FROM parking_spaces WHERE owner_id = :user_id AND is_active = 1 ORDER BY created_at DESC";
    $items_stmt = $db->prepare($items_query);
    $items_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
}

$items_stmt->execute();
$items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate active reservations count for parkers
$active_count = 0;
if ($user_type == 'parker') {
    foreach ($items as $item) {
        if (in_array($item['status'], ['confirmed', 'active'])) {
            $active_count++;
        }
    }
} else {
    $active_count = array_sum(array_column($items, 'available_spots'));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta name="robots" content="noindex, nofollow">
    <title>Dashboard - SpaceNode</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800;900&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'DM Sans', sans-serif;
            background: radial-gradient(ellipse at 0% 0%, #1a1a2e 0%, #16213e 40%, #0f0f23 100%);
            min-height: 100vh;
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
        
        .dashboard-container {
            display: flex;
            min-height: 100vh;
            position: relative;
            z-index: 1;
        }
        
        /* ============================================
           MOBILE TOP FLOATING GLASS PILL NAVBAR
        ============================================ */
        .mobile-top-nav {
            display: none;
            position: fixed;
            top: 16px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 1001;
            background: rgba(10, 20, 40, 0.85);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 60px;
            padding: 8px 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2), inset 0 1px 0 rgba(255, 255, 255, 0.1);
            transition: all 0.3s ease;
            width: auto;
            min-width: 280px;
            max-width: 90%;
        }
        
        .mobile-top-nav:hover {
            background: rgba(10, 20, 40, 0.95);
            border-color: rgba(255, 255, 255, 0.35);
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.25);
        }
        
        .mobile-top-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }
        
        /* Hamburger Menu */
        .mobile-hamburger {
            background: rgba(255,255,255,0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 50px;
            width: 42px;
            height: 42px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .mobile-hamburger i {
            font-size: 20px;
            color: white;
        }
        
        .mobile-hamburger:hover {
            background: rgba(79,110,247,0.2);
            transform: scale(1.02);
        }
        
        /* Logo */
        .mobile-logo img {
            height: 38px;
            width: auto;
            max-width: 130px;
            object-fit: contain;
        }
        
        /* User Info */
        .mobile-user {
            display: flex;
            align-items: center;
            gap: 10px;
            background: rgba(255,255,255,0.06);
            backdrop-filter: blur(10px);
            padding: 6px 14px 6px 10px;
            border-radius: 50px;
            border: 1px solid rgba(255,255,255,0.15);
        }
        
        .mobile-user-avatar {
            width: 34px;
            height: 34px;
            background: linear-gradient(135deg, #4F6EF7, #7C3AED);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 13px;
        }
        
        .mobile-user-name {
            color: white;
            font-size: 13px;
            font-weight: 500;
        }
        
        /* ============================================
           FLOATING GLASS DROPDOWN MENU
        ============================================ */
        .glass-dropdown-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            z-index: 1002;
            display: none;
            align-items: flex-start;
            justify-content: center;
        }
        
        .glass-dropdown-overlay.active {
            display: flex;
        }
        
        .glass-dropdown-menu {
            position: fixed;
            top: 85px;
            left: 50%;
            transform: translateX(-50%);
            width: 90%;
            max-width: 340px;
            background: rgba(10, 20, 40, 0.95);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 28px;
            padding: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.4);
            z-index: 1003;
            animation: dropdownSlideIn 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        @keyframes dropdownSlideIn {
            from {
                opacity: 0;
                transform: translateX(-50%) translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateX(-50%) translateY(0);
            }
        }
        
        .dropdown-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-bottom: 12px;
            margin-bottom: 12px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .dropdown-header h3 {
            font-family: 'Outfit', sans-serif;
            font-size: 16px;
            font-weight: 600;
            background: linear-gradient(135deg, #fff, #a5b4fc);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .dropdown-close {
            background: rgba(255,255,255,0.1);
            border: none;
            border-radius: 50%;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: rgba(255,255,255,0.7);
            transition: all 0.2s;
        }
        
        .dropdown-close:hover {
            background: rgba(239,68,68,0.2);
            color: #f87171;
        }
        
        .dropdown-items {
            display: flex;
            flex-direction: column;
            gap: 6px;
            max-height: 65vh;
            overflow-y: auto;
        }
        
        .dropdown-item {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 12px 16px;
            border-radius: 16px;
            text-decoration: none;
            color: rgba(255, 255, 255, 0.85);
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s ease;
        }
        
        .dropdown-item i {
            width: 22px;
            font-size: 16px;
            color: #a5b4fc;
        }
        
        .dropdown-item:hover {
            background: rgba(79, 110, 247, 0.2);
            color: #a5b4fc;
            transform: translateX(8px);
        }
        
        .dropdown-item.active {
            background: linear-gradient(135deg, rgba(79,110,247,0.15), rgba(124,58,237,0.15));
            color: #a5b4fc;
            border: 1px solid rgba(165,180,252,0.2);
        }
        
        .dropdown-divider {
            height: 1px;
            background: rgba(255,255,255,0.1);
            margin: 8px 0;
        }
        
        .logout-item {
            color: #f87171;
        }
        
        .logout-item i {
            color: #f87171;
        }
        
        .logout-item:hover {
            background: rgba(239,68,68,0.15);
            color: #fca5a5;
        }
        
        /* Desktop Sidebar - Completely Unchanged */
        .sidebar {
            width: 280px;
            background: rgba(255,255,255,0.06);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-right: 1px solid rgba(255,255,255,0.15);
            padding: 30px 20px;
            position: sticky;
            top: 0;
            height: 100vh;
            overflow-y: auto;
            transition: transform 0.3s ease;
            z-index: 1000;
        }
        
        .sidebar-logo {
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 40px;
            text-decoration: none;
        }
        
        .sidebar-logo img {
            max-width: 180px;
            height: auto;
            display: block;
        }
        
        .sidebar-menu {
            list-style: none;
        }
        
        .sidebar-menu li {
            margin-bottom: 8px;
        }
        
        .sidebar-menu a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            color: rgba(255,255,255,0.7);
            text-decoration: none;
            border-radius: 12px;
            transition: all 0.3s ease;
            font-size: 14px;
            font-weight: 500;
        }
        
        .sidebar-menu a:hover,
        .sidebar-menu a.active {
            background: rgba(255,255,255,0.1);
            color: #a5b4fc;
            backdrop-filter: blur(10px);
        }
        
        .sidebar-menu a svg {
            width: 20px;
            height: 20px;
            stroke: currentColor;
        }
        
        .sidebar-menu .logout {
            margin-top: 40px;
            color: rgba(248,113,113,0.8);
        }
        
        .sidebar-menu .logout:hover {
            background: rgba(239,68,68,0.15);
            color: #f87171;
        }
        
        /* Glassmorphism Main Content */
        .main-content {
            flex: 1;
            padding: 30px 40px;
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .header h1 {
            font-family: 'Outfit', sans-serif;
            font-size: 28px;
            font-weight: 700;
            background: linear-gradient(135deg, #fff, #a5b4fc);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .header h1 span {
            font-size: 14px;
            font-weight: 400;
            color: rgba(255,255,255,0.5);
            margin-left: 10px;
            -webkit-text-fill-color: rgba(255,255,255,0.5);
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 20px;
            background: rgba(255,255,255,0.06);
            backdrop-filter: blur(10px);
            padding: 8px 20px 8px 15px;
            border-radius: 60px;
            border: 1px solid rgba(255,255,255,0.15);
        }
        
        .user-avatar {
            width: 44px;
            height: 44px;
            background: linear-gradient(135deg, #4F6EF7, #7C3AED);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 18px;
            font-family: 'Outfit', sans-serif;
            box-shadow: 0 4px 15px rgba(79,110,247,0.3);
        }
        
        .user-details p {
            font-size: 14px;
            font-weight: 600;
            color: white;
        }
        
        .user-details small {
            font-size: 12px;
            color: rgba(255,255,255,0.6);
        }
        
        /* Glassmorphism Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: rgba(255,255,255,0.06);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 24px;
            padding: 24px;
            transition: all 0.4s cubic-bezier(0.4,0,0.2,1);
            box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.2);
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            background: rgba(255,255,255,0.1);
            border-color: rgba(255,255,255,0.3);
            box-shadow: 0 16px 48px 0 rgba(0, 0, 0, 0.3);
        }
        
        .stat-card h3 {
            font-size: 14px;
            font-weight: 500;
            color: rgba(255,255,255,0.7);
            margin-bottom: 12px;
        }
        
        .stat-card .number {
            font-family: 'Outfit', sans-serif;
            font-size: 32px;
            font-weight: 800;
            background: linear-gradient(135deg, #fff, #a5b4fc);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 5px;
        }
        
        .stat-card .trend {
            font-size: 12px;
            color: #4ade80;
        }
        
        /* Glassmorphism Recent Section */
        .recent-section {
            background: rgba(255,255,255,0.06);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 28px;
            padding: 28px;
            transition: all 0.4s ease;
        }
        
        .recent-section:hover {
            background: rgba(255,255,255,0.08);
            box-shadow: 0 16px 48px 0 rgba(0, 0, 0, 0.3);
        }
        
        .recent-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .recent-header h2 {
            font-family: 'Outfit', sans-serif;
            font-size: 22px;
            font-weight: 700;
            color: white;
            letter-spacing: -0.5px;
        }
        
        .btn-add {
            background: linear-gradient(135deg, #4F6EF7, #7C3AED);
            color: white;
            padding: 10px 20px;
            border-radius: 50px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            font-family: 'Outfit', sans-serif;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(79,110,247,0.3);
            position: relative;
            overflow: hidden;
        }
        
        .btn-add::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s ease;
        }
        
        .btn-add:hover::before {
            left: 100%;
        }
        
        .btn-add:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(79,110,247,0.4);
        }
        
        /* Parking Spaces Grid */
        .parking-spaces-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 24px;
            margin-top: 20px;
        }
        
        .parking-space-card {
            background: rgba(255,255,255,0.06);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 20px;
            overflow: hidden;
            transition: all 0.4s cubic-bezier(0.4,0,0.2,1);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
        }
        
        .parking-space-card:hover {
            transform: translateY(-5px);
            background: rgba(255,255,255,0.1);
            border-color: rgba(255,255,255,0.3);
            box-shadow: 0 20px 40px rgba(79,110,247,0.2);
        }
        
        .space-image {
            position: relative;
            height: 180px;
            overflow: hidden;
            background: linear-gradient(135deg, #1a1a2e, #16213e);
        }
        
        .space-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s ease;
        }
        
        .parking-space-card:hover .space-image img {
            transform: scale(1.05);
        }
        
        .space-status {
            position: absolute;
            top: 12px;
            right: 12px;
            padding: 5px 12px;
            border-radius: 50px;
            font-size: 12px;
            font-weight: 600;
            backdrop-filter: blur(10px);
            z-index: 2;
        }
        
        .space-status.active {
            background: rgba(34,197,94,0.2);
            color: #4ade80;
            border: 1px solid rgba(34,197,94,0.3);
        }
        
        .space-status.full {
            background: rgba(239,68,68,0.2);
            color: #f87171;
            border: 1px solid rgba(239,68,68,0.3);
        }
        
        .space-details {
            padding: 18px;
        }
        
        .space-details h3 {
            font-family: 'Outfit', sans-serif;
            font-size: 18px;
            font-weight: 700;
            color: white;
            margin-bottom: 6px;
        }
        
        .space-location {
            font-size: 13px;
            color: rgba(255,255,255,0.6);
            margin-bottom: 12px;
        }
        
        .space-prices {
            display: flex;
            gap: 12px;
            margin-bottom: 16px;
            font-size: 14px;
            font-weight: 600;
            color: #a5b4fc;
        }
        
        .space-actions {
            display: flex;
            gap: 10px;
        }
        
        .space-actions a, .space-actions button {
            flex: 1;
            padding: 10px;
            border: none;
            border-radius: 12px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            text-align: center;
            text-decoration: none;
            transition: all 0.3s ease;
            font-family: 'DM Sans', sans-serif;
        }
        
        .btn-edit {
            background: rgba(255,255,255,0.1);
            color: rgba(255,255,255,0.8);
            backdrop-filter: blur(5px);
        }
        
        .btn-edit:hover {
            background: rgba(255,255,255,0.2);
            transform: translateY(-2px);
        }
        
        .btn-view {
            background: linear-gradient(135deg, #4F6EF7, #7C3AED);
            color: white;
            box-shadow: 0 4px 12px rgba(79,110,247,0.3);
        }
        
        .btn-view:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(79,110,247,0.4);
        }
        
        .btn-delete {
            background: rgba(239,68,68,0.15);
            color: #f87171;
            border: 1px solid rgba(239,68,68,0.3);
        }
        
        .btn-delete:hover {
            background: rgba(239,68,68,0.25);
            transform: translateY(-2px);
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }
        
        .empty-state svg {
            width: 80px;
            height: 80px;
            margin-bottom: 20px;
            opacity: 0.4;
            stroke: rgba(255,255,255,0.5);
        }
        
        .empty-state p {
            color: rgba(255,255,255,0.6);
            font-size: 16px;
        }
        
        .empty-state a {
            color: #a5b4fc;
            text-decoration: none;
            margin-top: 15px;
            display: inline-block;
            font-weight: 600;
        }
        
        .empty-state a:hover {
            color: #c4b5fd;
            text-decoration: underline;
        }
        
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
        
        /* Animations */
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
        
        .stat-card, .parking-space-card {
            animation: fadeInUp 0.5s ease forwards;
            opacity: 0;
        }
        
        .stat-card:nth-child(1) { animation-delay: 0.05s; }
        .stat-card:nth-child(2) { animation-delay: 0.1s; }
        .stat-card:nth-child(3) { animation-delay: 0.15s; }
        .stat-card:nth-child(4) { animation-delay: 0.2s; }
        
        /* Responsive Design - Mobile Only */
        @media (max-width: 1024px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 768px) {
            body {
                padding-top: 80px;
                padding-bottom: 20px;
            }
            
            /* Show mobile top floating navbar */
            .mobile-top-nav {
                display: block;
            }
            
            /* Hide desktop sidebar on mobile */
            .sidebar {
                display: none;
            }
            
            .main-content {
                padding: 20px;
                margin-top: 0;
            }
            
            .header {
                display: block;
                text-align: center;
            }
            
            .user-info {
                display: none;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 16px;
                margin-top: 20px;
            }
            
            .stat-card .number {
                font-size: 24px;
            }
            
            .parking-spaces-grid {
                grid-template-columns: 1fr;
            }
            
            .recent-section {
                padding: 20px;
            }
            
            .glass-dropdown-menu {
                top: 75px;
                width: 92%;
                max-width: 320px;
            }
        }
        
        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .stat-card {
                padding: 18px;
            }
            
            .space-actions {
                flex-wrap: wrap;
            }
            
            .space-actions a, .space-actions button {
                min-width: 80px;
            }
            
            .recent-header h2 {
                font-size: 18px;
            }
            
            .btn-add {
                padding: 8px 16px;
                font-size: 13px;
            }
            
            .mobile-top-nav {
                top: 18px;
                padding: 6px 12px;
                min-width: 260px;
            }
            
            .mobile-hamburger {
                width: 36px;
                height: 36px;
            }
            
            .mobile-hamburger i {
                font-size: 18px;
            }
            
            .mobile-logo img {
                height: 32px;
                max-width: 100px;
            }
            
            .mobile-user-avatar {
                width: 28px;
                height: 28px;
                font-size: 11px;
            }
            
            .mobile-user-name {
                font-size: 11px;
            }
            
            .glass-dropdown-menu {
                top: 68px;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Mobile Floating Glass Pill Navbar (Top) -->
        <div class="mobile-top-nav">
            <div class="mobile-top-content">
                <!-- Hamburger Menu -->
                <div class="mobile-hamburger" onclick="toggleDropdown()">
                    <i class="fas fa-bars"></i>
                </div>
                
                <!-- Logo -->
                <div class="mobile-logo">
                    <img src="img/logo.png" alt="SpaceNode" onerror="this.src='img/logo-placeholder.jpg'">
                </div>
                
                <!-- User Info -->
                <div class="mobile-user">
                    <div class="mobile-user-avatar">
                        <?php 
                        $first_initial = !empty($user['first_name']) ? strtoupper(substr($user['first_name'], 0, 1)) : '';
                        $last_initial = !empty($user['last_name']) ? strtoupper(substr($user['last_name'], 0, 1)) : '';
                        echo sanitize($first_initial . $last_initial);
                        ?>
                    </div>
                    <div class="mobile-user-name">
                        <?php echo sanitize($user['first_name']); ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Floating Glass Dropdown Menu -->
        <div class="glass-dropdown-overlay" id="dropdownOverlay" onclick="closeDropdown()">
            <div class="glass-dropdown-menu" onclick="event.stopPropagation()">
                <div class="dropdown-header">
                    <h3><i class="fas fa-compass"></i> Navigate</h3>
                    <button class="dropdown-close" onclick="closeDropdown()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                
                <div class="dropdown-items">
                    <?php if ($user_type == 'owner'): ?>
                        <a href="dashboard.php" class="dropdown-item active">
                            <i class="fas fa-tachometer-alt"></i>
                            <span>Dashboard</span>
                        </a>
                        <a href="my-spaces.php" class="dropdown-item">
                            <i class="fas fa-parking"></i>
                            <span>My Spaces</span>
                        </a>
                        <a href="owner-reservations.php" class="dropdown-item">
                            <i class="fas fa-calendar-check"></i>
                            <span>Manage Bookings</span>
                        </a>
                        <a href="owner-earnings.php" class="dropdown-item">
                            <i class="fas fa-chart-line"></i>
                            <span>Earnings</span>
                        </a>
                        <div class="dropdown-divider"></div>
                        <a href="owner/enter-pin.php" class="dropdown-item">
                            <i class="fas fa-key"></i>
                            <span>Enter PIN</span>
                        </a>
                        <a href="owner/active-sessions.php" class="dropdown-item">
                            <i class="fas fa-clock"></i>
                            <span>Active Sessions</span>
                        </a>
                        <div class="dropdown-divider"></div>
                        <a href="profile.php" class="dropdown-item">
                            <i class="fas fa-user"></i>
                            <span>Profile</span>
                        </a>
                        <a href="settings.php" class="dropdown-item">
                            <i class="fas fa-cog"></i>
                            <span>Settings</span>
                        </a>
                        <div class="dropdown-divider"></div>
                        <a href="logout.php" class="dropdown-item logout-item">
                            <i class="fas fa-sign-out-alt"></i>
                            <span>Logout</span>
                        </a>
                    <?php else: ?>
                        <a href="dashboard.php" class="dropdown-item active">
                            <i class="fas fa-tachometer-alt"></i>
                            <span>Dashboard</span>
                        </a>
                        <a href="all-spaces.php" class="dropdown-item">
                            <i class="fas fa-search"></i>
                            <span>Find Parking</span>
                        </a>
                        <a href="my-reservations.php" class="dropdown-item">
                            <i class="fas fa-calendar-check"></i>
                            <span>My Reservations</span>
                        </a>
                        <div class="dropdown-divider"></div>
                        <a href="profile.php" class="dropdown-item">
                            <i class="fas fa-user"></i>
                            <span>Profile</span>
                        </a>
                        <a href="settings.php" class="dropdown-item">
                            <i class="fas fa-cog"></i>
                            <span>Settings</span>
                        </a>
                        <div class="dropdown-divider"></div>
                        <a href="logout.php" class="dropdown-item logout-item">
                            <i class="fas fa-sign-out-alt"></i>
                            <span>Logout</span>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Original Desktop Sidebar (Completely Unchanged) -->
        <aside class="sidebar">
            <a href="index.php" class="sidebar-logo">
                <img src="img/logo.png" alt="SpaceNode" onerror="this.src='img/logo-placeholder.jpg'">
            </a>
            
            <ul class="sidebar-menu">
                <li><a href="dashboard.php" class="active">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                    Dashboard
                </a></li>
                
                <?php if ($user_type == 'parker'): ?>
                <!-- Parker Specific Links -->
                <li><a href="all-spaces.php">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                    Find Parking
                </a></li>
                <li><a href="my-reservations.php">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    My Reservations
                </a></li>
                
                <?php else: ?>
                <!-- Owner Specific Links -->
                <li><a href="my-spaces.php">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                    My Spaces
                </a></li>
                <li><a href="owner-reservations.php">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    Manage Bookings
                </a></li>
                <li><a href="owner-earnings.php">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="20" x2="12" y2="10"/><line x1="18" y1="20" x2="18" y2="4"/><line x1="6" y1="20" x2="6" y2="16"/></svg>
                    Earnings
                </a></li>
                
                <!-- Owner Only Links (PIN & Active Sessions) -->
                <li><a href="owner/enter-pin.php">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                        <line x1="3" y1="9" x2="21" y2="9"/>
                        <line x1="9" y1="21" x2="9" y2="9"/>
                    </svg>
                    Enter PIN
                </a></li>
                <li><a href="owner/active-sessions.php">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/>
                        <polyline points="12 6 12 12 16 14"/>
                    </svg>
                    Active Sessions
                </a></li>
                <?php endif; ?>

                <!-- Common Links for Both User Types -->
                <li><a href="profile.php">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    Profile
                </a></li>
                <li><a href="settings.php">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="3"/>
                        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                    </svg>
                    Settings
                </a></li>
                <li><a href="logout.php" class="logout">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                    Logout
                </a></li>
            </ul>
        </aside>
        
        <!-- Main Content -->
        <main class="main-content">
            <div class="header">
                <h1>
                    Welcome back, <?php echo sanitize($user['first_name']); ?>!
                    <span><?php echo ucfirst(sanitize($user_type)); ?></span>
                </h1>
                <div class="user-info">
                    <div class="user-details">
                        <p><?php echo sanitize($user['first_name'] . ' ' . $user['last_name']); ?></p>
                        <small><?php echo sanitize($user['email']); ?></small>
                    </div>
                    <div class="user-avatar">
                        <?php 
                        $first_initial = !empty($user['first_name']) ? strtoupper(substr($user['first_name'], 0, 1)) : '';
                        $last_initial = !empty($user['last_name']) ? strtoupper(substr($user['last_name'], 0, 1)) : '';
                        echo sanitize($first_initial . $last_initial);
                        ?>
                    </div>
                </div>
            </div>
            
            <!-- Glassmorphism Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <h3>Total <?php echo $user_type == 'parker' ? 'Reservations' : 'Spaces'; ?></h3>
                    <div class="number"><?php echo count($items); ?></div>
                </div>
                <div class="stat-card">
                    <h3><?php echo $user_type == 'parker' ? 'Active Reservations' : 'Available Spots'; ?></h3>
                    <div class="number"><?php echo $active_count; ?></div>
                </div>
                <div class="stat-card">
                    <h3>Total <?php echo $user_type == 'parker' ? 'Spent' : 'Earned'; ?></h3>
                    <div class="number">₦<?php echo number_format($total_earned, 2); ?></div>
                </div>
                <div class="stat-card">
                    <h3>Member Since</h3>
                    <div class="number"><?php echo date('M Y', strtotime($user['created_at'])); ?></div>
                </div>
            </div>
            
            <?php if ($user_type == 'owner'): ?>
            <!-- Glassmorphism Recent Section -->
            <div class="recent-section">
                <div class="recent-header">
                    <h2>Your Parking Spaces</h2>
                    <a href="add-parking.php" class="btn-add">+ Add New Space</a>
                </div>
                
                <?php if (empty($items)): ?>
                    <div class="empty-state">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <circle cx="12" cy="12" r="10"/>
                            <line x1="12" y1="8" x2="12" y2="12"/>
                            <line x1="12" y1="16" x2="12.01" y2="16"/>
                        </svg>
                        <p>No parking spaces yet.</p>
                        <a href="add-parking.php">+ Add Your First Space →</a>
                    </div>
                <?php else: ?>
                    <div class="parking-spaces-grid">
                        <?php foreach ($items as $space): 
                            $main_image = getImageUrl($space['images'] ?? '');
                        ?>
                        <div class="parking-space-card">
                            <div class="space-image">
                                <img src="<?php echo sanitize($main_image); ?>" alt="<?php echo sanitize($space['name']); ?>" onerror="this.src='img/parking-placeholder.jpg'">
                                <span class="space-status <?php echo ($space['available_spots'] ?? 0) > 0 ? 'active' : 'full'; ?>">
                                    <?php echo (int)($space['available_spots'] ?? 0); ?>/<?php echo (int)($space['total_spots'] ?? 0); ?> spots
                                </span>
                            </div>
                            <div class="space-details">
                                <h3><?php echo sanitize($space['name']); ?></h3>
                                <p class="space-location">📍 <?php echo sanitize($space['city'] ?? ''); ?></p>
                                <div class="space-prices">
                                    <?php if (!empty($space['hourly_rate'])): ?>
                                        <span>₦<?php echo number_format($space['hourly_rate'], 0); ?>/hr</span>
                                    <?php endif; ?>
                                    <?php if (!empty($space['daily_rate'])): ?>
                                        <span>₦<?php echo number_format($space['daily_rate'], 0); ?>/day</span>
                                    <?php endif; ?>
                                </div>
                                <div class="space-actions">
                                    <a href="edit-parking.php?id=<?php echo (int)$space['id']; ?>" class="btn-edit">Edit</a>
                                    <a href="parking-details.php?id=<?php echo (int)$space['id']; ?>" class="btn-view">View</a>
                                    <button onclick="deleteSpace(<?php echo (int)$space['id']; ?>)" class="btn-delete">Delete</button>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </main>
    </div>

    <script>
    function toggleDropdown() {
        const overlay = document.getElementById('dropdownOverlay');
        overlay.classList.toggle('active');
        document.body.style.overflow = overlay.classList.contains('active') ? 'hidden' : '';
    }
    
    function closeDropdown() {
        const overlay = document.getElementById('dropdownOverlay');
        overlay.classList.remove('active');
        document.body.style.overflow = '';
    }
    
    function deleteSpace(id) {
        if (confirm('Are you sure you want to delete this parking space? This action cannot be undone.')) {
            window.location.href = 'delete-parking.php?id=' + id;
        }
    }
    </script>
    <?php include __DIR__ . '/chat/widget.php'; ?>
</body>
</html>