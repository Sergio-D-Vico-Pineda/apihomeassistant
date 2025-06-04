<?php
require_once 'constants.php';
require_once 'auth/auth_handler.php';

/**
 * Request Handler for Home Assistant API operations
 * Handles various requests and returns JSON responses
 */
class RequestHandler
{

    private static function sendJsonResponse($data, $statusCode = 200)
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    public static function sendErrorResponse($message, $statusCode = 400)
    {
        self::sendJsonResponse([
            'success' => false,
            'error' => $message
        ], $statusCode);
    }

    private static function checkAuthentication()
    {
        if (!AuthHandler::isAuthenticated()) {
            self::sendErrorResponse('Not authenticated', 401);
        }
    }

    /**
     * Make authenticated request to Home Assistant API
     */
    private static function makeHaApiRequest($endpoint, $method = 'GET', $data = null)
    {
        if (!isset($_SESSION['ha_url']) || !isset($_SESSION['ha_token'])) {
            return [
                'success' => false,
                'error' => 'Missing Home Assistant URL or token'
            ];
        }

        $url = rtrim($_SESSION['ha_url'], '/') . '/api' . $endpoint;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $_SESSION['ha_token'],
            'Content-Type: application/json'
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        }

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

        if ($httpCode >= 400) {
            return [
                'success' => false,
                'error' => "HTTP Error $httpCode",
                'response' => $response
            ];
        }

        $decodedResponse = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'error' => 'Invalid JSON response: ' . json_last_error_msg()
            ];
        }

        return [
            'success' => true,
            'data' => $decodedResponse
        ];
    }

    /**
     * Handle token refresh request
     */
    public static function handleRefresh()
    {
        self::checkAuthentication();

        $result = AuthHandler::refreshToken();

        if ($result) {
            self::sendJsonResponse([
                'success' => true,
                'message' => 'Token refreshed successfully'
            ]);
        } else {
            self::sendErrorResponse('Failed to refresh token');
        }
    }

    /**
     * Handle toggle action for entities (like lights, switches)
     */
    public static function handleToggleAction()
    {
        self::checkAuthentication();

        $input = json_decode(file_get_contents('php://input'), true);

        if (!isset($input['entity_id'])) {
            self::sendErrorResponse('Missing entity_id parameter');
        }

        $entityId = $input['entity_id'];
        $domain = explode('.', $entityId)[0];

        // Determine the service based on domain
        $service = 'toggle';
        if ($domain === 'cover') {
            // For covers, we need to check current state to determine action
            $stateResult = self::makeHaApiRequest("/states/$entityId");
            if ($stateResult['success']) {
                $currentState = $stateResult['data']['state'];
                $service = ($currentState === 'open') ? 'close_cover' : 'open_cover';
            }
        }

        $result = self::makeHaApiRequest("/services/$domain/$service", 'POST', [
            'entity_id' => $entityId
        ]);

        if ($result['success']) {
            self::sendJsonResponse([
                'success' => true,
                'message' => "Successfully toggled $entityId"
            ]);
        } else {
            self::sendErrorResponse($result['error']);
        }
    }

    /**
     * Handle device actions (turn_on, turn_off, specific services)
     */
    public static function handleDeviceAction()
    {
        self::checkAuthentication();

        $input = json_decode(file_get_contents('php://input'), true);

        if (!isset($input['entity_id']) || !isset($input['action'])) {
            self::sendErrorResponse('Missing entity_id or action parameter');
        }

        $entityId = $input['entity_id'];
        $action = $input['action'];
        $domain = explode('.', $entityId)[0];
        $serviceData = ['entity_id' => $entityId];

        // Add additional parameters if provided
        if (isset($input['brightness'])) {
            $serviceData['brightness'] = $input['brightness'];
        }
        if (isset($input['color_temp'])) {
            $serviceData['color_temp'] = $input['color_temp'];
        }
        if (isset($input['rgb_color'])) {
            $serviceData['rgb_color'] = $input['rgb_color'];
        }
        if (isset($input['temperature'])) {
            $serviceData['temperature'] = $input['temperature'];
        }

        $result = self::makeHaApiRequest("/services/$domain/$action", 'POST', $serviceData);

        if ($result['success']) {
            self::sendJsonResponse([
                'success' => true,
                'message' => "Successfully executed $action on $entityId"
            ]);
        } else {
            self::sendErrorResponse($result['error']);
        }
    }

    /**
     * Handle token authentication (store manual token)
     */
    public static function handleTokenAuth()
    {
        $input = json_decode(file_get_contents('php://input'), true);

        if (!isset($input['token']) || !isset($input['ha_url'])) {
            self::sendErrorResponse('Missing token or ha_url parameter');
        }

        $token = trim($input['token']);
        $haUrl = trim($input['ha_url']);

        if (!AuthHandler::validateHaUrl($haUrl)) {
            self::sendErrorResponse('Invalid Home Assistant URL format');
        }

        if (AuthHandler::storeManualToken($token, $haUrl)) {
            self::sendJsonResponse([
                'success' => true,
                'message' => 'Token stored successfully'
            ]);
        } else {
            self::sendErrorResponse('Failed to store token');
        }
    }

    /**
     * Handle logout request
     */
    public static function handleLogout()
    {
        if (AuthHandler::logout()) {
            self::sendJsonResponse([
                'success' => true,
                'message' => 'Logged out successfully'
            ]);
        } else {
            self::sendErrorResponse('Failed to logout');
        }
    }

    /**
     * Fetch all entity states
     */
    public static function fetchStates()
    {
        self::checkAuthentication();

        $filter = $_GET['filter'] ?? null;
        $endpoint = '/states';

        if ($filter) {
            $endpoint .= '/' . urlencode($filter);
        }

        $result = self::makeHaApiRequest($endpoint);

        if ($result['success']) {
            self::sendJsonResponse([
                'success' => true,
                'data' => $result['data']
            ]);
        } else {
            self::sendErrorResponse($result['error']);
        }
    }

    /**
     * Fetch available services
     */
    public static function fetchServices()
    {
        self::checkAuthentication();

        $result = self::makeHaApiRequest('/services');

        if ($result['success']) {
            self::sendJsonResponse([
                'success' => true,
                'data' => $result['data']
            ]);
        } else {
            self::sendErrorResponse($result['error']);
        }
    }

    /**
     * Fetch Home Assistant configuration
     */
    public static function fetchConfig()
    {
        self::checkAuthentication();

        $result = self::makeHaApiRequest('/config');

        if ($result['success']) {
            self::sendJsonResponse([
                'success' => true,
                'data' => $result['data']
            ]);
        } else {
            self::sendErrorResponse($result['error']);
        }
    }

    /**
     * Fetch events
     */
    public static function fetchEvents()
    {
        self::checkAuthentication();

        $result = self::makeHaApiRequest('/events');

        if ($result['success']) {
            self::sendJsonResponse([
                'success' => true,
                'data' => $result['data']
            ]);
        } else {
            self::sendErrorResponse($result['error']);
        }
    }

    /**
     * Fetch history data
     */
    public static function fetchHistory()
    {
        self::checkAuthentication();

        $entityId = $_GET['entity_id'] ?? null;
        $startTime = $_GET['start_time'] ?? null;
        $endTime = $_GET['end_time'] ?? null;

        $endpoint = '/history/period';

        if ($startTime) {
            $endpoint .= '/' . urlencode($startTime);
        }

        $queryParams = [];
        if ($entityId) {
            $queryParams['filter_entity_id'] = $entityId;
        }
        if ($endTime) {
            $queryParams['end_time'] = $endTime;
        }

        if (!empty($queryParams)) {
            $endpoint .= '?' . http_build_query($queryParams);
        }

        $result = self::makeHaApiRequest($endpoint);

        if ($result['success']) {
            self::sendJsonResponse([
                'success' => true,
                'data' => $result['data']
            ]);
        } else {
            self::sendErrorResponse($result['error']);
        }
    }

    /**
     * Fetch error log
     */
    public static function fetchErrorLog()
    {
        self::checkAuthentication();

        $result = self::makeHaApiRequest('/error_log');

        if ($result['success']) {
            self::sendJsonResponse([
                'success' => true,
                'data' => $result['data']
            ]);
        } else {
            self::sendErrorResponse($result['error']);
        }
    }

    /**
     * Fetch logbook entries
     */
    public static function fetchLogbook()
    {
        self::checkAuthentication();

        $entityId = $_GET['entity_id'] ?? null;
        $startTime = $_GET['start_time'] ?? null;
        $endTime = $_GET['end_time'] ?? null;

        $endpoint = '/logbook';

        if ($startTime) {
            $endpoint .= '/' . urlencode($startTime);
        }

        $queryParams = [];
        if ($entityId) {
            $queryParams['entity'] = $entityId;
        }
        if ($endTime) {
            $queryParams['end_time'] = $endTime;
        }

        if (!empty($queryParams)) {
            $endpoint .= '?' . http_build_query($queryParams);
        }

        $result = self::makeHaApiRequest($endpoint);

        if ($result['success']) {
            self::sendJsonResponse([
                'success' => true,
                'data' => $result['data']
            ]);
        } else {
            self::sendErrorResponse($result['error']);
        }
    }

    /**
     * Fetch calendar events
     */
    public static function fetchCalendars()
    {
        self::checkAuthentication();

        $entityId = $_GET['entity_id'] ?? null;
        $start = $_GET['start'] ?? null;
        $end = $_GET['end'] ?? null;

        if ($entityId) {
            // Fetch events for specific calendar
            $endpoint = "/calendars/$entityId/events";
            $queryParams = [];
            if ($start) {
                $queryParams['start'] = $start;
            }
            if ($end) {
                $queryParams['end'] = $end;
            }

            if (!empty($queryParams)) {
                $endpoint .= '?' . http_build_query($queryParams);
            }
        } else {
            // Fetch all calendars
            $endpoint = '/calendars';
        }

        $result = self::makeHaApiRequest($endpoint);

        if ($result['success']) {
            self::sendJsonResponse([
                'success' => true,
                'data' => $result['data']
            ]);
        } else {
            self::sendErrorResponse($result['error']);
        }
    }

    /**
     * Get states data for PHP usage (without JSON response)
     * Returns array with success status and data
     */
    public static function getStatesData($filter = null)
    {
        if (!AuthHandler::isAuthenticated()) {
            return [
                'success' => false,
                'error' => 'Not authenticated'
            ];
        }

        $endpoint = '/states';
        if ($filter) {
            $endpoint .= '/' . urlencode($filter);
        }

        return self::makeHaApiRequest($endpoint);
    }

    /**
     * Get specific entity state for PHP usage
     */
    public static function getEntityState($entityId)
    {
        if (!AuthHandler::isAuthenticated()) {
            return [
                'success' => false,
                'error' => 'Not authenticated'
            ];
        }

        return self::makeHaApiRequest('/states/' . urlencode($entityId));
    }
}

// Only handle requests if this file is being accessed directly
if (basename($_SERVER['PHP_SELF']) === 'request_handler.php') {
    // Handle the request
    session_start();

    // Get the action from query parameter or POST data
    $action = $_GET['action'] ?? $_POST['action'] ?? null;

    if (!$action) {
        RequestHandler::sendErrorResponse('No action specified');
    }

    // Route to appropriate handler
    switch ($action) {
        case 'handleRefresh':
            RequestHandler::handleRefresh();
            break;

        case 'handleToggleAction':
            RequestHandler::handleToggleAction();
            break;

        case 'handleDeviceAction':
            RequestHandler::handleDeviceAction();
            break;

        case 'handleTokenAuth':
            RequestHandler::handleTokenAuth();
            break;

        case 'handleLogout':
            RequestHandler::handleLogout();
            break;

        case 'fetchStates':
            RequestHandler::fetchStates();
            break;

        case 'fetchServices':
            RequestHandler::fetchServices();
            break;

        case 'fetchConfig':
            RequestHandler::fetchConfig();
            break;

        case 'fetchEvents':
            RequestHandler::fetchEvents();
            break;

        case 'fetchHistory':
            RequestHandler::fetchHistory();
            break;

        case 'fetchErrorLog':
            RequestHandler::fetchErrorLog();
            break;

        case 'fetchLogbook':
            RequestHandler::fetchLogbook();
            break;

        case 'fetchCalendars':
            RequestHandler::fetchCalendars();
            break;

        default:
            RequestHandler::sendErrorResponse('Unknown action: ' . $action);
    }
}
