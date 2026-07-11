<?php
session_start();
require_once 'includes/user-access.php';
redirectOwnersFromPublicPages();
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

function sanitize($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

$stats_query = "SELECT 
    (SELECT COUNT(*) FROM parking_spaces WHERE is_active = 1) as total_locations,
    (SELECT COUNT(*) FROM users WHERE user_type = 'parker') as total_customers,
    (SELECT COUNT(*) FROM reservations WHERE status = 'completed') as total_bookings,
    (SELECT COALESCE(AVG(rating), 0) FROM reviews) as avg_rating";

$stats_stmt = $db->prepare($stats_query);
$stats_stmt->execute();
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

$total_locations = isset($stats['total_locations']) && $stats['total_locations'] > 0 ? $stats['total_locations'] : 500;
$total_customers = isset($stats['total_customers']) && $stats['total_customers'] > 0 ? $stats['total_customers'] : 50000;
$total_bookings = isset($stats['total_bookings']) && $stats['total_bookings'] > 0 ? $stats['total_bookings'] : 100000;
$avg_rating = number_format($stats['avg_rating'] ?? 4.8, 1);

$search = isset($_GET['search']) ? sanitize($_GET['search']) : '';
$type = isset($_GET['type']) ? sanitize($_GET['type']) : '';
$available_only = isset($_GET['available']) ? true : false;

$count_query = "SELECT COUNT(DISTINCT ps.id) as total FROM parking_spaces ps WHERE ps.is_active = 1";
$count_params = [];
if (!empty($search)) {
    $count_query .= " AND (ps.name LIKE :search OR ps.city LIKE :search OR ps.address LIKE :search)";
    $count_params[':search'] = "%$search%";
}
if (!empty($type)) { $count_query .= " AND ps.parking_type = :type"; $count_params[':type'] = $type; }
if ($available_only) { $count_query .= " AND ps.available_spots > 0"; }
$count_stmt = $db->prepare($count_query);
foreach ($count_params as $key => $value) { $count_stmt->bindValue($key, $value); }
$count_stmt->execute();
$count_result = $count_stmt->fetch(PDO::FETCH_ASSOC);
$total_count = $count_result['total'] ?? 0;

$query = "SELECT ps.*, COALESCE(AVG(r.rating), 0) as avg_rating, COUNT(DISTINCT r.id) as review_count
          FROM parking_spaces ps LEFT JOIN reviews r ON ps.id = r.parking_id WHERE ps.is_active = 1";
$params = [];
if (!empty($search)) {
    $query .= " AND (ps.name LIKE :search OR ps.city LIKE :search OR ps.address LIKE :search)";
    $params[':search'] = "%$search%";
}
if (!empty($type)) { $query .= " AND ps.parking_type = :type"; $params[':type'] = $type; }
if ($available_only) { $query .= " AND ps.available_spots > 0"; }
$query .= " GROUP BY ps.id ORDER BY ps.created_at DESC LIMIT 6";
$stmt = $db->prepare($query);
foreach ($params as $key => $value) { $stmt->bindValue($key, $value); }
$stmt->execute();
$parking_spaces = $stmt->fetchAll(PDO::FETCH_ASSOC);

$newsletter_message = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['newsletter_email'])) {
    $email = filter_var($_POST['newsletter_email'], FILTER_SANITIZE_EMAIL);
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        try {
            $table_check = $db->query("SHOW TABLES LIKE 'newsletter_subscribers'");
            if ($table_check->rowCount() == 0) {
                $db->exec("CREATE TABLE IF NOT EXISTS newsletter_subscribers (id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(255) NOT NULL UNIQUE, first_name VARCHAR(100), last_name VARCHAR(100), subscribed_at DATETIME DEFAULT CURRENT_TIMESTAMP, status ENUM('active','unsubscribed') DEFAULT 'active', source VARCHAR(50) DEFAULT 'website', user_id INT NULL, ip_address VARCHAR(45), unsubscribe_token VARCHAR(64))");
            }
            $check_stmt = $db->prepare("SELECT id, status FROM newsletter_subscribers WHERE email = :email");
            $check_stmt->bindParam(':email', $email);
            $check_stmt->execute();
            $existing = $check_stmt->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                if ($existing['status'] == 'unsubscribed') {
                    $upd = $db->prepare("UPDATE newsletter_subscribers SET status='active', subscribed_at=NOW() WHERE id=:id");
                    $upd->bindParam(':id', $existing['id']); $upd->execute();
                    $newsletter_message = '<div class="alert-success">Welcome back! You\'re resubscribed.</div>';
                } else { $newsletter_message = '<div class="alert-info">You\'re already subscribed!</div>'; }
            } else {
                $token = bin2hex(random_bytes(32));
                $fn = $ln = null; $uid = null;
                if (isset($_SESSION['user_id'])) {
                    $uid = (int)$_SESSION['user_id'];
                    if (isset($_SESSION['user_name'])) { $np = explode(' ', $_SESSION['user_name'], 2); $fn = substr($np[0],0,100); $ln = isset($np[1]) ? substr($np[1],0,100) : null; }
                }
                $ins = $db->prepare("INSERT INTO newsletter_subscribers (email,first_name,last_name,user_id,ip_address,unsubscribe_token,source) VALUES (:email,:fn,:ln,:uid,:ip,:token,'website')");
                $ins->bindParam(':email',$email); $ins->bindParam(':fn',$fn); $ins->bindParam(':ln',$ln); $ins->bindParam(':uid',$uid); $ins->bindParam(':ip',$ip); $ins->bindParam(':token',$token);
                $ins->execute();
                $newsletter_message = '<div class="alert-success">Thanks for subscribing!</div>';
            }
        } catch (PDOException $e) {
            error_log('Newsletter error: '.$e->getMessage());
            $newsletter_message = '<div class="alert-error">Something went wrong. Try again later.</div>';
        }
    } else { $newsletter_message = '<div class="alert-error">Please enter a valid email address.</div>'; }
}

function getImageUrl($space_images, $default = 'img/parking-placeholder.jpg') {
    if (!empty($space_images)) {
        $images = json_decode($space_images, true);
        if (!empty($images) && isset($images[0])) {
            $image_path = 'uploads/parking/' . $images[0];
            return file_exists($image_path) ? $image_path : $default;
        }
    }
    return $default;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes"/>
  <title> SpaceNode – Smart Parking Solutions</title>
  
  <!-- Include all CSS assets (navbar, footer, global styles) -->
  <?php require_once 'includes/header-assets.php'; ?>
  
  <style>
    /* ============================================
       PAGE-SPECIFIC STYLES (Hero, Cards, Features)
       These are separate from navbar/footer styles
    ============================================ */
    .hero {
      position: relative;
      height: 820px;
      overflow: hidden;
    }

    .carousel-container { position: relative; width: 100%; height: 100%; }

    .carousel-slide {
      position: absolute; top: 0; left: 0; width: 100%; height: 100%;
      opacity: 0; visibility: hidden;
      transition: opacity 1.5s ease-in-out, visibility 1.5s ease-in-out;
    }
    .carousel-slide.active { opacity: 1; visibility: visible; }
    .carousel-slide img {
      width: 100%; height: 100%; object-fit: cover;
      filter: brightness(0.62);
      transform: scale(1);
      transition: transform 8s ease-in-out;
    }
    .carousel-slide.active img { transform: scale(1.08); }

    .hero-overlay { position: absolute; inset: 0; z-index: 2; }
    .hero-overlay-bottom {
      position: absolute; bottom: 0; left: 0; right: 0; z-index: 2;
      height: 200px;
      background: linear-gradient(to top, rgba(255,255,255,1) 0%, transparent 100%);
    }

    .hero-content {
      position: absolute;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
      text-align: center;
      z-index: 3;
      width: 100%;
      max-width: 860px;
      padding: 0 24px;
    }

    .hero-badge {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      background: rgba(255,255,255,0.15);
      backdrop-filter: blur(12px);
      border: 1px solid rgba(255,255,255,0.3);
      border-radius: 50px;
      padding: 6px 16px;
      font-size: 13px;
      color: rgba(255,255,255,0.95);
      font-weight: 500;
      margin-bottom: 22px;
      animation: fadeUp 0.7s ease both;
    }
    .hero-badge-dot {
      width: 7px; height: 7px;
      background: #4ade80;
      border-radius: 50%;
      box-shadow: 0 0 8px #4ade80;
      animation: pulse 2s infinite;
    }
    @keyframes pulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:0.6;transform:scale(1.3)} }

    .hero-content h1 {
      font-family: var(--font-display);
      font-size: clamp(40px, 7.5vw, 70px);
      font-weight: 900;
      color: #fff;
      line-height: 1.05;
      margin-bottom: 18px;
      letter-spacing: -1.5px;
      text-shadow: 0 4px 30px rgba(0,0,0,0.25);
      animation: fadeUp 0.7s 0.1s ease both;
    }
    .hero-content h1 span.grad {
      background: linear-gradient(90deg, #93c5fd, #c4b5fd);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }

    .hero-content p {
      font-size: clamp(15px, 2.5vw, 17px);
      color: rgba(255,255,255,0.88);
      max-width: 520px;
      margin: 0 auto 32px;
      line-height: 1.65;
      animation: fadeUp 0.7s 0.2s ease both;
    }

    .hero-search-wrap {
      animation: fadeUp 0.7s 0.3s ease both;
      margin-bottom: 36px;
    }
    .hero-search {
      display: flex;
      background: rgba(255,255,255,0.18);
      backdrop-filter: blur(20px) saturate(160%);
      -webkit-backdrop-filter: blur(20px);
      border-radius: 80px;
      overflow: hidden;
      max-width: 560px;
      margin: 0 auto;
      box-shadow: 0 20px 60px rgba(0,0,0,0.25), inset 0 1px 0 rgba(255,255,255,0.4);
      border: 1px solid rgba(255,255,255,0.35);
    }
    .hero-search input {
      flex: 1;
      padding: 18px 24px;
      border: none; outline: none;
      font-size: 15px;
      color: var(--text);
      font-family: var(--font-body);
      background: rgba(255,255,255,0.9);
    }
    .hero-search input::placeholder { color: var(--muted); }
    .hero-search button {
      background: linear-gradient(135deg, var(--blue), var(--purple));
      color: #fff; border: none; cursor: pointer;
      padding: 18px 32px;
      font-size: 15px; font-weight: 600;
      font-family: var(--font-display);
      transition: all 0.3s;
      white-space: nowrap;
    }
    .hero-search button:hover { opacity: 0.92; }

    .hero-stats {
      display: flex;
      justify-content: center;
      gap: 14px;
      flex-wrap: wrap;
      animation: fadeUp 0.7s 0.4s ease both;
    }
    .hero-stat {
      background: rgba(255,255,255,0.13);
      backdrop-filter: blur(12px);
      border: 1px solid rgba(255,255,255,0.28);
      border-radius: 50px;
      padding: 12px 24px;
      text-align: center;
      min-width: 120px;
      transition: all 0.3s;
    }
    .hero-stat:hover {
      transform: translateY(-4px);
      background: rgba(255,255,255,0.22);
      border-color: rgba(255,255,255,0.45);
      box-shadow: 0 12px 30px rgba(0,0,0,0.15);
    }
    .hero-stat strong {
      display: block; font-family: var(--font-display);
      font-size: 26px; font-weight: 800; color: #fff; margin-bottom: 2px;
    }
    .hero-stat span { font-size: 12px; color: rgba(255,255,255,0.82); font-weight: 500; }

    .carousel-nav {
      position: absolute; bottom: 120px; left: 50%; transform: translateX(-50%);
      display: flex; gap: 10px; z-index: 10;
    }
    .carousel-dot {
      width: 8px; height: 8px; border-radius: 50%;
      background: rgba(255,255,255,0.4); cursor: pointer; transition: all 0.3s;
    }
    .carousel-dot.active { background: #fff; width: 24px; border-radius: 4px; }
    .carousel-arrow {
      position: absolute; top: 50%; transform: translateY(-50%);
      width: 46px; height: 46px; border-radius: 50%;
      background: rgba(255,255,255,0.15);
      backdrop-filter: blur(12px);
      border: 1px solid rgba(255,255,255,0.3);
      display: flex; align-items: center; justify-content: center;
      cursor: pointer; z-index: 10; transition: all 0.3s; color: white;
    }
    .carousel-arrow:hover { background: rgba(255,255,255,0.3); transform: translateY(-50%) scale(1.08); }
    .carousel-arrow.left { left: 24px; }
    .carousel-arrow.right { right: 24px; }
    .carousel-arrow svg { width: 22px; height: 22px; }
    .carousel-progress {
      position: absolute; bottom: 0; left: 0; height: 3px;
      background: linear-gradient(90deg, var(--blue), var(--purple)); z-index: 10;
    }

    /* Filter Bar */
    .filter-bar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 16px 48px;
      background: rgba(255,255,255,0.85);
      backdrop-filter: blur(16px) saturate(180%);
      -webkit-backdrop-filter: blur(16px);
      border-bottom: 1px solid rgba(229,231,235,0.5);
      flex-wrap: wrap;
      gap: 12px;
      position: relative;
      top: 0;
      z-index: 90;
      box-shadow: 0 4px 24px rgba(0,0,0,0.06);
    }
    .filter-left {
      display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
    }
    .filter-icon {
      display: flex; align-items: center; gap: 7px;
      font-size: 13px; color: var(--muted); font-weight: 600;
      text-transform: uppercase; letter-spacing: 0.5px;
    }
    .filter-icon svg { width: 16px; height: 16px; }
    .filter-select {
      border: 1px solid rgba(229,231,235,0.7);
      border-radius: 50px; padding: 9px 18px;
      font-size: 13px; font-family: var(--font-body);
      color: var(--text); font-weight: 500;
      background: rgba(255,255,255,0.9);
      cursor: pointer; outline: none; min-width: 170px;
      transition: all 0.2s;
    }
    .filter-select:focus { border-color: var(--blue); box-shadow: 0 0 0 3px rgba(79,110,247,0.1); }
    .filter-checkbox {
      display: flex; align-items: center; gap: 7px;
      font-size: 13px; font-weight: 500; color: var(--text);
      cursor: pointer; padding: 8px 14px;
      background: rgba(255,255,255,0.9);
      border: 1px solid rgba(229,231,235,0.7);
      border-radius: 50px; transition: all 0.2s;
    }
    .filter-checkbox:hover { border-color: var(--blue); background: rgba(79,110,247,0.04); }
    .filter-checkbox input { width: 16px; height: 16px; accent-color: var(--blue); }
    .filter-count {
      font-size: 13px; color: var(--muted); font-weight: 600;
      background: rgba(243,244,246,0.9);
      padding: 8px 16px; border-radius: 50px;
    }

    /* Section Heading */
    .section-heading {
      text-align: center;
      margin-bottom: 48px;
    }
    .section-heading .eyebrow {
      display: inline-block;
      font-size: 12px; font-weight: 700; letter-spacing: 1.5px;
      text-transform: uppercase; color: var(--blue);
      background: rgba(79,110,247,0.1);
      padding: 5px 14px; border-radius: 50px;
      margin-bottom: 14px;
    }
    .section-heading h2 {
      font-family: var(--font-display);
      font-size: clamp(28px, 4vw, 40px);
      font-weight: 800; color: var(--text);
      letter-spacing: -0.8px; margin-bottom: 12px;
    }
    .section-heading p {
      font-size: 16px; color: var(--muted); max-width: 480px; margin: 0 auto; line-height: 1.6;
    }

    /* Cards Grid */
    .cards-section {
      padding: 72px 48px;
      background: radial-gradient(ellipse at 20% 30%, rgba(147,197,253,0.22) 0%, transparent 55%),
                  radial-gradient(ellipse at 80% 70%, rgba(196,181,253,0.2) 0%, transparent 55%),
                  radial-gradient(ellipse at 50% 50%, rgba(255,255,255,0.9) 0%, #F1F5FF 100%);
    }

    .cards-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 28px;
      max-width: 1200px;
      margin: 0 auto;
    }

    .park-card {
      border: 1px solid rgba(255,255,255,0.75);
      border-radius: 28px;
      overflow: hidden;
      background: rgba(255,255,255,0.55);
      backdrop-filter: blur(24px) saturate(200%) brightness(1.05);
      -webkit-backdrop-filter: blur(24px) saturate(200%) brightness(1.05);
      transition: all 0.4s cubic-bezier(0.4,0,0.2,1);
      box-shadow: 0 4px 24px rgba(0,0,0,0.07), 0 1px 0 rgba(255,255,255,0.9) inset, 0 -1px 0 rgba(255,255,255,0.3) inset, 1px 0 0 rgba(255,255,255,0.5) inset;
      opacity: 0;
      transform: translateY(28px);
      position: relative;
    }
    .park-card::after {
      content: '';
      position: absolute;
      top: 0; left: 0; right: 0;
      height: 1px;
      background: linear-gradient(90deg, transparent 0%, rgba(255,255,255,0.9) 30%, rgba(255,255,255,1) 50%, rgba(255,255,255,0.9) 70%, transparent 100%);
      border-radius: 28px 28px 0 0;
      pointer-events: none;
    }
    .park-card.animate-in { opacity: 1; transform: translateY(0); }
    .park-card:hover {
      transform: translateY(-10px);
      background: rgba(255,255,255,0.78);
      backdrop-filter: blur(32px) saturate(220%) brightness(1.08);
      box-shadow: 0 28px 56px rgba(79,110,247,0.2), 0 8px 20px rgba(0,0,0,0.06), 0 1px 0 rgba(255,255,255,1) inset, 1px 0 0 rgba(255,255,255,0.7) inset;
      border-color: rgba(255,255,255,0.95);
    }

    .park-card-img {
      position: relative; height: 210px; overflow: hidden;
      background: linear-gradient(135deg, #1a2035 0%, #263352 100%);
    }
    .park-card-img img {
      width: 100%; height: 100%; object-fit: cover;
      transition: transform 0.6s cubic-bezier(0.4,0,0.2,1);
    }
    .park-card:hover .park-card-img img { transform: scale(1.08); }

    .badge-type {
      position: absolute; top: 14px; left: 14px;
      background: rgba(0,0,0,0.55);
      backdrop-filter: blur(8px);
      color: #fff; border-radius: 50px;
      padding: 5px 14px; font-size: 11px; font-weight: 700;
      text-transform: uppercase; letter-spacing: 0.5px;
      border: 1px solid rgba(255,255,255,0.2);
    }
    .badge-status {
      position: absolute; top: 14px; right: 14px;
      border-radius: 50px; padding: 5px 14px;
      font-size: 12px; font-weight: 600;
      display: flex; align-items: center; gap: 6px;
      backdrop-filter: blur(8px);
    }
    .badge-status.available { background: rgba(220,252,231,0.92); color: #16A34A; }
    .badge-status.full { background: rgba(254,226,226,0.92); color: #DC2626; }
    .badge-status::before {
      content: ''; width: 7px; height: 7px; border-radius: 50%;
    }
    .badge-status.available::before { background: #16A34A; box-shadow: 0 0 6px #16A34A; }
    .badge-status.full::before { background: #DC2626; }

    .park-card-rating {
      position: absolute; bottom: 14px; left: 14px;
      background: rgba(0,0,0,0.6);
      backdrop-filter: blur(8px);
      color: #fff; border-radius: 50px;
      padding: 5px 12px; font-size: 13px; font-weight: 600;
      display: flex; align-items: center; gap: 4px;
      border: 1px solid rgba(255,255,255,0.2);
    }
    .star { color: #FBBF24; }

    .park-card-header {
      padding: 20px 20px 0;
    }
    .park-card-header h3 {
      font-family: var(--font-display);
      font-size: 17px; font-weight: 700; margin-bottom: 5px; letter-spacing: -0.2px;
    }
    .park-card-location {
      display: flex; align-items: center; gap: 4px;
      font-size: 13px; color: var(--muted);
    }
    .park-card-location svg { width: 13px; height: 13px; }

    .park-card-tags {
      display: flex; flex-wrap: wrap; gap: 7px;
      padding: 14px 20px 0;
    }
    .tag {
      background: rgba(248,250,252,0.9);
      backdrop-filter: blur(4px);
      border: 1px solid rgba(229,231,235,0.6);
      color: #475569; border-radius: 50px;
      padding: 4px 11px; font-size: 12px; font-weight: 500;
      transition: all 0.2s;
    }
    .tag:hover { background: rgba(79,110,247,0.08); color: var(--blue); border-color: rgba(79,110,247,0.2); }
    .tag-more { color: var(--blue); background: rgba(238,242,255,0.9); border-color: rgba(79,110,247,0.2); }

    .park-card-capacity {
      display: flex; justify-content: space-between; align-items: center;
      padding: 14px 20px; font-size: 13px; color: var(--muted);
    }
    .park-card-capacity strong { font-size: 15px; color: var(--text); font-weight: 700; }

    .park-card-prices {
      display: flex; gap: 0;
      border-top: 1px solid rgba(255,255,255,0.6);
      border-bottom: 1px solid rgba(255,255,255,0.6);
      padding: 14px 20px;
      background: rgba(255,255,255,0.35);
      backdrop-filter: blur(12px);
    }
    .price-item { flex: 1; text-align: center; }
    .price-item label { display: block; font-size: 11px; color: var(--muted); margin-bottom: 3px; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; }
    .price-item .amount { font-family: var(--font-display); font-size: 19px; font-weight: 800; color: var(--blue); }
    .price-item .amount::before { content: '₦'; font-size: 13px; font-weight: 600; opacity: 0.7; }

    .park-card-btn {
      margin: 16px 20px 20px;
      display: flex;
      width: calc(100% - 40px);
      background: linear-gradient(135deg, var(--blue), var(--purple));
      color: #fff; border: none; cursor: pointer;
      padding: 14px 20px;
      border-radius: 50px;
      font-family: var(--font-body);
      font-size: 14px; font-weight: 600;
      text-align: center;
      transition: all 0.3s;
      align-items: center; justify-content: center; gap: 8px;
      text-decoration: none;
      box-shadow: 0 4px 18px rgba(79,110,247,0.32);
      position: relative; overflow: hidden;
    }
    .park-card-btn::before {
      content: '';
      position: absolute; inset: 0;
      background: linear-gradient(135deg, rgba(255,255,255,0) 0%, rgba(255,255,255,0.15) 100%);
      opacity: 0; transition: opacity 0.3s;
    }
    .park-card-btn:hover::before { opacity: 1; }
    .park-card-btn:hover { transform: translateY(-2px); box-shadow: 0 10px 28px rgba(79,110,247,0.42); }
    .park-card-btn svg { width: 15px; height: 15px; }

    .park-card:nth-child(1){transition-delay:.05s}
    .park-card:nth-child(2){transition-delay:.1s}
    .park-card:nth-child(3){transition-delay:.15s}
    .park-card:nth-child(4){transition-delay:.2s}
    .park-card:nth-child(5){transition-delay:.25s}
    .park-card:nth-child(6){transition-delay:.3s}

    .no-results {
      grid-column: 1/-1; text-align: center; padding: 80px 20px; color: var(--muted);
    }
    .no-results h3 { font-family: var(--font-display); font-size: 22px; margin-top: 16px; margin-bottom: 8px; color: var(--text); }

    .view-all-wrap {
      text-align: center; margin-top: 48px;
    }
    .btn-view-all {
      display: inline-flex; align-items: center; gap: 10px;
      background: rgba(255,255,255,0.9);
      backdrop-filter: blur(12px);
      border: 2px solid rgba(79,110,247,0.3);
      color: var(--blue); border-radius: 50px;
      padding: 15px 40px; font-family: var(--font-display);
      font-size: 15px; font-weight: 700;
      text-decoration: none; transition: all 0.3s;
      box-shadow: 0 4px 20px rgba(79,110,247,0.12);
    }
    .btn-view-all:hover {
      background: var(--blue); color: #fff; border-color: var(--blue);
      transform: translateY(-2px);
      box-shadow: 0 12px 32px rgba(79,110,247,0.35);
    }

    /* Features Section (dark glass) */
    .features-section {
      padding: 90px 48px;
      background: linear-gradient(135deg, #0F172A 0%, #1E1B4B 100%);
      position: relative;
      overflow: hidden;
    }
    .features-section::before {
      content: '';
      position: absolute; top: -100px; left: -100px;
      width: 500px; height: 500px;
      background: radial-gradient(circle, rgba(79,110,247,0.2) 0%, transparent 70%);
      pointer-events: none;
    }
    .features-section::after {
      content: '';
      position: absolute; bottom: -100px; right: -100px;
      width: 500px; height: 500px;
      background: radial-gradient(circle, rgba(124,58,237,0.2) 0%, transparent 70%);
      pointer-events: none;
    }
    .features-section .section-heading .eyebrow { background: rgba(79,110,247,0.2); color: #93c5fd; }
    .features-section .section-heading h2 { color: #fff; }
    .features-section .section-heading p { color: rgba(255,255,255,0.6); }

    .features-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 28px;
      max-width: 1100px;
      margin: 0 auto;
      position: relative; z-index: 1;
    }

    .feature-card {
      border: 1px solid rgba(255,255,255,0.12);
      border-radius: 30px;
      padding: 44px 32px;
      background: rgba(255,255,255,0.06);
      backdrop-filter: blur(20px) saturate(160%);
      -webkit-backdrop-filter: blur(20px);
      transition: all 0.4s cubic-bezier(0.4,0,0.2,1);
      text-align: center;
      box-shadow: 0 8px 32px rgba(0,0,0,0.25), inset 0 1px 0 rgba(255,255,255,0.1);
      opacity: 0; transform: translateY(28px);
      position: relative; overflow: hidden;
    }
    .feature-card::before {
      content: '';
      position: absolute; inset: 0;
      background: linear-gradient(135deg, rgba(255,255,255,0.05) 0%, transparent 100%);
      opacity: 0; transition: opacity 0.3s;
    }
    .feature-card:hover::before { opacity: 1; }
    .feature-card.animate-in { opacity: 1; transform: translateY(0); }
    .feature-card:hover {
      transform: translateY(-12px);
      box-shadow: 0 30px 60px rgba(0,0,0,0.35), inset 0 1px 0 rgba(255,255,255,0.2);
      border-color: rgba(255,255,255,0.22);
      background: rgba(255,255,255,0.1);
    }
    .feature-card:nth-child(1){transition-delay:.1s}
    .feature-card:nth-child(2){transition-delay:.2s}
    .feature-card:nth-child(3){transition-delay:.3s}

    .feature-icon {
      width: 78px; height: 78px; border-radius: 26px;
      display: flex; align-items: center; justify-content: center;
      margin: 0 auto 26px;
      box-shadow: 0 8px 24px rgba(0,0,0,0.3);
    }
    .feature-icon svg { width: 34px; height: 34px; }
    .feature-icon.blue { background: linear-gradient(135deg, #3B82F6, #6366F1); }
    .feature-icon.purple { background: linear-gradient(135deg, #8B5CF6, #A855F7); }
    .feature-icon.green { background: linear-gradient(135deg, #10B981, #22C55E); }

    .feature-card h3 {
      font-family: var(--font-display);
      font-size: 22px; font-weight: 700;
      color: #fff; margin-bottom: 14px; letter-spacing: -0.3px;
    }
    .feature-card p { font-size: 15px; color: rgba(255,255,255,0.6); line-height: 1.7; }

    /* Dark Bottom Bar */
    .dark-bar {
      background: var(--darker);
      padding: 40px 24px;
      display: flex; align-items: center; justify-content: center;
      gap: 64px; flex-wrap: wrap;
      border-top: 1px solid rgba(255,255,255,0.06);
    }
    .dark-bar-item {
      display: flex; align-items: center; gap: 16px; transition: transform 0.3s;
    }
    .dark-bar-item:hover { transform: translateY(-3px); }
    .dark-bar-icon {
      width: 58px; height: 58px; border-radius: 18px;
      display: flex; align-items: center; justify-content: center;
      backdrop-filter: blur(8px);
    }
    .dark-bar-icon.blue { background: rgba(99,102,241,0.15); border: 1px solid rgba(99,102,241,0.2); }
    .dark-bar-icon.green { background: rgba(34,197,94,0.15); border: 1px solid rgba(34,197,94,0.2); }
    .dark-bar-icon.yellow { background: rgba(245,158,11,0.15); border: 1px solid rgba(245,158,11,0.2); }
    .dark-bar-icon svg { width: 28px; height: 28px; }
    .dark-bar-text h4 { font-family: var(--font-display); font-size: 17px; font-weight: 700; color: #fff; margin-bottom: 3px; }
    .dark-bar-text p { font-size: 13px; color: #6B7280; }

    /* Responsive */
    @media (max-width: 1100px) {
      .cards-grid { grid-template-columns: repeat(2,1fr); }
      .features-grid { grid-template-columns: repeat(2,1fr); }
      .cards-section, .features-section { padding: 60px 32px; }
      .filter-bar { padding: 16px 32px; }
    }

    @media (max-width: 768px) {
      .hero { height: 640px; }
      .hero-content h1 { font-size: 34px; letter-spacing: -0.5px; }
      .hero-content p { font-size: 15px; }
      .hero-search { flex-direction: column; border-radius: 24px; }
      .hero-search input { text-align: left; }
      .hero-search button { border-radius: 0; }
      .hero-stats { display: grid; grid-template-columns: repeat(2,1fr); gap: 10px; padding: 0 10px; }
      .hero-stat { min-width: auto; padding: 10px 14px; }
      .hero-stat strong { font-size: 20px; }
      .carousel-arrow { display: none; }
      .filter-bar { padding: 14px 20px; flex-direction: column; align-items: stretch; }
      .filter-left { flex-direction: column; align-items: stretch; }
      .filter-select { width: 100%; }
      .cards-section { padding: 40px 20px; }
      .cards-grid { grid-template-columns: 1fr; }
      .features-section { padding: 60px 20px; }
      .features-grid { grid-template-columns: 1fr; }
      .dark-bar { gap: 30px; flex-direction: column; padding: 36px 20px; }
      .dark-bar-item { width: 100%; justify-content: center; }
    }

    @media (max-width: 480px) {
      .hero-content h1 { font-size: 28px; }
    }
  </style>
</head>
<body>

<!-- Include Navbar Component -->
<?php require_once 'includes/navbar.php'; ?>

<!-- HERO WITH CAROUSEL -->
<section class="hero">
  <div class="carousel-container" id="heroCarousel">
    <div class="carousel-slide active">
      <img src="img/carosel (3).jpg" alt="Parking Space 1" onerror="this.src='https://images.unsplash.com/photo-1573342218828-3df50b1a3a5f?auto=format&fit=crop&w=2070&q=80'">
    </div>
    <div class="carousel-slide">
      <img src="img/carosel (3).png" alt="Parking Space 2" onerror="this.src='https://images.unsplash.com/photo-1506521781263-d8422e82f27a?auto=format&fit=crop&w=2070&q=80'">
    </div>
    <div class="carousel-slide">
      <img src="img/carosel (4).jpg" alt="Parking Space 3" onerror="this.src='https://images.unsplash.com/photo-1590674899484-d5640e854a45?auto=format&fit=crop&w=2070&q=80'">
    </div>
  </div>

  <div class="hero-overlay"></div>
  <div class="hero-overlay-bottom"></div>
  <div class="carousel-progress" id="carouselProgress" style="width:33.33%"></div>

  <div class="carousel-arrow left" onclick="prevSlide()">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
  </div>
  <div class="carousel-arrow right" onclick="nextSlide()">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
  </div>

  <div class="carousel-nav" id="carouselNav">
    <div class="carousel-dot active" onclick="goToSlide(0)"></div>
    <div class="carousel-dot" onclick="goToSlide(1)"></div>
    <div class="carousel-dot" onclick="goToSlide(2)"></div>
  </div>

  <div class="hero-content">
    <div class="hero-badge">
      <span class="hero-badge-dot"></span>
      500+ Verified Parking Spots
    </div>
    <h1>
      Find &amp; Book Parking<br>
      <span class="grad">In Seconds</span>
    </h1>
    <p>Discover secure parking spaces near you. Save time and money with our smart parking platform.</p>

    <div class="hero-search-wrap">
      <form class="hero-search" method="GET" action="index.php">
        <input type="text" name="search" placeholder="Where do you want to park?" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>"/>
        <button type="submit">Search Parking</button>
      </form>
    </div>

    <div class="hero-stats">
      <div class="hero-stat"><strong><?php echo number_format($total_locations); ?>+</strong><span>Locations</span></div>
      <div class="hero-stat"><strong><?php echo number_format($total_customers); ?>+</strong><span>Customers</span></div>
      <div class="hero-stat"><strong>24/7</strong><span>Support</span></div>
      <div class="hero-stat"><strong><?php echo $avg_rating; ?>★</strong><span>Rating</span></div>
    </div>
  </div>
</section>

<!-- FILTER BAR -->
<div class="filter-bar">
  <form class="filter-left" method="GET" action="index.php" id="filter-form">
    <?php if (!empty($search)): ?>
      <input type="hidden" name="search" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
    <?php endif; ?>
    <div class="filter-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
      Filter:
    </div>
    <select name="type" class="filter-select" onchange="document.getElementById('filter-form').submit()">
      <option value="">All Types</option>
      <option value="covered_garage" <?php echo $type=='covered_garage'?'selected':''; ?>>Covered Garage</option>
      <option value="open_lot" <?php echo $type=='open_lot'?'selected':''; ?>>Open Lot</option>
      <option value="underground" <?php echo $type=='underground'?'selected':''; ?>>Underground</option>
      <option value="street_parking" <?php echo $type=='street_parking'?'selected':''; ?>>Street Parking</option>
    </select>
    <label class="filter-checkbox">
      <input type="checkbox" name="available" value="1" <?php echo $available_only?'checked':''; ?> onchange="document.getElementById('filter-form').submit()"/> Available Only
    </label>
  </form>
  <span class="filter-count"><?php echo $total_count; ?> spaces</span>
</div>

<!-- CARDS GRID -->
<section class="cards-section">
  <div class="section-heading">
    <span class="eyebrow">Parking Spaces</span>
    <h2>Available Near You</h2>
    <p>Browse verified, secure parking spots with real-time availability.</p>
  </div>
  <div class="cards-grid">
    <?php if (empty($parking_spaces)): ?>
      <div class="no-results">
        <svg width="72" height="72" viewBox="0 0 24 24" fill="none" stroke="#CBD5E1" stroke-width="1.5">
          <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
        <h3>No spaces found</h3>
        <p>Try adjusting your filters or search term.</p>
      </div>
    <?php else: ?>
      <?php foreach ($parking_spaces as $space):
        $amenities = json_decode($space['amenities'] ?? '', true) ?: [];
        $display_amenities = array_slice($amenities, 0, 3);
        $remaining = count($amenities) - 3;
        $status = $space['available_spots'] > 0 ? 'available' : 'full';
        $status_text = $space['available_spots'] > 0 ? 'Available' : 'Full';
        $type_display = ucwords(str_replace('_', ' ', $space['parking_type'] ?? ''));
      ?>
      <div class="park-card">
        <div class="park-card-img">
          <img src="<?php echo htmlspecialchars(getImageUrl($space['images'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
               alt="<?php echo htmlspecialchars($space['name'] ?? ''); ?>"
               onerror="this.src='img/parking-placeholder.jpg'; this.onerror=null;">
          <span class="badge-type"><?php echo htmlspecialchars($type_display); ?></span>
          <span class="badge-status <?php echo $status; ?>"><?php echo $status_text; ?></span>
          <div class="park-card-rating">
            <span class="star">★</span>
            <?php echo number_format($space['avg_rating'] ?? 0, 1); ?>
            <span style="opacity:.7">(<?php echo (int)($space['review_count'] ?? 0); ?>)</span>
          </div>
        </div>
        <div class="park-card-header">
          <h3><?php echo htmlspecialchars($space['name'] ?? ''); ?></h3>
          <div class="park-card-location">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
            <?php echo htmlspecialchars($space['city'] ?? ''); ?>
          </div>
        </div>
        <div class="park-card-tags">
          <?php foreach ($display_amenities as $amenity): ?>
            <span class="tag"><?php echo htmlspecialchars($amenity); ?></span>
          <?php endforeach; ?>
          <?php if ($remaining > 0): ?><span class="tag tag-more">+<?php echo $remaining; ?></span><?php endif; ?>
        </div>
        <div class="park-card-capacity">
          <span>Capacity</span>
          <strong><?php echo (int)($space['available_spots'] ?? 0); ?>/<?php echo (int)($space['total_spots'] ?? 0); ?></strong>
        </div>
        <div class="park-card-prices">
          <?php if (!empty($space['hourly_rate'])): ?>
          <div class="price-item"><label>Hourly</label><div class="amount"><?php echo number_format($space['hourly_rate'], 0); ?></div></div>
          <?php endif; ?>
          <?php if (!empty($space['daily_rate'])): ?>
          <div class="price-item"><label>Daily</label><div class="amount"><?php echo number_format($space['daily_rate'], 0); ?></div></div>
          <?php endif; ?>
        </div>
        <a href="parking-details.php?id=<?php echo (int)$space['id']; ?>" class="park-card-btn">
          View Details
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
        </a>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
  <?php if (count($parking_spaces) > 0): ?>
  <div class="view-all-wrap">
    <a href="all-spaces.php" class="btn-view-all">
      View All <?php echo $total_count; ?> Spaces
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
    </a>
  </div>
  <?php endif; ?>
</section>

<!-- FEATURES (dark glassmorphism) -->
<section class="features-section" id="how-it-works">
  <div class="section-heading">
    <span class="eyebrow">Why SpaceNode</span>
    <h2>Everything You Need</h2>
    <p>We make parking stress-free with technology that works for you.</p>
  </div>
  <div class="features-grid">
    <div class="feature-card">
      <div class="feature-icon blue">
        <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
      </div>
      <h3>Secure &amp; Safe</h3>
      <p>24/7 monitored facilities with advanced security systems for your complete peace of mind.</p>
    </div>
    <div class="feature-card">
      <div class="feature-icon purple">
        <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
      </div>
      <h3>Instant Booking</h3>
      <p>Reserve your spot in seconds with our fast and seamless booking experience.</p>
    </div>
    <div class="feature-card">
      <div class="feature-icon green">
        <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
      </div>
      <h3>Best Prices</h3>
      <p>Compare rates and find the most affordable options with our best-price guarantee.</p>
    </div>
  </div>
</section>

<!-- DARK BOTTOM BAR -->
<div class="dark-bar">
  <div class="dark-bar-item">
    <div class="dark-bar-icon blue">
      <svg viewBox="0 0 24 24" fill="none" stroke="#6366F1" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
    </div>
    <div class="dark-bar-text"><h4>Secure Parking</h4><p>24/7 monitored</p></div>
  </div>
  <div class="dark-bar-item">
    <div class="dark-bar-icon green">
      <svg viewBox="0 0 24 24" fill="none" stroke="#22C55E" stroke-width="2"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
    </div>
    <div class="dark-bar-text"><h4>Instant Booking</h4><p>Reserve in seconds</p></div>
  </div>
  <div class="dark-bar-item">
    <div class="dark-bar-icon yellow">
      <svg viewBox="0 0 24 24" fill="none" stroke="#F59E0B" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
    </div>
    <div class="dark-bar-text"><h4>Best Rates</h4><p>Price guarantee</p></div>
  </div>
</div>

<!-- Include Footer Component -->
<?php require_once 'includes/footer.php'; ?>

<script>
  // Carousel
  let currentSlide = 0;
  const slides = document.querySelectorAll('.carousel-slide');
  const dots = document.querySelectorAll('.carousel-dot');
  const progressBar = document.getElementById('carouselProgress');
  const totalSlides = slides.length;
  let autoSlideInterval;

  function showSlide(index) {
    slides.forEach(s => s.classList.remove('active'));
    dots.forEach(d => d.classList.remove('active'));
    slides[index].classList.add('active');
    dots[index].classList.add('active');
    if (progressBar) progressBar.style.width = ((index + 1) / totalSlides * 100) + '%';
    currentSlide = index;
  }
  function nextSlide() { currentSlide = (currentSlide + 1) % totalSlides; showSlide(currentSlide); resetAutoSlide(); }
  function prevSlide() { currentSlide = (currentSlide - 1 + totalSlides) % totalSlides; showSlide(currentSlide); resetAutoSlide(); }
  function goToSlide(i) { showSlide(i); resetAutoSlide(); }
  function startAutoSlide() { autoSlideInterval = setInterval(nextSlide, 5000); }
  function resetAutoSlide() { clearInterval(autoSlideInterval); startAutoSlide(); }

  const carousel = document.querySelector('.carousel-container');
  if (carousel) {
    carousel.addEventListener('mouseenter', () => clearInterval(autoSlideInterval));
    carousel.addEventListener('mouseleave', startAutoSlide);
    let tx = 0;
    carousel.addEventListener('touchstart', e => { tx = e.changedTouches[0].screenX; });
    carousel.addEventListener('touchend', e => {
      const diff = e.changedTouches[0].screenX - tx;
      if (diff < -50) nextSlide();
      if (diff > 50) prevSlide();
    });
  }
  document.addEventListener('keydown', e => {
    if (e.key === 'ArrowLeft') prevSlide();
    if (e.key === 'ArrowRight') nextSlide();
  });
  startAutoSlide(); showSlide(0);

  // Scroll animations
  const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (entry.isIntersecting) entry.target.classList.add('animate-in');
    });
  }, { threshold: 0.1, rootMargin: '0px 0px -40px 0px' });

  document.querySelectorAll('.park-card, .feature-card').forEach(el => observer.observe(el));
</script>
</body>
</html>