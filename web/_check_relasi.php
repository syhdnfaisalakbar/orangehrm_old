<?php
$conn = new PDO('mysql:host=localhost;dbname=orangehrm;charset=utf8', 'root', '');

// Structure of the existing tables
$tables = ['ohrm_manpower_request', 'ohrm_job_vacancy', 'ohrm_quota_requests', 'ohrm_ext_department_quota'];

foreach ($tables as $t) {
    echo "==== $t ====\n";
    try {
        $stmt = $conn->query("DESCRIBE $t");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            echo $row['Field'] . " | " . $row['Type'] . " | Null=" . $row['Null'] . " | Default=" . ($row['Default'] ?? 'NULL') . " | Extra=" . ($row['Extra'] ?? '') ."\n";
        }
    } catch (Exception $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
    }
    echo "\n";
}

// Sample data
echo "==== ohrm_manpower_request data ====\n";
$stmt = $conn->query("SELECT * FROM ohrm_manpower_request ORDER BY id");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo json_encode($row) . "\n";
}

echo "\n==== ohrm_quota_requests data ====\n";
$stmt = $conn->query("SELECT * FROM ohrm_quota_requests ORDER BY id");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo json_encode($row) . "\n";
}

echo "\n==== ohrm_job_vacancy data ====\n";
$stmt = $conn->query("SELECT id, name, job_title_code, no_of_positions, status, hiring_manager_id FROM ohrm_job_vacancy ORDER BY id");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo json_encode($row) . "\n";
}

echo "\n==== ohrm_job_title (sample) ====\n";
$stmt = $conn->query("SELECT id, job_title_name FROM ohrm_job_title WHERE is_deleted = 0 LIMIT 10");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo $row['id'] . " => " . $row['job_title_name'] . "\n";
}
