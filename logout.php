<?php
require_once __DIR__ . '/admin/config.php';
if (is_visitor_logged_in()) {
    unset($_SESSION['visitor_id'], $_SESSION['visitor']);
}
header('Location: /'); exit;
