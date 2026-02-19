<?php
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

// Get real statistics from database
$stats_query = "SELECT 
    (SELECT COUNT(*) FROM parking_spaces WHERE is_active = 1) as total_locations,
    (SELECT COUNT(*) FROM users WHERE user_type = 'parker') as total_customers,
    (SELECT COUNT(*) FROM reservations WHERE status = 'completed') as total_bookings,
    (SELECT COALESCE(AVG(rating), 0) FROM reviews) as avg_rating";

$stats_stmt = $db->prepare($stats_query);
$stats_stmt->execute();
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// If no data yet, use defaults
$total_locations = $stats['total_locations'] ?: 500;
$total_customers = $stats['total_customers'] ?: 50000;
$total_bookings = $stats['total_bookings'] ?: 100000;
$avg_rating = number_format($stats['avg_rating'] ?: 4.8, 1);

// Get parking spaces from database
$search = isset($_GET['search']) ? $_GET['search'] : '';
$type = isset($_GET['type']) ? $_GET['type'] : '';
$available_only = isset($_GET['available']) ? true : false;

$query = "SELECT ps.*, 
          COALESCE(AVG(r.rating), 0) as avg_rating,
          COUNT(DISTINCT r.id) as review_count
          FROM parking_spaces ps
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

if ($available_only) {
    $query .= " AND ps.available_spots > 0";
}

$query .= " GROUP BY ps.id ORDER BY ps.created_at DESC LIMIT 6";

$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$parking_spaces = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle newsletter subscription
$newsletter_message = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['newsletter_email'])) {
    $email = filter_var($_POST['newsletter_email'], FILTER_SANITIZE_EMAIL);
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        // Store in database (you'd need a newsletter table)
        $newsletter_message = '<div class="alert-success">Thanks for subscribing!</div>';
    } else {
        $newsletter_message = '<div class="alert-error">Please enter a valid email.</div>';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>ParkEase – Smart Parking Solutions</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --blue:    #4F6EF7;
      --purple:  #7C3AED;
      --violet:  #9333EA;
      --green:   #22C55E;
      --red:     #EF4444;
      --yellow:  #F59E0B;
      --dark:    #0F172A;
      --darker:  #0B1120;
      --card-bg: #FFFFFF;
      --border:  #E5E7EB;
      --text:    #111827;
      --muted:   #6B7280;
      --light-bg:#F9FAFB;
    }

    body {
      font-family: 'Inter', sans-serif;
      color: var(--text);
      background: #fff;
    }

    /* ── NAVBAR ── */
    nav {
      position: sticky;
      top: 0;
      z-index: 100;
      background: #fff;
      border-bottom: 1px solid var(--border);
      padding: 0 48px;
      height: 64px;
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .nav-logo {
      display: flex;
      align-items: center;
      gap: 10px;
      text-decoration: none;
    }
    .nav-logo-icon {
      width: 36px; height: 36px;
      background: linear-gradient(135deg, var(--blue), var(--purple));
      border-radius: 10px;
      display: flex; align-items: center; justify-content: center;
    }
    .nav-logo-icon svg { width: 20px; height: 20px; fill: #fff; }
    .nav-logo-text h1 { font-size: 18px; font-weight: 700; color: var(--text); }
    .nav-logo-text p  { font-size: 10px; color: var(--muted); }

    .nav-links {
      display: flex; align-items: center; gap: 32px;
      list-style: none; position: relative;
    }
    .nav-links a {
      text-decoration: none; font-size: 14px; font-weight: 500;
      color: var(--text);
      transition: color .2s;
    }
    .nav-links a:hover, .nav-links a.active { color: var(--blue); }
    .nav-links .more { display: flex; align-items: center; gap: 4px; cursor: pointer; }
    .nav-links .more svg { width: 14px; height: 14px; }

    .dropdown-menu {
      display: none;
      position: absolute;
      top: calc(100% + 12px);
      left: 6;
      background: #fff;
      border-radius: 10px;
      box-shadow: 0 8px 24px rgba(0,0,0,0.12);
      min-width: 160px;
      padding: 8px 0;
      list-style: none;
      z-index: 999;
    }

    .dropdown-menu li a {
      display: block;
      padding: 10px 20px;
      font-size: 14px;
      color: #111827;
      text-decoration: none;
      transition: background 0.15s;
    }

    .dropdown-menu li a:hover {
      background: #F3F4F6;
    }

    li:hover .dropdown-menu {
      display: block;
    }

    .nav-right {
      display: flex; align-items: center; gap: 16px;
    }
    .nav-reservations {
      display: flex; align-items: center; gap: 6px;
      font-size: 13px; font-weight: 500; color: var(--text);
      text-decoration: none;
    }
    .nav-reservations svg { width: 16px; height: 16px; }
    .nav-phone {
      display: flex; align-items: center; gap: 6px;
      font-size: 13px; font-weight: 500; color: var(--text);
    }
    .nav-phone svg { width: 16px; height: 16px; }
    .btn-signin, .btn-dashboard {
      background: var(--blue);
      color: #fff; border: none; cursor: pointer;
      padding: 8px 20px; border-radius: 8px;
      font-size: 14px; font-weight: 600;
      transition: background .2s;
      text-decoration: none;
      display: inline-block;
    }
    .btn-signin:hover, .btn-dashboard:hover { background: #3a56d4; }
    
    .user-menu {
      display: flex;
      align-items: center;
      gap: 12px;
    }
    .user-avatar {
      width: 36px;
      height: 36px;
      background: linear-gradient(135deg, var(--blue), var(--purple));
      border-radius: 8px;
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
      font-weight: 600;
      font-size: 14px;
      text-decoration: none;
    }

    /* ── MODERN HERO CAROUSEL ── */
    .hero {
      position: relative;
      height: 700px;
      overflow: hidden;
    }

    .carousel-container {
      position: relative;
      width: 100%;
      height: 100%;
    }

    .carousel-slide {
      position: absolute;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      opacity: 0;
      visibility: hidden;
      transition: opacity 1.5s ease-in-out, visibility 1.5s ease-in-out;
    }

    .carousel-slide.active {
      opacity: 1;
      visibility: visible;
    }

    .carousel-slide img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      filter: brightness(0.7);
      transform: scale(1);
      transition: transform 8s ease-in-out;
    }

    .carousel-slide.active img {
      transform: scale(1.1);
    }

    /* Dark overlay for better text readability */
    .hero-overlay {
      position: absolute;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      /* background: linear-gradient(135deg, 
        rgba(144, 145, 235, 0.4) 0%, 
        rgba(177, 153, 235, 0.4) 40%, 
        rgba(198, 158, 235, 0.4) 70%, 
        rgba(206, 187, 240, 0.4) 100%); */
      z-index: 2;
    }

    /* Hero content */
    .hero-content {
      position: absolute;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
      text-align: center;
      z-index: 3;
      width: 100%;
      max-width: 900px;
      padding: 0 20px;
    }

    .hero-content h1 {
      font-size: 64px;
      font-weight: 800;
      color: #fff;
      line-height: 1.1;
      margin-bottom: 16px;
      text-shadow: 0 4px 30px rgba(0,0,0,0.3);
      animation: fadeUp 0.8s ease both;
    }

    .hero-content h1 span {
      display: block;
    }

    .hero-content p {
      font-size: 18px;
      color: rgba(255,255,255,.95);
      max-width: 600px;
      margin: 0 auto 36px;
      line-height: 1.6;
      text-shadow: 0 2px 20px rgba(0,0,0,0.3);
      animation: fadeUp 0.8s 0.1s ease both;
    }

    .hero-search {
      display: flex;
      background: rgba(255,255,255,0.95);
      backdrop-filter: blur(10px);
      border-radius: 16px;
      overflow: hidden;
      max-width: 580px;
      margin: 0 auto 48px;
      box-shadow: 0 20px 40px rgba(0,0,0,0.3);
      animation: fadeUp 0.8s 0.2s ease both;
    }

    .hero-search input {
      flex: 1;
      padding: 18px 24px;
      border: none;
      outline: none;
      font-size: 16px;
      color: var(--text);
      font-family: 'Inter', sans-serif;
      background: transparent;
    }

    .hero-search input::placeholder {
      color: var(--muted);
    }

    .hero-search button {
      background: var(--blue);
      color: #fff;
      border: none;
      cursor: pointer;
      padding: 18px 32px;
      font-size: 16px;
      font-weight: 600;
      font-family: 'Inter', sans-serif;
      transition: background .2s;
      white-space: nowrap;
    }

    .hero-search button:hover {
      background: #3a56d4;
    }

    .hero-stats {
      display: flex;
      justify-content: center;
      gap: 20px;
      flex-wrap: wrap;
      animation: fadeUp 0.8s 0.3s ease both;
    }

    .hero-stat {
      background: rgba(255,255,255,0.15);
      backdrop-filter: blur(10px);
      border: 1px solid rgba(255,255,255,0.3);
      border-radius: 16px;
      padding: 20px 32px;
      text-align: center;
      min-width: 140px;
      transition: transform 0.3s;
    }

    .hero-stat:hover {
      transform: translateY(-5px);
      background: rgba(255,255,255,0.2);
    }

    .hero-stat strong {
      display: block;
      font-size: 28px;
      font-weight: 800;
      color: #fff;
      margin-bottom: 4px;
    }

    .hero-stat span {
      font-size: 13px;
      color: rgba(255,255,255,.9);
      font-weight: 500;
      letter-spacing: 0.5px;
    }

    /* Carousel navigation */
    .carousel-nav {
      position: absolute;
      bottom: 40px;
      left: 50%;
      transform: translateX(-50%);
      display: flex;
      gap: 12px;
      z-index: 10;
    }

    .carousel-dot {
      width: 12px;
      height: 12px;
      border-radius: 50%;
      background: rgba(255,255,255,0.5);
      border: 2px solid transparent;
      cursor: pointer;
      transition: all 0.3s;
    }

    .carousel-dot.active {
      background: #fff;
      transform: scale(1.3);
      box-shadow: 0 0 20px rgba(255,255,255,0.5);
    }

    .carousel-dot:hover {
      background: rgba(255,255,255,0.8);
    }

    .carousel-arrow {
      position: absolute;
      top: 50%;
      transform: translateY(-50%);
      width: 50px;
      height: 50px;
      border-radius: 50%;
      background: rgba(255,255,255,0.2);
      backdrop-filter: blur(10px);
      border: 1px solid rgba(255,255,255,0.3);
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      z-index: 10;
      transition: all 0.3s;
      color: white;
    }

    .carousel-arrow:hover {
      background: rgba(255,255,255,0.3);
      transform: translateY(-50%) scale(1.1);
    }

    .carousel-arrow.left {
      left: 30px;
    }

    .carousel-arrow.right {
      right: 30px;
    }

    .carousel-arrow svg {
      width: 24px;
      height: 24px;
    }

    /* Progress bar */
    .carousel-progress {
      position: absolute;
      bottom: 0;
      left: 0;
      height: 4px;
      background: linear-gradient(90deg, var(--blue), var(--purple));
      z-index: 10;
      transition: width 5s linear;
    }

    /* ── FILTER BAR ── */
    .filter-bar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 20px 48px;
      background: #fff;
      border-bottom: 1px solid var(--border);
    }

    .filter-left {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .filter-icon {
      display: flex;
      align-items: center;
      gap: 6px;
      font-size: 14px;
      color: var(--muted);
      font-weight: 500;
    }

    .filter-icon svg {
      width: 16px;
      height: 16px;
    }

    .filter-select {
      border: 1px solid var(--border);
      border-radius: 8px;
      padding: 8px 14px;
      font-size: 14px;
      font-family: 'Inter', sans-serif;
      color: var(--text);
      background: #fff;
      cursor: pointer;
      outline: none;
    }

    .filter-checkbox {
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 14px;
      color: var(--text);
      cursor: pointer;
    }

    .filter-checkbox input {
      width: 16px;
      height: 16px;
      accent-color: var(--blue);
    }

    .filter-count {
      font-size: 14px;
      color: var(--muted);
    }

    /* ── CARDS GRID ── */
    .cards-section {
      padding: 32px 48px 48px;
      background: #fff;
    }

    .cards-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 24px;
    }

    .park-card {
      border: 1px solid var(--border);
      border-radius: 16px;
      overflow: hidden;
      background: #fff;
      transition: box-shadow .25s, transform .25s;
    }

    .park-card:hover {
      box-shadow: 0 8px 32px rgba(0,0,0,.1);
      transform: translateY(-2px);
    }

    .park-card-header {
      padding: 20px 20px 0;
    }

    .park-card-header h3 {
      font-size: 16px;
      font-weight: 700;
      margin-bottom: 4px;
    }

    .park-card-location {
      display: flex;
      align-items: center;
      gap: 4px;
      font-size: 13px;
      color: var(--muted);
    }

    .park-card-location svg {
      width: 13px;
      height: 13px;
    }

    .park-card-tags {
      display: flex;
      flex-wrap: wrap;
      gap: 6px;
      padding: 12px 20px 0;
    }

    .tag {
      background: #F3F4F6;
      color: #374151;
      border-radius: 6px;
      padding: 3px 10px;
      font-size: 11px;
      font-weight: 500;
    }

    .tag-more {
      color: var(--blue);
      background: #EEF2FF;
    }

    .park-card-capacity {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 12px 20px;
      font-size: 13px;
      color: var(--muted);
    }

    .park-card-capacity strong {
      font-size: 14px;
      color: var(--text);
      font-weight: 600;
    }

    .park-card-prices {
      display: flex;
      gap: 0;
      border-top: 1px solid var(--border);
      padding: 12px 20px;
    }

    .price-item {
      flex: 1;
      text-align: center;
    }

    .price-item label {
      display: block;
      font-size: 11px;
      color: var(--muted);
      margin-bottom: 2px;
    }

    .price-item .amount {
      font-size: 15px;
      font-weight: 700;
      color: var(--blue);
    }

    .price-item .amount::before {
      content: '₦';
      font-size: 11px;
      font-weight: 600;
    }

    .park-card-btn {
      margin: 0 20px 20px;
      display: block;
      width: calc(100% - 40px);
      background: var(--blue);
      color: #fff;
      border: none;
      cursor: pointer;
      padding: 12px;
      border-radius: 10px;
      font-size: 14px;
      font-weight: 600;
      font-family: 'Inter', sans-serif;
      text-align: center;
      transition: background .2s;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      text-decoration: none;
    }

    .park-card-btn:hover {
      background: #3a56d4;
    }

    .park-card-btn svg {
      width: 14px;
      height: 14px;
    }

    .park-card-img {
      position: relative;
      height: 180px;
      overflow: hidden;
      background: linear-gradient(135deg, #1a2035 0%, #263352 50%, #1a2850 100%);
    }

    .park-card-img img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      transition: transform 0.3s ease;
    }

    .park-card:hover .park-card-img img {
      transform: scale(1.05);
    }

    .park-card-img .badge-type,
    .park-card-img .badge-status,
    .park-card-img .park-card-rating {
      z-index: 2;
    }

    .park-card-img .badge-type {
      position: absolute;
      top: 12px;
      left: 12px;
      background: rgba(0,0,0,.65);
      color: #fff;
      border-radius: 6px;
      padding: 3px 10px;
      font-size: 11px;
      font-weight: 600;
      text-transform: capitalize;
    }

    .park-card-img .badge-status {
      position: absolute;
      top: 12px;
      right: 12px;
      border-radius: 6px;
      padding: 3px 10px;
      font-size: 11px;
      font-weight: 600;
      display: flex;
      align-items: center;
      gap: 4px;
    }

    .badge-status.available {
      background: #DCFCE7;
      color: #16A34A;
    }

    .badge-status.full {
      background: #FEE2E2;
      color: #DC2626;
    }

    .badge-status.available::before,
    .badge-status.full::before {
      content: '';
      width: 6px;
      height: 6px;
      border-radius: 50%;
    }

    .badge-status.available::before {
      background: #16A34A;
    }

    .badge-status.full::before {
      background: #DC2626;
    }

    .park-card-rating {
      position: absolute;
      bottom: 12px;
      left: 12px;
      background: rgba(0,0,0,.65);
      color: #fff;
      border-radius: 6px;
      padding: 3px 8px;
      font-size: 12px;
      font-weight: 600;
      display: flex;
      align-items: center;
      gap: 3px;
    }

    .star {
      color: #F59E0B;
      font-size: 11px;
    }

    /* ── FEATURES SECTION ── */
    .features-section {
      padding: 64px 48px;
      background: #fff;
    }

    .features-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 24px;
    }

    .feature-card {
      border: 1px solid var(--border);
      border-radius: 16px;
      padding: 32px 28px;
      background: #fff;
      transition: box-shadow .25s;
    }

    .feature-card:hover {
      box-shadow: 0 4px 20px rgba(0,0,0,.08);
    }

    .feature-icon {
      width: 56px;
      height: 56px;
      border-radius: 14px;
      display: flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 20px;
    }

    .feature-icon svg {
      width: 28px;
      height: 28px;
    }

    .feature-icon.blue {
      background: linear-gradient(135deg, #3B82F6, #6366F1);
    }

    .feature-icon.purple {
      background: linear-gradient(135deg, #8B5CF6, #A855F7);
    }

    .feature-icon.green {
      background: linear-gradient(135deg, #10B981, #22C55E);
    }

    .feature-card h3 {
      font-size: 18px;
      font-weight: 700;
      margin-bottom: 10px;
    }

    .feature-card p {
      font-size: 14px;
      color: var(--muted);
      line-height: 1.6;
    }

    /* ── BOTTOM DARK BAR ── */
    .dark-bar {
      background: var(--darker);
      padding: 20px 4px;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 60px;
    }

    .dark-bar-item {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .dark-bar-main {
      padding-left: 25px;
      margin-right: 80px;
    }

    .dark-bar-icon {
      width: 60px;
      height: 45px;
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .dark-bar-icon.blue {
      background: rgba(99,102,241,.25);
    }

    .dark-bar-icon.green {
      background: rgba(34,197,94,.25);
    }

    .dark-bar-icon.yellow {
      background: rgba(245,158,11,.25);
    }

    .dark-bar-icon svg {
      width: 20px;
      height: 20px;
    }

    .dark-bar-text h4 {
      font-size: 14px;
      font-weight: 600;
      color: #fff;
    }

    .dark-bar-text p {
      font-size: 12px;
      color: #9CA3AF;
    }

    /* ── FOOTER ── */
    footer {
      background: var(--darker);
      border-top: 1px solid rgba(255,255,255,.08);
      padding: 60px 48px 40px;
    }

    .footer-grid {
      display: grid;
      grid-template-columns: 2fr 1fr 1fr 1.5fr;
      gap: 48px;
      margin-bottom: 48px;
    }

    .footer-brand p {
      font-size: 13px;
      color: #9CA3AF;
      line-height: 1.7;
      margin: 12px 0 20px;
      max-width: 260px;
    }

    .footer-socials {
      display: flex;
      gap: 12px;
    }

    .footer-social {
      width: 32px;
      height: 32px;
      background: rgba(255,255,255,.08);
      border-radius: 8px;
      display: flex;
      align-items: center;
      justify-content: center;
      color: #9CA3AF;
      text-decoration: none;
      font-size: 13px;
      font-weight: 600;
      transition: background .2s;
    }

    .footer-social:hover {
      background: rgba(255,255,255,.15);
      color: #fff;
    }

    .footer-col h4 {
      font-size: 14px;
      font-weight: 600;
      color: #fff;
      margin-bottom: 16px;
    }

    .footer-col ul {
      list-style: none;
    }

    .footer-col ul li {
      margin-bottom: 10px;
    }

    .footer-col ul li a {
      text-decoration: none;
      color: #9CA3AF;
      font-size: 13px;
      transition: color .2s;
    }

    .footer-col ul li a:hover {
      color: #fff;
    }

    .footer-contact p {
      display: flex;
      align-items: flex-start;
      gap: 8px;
      font-size: 13px;
      color: #9CA3AF;
      margin-bottom: 10px;
      line-height: 1.5;
    }

    .footer-contact p svg {
      width: 15px;
      height: 15px;
      flex-shrink: 0;
      margin-top: 2px;
    }

    .footer-support {
      background: var(--blue);
      border-radius: 10px;
      padding: 12px 16px;
      margin-top: 16px;
    }

    .footer-support h5 {
      font-size: 13px;
      font-weight: 700;
      color: #fff;
    }

    .footer-support p {
      font-size: 11px;
      color: rgba(255,255,255,.8);
      margin: 0;
    }

    .footer-apps {
      display: flex;
      gap: 10px;
      margin-top: 16px;
    }

    .app-btn {
      border: 1px solid rgba(255,255,255,.2);
      border-radius: 8px;
      padding: 6px 12px;
      color: #fff;
      font-size: 11px;
      text-decoration: none;
      display: flex;
      flex-direction: column;
    }

    .app-btn span:first-child {
      font-size: 9px;
      color: rgba(255,255,255,.6);
    }

    .app-btn span:last-child {
      font-weight: 600;
    }

    .footer-newsletter {
      margin-top: 20px;
    }

    .footer-newsletter h5 {
      font-size: 14px;
      font-weight: 600;
      color: #fff;
      margin-bottom: 8px;
    }

    .footer-newsletter p {
      font-size: 12px;
      color: #9CA3AF;
      margin-bottom: 12px;
    }

    .newsletter-form {
      display: flex;
      gap: 8px;
    }

    .newsletter-form input {
      flex: 1;
      padding: 10px 14px;
      background: rgba(255,255,255,.08);
      border: 1px solid rgba(255,255,255,.15);
      border-radius: 8px;
      outline: none;
      color: #fff;
      font-size: 13px;
      font-family: 'Inter', sans-serif;
    }

    .newsletter-form input::placeholder {
      color: #6B7280;
    }

    .newsletter-form button {
      background: var(--blue);
      color: #fff;
      border: none;
      border-radius: 8px;
      padding: 10px 18px;
      font-size: 13px;
      font-weight: 600;
      cursor: pointer;
      font-family: 'Inter', sans-serif;
    }

    .footer-bottom {
      border-top: 1px solid rgba(255,255,255,.08);
      padding-top: 24px;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }

    .footer-bottom p {
      font-size: 12px;
      color: #6B7280;
    }

    .footer-logo-wrap {
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .footer-logo-wrap .nav-logo-icon {
      width: 28px;
      height: 28px;
      border-radius: 7px;
    }

    .footer-logo-wrap span {
      font-size: 15px;
      font-weight: 700;
      color: #fff;
    }

    .footer-p {
      color: #9CA3AF;
      font-size: 15px;
      padding-top: 20px;
      padding-bottom: 15px;
    }

    .alert-success {
      background: #DCFCE7;
      color: #16A34A;
      padding: 10px;
      border-radius: 8px;
      margin-bottom: 10px;
      font-size: 13px;
    }

    .alert-error {
      background: #FEE2E2;
      color: #DC2626;
      padding: 10px;
      border-radius: 8px;
      margin-bottom: 10px;
      font-size: 13px;
    }

    .no-results {
      grid-column: 1 / -1;
      text-align: center;
      padding: 60px;
      color: var(--muted);
    }

    @keyframes fadeUp {
      from {
        opacity: 0;
        transform: translateY(24px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    /* Responsive */
    @media (max-width: 1024px) {
      .cards-grid {
        grid-template-columns: repeat(2, 1fr);
      }
      
      .hero-content h1 {
        font-size: 48px;
      }
    }

    @media (max-width: 768px) {
      nav {
        padding: 0 20px;
      }
      
      .nav-links,
      .nav-phone {
        display: none;
      }
      
      .hero-content h1 {
        font-size: 36px;
      }
      
      .hero-content h1 span {
        display: inline;
      }
      
      .cards-grid {
        grid-template-columns: 1fr;
      }
      
      .filter-bar {
        flex-direction: column;
        gap: 10px;
        align-items: flex-start;
      }
      
      .footer-grid {
        grid-template-columns: 1fr;
        gap: 30px;
      }
      
      .carousel-arrow {
        display: none;
      }
    }
  </style>
</head>
<body>

<!-- NAVBAR -->
<nav>
  <a class="nav-logo" href="index.php">
    <div class="nav-logo-icon">
      <svg viewBox="0 0 24 24"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/></svg>
    </div>
    <div class="nav-logo-text">
      <h1>ParkEase</h1>
      <p>Smart Parking Solutions</p>
    </div>
  </a>

  <ul class="nav-links">
    <li><a href="index.php" class="active">Find Parking</a></li>
    <li><a href="#pricing">Pricing</a></li>
    <li><a href="#how-it-works">How It Works</a></li>
    <li>
      <a href="#" class="more">
        More
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
        <ul class="dropdown-menu">
            <li><a href="#about">About Us</a></li>
            <li><a href="#faq">FAQ</a></li>
            <li><a href="#help">Help Center</a></li>
            <li><a href="#contact">Contact</a></li>
        </ul>
      </a>
    </li>
  </ul>

  <div class="nav-right">
    <?php if (isset($_SESSION['user_id'])): ?>
      <a class="nav-reservations" href="dashboard.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        My Reservations
      </a>
      <div class="user-menu">
        <span class="nav-phone">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.61 3.4 2 2 0 0 1 3.6 1.22h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.84a16 16 0 0 0 6.29 6.29l.95-.95a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
          1-800-PARKEASE
        </span>
        <a href="dashboard.php" class="user-avatar">
          <?php 
            $initials = '';
            if (isset($_SESSION['user_name'])) {
              $name_parts = explode(' ', $_SESSION['user_name']);
              $initials = strtoupper(substr($name_parts[0], 0, 1) . (isset($name_parts[1]) ? substr($name_parts[1], 0, 1) : ''));
            }
            echo $initials ?: 'U';
          ?>
        </a>
      </div>
    <?php else: ?>
      <a class="nav-reservations" href="login.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        My Reservations
      </a>
      <div class="nav-phone">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.61 3.4 2 2 0 0 1 3.6 1.22h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.84a16 16 0 0 0 6.29 6.29l.95-.95a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
        1-800-PARKEASE
      </div>
      <button class="btn-signin" onclick="window.location.href='login.php'">Sign In</button>
    <?php endif; ?>
  </div>
</nav>

<!-- HERO SECTION WITH CAROUSEL -->
<section class="hero">
  <div class="carousel-container" id="heroCarousel">
    <!-- Carousel Slides - Add your 3 images here -->
    <div class="carousel-slide active">
      <img src="img/carosel (3).jpg" alt="Parking Space 1" onerror="this.src='https://images.unsplash.com/photo-1573342218828-3df50b1a3a5f?ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&auto=format&fit=crop&w=2070&q=80'">
    </div>
    <div class="carousel-slide">
      <img src="img/carosel (3).png" alt="Parking Space 2" onerror="this.src='https://images.unsplash.com/photo-1506521781263-d8422e82f27a?ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&auto=format&fit=crop&w=2070&q=80'">
    </div>
    <div class="carousel-slide">
      <img src="img/carosel (4).jpg" alt="Parking Space 3" onerror="this.src='https://images.unsplash.com/photo-1590674899484-d5640e854a45?ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D&auto=format&fit=crop&w=2070&q=80'">
    </div>
  </div>
  
  <!-- Dark overlay for better text visibility -->
  <div class="hero-overlay"></div>
  
  <!-- Progress bar -->
  <div class="carousel-progress" id="carouselProgress" style="width: 33.33%"></div>
  
  <!-- Navigation arrows -->
  <div class="carousel-arrow left" onclick="prevSlide()">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
      <polyline points="15 18 9 12 15 6"/>
    </svg>
  </div>
  <div class="carousel-arrow right" onclick="nextSlide()">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
      <polyline points="9 18 15 12 9 6"/>
    </svg>
  </div>
  
  <!-- Navigation dots -->
  <div class="carousel-nav" id="carouselNav">
    <div class="carousel-dot active" onclick="goToSlide(0)"></div>
    <div class="carousel-dot" onclick="goToSlide(1)"></div>
    <div class="carousel-dot" onclick="goToSlide(2)"></div>
  </div>

  <!-- Hero Content (Centered) -->
  <div class="hero-content">
    <h1>
      <span>Find &amp; Book Parking</span>
      <span>In Seconds</span>
    </h1>
    <p>Discover secure parking spaces near you. Save time and money with our smart parking platform.</p>

    <form class="hero-search" method="GET" action="index.php">
      <input type="text" name="search" placeholder="Where do you want to park?" value="<?php echo htmlspecialchars($search); ?>"/>
      <button type="submit">Search Parking</button>
    </form>

    <div class="hero-stats">
      <div class="hero-stat"><strong><?php echo number_format($total_locations); ?>+</strong><span>Parking Locations</span></div>
      <div class="hero-stat"><strong><?php echo number_format($total_customers); ?>+</strong><span>Happy Customers</span></div>
      <div class="hero-stat"><strong>24/7</strong><span>Customer Support</span></div>
      <div class="hero-stat"><strong><?php echo $avg_rating; ?>★</strong><span>Average Rating</span></div>
    </div>
  </div>
</section>

<!-- FILTER BAR -->
<div class="filter-bar">
  <form class="filter-left" method="GET" action="index.php" id="filter-form">
    <?php if (!empty($search)): ?>
      <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
    <?php endif; ?>
    
    <div class="filter-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
      Filter by:
    </div>
    <select name="type" class="filter-select" onchange="document.getElementById('filter-form').submit()">
      <option value="">All Parking Types</option>
      <option value="covered_garage" <?php echo $type == 'covered_garage' ? 'selected' : ''; ?>>Covered Garage</option>
      <option value="open_lot" <?php echo $type == 'open_lot' ? 'selected' : ''; ?>>Open Lot</option>
      <option value="underground" <?php echo $type == 'underground' ? 'selected' : ''; ?>>Underground</option>
      <option value="street_parking" <?php echo $type == 'street_parking' ? 'selected' : ''; ?>>Street Parking</option>
    </select>
    <label class="filter-checkbox">
      <input type="checkbox" name="available" value="1" <?php echo $available_only ? 'checked' : ''; ?> onchange="document.getElementById('filter-form').submit()"/> Available Only
    </label>
  </form>
  <span class="filter-count"><?php echo count($parking_spaces); ?> parking spaces found</span>
</div>

<!-- CARDS GRID -->
<section class="cards-section">
  <div class="cards-grid">
    <?php if (empty($parking_spaces)): ?>
      <div class="no-results">
        <svg width="80" height="80" viewBox="0 0 24 24" fill="none" stroke="#9CA3AF" stroke-width="1.5">
          <circle cx="12" cy="12" r="10"/>
          <line x1="12" y1="8" x2="12" y2="12"/>
          <line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
        <h3>No parking spaces found</h3>
        <p>Try adjusting your filters or search criteria</p>
      </div>
    <?php else: ?>
      <?php foreach ($parking_spaces as $space): 
        $amenities = json_decode($space['amenities'], true) ?: [];
        $display_amenities = array_slice($amenities, 0, 3);
        $remaining = count($amenities) - 3;
        $status = $space['available_spots'] > 0 ? 'available' : 'full';
        $status_text = $space['available_spots'] > 0 ? 'Available' : 'Full';
        $type_display = str_replace('_', ' ', $space['parking_type']);
        $type_display = ucwords($type_display);
      ?>
      <div class="park-card">
        <div class="park-card-img">
          <?php 
          $space_images = !empty($space['images']) ? json_decode($space['images'], true) : [];
          $image_url = !empty($space_images) ? 'uploads/parking/' . $space_images[0] : 'img/parking-placeholder.jpg';
          ?>
          <img src="<?php echo $image_url; ?>" 
               alt="<?php echo htmlspecialchars($space['name']); ?>"
               onerror="this.src='img/parking-placeholder.jpg'; this.onerror=null;">
          <span class="badge-type"><?php echo $type_display; ?></span>
          <span class="badge-status <?php echo $status; ?>"><?php echo $status_text; ?></span>
          <div class="park-card-rating">
            <span class="star">★</span> 
            <?php echo number_format($space['avg_rating'], 1); ?> 
            <span style="color:#9CA3AF;font-size:10px">(<?php echo $space['review_count']; ?>)</span>
          </div>
        </div>
        <div class="park-card-header">
          <h3><?php echo htmlspecialchars($space['name']); ?></h3>
          <div class="park-card-location">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
            <?php echo htmlspecialchars($space['city']); ?>
          </div>
        </div>
        <div class="park-card-tags">
          <?php foreach ($display_amenities as $amenity): ?>
            <span class="tag"><?php echo htmlspecialchars($amenity); ?></span>
          <?php endforeach; ?>
          <?php if ($remaining > 0): ?>
            <span class="tag tag-more">+<?php echo $remaining; ?> more</span>
          <?php endif; ?>
        </div>
        <div class="park-card-capacity">
          <span>Capacity</span>
          <strong><?php echo $space['available_spots']; ?>/<?php echo $space['total_spots']; ?> spots</strong>
        </div>
        <div class="park-card-prices">
          <?php if ($space['hourly_rate']): ?>
          <div class="price-item"><label>Hourly</label><div class="amount"><?php echo number_format($space['hourly_rate'], 0); ?></div></div>
          <?php endif; ?>
          <?php if ($space['daily_rate']): ?>
          <div class="price-item"><label>Daily</label><div class="amount"><?php echo number_format($space['daily_rate'], 0); ?></div></div>
          <?php endif; ?>
          <?php if ($space['monthly_rate']): ?>
          <div class="price-item"><label>Monthly</label><div class="amount"><?php echo number_format($space['monthly_rate'], 0); ?></div></div>
          <?php endif; ?>
        </div>
        <a href="parking-details.php?id=<?php echo $space['id']; ?>" class="park-card-btn">
          View Details
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
        </a>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</section>

<!-- FEATURES -->
<section class="features-section" id="how-it-works">
  <div class="features-grid">
    <div class="feature-card">
      <div class="feature-icon blue">
        <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
      </div>
      <h3>Secure &amp; Safe</h3>
      <p>All parking facilities are monitored 24/7 with advanced security systems for your peace of mind.</p>
    </div>
    <div class="feature-card">
      <div class="feature-icon purple">
        <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
      </div>
      <h3>Instant Booking</h3>
      <p>Reserve your parking spot in seconds with our fast and easy booking process.</p>
    </div>
    <div class="feature-card">
      <div class="feature-icon green">
        <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
      </div>
      <h3>Best Prices</h3>
      <p>Compare rates and find the most affordable parking options with our price guarantee.</p>
    </div>
  </div>
</section>

<!-- DARK BOTTOM BAR -->
<div class="dark-bar">
  <div class="dark-bar-main">
    <div class="dark-bar-item">
      <div class="dark-bar-icon blue">
        <svg viewBox="0 0 24 24" fill="none" stroke="#6366F1" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
      </div>
      <div class="dark-bar-text">
        <h4>Secure Parking</h4>
        <p>24/7 monitored facilities</p>
      </div>
    </div>
  </div>
  <div class="dark-bar-main">
    <div class="dark-bar-item">
      <div class="dark-bar-icon green">
        <svg viewBox="0 0 24 24" fill="none" stroke="#22C55E" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
      </div>
      <div class="dark-bar-text">
        <h4>Instant Booking</h4>
        <p>Reserve in seconds</p>
      </div>
    </div>
  </div>
  <div class="dark-bar-main">
    <div class="dark-bar-item">
      <div class="dark-bar-icon yellow">
        <svg viewBox="0 0 24 24" fill="none" stroke="#F59E0B" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
      </div>
      <div class="dark-bar-text">
        <h4>Best Rates</h4>
        <p>Guaranteed lowest prices</p>
      </div>
    </div>
  </div>
</div>

<!-- FOOTER -->
<footer>
  <div class="footer-grid">
    <div>
      <div class="footer-logo-wrap">
        <div class="nav-logo-icon">
          <svg viewBox="0 0 24 24" style="width:14px;height:14px;fill:#fff"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/></svg>
        </div>
        <span>ParkEase</span>
      </div>
      <p class="footer-p">Your trusted partner for finding and booking parking spaces. Safe, convenient, and affordable parking solutions for everyone.</p>
      <div class="footer-socials">
        <a class="footer-social" href="#">f</a>
        <a class="footer-social" href="#">𝕏</a>
        <a class="footer-social" href="#">in</a>
        <a class="footer-social" href="#">▶</a>
      </div>
      <div class="footer-apps">
        <a class="app-btn" href="#"><span>Download on the</span><span>App Store</span></a>
        <a class="app-btn" href="#"><span>Get it on</span><span>Google Play</span></a>
      </div>
    </div>

    <!-- Quick Links -->
    <div class="footer-col">
      <h4>Quick Links</h4>
      <ul>
        <li><a href="index.php">Find Parking</a></li>
        <li><a href="dashboard.php">My Reservations</a></li>
        <li><a href="#pricing">Pricing</a></li>
        <li><a href="#how-it-works">How It Works</a></li>
        <li><a href="#about">About Us</a></li>
      </ul>
    </div>

    <!-- Support -->
    <div class="footer-col">
      <h4>Support</h4>
      <ul>
        <li><a href="#help">Help Center</a></li>
        <li><a href="#faq">FAQ</a></li>
        <li><a href="#contact">Contact Us</a></li>
        <li><a href="#safety">Safety Guidelines</a></li>
        <li><a href="#accessibility">Accessibility</a></li>
      </ul>
    </div>

    <!-- Contact -->
    <div class="footer-col footer-contact">
      <h4>Contact Us</h4>
      <p>
        <svg viewBox="0 0 24 24" fill="none" stroke="#9CA3AF" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
        123 Parking Avenue<br>New York, NY 10001
      </p>
      <p>
        <svg viewBox="0 0 24 24" fill="none" stroke="#9CA3AF" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.61 3.4 2 2 0 0 1 3.6 1.22h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.84a16 16 0 0 0 6.29 6.29l.95-.95a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
        1-800-PARKEASE
      </p>
      <p>
        <svg viewBox="0 0 24 24" fill="none" stroke="#9CA3AF" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
        support@parkease.com
      </p>
      <div class="footer-support">
        <h5>24/7 Customer Support</h5>
        <p>We're always here to help.</p>
      </div>

      <div class="footer-newsletter">
        <h5>Subscribe to Our Newsletter</h5>
        <p>Get exclusive deals and parking tips delivered to your inbox.</p>
        <?php echo $newsletter_message; ?>
        <form method="POST" action="index.php#newsletter" class="newsletter-form">
          <input type="email" name="newsletter_email" placeholder="Enter your email address" required/>
          <button type="submit">Subscribe</button>
        </form>
      </div>
    </div>
  </div>

  <div class="footer-bottom">
    <p>© <?php echo date('Y'); ?> ParkEase. All rights reserved.</p>
    <p>Privacy Policy · Terms of Service · Cookie Policy</p>
  </div>
</footer>

<!-- Carousel JavaScript -->
<script>
  let currentSlide = 0;
  const slides = document.querySelectorAll('.carousel-slide');
  const dots = document.querySelectorAll('.carousel-dot');
  const progressBar = document.getElementById('carouselProgress');
  const totalSlides = slides.length;
  let autoSlideInterval;

  function showSlide(index) {
    // Remove active class from all slides and dots
    slides.forEach(slide => slide.classList.remove('active'));
    dots.forEach(dot => dot.classList.remove('active'));
    
    // Add active class to current slide and dot
    slides[index].classList.add('active');
    dots[index].classList.add('active');
    
    // Update progress bar
    const progress = ((index + 1) / totalSlides) * 100;
    progressBar.style.width = progress + '%';
    
    currentSlide = index;
  }

  function nextSlide() {
    currentSlide = (currentSlide + 1) % totalSlides;
    showSlide(currentSlide);
    resetAutoSlide();
  }

  function prevSlide() {
    currentSlide = (currentSlide - 1 + totalSlides) % totalSlides;
    showSlide(currentSlide);
    resetAutoSlide();
  }

  function goToSlide(index) {
    showSlide(index);
    resetAutoSlide();
  }

  function startAutoSlide() {
    autoSlideInterval = setInterval(nextSlide, 5000); // Change slide every 5 seconds
  }

  function resetAutoSlide() {
    clearInterval(autoSlideInterval);
    startAutoSlide();
  }

  // Pause auto-slide on hover
  const carousel = document.querySelector('.carousel-container');
  carousel.addEventListener('mouseenter', () => {
    clearInterval(autoSlideInterval);
  });

  carousel.addEventListener('mouseleave', () => {
    startAutoSlide();
  });

  // Touch events for mobile
  let touchStartX = 0;
  let touchEndX = 0;

  carousel.addEventListener('touchstart', (e) => {
    touchStartX = e.changedTouches[0].screenX;
  });

  carousel.addEventListener('touchend', (e) => {
    touchEndX = e.changedTouches[0].screenX;
    handleSwipe();
  });

  function handleSwipe() {
    const swipeThreshold = 50;
    if (touchEndX < touchStartX - swipeThreshold) {
      nextSlide(); // Swipe left
    }
    if (touchEndX > touchStartX + swipeThreshold) {
      prevSlide(); // Swipe right
    }
  }

  // Keyboard navigation
  document.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowLeft') {
      prevSlide();
    } else if (e.key === 'ArrowRight') {
      nextSlide();
    }
  });

  // Start auto-slide
  startAutoSlide();

  // Initialize first slide
  showSlide(0);
</script>

</body>
</html>