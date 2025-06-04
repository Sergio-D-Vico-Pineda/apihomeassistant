<?php
// require_once '../constants.php';

class AuthHandler {
    
    /**
     * Check if user has a valid token or can refresh it
     * @return bool True if authenticated, false otherwise
     */
    public static function isAuthenticated() {
        if (!isset($_SESSION['ha_token'])) {
            return false;
        }
        
        // Check if token is still valid
        if (isset($_SESSION['ha_token_expires']) && $_SESSION['ha_token_expires'] > time()) {
            return true;
        }
        
        // Try to refresh token if expired
        return self::refreshToken();
    }
    
    /**
     * Refresh the access token using refresh token
     * @return bool True if refresh successful, false otherwise
     */
    public static function refreshToken() {
        if (!isset($_SESSION['ha_refresh_token']) || !isset($_SESSION['ha_url'])) {
            return false;
        }
        
        $refreshUrl = rtrim($_SESSION['ha_url'], '/') . '/auth/token';
        $refreshData = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $_SESSION['ha_refresh_token'],
            'client_id' => CLIENT_ID
        ];
        
        $response = self::makeTokenRequest($refreshUrl, $refreshData);
        
        if ($response['success']) {
            $tokenData = $response['data'];
            $_SESSION['ha_token'] = $tokenData['access_token'];
            $_SESSION['ha_refresh_token'] = $tokenData['refresh_token'] ?? $_SESSION['ha_refresh_token'];
            $_SESSION['ha_token_expires'] = time() + ($tokenData['expires_in'] ?? 1800);
            return true;
        }
        
        return false;
    }
    
    /**
     * Exchange authorization code for tokens
     * @param string $code Authorization code
     * @param string $state Home Assistant URL
     * @return array Result with success status and data/error
     */
    public static function exchangeCodeForTokens($code, $state) {
        if (empty($code) || empty($state)) {
            return [
                'success' => false,
                'error' => 'Missing authorization code or state parameter'
            ];
        }
        
        $tokenUrl = rtrim($state, '/') . '/auth/token';
        $postData = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => CLIENT_ID,
            'redirect_uri' => REDIRECT_URI,
        ];
        
        $response = self::makeTokenRequest($tokenUrl, $postData);
        
        if ($response['success']) {
            $tokenData = $response['data'];
            $_SESSION['ha_token'] = $tokenData['access_token'];
            $_SESSION['ha_refresh_token'] = $tokenData['refresh_token'] ?? null;
            $_SESSION['ha_token_expires'] = time() + ($tokenData['expires_in'] ?? 1800);
            $_SESSION['ha_url'] = $state;
            
            return [
                'success' => true,
                'data' => $tokenData
            ];
        }
        
        return $response;
    }
    
    /**
     * Store token from manual input (long-lived token)
     * @param string $token Long-lived access token
     * @param string $haUrl Home Assistant URL
     * @return bool True if successful
     */
    public static function storeManualToken($token, $haUrl) {
        if (empty($token) || empty($haUrl)) {
            return false;
        }
        
        $_SESSION['ha_token'] = $token;
        $_SESSION['ha_url'] = rtrim($haUrl, '/');
        $_SESSION['ha_token_expires'] = time() + (365 * 24 * 3600); // Long-lived tokens don't expire
        $_SESSION['ha_refresh_token'] = null; // Long-lived tokens don't have refresh tokens
        
        return true;
    }
    
    /**
     * Logout user and revoke tokens
     * @return bool True if logout successful
     */
    public static function logout() {
        // Try to revoke refresh token if available
        if (isset($_SESSION['ha_refresh_token']) && isset($_SESSION['ha_url'])) {
            $revokeUrl = rtrim($_SESSION['ha_url'], '/') . '/auth/token';
            $revokeData = [
                'token' => $_SESSION['ha_refresh_token'],
                'action' => 'revoke'
            ];
            
            $ch = curl_init($revokeUrl);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($revokeData));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/x-www-form-urlencoded'
            ]);
            curl_exec($ch);
            curl_close($ch);
        }
        
        // Clear session
        session_destroy();
        return true;
    }
    
    /**
     * Get OAuth2 authorization URL
     * @param string $haUrl Home Assistant URL
     * @return string Authorization URL
     */
    public static function getAuthorizationUrl($haUrl = null) {
        $haUrl = $haUrl ?: DEFAULT_HA_INSTANCE;
        
        $params = http_build_query([
            'client_id' => CLIENT_ID,
            'redirect_uri' => REDIRECT_URI,
            'state' => $haUrl,
        ]);
        
        return rtrim($haUrl, '/') . '/auth/authorize?' . $params;
    }
    
    /**
     * Make a token request to Home Assistant
     * @param string $url Token endpoint URL
     * @param array $data POST data
     * @return array Response with success status and data/error
     */
    private static function makeTokenRequest($url, $data) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            return [
                'success' => false,
                'error' => 'cURL Error: ' . $curlError
            ];
        }
        
        if ($httpCode !== 200) {
            return [
                'success' => false,
                'error' => "HTTP Error $httpCode: " . $response
            ];
        }
        
        $tokenData = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'error' => 'Invalid JSON response: ' . json_last_error_msg()
            ];
        }
        
        if (isset($tokenData['error'])) {
            return [
                'success' => false,
                'error' => $tokenData['error_description'] ?? $tokenData['error']
            ];
        }
        
        return [
            'success' => true,
            'data' => $tokenData
        ];
    }
    
    /**
     * Get current token info
     * @return array|null Token information or null if not authenticated
     */
    public static function getTokenInfo() {
        if (!isset($_SESSION['ha_token'])) {
            return null;
        }
        
        return [
            'token' => $_SESSION['ha_token'],
            'expires' => $_SESSION['ha_token_expires'] ?? null,
            'has_refresh' => isset($_SESSION['ha_refresh_token']),
            'ha_url' => $_SESSION['ha_url'] ?? null,
            'is_expired' => isset($_SESSION['ha_token_expires']) && $_SESSION['ha_token_expires'] <= time()
        ];
    }
    
    /**
     * Validate Home Assistant URL format
     * @param string $url URL to validate
     * @return bool True if valid
     */
    public static function validateHaUrl($url) {
        if (empty($url)) {
            return false;
        }
        
        $parsed = parse_url($url);
        if (!$parsed || !isset($parsed['scheme']) || !isset($parsed['host'])) {
            return false;
        }
        
        // Only allow http/https
        if (!in_array($parsed['scheme'], ['http', 'https'])) {
            return false;
        }
        
        return true;
    }
}
