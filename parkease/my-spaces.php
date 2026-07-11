<?php
session_start();
require_once 'includes/user-access.php';
require_once 'config/database.php';

// Check if user is logged in and is an owner
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'owner') {
    header('Location: login.php');
    exit();
}

$database = new Database();
$db = $database->getConnection();

$owner_id = $_SESSION['user_id'];

// Get all parking spaces for this owner
$query = "SELECT * FROM parking_spaces WHERE owner_id = :owner_id ORDER BY created_at DESC";
$stmt = $db->prepare($query);
$stmt->bindParam(':owner_id', $owner_id);
$stmt->execute();
$spaces = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$stats_query = "SELECT 
                COUNT(*) as total_spaces,
                SUM(total_spots) as total_capacity,
                SUM(available_spots) as total_available,
                AVG(hourly_rate) as avg_hourly_rate
                FROM parking_spaces 
                WHERE owner_id = :owner_id";
$stats_stmt = $db->prepare($stats_query);
$stats_stmt->bindParam(':owner_id', $owner_id);
$stats_stmt->execute();
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Handle filters
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Build search query
if (!empty($search)) {
    $search_query = "SELECT * FROM parking_spaces 
                     WHERE owner_id = :owner_id 
                     AND (name LIKE :search OR city LIKE :search OR address LIKE :search)
                     ORDER BY created_at DESC";
    $search_stmt = $db->prepare($search_query);
    $search_stmt->bindParam(':owner_id', $owner_id);
    $search_param = "%$search%";
    $search_stmt->bindParam(':search', $search_param);
    $search_stmt->execute();
    $spaces = $search_stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <title>My Parking Spaces - SpaceNode</title>
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
        
        /* Glassmorphism Sidebar */
        .sidebar {
            width: 280px;
            background: rgba(255,255,255,0.06);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-right: 1px solid rgba(255,255,255,0.1);
            padding: 30px 20px;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
        }
        
        .sidebar-logo {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 40px;
            text-decoration: none;
        }
        
        .sidebar-logo-icon {
            width: 42px;
            height: 42px;
            background: linear-gradient(135deg, #4F6EF7, #7C3AED);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 15px rgba(79,110,247,0.3);
        }
        
        .sidebar-logo-icon svg {
            width: 22px;
            height: 22px;
            fill: white;
        }
        
        .sidebar-logo-text h2 {
            font-family: 'Outfit', sans-serif;
            font-size: 20px;
            font-weight: 700;
            background: linear-gradient(135deg, #fff, #a5b4fc);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .sidebar-logo-text p {
            font-size: 10px;
            color: rgba(255,255,255,0.6);
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
            margin-left: 280px;
            padding: 30px 40px;
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 32px;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .header h1 {
            font-family: 'Outfit', sans-serif;
            font-size: clamp(24px, 5vw, 32px);
            font-weight: 700;
            background: linear-gradient(135deg, #fff, #a5b4fc);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            letter-spacing: -0.5px;
        }
        
        .btn-add {
            background: linear-gradient(135deg, #4F6EF7, #7C3AED);
            color: white;
            padding: 12px 24px;
            border-radius: 50px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            font-family: 'Outfit', sans-serif;
            display: inline-flex;
            align-items: center;
            gap: 8px;
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
        
        .stat-card:nth-child(1) { animation-delay: 0.05s; }
        .stat-card:nth-child(2) { animation-delay: 0.1s; }
        .stat-card:nth-child(3) { animation-delay: 0.15s; }
        .stat-card:nth-child(4) { animation-delay: 0.2s; }
        
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
        
        .stat-number {
            font-family: 'Outfit', sans-serif;
            font-size: 32px;
            font-weight: 800;
            background: linear-gradient(135deg, #fff, #a5b4fc);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .stat-label {
            font-size: 12px;
            color: #4ade80;
            margin-top: 8px;
        }
        
        /* Glassmorphism Search Section */
        .search-section {
            background: rgba(255,255,255,0.06);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 24px;
            padding: 24px;
            margin-bottom: 30px;
            transition: all 0.3s ease;
        }
        
        .search-form {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .search-input {
            flex: 1;
            padding: 14px 20px;
            background: rgba(255,255,255,0.05);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 60px;
            font-size: 14px;
            font-family: 'DM Sans', sans-serif;
            color: white;
            transition: all 0.3s ease;
        }
        
        .search-input::placeholder {
            color: rgba(255,255,255,0.4);
        }
        
        .search-input:focus {
            outline: none;
            border-color: rgba(165,180,252,0.6);
            background: rgba(255,255,255,0.1);
            box-shadow: 0 0 0 3px rgba(79,110,247,0.2);
        }
        
        .search-btn {
            background: linear-gradient(135deg, #4F6EF7, #7C3AED);
            color: white;
            border: none;
            padding: 14px 28px;
            border-radius: 60px;
            font-weight: 600;
            cursor: pointer;
            font-family: 'Outfit', sans-serif;
            transition: all 0.3s ease;
        }
        
        .search-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(79,110,247,0.4);
        }
        
        .reset-btn {
            background: rgba(255,255,255,0.1);
            color: rgba(255,255,255,0.9);
            border: 1px solid rgba(255,255,255,0.2);
            padding: 14px 28px;
            border-radius: 60px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s ease;
        }
        
        .reset-btn:hover {
            background: rgba(255,255,255,0.2);
            transform: translateY(-2px);
        }
        
        /* Glassmorphism Spaces Grid */
        .spaces-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
            gap: 24px;
            margin-top: 20px;
        }
        
        .space-card {
            background: rgba(255,255,255,0.06);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 24px;
            overflow: hidden;
            transition: all 0.4s cubic-bezier(0.4,0,0.2,1);
            box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.2);
            animation: fadeInUp 0.5s ease forwards;
            opacity: 0;
        }
        
        .space-card:nth-child(1) { animation-delay: 0.05s; }
        .space-card:nth-child(2) { animation-delay: 0.1s; }
        .space-card:nth-child(3) { animation-delay: 0.15s; }
        .space-card:nth-child(4) { animation-delay: 0.2s; }
        
        .space-card:hover {
            transform: translateY(-5px);
            background: rgba(255,255,255,0.1);
            border-color: rgba(255,255,255,0.3);
            box-shadow: 0 20px 48px 0 rgba(0, 0, 0, 0.3);
        }
        
        .space-image {
            position: relative;
            height: 200px;
            overflow: hidden;
            background: linear-gradient(135deg, #1a1a2e, #16213e);
        }
        
        .space-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s ease;
        }
        
        .space-card:hover .space-image img {
            transform: scale(1.05);
        }
        
        .space-type {
            position: absolute;
            top: 12px;
            left: 12px;
            background: rgba(0,0,0,0.6);
            backdrop-filter: blur(8px);
            color: white;
            padding: 5px 12px;
            border-radius: 50px;
            font-size: 11px;
            font-weight: 600;
            text-transform: capitalize;
            border: 1px solid rgba(255,255,255,0.2);
        }
        
        .space-status {
            position: absolute;
            top: 12px;
            right: 12px;
            padding: 5px 12px;
            border-radius: 50px;
            font-size: 11px;
            font-weight: 600;
            backdrop-filter: blur(8px);
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .space-status:hover {
            transform: scale(1.05);
        }
        
        .status-active {
            background: rgba(34,197,94,0.15);
            color: #4ade80;
            border: 1px solid rgba(34,197,94,0.3);
        }
        
        .status-full {
            background: rgba(239,68,68,0.15);
            color: #f87171;
            border: 1px solid rgba(239,68,68,0.3);
        }
        
        .space-content {
            padding: 20px;
        }
        
        .space-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .space-name {
            font-family: 'Outfit', sans-serif;
            font-size: 18px;
            font-weight: 700;
            color: white;
            letter-spacing: -0.3px;
        }
        
        .space-location {
            display: flex;
            align-items: center;
            gap: 6px;
            color: rgba(255,255,255,0.6);
            font-size: 13px;
            margin-bottom: 16px;
        }
        
        /* Glassmorphism Availability Toggle */
        .availability-toggle {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin: 12px 0;
            padding: 12px;
            background: rgba(255,255,255,0.05);
            border-radius: 60px;
            border: 1px solid rgba(255,255,255,0.1);
        }
        
        .toggle-label {
            font-size: 13px;
            font-weight: 500;
        }
        
        .toggle-label.available {
            color: #4ade80;
        }
        
        .toggle-label.full {
            color: #f87171;
        }
        
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 52px;
            height: 26px;
        }
        
        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        
        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(239,68,68,0.3);
            transition: .3s;
            border-radius: 34px;
        }
        
        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 22px;
            width: 22px;
            left: 2px;
            bottom: 2px;
            background-color: #f87171;
            transition: .3s;
            border-radius: 50%;
        }
        
        input:checked + .toggle-slider {
            background-color: rgba(34,197,94,0.3);
        }
        
        input:checked + .toggle-slider:before {
            transform: translateX(26px);
            background-color: #4ade80;
        }
        
        /* Quick Actions */
        .quick-actions {
            display: flex;
            gap: 10px;
            margin: 12px 0;
        }
        
        .quick-action-btn {
            flex: 1;
            padding: 8px;
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 50px;
            color: rgba(255,255,255,0.8);
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .quick-action-btn:hover {
            background: rgba(79,110,247,0.2);
            border-color: rgba(165,180,252,0.4);
        }
        
        /* Spot Badges */
        .spot-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 14px;
            border-radius: 50px;
            font-size: 12px;
            font-weight: 600;
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.15);
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .spot-badge:hover {
            background: rgba(79,110,247,0.2);
            transform: scale(1.05);
        }
        
        .spot-badge.available {
            background: rgba(34,197,94,0.15);
            color: #4ade80;
            border-color: rgba(34,197,94,0.3);
        }
        
        /* Amenities */
        .space-amenities {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin: 12px 0;
        }
        
        .amenity-tag {
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.1);
            color: rgba(255,255,255,0.7);
            padding: 4px 10px;
            border-radius: 50px;
            font-size: 11px;
        }
        
        /* Space Stats */
        .space-stats {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-top: 1px solid rgba(255,255,255,0.1);
            border-bottom: 1px solid rgba(255,255,255,0.1);
            margin: 12px 0;
        }
        
        .stat-item {
            text-align: center;
            flex: 1;
        }
        
        .stat-label {
            font-size: 11px;
            color: rgba(255,255,255,0.5);
            margin-bottom: 4px;
        }
        
        .stat-value {
            font-size: 16px;
            font-weight: 700;
            color: #a5b4fc;
        }
        
        .space-prices {
            display: flex;
            gap: 12px;
            margin: 12px 0;
            font-size: 13px;
            font-weight: 600;
            color: #a5b4fc;
        }
        
        /* Action Buttons */
        .space-actions {
            display: flex;
            gap: 10px;
            margin-top: 16px;
        }
        
        .btn-edit, .btn-view, .btn-delete {
            flex: 1;
            padding: 10px;
            border: none;
            border-radius: 50px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            text-align: center;
            text-decoration: none;
            transition: all 0.3s;
            font-family: 'Outfit', sans-serif;
        }
        
        .btn-edit {
            background: rgba(255,255,255,0.1);
            color: rgba(255,255,255,0.9);
        }
        
        .btn-edit:hover {
            background: rgba(255,255,255,0.2);
            transform: translateY(-2px);
        }
        
        .btn-view {
            background: linear-gradient(135deg, #4F6EF7, #7C3AED);
            color: white;
        }
        
        .btn-view:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(79,110,247,0.4);
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
            padding: 60px 40px;
            background: rgba(255,255,255,0.06);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 28px;
            grid-column: 1 / -1;
        }
        
        .empty-state svg {
            width: 80px;
            height: 80px;
            margin-bottom: 20px;
            opacity: 0.4;
            stroke: rgba(255,255,255,0.5);
        }
        
        .empty-state h3 {
            font-family: 'Outfit', sans-serif;
            font-size: 22px;
            color: white;
            margin-bottom: 10px;
        }
        
        .empty-state p {
            color: rgba(255,255,255,0.6);
            margin-bottom: 20px;
        }
        
        .empty-state a {
            background: linear-gradient(135deg, #4F6EF7, #7C3AED);
            color: white;
            padding: 12px 24px;
            border-radius: 50px;
            text-decoration: none;
            display: inline-block;
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
        
        /* Responsive Design */
        @media (max-width: 1024px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 768px) {
            .sidebar {
                width: 0;
                display: none;
            }
            
            .main-content {
                margin-left: 0;
                padding: 20px;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 16px;
            }
            
            .spaces-grid {
                grid-template-columns: 1fr;
            }
            
            .search-form {
                flex-direction: column;
            }
            
            .search-input, .search-btn, .reset-btn {
                width: 100%;
            }
        }
        
        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .btn-add {
                width: 100%;
                justify-content: center;
            }
            
            .quick-actions {
                flex-direction: column;
            }
            
            .space-actions {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Glassmorphism Sidebar -->
        <aside class="sidebar">
            <a href="index.php" class="sidebar-logo">
                <div class="sidebar-logo-icon">
                    <svg viewBox="0 0 24 24"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z"/></svg>
                </div>
                <div class="sidebar-logo-text">
                    <h2>SpaceNode</h2>
                    <p>Smart Parking Solutions</p>
                </div>
            </a>
            
            <ul class="sidebar-menu">
                <li><a href="dashboard.php">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                    Dashboard
                </a></li>
                
                <li><a href="my-spaces.php" class="active">
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
                
                <li><a href="#">
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
                <h1><i class="fas fa-parking"></i> My Parking Spaces</h1>
                <a href="add-parking.php" class="btn-add">
                    <i class="fas fa-plus"></i> Add New Space
                </a>
            </div>
            
            <!-- Glassmorphism Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <h3><i class="fas fa-building"></i> Total Spaces</h3>
                    <div class="stat-number"><?php echo $stats['total_spaces'] ?? 0; ?></div>
                </div>
                <div class="stat-card">
                    <h3><i class="fas fa-warehouse"></i> Total Capacity</h3>
                    <div class="stat-number"><?php echo $stats['total_capacity'] ?? 0; ?></div>
                </div>
                <div class="stat-card">
                    <h3><i class="fas fa-check-circle"></i> Available Spots</h3>
                    <div class="stat-number"><?php echo $stats['total_available'] ?? 0; ?></div>
                    <div class="stat-label">across all spaces</div>
                </div>
                <div class="stat-card">
                    <h3><i class="fas fa-chart-line"></i> Avg. Hourly Rate</h3>
                    <div class="stat-number">₦<?php echo number_format($stats['avg_hourly_rate'] ?? 0, 0); ?></div>
                </div>
            </div>
            
            <!-- Glassmorphism Search Section -->
            <div class="search-section">
                <form method="GET" class="search-form">
                    <input type="text" name="search" class="search-input" placeholder="Search by name, city, or address..." value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit" class="search-btn"><i class="fas fa-search"></i> Search</button>
                    <?php if (!empty($search)): ?>
                        <a href="my-spaces.php" class="reset-btn"><i class="fas fa-times"></i> Clear Search</a>
                    <?php endif; ?>
                </form>
            </div>
            
            <!-- Spaces Grid -->
            <?php if (empty($spaces)): ?>
                <div class="empty-state">
                    <i class="fas fa-parking fa-4x" style="color: rgba(255,255,255,0.4); margin-bottom: 20px;"></i>
                    <?php if (!empty($search)): ?>
                        <h3>No spaces found matching "<?php echo htmlspecialchars($search); ?>"</h3>
                        <p>Try a different search term or <a href="my-spaces.php">view all spaces</a>.</p>
                    <?php else: ?>
                        <h3>No parking spaces yet</h3>
                        <p>Start by adding your first parking space.</p>
                        <a href="add-parking.php">+ Add Your First Space</a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="spaces-grid">
                    <?php foreach ($spaces as $space): 
                        $space_images = !empty($space['images']) ? json_decode($space['images'], true) : [];
                        $main_image = !empty($space_images) ? 'uploads/parking/' . $space_images[0] : 'img/parking-placeholder.jpg';
                        $amenities = !empty($space['amenities']) ? json_decode($space['amenities'], true) : [];
                        $display_amenities = array_slice($amenities, 0, 3);
                        $type_display = str_replace('_', ' ', $space['parking_type']);
                        $type_display = ucwords($type_display);
                        $status_class = $space['available_spots'] > 0 ? 'status-active' : 'status-full';
                        $status_text = $space['available_spots'] > 0 ? $space['available_spots'] . '/' . $space['total_spots'] . ' spots' : 'Full';
                    ?>
                    <div class="space-card" data-space-id="<?php echo $space['id']; ?>">
                        <div class="space-image">
                            <img src="<?php echo $main_image; ?>" alt="<?php echo htmlspecialchars($space['name']); ?>" onerror="this.src='img/parking-placeholder.jpg'">
                            <span class="space-type"><i class="fas fa-tag"></i> <?php echo $type_display; ?></span>
                            <span class="space-status <?php echo $status_class; ?>"><i class="fas <?php echo $space['available_spots'] > 0 ? 'fa-check-circle' : 'fa-times-circle'; ?>"></i> <?php echo $status_text; ?></span>
                        </div>
                        
                        <div class="space-content">
                            <div class="space-header">
                                <h3 class="space-name"><?php echo htmlspecialchars($space['name']); ?></h3>
                            </div>
                            
                            <div class="space-location">
                                <i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($space['city']); ?>
                            </div>
                            
                            <!-- Glassmorphism Availability Toggle Section -->
                            <div class="availability-toggle">
                                <span class="toggle-label <?php echo $space['available_spots'] > 0 ? 'available' : 'full'; ?>">
                                    <i class="fas <?php echo $space['available_spots'] > 0 ? 'fa-check-circle' : 'fa-exclamation-circle'; ?>"></i>
                                    <?php echo $space['available_spots']; ?>/<?php echo $space['total_spots']; ?> spots available
                                </span>
                                <label class="toggle-switch">
                                    <input type="checkbox" 
                                           class="availability-checkbox" 
                                           data-space-id="<?php echo $space['id']; ?>"
                                           data-total="<?php echo $space['total_spots']; ?>"
                                           <?php echo $space['available_spots'] > 0 ? 'checked' : ''; ?>
                                           onchange="toggleAvailability(<?php echo $space['id']; ?>, this.checked)">
                                    <span class="toggle-slider"></span>
                                </label>
                            </div>
                            
                            <!-- Quick Actions -->
                            <div class="quick-actions">
                                <button class="quick-action-btn" onclick="setAvailability(<?php echo $space['id']; ?>, <?php echo $space['total_spots']; ?>)">
                                    <i class="fas fa-check-circle"></i> Set All Available
                                </button>
                                <button class="quick-action-btn" onclick="setAvailability(<?php echo $space['id']; ?>, 0)">
                                    <i class="fas fa-ban"></i> Set Full
                                </button>
                            </div>
                            
                            <!-- Spot Badges -->
                            <div style="display: flex; flex-wrap: wrap; gap: 8px; margin: 12px 0;">
                                <?php 
                                $total = $space['total_spots'];
                                $available = $space['available_spots'];
                                $steps = [0, ceil($total/4), ceil($total/2), ceil(3*$total/4), $total];
                                $steps = array_unique($steps);
                                sort($steps);
                                foreach ($steps as $spot_count): 
                                    if ($spot_count == 0) continue;
                                ?>
                                    <span class="spot-badge <?php echo $available == $spot_count ? 'available' : ''; ?>" 
                                          onclick="setAvailability(<?php echo $space['id']; ?>, <?php echo $spot_count; ?>)"
                                          style="cursor: pointer;">
                                        <?php echo $spot_count; ?> spots
                                    </span>
                                <?php endforeach; ?>
                            </div>
                            
                            <?php if (!empty($display_amenities)): ?>
                            <div class="space-amenities">
                                <?php foreach ($display_amenities as $amenity): ?>
                                    <span class="amenity-tag"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($amenity); ?></span>
                                <?php endforeach; ?>
                                <?php if (count($amenities) > 3): ?>
                                    <span class="amenity-tag">+<?php echo count($amenities) - 3; ?> more</span>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                            
                            <div class="space-stats">
                                <div class="stat-item">
                                    <div class="stat-label"><i class="fas fa-warehouse"></i> Total Spots</div>
                                    <div class="stat-value" id="total-<?php echo $space['id']; ?>"><?php echo $space['total_spots']; ?></div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-label"><i class="fas fa-check"></i> Available</div>
                                    <div class="stat-value" id="available-<?php echo $space['id']; ?>"><?php echo $space['available_spots']; ?></div>
                                </div>
                                <div class="stat-item">
                                    <div class="stat-label"><i class="fas fa-car"></i> Occupied</div>
                                    <div class="stat-value" id="occupied-<?php echo $space['id']; ?>"><?php echo $space['total_spots'] - $space['available_spots']; ?></div>
                                </div>
                            </div>
                            
                            <div class="space-prices">
                                <?php if ($space['hourly_rate']): ?>
                                    <span><i class="far fa-clock"></i> ₦<?php echo number_format($space['hourly_rate'], 0); ?>/hr</span>
                                <?php endif; ?>
                                <?php if ($space['daily_rate']): ?>
                                    <span><i class="far fa-calendar-day"></i> ₦<?php echo number_format($space['daily_rate'], 0); ?>/day</span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="space-actions">
                                <a href="edit-parking.php?id=<?php echo $space['id']; ?>" class="btn-edit"><i class="fas fa-edit"></i> Edit</a>
                                <a href="parking-details.php?id=<?php echo $space['id']; ?>" class="btn-view"><i class="fas fa-eye"></i> View</a>
                                <button onclick="deleteSpace(<?php echo $space['id']; ?>)" class="btn-delete"><i class="fas fa-trash"></i> Delete</button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>
    
    <script>
    function deleteSpace(id) {
        if (confirm('Are you sure you want to delete this parking space? This action cannot be undone.')) {
            window.location.href = 'delete-parking.php?id=' + id;
        }
    }

    function toggleAvailability(spaceId, isChecked) {
        const checkbox = document.querySelector(`.availability-checkbox[data-space-id="${spaceId}"]`);
        const totalSpots = parseInt(checkbox.getAttribute('data-total'));
        const availableSpots = isChecked ? totalSpots : 0;
        updateAvailability(spaceId, availableSpots);
    }

    function setAvailability(spaceId, spots) {
        updateAvailability(spaceId, spots);
    }

    function updateAvailability(spaceId, availableSpots) {
        const checkbox = document.querySelector(`.availability-checkbox[data-space-id="${spaceId}"]`);
        if (checkbox) checkbox.disabled = true;
        
        fetch('api/toggle-availability.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                space_id: spaceId,
                available_spots: availableSpots
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const totalElement = document.getElementById(`total-${spaceId}`);
                if (!totalElement) return;
                
                const total = parseInt(totalElement.textContent);
                
                const availableElement = document.getElementById(`available-${spaceId}`);
                const occupiedElement = document.getElementById(`occupied-${spaceId}`);
                
                if (availableElement) availableElement.textContent = data.available_spots;
                if (occupiedElement) occupiedElement.textContent = total - data.available_spots;
                
                if (checkbox) {
                    checkbox.checked = data.available_spots > 0;
                    checkbox.disabled = false;
                }
                
                const statusBadge = document.querySelector(`.space-card[data-space-id="${spaceId}"] .space-status`);
                if (statusBadge) {
                    statusBadge.className = `space-status ${data.available_spots > 0 ? 'status-active' : 'status-full'}`;
                    statusBadge.innerHTML = data.available_spots > 0 ? 
                        `<i class="fas fa-check-circle"></i> ${data.available_spots}/${total} spots` : 
                        '<i class="fas fa-times-circle"></i> Full';
                }
                
                const toggleLabel = document.querySelector(`.space-card[data-space-id="${spaceId}"] .toggle-label`);
                if (toggleLabel) {
                    toggleLabel.className = `toggle-label ${data.available_spots > 0 ? 'available' : 'full'}`;
                    toggleLabel.innerHTML = data.available_spots > 0 ? 
                        `<i class="fas fa-check-circle"></i> ${data.available_spots}/${total} spots available` : 
                        `<i class="fas fa-exclamation-circle"></i> ${data.available_spots}/${total} spots available`;
                }
                
                document.querySelectorAll(`.space-card[data-space-id="${spaceId}"] .spot-badge`).forEach(badge => {
                    const badgeSpots = parseInt(badge.textContent);
                    if (badgeSpots === data.available_spots) {
                        badge.className = 'spot-badge available';
                    } else {
                        badge.className = 'spot-badge';
                    }
                });
            } else {
                alert('Error: ' + (data.message || 'Unknown error occurred'));
                if (checkbox) checkbox.checked = !checkbox.checked;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Failed to update availability. Please check your connection and try again.');
            if (checkbox) checkbox.checked = !checkbox.checked;
        })
        .finally(() => {
            if (checkbox) checkbox.disabled = false;
        });
    }
    </script>
    <?php include __DIR__ . '/chat/widget.php'; ?>
</body>
</html>