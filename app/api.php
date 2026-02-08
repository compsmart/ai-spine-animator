<?php
require_once __DIR__ . '/../../lib/Database.php';
require_once __DIR__ . '/../../lib/Auth.php';
require_once __DIR__ . '/../../lib/Projects.php';

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // === UPLOAD IMAGE ===
    if ($action === 'upload') {
        if (!isset($_FILES['image'])) {
            echo json_encode(['status' => 'error', 'message' => 'No image file provided']);
            exit;
        }

        $file = $_FILES['image'];
        $allowed = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/svg+xml'];

        if (!in_array($file['type'], $allowed)) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid file type. Allowed: PNG, JPG, GIF, WebP, SVG']);
            exit;
        }

        if ($file['size'] > 10 * 1024 * 1024) {
            echo json_encode(['status' => 'error', 'message' => 'File too large. Maximum 10MB']);
            exit;
        }

        $ext = pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'png';
        $filename = 'spine_' . time() . '_' . uniqid() . '.' . $ext;
        $destPath = $uploadsDir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            echo json_encode(['status' => 'error', 'message' => 'Failed to save uploaded file']);
            exit;
        }

        echo json_encode([
            'status' => 'success',
            'filename' => $filename,
            'url' => 'uploads/' . $filename,
            'size' => $file['size']
        ]);
        exit;
    }

    // === ANALYZE IMAGE WITH GEMINI ===
    if ($action === 'analyze') {
        $data = json_decode(file_get_contents('php://input'), true);
        $filename = $data['filename'] ?? '';

        if (empty($filename)) {
            echo json_encode(['status' => 'error', 'message' => 'No filename provided']);
            exit;
        }

        $imagePath = $uploadsDir . basename($filename);
        if (!file_exists($imagePath)) {
            echo json_encode(['status' => 'error', 'message' => 'Image file not found']);
            exit;
        }

        $model = $data['model'] ?? 'gemini-3-flash-preview';
        $binDir = dirname(__DIR__) . '/bin';
        $command = "python3 " . escapeshellarg($binDir . "/spine_analyze.py") . " --image " . escapeshellarg($imagePath) . " --model " . escapeshellarg($model) . " 2>/dev/null";
        $output = shell_exec($command);

        if (!$output) {
            echo json_encode(['status' => 'error', 'message' => 'No response from analysis backend']);
            exit;
        }

        // Extract JSON in case of leading warnings
        $jsonStart = strpos($output, '{');
        if ($jsonStart !== false) {
            $output = substr($output, $jsonStart);
        }

        echo $output;
        exit;
    }

    // === REFINE ANCHORS WITH GEMINI ===
    if ($action === 'refine') {
        $data = json_decode(file_get_contents('php://input'), true);
        $filename = $data['filename'] ?? '';
        $anchors = $data['anchors'] ?? [];

        if (empty($filename)) {
            echo json_encode(['status' => 'error', 'message' => 'No filename provided']);
            exit;
        }

        if (empty($anchors)) {
            echo json_encode(['status' => 'error', 'message' => 'No anchors provided']);
            exit;
        }

        $imagePath = $uploadsDir . basename($filename);
        if (!file_exists($imagePath)) {
            echo json_encode(['status' => 'error', 'message' => 'Image file not found']);
            exit;
        }

        $anchorsJson = json_encode($anchors);
        $model = $data['model'] ?? 'gemini-3-flash-preview';
        $binDir = dirname(__DIR__) . '/bin';
        $command = "python3 " . escapeshellarg($binDir . "/spine_refine.py") . " --image " . escapeshellarg($imagePath) . " --anchors " . escapeshellarg($anchorsJson) . " --model " . escapeshellarg($model) . " 2>/dev/null";
        $output = shell_exec($command);

        if (!$output) {
            echo json_encode(['status' => 'error', 'message' => 'No response from refinement backend']);
            exit;
        }

        // Extract JSON in case of leading warnings
        $jsonStart = strpos($output, '{');
        if ($jsonStart !== false) {
            $output = substr($output, $jsonStart);
        }

        echo $output;
        exit;
    }

    // === UPDATE ANIMATIONS WITH GEMINI ===
    if ($action === 'update_animations') {
        $data = json_decode(file_get_contents('php://input'), true);
        $filename = $data['filename'] ?? '';
        $anchors = $data['anchors'] ?? [];
        $bones = $data['bones'] ?? [];

        if (empty($filename)) {
            echo json_encode(['status' => 'error', 'message' => 'No filename provided']);
            exit;
        }

        if (empty($anchors)) {
            echo json_encode(['status' => 'error', 'message' => 'No anchors provided']);
            exit;
        }

        $imagePath = $uploadsDir . basename($filename);
        if (!file_exists($imagePath)) {
            echo json_encode(['status' => 'error', 'message' => 'Image file not found']);
            exit;
        }

        $anchorsJson = json_encode($anchors);
        $bonesJson = json_encode($bones);
        $model = $data['model'] ?? 'gemini-3-flash-preview';
        $description = $data['description'] ?? '';
        $existing = $data['existing'] ?? [];
        $binDir = dirname(__DIR__) . '/bin';
        $command = "python3 " . escapeshellarg($binDir . "/spine_update_anims.py") . " --image " . escapeshellarg($imagePath) . " --anchors " . escapeshellarg($anchorsJson) . " --bones " . escapeshellarg($bonesJson) . " --model " . escapeshellarg($model);
        if (!empty($description)) {
            $command .= " --description " . escapeshellarg($description);
        }
        if (!empty($existing)) {
            $command .= " --existing " . escapeshellarg(json_encode($existing));
        }
        $command .= " 2>/dev/null";
        $output = shell_exec($command);

        if (!$output) {
            echo json_encode(['status' => 'error', 'message' => 'No response from animation update backend']);
            exit;
        }

        // Extract JSON in case of leading warnings
        $jsonStart = strpos($output, '{');
        if ($jsonStart !== false) {
            $output = substr($output, $jsonStart);
        }

        echo $output;
        exit;
    }

    // === SAVE PROJECT ===
    if ($action === 'save') {
        $data = json_decode(file_get_contents('php://input'), true);
        $projectName = $data['name'] ?? '';
        $projectData = $data['project'] ?? null;

        if (empty($projectName) || !$projectData) {
            echo json_encode(['status' => 'error', 'message' => 'Project name and data are required']);
            exit;
        }

        // Sanitize filename
        $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $projectName);
        $filename = $safeName . '_' . time() . '.json';

        $projectData['name'] = $projectName;
        $projectData['savedAt'] = date('c');

        $saved = file_put_contents(
            $projectsDir . $filename,
            json_encode($projectData, JSON_PRETTY_PRINT)
        );

        if ($saved === false) {
            echo json_encode(['status' => 'error', 'message' => 'Failed to save project']);
            exit;
        }

        // Register project in unified projects system
        $userId = Auth::checkAuth();
        if ($userId) {
            Projects::register($userId, 'spine', $filename, $projectName, 'active', null, ['savedAt' => date('c')]);
        }

        echo json_encode([
            'status' => 'success',
            'filename' => $filename,
            'message' => 'Project saved'
        ]);
        exit;
    }

} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {

    // === LIST PROJECTS ===
    if ($action === 'projects') {
        $files = glob($projectsDir . '*.json');
        usort($files, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        $projects = [];
        foreach (array_slice($files, 0, 50) as $file) {
            $data = json_decode(file_get_contents($file), true);
            $projects[] = [
                'filename' => basename($file),
                'name' => $data['name'] ?? basename($file, '.json'),
                'savedAt' => $data['savedAt'] ?? date('c', filemtime($file)),
                'imageType' => $data['imageType'] ?? 'unknown'
            ];
        }
        echo json_encode(['status' => 'success', 'projects' => $projects]);
        exit;
    }

    // === LOAD PROJECT ===
    if ($action === 'load') {
        $filename = $_GET['filename'] ?? '';
        if (empty($filename)) {
            echo json_encode(['status' => 'error', 'message' => 'No filename provided']);
            exit;
        }

        $filepath = $projectsDir . basename($filename);
        if (!file_exists($filepath)) {
            echo json_encode(['status' => 'error', 'message' => 'Project not found']);
            exit;
        }

        $data = json_decode(file_get_contents($filepath), true);
        echo json_encode(['status' => 'success', 'project' => $data]);
        exit;
    }
}

echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
