<?php
session_start();
require_once 'includes/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Detect correct user ID from session (handles both admin_id and user_id keys)
$userid = $_SESSION['user_id'] ?? $_SESSION['admin_id'] ?? $_SESSION['id'] ?? null;
$role = isset($_SESSION['role']) ? $_SESSION['role'] : (isset($_SESSION['admin_id']) ? 'admin' : 'janitor');

if (!$userid) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized: No user ID in session']);
    exit;
}

if (!isset($_FILES['profile_picture']) || $_FILES['profile_picture']['error'] !== 0) {
    echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error']);
    exit;
}

$fileTmp = $_FILES['profile_picture']['tmp_name'];
$fileName = basename($_FILES['profile_picture']['name']);
$fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
$fileSize = $_FILES['profile_picture']['size'];

$allowed = ['jpg', 'jpeg', 'png', 'gif'];
$maxSize = 5 * 1024 * 1024; // 5MB

if (!in_array($fileExt, $allowed)) {
    echo json_encode(['success' => false, 'message' => 'Invalid file type. Allowed: JPG, PNG, GIF']);
    exit;
}

if ($fileSize > $maxSize) {
    echo json_encode(['success' => false, 'message' => 'File too large. Max 5MB']);
    exit;
}

$uploadDir = 'uploads/profile-pictures/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Generate unique filename
$newFileName = 'profile_' . $userid . '_' . time() . '.' . $fileExt;
$targetFile = $uploadDir . $newFileName;

// Move uploaded file
if (!move_uploaded_file($fileTmp, $targetFile)) {
    echo json_encode(['success' => false, 'message' => 'Failed to save file']);
    exit;
}

// Determine table and id field based on role
if ($role === 'admin') {
    $table = 'admins';
    $idField = 'admin_id';
} else {
    $table = 'janitors';
    $idField = 'janitor_id';
}

// Insert/Update file path into database
try {
    if (isset($pdo) && $pdo instanceof PDO) {
        // PDO version
        $stmt = $pdo->prepare("UPDATE {$table} SET profile_picture = :path, updated_at = NOW() WHERE {$idField} = :id");
        $stmt->execute([':path' => $targetFile, ':id' => $userid]);
    } else {
        // mysqli version - BOTH params are strings (ss not si)
        $stmt = $conn->prepare("UPDATE {$table} SET profile_picture = ?, updated_at = NOW() WHERE {$idField} = ?");
        if (!$stmt) {
            throw new Exception('Database prepare failed: ' . $conn->error);
        }
        $stmt->bind_param("ss", $targetFile, $userid);
        $ok = $stmt->execute();
        if (!$ok || $stmt->errno) {
            throw new Exception($stmt->error ?? 'Database execute failed');
        }
        $stmt->close();
    }

    echo json_encode([
        'success' => true,
        'path' => $targetFile,
        'message' => 'Profile picture updated successfully'
    ]);
} catch (Exception $e) {
    @unlink($targetFile);
    error_log('[upload_profile_picture.php] Error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
exit;
?>
