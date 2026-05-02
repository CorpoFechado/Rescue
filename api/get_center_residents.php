<?php
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../includes/session_guard.php';
require_once __DIR__ . '/../config/database.php';

require_role('official');

header('Content-Type: application/json');

$centerId = (int) ($_GET['center_id'] ?? 0);
if (!$centerId) { echo json_encode([]); exit(); }

$stmt = $conn->prepare("
    SELECT
        r.first_name, r.last_name, r.phone,
        r.house_no, r.street, r.area,
        sr.reported_at
    FROM safety_reports sr
    JOIN residents r ON r.resident_id = sr.resident_id
    WHERE sr.center_id = ?
      AND sr.status = 'evacuated'
      AND sr.report_id = (
          SELECT MAX(sr2.report_id) FROM safety_reports sr2
          WHERE sr2.resident_id = sr.resident_id
      )
    ORDER BY sr.reported_at DESC
");
$stmt->execute([$centerId]);
$residents = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($residents);