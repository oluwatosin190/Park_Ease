<?php
// header-assets.php - Contains all CSS styles for navbar, footer, and global styles
?>
<!-- Fonts & Global Styles -->
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800;900&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet"/>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  :root {
    --blue:    #4F6EF7;
    --blue-2:  #6080FF;
    --purple:  #7C3AED;
    --violet:  #9333EA;
    --green:   #22C55E;
    --red:     #EF4444;
    --yellow:  #F59E0B;
    --dark:    #0F172A;
    --darker:  #080E1C;
    --glass-bg: rgba(255,255,255,0.12);
    --glass-border: rgba(255,255,255,0.22);
    --glass-shadow: 0 8px 32px rgba(0,0,0,0.18);
    --card-glass: rgba(255,255,255,0.82);
    --card-border: rgba(255,255,255,0.6);
    --text:    #0F172A;
    --muted:   #64748B;
    --light-bg:#F8FAFC;
    --font-display: 'Outfit', sans-serif;
    --font-body: 'DM Sans', sans-serif;
  }

  html { scroll-behavior: smooth; }

  body {
    font-family: var(--font-body);
    color: var(--text);
    background: #fff;
    overflow-x: hidden;
  }

  /* ─────────────────────────────────────────
     NAVBAR STYLES
  ───────────────────────────────────────── */
  .nav-wrapper {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    z-index: 1000;
    display: flex;
    justify-content: center;
    padding: 16px 24px;
    pointer-events: none;
  }

  nav {
    pointer-events: all;
    width: 100%;
    max-width: 1160px;
    height: 64px;
    background: rgba(255, 255, 255, 0.18);
    backdrop-filter: blur(20px) saturate(180%);
    -webkit-backdrop-filter: blur(20px) saturate(180%);
    border: 1px solid rgba(255, 255, 255, 0.35);
    border-radius: 100px;
    padding: 0 12px 0 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    box-shadow: 0 8px 32px rgba(0,0,0,0.14), inset 0 1px 0 rgba(255,255,255,0.4);
    transition: all 0.4s cubic-bezier(0.4,0,0.2,1);
  }

  nav.scrolled {
    background: rgba(255, 255, 255, 0.88);
    backdrop-filter: blur(24px) saturate(200%);
    box-shadow: 0 12px 40px rgba(0,0,0,0.16), inset 0 1px 0 rgba(255,255,255,0.9);
    border-color: rgba(255,255,255,0.7);
  }

  /* Glass container for logo – heavy glassmorphism effect */
  /* .nav-logo-glass {
    background: rgba(255, 255, 255, 0.15);
    backdrop-filter: blur(16px) saturate(180%);
    -webkit-backdrop-filter: blur(16px) saturate(180%);
    border-radius: 60px;
    padding: 1px 10px 2px 1px;
    border: 1px solid rgba(255, 255, 255, 0.35);
    box-shadow: 0 4px 20px rgba(0,0,0,0.15), inset 0 1px 0 rgba(255,255,255,0.3);
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 10px;
  }

  .nav-logo-glass:hover {
    background: rgba(255, 255, 255, 0.25);
    border-color: rgba(255, 255, 255, 0.5);
    box-shadow: 0 8px 28px rgba(0,0,0,0.2), inset 0 1px 0 rgba(255,255,255,0.4);
  } */

  .nav-logo {
    text-decoration: none;
    flex-shrink: 0;
  }

  /* BIGGER LOGO IMAGE - only the image size increases */
  .nav-logo-img {
    margin-top: 5px;
    height: 78px;
    width: auto;
    max-width: 180px;
    object-fit: contain;
    display: block;
    filter: drop-shadow(0 2px 6px rgba(0,0,0,0.2)) brightness(1.05) contrast(1.05);
    transition: filter 0.3s;
  }

  .nav-logo:hover .nav-logo-img {
    filter: drop-shadow(0 4px 12px rgba(79,110,247,0.5)) brightness(1.1);
  }

  .nav-logo-icon {
    width: 44px; height: 44px;
    background: linear-gradient(135deg, var(--blue), var(--purple));
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 4px 14px rgba(79,110,247,0.4);
  }
  .nav-logo-icon svg { width: 24px; height: 24px; fill: #fff; }

  .nav-center {
    display: flex;
    align-items: center;
    gap: 4px;
    position: absolute;
    left: 50%;
    transform: translateX(-50%);
  }

  .nav-center a,
  .nav-center .more-btn {
    text-decoration: none;
    font-family: var(--font-body);
    font-size: 14px;
    font-weight: 500;
    color: var(--text);
    padding: 8px 14px;
    border-radius: 50px;
    transition: all 0.2s;
    background: transparent;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 4px;
    white-space: nowrap;
  }
  .nav-center a:hover,
  .nav-center .more-btn:hover {
    background: rgba(79,110,247,0.1);
    color: var(--blue);
  }
  .nav-center a.active {
    background: rgba(79,110,247,0.12);
    color: var(--blue);
    font-weight: 600;
  }
  .nav-center .more-btn svg { width: 14px; height: 14px; }

  .nav-dropdown-wrap {
    position: relative;
  }
  .nav-dropdown {
    display: none;
    position: absolute;
    top: calc(100% + 10px);
    left: 50%;
    transform: translateX(-50%);
    background: rgba(255,255,255,0.92);
    backdrop-filter: blur(20px) saturate(180%);
    -webkit-backdrop-filter: blur(20px);
    border: 1px solid rgba(255,255,255,0.6);
    border-radius: 20px;
    box-shadow: 0 20px 50px rgba(0,0,0,0.15);
    min-width: 180px;
    padding: 8px;
    list-style: none;
    z-index: 200;
  }
  .nav-dropdown::before {
    content: '';
    position: absolute;
    top: -20px;
    left: 0;
    width: 100%;
    height: 20px;
  }
  .nav-dropdown-wrap:hover .nav-dropdown { display: block; }
  .nav-dropdown li a {
    display: block;
    padding: 10px 16px;
    font-size: 14px;
    color: var(--text);
    text-decoration: none;
    border-radius: 12px;
    transition: background 0.15s;
    font-weight: 500;
  }
  .nav-dropdown li a:hover { background: rgba(79,110,247,0.08); color: var(--blue); }

  .nav-right {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-shrink: 0;
  }
  .nav-reservations {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 14px;
    font-weight: 500;
    color: var(--text);
    text-decoration: none;
    padding: 8px 14px;
    border-radius: 50px;
    transition: all 0.2s;
  }
  .nav-reservations:hover { background: rgba(79,110,247,0.1); color: var(--blue); }
  .nav-reservations svg { width: 16px; height: 16px; }

  .btn-nav-cta {
    background: linear-gradient(135deg, var(--blue), var(--purple));
    color: #fff;
    border: none;
    cursor: pointer;
    padding: 10px 22px;
    border-radius: 50px;
    font-family: var(--font-body);
    font-size: 14px;
    font-weight: 600;
    transition: all 0.3s;
    text-decoration: none;
    display: inline-block;
    box-shadow: 0 4px 16px rgba(79,110,247,0.35);
    position: relative;
    overflow: hidden;
  }
  .btn-nav-cta::after {
    content: '';
    position: absolute;
    inset: 0;
    background: rgba(255,255,255,0);
    transition: background 0.2s;
    border-radius: 50px;
  }
  .btn-nav-cta:hover::after { background: rgba(255,255,255,0.1); }
  .btn-nav-cta:hover { transform: translateY(-1px); box-shadow: 0 8px 24px rgba(79,110,247,0.45); }

  .user-avatar {
    width: 38px; height: 38px;
    background: linear-gradient(135deg, var(--blue), var(--purple));
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    color: white; font-weight: 700; font-size: 14px;
    text-decoration: none;
    box-shadow: 0 4px 14px rgba(79,110,247,0.35);
    transition: transform 0.2s;
  }
  .user-avatar:hover { transform: scale(1.06); }

  .mobile-menu-btn {
    display: none;
    flex-direction: column;
    gap: 5px;
    cursor: pointer;
    padding: 8px;
    border-radius: 10px;
    transition: background 0.3s;
    width: 36px;
    height: 36px;
    align-items: center;
    justify-content: center;
  }
  .mobile-menu-btn:hover { background: rgba(79,110,247,0.12); }
  .mobile-menu-btn span { 
    width: 20px; height: 2px; background: var(--text); border-radius: 2px; 
    transition: all 0.4s cubic-bezier(0.4,0,0.2,1); display: block;
    transform-origin: center;
  }
  .mobile-menu-btn.open span:nth-child(1) { transform: rotate(45deg) translateY(9px); }
  .mobile-menu-btn.open span:nth-child(2) { opacity: 0; transform: scaleX(0); }
  .mobile-menu-btn.open span:nth-child(3) { transform: rotate(-45deg) translateY(-9px); }

  .mobile-nav-overlay {
    display: none;
    position: fixed;
    top: 96px;
    left: 16px;
    right: 16px;
    background: rgba(255,255,255,0.12);
    backdrop-filter: blur(32px) saturate(180%);
    -webkit-backdrop-filter: blur(32px) saturate(180%);
    border: 1px solid rgba(255,255,255,0.28);
    border-radius: 28px;
    box-shadow: 0 20px 60px rgba(0,0,0,0.18), inset 0 1px 0 rgba(255,255,255,0.4);
    padding: 20px;
    z-index: 999;
    opacity: 0;
    transform: translateY(-10px);
    pointer-events: none;
    transition: all 0.4s cubic-bezier(0.4,0,0.2,1);
    max-height: calc(100vh - 120px);
    overflow-y: auto;
    overflow-x: hidden;
    -webkit-overflow-scrolling: touch;
  }
  .mobile-nav-overlay::-webkit-scrollbar {
    width: 4px;
  }
  .mobile-nav-overlay::-webkit-scrollbar-track {
    background: rgba(255,255,255,0.1);
    border-radius: 10px;
  }
  .mobile-nav-overlay::-webkit-scrollbar-thumb {
    background: rgba(255,255,255,0.3);
    border-radius: 10px;
  }
  .mobile-nav-overlay::-webkit-scrollbar-thumb:hover {
    background: rgba(255,255,255,0.5);
  }
  .mobile-nav-overlay.open { 
    display: block;
    opacity: 1; 
    transform: translateY(0);
    pointer-events: all;
  }

  .mobile-nav-section {
    margin-bottom: 16px;
  }
  .mobile-nav-section:last-child {
    margin-bottom: 0;
  }
  .mobile-nav-section-label {
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.8px;
    text-transform: uppercase;
    color: rgba(255,255,255,0.5);
    padding: 0 16px;
    margin-bottom: 8px;
  }

  .mobile-nav-overlay a {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 16px;
    font-size: 15px;
    font-weight: 500;
    color: white;
    text-decoration: none;
    border-radius: 16px;
    transition: all 0.25s cubic-bezier(0.4,0,0.2,1);
    margin-bottom: 6px;
    background: transparent;
    position: relative;
  }
  .mobile-nav-overlay a::before {
    content: '';
    position: absolute;
    inset: 0;
    background: rgba(79,110,247,0.15);
    border-radius: 16px;
    opacity: 0;
    transition: opacity 0.25s;
  }
  .mobile-nav-overlay a:hover::before { opacity: 1; }
  .mobile-nav-overlay a:hover { 
    color: var(--blue); 
    transform: translateX(4px);
  }
  .mobile-nav-overlay a svg {
    width: 18px;
    height: 18px;
    flex-shrink: 0;
  }

  .mobile-nav-divider { 
    height: 1px; 
    background: rgba(255,255,255,0.15);
    margin: 12px 0;
    border-radius: 1px;
  }

  .mobile-nav-cta {
    display: block;
    text-align: center;
    padding: 14px 16px;
    background: linear-gradient(135deg, var(--blue), var(--purple));
    color: #fff;
    border-radius: 16px;
    font-weight: 600;
    font-size: 15px;
    text-decoration: none;
    margin-top: 8px;
    transition: all 0.3s cubic-bezier(0.4,0,0.2,1);
    box-shadow: 0 4px 14px rgba(79,110,247,0.3);
    position: relative;
    overflow: hidden;
  }
  .mobile-nav-cta::before {
    content: '';
    position: absolute;
    inset: 0;
    background: rgba(255,255,255,0);
    transition: background 0.3s;
  }
  .mobile-nav-cta:active::before { background: rgba(255,255,255,0.15); }
  .mobile-nav-cta:hover { 
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(79,110,247,0.4);
  }

  /* ─────────────────────────────────────────
     FOOTER STYLES
  ───────────────────────────────────────── */
  footer {
    background: var(--darker);
    border-top: 1px solid rgba(255,255,255,0.07);
    padding: 70px 48px 40px;
  }
  .footer-grid {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr 1.5fr;
    gap: 60px;
    margin-bottom: 60px;
    max-width: 1200px;
    margin-left: auto;
    margin-right: auto;
  }
  .footer-logo-wrap {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 18px;
  }
  .footer-logo-wrap span {
    font-family: var(--font-display);
    font-size: 22px; font-weight: 800; color: #fff;
  }
  .footer-p { color: #94A3B8; font-size: 14px; line-height: 1.75; margin-bottom: 22px; max-width: 280px; }
  .footer-socials { display: flex; gap: 10px; }
  .footer-social {
    width: 38px; height: 38px;
    background: rgba(255,255,255,0.07);
    backdrop-filter: blur(8px);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    color: #94A3B8; text-decoration: none; font-size: 15px; font-weight: 700;
    transition: all 0.3s;
  }
  .footer-social:hover { background: var(--blue); color: #fff; border-color: var(--blue); transform: translateY(-3px); }

  .footer-apps { display: flex; gap: 12px; margin-top: 22px; }
  .app-btn {
    border: 1px solid rgba(255,255,255,0.15);
    background: rgba(255,255,255,0.05);
    backdrop-filter: blur(8px);
    border-radius: 14px; padding: 8px 14px;
    color: #fff; font-size: 12px; text-decoration: none;
    display: flex; flex-direction: column; transition: all 0.3s;
  }
  .app-btn:hover { background: var(--blue); border-color: var(--blue); transform: translateY(-2px); }
  .app-btn span:first-child { font-size: 10px; color: rgba(255,255,255,0.55); margin-bottom: 1px; }
  .app-btn span:last-child { font-weight: 700; font-size: 13px; }

  .footer-col h4 { font-family: var(--font-display); font-size: 16px; font-weight: 700; color: #fff; margin-bottom: 22px; }
  .footer-col ul { list-style: none; }
  .footer-col ul li { margin-bottom: 12px; }
  .footer-col ul li a { text-decoration: none; color: #94A3B8; font-size: 14px; transition: color .2s; }
  .footer-col ul li a:hover { color: #fff; }

  .footer-contact p {
    display: flex; align-items: flex-start; gap: 10px;
    font-size: 14px; color: #94A3B8; margin-bottom: 14px; line-height: 1.6;
  }
  .footer-contact p svg { width: 18px; height: 18px; flex-shrink: 0; margin-top: 2px; }

  .footer-support {
    background: linear-gradient(135deg, var(--blue), var(--purple));
    border-radius: 18px; padding: 18px 20px; margin: 20px 0;
    position: relative; overflow: hidden;
  }
  .footer-support::before {
    content: '';
    position: absolute; top: -20px; right: -20px;
    width: 80px; height: 80px;
    background: rgba(255,255,255,0.1); border-radius: 50%;
  }
  .footer-support h5 { font-family: var(--font-display); font-size: 15px; font-weight: 700; color: #fff; margin-bottom: 4px; }
  .footer-support p { font-size: 12px; color: rgba(255,255,255,0.75); margin: 0; }

  .footer-newsletter h5 { font-family: var(--font-display); font-size: 15px; font-weight: 700; color: #fff; margin-bottom: 8px; }
  .footer-newsletter p { font-size: 13px; color: #94A3B8; margin-bottom: 16px; }
  .newsletter-form { display: flex; gap: 8px; }
  .newsletter-form input {
    flex: 1; padding: 12px 16px;
    background: rgba(255,255,255,0.08);
    backdrop-filter: blur(8px);
    border: 1px solid rgba(255,255,255,0.12);
    border-radius: 14px; outline: none;
    color: #fff; font-size: 14px;
    font-family: var(--font-body); transition: all 0.3s;
  }
  .newsletter-form input::placeholder { color: #6B7280; }
  .newsletter-form input:focus { border-color: var(--blue); background: rgba(255,255,255,0.12); }
  .newsletter-form button {
    background: linear-gradient(135deg, var(--blue), var(--purple));
    color: #fff; border: none; border-radius: 14px;
    padding: 12px 20px; font-size: 13px; font-weight: 700;
    cursor: pointer; font-family: var(--font-display); transition: all 0.3s;
    box-shadow: 0 4px 14px rgba(79,110,247,0.3);
  }
  .newsletter-form button:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(79,110,247,0.4); }

  .footer-bottom {
    border-top: 1px solid rgba(255,255,255,0.07);
    padding-top: 28px;
    display: flex; justify-content: space-between; align-items: center;
    max-width: 1200px; margin: 0 auto;
  }
  .footer-bottom p { font-size: 13px; color: #64748B; }

  /* Alerts */
  .alert-success { background: #DCFCE7; color: #16A34A; padding: 12px 16px; border-radius: 14px; margin-bottom: 16px; font-size: 13px; font-weight: 500; }
  .alert-info { background: #DBEAFE; color: #1E40AF; padding: 12px 16px; border-radius: 14px; margin-bottom: 16px; font-size: 13px; }
  .alert-error { background: #FEE2E2; color: #DC2626; padding: 12px 16px; border-radius: 14px; margin-bottom: 16px; font-size: 13px; }

  /* Responsive */
  @media (max-width: 820px) {
    .nav-center { display: none; }
    .mobile-menu-btn { display: flex; }
    .nav-wrapper { padding: 12px 16px; }
    nav { padding: 0 12px 0 16px; }
    .nav-right { gap: 6px; }
    .nav-reservations { display: none; }
    
    /* Adjust glass logo for mobile - smaller padding but still large logo */
    .nav-logo-glass {
      padding: 6px 16px 6px 12px;
    }
    .nav-logo-img {
      height: 44px;
      max-width: 150px;
    }
  }

  @media (max-width: 768px) {
    .mobile-nav-overlay {
      top: 80px;
      left: 12px;
      right: 12px;
      padding: 16px;
      border-radius: 20px;
    }
    .mobile-nav-section-label {
      font-size: 10px;
      padding: 0 12px;
      margin-bottom: 6px;
    }
    .mobile-nav-overlay a {
      padding: 12px 14px;
      font-size: 14px;
      gap: 10px;
      margin-bottom: 4px;
    }
    .mobile-nav-overlay a svg {
      width: 16px;
      height: 16px;
    }
    .mobile-nav-cta {
      padding: 12px 14px;
      font-size: 14px;
      margin-top: 6px;
    }
    .mobile-nav-divider {
      margin: 10px 0;
    }
    .mobile-nav-section {
      margin-bottom: 12px;
    }

    footer { padding: 50px 20px 30px; }
    .footer-grid { grid-template-columns: 1fr; gap: 36px; }
    .footer-logo-wrap { justify-content: center; }
    .footer-p { text-align: center; margin: 0 auto 20px; }
    .footer-socials, .footer-apps { justify-content: center; }
    .footer-col { text-align: center; }
    .footer-col ul { display: flex; flex-direction: column; align-items: center; }
    .footer-contact p { justify-content: center; }
    .newsletter-form { flex-direction: column; }
    .footer-bottom { flex-direction: column; gap: 12px; text-align: center; }
  }

  @media (max-width: 480px) {
    .nav-wrapper { padding: 10px 12px; }
    nav { padding: 0 10px 0 14px; height: 58px; }
    .nav-logo-img { height: 38px; max-width: 130px; }
    .nav-logo-glass { padding: 5px 12px 5px 10px; }
    .nav-logo-icon { width: 36px; height: 36px; }
    .nav-logo-icon svg { width: 18px; height: 18px; }
    .mobile-menu-btn { width: 32px; height: 32px; padding: 6px; }
    .mobile-menu-btn span { width: 18px; }

    .btn-nav-cta { padding: 8px 16px; font-size: 13px; }
    .user-avatar { width: 32px; height: 32px; font-size: 12px; }

    .mobile-nav-overlay {
      top: 74px;
      left: 8px;
      right: 8px;
      padding: 14px;
      border-radius: 18px;
      max-height: calc(100vh - 90px);
    }
    .mobile-nav-section {
      margin-bottom: 10px;
    }
    .mobile-nav-section-label {
      font-size: 9px;
      padding: 0 10px;
      margin-bottom: 6px;
      letter-spacing: 0.6px;
    }
    .mobile-nav-overlay a {
      padding: 11px 12px;
      font-size: 13px;
      gap: 10px;
      margin-bottom: 3px;
      border-radius: 14px;
    }
    .mobile-nav-overlay a svg {
      width: 16px;
      height: 16px;
      min-width: 16px;
    }
    .mobile-nav-divider {
      margin: 8px 0;
      height: 0.5px;
    }
    .mobile-nav-cta {
      padding: 11px 12px;
      font-size: 13px;
      margin-top: 6px;
      border-radius: 14px;
    }
  }

  @media (max-width: 380px) {
    .nav-wrapper { padding: 8px 10px; }
    nav { padding: 0 10px 0 12px; height: 54px; }
    .nav-logo-img { height: 34px; max-width: 120px; }
    .nav-logo-glass { padding: 4px 10px 4px 8px; }
    .nav-logo-icon { width: 32px; height: 32px; }
    .nav-logo-icon svg { width: 16px; height: 16px; }
    .mobile-menu-btn { width: 28px; height: 28px; padding: 4px; }
    .mobile-menu-btn span { width: 16px; height: 1.5px; }
    
    .mobile-nav-overlay {
      top: 70px;
      left: 6px;
      right: 6px;
      padding: 12px;
      border-radius: 16px;
      max-height: calc(100vh - 80px);
    }
    .mobile-nav-section {
      margin-bottom: 8px;
    }
    .mobile-nav-section-label {
      font-size: 8px;
      padding: 0 8px;
      margin-bottom: 5px;
      letter-spacing: 0.5px;
    }
    .mobile-nav-overlay a {
      padding: 10px 11px;
      font-size: 12px;
      gap: 8px;
      margin-bottom: 2px;
      border-radius: 12px;
    }
    .mobile-nav-overlay a svg {
      width: 15px;
      height: 15px;
    }
    .mobile-nav-divider {
      margin: 6px 0;
    }
    .mobile-nav-cta {
      padding: 10px 11px;
      font-size: 12px;
      margin-top: 4px;
      border-radius: 12px;
    }
    .btn-nav-cta { padding: 6px 12px; font-size: 12px; }
  }

  /* Scroll animation helper */
  @keyframes fadeUp {
    from { opacity: 0; transform: translateY(28px); }
    to { opacity: 1; transform: translateY(0); }
  }
</style>