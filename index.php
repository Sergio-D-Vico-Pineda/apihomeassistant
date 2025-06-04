<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Home Assistant Integration</title>
    <link rel="stylesheet" href="css/styles.css">
    <script src="js/websocket-handler.js"></script>
</head>

<?php
require_once 'constants.php';
require_once 'auth/auth_handler.php';
session_start();
?>

<body class="ha-body">
    <div class="ha-container">
        <h1>Home Assistant Integration</h1>
        <div class="ha-debug">
            <h3>Session Values</h3>
            <pre>
                <?php
                echo "<br/>SESSION Values:\n";
                foreach ($_SESSION as $key => $value) {
                    if (is_array($value)) {
                        echo htmlspecialchars($key . ': ' . print_r($value, true)) . "\n";
                    } else {
                        if ($key === 'ha_token_expires') {
                            echo htmlspecialchars($key . ': ' . date('M j, Y g:i A', $value)) . "\n";
                        } else {
                            echo htmlspecialchars($key . ': ' . $value) . "\n";
                        }
                    }
                }
                ?>
                <?php
                echo "POST Values:\n";
                foreach ($_POST as $key => $value) {
                    if (is_array($value)) {
                        echo htmlspecialchars($key . ': ' . print_r($value, true)) . "\n";
                    } else {
                        echo htmlspecialchars($key . ': ' . $value) . "\n";
                    }
                }
                ?>
            </pre>
        </div>

        <?php !AuthHandler::isAuthenticated() ? require_once 'htmlphp/login.php' : require_once 'htmlphp/devices.php'; ?>
    </div>
</body>