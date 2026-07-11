<?php
session_start();
require_once 'includes/user-access.php';
redirectOwnersFromPublicPages();
require_once 'config/database.php';

// Function to sanitize output
function sanitize($input) {
    return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
}

// Get database connection (optional - for future FAQ management)
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

$page_title = 'Frequently Asked Questions - SpaceNode';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes"/>
    <meta name="description" content="Find answers to frequently asked questions about SpaceNode parking services, booking, payments, and support.">
    <meta name="keywords" content="SpaceNode, FAQ, parking questions, booking help, support">
    <meta name="robots" content="index, follow">
    <title>FAQ - SpaceNode</title>
    
    <!-- Include all CSS assets (navbar, footer, global styles) -->
    <?php require_once 'includes/header-assets.php'; ?>
    
    <style>
        /* ============================================
           PAGE-SPECIFIC STYLES (FAQ Page with Heavy Glassmorphism)
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

        /* FAQ Container */
        .faq-container {
            max-width: 1000px;
            margin: 0 auto;
        }

        /* FAQ Category */
        .faq-category {
            margin-bottom: 56px;
        }

        .faq-category-title {
            font-family: var(--font-display);
            font-size: 26px;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 28px;
            padding-bottom: 12px;
            display: inline-block;
            background: linear-gradient(135deg, var(--blue), var(--purple));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            border-bottom: 3px solid transparent;
            border-image: linear-gradient(135deg, var(--blue), var(--purple));
            border-image-slice: 1;
        }

        /* Glassmorphism Accordion */
        .faq-accordion {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .accordion-item {
            background: rgba(255,255,255,0.75);
            backdrop-filter: blur(20px) saturate(180%);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.6);
            border-radius: 24px;
            overflow: hidden;
            transition: all 0.4s cubic-bezier(0.4,0,0.2,1);
            box-shadow: 0 4px 16px rgba(0,0,0,0.05), inset 0 1px 0 rgba(255,255,255,0.8);
        }

        .accordion-item:hover {
            border-color: rgba(79,110,247,0.4);
            box-shadow: 0 8px 28px rgba(79,110,247,0.12);
            transform: translateY(-2px);
        }

        .accordion-header {
            padding: 22px 28px;
            background: transparent;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.3s;
        }

        .accordion-item.active .accordion-header {
            background: rgba(79,110,247,0.06);
            border-bottom: 1px solid rgba(79,110,247,0.15);
        }

        .accordion-header h3 {
            font-family: var(--font-display);
            font-size: 17px;
            font-weight: 600;
            color: var(--text);
            margin: 0;
            letter-spacing: -0.2px;
        }

        .accordion-icon {
            width: 28px;
            height: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, var(--blue), var(--purple));
            border-radius: 50%;
            color: white;
            font-weight: bold;
            font-size: 14px;
            transition: transform 0.3s ease;
            box-shadow: 0 2px 8px rgba(79,110,247,0.3);
        }

        .accordion-item.active .accordion-icon {
            transform: rotate(180deg);
        }

        .accordion-content {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.4s cubic-bezier(0.4,0,0.2,1);
        }

        .accordion-item.active .accordion-content {
            max-height: 600px;
        }

        .accordion-body {
            padding: 0 28px 24px 28px;
            font-size: 15px;
            color: var(--muted);
            line-height: 1.7;
        }

        .accordion-body p {
            margin-bottom: 12px;
        }

        .accordion-body p:last-child {
            margin-bottom: 0;
        }

        .accordion-body ul {
            margin: 12px 0;
            padding-left: 24px;
        }

        .accordion-body li {
            margin-bottom: 8px;
        }

        .accordion-body a {
            color: var(--blue);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.2s;
        }

        .accordion-body a:hover {
            color: var(--purple);
            text-decoration: underline;
        }

        /* Glassmorphism CTA Section */
        .faq-cta {
            background: rgba(255,255,255,0.75);
            backdrop-filter: blur(20px) saturate(180%);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.6);
            border-radius: 32px;
            padding: 48px;
            text-align: center;
            margin-top: 48px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.08), inset 0 1px 0 rgba(255,255,255,0.8);
            transition: all 0.3s ease;
        }

        .faq-cta:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 48px rgba(79,110,247,0.15);
        }

        .faq-cta h3 {
            font-family: var(--font-display);
            font-size: 24px;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 12px;
            letter-spacing: -0.5px;
        }

        .faq-cta p {
            font-size: 16px;
            color: var(--muted);
            margin-bottom: 24px;
            line-height: 1.6;
        }

        .cta-button {
            display: inline-block;
            background: linear-gradient(135deg, var(--blue), var(--purple));
            color: white;
            padding: 14px 36px;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            font-family: var(--font-display);
            transition: all 0.3s;
            box-shadow: 0 4px 18px rgba(79,110,247,0.32);
        }

        .cta-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 28px rgba(79,110,247,0.42);
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
            .faq-category-title { font-size: 24px; }
            .accordion-header { padding: 18px 24px; }
            .accordion-header h3 { font-size: 16px; }
            .accordion-body { padding: 0 24px 20px 24px; font-size: 14px; }
        }

        @media (max-width: 768px) {
            .hero { padding: 60px 20px; }
            .hero h1 { font-size: 32px; }
            .hero p { font-size: 16px; }
            
            .content-section { padding: 40px 20px; }
            .section-title { font-size: 28px; }
            .section-subtitle { font-size: 15px; }
            
            .faq-category-title { font-size: 22px; margin-bottom: 20px; }
            .faq-accordion { gap: 12px; }
            
            .accordion-header { padding: 16px 20px; }
            .accordion-header h3 { font-size: 15px; }
            .accordion-body { padding: 0 20px 16px 20px; font-size: 13px; }
            
            .faq-cta { padding: 32px 24px; }
            .faq-cta h3 { font-size: 20px; }
            .faq-cta p { font-size: 14px; }
            .cta-button { padding: 12px 28px; font-size: 13px; }
        }

        @media (max-width: 480px) {
            .hero { padding: 40px 16px; }
            .hero h1 { font-size: 28px; }
            .hero p { font-size: 14px; }
            
            .content-section { padding: 32px 16px; }
            .section-title { font-size: 24px; }
            .section-subtitle { font-size: 13px; margin-bottom: 32px; }
            
            .faq-category-title { font-size: 20px; margin-bottom: 16px; }
            
            .accordion-header { padding: 14px 16px; }
            .accordion-header h3 { font-size: 14px; }
            .accordion-body { padding: 0 16px 14px 16px; font-size: 12px; }
            .accordion-icon { width: 24px; height: 24px; font-size: 12px; }
            
            .faq-cta { padding: 24px 16px; }
            .faq-cta h3 { font-size: 18px; }
            .cta-button { padding: 10px 24px; }
        }
    </style>
</head>
<body>
    <!-- Include Navbar Component -->
    <?php require_once 'includes/navbar.php'; ?>

    <!-- HERO SECTION with Glassmorphism -->
    <section class="hero">
        <h1>Frequently Asked Questions</h1>
        <p>Find answers to common questions about SpaceNode parking services</p>
    </section>

    <!-- MAIN CONTENT -->
    <div class="content-section">
        <div class="faq-container">
            
            <!-- Getting Started Section -->
            <section class="faq-category">
                <h2 class="faq-category-title">📌 Getting Started</h2>
                <div class="faq-accordion">
                    
                    <div class="accordion-item">
                        <div class="accordion-header">
                            <h3>How do I create an account on SpaceNode?</h3>
                            <span class="accordion-icon">▼</span>
                        </div>
                        <div class="accordion-content">
                            <div class="accordion-body">
                                <p>Creating an account is quick and easy! Visit our <a href="register.php">registration page</a>, enter your email address, create a secure password, and provide basic information. You'll receive a verification email to confirm your account. Once verified, you can start booking parking spaces immediately.</p>
                            </div>
                        </div>
                    </div>

                    <div class="accordion-item">
                        <div class="accordion-header">
                            <h3>What information do I need to provide to sign up?</h3>
                            <span class="accordion-icon">▼</span>
                        </div>
                        <div class="accordion-content">
                            <div class="accordion-body">
                                <p>We require the following information:</p>
                                <ul>
                                    <li>Full name</li>
                                    <li>Email address</li>
                                    <li>Phone number</li>
                                    <li>Password</li>
                                    <li>User type (Parker or Space Owner)</li>
                                </ul>
                                <p>All information is kept secure and encrypted according to industry standards.</p>
                            </div>
                        </div>
                    </div>

                    <div class="accordion-item">
                        <div class="accordion-header">
                            <h3>Is SpaceNode free to use?</h3>
                            <span class="accordion-icon">▼</span>
                        </div>
                        <div class="accordion-content">
                            <div class="accordion-body">
                                <p>Yes! SpaceNode is completely free to download and use. You only pay when you book a parking space, and there are no hidden fees.</p>
                            </div>
                        </div>
                    </div>

                </div>
            </section>

            <!-- Booking & Reservations -->
            <section class="faq-category">
                <h2 class="faq-category-title">📅 Booking & Reservations</h2>
                <div class="faq-accordion">
                    
                    <div class="accordion-item">
                        <div class="accordion-header">
                            <h3>How do I book a parking space?</h3>
                            <span class="accordion-icon">▼</span>
                        </div>
                        <div class="accordion-content">
                            <div class="accordion-body">
                                <p>Booking is simple in just 3 steps:</p>
                                <ul>
                                    <li><strong>Search:</strong> Enter your location and dates to find available spaces</li>
                                    <li><strong>Select:</strong> Choose your preferred space based on price, location, and amenities</li>
                                    <li><strong>Confirm:</strong> Complete payment and receive instant confirmation</li>
                                </ul>
                                <p>You'll receive a confirmation with the space address, access instructions, and the space owner's contact details.</p>
                            </div>
                        </div>
                    </div>

                    <div class="accordion-item">
                        <div class="accordion-header">
                            <h3>Can I cancel my reservation?</h3>
                            <span class="accordion-icon">▼</span>
                        </div>
                        <div class="accordion-content">
                            <div class="accordion-body">
                                <p><strong>Yes, you can cancel for free up to 30 minutes before your reservation starts.</strong> If you cancel after that, a small cancellation fee (10% of the booking cost) will apply. To cancel, go to "My Reservations" and click "Cancel Booking".</p>
                                <p>Your refund will be processed within 2-3 business days. We recommend canceling as soon as possible to avoid any fees.</p>
                            </div>
                        </div>
                    </div>

                    <div class="accordion-item">
                        <div class="accordion-header">
                            <h3>Can I modify my booking?</h3>
                            <span class="accordion-icon">▼</span>
                        </div>
                        <div class="accordion-content">
                            <div class="accordion-body">
                                <p>You can extend your booking directly from the app if availability allows. To shorten your booking, cancel and rebook for the new duration. If you need to change dates significantly, we recommend canceling and making a new reservation for your preferred dates.</p>
                            </div>
                        </div>
                    </div>

                    <div class="accordion-item">
                        <div class="accordion-header">
                            <h3>What if there are no spaces available at my location?</h3>
                            <span class="accordion-icon">▼</span>
                        </div>
                        <div class="accordion-content">
                            <div class="accordion-body">
                                <p>If spaces aren't available at your first choice location, try:</p>
                                <ul>
                                    <li>Searching nearby areas (we'll show you options within a radius)</li>
                                    <li>Changing your dates or times</li>
                                    <li>Using our "Find Nearest" feature to discover available spaces close to you</li>
                                </ul>
                                <p>You can also set up notifications to be alerted when spaces become available at your preferred location.</p>
                            </div>
                        </div>
                    </div>

                </div>
            </section>

            <!-- Payments & Pricing -->
            <section class="faq-category">
                <h2 class="faq-category-title">💰 Payments & Pricing</h2>
                <div class="faq-accordion">
                    
                    <div class="accordion-item">
                        <div class="accordion-header">
                            <h3>What payment methods do you accept?</h3>
                            <span class="accordion-icon">▼</span>
                        </div>
                        <div class="accordion-content">
                            <div class="accordion-body">
                                <p>We accept all major payment methods for your convenience:</p>
                                <ul>
                                    <li>Credit cards (Visa, Mastercard, American Express)</li>
                                    <li>Debit cards</li>
                                    <li>Digital wallets (Apple Pay, Google Pay)</li>
                                    <li>Bank transfers</li>
                                    <li>PayStack (for mobile wallet payments in Africa)</li>
                                </ul>
                                <p>All transactions are secure and encrypted with industry-leading SSL encryption.</p>
                            </div>
                        </div>
                    </div>

                    <div class="accordion-item">
                        <div class="accordion-header">
                            <h3>Why was I charged a different amount?</h3>
                            <span class="accordion-icon">▼</span>
                        </div>
                        <div class="accordion-content">
                            <div class="accordion-body">
                                <p>The final charge may differ from the estimated price due to:</p>
                                <ul>
                                    <li><strong>Service fees:</strong> We charge a service fee on bookings</li>
                                    <li><strong>Taxes:</strong> Local taxes are added based on your location</li>
                                    <li><strong>Dynamic pricing:</strong> Prices adjust based on demand and availability</li>
                                </ul>
                                <p>The exact breakdown is shown before you confirm your payment.</p>
                            </div>
                        </div>
                    </div>

                    <div class="accordion-item">
                        <div class="accordion-header">
                            <h3>Is my payment information safe?</h3>
                            <span class="accordion-icon">▼</span>
                        </div>
                        <div class="accordion-content">
                            <div class="accordion-body">
                                <p><strong>Absolutely!</strong> We take security very seriously. All payment information is:</p>
                                <ul>
                                    <li>Encrypted using 256-bit SSL encryption</li>
                                    <li>Compliant with PCI-DSS standards</li>
                                    <li>Never stored on our servers (processed securely by trusted providers)</li>
                                </ul>
                            </div>
                        </div>
                    </div>

                </div>
            </section>

            <!-- For Space Owners -->
            <section class="faq-category">
                <h2 class="faq-category-title">🏢 For Space Owners</h2>
                <div class="faq-accordion">
                    
                    <div class="accordion-item">
                        <div class="accordion-header">
                            <h3>How do I list my parking space?</h3>
                            <span class="accordion-icon">▼</span>
                        </div>
                        <div class="accordion-content">
                            <div class="accordion-body">
                                <p>List your space in 4 easy steps:</p>
                                <ul>
                                    <li><strong>Sign up</strong> as a Space Owner</li>
                                    <li><strong>Add space details:</strong> Address, size, amenities, photos</li>
                                    <li><strong>Set your price</strong> and availability schedule</li>
                                    <li><strong>Launch!</strong> Your space is now visible to parkers</li>
                                </ul>
                                <p>The whole process takes about 10 minutes.</p>
                            </div>
                        </div>
                    </div>

                    <div class="accordion-item">
                        <div class="accordion-header">
                            <h3>How much can I earn?</h3>
                            <span class="accordion-icon">▼</span>
                        </div>
                        <div class="accordion-content">
                            <div class="accordion-body">
                                <p>Earnings depend on several factors:</p>
                                <ul>
                                    <li>Location (higher demand = higher rates)</li>
                                    <li>Space amenities (covered, gated, EV charging)</li>
                                    <li>Availability and hours of operation</li>
                                </ul>
                                <p>We take a commission on each booking. The rest goes directly to you! Most space owners earn between ₦50,000 - ₦500,000 per month depending on location and demand.</p>
                            </div>
                        </div>
                    </div>

                </div>
            </section>

            <!-- Glassmorphism CTA Section -->
            <section class="faq-cta">
                <h3>Didn't find what you're looking for?</h3>
                <p>Have a question we didn't answer? Our support team is here to help!</p>
                <a href="contact.php" class="cta-button">Contact Support</a>
            </section>

        </div>
    </div>

    <!-- Include Footer Component -->
    <?php require_once 'includes/footer.php'; ?>

    <script>
        // Accordion functionality with smooth animation
        const accordionItems = document.querySelectorAll('.accordion-item');
        
        accordionItems.forEach(item => {
            const header = item.querySelector('.accordion-header');
            
            header.addEventListener('click', () => {
                // Toggle current item
                item.classList.toggle('active');
                
                // Optional: Close other items (uncomment if you want only one open at a time)
                // accordionItems.forEach(otherItem => {
                //     if (otherItem !== item && otherItem.classList.contains('active')) {
                //         otherItem.classList.remove('active');
                //     }
                // });
            });
        });
    </script>
</body>
</html>