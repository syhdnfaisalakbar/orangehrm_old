<?php
$conn = new PDO('mysql:host=localhost;dbname=orangehrm;charset=utf8', 'root', '');

// Cek screen permissions untuk Supervisor role (id=3)
$stmt = $conn->query("
    SELECT s.name AS screen_name, s.action_url, m.name AS module,
           urs.can_read, urs.can_create, urs.can_update, urs.can_delete
    FROM ohrm_user_role_screen urs
    JOIN ohrm_screen s ON urs.screen_id = s.id
    JOIN ohrm_module m ON s.module_id = m.id
    WHERE urs.user_role_id = 3
    ORDER BY m.name, s.name
");
echo "==== SCREEN PERMISSIONS FOR SUPERVISOR ====\n";
echo "screen_name | action_url | module | R | C | U | D\n";
echo "----------------------------------------------------\n";
foreach ($stmt as $row) {
    echo $row['screen_name'] . " | " . $row['action_url'] . " | " . $row['module'] . " | " . $row['can_read'] . " | " . $row['can_create'] . " | " . $row['can_update'] . " | " . $row['can_delete'] . "\n";
}

// Cek data group permissions
echo "\n==== DATA GROUP PERMISSIONS FOR SUPERVISOR ====\n";
$stmt2 = $conn->query("
    SELECT dg.name AS data_group, urdg.can_read, urdg.can_create, urdg.can_update, urdg.can_delete
    FROM ohrm_user_role_data_group urdg
    JOIN ohrm_data_group dg ON urdg.data_group_id = dg.id
    WHERE urdg.user_role_id = 3
    ORDER BY dg.name
");
foreach ($stmt2 as $row) {
    echo $row['data_group'] . " | R=" . $row['can_read'] . " C=" . $row['can_create'] . " U=" . $row['can_update'] . " D=" . $row['can_delete'] . "\n";
}

// Cek menu items
echo "\n==== MENU ITEMS (top level) ====\n";
$stmt3 = $conn->query("SELECT id, menu_title, level, order_hint FROM ohrm_menu_item WHERE level=1 ORDER BY order_hint");
foreach ($stmt3 as $row) {
    echo $row['id'] . " | " . $row['menu_title'] . " (level=" . $row['level'] . ", order=" . $row['order_hint'] . ")\n";
}
