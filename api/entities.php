<?php
// api/entities.php - Fetch active business units and default currencies

define('IS_API', true);
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

$user = authenticate_api_request();
$conn = get_db_connection();

$stmt = $conn->prepare("SELECT id, name, default_currency, status FROM entities WHERE status = 'active' ORDER BY id ASC");
$stmt->execute();
$entities = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

echo json_encode([
    'success' => true,
    'entities' => $entities
]);
