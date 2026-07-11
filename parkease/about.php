<?php
session_start();
require_once 'includes/user-access.php';
redirectOwnersFromPublicPages();
require_once 'config/database.php';
require_once 'config/team-image.php';

// Function to sanitize output
function sanitize($input) {
    return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
}

// Get database connection
$database = new Database();
$db = $database->getConnection();

// Get real statistics from database with error handling
$stats_query = "SELECT 
    (SELECT COUNT(*) FROM parking_spaces WHERE is_active = 1) as total_locations,
    (SELECT COUNT(*) FROM users WHERE user_type = 'parker') as total_customers,
    (SELECT COUNT(*) FROM reservations WHERE status = 'completed') as total_bookings,
    (SELECT COALESCE(AVG(rating), 0) FROM reviews) as avg_rating";

try {
    $stats_stmt = $db->prepare($stats_query);
    $stats_stmt->execute();
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Stats query error: " . $e->getMessage());
    $stats = [
        'total_locations' => 500,
        'total_customers' => 50000,
        'total_bookings' => 100000,
        'avg_rating' => 4.8
    ];
}

// Use defaults if no data
$total_locations = isset($stats['total_locations']) && $stats['total_locations'] > 0 ? (int)$stats['total_locations'] : 500;
$total_customers = isset($stats['total_customers']) && $stats['total_customers'] > 0 ? (int)$stats['total_customers'] : 50000;
$total_bookings = isset($stats['total_bookings']) && $stats['total_bookings'] > 0 ? (int)$stats['total_bookings'] : 100000;
$avg_rating = number_format($stats['avg_rating'] ?? 4.8, 1);

// Handle newsletter subscription
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
                    $upd->bindParam(':id', $existing['id']); 
                    $upd->execute();
                    $newsletter_message = '<div class="alert-success">Welcome back! You\'re resubscribed.</div>';
                } else { 
                    $newsletter_message = '<div class="alert-info">You\'re already subscribed!</div>'; 
                }
            } else {
                $token = bin2hex(random_bytes(32));
                $fn = $ln = null; 
                $uid = null;
                if (isset($_SESSION['user_id'])) {
                    $uid = (int)$_SESSION['user_id'];
                    if (isset($_SESSION['user_name'])) { 
                        $np = explode(' ', $_SESSION['user_name'], 2); 
                        $fn = substr($np[0],0,100); 
                        $ln = isset($np[1]) ? substr($np[1],0,100) : null; 
                    }
                }
                $ins = $db->prepare("INSERT INTO newsletter_subscribers (email,first_name,last_name,user_id,ip_address,unsubscribe_token,source) VALUES (:email,:fn,:ln,:uid,:ip,:token,'website')");
                $ins->bindParam(':email',$email); 
                $ins->bindParam(':fn',$fn); 
                $ins->bindParam(':ln',$ln); 
                $ins->bindParam(':uid',$uid); 
                $ins->bindParam(':ip',$ip); 
                $ins->bindParam(':token',$token);
                $ins->execute();
                $newsletter_message = '<div class="alert-success">Thanks for subscribing!</div>';
            }
        } catch (PDOException $e) {
            error_log('Newsletter error: '.$e->getMessage());
            $newsletter_message = '<div class="alert-error">Something went wrong. Try again later.</div>';
        }
    } else { 
        $newsletter_message = '<div class="alert-error">Please enter a valid email address.</div>'; 
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes"/>
  <meta name="description" content="Learn about SpaceNode - revolutionizing parking management through technology, convenience, and community">
  <meta name="keywords" content="SpaceNode, parking, smart parking, about us">
  <title>About Us - SpaceNode</title>
  
  <!-- Include all CSS assets (navbar, footer, global styles) -->
  <?php require_once 'includes/header-assets.php'; ?>
  
  <style>
    /* ============================================
       PAGE-SPECIFIC STYLES (About Page with Heavy Glassmorphism)
       These are separate from navbar/footer styles
    ============================================ */
    
    /* Hero Section with Glassmorphism */
    .hero {
      position: relative;
      background: linear-gradient(135deg, #0F172A 0%, #1E1B4B 100%);
      padding: 120px 48px;
      text-align: center;
      color: white;
      overflow: hidden;
    }

    .hero::before {
      content: '';
      position: absolute;
      top: -100px;
      left: -100px;
      width: 500px;
      height: 500px;
      background: radial-gradient(circle, rgba(79,110,247,0.3) 0%, transparent 70%);
      pointer-events: none;
    }

    .hero::after {
      content: '';
      position: absolute;
      bottom: -100px;
      right: -100px;
      width: 500px;
      height: 500px;
      background: radial-gradient(circle, rgba(124,58,237,0.25) 0%, transparent 70%);
      pointer-events: none;
    }

    .hero h1 {
      font-family: var(--font-display);
      font-size: clamp(40px, 7vw, 64px);
      font-weight: 900;
      margin-bottom: 20px;
      line-height: 1.2;
      letter-spacing: -1.5px;
      text-shadow: 0 4px 30px rgba(0,0,0,0.25);
      position: relative;
      z-index: 2;
    }

    .hero p {
      font-size: 18px;
      opacity: 0.92;
      max-width: 600px;
      margin: 0 auto;
      line-height: 1.6;
      position: relative;
      z-index: 2;
    }

    /* Content Sections with Glass Background */
    .content-section {
      padding: 64px 48px;
      background: radial-gradient(ellipse at 20% 30%, rgba(147,197,253,0.15) 0%, transparent 55%),
                  radial-gradient(ellipse at 80% 70%, rgba(196,181,253,0.12) 0%, transparent 55%),
                  #F1F5FF;
      max-width: 1400px;
      margin: 0 auto;
    }

    .section-title {
      font-family: var(--font-display);
      font-size: clamp(28px, 4vw, 40px);
      font-weight: 800;
      margin-bottom: 16px;
      color: var(--text);
      text-align: center;
      letter-spacing: -0.8px;
    }

    .section-subtitle {
      font-size: 17px;
      color: var(--muted);
      text-align: center;
      margin-bottom: 48px;
      max-width: 600px;
      margin-left: auto;
      margin-right: auto;
      line-height: 1.6;
    }

    /* Mission Section with Glassmorphism */
    .mission-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 48px;
      align-items: center;
      margin-bottom: 80px;
    }

    .mission-content h3 {
      font-family: var(--font-display);
      font-size: 32px;
      font-weight: 700;
      margin-bottom: 20px;
      background: linear-gradient(135deg, var(--blue), var(--purple));
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }

    .mission-content p {
      font-size: 16px;
      line-height: 1.8;
      color: var(--muted);
      margin-bottom: 16px;
    }

    .mission-image {
      background: linear-gradient(135deg, rgba(79,110,247,0.15), rgba(124,58,237,0.15));
      backdrop-filter: blur(20px);
      border: 1px solid rgba(255,255,255,0.4);
      border-radius: 28px;
      height: 320px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 100px;
      box-shadow: 0 20px 40px rgba(0,0,0,0.1), inset 0 1px 0 rgba(255,255,255,0.6);
      transition: transform 0.3s ease;
    }

    .mission-image:hover {
      transform: translateY(-8px);
    }

    /* Values Grid - Glassmorphism Cards */
    .values-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 32px;
      margin-bottom: 80px;
    }

    .value-card {
      background: rgba(255,255,255,0.75);
      backdrop-filter: blur(20px) saturate(180%);
      -webkit-backdrop-filter: blur(20px);
      border: 1px solid rgba(255,255,255,0.6);
      border-radius: 28px;
      padding: 40px 32px;
      text-align: center;
      transition: all 0.4s cubic-bezier(0.4,0,0.2,1);
      box-shadow: 0 8px 32px rgba(0,0,0,0.08), inset 0 1px 0 rgba(255,255,255,0.8);
      position: relative;
      overflow: hidden;
    }

    .value-card::after {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 2px;
      background: linear-gradient(90deg, transparent, rgba(79,110,247,0.6), transparent);
    }

    .value-card:hover {
      transform: translateY(-10px);
      background: rgba(255,255,255,0.88);
      backdrop-filter: blur(32px) saturate(220%);
      box-shadow: 0 28px 56px rgba(79,110,247,0.2), 0 8px 20px rgba(0,0,0,0.06);
      border-color: rgba(255,255,255,0.9);
    }

    .value-icon {
      font-size: 56px;
      margin-bottom: 20px;
    }

    .value-card h4 {
      font-family: var(--font-display);
      font-size: 22px;
      font-weight: 700;
      margin-bottom: 12px;
      color: var(--text);
      letter-spacing: -0.3px;
    }

    .value-card p {
      font-size: 15px;
      color: var(--muted);
      line-height: 1.7;
    }

    /* Stats Section - Glassmorphism */
    .stats-grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 24px;
      background: linear-gradient(135deg, rgba(79,110,247,0.15), rgba(124,58,237,0.15));
      backdrop-filter: blur(20px);
      border: 1px solid rgba(255,255,255,0.4);
      border-radius: 32px;
      padding: 56px 48px;
      margin-bottom: 80px;
      box-shadow: 0 20px 40px rgba(0,0,0,0.1), inset 0 1px 0 rgba(255,255,255,0.6);
    }

    .stat-item {
      text-align: center;
      color: var(--text);
      transition: all 0.3s ease;
    }

    .stat-item:hover {
      transform: translateY(-5px);
    }

    .stat-number {
      font-family: var(--font-display);
      font-size: 44px;
      font-weight: 800;
      margin-bottom: 8px;
      background: linear-gradient(135deg, var(--blue), var(--purple));
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }

    .stat-label {
      font-size: 14px;
      font-weight: 500;
      color: var(--muted);
    }

    /* Team Section - Glassmorphism Cards */
    .team-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
      justify-content: center;
      justify-items: center;
      gap: 28px;
      margin: 0 auto 80px;
      max-width: 900px;
      width: min(100%, 900px);
    }

    .team-member {
      width: 100%;
      max-width: 520px;
      background: rgba(255,255,255,0.75);
      backdrop-filter: blur(20px) saturate(180%);
      -webkit-backdrop-filter: blur(20px);
      border: 1px solid rgba(255,255,255,0.6);
      border-radius: 28px;
      overflow: hidden;
      text-align: center;
      transition: all 0.4s cubic-bezier(0.4,0,0.2,1);
      box-shadow: 0 8px 32px rgba(0,0,0,0.08), inset 0 1px 0 rgba(255,255,255,0.8);
    }

    .team-member:hover {
      transform: translateY(-10px);
      background: rgba(255,255,255,0.88);
      backdrop-filter: blur(32px) saturate(220%);
      box-shadow: 0 28px 56px rgba(79,110,247,0.2), 0 8px 20px rgba(0,0,0,0.06);
      border-color: rgba(255,255,255,0.9);
    }

    .team-avatar {
      width: 100%;
      height: 220px;
      background: linear-gradient(135deg, #0F172A, #1E1B4B);
      display: flex;
      align-items: center;
      justify-content: center;
      overflow: hidden;
      position: relative;
    }

    .team-avatar img {
      width: 100%;
      height: 100%;
      object-fit: contain;
      object-position: center;
      display: block;
    }

    .team-info {
      padding: 24px;
    }

    .team-name {
      font-family: var(--font-display);
      font-size: 18px;
      font-weight: 700;
      margin-bottom: 4px;
      color: var(--text);
    }

    .team-role {
      font-size: 13px;
      background: linear-gradient(135deg, var(--blue), var(--purple));
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
      font-weight: 600;
    }

    .team-note {
      font-size: 14px;
      color: var(--muted);
      font-weight: 500;
      line-height: 1.7;
      margin-top: 14px;
      text-align: left;
    }

    /* Timeline Section - Glassmorphism */
    .timeline {
      position: relative;
      padding: 40px 0;
      margin-bottom: 80px;
    }

    .timeline::before {
      content: '';
      position: absolute;
      left: 50%;
      top: 0;
      bottom: 0;
      width: 3px;
      background: linear-gradient(180deg, var(--blue), var(--purple));
      transform: translateX(-1px);
      border-radius: 3px;
    }

    .timeline-item {
      margin-bottom: 48px;
      position: relative;
    }

    .timeline-item:nth-child(odd) .timeline-content {
      margin-left: 0;
      margin-right: auto;
      width: calc(50% - 40px);
      text-align: right;
    }

    .timeline-item:nth-child(even) .timeline-content {
      margin-left: auto;
      margin-right: 0;
      width: calc(50% - 40px);
      text-align: left;
    }

    .timeline-dot {
      position: absolute;
      left: 50%;
      top: 0;
      width: 18px;
      height: 18px;
      background: linear-gradient(135deg, var(--blue), var(--purple));
      border: 4px solid white;
      border-radius: 50%;
      transform: translateX(-50%);
      z-index: 2;
      box-shadow: 0 0 0 3px rgba(79,110,247,0.3);
    }

    .timeline-content {
      background: rgba(255,255,255,0.75);
      backdrop-filter: blur(20px) saturate(180%);
      -webkit-backdrop-filter: blur(20px);
      border: 1px solid rgba(255,255,255,0.6);
      border-radius: 20px;
      padding: 24px;
      transition: all 0.3s ease;
    }

    .timeline-content:hover {
      background: rgba(255,255,255,0.88);
      transform: translateY(-4px);
      box-shadow: 0 12px 28px rgba(79,110,247,0.15);
    }

    .timeline-year {
      font-family: var(--font-display);
      font-size: 20px;
      font-weight: 800;
      background: linear-gradient(135deg, var(--blue), var(--purple));
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
      margin-bottom: 6px;
    }

    .timeline-title {
      font-size: 16px;
      font-weight: 700;
      color: var(--text);
      margin-bottom: 8px;
    }

    .timeline-description {
      font-size: 14px;
      color: var(--muted);
      line-height: 1.6;
    }

    /* CTA Section - Glassmorphism */
    .cta-section {
      background: linear-gradient(135deg, rgba(79,110,247,0.15), rgba(124,58,237,0.15));
      backdrop-filter: blur(20px);
      border: 1px solid rgba(255,255,255,0.4);
      text-align: center;
      padding: 64px 48px;
      border-radius: 32px;
      margin-bottom: 0;
      box-shadow: 0 20px 40px rgba(0,0,0,0.1), inset 0 1px 0 rgba(255,255,255,0.6);
    }

    .cta-section h2 {
      font-family: var(--font-display);
      font-size: clamp(28px, 4vw, 36px);
      font-weight: 800;
      margin-bottom: 16px;
      background: linear-gradient(135deg, var(--blue), var(--purple));
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }

    .cta-section p {
      font-size: 16px;
      color: var(--muted);
      margin-bottom: 32px;
    }

    .cta-buttons {
      display: flex;
      gap: 16px;
      justify-content: center;
      flex-wrap: wrap;
    }

    .cta-btn {
      padding: 14px 32px;
      border-radius: 50px;
      text-decoration: none;
      font-weight: 600;
      transition: all 0.3s;
      cursor: pointer;
      border: none;
      font-size: 14px;
      font-family: var(--font-display);
    }

    .cta-btn-primary {
      background: linear-gradient(135deg, var(--blue), var(--purple));
      color: white;
      box-shadow: 0 4px 18px rgba(79,110,247,0.32);
    }

    .cta-btn-primary:hover {
      transform: translateY(-2px);
      box-shadow: 0 10px 28px rgba(79,110,247,0.42);
    }

    .cta-btn-secondary {
      background: rgba(255,255,255,0.9);
      backdrop-filter: blur(8px);
      color: var(--blue);
      border: 1px solid rgba(79,110,247,0.3);
    }

    .cta-btn-secondary:hover {
      background: rgba(79,110,247,0.1);
      transform: translateY(-2px);
      border-color: var(--blue);
    }

    /* Alerts for Newsletter */
    .alert-success { 
      background: #DCFCE7; 
      color: #16A34A; 
      padding: 12px 16px; 
      border-radius: 14px; 
      margin-bottom: 16px; 
      font-size: 13px; 
      font-weight: 500; 
    }
    .alert-info { 
      background: #DBEAFE; 
      color: #1E40AF; 
      padding: 12px 16px; 
      border-radius: 14px; 
      margin-bottom: 16px; 
      font-size: 13px; 
    }
    .alert-error { 
      background: #FEE2E2; 
      color: #DC2626; 
      padding: 12px 16px; 
      border-radius: 14px; 
      margin-bottom: 16px; 
      font-size: 13px; 
    }

    /* Responsive Design */
    @media (max-width: 1024px) {
      .content-section { padding: 48px 32px; }
      .section-title { font-size: 32px; }
      .hero { padding: 80px 32px; }
      .hero h1 { font-size: 44px; }
      .mission-grid { gap: 40px; }
      .stats-grid { padding: 40px 32px; }
      .stat-number { font-size: 36px; }
    }

    @media (max-width: 768px) {
      .hero { padding: 60px 20px; }
      .hero h1 { font-size: 32px; }
      .hero p { font-size: 16px; }
      
      .content-section { padding: 40px 20px; }
      .section-title { font-size: 28px; }
      
      .mission-grid { 
        grid-template-columns: 1fr; 
        gap: 32px; 
      }
      .mission-image { 
        height: 250px;
        font-size: 80px; 
      }
      
      .values-grid { 
        grid-template-columns: 1fr; 
        gap: 20px;
      }
      
      .stats-grid { 
        grid-template-columns: repeat(2, 1fr); 
        gap: 24px;
        padding: 32px 20px;
      }
      .stat-number { 
        font-size: 28px; 
      }
      
      .team-grid { 
        grid-template-columns: 1fr; 
        max-width: 100%;
      }
      
      .timeline::before { 
        left: 20px; 
      }
      .timeline-item:nth-child(odd) .timeline-content,
      .timeline-item:nth-child(even) .timeline-content {
        width: calc(100% - 60px);
        margin-left: 60px;
        text-align: left;
      }
      .timeline-dot { 
        left: 14px; 
      }
      
      .cta-section { 
        padding: 40px 20px; 
      }
      .cta-section h2 { 
        font-size: 28px; 
      }
    }

    @media (max-width: 480px) {
      .hero h1 { 
        font-size: 28px; 
      }
      .hero p { 
        font-size: 14px; 
      }
      
      .section-title { 
        font-size: 24px; 
      }
      
      .stats-grid { 
        grid-template-columns: 1fr; 
      }
      .stat-number { 
        font-size: 26px; 
      }
      
      .team-avatar { 
        height: 180px; 
      }
      
      .cta-section h2 { 
        font-size: 24px; 
      }
      .cta-buttons { 
        flex-direction: column; 
        gap: 12px;
      }
      .cta-btn { 
        width: 100%; 
        text-align: center;
      }
    }
  </style>
</head>
<body>
  <!-- Include Navbar Component -->
  <?php require_once 'includes/navbar.php'; ?>

  <!-- HERO SECTION with Glassmorphism -->
  <section class="hero">
    <h1>About SpaceNode</h1>
    <p>Revolutionizing parking management through technology, convenience, and community</p>
  </section>

  <!-- MAIN CONTENT -->
  <div class="content-section">
    <!-- MISSION SECTION -->
    <section>
      <div class="mission-grid">
        <div class="mission-content">
          <h3>Our Mission</h3>
          <p>At SpaceNode, we believe that parking shouldn't be stressful. Our mission is to transform the parking experience by connecting drivers with available parking spaces in real-time, making parking easier, faster, and more affordable for everyone.</p>
          <p>We're committed to reducing traffic congestion, saving people time and money, and creating a smarter city through innovative technology and sustainable solutions.</p>
        </div>
        <div class="mission-image">🎯</div>
      </div>
    </section>

    <!-- VALUES SECTION -->
    <section>
      <h2 class="section-title">Our Core Values</h2>
      <div class="values-grid">
        <div class="value-card">
          <div class="value-icon">💡</div>
          <h4>Innovation</h4>
          <p>We continuously push boundaries to deliver cutting-edge parking solutions that anticipate and meet evolving customer needs.</p>
        </div>
        <div class="value-card">
          <div class="value-icon">🤝</div>
          <h4>Community</h4>
          <p>We believe in building strong relationships with our users, partners, and the communities we serve.</p>
        </div>
        <div class="value-card">
          <div class="value-icon">🌱</div>
          <h4>Sustainability</h4>
          <p>We're dedicated to reducing environmental impact and promoting eco-friendly transportation practices.</p>
        </div>
      </div>
    </section>

    <!-- STATS SECTION - Glassmorphism -->
    <section class="stats-grid">
      <div class="stat-item">
        <div class="stat-number"><?php echo number_format($total_locations); ?>+</div>
        <div class="stat-label">Active Parking Spaces</div>
      </div>
      <div class="stat-item">
        <div class="stat-number"><?php echo number_format($total_customers); ?>+</div>
        <div class="stat-label">Happy Customers</div>
      </div>
      <div class="stat-item">
        <div class="stat-number"><?php echo number_format($total_bookings); ?>+</div>
        <div class="stat-label">Successful Bookings</div>
      </div>
      <div class="stat-item">
        <div class="stat-number"><?php echo $avg_rating; ?> ★</div>
        <div class="stat-label">Average Rating</div>
      </div>
    </section>

    <!-- TEAM SECTION -->
    <section>
      <h2 class="section-title">Leadership Team</h2>
      <p class="section-subtitle">Meet the dedicated professionals leading SpaceNode into the future</p>
      <div class="team-grid">
        <div class="team-member">
          <div class="team-avatar">
            <img src="<?php echo isset($team_image_path) ? sanitize($team_image_path) : 'img/team-placeholder.jpg'; ?>" alt="TriStack Team" onerror="this.src='img/team-placeholder.jpg'" />
          </div>
          <div class="team-info">
            <div class="team-name">TRI - STACK</div>
            <div class="team-role">Founder & Co-founder</div>
            <div class="team-note">TriStack is a dedicated team of three developers passionate about creating smart and reliable digital solutions. As the minds behind this parking space platform, we focus on building efficient, user-friendly systems that simplify operations and enhance customer experience.</div>
          </div>
        </div>
      </div>
    </section>

    <!-- TIMELINE SECTION -->
    <section>
      <h2 class="section-title">Our Journey</h2>
      <p class="section-subtitle">From idea to innovation: The SpaceNode story</p>
      <div class="timeline">
        <div class="timeline-item">
          <div class="timeline-dot"></div>
          <div class="timeline-content">
            <div class="timeline-year">2021</div>
            <div class="timeline-title">The Beginning</div>
            <div class="timeline-description">SpaceNode was founded with a simple vision: make parking easy for everyone. Our founding team started with extensive market research and user interviews.</div>
          </div>
        </div>
        <div class="timeline-item">
          <div class="timeline-dot"></div>
          <div class="timeline-content">
            <div class="timeline-year">2022</div>
            <div class="timeline-title">Beta Launch</div>
            <div class="timeline-description">We launched our beta version in major cities, onboarding over 5,000 users and 500 parking space owners in just three months.</div>
          </div>
        </div>
        <div class="timeline-item">
          <div class="timeline-dot"></div>
          <div class="timeline-content">
            <div class="timeline-year">2023</div>
            <div class="timeline-title">Official Release</div>
            <div class="timeline-description">Full platform launch with advanced features including real-time availability, integrated payments, and AI-powered recommendations.</div>
          </div>
        </div>
        <div class="timeline-item">
          <div class="timeline-dot"></div>
          <div class="timeline-content">
            <div class="timeline-year">2024</div>
            <div class="timeline-title">Expansion & Growth</div>
            <div class="timeline-description">Expanded to 50+ cities and reached 100,000+ active users. Introduced subscription plans and corporate partnerships.</div>
          </div>
        </div>
        <div class="timeline-item">
          <div class="timeline-dot"></div>
          <div class="timeline-content">
            <div class="timeline-year">2025</div>
            <div class="timeline-title">Global Vision</div>
            <div class="timeline-description">Launched international operations and integrated sustainability features to promote eco-friendly parking solutions.</div>
          </div>
        </div>
      </div>
    </section>
  </div>

  <!-- CTA SECTION - Glassmorphism -->
  <div class="content-section">
    <section class="cta-section">
      <h2>Ready to Experience the Future of Parking?</h2>
      <p>Join thousands of users who have made parking stress-free with SpaceNode</p>
      <div class="cta-buttons">
        <a href="<?php echo isset($_SESSION['user_id']) && !empty($_SESSION['user_id']) ? 'index.php' : 'register.php'; ?>" class="cta-btn cta-btn-primary">
          <?php echo isset($_SESSION['user_id']) && !empty($_SESSION['user_id']) ? 'Find Parking' : 'Get Started Today'; ?>
        </a>
        <a href="#" class="cta-btn cta-btn-secondary">Learn More</a>
      </div>
    </section>
  </div>

  <!-- Include Footer Component -->
  <?php require_once 'includes/footer.php'; ?>

  <script>
    // Smooth scrolling for anchor links
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
      anchor.addEventListener('click', function (e) {
        e.preventDefault();
        const target = document.querySelector(this.getAttribute('href'));
        if (target) {
          target.scrollIntoView({ behavior: 'smooth' });
        }
      });
    });
  </script>
</body>
</html>