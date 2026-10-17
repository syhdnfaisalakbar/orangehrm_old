<?php
$conn = new PDO('mysql:host=localhost;dbname=orangehrm;charset=utf8', 'root', '');
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    $sql = "CREATE TABLE IF NOT EXISTS ohrm_manpower_vacancy_link (
        id INT AUTO_INCREMENT PRIMARY KEY,
        manpower_request_id INT NOT NULL,
        vacancy_id INT NOT NULL,
        request_type VARCHAR(20) NOT NULL COMMENT 'Planned/Replacement/Unplanned',
        status VARCHAR(20) NOT NULL DEFAULT 'Terkait' COMMENT 'Terkait/Filled/Closed',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (manpower_request_id) REFERENCES ohrm_manpower_request(id) ON DELETE CASCADE,
        FOREIGN KEY (vacancy_id) REFERENCES ohrm_job_vacancy(id) ON DELETE CASCADE,
        UNIQUE KEY unique_link (manpower_request_id, vacancy_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    
    $conn->exec($sql);
    echo "OK: ohrm_manpower_vacancy_link created successfully.\n";
    
    // Verify
    $stmt = $conn->query("DESCRIBE ohrm_manpower_vacancy_link");
    echo "\nStructure:\n";
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "  " . $row['Field'] . " | " . $row['Type'] . " | " . $row['Null'] . " | " . ($row['Key'] ?? '') . " | " . ($row['Default'] ?? 'NULL') . "\n";
    }
    
    // Also add manpower_request_id column to ohrm_job_vacancy if not exists
    $checkCol = $conn->query("SHOW COLUMNS FROM ohrm_job_vacancy LIKE 'manpower_request_id'");
    if ($checkCol->rowCount() == 0) {
        $conn->exec("ALTER TABLE ohrm_job_vacancy ADD COLUMN manpower_request_id INT NULL AFTER id");
        echo "\nOK: Column manpower_request_id added to ohrm_job_vacancy.\n";
    } else {
        echo "\nColumn manpower_request_id already exists in ohrm_job_vacancy.\n";
    }

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
