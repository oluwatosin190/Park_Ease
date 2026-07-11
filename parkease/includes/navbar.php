<?php
// navbar.php - HTML structure only (no CSS)
// Include access control functions if not already loaded
if (!function_exists('isOwner')) {
    require_once 'includes/user-access.php';
}
?>
<!-- FLOATING PILL NAVBAR -->
<div class="nav-wrapper">
  <nav id="mainNav">
    <!-- Logo - Dynamic link based on user type with heavy glass effect -->
    <a class="nav-logo" href="<?php echo getHomeLink(); ?>">
      <div class="nav-logo-glass">
        <img class="nav-logo-img" src="img/logo.png" alt="Park Ease – Smart Parking Solution"
             onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
        <!-- Fallback if logo image is missing -->
        <div class="nav-logo-icon" style="display:none;">
          <svg viewBox="0 0 24 24"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/></svg>
        </div>
      </div>
    </a>

    <!-- Centered Links - Hidden for Owners -->
    <?php if (!isOwner()): ?>
    <div class="nav-center">
      <a href="index.php" <?php echo (basename($_SERVER['PHP_SELF']) == 'index.php') ? 'class="active"' : ''; ?>>Find Parking</a>
      <a href="#how-it-works">How It Works</a>
      <a href="all-spaces.php" <?php echo (basename($_SERVER['PHP_SELF']) == 'all-spaces.php') ? 'class="active"' : ''; ?>>Browse All</a>
      <div class="nav-dropdown-wrap">
        <button class="more-btn" type="button">
          More
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
        </button>
        <ul class="nav-dropdown">
          <li><a href="about.php" <?php echo (basename($_SERVER['PHP_SELF']) == 'about.php') ? 'style="background:rgba(79,110,247,0.08);color:#4F6EF7;"' : ''; ?>>About Us</a></li>
          <li><a href="faq.php" <?php echo (basename($_SERVER['PHP_SELF']) == 'faq.php') ? 'style="background:rgba(79,110,247,0.08);color:#4F6EF7;"' : ''; ?>>FAQ</a></li>
          <li><a href="help_center.php">Help Center</a></li>
          <li><a href="contact.php" <?php echo (basename($_SERVER['PHP_SELF']) == 'contact.php') ? 'style="background:rgba(79,110,247,0.08);color:#4F6EF7;"' : ''; ?>>Contact</a></li>
          <li><a href="reviews.php" <?php echo (basename($_SERVER['PHP_SELF']) == 'reviews.php') ? 'style="background:rgba(79,110,247,0.08);color:#4F6EF7;"' : ''; ?>>Reviews</a></li>
        </ul>
      </div>
    </div>
    <?php endif; ?>

    <!-- Right Side -->
    <div class="nav-right">
      <?php if (isset($_SESSION['user_id'])): ?>
        <a class="nav-reservations" href="dashboard.php">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
          Dashboard
        </a>
        <a href="dashboard.php" class="user-avatar">
          <?php
            $initials = '';
            if (isset($_SESSION['user_name'])) {
              $np = explode(' ', $_SESSION['user_name']);
              $initials = strtoupper(substr($np[0],0,1).(isset($np[1]) ? substr($np[1],0,1) : ''));
            }
            echo $initials ?: 'U';
          ?>
        </a>
      <?php else: ?>
        <a class="nav-reservations" href="login.php">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          Sign In
        </a>
        <a class="btn-nav-cta" href="login.php">Get Started</a>
      <?php endif; ?>
    </div>

    <!-- Mobile hamburger -->
    <div class="mobile-menu-btn" id="mobileMenuBtn" onclick="toggleMobileMenu()" aria-label="Toggle menu">
      <span></span><span></span><span></span>
    </div>
  </nav>
</div>

<!-- Mobile Nav Overlay - Hidden content for Owners -->
<div class="mobile-nav-overlay" id="mobileNav">
  <?php if (!isOwner()): ?>
  <!-- Explore Section (Hidden for Owners) -->
  <div class="mobile-nav-section">
    <div class="mobile-nav-section-label">Explore</div>
    <a href="index.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
      Find Parking
    </a>
    <a href="all-spaces.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
      Browse All
    </a>
    <a href="#how-it-works">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
      How It Works
    </a>
  </div>

  <div class="mobile-nav-divider"></div>

  <!-- Information Section (Hidden for Owners) -->
  <div class="mobile-nav-section">
    <div class="mobile-nav-section-label">Information</div>
    <a href="about.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
      About Us
    </a>
    <a href="faq.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 9h6v6H9z"/><path d="M12 3C7.03 3 3 7.03 3 12s4.03 9 9 9 9-4.03 9-9S16.97 3 12 3z"/></svg>
      FAQ
    </a>
    <a href="help_center.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
      Help Center
    </a>
    <a href="contact.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/><line x1="9" y1="10" x2="15" y2="10"/><line x1="9" y1="14" x2="15" y2="14"/></svg>
      Contact
    </a>
    <a href="reviews.php">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
      Reviews
    </a>
  </div>

  <div class="mobile-nav-divider"></div>
  <?php endif; ?>

  <!-- Account Section (Visible to all logged in users) -->
  <div class="mobile-nav-section">
    <div class="mobile-nav-section-label">Account</div>
    <?php if (isset($_SESSION['user_id'])): ?>
      <a href="dashboard.php" style="color: #4F6EF7; font-weight: 600;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        Dashboard
      </a>
      <!-- Only show My Reservations for parkers -->
      <?php if (!isOwner()): ?>
      <a href="my-reservations.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        My Reservations
      </a>
      <?php endif; ?>
      <a href="profile.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        Profile
      </a>
      <a href="settings.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M12 1v6m0 6v6M4.22 4.22l4.24 4.24m6.08 0l4.24-4.24M1 12h6m6 0h6m-1.78 7.78l-4.24-4.24m-6.08 0l-4.24 4.24"/></svg>
        Settings
      </a>
      <a href="logout.php" style="color: #EF4444;">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        Logout
      </a>
    <?php else: ?>
      <a href="login.php">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        Sign In
      </a>
    <?php endif; ?>
  </div>

  <!-- CTA Button -->
  <div class="mobile-nav-section" style="margin-top: 4px;">
    <?php if (isset($_SESSION['user_id'])): ?>
      <a href="dashboard.php" class="mobile-nav-cta">Open Dashboard →</a>
    <?php else: ?>
      <a href="login.php" class="mobile-nav-cta">Get Started →</a>
    <?php endif; ?>
  </div>
</div>

<script>
  // Mobile menu functions for navbar
  function toggleMobileMenu() {
    const overlay = document.getElementById('mobileNav');
    const btn = document.getElementById('mobileMenuBtn');
    if (overlay && btn) {
      overlay.classList.toggle('open');
      btn.classList.toggle('open');
    }
  }
  
  // Close mobile menu when clicking a link
  const mobileLinks = document.querySelectorAll('.mobile-nav-overlay a');
  mobileLinks.forEach(link => {
    link.addEventListener('click', closeMobileMenu);
  });
  
  function closeMobileMenu() {
    const overlay = document.getElementById('mobileNav');
    const btn = document.getElementById('mobileMenuBtn');
    if (overlay && btn) {
      overlay.classList.remove('open');
      btn.classList.remove('open');
    }
  }
  
  // Close mobile menu when clicking outside
  document.addEventListener('click', function(e) {
    const overlay = document.getElementById('mobileNav');
    const btn = document.getElementById('mobileMenuBtn');
    if (overlay && overlay.classList.contains('open') && !overlay.contains(e.target) && !btn?.contains(e.target)) {
      closeMobileMenu();
    }
  });
  
  // Navbar scroll effect
  const nav = document.getElementById('mainNav');
  window.addEventListener('scroll', () => {
    if (nav) nav.classList.toggle('scrolled', window.scrollY > 40);
  });
</script>