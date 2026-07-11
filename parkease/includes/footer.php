<?php
// footer.php - HTML structure only (no CSS)
// Note: $newsletter_message should be defined in the parent page
?>
<!-- FOOTER -->
<footer>
  <div class="footer-grid">
    <div>
      <!-- Logo area - using actual image logo for better visibility -->
      <div class="footer-logo-wrap">
        <img class="footer-logo-img" src="img/logo.png" alt="SpaceNode - Smart Parking Solution" 
             onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
        <div class="footer-logo-icon" style="display:none;">
          <svg viewBox="0 0 24 24" style="width:16px;height:16px;fill:#fff"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z"/></svg>
        </div>
        <!-- Optional text fallback (hidden when image loads) -->
        <span class="footer-logo-text" style="display: none;">SpaceNode</span>
      </div>
      <p class="footer-p">Your trusted partner for finding and booking parking spaces. Safe, convenient, and affordable.</p>
      <div class="footer-socials">
        <a class="footer-social" href="#">f</a>
        <a class="footer-social" href="#">𝕏</a>
        <a class="footer-social" href="#">in</a>
        <a class="footer-social" href="#">▶</a>
      </div>
      <div class="footer-apps">
        <a class="app-btn" href="#"><span>Download on</span><span>App Store</span></a>
        <a class="app-btn" href="#"><span>Get it on</span><span>Google Play</span></a>
      </div>
    </div>

    <div class="footer-col">
      <h4>Quick Links</h4>
      <ul>
        <li><a href="index.php">Find Parking</a></li>
        <li><a href="all-spaces.php">All Spaces</a></li>
        <li><a href="#how-it-works">How It Works</a></li>
        <li><a href="about.php">About Us</a></li>
      </ul>
    </div>

    <div class="footer-col">
      <h4>Support</h4>
      <ul>
        <li><a href="#help">Help Center</a></li>
        <li><a href="faq.php">FAQ</a></li>
        <li><a href="contact.php">Contact Us</a></li>
        <li><a href="#safety">Safety</a></li>
        <li><a href="#accessibility">Accessibility</a></li>
      </ul>
    </div>

    <div class="footer-col footer-contact">
      <h4>Contact Us</h4>
      <p>
        <svg viewBox="0 0 24 24" fill="none" stroke="#94A3B8" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
        123 Parking Ave, NY 10001
      </p>
      <p>
        <svg viewBox="0 0 24 24" fill="none" stroke="#94A3B8" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.61 3.4 2 2 0 0 1 3.6 1.22h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.84a16 16 0 0 0 6.29 6.29l.95-.95a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
        1-800-SpaceNode
      </p>
      <p>
        <svg viewBox="0 0 24 24" fill="none" stroke="#94A3B8" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
        support@spacenode.com
      </p>
      <div class="footer-support">
        <h5>24/7 Support</h5>
        <p>We're always here to help you</p>
      </div>
      <div class="footer-newsletter">
        <h5>Newsletter</h5>
        <p>Get exclusive deals and tips</p>
        <?php if (isset($newsletter_message)) echo $newsletter_message; ?>
        <form method="POST" action="<?php echo basename($_SERVER['PHP_SELF']); ?>#newsletter" class="newsletter-form">
          <input type="email" name="newsletter_email" placeholder="Your email address" required/>
          <button type="submit">Subscribe</button>
        </form>
      </div>
    </div>
  </div>

  <div class="footer-bottom">
    <p>© <?php echo date('Y'); ?> SpaceNode. All rights reserved.</p>
    <p>Privacy · Terms · Cookies</p>
  </div>
</footer>

<!-- Ensure footer logo image gets proper styling (add to header-assets if not already there) -->
<style>
  .footer-logo-img {
    height: 100px;
    width: auto;
    max-width: 150px;
    object-fit: contain;
    display: block;
    filter: drop-shadow(0 2px 4px rgba(0,0,0,0.2)) brightness(1.05);
    transition: filter 0.2s;
  }
  .footer-logo-img:hover {
    filter: drop-shadow(0 4px 8px rgba(0,0,0,0.25)) brightness(1.1);
  }
  .footer-logo-wrap {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 18px;
  }
  .footer-logo-icon {
    width: 38px;
    height: 38px;
    background: linear-gradient(135deg, var(--blue), var(--purple));
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 4px 14px rgba(79,110,247,0.4);
  }
  /* Ensure fallback text is not visible when image loads */
  .footer-logo-text {
    font-family: var(--font-display);
    font-size: 22px;
    font-weight: 800;
    color: #fff;
    display: none;
  }
  /* If image fails, show the text fallback and hide the broken image */
  .footer-logo-img:not([src]), .footer-logo-img[src=""] {
    display: none;
  }
  .footer-logo-img:not([src]) + .footer-logo-text,
  .footer-logo-img[src=""] + .footer-logo-text {
    display: inline-block;
  }
</style>