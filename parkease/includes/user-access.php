<?php
// includes/user-access.php - Access control helper functions

/**
 * Check if current user is an owner
 */
function isOwner() {
    return isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'owner' && !isset($_SESSION['is_admin']);
}

/**
 * Check if current user is a parker
 */
function isParker() {
    return isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'parker' && !isset($_SESSION['is_admin']);
}

/**
 * Redirect owners away from public pages
 * Call this at the top of all public pages (index.php, about.php, faq.php, contact.php, reviews.php, help_center.php, all-spaces.php, my-reservations.php, book.php, parking-details.php)
 */
function redirectOwnersFromPublicPages() {
    if (isOwner()) {
        header('Location: dashboard.php');
        exit();
    }
}

/**
 * Get the appropriate home link based on user type
 */
function getHomeLink() {
    if (isOwner()) {
        return 'dashboard.php';
    }
    return 'index.php';
}