<?php
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/session_guard.php';
require_once __DIR__ . '/../config/database.php';

require_role('official');

header('Content-Type: application/json');

$userId = (int) ($_GET['user_id'] ?? 0);
if (!$userId) { echo json_encode(['error' => 'Invalid']); exit(); }

$stmt = $conn->prepare("SELECT password FROM users WHERE user_id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

echo $user
    ? json_encode(['password' => $user['password']])
    : json_encode(['error' => 'Not found']);