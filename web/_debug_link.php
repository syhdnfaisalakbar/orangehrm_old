<?php
$conn = new PDO('mysql:host=localhost;dbname=orangehrm;charset=utf8', 'root', '');

echo "Existing vacancies:\n";
$stmt = $conn->query("SELECT id, name, job_title_code FROM ohrm_job_vacancy ORDER BY id");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "  id=" . $row['id'] . " | " . $row['name'] . " | job_title_code=" . $row['job_title_code'] . "\n";
}

echo "\nExisting manpower requests:\n";
$stmt = $conn->query("SELECT id, req_type, department, position FROM ohrm_manpower_request ORDER BY id");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "  id=" . $row['id'] . " | " . $row['req_type'] . " | " . $row['department'] . " | " . $row['position'] . "\n";
}
