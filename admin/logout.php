<?php
require_once 'includes/admin_auth.php';

// Logout admin
adminLogout();

// Redirect to login page
header('Location: login.php');
exit;
?>