<?php
// Handle manual token submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['token']) && isset($_POST['ha_url'])) {
    $token = trim($_POST['token']);
    $haUrl = trim($_POST['ha_url']);
    
    if (!empty($token) && !empty($haUrl)) {
        if (AuthHandler::validateHaUrl($haUrl)) {
            if (AuthHandler::storeManualToken($token, $haUrl)) {
                header('Location: /index.php');
                exit;
            } else {
                $error = 'Failed to store token. Please try again.';
            }
        } else {
            $error = 'Invalid Home Assistant URL format.';
        }
    } else {
        $error = 'Please provide both URL and token.';
    }
}
?>

<?php if (isset($error)): ?>
    <div class="ha-error" style="margin-bottom: 20px; padding: 10px; background: #ffebee; border: 1px solid #f44336; border-radius: 4px; color: #c62828;">
        <?= htmlspecialchars($error) ?>
    </div>
<?php endif; ?>

<div class="logins">
    <div>
        <form method="POST" class="ha-form">
            <div class="ha-form-group">
                <label for="ha_url">Home Assistant URL:</label>
                <input type="text" id="ha_url" name="ha_url" placeholder="http://your-ha-instance:8123" required class="ha-input" value="<?= htmlspecialchars($_POST['ha_url'] ?? DEFAULT_HA_INSTANCE); ?>">
            </div>
            <div class="ha-form-group">
                <label for="token">Long-lived Access Token:</label>
                <input type="text" id="token" name="token" placeholder="Enter your token" required class="ha-input">
            </div>
            <button type="submit" class="ha-button">Connect with Token</button>
        </form>
    </div>
    <div>
        <?php $authorizeUrl = AuthHandler::getAuthorizationUrl(); ?>
        <a href="<?= htmlspecialchars($authorizeUrl); ?>" class="ha-button">
            Log in with Home Assistant (<?= DEFAULT_HA_INSTANCE ?>)
        </a>
    </div>
</div>