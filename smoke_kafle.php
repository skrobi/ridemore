<?php
// smoke_kafle.php — podgląd paska statystyk usera 1 po poprawce
require __DIR__ . '/core/bootstrap.php';
$_SESSION['user_id'] = 1; $_GET = [];
ob_start();
Controllers\DiscoveryController::index();
$html = (string) ob_get_clean();

$txt = trim(preg_replace('/\s+/', ' ', strip_tags($html)));
$start = strpos($txt, 'Twoje punkty');
echo substr($txt, $start, 700), "\n";
