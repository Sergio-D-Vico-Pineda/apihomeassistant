<?php
require_once '../constants.php';
require_once 'auth_handler.php';
session_start();

// Check if we have a valid token or can refresh it
if (AuthHandler::isAuthenticated()) {
    header('Location: /index.php');
    exit;
}

if (!isset($_GET['code'])) {
    exit('Authorization failed or denied.');
}

$code = $_GET['code'];
$state = $_GET['state'];

// Exchange code for tokens using AuthHandler
$result = AuthHandler::exchangeCodeForTokens($code, $state);

if (!$result['success']) {
    exit('Error: ' . htmlspecialchars($result['error']));
}

// Redirect to the main dashboard
header('Location: /index.php');
exit;
