<?php
session_start();

// NEW: itala ang logout ng admin bago burahin ang session
require_once __DIR__ . '/login_history_helper.php';
logAdminLogout(null, 'Manual logout');

session_destroy();
header("Location: login.php"); // back to your existing unified login
exit;
?>