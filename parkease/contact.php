<?php
session_start();
require_once 'includes/user-access.php';
redirectOwnersFromPublicPages();
require_once 'config/database.php';
require_once 'config/team-image.php';
require_once 'includes/email-functions.php'; // For sending email notifications

// Function to sanitize output
function sanitize($input) {
    return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
}

// Get database connection
$database = new Database();
$db = $database->getConnection();

$message = '';
$error = '';

// Handle newsletter subscription for footer
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

$rate_limit_key = 'contact_submit_' . ($_SESSION['user_id'] ?? $_SERVER['REMOTE_ADDR']);

if (isset($_SESSION[$rate_limit_key]) && $_SESSION[$rate_limit_key] > time() - 300) {
    $error = 'Please wait 5 minutes before sending another message.';
}

// Handle contact form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['contact_submit']) && empty($error)) {
    // CSRF protection (optional but recommended)
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        // Sanitize and validate inputs
        $name = isset($_POST['name']) ? trim($_POST['name']) : '';
        $email = isset($_POST['email']) ? trim($_POST['email']) : '';
        $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
        $subject = isset($_POST['subject']) ? trim($_POST['subject']) : '';
        $message_text = isset($_POST['message']) ? trim($_POST['message']) : '';
        
        // Validate required fields
        $errors = [];
        
        if (empty($name)) {
            $errors[] = 'Name is required.';
        } elseif (strlen($name) > 100) {
            $errors[] = 'Name cannot exceed 100 characters.';
        }
        
        if (empty($email)) {
            $errors[] = 'Email is required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        } elseif (strlen($email) > 100) {
            $errors[] = 'Email cannot exceed 100 characters.';
        }
        
        if (!empty($phone) && !preg_match('/^[0-9+\-\s()]+$/', $phone)) {
            $errors[] = 'Please enter a valid phone number.';
        }
        
        if (empty($subject)) {
            $errors[] = 'Subject is required.';
        } elseif (strlen($subject) > 200) {
            $errors[] = 'Subject cannot exceed 200 characters.';
        }
        
        if (empty($message_text)) {
            $errors[] = 'Message is required.';
        } elseif (strlen($message_text) > 5000) {
            $errors[] = 'Message cannot exceed 5000 characters.';
        }
        
        if (empty($errors)) {
            try {
                // Check if contact_messages table exists, create if not
                $table_check = $db->query("SHOW TABLES LIKE 'contact_messages'");
                if ($table_check->rowCount() == 0) {
                    $create_table = "CREATE TABLE IF NOT EXISTS contact_messages (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        name VARCHAR(100) NOT NULL,
                        email VARCHAR(100) NOT NULL,
                        phone VARCHAR(50),
                        subject VARCHAR(200) NOT NULL,
                        message TEXT NOT NULL,
                        user_id INT NULL,
                        ip_address VARCHAR(45),
                        status ENUM('unread', 'read', 'replied') DEFAULT 'unread',
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
                    )";
                    $db->exec($create_table);
                }
                
                // Save to database
                $ip_address = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                $user_id = $_SESSION['user_id'] ?? null;
                
                $insert_query = "INSERT INTO contact_messages 
                                (name, email, phone, subject, message, user_id, ip_address, created_at) 
                                VALUES 
                                (:name, :email, :phone, :subject, :message, :user_id, :ip, NOW())";
                
                $insert_stmt = $db->prepare($insert_query);
                $insert_stmt->bindParam(':name', $name, PDO::PARAM_STR);
                $insert_stmt->bindParam(':email', $email, PDO::PARAM_STR);
                $insert_stmt->bindParam(':phone', $phone, PDO::PARAM_STR);
                $insert_stmt->bindParam(':subject', $subject, PDO::PARAM_STR);
                $insert_stmt->bindParam(':message', $message_text, PDO::PARAM_STR);
                $insert_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
                $insert_stmt->bindParam(':ip', $ip_address, PDO::PARAM_STR);
                $insert_stmt->execute();
                
                $message_id = $db->lastInsertId();
                
                // Send email notification to admin
                $admin_email = 'spacenode.support@gmail.com'; // Change to your admin email
                $email_subject = "New Contact Form Message: $subject";
                
                $email_body = "
                <html>
                <head>
                    <style>
                        body { font-family: Arial, sans-serif; line-height: 1.6; }
                        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                        .header { background: #4F6EF7; color: white; padding: 20px; text-align: center; }
                        .content { background: #f9fafb; padding: 20px; }
                        .details { background: white; padding: 15px; border-radius: 8px; margin: 15px 0; }
                    </style>
                </head>
                <body>
                    <div class='container'>
                        <div class='header'>
                            <h2>New Contact Form Submission</h2>
                        </div>
                        <div class='content'>
                            <div class='details'>
                                <p><strong>From:</strong> " . sanitize($name) . " &lt;" . sanitize($email) . "&gt;</p>
                                <p><strong>Phone:</strong> " . (empty($phone) ? 'Not provided' : sanitize($phone)) . "</p>
                                <p><strong>Subject:</strong> " . sanitize($subject) . "</p>
                                <p><strong>Message:</strong></p>
                                <p>" . nl2br(sanitize($message_text)) . "</p>
                                " . ($user_id ? "<p><strong>User ID:</strong> $user_id</p>" : "") . "
                                <p><strong>IP Address:</strong> $ip_address</p>
                                <p><strong>Submitted:</strong> " . date('Y-m-d H:i:s') . "</p>
                            </div>
                            <p>Reply to: <a href='mailto:" . sanitize($email) . "'>" . sanitize($email) . "</a></p>
                        </div>
                    </div>
                </body>
                </html>";
                
                // Use PHPMailer if available
                if (class_exists('EmailNotifications')) {
                    try {
                        $mailer = new EmailNotifications($db);
                        $mail = EmailConfig::getMailer();
                        $mail->addAddress($admin_email, 'SpaceNode Admin');
                        $mail->Subject = $email_subject;
                        $mail->isHTML(true);
                        $mail->Body = $email_body;
                        $mail->AltBody = strip_tags(str_replace(['<br>', '</p>'], ["\n", "\n\n"], $email_body));
                        $mail->send();
                    } catch (Exception $e) {
                        error_log("Failed to send contact email: " . $e->getMessage());
                        // Don't fail the submission if email fails
                    }
                }
                
                // Set rate limit
                $_SESSION[$rate_limit_key] = time();
                
                $message = 'Thank you for your message! We\'ll get back to you soon.';
                
                // Clear form after successful submission
                $name = $email = $phone = $subject = $message_text = '';
                
                // Log the action
                error_log("Contact form submission saved: ID $message_id from " . ($user_id ? "user $user_id" : $ip_address));
                
            } catch (PDOException $e) {
                error_log("Contact form database error: " . $e->getMessage());
                $error = 'Sorry, we couldn\'t save your message. Please try again later.';
            }
        } else {
            $error = implode('<br>', $errors);
        }
    }
}

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes"/>
  <meta name="description" content="Contact SpaceNode customer support. Get help with parking reservations, account issues, or general inquiries.">
  <meta name="robots" content="index, follow">
  <title>Contact Us - SpaceNode</title>
  
  <!-- Include all CSS assets (navbar, footer, global styles) -->
  <?php require_once 'includes/header-assets.php'; ?>
  
  <style>
    /* ============================================
       PAGE-SPECIFIC STYLES (Contact Page with Heavy Glassmorphism)
       These are separate from navbar/footer styles
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

    /* Content Sections */
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
      margin-bottom: 32px;
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

    /* Contact Grid */
    .contact-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 48px;
      margin-bottom: 64px;
    }

    /* Glassmorphism Contact Info Cards */
    .contact-info {
      display: flex;
      flex-direction: column;
      gap: 28px;
    }

    .contact-item {
      display: flex;
      gap: 20px;
      background: rgba(255,255,255,0.75);
      backdrop-filter: blur(20px) saturate(180%);
      -webkit-backdrop-filter: blur(20px);
      border: 1px solid rgba(255,255,255,0.6);
      border-radius: 24px;
      padding: 24px;
      transition: all 0.4s cubic-bezier(0.4,0,0.2,1);
      box-shadow: 0 4px 16px rgba(0,0,0,0.05), inset 0 1px 0 rgba(255,255,255,0.8);
    }

    .contact-item:hover {
      transform: translateY(-5px);
      background: rgba(255,255,255,0.88);
      backdrop-filter: blur(32px) saturate(220%);
      box-shadow: 0 20px 40px rgba(79,110,247,0.15);
      border-color: rgba(255,255,255,0.9);
    }

    .contact-icon {
      width: 56px;
      height: 56px;
      background: linear-gradient(135deg, rgba(79,110,247,0.15), rgba(124,58,237,0.15));
      border-radius: 18px;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
      transition: all 0.3s;
    }

    .contact-item:hover .contact-icon {
      background: linear-gradient(135deg, var(--blue), var(--purple));
    }

    .contact-icon svg {
      width: 26px;
      height: 26px;
      stroke: var(--blue);
      stroke-width: 2;
      fill: none;
      transition: all 0.3s;
    }

    .contact-item:hover .contact-icon svg {
      stroke: white;
    }

    .contact-content h3 {
      font-family: var(--font-display);
      font-size: 18px;
      font-weight: 700;
      color: var(--text);
      margin-bottom: 8px;
      letter-spacing: -0.3px;
    }

    .contact-content p {
      font-size: 14px;
      color: var(--muted);
      line-height: 1.7;
    }

    .contact-content a {
      color: var(--blue);
      text-decoration: none;
      transition: color 0.2s;
    }

    .contact-content a:hover {
      color: var(--purple);
      text-decoration: underline;
    }

    /* Glassmorphism Contact Form */
    .contact-form {
      background: rgba(255,255,255,0.75);
      backdrop-filter: blur(20px) saturate(180%);
      -webkit-backdrop-filter: blur(20px);
      border: 1px solid rgba(255,255,255,0.6);
      border-radius: 28px;
      padding: 40px;
      box-shadow: 0 8px 32px rgba(0,0,0,0.08), inset 0 1px 0 rgba(255,255,255,0.8);
      transition: all 0.3s ease;
    }

    .contact-form:hover {
      box-shadow: 0 20px 48px rgba(79,110,247,0.12);
    }

    .contact-form h2 {
      font-family: var(--font-display);
      font-size: 26px;
      font-weight: 700;
      color: var(--text);
      margin-bottom: 28px;
      letter-spacing: -0.5px;
    }

    .form-group {
      margin-bottom: 20px;
    }

    .form-row {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 20px;
    }

    .form-row .form-group {
      margin-bottom: 0;
    }

    .form-group label {
      display: block;
      font-size: 13px;
      font-weight: 600;
      color: var(--text);
      margin-bottom: 8px;
    }

    .form-group input,
    .form-group textarea {
      width: 100%;
      padding: 14px 16px;
      border: 1px solid rgba(229,231,235,0.7);
      border-radius: 16px;
      font-size: 14px;
      font-family: var(--font-body);
      transition: all 0.3s;
      background: rgba(255,255,255,0.9);
      color: var(--text);
    }

    .form-group input:focus,
    .form-group textarea:focus {
      outline: none;
      border-color: var(--blue);
      box-shadow: 0 0 0 4px rgba(79,110,247,0.1);
      background: white;
    }

    .form-group input::placeholder,
    .form-group textarea::placeholder {
      color: var(--muted);
    }

    .form-group textarea {
      min-height: 130px;
      resize: vertical;
    }

    .form-button {
      background: linear-gradient(135deg, var(--blue), var(--purple));
      color: #fff;
      border: none;
      padding: 16px 32px;
      border-radius: 50px;
      font-size: 15px;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.3s;
      width: 100%;
      font-family: var(--font-display);
      box-shadow: 0 4px 18px rgba(79,110,247,0.32);
    }

    .form-button:hover {
      transform: translateY(-2px);
      box-shadow: 0 10px 28px rgba(79,110,247,0.42);
    }

    .form-message {
      padding: 14px 18px;
      border-radius: 16px;
      margin-bottom: 24px;
      font-size: 14px;
      font-weight: 500;
    }

    .form-message.success {
      background: rgba(34, 197, 94, 0.12);
      color: #15803d;
      border: 1px solid rgba(34, 197, 94, 0.3);
    }

    .form-message.error {
      background: rgba(239, 68, 68, 0.12);
      color: #b91c1c;
      border: 1px solid rgba(239, 68, 68, 0.3);
    }

    /* Glassmorphism FAQ Grid */
    .faq-grid {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 24px;
      margin-top: 48px;
    }

    .faq-item {
      background: rgba(255,255,255,0.75);
      backdrop-filter: blur(20px) saturate(180%);
      -webkit-backdrop-filter: blur(20px);
      border: 1px solid rgba(255,255,255,0.6);
      border-radius: 24px;
      padding: 28px;
      transition: all 0.4s cubic-bezier(0.4,0,0.2,1);
      box-shadow: 0 4px 16px rgba(0,0,0,0.05), inset 0 1px 0 rgba(255,255,255,0.8);
    }

    .faq-item:hover {
      transform: translateY(-5px);
      background: rgba(255,255,255,0.88);
      backdrop-filter: blur(32px) saturate(220%);
      box-shadow: 0 20px 40px rgba(79,110,247,0.12);
      border-color: rgba(79,110,247,0.3);
    }

    .faq-item h4 {
      font-family: var(--font-display);
      font-size: 17px;
      font-weight: 700;
      color: var(--text);
      margin-bottom: 10px;
      letter-spacing: -0.3px;
    }

    .faq-item p {
      font-size: 14px;
      color: var(--muted);
      line-height: 1.7;
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
      .contact-grid { gap: 32px; }
      .contact-form { padding: 32px; }
    }

    @media (max-width: 768px) {
      .hero { padding: 60px 20px; }
      .hero h1 { font-size: 32px; }
      .hero p { font-size: 16px; }
      
      .content-section { padding: 40px 20px; }
      .section-title { font-size: 28px; }
      .section-subtitle { font-size: 15px; }
      
      .contact-grid {
        grid-template-columns: 1fr;
        gap: 32px;
      }
      
      .contact-form {
        padding: 28px;
      }
      
      .contact-form h2 {
        font-size: 24px;
      }
      
      .form-row {
        grid-template-columns: 1fr;
        gap: 16px;
      }
      
      .faq-grid {
        grid-template-columns: 1fr;
        gap: 20px;
      }
      
      .contact-item {
        padding: 20px;
      }
      
      .contact-icon {
        width: 48px;
        height: 48px;
      }
      
      .contact-icon svg {
        width: 22px;
        height: 22px;
      }
    }

    @media (max-width: 480px) {
      .hero { padding: 40px 16px; }
      .hero h1 { font-size: 28px; }
      .hero p { font-size: 14px; }
      
      .content-section { padding: 32px 16px; }
      .section-title { font-size: 24px; }
      .section-subtitle { font-size: 13px; margin-bottom: 32px; }
      
      .contact-form { padding: 20px; }
      .contact-form h2 { font-size: 22px; }
      
      .contact-item { padding: 16px; gap: 14px; }
      .contact-icon { width: 44px; height: 44px; }
      .contact-content h3 { font-size: 16px; }
      .contact-content p { font-size: 13px; }
      
      .faq-item { padding: 20px; }
      .faq-item h4 { font-size: 16px; }
      .faq-item p { font-size: 13px; }
      
      .form-group input,
      .form-group textarea {
        padding: 12px 14px;
        font-size: 13px;
      }
      
      .form-button {
        padding: 14px 28px;
        font-size: 14px;
      }
    }
  </style>
</head>
<body>
  <!-- Include Navbar Component -->
  <?php require_once 'includes/navbar.php'; ?>

  <!-- HERO SECTION with Glassmorphism -->
  <section class="hero">
    <h1>Get in Touch</h1>
    <p>Have questions? We'd love to hear from you. Send us a message and we'll respond as soon as possible.</p>
  </section>

  <!-- MAIN CONTENT -->
  <div class="content-section">
    <section>
      <div class="contact-grid">
        <!-- Contact Information - Glassmorphism Cards -->
        <div class="contact-info">
          <div class="contact-item">
            <div class="contact-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
                <circle cx="12" cy="10" r="3"/>
              </svg>
            </div>
            <div class="contact-content">
              <h3>Address</h3>
              <p>123 Parking Avenue<br>New York, NY 10001<br>United States</p>
            </div>
          </div>

          <div class="contact-item">
            <div class="contact-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.61 3.4 2 2 0 0 1 3.6 1.22h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.84a16 16 0 0 0 6.29 6.29l.95-.95a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/>
              </svg>
            </div>
            <div class="contact-content">
              <h3>Phone</h3>
              <p><a href="tel:1-800-SpaceNode">1-800-SpaceNode</a><br>(1-800-727-5327)</p>
            </div>
          </div>

          <div class="contact-item">
            <div class="contact-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                <polyline points="22,6 12,13 2,6"/>
              </svg>
            </div>
            <div class="contact-content">
              <h3>Email</h3>
              <p><a href="mailto:support@spacenode.com">support@spacenode.com</a><br><a href="mailto:info@spacenode.com">info@spacenode.com</a></p>
            </div>
          </div>

          <div class="contact-item">
            <div class="contact-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="1"/>
                <path d="M12 1v6m0 6v6M4.22 4.22l4.24 4.24m3.08 3.08l4.24 4.24M1 12h6m6 0h6M4.22 19.78l4.24-4.24m3.08-3.08l4.24-4.24"/>
              </svg>
            </div>
            <div class="contact-content">
              <h3>Business Hours</h3>
              <p>Monday - Friday: 9:00 AM - 6:00 PM<br>Saturday: 10:00 AM - 4:00 PM<br>Sunday: Closed</p>
            </div>
          </div>
        </div>

        <!-- Glassmorphism Contact Form -->
        <div class="contact-form">
          <h2>Send us a Message</h2>
          
          <?php if ($message): ?>
            <div class="form-message success"><?php echo sanitize($message); ?></div>
          <?php endif; ?>
          
          <?php if ($error): ?>
            <div class="form-message error"><?php echo $error; ?></div>
          <?php endif; ?>

          <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            
            <div class="form-row">
              <div class="form-group">
                <label for="name">Your Name *</label>
                <input type="text" id="name" name="name" placeholder="Your Name" required maxlength="100" value="<?php echo isset($name) ? sanitize($name) : ''; ?>">
              </div>
              <div class="form-group">
                <label for="email">Email Address *</label>
                <input type="email" id="email" name="email" placeholder="you@example.com" required maxlength="100" value="<?php echo isset($email) ? sanitize($email) : ''; ?>">
              </div>
            </div>

            <div class="form-row">
              <div class="form-group">
                <label for="phone">Phone Number</label>
                <input type="tel" id="phone" name="phone" placeholder="(123) 456-7890" maxlength="50" value="<?php echo isset($phone) ? sanitize($phone) : ''; ?>">
              </div>
              <div class="form-group">
                <label for="subject">Subject *</label>
                <input type="text" id="subject" name="subject" placeholder="How can we help?" required maxlength="200" value="<?php echo isset($subject) ? sanitize($subject) : ''; ?>">
              </div>
            </div>

            <div class="form-group">
              <label for="message">Message *</label>
              <textarea id="message" name="message" placeholder="Tell us more about your inquiry..." required maxlength="5000"><?php echo isset($message_text) ? sanitize($message_text) : ''; ?></textarea>
            </div>

            <button type="submit" name="contact_submit" class="form-button">Send Message</button>
          </form>
        </div>
      </div>
    </section>

    <!-- Glassmorphism FAQ Section -->
    <section id="faq">
      <h2 class="section-title">Frequently Asked Questions</h2>
      <p class="section-subtitle">Find quick answers to common questions</p>
      
      <div class="faq-grid">
        <div class="faq-item">
          <h4>How do I book a parking space?</h4>
          <p>Simply download the SpaceNode app, create an account, find available spaces near your location, and book instantly. You'll receive real-time updates about your reservation.</p>
        </div>
        <div class="faq-item">
          <h4>What payment methods do you accept?</h4>
          <p>We accept all major credit cards, debit cards, digital wallets, and bank transfers. All transactions are secure and encrypted.</p>
        </div>
        <div class="faq-item">
          <h4>Can I cancel my reservation?</h4>
          <p>Yes! You can cancel up to 30 minutes before your reservation starts for a full refund. Cancellations after that may incur a small fee.</p>
        </div>
        <div class="faq-item">
          <h4>How do I list my parking space?</h4>
          <p>If you have extra parking space, visit our "Add Parking" page, fill in your space details, set your pricing, and start earning money.</p>
        </div>
        <div class="faq-item">
          <h4>Is my payment information safe?</h4>
          <p>Absolutely! We use industry-leading encryption and comply with all PCI-DSS security standards to protect your information.</p>
        </div>
        <div class="faq-item">
          <h4>What if I have an issue with my booking?</h4>
          <p>Contact our 24/7 support team via email, phone, or chat. We're here to help and typically respond within 1 hour.</p>
        </div>
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