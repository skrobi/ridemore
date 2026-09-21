<?php
// views/web/partials/metric-charts.php
// DODATKOWE KANAŁY Z PLIKU LICZNIKA — tętno, kadencja, moc, temperatura
// (2026-09-11, prośba usera: „jeśli mamy dostępne jakieś inne parametry z GPX,
// to również powinny być zwizualizowane... osobne wykresy na jednej skali km").
//
// STOI ZARAZ POD `elevation-profile.php` I DZIELI Z NIM DANE. Próbki są tą samą
// tablicą (`Gpx::sampleProfile` dokłada kanały obok wysokości), więc wykresy
// mają wspólną oś X z definicji — nie ma tu żadnego dopasowywania skal.
// Kursor jest też wspólny: najechanie na którykolwiek wykres przesuwa pozostałe
// i pinezkę na mapie (patrz `ridemoreProfileCursors` w assets/js/gpx-map.js).
//
// Oczekuje w zasięgu:
//   $mcProfile  — te same próbki, co `$epProfile` w profilu wysokości,
//   $mcChannels — `Gpx::parse()['metricChannels']`, czyli kanały, które ten
//                 plik FAKTYCZNIE niesie (z etykietą, jednostką, kolorem,
//                 średnią i maksimum).
//
// Pusty zestaw kanałów nie renderuje NIC — plik z planera albo licznik bez
// czujników jest normalnym przypadkiem, nie brakiem danych do zgłoszenia.
//
// Podpięcie do strony: `ridemoreSetupMetricCharts()` po stworzeniu mapy —
// jedna linijka, jak przy profilu wysokości.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

$mcProfile  = $mcProfile ?? null;
$mcChannels = $mcChannels ?? [];

if (!$mcProfile || !$mcChannels) { return; }
?>
<div class="metric-charts" data-metric-charts
     data-metric-profile='<?= htmlspecialchars(json_encode($mcProfile), ENT_QUOTES) ?>'>
    <?php foreach ($mcChannels as $kanal): ?>
    <?php // Specyfikacja kanału jedzie atrybutem, a nie drugim słownikiem w JS:
          // etykieta, jednostka i kolor są już opisane w `Gpx::METRIC_CHANNELS`
          // i dwa miejsca z tą samą listą rozjechałyby się przy dołożeniu
          // piątego kanału. ?>
    <div class="metric-chart" data-metric-key="<?= htmlspecialchars($kanal['key']) ?>"
         data-metric-spec='<?= htmlspecialchars(json_encode($kanal, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>'>
        <div class="metric-chart__c"></div>
    </div>
    <?php endforeach; ?>
</div>
