<?php
// ============================================================
// api/residents_map.php  — Returns residents with coordinates (JSON)
// ============================================================
session_start();
require_once '../includes/db.php';

// Allow both admin and resident sessions
if (empty($_SESSION['admin_id']) && empty($_SESSION['resident_id'])) {
    jsonError('Unauthorized', 401);
}

$residents = dbFetchAll(
    "SELECT barangay_id, CONCAT(first_name,' ',last_name) AS full_name,
            zone_purok, latitude, longitude
     FROM residents
     WHERE latitude IS NOT NULL AND longitude IS NOT NULL AND status != 'Inactive'"
);

header('Content-Type: application/json');
echo json_encode(['success' => true, 'residents' => $residents]);
    