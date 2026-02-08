<?php
require_once __DIR__ . '/../../lib/Security.php';
require_once __DIR__ . '/../../lib/Database.php';
require_once __DIR__ . '/../../lib/Auth.php';
require_once __DIR__ . '/../../lib/Credits.php';

// Start authentication session
Auth::startSession();

header('Content-Type: application/json');

$appDir = __DIR__;
$uploadsDir = $appDir . '/uploads/';
$projectsDir = $appDir . '/projects/';

// Ensure directories exist
if (!is_dir($uploadsDir)) mkdir($uploadsDir, 0755, true);
if (!is_dir($projectsDir)) mkdir($projectsDir, 0755, true);

// Determine action
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Rate limiting
$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // === UPLOAD IMAGE ===
    if ($action === 'upload') {
        // Rate limiting (20 uploads per hour)
        $rateLimit = Security::checkRateLimit($clientIp, 20, 3600, 'spine_upload');
        if (!$rateLimit['allowed']) {
            http_response_code(429);
            echo json_encode([
                'status' => 'error',
                'message' => 'Rate limit exceeded',
                'retry_after' => $rateLimit['reset']
            ]);
            exit;
        }

        if (!isset($_FILES['image'])) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'No image file provided']);
            exit;
        }

        // Secure file upload
        $allowedTypes = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/svg+xml'];
        $uploadResult = Security::validateFileUpload($_FILES['image'], $uploadsDir, $allowedTypes, 10485760); // 10MB

        if (!$uploadResult['success']) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => $uploadResult['error']]);
            exit;
        }

        $destPath = $uploadResult['path'];
        $filename = basename($destPath);

        echo json_encode([
            'status' => 'success',
            'filename' => $filename,
            'url' => 'uploads/' . $filename,
            'size' => filesize($destPath)
        ]);
        exit;
    }

    // === ANALYZE IMAGE WITH GEMINI ===
    if ($action === 'analyze') {
        // Rate limiting (30 analyses per hour)
        $rateLimit = Security::checkRateLimit($clientIp, 30, 3600, 'spine_analyze');
        if (!$rateLimit['allowed']) {
            http_response_code(429);
            echo json_encode([
                'status' => 'error',
                'message' => 'Rate limit exceeded',
                'retry_after' => $rateLimit['reset']
            ]);
            exit;
        }

        $rawInput = file_get_contents('php://input');
        $jsonValidation = Security::validateJson($rawInput);

        if (!$jsonValidation['valid']) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid JSON']);
            exit;
        }

        $data = $jsonValidation['data'];
        $filename = $data['filename'] ?? '';
        $csrfToken = $data['csrf_token'] ?? '';

        // CSRF validation (enable when implementing sessions)
        // if (!Security::validateCsrfToken($csrfToken)) {
        //     http_response_code(403);
        //     echo json_encode(['status' => 'error', 'message' => 'Invalid CSRF token']);
        //     exit;
        // }

        if (empty($filename)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'No filename provided']);
            exit;
        }

        // Validate path
        $imagePath = Security::validatePath($uploadsDir, $filename);
        if ($imagePath === false || !file_exists($imagePath)) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'Image file not found']);
            exit;
        }

        // AUTH: Check authentication
        $userId = Auth::checkAuth();
        if (!$userId) {
            http_response_code(401);
            echo json_encode([
                'status' => 'error',
                'message' => 'Authentication required',
                'code' => 'AUTH_REQUIRED'
            ]);
            exit;
        }

        // AUTH: Check and deduct credits (2 credits for analyze)
        $requiredCredits = Credits::getCost('spine', 'analyze');
        if (!Credits::hasEnoughCredits($userId, $requiredCredits)) {
            http_response_code(402);
            echo json_encode([
                'status' => 'error',
                'message' => 'Insufficient credits',
                'code' => 'INSUFFICIENT_CREDITS',
                'required' => $requiredCredits,
                'balance' => Credits::getBalance($userId)
            ]);
            exit;
        }

        $deducted = Credits::deductCredits(
            $userId,
            $requiredCredits,
            'spine',
            'analyze',
            ['filename' => basename($imagePath)]
        );

        if (!$deducted) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Failed to process credits']);
            exit;
        }

        $model = $data['model'] ?? 'gemini-3-flash-preview';

        // Validate model name (whitelist)
        $allowedModels = ['gemini-3-flash-preview', 'gemini-2-flash-exp', 'gemini-pro-vision'];
        if (!in_array($model, $allowedModels, true)) {
            $model = 'gemini-3-flash-preview';
        }

        $binDir = '/var/www/evo/projects/saas-suite/bin';
        $scriptPath = $binDir . '/spine_analyze.py';

        $args = [
            '--image' => $imagePath,
            '--model' => $model
        ];

        $result = Security::executePythonScript($scriptPath, $args, 120);

        if (!$result['success']) {
            error_log("Spine analyze error: " . $result['error']);
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Analysis failed']);
            exit;
        }

        $output = $result['output'];
        $jsonStart = strpos($output, '{');
        if ($jsonStart !== false) {
            $output = substr($output, $jsonStart);
        }

        // Validate and sanitize JSON output
        $outputValidation = Security::validateJson($output);
        if ($outputValidation['valid']) {
            echo Security::sanitizeJson($outputValidation['data']);
        } else {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Invalid response from analysis']);
        }
        exit;
    }

    // === REFINE ANCHORS WITH GEMINI ===
    if ($action === 'refine') {
        // Rate limiting (30 refinements per hour)
        $rateLimit = Security::checkRateLimit($clientIp, 30, 3600, 'spine_refine');
        if (!$rateLimit['allowed']) {
            http_response_code(429);
            echo json_encode([
                'status' => 'error',
                'message' => 'Rate limit exceeded',
                'retry_after' => $rateLimit['reset']
            ]);
            exit;
        }

        $rawInput = file_get_contents('php://input');
        $jsonValidation = Security::validateJson($rawInput);

        if (!$jsonValidation['valid']) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid JSON']);
            exit;
        }

        $data = $jsonValidation['data'];
        $filename = $data['filename'] ?? '';
        $anchors = $data['anchors'] ?? [];
        $csrfToken = $data['csrf_token'] ?? '';

        if (empty($filename)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'No filename provided']);
            exit;
        }

        if (empty($anchors) || !is_array($anchors)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'No anchors provided']);
            exit;
        }

        // Validate path
        $imagePath = Security::validatePath($uploadsDir, $filename);
        if ($imagePath === false || !file_exists($imagePath)) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'Image file not found']);
            exit;
        }

        // AUTH: Check authentication
        $userId = Auth::checkAuth();
        if (!$userId) {
            http_response_code(401);
            echo json_encode([
                'status' => 'error',
                'message' => 'Authentication required',
                'code' => 'AUTH_REQUIRED'
            ]);
            exit;
        }

        // AUTH: Check and deduct credits (2 credits for refine)
        $requiredCredits = Credits::getCost('spine', 'refine');
        if (!Credits::hasEnoughCredits($userId, $requiredCredits)) {
            http_response_code(402);
            echo json_encode([
                'status' => 'error',
                'message' => 'Insufficient credits',
                'code' => 'INSUFFICIENT_CREDITS',
                'required' => $requiredCredits,
                'balance' => Credits::getBalance($userId)
            ]);
            exit;
        }

        $deducted = Credits::deductCredits(
            $userId,
            $requiredCredits,
            'spine',
            'refine',
            ['filename' => basename($imagePath), 'anchor_count' => count($anchors)]
        );

        if (!$deducted) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Failed to process credits']);
            exit;
        }

        $model = $data['model'] ?? 'gemini-3-flash-preview';

        // Validate model name
        $allowedModels = ['gemini-3-flash-preview', 'gemini-2-flash-exp', 'gemini-pro-vision'];
        if (!in_array($model, $allowedModels, true)) {
            $model = 'gemini-3-flash-preview';
        }

        $binDir = '/var/www/evo/projects/saas-suite/bin';
        $scriptPath = $binDir . '/spine_refine.py';

        $args = [
            '--image' => $imagePath,
            '--anchors' => $anchors,
            '--model' => $model
        ];

        $result = Security::executePythonScript($scriptPath, $args, 120);

        if (!$result['success']) {
            error_log("Spine refine error: " . $result['error']);
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Refinement failed']);
            exit;
        }

        $output = $result['output'];
        $jsonStart = strpos($output, '{');
        if ($jsonStart !== false) {
            $output = substr($output, $jsonStart);
        }

        // Validate and sanitize JSON output
        $outputValidation = Security::validateJson($output);
        if ($outputValidation['valid']) {
            echo Security::sanitizeJson($outputValidation['data']);
        } else {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Invalid response from refinement']);
        }
        exit;
    }

    // === UPDATE ANIMATIONS WITH GEMINI ===
    if ($action === 'update_animations') {
        // Rate limiting (20 updates per hour)
        $rateLimit = Security::checkRateLimit($clientIp, 20, 3600, 'spine_update_anims');
        if (!$rateLimit['allowed']) {
            http_response_code(429);
            echo json_encode([
                'status' => 'error',
                'message' => 'Rate limit exceeded',
                'retry_after' => $rateLimit['reset']
            ]);
            exit;
        }

        $rawInput = file_get_contents('php://input');
        $jsonValidation = Security::validateJson($rawInput);

        if (!$jsonValidation['valid']) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid JSON']);
            exit;
        }

        $data = $jsonValidation['data'];
        $filename = $data['filename'] ?? '';
        $anchors = $data['anchors'] ?? [];
        $bones = $data['bones'] ?? [];
        $csrfToken = $data['csrf_token'] ?? '';

        if (empty($filename)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'No filename provided']);
            exit;
        }

        if (empty($anchors) || !is_array($anchors)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'No anchors provided']);
            exit;
        }

        // Validate path
        $imagePath = Security::validatePath($uploadsDir, $filename);
        if ($imagePath === false || !file_exists($imagePath)) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'Image file not found']);
            exit;
        }

        // AUTH: Check authentication
        $userId = Auth::checkAuth();
        if (!$userId) {
            http_response_code(401);
            echo json_encode([
                'status' => 'error',
                'message' => 'Authentication required',
                'code' => 'AUTH_REQUIRED'
            ]);
            exit;
        }

        // AUTH: Check and deduct credits (2 credits for update_animations)
        $requiredCredits = Credits::getCost('spine', 'update_animations');
        if (!Credits::hasEnoughCredits($userId, $requiredCredits)) {
            http_response_code(402);
            echo json_encode([
                'status' => 'error',
                'message' => 'Insufficient credits',
                'code' => 'INSUFFICIENT_CREDITS',
                'required' => $requiredCredits,
                'balance' => Credits::getBalance($userId)
            ]);
            exit;
        }

        $deducted = Credits::deductCredits(
            $userId,
            $requiredCredits,
            'spine',
            'update_animations',
            ['filename' => basename($imagePath), 'anchor_count' => count($anchors)]
        );

        if (!$deducted) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Failed to process credits']);
            exit;
        }

        $model = $data['model'] ?? 'gemini-3-flash-preview';
        $description = $data['description'] ?? '';
        $existing = $data['existing'] ?? [];

        // Validate model name
        $allowedModels = ['gemini-3-flash-preview', 'gemini-2-flash-exp', 'gemini-pro-vision'];
        if (!in_array($model, $allowedModels, true)) {
            $model = 'gemini-3-flash-preview';
        }

        // Validate description length
        if ($description && !Security::validateLength($description, 1000)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Description too long (max 1000 chars)']);
            exit;
        }

        $binDir = '/var/www/evo/projects/saas-suite/bin';
        $scriptPath = $binDir . '/spine_update_anims.py';

        $args = [
            '--image' => $imagePath,
            '--anchors' => $anchors,
            '--bones' => $bones,
            '--model' => $model
        ];

        if (!empty($description)) {
            $args['--description'] = $description;
        }

        if (!empty($existing) && is_array($existing)) {
            $args['--existing'] = $existing;
        }

        $result = Security::executePythonScript($scriptPath, $args, 180);

        if (!$result['success']) {
            error_log("Spine update animations error: " . $result['error']);
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Animation update failed']);
            exit;
        }

        $output = $result['output'];
        $jsonStart = strpos($output, '{');
        if ($jsonStart !== false) {
            $output = substr($output, $jsonStart);
        }

        // Validate and sanitize JSON output
        $outputValidation = Security::validateJson($output);
        if ($outputValidation['valid']) {
            echo Security::sanitizeJson($outputValidation['data']);
        } else {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Invalid response from animation update']);
        }
        exit;
    }

    // === AUTOSAVE (upsert) ===
    if ($action === 'autosave') {
        // Rate limiting (60 autosaves per hour)
        $rateLimit = Security::checkRateLimit($clientIp, 60, 3600, 'spine_autosave');
        if (!$rateLimit['allowed']) {
            http_response_code(429);
            echo json_encode(['status' => 'error', 'message' => 'Rate limit exceeded', 'retry_after' => $rateLimit['reset']]);
            exit;
        }

        // AUTH: Check authentication
        $userId = Auth::checkAuth();
        if (!$userId) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'Authentication required', 'code' => 'AUTH_REQUIRED']);
            exit;
        }

        $rawInput = file_get_contents('php://input');

        // 500KB size limit
        if (strlen($rawInput) > 512000) {
            http_response_code(413);
            echo json_encode(['status' => 'error', 'message' => 'Payload too large (max 500KB)']);
            exit;
        }

        $jsonValidation = Security::validateJson($rawInput);
        if (!$jsonValidation['valid']) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid JSON']);
            exit;
        }

        $stateData = $jsonValidation['data'];

        try {
            $db = Database::getInstance();
            $encoded = json_encode($stateData);
            $result = $db->query(
                'INSERT INTO spine_autosave (user_id, state_data, updated_at) VALUES (?, ?, NOW()) ON CONFLICT (user_id) DO UPDATE SET state_data = ?, updated_at = NOW()',
                [$userId, $encoded, $encoded]
            );
            if ($result === false) throw new Exception('Query failed');
            echo json_encode(['status' => 'success']);
        } catch (Exception $e) {
            error_log("Spine autosave error: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Failed to save state']);
        }
        exit;
    }

    // === AUTOSAVE CLEAR ===
    if ($action === 'autosave_clear') {
        // AUTH: Check authentication
        $userId = Auth::checkAuth();
        if (!$userId) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'Authentication required', 'code' => 'AUTH_REQUIRED']);
            exit;
        }

        try {
            $db = Database::getInstance();
            $result = $db->query('DELETE FROM spine_autosave WHERE user_id = ?', [$userId]);
            if ($result === false) throw new Exception('Query failed');
            echo json_encode(['status' => 'success']);
        } catch (Exception $e) {
            error_log("Spine autosave clear error: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Failed to clear autosave']);
        }
        exit;
    }

    // === SAVE PROJECT ===
    if ($action === 'save') {
        // Rate limiting (50 saves per hour)
        $rateLimit = Security::checkRateLimit($clientIp, 50, 3600, 'spine_save');
        if (!$rateLimit['allowed']) {
            http_response_code(429);
            echo json_encode([
                'status' => 'error',
                'message' => 'Rate limit exceeded',
                'retry_after' => $rateLimit['reset']
            ]);
            exit;
        }

        $rawInput = file_get_contents('php://input');
        $jsonValidation = Security::validateJson($rawInput);

        if (!$jsonValidation['valid']) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid JSON']);
            exit;
        }

        $data = $jsonValidation['data'];
        $projectName = $data['name'] ?? '';
        $projectData = $data['project'] ?? null;
        $csrfToken = $data['csrf_token'] ?? '';

        if (empty($projectName) || !$projectData) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Project name and data are required']);
            exit;
        }

        // AUTH: Check authentication
        $userId = Auth::checkAuth();
        if (!$userId) {
            http_response_code(401);
            echo json_encode([
                'status' => 'error',
                'message' => 'Authentication required',
                'code' => 'AUTH_REQUIRED'
            ]);
            exit;
        }

        // AUTH: Check and deduct credits (2 credits for save)
        $requiredCredits = Credits::getCost('spine', 'save');
        if (!Credits::hasEnoughCredits($userId, $requiredCredits)) {
            http_response_code(402);
            echo json_encode([
                'status' => 'error',
                'message' => 'Insufficient credits',
                'code' => 'INSUFFICIENT_CREDITS',
                'required' => $requiredCredits,
                'balance' => Credits::getBalance($userId)
            ]);
            exit;
        }

        $deducted = Credits::deductCredits(
            $userId,
            $requiredCredits,
            'spine',
            'save',
            ['project_name' => substr($projectName, 0, 100)]
        );

        if (!$deducted) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Failed to process credits']);
            exit;
        }

        // Validate project name length
        if (!Security::validateLength($projectName, 100, 1)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Project name must be 1-100 characters']);
            exit;
        }

        // Sanitize filename (alphanumeric, dash, underscore only)
        $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $projectName);
        $filename = $safeName . '_' . bin2hex(random_bytes(4)) . '.json';

        $projectData['name'] = $projectName;
        $projectData['savedAt'] = date('c');

        // Validate path
        $filepath = Security::validatePath($projectsDir, $filename);
        if ($filepath === false) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Invalid file path']);
            exit;
        }

        $saved = file_put_contents($filepath, Security::sanitizeJson($projectData));

        if ($saved === false) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Failed to save project']);
            exit;
        }

        chmod($filepath, 0644);

        echo json_encode([
            'status' => 'success',
            'filename' => $filename,
            'message' => 'Project saved'
        ]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
    exit;

} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {

    // === AUTOSAVE LOAD ===
    if ($action === 'autosave_load') {
        // AUTH: Check authentication
        $userId = Auth::checkAuth();
        if (!$userId) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'Authentication required', 'code' => 'AUTH_REQUIRED']);
            exit;
        }

        try {
            $db = Database::getInstance();
            $stmt = $db->query('SELECT state_data FROM spine_autosave WHERE user_id = ?', [$userId]);
            if ($stmt === false) throw new Exception('Query failed');
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                echo json_encode(['status' => 'success', 'state' => json_decode($row['state_data'], true)]);
            } else {
                echo json_encode(['status' => 'success', 'state' => null]);
            }
        } catch (Exception $e) {
            error_log("Spine autosave load error: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Failed to load autosave']);
        }
        exit;
    }

    // === LIST PROJECTS ===
    if ($action === 'projects') {
        // NOTE: Should be scoped to authenticated user when auth is implemented
        $files = glob($projectsDir . '*.json');
        if ($files === false) {
            echo json_encode(['status' => 'success', 'projects' => []]);
            exit;
        }

        usort($files, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        $projects = [];
        foreach (array_slice($files, 0, 100) as $file) {
            // Validate path
            $safePath = Security::validatePath($projectsDir, basename($file));
            if ($safePath === false) continue;

            $content = file_get_contents($safePath);
            $jsonValidation = Security::validateJson($content);

            if ($jsonValidation['valid']) {
                $data = $jsonValidation['data'];
                $projects[] = [
                    'filename' => basename($file),
                    'name' => $data['name'] ?? basename($file, '.json'),
                    'savedAt' => $data['savedAt'] ?? date('c', filemtime($safePath)),
                    'imageType' => $data['imageType'] ?? 'unknown'
                ];
            }
        }

        echo json_encode(['status' => 'success', 'projects' => $projects]);
        exit;
    }

    // === LOAD PROJECT ===
    if ($action === 'load') {
        $filename = $_GET['filename'] ?? '';

        if (empty($filename)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'No filename provided']);
            exit;
        }

        // Validate path
        $filepath = Security::validatePath($projectsDir, $filename);
        if ($filepath === false || !file_exists($filepath)) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'Project not found']);
            exit;
        }

        $content = file_get_contents($filepath);
        $jsonValidation = Security::validateJson($content);

        if (!$jsonValidation['valid']) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Invalid project data']);
            exit;
        }

        echo Security::sanitizeJson(['status' => 'success', 'project' => $jsonValidation['data']]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
    exit;
}

http_response_code(405);
echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
