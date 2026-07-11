<?php
session_start();
require_once 'includes/user-access.php';
redirectOwnersFromPublicPages();
require_once 'config/database.php';

// Get database connection
$database = new Database();
$db = $database->getConnection();

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
  <title>Help Center - SpaceNode</title>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800;900&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  
  <!-- Include all CSS assets -->
  <?php require_once 'includes/header-assets.php'; ?>
  
  <style>
    /* ============================================
       PAGE-SPECIFIC STYLES (Help Center with Heavy Glassmorphism)
    ============================================ */
    
    /* Hero Section with Glassmorphism */
    .hero {
      position: relative;
      background: linear-gradient(135deg, #0F172A 0%, #1E1B4B 100%);
      padding: 100px 48px;
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
      font-size: clamp(40px, 7vw, 56px);
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

    /* Search Section */
    .search-section {
      padding: 48px 48px;
      background: radial-gradient(ellipse at 20% 30%, rgba(147,197,253,0.15) 0%, transparent 55%),
                  radial-gradient(ellipse at 80% 70%, rgba(196,181,253,0.12) 0%, transparent 55%),
                  #F1F5FF;
      text-align: center;
    }

    .search-container {
      max-width: 600px;
      margin: 0 auto;
    }

    .search-container h2 {
      font-family: var(--font-display);
      font-size: 28px;
      font-weight: 700;
      background: linear-gradient(135deg, var(--text), #4F6EF7);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
      margin-bottom: 24px;
      letter-spacing: -0.5px;
    }

    .search-box {
      display: flex;
      align-items: center;
      gap: 12px;
      background: rgba(255,255,255,0.75);
      backdrop-filter: blur(20px);
      border: 1px solid rgba(255,255,255,0.6);
      border-radius: 60px;
      padding: 14px 24px;
      transition: all 0.3s ease;
      box-shadow: 0 4px 20px rgba(0,0,0,0.05), inset 0 1px 0 rgba(255,255,255,0.8);
    }

    .search-box:focus-within {
      border-color: var(--blue);
      box-shadow: 0 8px 28px rgba(79,110,247,0.15);
    }

    .search-box i {
      color: var(--muted);
      font-size: 18px;
    }

    .search-box input {
      flex: 1;
      border: none;
      background: transparent;
      font-size: 16px;
      font-family: var(--font-body);
      outline: none;
      color: var(--text);
    }

    .search-box input::placeholder {
      color: var(--muted);
    }

    /* Content Section */
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
      font-size: clamp(28px, 4vw, 36px);
      font-weight: 800;
      margin-bottom: 48px;
      color: var(--text);
      text-align: center;
      letter-spacing: -0.8px;
    }

    /* Glassmorphism Help Cards Grid */
    .help-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 28px;
      margin-bottom: 64px;
    }

    .help-card {
      background: rgba(255,255,255,0.75);
      backdrop-filter: blur(20px) saturate(180%);
      -webkit-backdrop-filter: blur(20px);
      border: 1px solid rgba(255,255,255,0.6);
      border-radius: 24px;
      padding: 32px;
      transition: all 0.4s cubic-bezier(0.4,0,0.2,1);
      box-shadow: 0 8px 32px rgba(0,0,0,0.08), inset 0 1px 0 rgba(255,255,255,0.8);
      cursor: pointer;
      text-decoration: none;
      color: inherit;
      display: flex;
      flex-direction: column;
    }

    .help-card:hover {
      transform: translateY(-8px);
      background: rgba(255,255,255,0.88);
      backdrop-filter: blur(32px) saturate(220%);
      box-shadow: 0 28px 56px rgba(79,110,247,0.2);
      border-color: rgba(255,255,255,0.9);
    }

    .help-card i {
      font-size: 48px;
      background: linear-gradient(135deg, var(--blue), var(--purple));
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
      margin-bottom: 20px;
    }

    .help-card h3 {
      font-family: var(--font-display);
      font-size: 20px;
      font-weight: 700;
      color: var(--text);
      margin-bottom: 12px;
      letter-spacing: -0.3px;
    }

    .help-card p {
      font-size: 14px;
      color: var(--muted);
      line-height: 1.6;
      flex-grow: 1;
      margin-bottom: 16px;
    }

    .help-card-arrow {
      display: flex;
      align-items: center;
      gap: 8px;
      color: var(--blue);
      font-weight: 600;
      font-size: 14px;
    }

    .help-card-arrow i {
      font-size: 14px;
      transition: transform 0.3s ease;
    }

    .help-card:hover .help-card-arrow i {
      transform: translateX(4px);
    }

    /* Glassmorphism Help Sections */
    .help-section {
      background: rgba(255,255,255,0.75);
      backdrop-filter: blur(20px) saturate(180%);
      -webkit-backdrop-filter: blur(20px);
      border: 1px solid rgba(255,255,255,0.6);
      border-radius: 28px;
      padding: 40px;
      margin-bottom: 40px;
      transition: all 0.3s ease;
    }

    .help-section:hover {
      background: rgba(255,255,255,0.85);
      box-shadow: 0 12px 32px rgba(79,110,247,0.1);
    }

    .help-section h2 {
      font-family: var(--font-display);
      font-size: 26px;
      font-weight: 700;
      color: var(--text);
      margin-bottom: 28px;
      display: flex;
      align-items: center;
      gap: 12px;
      letter-spacing: -0.5px;
    }

    .help-section h2 i {
      background: linear-gradient(135deg, var(--blue), var(--purple));
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }

    .help-section-content {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 24px;
    }

    .help-item {
      background: rgba(255,255,255,0.6);
      backdrop-filter: blur(12px);
      border-radius: 20px;
      padding: 24px;
      border: 1px solid rgba(255,255,255,0.6);
      transition: all 0.3s ease;
    }

    .help-item:hover {
      transform: translateY(-4px);
      background: rgba(255,255,255,0.8);
      border-color: rgba(79,110,247,0.3);
      box-shadow: 0 8px 24px rgba(79,110,247,0.1);
    }

    .help-item h3 {
      font-family: var(--font-display);
      font-size: 17px;
      font-weight: 700;
      color: var(--text);
      margin-bottom: 14px;
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .help-item-number {
      width: 30px;
      height: 30px;
      background: linear-gradient(135deg, var(--blue), var(--purple));
      color: white;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 700;
      font-size: 14px;
      flex-shrink: 0;
    }

    .help-item p {
      font-size: 14px;
      color: var(--muted);
      line-height: 1.7;
    }

    .help-item ul {
      list-style: none;
      margin: 12px 0 0;
    }

    .help-item li {
      font-size: 14px;
      color: var(--muted);
      padding: 6px 0 6px 24px;
      position: relative;
    }

    .help-item li:before {
      content: "→";
      position: absolute;
      left: 8px;
      color: var(--blue);
      font-weight: bold;
    }

    /* Glassmorphism Video Section */
    .video-section {
      background: rgba(255,255,255,0.75);
      backdrop-filter: blur(20px) saturate(180%);
      -webkit-backdrop-filter: blur(20px);
      border: 1px solid rgba(255,255,255,0.6);
      border-radius: 28px;
      padding: 40px;
      margin-bottom: 40px;
    }

    .video-section h2 {
      font-family: var(--font-display);
      font-size: 26px;
      font-weight: 700;
      color: var(--text);
      margin-bottom: 32px;
    }

    .video-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 24px;
    }

    .video-card {
      background: rgba(255,255,255,0.6);
      backdrop-filter: blur(12px);
      border-radius: 20px;
      overflow: hidden;
      border: 1px solid rgba(255,255,255,0.6);
      transition: all 0.3s ease;
    }

    .video-card:hover {
      transform: translateY(-4px);
      background: rgba(255,255,255,0.8);
      border-color: rgba(79,110,247,0.3);
      box-shadow: 0 12px 28px rgba(79,110,247,0.15);
    }

    .video-thumbnail {
      width: 100%;
      height: 180px;
      background: linear-gradient(135deg, rgba(79,110,247,0.2), rgba(124,58,237,0.2));
      display: flex;
      align-items: center;
      justify-content: center;
      position: relative;
      cursor: pointer;
      transition: all 0.3s ease;
    }

    .video-thumbnail:hover {
      background: linear-gradient(135deg, rgba(79,110,247,0.3), rgba(124,58,237,0.3));
    }

    .video-play-btn {
      width: 56px;
      height: 56px;
      background: linear-gradient(135deg, var(--blue), var(--purple));
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
      font-size: 20px;
      transition: all 0.3s;
      box-shadow: 0 4px 15px rgba(79,110,247,0.3);
    }

    .video-thumbnail:hover .video-play-btn {
      transform: scale(1.1);
    }

    .video-info {
      padding: 18px;
    }

    .video-info h4 {
      font-family: var(--font-display);
      font-size: 15px;
      font-weight: 700;
      color: var(--text);
      margin-bottom: 6px;
    }

    .video-info p {
      font-size: 12px;
      color: var(--muted);
    }

    /* Glassmorphism CTA Section */
    .help-cta {
      background: rgba(255,255,255,0.75);
      backdrop-filter: blur(20px) saturate(180%);
      -webkit-backdrop-filter: blur(20px);
      border: 1px solid rgba(255,255,255,0.6);
      border-radius: 28px;
      padding: 48px;
      text-align: center;
      margin-top: 64px;
      transition: all 0.3s ease;
    }

    .help-cta:hover {
      background: rgba(255,255,255,0.85);
      box-shadow: 0 12px 32px rgba(79,110,247,0.1);
    }

    .help-cta h3 {
      font-family: var(--font-display);
      font-size: 26px;
      font-weight: 700;
      color: var(--text);
      margin-bottom: 16px;
      letter-spacing: -0.5px;
    }

    .help-cta p {
      font-size: 16px;
      color: var(--muted);
      margin-bottom: 28px;
      line-height: 1.6;
    }

    .cta-buttons {
      display: flex;
      gap: 16px;
      justify-content: center;
      flex-wrap: wrap;
    }

    .cta-button {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 14px 32px;
      border-radius: 50px;
      text-decoration: none;
      font-weight: 600;
      font-size: 14px;
      font-family: var(--font-display);
      transition: all 0.3s ease;
    }

    .cta-button.primary {
      background: linear-gradient(135deg, var(--blue), var(--purple));
      color: white;
      box-shadow: 0 4px 18px rgba(79,110,247,0.32);
    }

    .cta-button.primary:hover {
      transform: translateY(-2px);
      box-shadow: 0 10px 28px rgba(79,110,247,0.42);
    }

    .cta-button.secondary {
      background: rgba(255,255,255,0.9);
      backdrop-filter: blur(8px);
      color: var(--blue);
      border: 1px solid rgba(79,110,247,0.3);
    }

    .cta-button.secondary:hover {
      background: rgba(79,110,247,0.08);
      transform: translateY(-2px);
      border-color: var(--blue);
    }

    /* Alerts for Newsletter */
    .alert-success { 
      background: rgba(34,197,94,0.15); 
      color: #4ade80; 
      padding: 12px 16px; 
      border-radius: 14px; 
      margin-bottom: 16px; 
      font-size: 13px; 
      font-weight: 500; 
      border: 1px solid rgba(34,197,94,0.3);
    }
    .alert-info { 
      background: rgba(59,130,246,0.15); 
      color: #60a5fa; 
      padding: 12px 16px; 
      border-radius: 14px; 
      margin-bottom: 16px; 
      font-size: 13px; 
      border: 1px solid rgba(59,130,246,0.3);
    }
    .alert-error { 
      background: rgba(239,68,68,0.15); 
      color: #f87171; 
      padding: 12px 16px; 
      border-radius: 14px; 
      margin-bottom: 16px; 
      font-size: 13px; 
      border: 1px solid rgba(239,68,68,0.3);
    }

    /* Responsive Design */
    @media (max-width: 1100px) {
      .help-grid { grid-template-columns: repeat(2, 1fr); }
      .video-grid { grid-template-columns: repeat(2, 1fr); }
      .help-section-content { grid-template-columns: 1fr; }
    }

    @media (max-width: 768px) {
      .hero { padding: 60px 20px; }
      .hero h1 { font-size: 32px; }
      .search-section { padding: 40px 20px; }
      .content-section { padding: 40px 20px; }
      .help-grid { grid-template-columns: 1fr; }
      .help-section { padding: 28px; }
      .help-section h2 { font-size: 22px; }
      .video-section { padding: 28px; }
      .video-grid { grid-template-columns: 1fr; }
      .help-cta { padding: 32px 24px; }
      .help-cta h3 { font-size: 22px; }
      .cta-buttons { flex-direction: column; }
      .cta-button { width: 100%; justify-content: center; }
    }

    @media (max-width: 480px) {
      .hero { padding: 40px 16px; }
      .hero h1 { font-size: 28px; }
      .help-card { padding: 24px; }
      .help-card h3 { font-size: 18px; }
      .help-section { padding: 20px; }
      .help-item { padding: 18px; }
      .video-thumbnail { height: 160px; }
      .video-play-btn { width: 48px; height: 48px; font-size: 18px; }
    }
  </style>
</head>
<body>
  <!-- Include Navbar Component -->
  <?php require_once 'includes/navbar.php'; ?>

  <!-- HERO SECTION -->
  <section class="hero">
    <h1>Help Center</h1>
    <p>Everything you need to know about using SpaceNode</p>
  </section>

  <!-- SEARCH SECTION -->
  <section class="search-section">
    <div class="search-container">
      <h2><i class="fas fa-question-circle"></i> How can we help?</h2>
      <div class="search-box">
        <i class="fas fa-search"></i>
        <input type="text" placeholder="Search for help..." id="searchInput"/>
      </div>
    </div>
  </section>

  <!-- MAIN CONTENT -->
  <div class="content-section">
    
    <!-- Glassmorphism Quick Help Cards -->
    <div class="help-grid">
      <a href="#getting-started" class="help-card">
        <i class="fas fa-rocket"></i>
        <h3>Getting Started</h3>
        <p>Learn how to create an account and book your first parking space</p>
        <div class="help-card-arrow">
          Learn more <i class="fas fa-arrow-right"></i>
        </div>
      </a>

      <a href="#account-management" class="help-card">
        <i class="fas fa-user-cog"></i>
        <h3>Account Management</h3>
        <p>Manage your profile, settings, and preferences</p>
        <div class="help-card-arrow">
          Learn more <i class="fas fa-arrow-right"></i>
        </div>
      </a>

      <a href="#booking-help" class="help-card">
        <i class="fas fa-calendar-check"></i>
        <h3>Booking Help</h3>
        <p>Get help with searching, booking, and managing reservations</p>
        <div class="help-card-arrow">
          Learn more <i class="fas fa-arrow-right"></i>
        </div>
      </a>

      <a href="#payment-issues" class="help-card">
        <i class="fas fa-credit-card"></i>
        <h3>Payments & Billing</h3>
        <p>Understand billing, charges, and payment methods</p>
        <div class="help-card-arrow">
          Learn more <i class="fas fa-arrow-right"></i>
        </div>
      </a>

      <a href="#space-owner" class="help-card">
        <i class="fas fa-building"></i>
        <h3>For Space Owners</h3>
        <p>List spaces, manage earnings, and handle reservations</p>
        <div class="help-card-arrow">
          Learn more <i class="fas fa-arrow-right"></i>
        </div>
      </a>

      <a href="#troubleshooting" class="help-card">
        <i class="fas fa-wrench"></i>
        <h3>Troubleshooting</h3>
        <p>Solve common issues and technical problems</p>
        <div class="help-card-arrow">
          Learn more <i class="fas fa-arrow-right"></i>
        </div>
      </a>
    </div>

    <!-- Glassmorphism Getting Started Section -->
    <div class="help-section" id="getting-started">
      <h2><i class="fas fa-star"></i> Getting Started Guide</h2>
      <div class="help-section-content">
        <div class="help-item">
          <h3><span class="help-item-number">1</span> Create Your Account</h3>
          <p>Sign up with your email and basic information. Choose whether you want to be a Parker (booking spaces) or Space Owner (listing spaces).</p>
          <ul>
            <li>Visit the registration page</li>
            <li>Fill in your details</li>
            <li>Verify your email</li>
            <li>Complete your profile</li>
          </ul>
        </div>
        <div class="help-item">
          <h3><span class="help-item-number">2</span> Set Up Your Preferences</h3>
          <p>Configure your settings, payment methods, and notification preferences to customize your SpaceNode experience.</p>
          <ul>
            <li>Add a payment method</li>
            <li>Set notification preferences</li>
            <li>Add your vehicle information</li>
            <li>Enable location access</li>
          </ul>
        </div>
        <div class="help-item">
          <h3><span class="help-item-number">3</span> Find Your First Parking</h3>
          <p>Use our search feature to find available parking spaces near your location. Filter by price, amenities, and distance.</p>
          <ul>
            <li>Enter your location</li>
            <li>Select your dates and times</li>
            <li>Browse available spaces</li>
            <li>Read reviews and ratings</li>
          </ul>
        </div>
        <div class="help-item">
          <h3><span class="help-item-number">4</span> Complete Your Booking</h3>
          <p>Select a space and complete the booking process with our secure payment system. You'll receive instant confirmation.</p>
          <ul>
            <li>Choose your preferred space</li>
            <li>Review the details</li>
            <li>Complete payment</li>
            <li>Get your confirmation</li>
          </ul>
        </div>
      </div>
    </div>

    <!-- Glassmorphism Account Management Section -->
    <div class="help-section" id="account-management">
      <h2><i class="fas fa-user-shield"></i> Account Management</h2>
      <div class="help-section-content">
        <div class="help-item">
          <h3><i class="fas fa-user-edit"></i> Update Your Profile</h3>
          <p>Keep your profile information current to ensure smooth transactions and better service. You can update your name, contact details, and profile photo anytime from your account settings.</p>
        </div>
        <div class="help-item">
          <h3><i class="fas fa-credit-card"></i> Manage Payment Methods</h3>
          <p>Add, update, or remove payment methods in your account settings. We support credit cards, debit cards, digital wallets, and more for your convenience.</p>
        </div>
        <div class="help-item">
          <h3><i class="fas fa-key"></i> Change Your Password</h3>
          <p>For security, we recommend changing your password regularly. Go to Security Settings and follow the password change process to keep your account safe.</p>
        </div>
        <div class="help-item">
          <h3><i class="fas fa-chart-line"></i> Review Your Activity</h3>
          <p>Check your booking history, transactions, and account activity in your dashboard. All your information is securely stored and accessible anytime.</p>
        </div>
      </div>
    </div>

    <!-- Glassmorphism Booking Help Section -->
    <div class="help-section" id="booking-help">
      <h2><i class="fas fa-calendar-alt"></i> Booking Help & Support</h2>
      <div class="help-section-content">
        <div class="help-item">
          <h3><i class="fas fa-filter"></i> How to Search Effectively</h3>
          <p>Use filters to narrow down your search and find the perfect parking space. Filter by location, price range, amenities, and distance from your destination.</p>
        </div>
        <div class="help-item">
          <h3><i class="fas fa-info-circle"></i> Understanding Space Details</h3>
          <p>Each space listing includes photos, amenities, hourly/daily rates, operating hours, and reviews from other users. Check all details before booking.</p>
        </div>
        <div class="help-item">
          <h3><i class="fas fa-ban"></i> Cancellation Policy</h3>
          <p>Cancel free up to 30 minutes before your reservation. Cancellations after that time incur a 10% fee. Refunds are processed within 2-3 business days.</p>
        </div>
        <div class="help-item">
          <h3><i class="fas fa-edit"></i> Modify or Extend Bookings</h3>
          <p>Extend your booking if availability allows. To shorten, cancel and rebook. For major date changes, we recommend canceling and making a new reservation.</p>
        </div>
      </div>
    </div>

    <!-- Glassmorphism Payment Issues Section -->
    <div class="help-section" id="payment-issues">
      <h2><i class="fas fa-money-bill-wave"></i> Payments & Billing</h2>
      <div class="help-section-content">
        <div class="help-item">
          <h3><i class="fas fa-credit-card"></i> Payment Methods Accepted</h3>
          <p>We accept all major credit cards, debit cards, digital wallets (Apple Pay, Google Pay), bank transfers, and PayStack for mobile payments.</p>
        </div>
        <div class="help-item">
          <h3><i class="fas fa-receipt"></i> Understanding Your Bill</h3>
          <p>Your final bill includes: space rental + service fee (10-15%) + local taxes + any optional add-ons. All charges are shown before you confirm payment.</p>
        </div>
        <div class="help-item">
          <h3><i class="fas fa-exclamation-triangle"></i> Billing Issues & Disputes</h3>
          <p>If you notice a discrepancy, contact support immediately with your booking number. We'll review and resolve any billing issues promptly.</p>
        </div>
        <div class="help-item">
          <h3><i class="fas fa-undo-alt"></i> Refunds & Refund Status</h3>
          <p>Eligible refunds are processed within 2-3 business days. Track your refund status in the Transactions section of your account.</p>
        </div>
      </div>
    </div>

    <!-- Glassmorphism Space Owners Section -->
    <div class="help-section" id="space-owner">
      <h2><i class="fas fa-building"></i> For Space Owners</h2>
      <div class="help-section-content">
        <div class="help-item">
          <h3><i class="fas fa-plus-circle"></i> Listing Your Space</h3>
          <p>Create a compelling listing with photos, accurate address, amenities, pricing, and operating hours. Clear photos and detailed descriptions get more bookings!</p>
        </div>
        <div class="help-item">
          <h3><i class="fas fa-chart-simple"></i> Pricing Strategies</h3>
          <p>Set competitive prices based on location, demand, and amenities. Monitor competitor pricing and adjust dynamically for better earnings.</p>
        </div>
        <div class="help-item">
          <h3><i class="fas fa-calendar-week"></i> Managing Reservations</h3>
          <p>Accept or reject booking requests, set availability, and manage your calendar from your owner dashboard. You have full control over your space.</p>
        </div>
        <div class="help-item">
          <h3><i class="fas fa-chart-line"></i> Tracking Earnings & Payouts</h3>
          <p>Monitor your earnings in real-time. We pay out weekly to your bank account. Minimum payout threshold is ₦100.</p>
        </div>
      </div>
    </div>

    <!-- Glassmorphism Troubleshooting Section -->
    <div class="help-section" id="troubleshooting">
      <h2><i class="fas fa-wrench"></i> Troubleshooting & Technical Help</h2>
      <div class="help-section-content">
        <div class="help-item">
          <h3><i class="fas fa-sign-in-alt"></i> Can't Log In?</h3>
          <p>Try clearing your browser cache, using a different browser, or resetting your password. Ensure your email is verified and you're using the correct credentials.</p>
        </div>
        <div class="help-item">
          <h3><i class="fas fa-mobile-alt"></i> App Performance Issues</h3>
          <p>Update to the latest app version, clear cache, or reinstall if problems persist. Check your internet connection and device storage.</p>
        </div>
        <div class="help-item">
          <h3><i class="fas fa-credit-card"></i> Payment Not Going Through</h3>
          <p>Verify your card details, check expiration date, ensure sufficient funds, and try a different payment method. Contact your bank if issues continue.</p>
        </div>
        <div class="help-item">
          <h3><i class="fas fa-map-marker-alt"></i> Location Not Showing Correctly</h3>
          <p>Enable location permissions in your phone settings, ensure GPS is turned on, and refresh the app. Try rebooting your device.</p>
        </div>
      </div>
    </div>

    <!-- Glassmorphism Video Tutorials -->
    <div class="video-section">
      <h2><i class="fas fa-video"></i> Video Tutorials</h2>
      <div class="video-grid">
        <div class="video-card">
          <div class="video-thumbnail">
            <div class="video-play-btn"><i class="fas fa-play"></i></div>
          </div>
          <div class="video-info">
            <h4>Getting Started with SpaceNode</h4>
            <p><i class="far fa-clock"></i> 5 min • Beginner</p>
          </div>
        </div>
        <div class="video-card">
          <div class="video-thumbnail">
            <div class="video-play-btn"><i class="fas fa-play"></i></div>
          </div>
          <div class="video-info">
            <h4>How to Book a Parking Space</h4>
            <p><i class="far fa-clock"></i> 7 min • Beginner</p>
          </div>
        </div>
        <div class="video-card">
          <div class="video-thumbnail">
            <div class="video-play-btn"><i class="fas fa-play"></i></div>
          </div>
          <div class="video-info">
            <h4>List Your Space & Earn Money</h4>
            <p><i class="far fa-clock"></i> 10 min • Intermediate</p>
          </div>
        </div>
      </div>
    </div>

    <!-- Glassmorphism CTA Section -->
    <div class="help-cta">
      <h3><i class="fas fa-headset"></i> Still need help?</h3>
      <p>Can't find the answer you're looking for? Our dedicated support team is available 24/7 to help you get the most out of SpaceNode.</p>
      <div class="cta-buttons">
        <a href="contact.php" class="cta-button primary"><i class="fas fa-envelope"></i> Contact Support</a>
        <a href="faq.php" class="cta-button secondary"><i class="fas fa-question-circle"></i> View FAQ</a>
      </div>
    </div>

  </div>

  <!-- Include Footer Component -->
  <?php require_once 'includes/footer.php'; ?>

  <script>
    // Search functionality
    const searchInput = document.getElementById('searchInput');
    
    if (searchInput) {
      searchInput.addEventListener('keyup', (e) => {
        const searchTerm = e.target.value.toLowerCase();
        const cards = document.querySelectorAll('.help-card');
        const sections = document.querySelectorAll('.help-section');
        
        cards.forEach(card => {
          const text = card.textContent.toLowerCase();
          card.style.display = text.includes(searchTerm) ? 'flex' : 'none';
        });
        
        sections.forEach(section => {
          const text = section.textContent.toLowerCase();
          section.style.display = text.includes(searchTerm) ? 'block' : 'none';
        });
      });
    }

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