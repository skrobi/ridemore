<?php

/* ============================================================================
  KORONA ROWEROWA POLSKI - API CONFIG
  Database connection + API-specific functions
  ============================================================================ */


// Load global functions
require_once __DIR__ . '/../includes/functions.php';
$envPath = __DIR__ . '/.env';

// 1️⃣ Wczytanie .env
if (file_exists($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#'))
            continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value, " \t\n\r\0\x0B\"'");
        putenv("$key=$value");
        $_ENV[$key] = $value;
    }
}

// 2️⃣ Lista krytycznych zmiennych
$required = [
    'DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'DB_CHARSET',
    'STRAVA_CLIENT_ID', 'STRAVA_CLIENT_SECRET',
    'JWT_SECRET', 'JWT_EXPIRY',
    'SMTP_HOST', 'SMTP_PORT', 'SMTP_USER', 'SMTP_PASS', 'FROM_EMAIL', 'FROM_NAME',
    'APP_URL'
];

// 3️⃣ Walidacja ENV
foreach ($required as $key) {
    if (!getenv($key)) {
        throw new RuntimeException("Brak zmiennej środowiskowej: $key");
    }
}

// 4️⃣ Automatyczne definiowanie stałych z ENV
foreach ($_ENV as $key => $value) {
    if (!defined($key)) {
        define($key, $value);
    }
}

define('MAGIC_LINK_EXPIRY', 15 * 60);
define('MAX_UPLOAD_SIZE', 10 * 1024 * 1024);
define('ALLOWED_GPX_EXTENSIONS', ['gpx']);
define('ALLOWED_IMAGE_EXTENSIONS', ['jpg', 'jpeg', 'png', 'webp']);
define('MAX_GPX_POINTS', 10000000);

define('MAGIC_LINK_RATE_LIMIT', 5);
define('MAGIC_LINK_RATE_WINDOW', 15 * 60);

/// CORS Headers - PRZED session_start!
if (strpos($_SERVER['REQUEST_URI'], '/api/') !== false) {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
}

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}



if ($_SERVER['HTTP_HOST'] === 'localhost' || strpos($_SERVER['HTTP_HOST'], 'local.') === 0) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    define('DEBUG_MODE', true);  // ✅ TRUE na localhost
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
    define('DEBUG_MODE', true); // ✅ FALSE na produkcji
}







spl_autoload_register(function ($className) {
    $typeMap = [
        'Model' => 'models',
        'Service' => 'services',
        'Helper' => 'helpers',
    ];

    // ✅ Zawsze absolutna ścieżka od głównego folderu projektu
    $baseDir = base_path('includes/classes/');

    foreach ($typeMap as $suffix => $folder) {
        if (str_ends_with($className, $suffix)) {
            $file = $baseDir . $folder . '/' . $className . '.php';

            if (file_exists($file)) {
                require_once $file;
                return;
            }
        }
    }

    // Fallback - szukaj w głównym folderze classes
    $file = $baseDir . $className . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// ============================================================================
// DATABASE CONNECTION
// ============================================================================

/**
 * Get PDO database connection (singleton)
 * @return PDO
 */
function getDB() {
    static $pdo = null;

    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];

            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
            //$pdo->exec("SET GLOBAL max_allowed_packet=268435456"); // 256MB

            if (DEBUG_MODE) {
                $stmt = $pdo->query("SELECT @@SESSION.tx_isolation AS isolation");
                $isolation = $stmt->fetchColumn();
                log_debug("Database isolation: $isolation", 'info');

                $stmt = $pdo->query("SELECT VERSION() AS version");
                $version = $stmt->fetchColumn();
                log_debug("Database version: $version", 'info');
            }
        } catch (PDOException $e) {
            if (DEBUG_MODE) {
                echo json_encode([
                    'success' => false,
                    'error' => $e->getMessage(),
                    'code' => $e->getCode()
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'error' => 'Database connection failed'
                ]);
            }
            exit;
        }
    }

    return $pdo;
}

// W config.php, dodaj:
define('SQL_LOG_FILE', __DIR__ . '/../logs/sql_queries.log');

// ============================================================================
// JSON RESPONSE HELPERS
// ============================================================================

/**
 * Send JSON success response
 * @param mixed $data
 * @param int $httpCode
 */
function sendJSON($success, $data = null, $httpCode = 200) {
    http_response_code($httpCode);
    echo json_encode([
        'success' => $success,
        'data' => $data
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Send error response
 * @param string $message
 * @param int $httpCode
 */
function sendError($message, $httpCode = 400) {
    //log_debug("API Error [$httpCode]: $message", 'warning');

    http_response_code($httpCode);
    echo json_encode([
        'success' => false,
        'error' => $message
            ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================================
// JWT FUNCTIONS
// ============================================================================

/**
 * Get Authorization Bearer token from headers
 * @return string|null
 */
function getBearerToken() {
    // Method 0: Cookie (DODAJ NA POCZĄTKU)
    if (isset($_COOKIE['auth_token'])) {
        if (DEBUG_MODE)
            log_debug("Token from cookie");
        return $_COOKIE['auth_token'];
    }

    // Method 1: Apache mod_rewrite
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        if (preg_match('/Bearer\s+(.*)$/i', $_SERVER['HTTP_AUTHORIZATION'], $matches)) {
            if (DEBUG_MODE)
                log_debug("Token from HTTP_AUTHORIZATION");
            return $matches[1];
        }
    }

    // Method 2: Apache/CGI
    if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        if (preg_match('/Bearer\s+(.*)$/i', $_SERVER['REDIRECT_HTTP_AUTHORIZATION'], $matches)) {
            if (DEBUG_MODE)
                log_debug("Token from REDIRECT_HTTP_AUTHORIZATION");
            return $matches[1];
        }
    }

    // Method 3: Nginx
    $headers = getallheaders();
    if (isset($headers['Authorization'])) {
        if (preg_match('/Bearer\s+(.*)$/i', $headers['Authorization'], $matches)) {
            if (DEBUG_MODE)
                log_debug("Token from getallheaders");
            return $matches[1];
        }
    }

    // Method 4: Fallback - query param
    if (isset($_GET['token'])) {
        if (DEBUG_MODE)
            log_debug("Token from query param");
        return $_GET['token'];
    }

    if (DEBUG_MODE)
        log_debug("❌ No token found");

    return null;
}

/**
 * Verify JWT token
 * @param string $token
 * @return array|false User data or false
 */
function verifyJWT($token) {
    $parts = explode('.', $token);

    if (count($parts) !== 3) {
        return false;
    }

    list($header, $payload, $signature) = $parts;

    // Verify signature
    $validSignature = hash_hmac('sha256', "$header.$payload", JWT_SECRET, true);
    $validSignature = rtrim(strtr(base64_encode($validSignature), '+/', '-_'), '=');

    if (!hash_equals($signature, $validSignature)) {
        return false;
    }

    // Decode payload
    $payloadData = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);

    if (!$payloadData) {
        return false;
    }

    // Check expiry
    if (isset($payloadData['exp']) && $payloadData['exp'] < time()) {
        return false;
    }

    return $payloadData;
}

/**
 * Generate JWT token
 * @param array $data Payload data
 * @return string JWT token
 */
function generateJWT($data) {
    $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
    $header = rtrim(strtr(base64_encode($header), '+/', '-_'), '=');

    $data['iat'] = time();
    $data['exp'] = time() + JWT_EXPIRY;

    $payload = json_encode($data);
    $payload = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');

    $signature = hash_hmac('sha256', "$header.$payload", JWT_SECRET, true);
    $signature = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

    return "$header.$payload.$signature";
}

/**
 * Require authentication - exits with 401 if not authenticated
 * @return array User data from JWT
 */
function requireAuth() {
    $token = getBearerToken();

    if (!$token) {
        sendError('Authentication required', 401);
    }

    $userData = verifyJWT($token);

    if (!$userData) {
        sendError('Invalid or expired token', 401);
    }

    return $userData;
}

/**
 * Optional authentication - returns user data or null
 * @return array|null User data or null
 */
function optionalAuth() {
    $token = getBearerToken();

    if (!$token) {
        return null;
    }

    return verifyJWT($token);
}

// ============================================================================
// USER FUNCTIONS
// ============================================================================

/**
 * Get user by local_id or create if not exists
 * @param string $localId UUID v4
 * @return array User data
 */

/**
 * Get user by local_id or create if not exists
 * @param string $localId UUID v4
 * @return array User data
 */
function getOrCreateUser($localId) {
    // Validate UUID format
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $localId)) {
        sendError('Invalid user_local_id format');
    }

    $db = getDB();

    $stmt = $db->prepare("
        SELECT user_id, user_local_id, email, username, status 
        FROM users 
        WHERE user_local_id = ?
    ");
    $stmt->execute([$localId]);
    $user = $stmt->fetch();

    if ($user) {
        return $user;
    }

    // Create new guest user
    try {
        $stmt = $db->prepare("
            INSERT INTO users (user_local_id, status, created_at, last_active) 
            VALUES (?, 'guest', NOW(), NOW())
        ");
        $stmt->execute([$localId]);

        return [
            'user_id' => (int) $db->lastInsertId(),
            'user_local_id' => $localId,
            'email' => null,
            'username' => null,
            'status' => 'guest'
        ];
    } catch (PDOException $e) {
        // UNIQUE constraint violation - retry
        if ($e->getCode() == 23000) {
            $stmt = $db->prepare("
                SELECT user_id, user_local_id, email, username, status 
                FROM users 
                WHERE user_local_id = ?
            ");
            $stmt->execute([$localId]);
            $user = $stmt->fetch();

            if ($user) {
                return $user;
            }
        }

        throw $e;
    }
}

/**
 * Validate JWT token and return user data
 * @param string $token
 * @return array|null User data or null
 */
function validateToken($token) {
    $payload = verifyJWT($token);

    if (!$payload || !isset($payload['user_id'])) {
        return null;
    }

    $db = getDB();
    $stmt = $db->prepare("
        SELECT user_id, user_local_id, email, username, status 
        FROM users 
        WHERE user_id = ?
    ");
    $stmt->execute([$payload['user_id']]);
    $user = $stmt->fetch();

    return $user ?: null;
}

// ============================================================================
// EMAIL FUNCTIONS
// ============================================================================

/**
 * Render email template with variables
 * @param string $templateName Template name without .php
 * @param array $variables Variables to pass to template
 * @return string HTML content
 */
function renderEmailTemplate($templateName, $variables = []) {
    $templatePath = base_path("templates/emails/{$templateName}.php");

    if (!file_exists($templatePath)) {
        throw new Exception("Email template not found: $templateName");
    }

    extract($variables);

    ob_start();
    include $templatePath;
    return ob_get_clean();
}


/**
 * Send email via SMTP using fsockopen (no external libraries)
 * @param string $to Recipient email
 * @param string $subject Email subject
 * @param string $body HTML body
 * @return bool Success
 */

/**
 * Send email via SMTP using fsockopen (no external libraries)
 * Enhanced version with better SMTP response handling
 */
function sendEmail($to, $subject, $body) {
    if (DEBUG_MODE) {
        // DEBUG - save to file
        $logFile = base_path('logs/emails.log');
        $logDir = dirname($logFile);
        if (!is_dir($logDir))
            mkdir($logDir, 0755, true);

        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "\n=== EMAIL LOG [$timestamp] ===\n";
        $logEntry .= "To: $to\nSubject: $subject\n";
        $logEntry .= "Magic Link: " . extractMagicLinkFromBody($body) . "\n";
        $logEntry .= "================\n";

        file_put_contents($logFile, $logEntry, FILE_APPEND);
        //log_debug("📧 EMAIL MOCK: Saved to logs/emails.log", 'info');
    }

    // PRODUCTION - SMTP via fsockopen
    $smtp = null;

    try {
        // Connect to SMTP server with SSL
        $smtp = @fsockopen('ssl://' . SMTP_HOST, SMTP_PORT, $errno, $errstr, 30);

        if (!$smtp) {
            throw new Exception("SMTP connection failed: $errstr ($errno)");
        }

        // Helper function to read multi-line SMTP responses
        $readResponse = function ($socket) {
            $response = '';
            while ($line = fgets($socket, 515)) {
                $response .= $line;
                // Multi-line response continues if 4th char is '-'
                if (substr($line, 3, 1) != '-')
                    break;
            }
            return $response;
        };

        // 1. Server greeting (220)
        $response = $readResponse($smtp);
        //log_debug("SMTP: $response", 'info');
        if (!preg_match('/^220/', $response)) {
            throw new Exception("Invalid greeting: $response");
        }

        // 2. EHLO
        fputs($smtp, "EHLO " . ($_SERVER['SERVER_NAME'] ?? 'localhost') . "\r\n");
        $response = $readResponse($smtp);
        //log_debug("SMTP EHLO: $response", 'info');
        // 3. AUTH LOGIN
        fputs($smtp, "AUTH LOGIN\r\n");
        $response = $readResponse($smtp);
        //log_debug("SMTP AUTH: $response", 'info');

        if (!preg_match('/^334/', $response)) {
            throw new Exception("AUTH LOGIN not accepted: $response");
        }

        // 4. Send username (base64)
        fputs($smtp, base64_encode(SMTP_USER) . "\r\n");
        $response = $readResponse($smtp);
        //log_debug("SMTP USER: $response", 'info');

        if (!preg_match('/^334/', $response)) {
            throw new Exception("Username rejected: $response");
        }

        // 5. Send password (base64)
        fputs($smtp, base64_encode(SMTP_PASS) . "\r\n");
        $response = $readResponse($smtp);
        //log_debug("SMTP PASS: $response", 'info');
        // Accept both 235 (standard) and 250 (some servers)
        if (!preg_match('/^(235|250)/', $response)) {
            throw new Exception("Authentication failed: $response");
        }

        // 6. MAIL FROM
        fputs($smtp, "MAIL FROM: <" . FROM_EMAIL . ">\r\n");
        $response = $readResponse($smtp);
        //log_debug("SMTP MAIL FROM: $response", 'info');

        if (!preg_match('/^250/', $response)) {
            throw new Exception("MAIL FROM rejected: $response");
        }

        // 7. RCPT TO
        fputs($smtp, "RCPT TO: <$to>\r\n");
        $response = $readResponse($smtp);
        //log_debug("SMTP RCPT TO: $response", 'info');

        if (!preg_match('/^250/', $response)) {
            throw new Exception("RCPT TO rejected: $response");
        }

        // 8. DATA
        fputs($smtp, "DATA\r\n");
        $response = $readResponse($smtp);
        //log_debug("SMTP DATA: $response", 'info');

        if (!preg_match('/^354/', $response)) {
            throw new Exception("DATA rejected: $response");
        }

        // 9. Email content
        $message = "From: " . FROM_NAME . " <" . FROM_EMAIL . ">\r\n";
        $message .= "To: <$to>\r\n";
        $message .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
        $message .= "MIME-Version: 1.0\r\n";
        $message .= "Content-Type: text/html; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: 8bit\r\n";
        $message .= "\r\n";
        $message .= $body . "\r\n";
        $message .= ".\r\n";

        fputs($smtp, $message);
        $response = $readResponse($smtp);
        //log_debug("SMTP SEND: $response", 'info');

        if (!preg_match('/^250/', $response)) {
            throw new Exception("Message rejected: $response");
        }

        // 10. QUIT
        fputs($smtp, "QUIT\r\n");
        $readResponse($smtp);

        fclose($smtp);

        //log_debug("✅ EMAIL SENT successfully to: $to", 'info');
        return true;
    } catch (Exception $e) {
        //log_debug("❌ EMAIL ERROR: " . $e->getMessage(), 'error');

        if ($smtp) {
            @fclose($smtp);
        }

        return false;
    }
}

function extractMagicLinkFromBody($body) {
    if (preg_match('/href="([^"]*verify\.php[^"]*)"/', $body, $matches)) {
        return $matches[1];
    }
    return 'Not found';
}

// ============================================================================
// SPATIAL FUNCTIONS
// ============================================================================

/**
 * Parse and validate bbox string
 * @param string $bbox "minLng,minLat,maxLng,maxLat"
 * @return array [minLng, minLat, maxLng, maxLat]
 */
function parseBbox($bbox) {
    $parts = explode(',', $bbox);

    if (count($parts) !== 4) {
        sendError('Invalid bbox format. Expected: minLng,minLat,maxLng,maxLat');
    }

    $coords = array_map('floatval', $parts);
    list($minLng, $minLat, $maxLng, $maxLat) = $coords;

    // Validate coordinates
    if ($minLng < -180 || $minLng > 180 || $maxLng < -180 || $maxLng > 180) {
        sendError('Invalid longitude values. Must be between -180 and 180');
    }

    if ($minLat < -90 || $minLat > 90 || $maxLat < -90 || $maxLat > 90) {
        sendError('Invalid latitude values. Must be between -90 and 90');
    }

    if ($minLng >= $maxLng || $minLat >= $maxLat) {
        sendError('Invalid bbox: min values must be less than max values');
    }

    return $coords;
}

/**
 * Create WKT POLYGON from bbox for MySQL spatial queries
 * @param array $bbox [minLng, minLat, maxLng, maxLat]
 * @return string WKT POLYGON string
 */
function bboxToPolygon($bbox) {
    list($minLng, $minLat, $maxLng, $maxLat) = $bbox;

    return sprintf(
            "POLYGON((%f %f, %f %f, %f %f, %f %f, %f %f))",
            $minLng, $minLat,
            $maxLng, $minLat,
            $maxLng, $maxLat,
            $minLng, $maxLat,
            $minLng, $minLat
    );
}

// ============================================================================
// VALIDATION HELPERS
// ============================================================================

/**
 * Validate and sanitize route ID
 * @param mixed $routeId
 * @return int
 */
function validateRouteId($routeId) {
    $id = filter_var($routeId, FILTER_VALIDATE_INT);
    if ($id === false || $id <= 0) {
        sendError('Invalid route_id');
    }
    return $id;
}

/**
 * Validate pagination parameters
 * @param int $page
 * @param int $limit
 * @return array [offset, limit]
 */
function validatePagination($page = 1, $limit = 20) {
    $page = max(1, (int) $page);
    $limit = min(100, max(1, (int) $limit)); // Max 100 items per page
    $offset = ($page - 1) * $limit;

    return [$offset, $limit];
}

/**
 * Get user from JWT token OR local_id (priority: JWT)
 * Used by endpoints that accept both authenticated and guest users
 * 
 * @param string|null $localId Optional local_id from request
 * @return array User data
 */
function getUserFromAuth($localId = null) {
    // 1. PRIORITY: Check JWT token first
    $token = getBearerToken();
    if ($token) {
        $userData = verifyJWT($token);

        if ($userData && isset($userData['user_id'])) {
            $db = getDB();
            $stmt = $db->prepare("
                SELECT user_id, user_local_id, email, username, status, role
                FROM users 
                WHERE user_id = ?
            ");
            $stmt->execute([$userData['user_id']]);
            $user = $stmt->fetch();

            if ($user) {
                //log_debug("✅ User from JWT: user_id={$user['user_id']}, email={$user['email']}", 'info');
                return $user;
            }
        }
    }

    // 2. FALLBACK: Use local_id for guests
    if ($localId) {
        //log_debug("ℹ️ No JWT token, using local_id for guest", 'info');
        return getOrCreateUser($localId);
    }

    sendError('Authentication required - no token or local_id provided', 401);
}

/**
 * Generate manage panel URL
 */
function manage_url($path = '') {
    $base = get_base_url();
    $path = ltrim($path, '/');
    return $path ? "{$base}/manage/{$path}" : "{$base}/manage/";
}



/**
 * Get challenge image URL
 * @param string|null $imageUrl
 * @return string
 */
function get_challenge_image($imageUrl) {
    return get_image_url($imageUrl);
}

/**
 * Get medal badge image URL
 * @param string|null $imageUrl
 * @return string
 */
function get_medal_badge_image($imageUrl) {
    return get_image_url($imageUrl);
}

/**
 * Get medal physical image URL
 * @param string|null $imageUrl
 * @return string
 */
function get_medal_physical_image($imageUrl) {
    return get_image_url($imageUrl);
}
