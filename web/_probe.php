<?php
$base = 'http://172.25.0.1:8080/orangehrm';
$jar = 'C:/xampp/htdocs/orangehrm/web/_tmp_cookies.txt';
@unlink($jar);
$p = new PDO('mysql:host=localhost;dbname=orangehrm', 'root', '');
$orig = $p->query("SELECT user_password FROM ohrm_user WHERE user_name='admin'")->fetchColumn();
// set temp pass
$p->prepare("UPDATE ohrm_user SET user_password=? WHERE user_name='admin'")->execute([password_hash('TempPass123!', PASSWORD_BCRYPT, ['cost'=>12])]);

function req($url, $post = null, $jar) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_COOKIEJAR=>$jar, CURLOPT_COOKIEFILE=>$jar,
        CURLOPT_FOLLOWLOCATION=>false, CURLOPT_TIMEOUT=>30, CURLOPT_HEADER=>false]);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $post); }
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$code, $r];
}
[$c, $b] = req($base.'/web/auth/login', null, $jar);
preg_match('/:token="&quot;([^&]+)/', $b, $m); $t = $m[1];
req($base.'/web/auth/validate', http_build_query(['username'=>'admin','password'=>'TempPass123!','_token'=>$t]), $jar);

$urls = [
  'viewSystemUsers (screen1 shell)' => '/web/admin/viewSystemUsers',
  'viewSystemUsers?page=quota-request' => '/web/admin/viewSystemUsers?page=quota-request',
  'viewSystemUsers?page=all-quota-department' => '/web/admin/viewSystemUsers?page=all-quota-department',
  'viewSystemUsers?page=resource-fulfillment' => '/web/admin/viewSystemUsers?page=resource-fulfillment',
];
foreach ($urls as $label => $r) {
    [$code, $body] = req($base.$r, null, $jar);
    preg_match('/:sidepanel-menu-items="(\[[^\]]*\])"/', $body, $sp);
    preg_match('/:topbar-menu-items="(\[[^\]]*\])"/', $body, $tm);
    $comp = 'class="view-';
    preg_match('/<view-([a-z0-9-]+)/i', $body, $vm); // rendered component tag
    echo "[$label]\n HTTP=$code size=".strlen($body)." component=".($vm[1]??'(none)')."\n";
    echo " TOPBAR: ".substr($tm[1] ?? '(none)',0,700)."\n\n";
}
// revert pass
$p->prepare("UPDATE ohrm_user SET user_password=? WHERE user_name='admin'")->execute([$orig]);
echo "admin password REVERTED to original.\n";
@unlink($jar);