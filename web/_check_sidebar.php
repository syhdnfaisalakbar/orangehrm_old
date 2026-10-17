<?php
$conn = new PDO('mysql:host=localhost;dbname=orangehrm;charset=utf8', 'root', '');

// Cek menu item yang level=1 dan order_hint sekitar 99-200
$stmt = $conn->query("
    SELECT mi.id, mi.menu_title, mi.level, mi.order_hint, mi.parent_id, mi.status,
           s.id AS screen_id, s.name AS screen_name, s.action_url, s.module_id,
           m.name AS module_name
    FROM ohrm_menu_item mi
    LEFT JOIN ohrm_screen s ON mi.screen_id = s.id
    LEFT JOIN ohrm_module m ON s.module_id = m.id
    WHERE mi.level = 1
    ORDER BY mi.order_hint
");
echo "id | menu_title | order_hint | screen_id | action_url | module\n";
echo "-------------------------------------------------------------\n";
foreach ($stmt as $row) {
    echo $row['id'] . " | " . $row['menu_title'] . " | " . $row['order_hint'] . " | " . ($row['screen_id'] ?? '-') . " | " . ($row['action_url'] ?? '-') . " | " . ($row['module_name'] ?? '-') . "\n";
}

// Cek juga menu yang parent_id-nya 1 (Admin submenu)
echo "\n==== Admin submenu items ====\n";
$stmt2 = $conn->query("
    SELECT mi.id, mi.menu_title, mi.level, mi.order_hint,
           s.action_url, s.name AS screen_name
    FROM ohrm_menu_item mi
    LEFT JOIN ohrm_screen s ON mi.screen_id = s.id
    WHERE mi.parent_id = 1
    ORDER BY mi.order_hint
");
foreach ($stmt2 as $row) {
    echo $row['id'] . " | " . $row['menu_title'] . " | action=" . ($row['action_url'] ?? '-') . " | screen=" . ($row['screen_name'] ?? '-') . "\n";
}
