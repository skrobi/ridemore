<?php
// tests/dashboard.php
// DASHBOARD TESTÓW — web UI + CLI runner.
//
// WEB (lokalnie, tylko APP_ENV=dev):
//   http://localhost/ridemore/tests/dashboard.php
//   — przyciski: uruchom pojedynczy zestaw albo wszystkie,
//   — logi wykonawcze ostatniego przebiegu (per zestaw, rozwijane),
//   — historia ostatnich 50 przebiegów z logami błędów.
//   Poza APP_ENV=dev strona odpowiada 403 — testy PISZĄ DO BAZY (transakcje
//   są wycofywane, ale nie ma powodu, żeby ktokolwiek z sieci to wywoływał).
//
// CLI (to samo, z linii poleceń):
//   php tests/dashboard.php           — uruchom wszystkie zestawy, wydruk podsumowania
//   php tests/dashboard.php --open    — to samo i otwórz dashboard w przeglądarce
//
// Zestawy są odpalane OSOBNYMI procesami (php tests/run.php <nazwa>), dzięki
// czemu błąd jednego nie przerywa reszty, a wynik i czas są per zestaw.
// Wyniki: tests/results/history.json (50 ostatnich przebiegów).

$isCli = PHP_SAPI === 'cli';

require __DIR__ . '/../core/bootstrap.php';

if (APP_ENV !== 'dev') {
    if ($isCli) {
        fwrite(STDERR, "Testy uruchamiamy WYŁĄCZNIE na APP_ENV=dev. Teraz: " . APP_ENV . "\n");
        exit(2);
    }
    http_response_code(403);
    exit("Dashboard testów jest dostępny tylko na APP_ENV=dev.\n");
}

const HISTORY_CAP = 50;

$resultsDir  = __DIR__ . '/results';
$historyFile = $resultsDir . '/history.json';
if (!is_dir($resultsDir)) {
    mkdir($resultsDir, 0755, true);
}

// --- wspólne --------------------------------------------------------------

function loadHistory(): array
{
    global $historyFile;
    if (!is_file($historyFile)) {
        return [];
    }
    $decoded = json_decode((string) file_get_contents($historyFile), true);
    return is_array($decoded) ? $decoded : [];
}

function saveHistory(array $history): void
{
    global $historyFile;
    if (count($history) > HISTORY_CAP) {
        $history = array_slice($history, -HISTORY_CAP);
    }
    file_put_contents($historyFile, json_encode($history, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function suiteNames(): array
{
    $files = glob(__DIR__ . '/*_test.php') ?: [];
    sort($files);
    return array_map(static fn(string $f): string => basename($f, '_test.php'), $files);
}

/**
 * Ścieżka do php.exe, którą można ODPALIĆ z wnętrza requestu.
 *
 * PHP_BINARY jest pułapką: pod mod_php (Apache) wskazuje na httpd.exe, a nie
 * na PHP — proc_open z takim "binarnym" odpalal nową instancję Apache, która
 * padała z AH02965 (exit 3, zero testów). PHP_BINDIR też nie jest wiarygodny
 * (XAMPP kompiluje z prefixem C:\php, którego na dysku nie ma). Kolejność:
 * 1) PHP_BINARY, jeśli faktycznie jest PHP-em; 2) PHP_BINDIR, jeśli istnieje;
 * 3) goła nazwa 'php' — Windows znajdzie ją po PATH (CreateProcess).
 */
function phpBinary(): string
{
    $binary = PHP_BINARY;
    if (is_file($binary) && preg_match('/php(\.exe)?$/i', basename($binary))) {
        return $binary;
    }
    $bindir = PHP_BINDIR . DIRECTORY_SEPARATOR . 'php.exe';
    if (is_file($bindir)) {
        return $bindir;
    }
    return 'php';
}

function runSuite(string $name): array
{
    $proc = proc_open(
        [phpBinary(), __DIR__ . '/run.php', $name],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($proc)) {
        return ['exit' => -1, 'output' => 'proc_open zawiódł', 'duration_ms' => 0];
    }
    $start = microtime(true);
    $out   = stream_get_contents($pipes[1]);
    $out  .= stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($proc);

    return [
        'exit'        => $exit,
        'output'      => $out,
        'duration_ms' => (int) round((microtime(true) - $start) * 1000),
    ];
}

/** Podsumowanie z ostatniej linii run.php: "OK: 12 przeszło, 0 nie przeszło (345 ms)". */
function suiteCounts(string $output): array
{
    if (preg_match('/(?:OK|BŁĘDY): (\d+) przeszło, (\d+) nie przeszło \((\d+) ms\)/', $output, $m)) {
        return ['passed' => (int) $m[1], 'failed' => (int) $m[2], 'duration_ms' => (int) $m[3]];
    }
    return ['passed' => 0, 'failed' => 0, 'duration_ms' => 0];
}

/** Przebieg wszystkich zestawów. */
function runAll(array $names): array
{
    $run = [
        'ts'          => date('c'),
        'db'          => APP_CONFIG['db']['name'],
        'php'         => PHP_VERSION,
        'duration_ms' => 0,
        'suites'      => [],
        'total'       => 0,
        'passed'      => 0,
        'failed'      => 0,
    ];
    $runStart = microtime(true);

    foreach ($names as $name) {
        $res      = runSuite($name);
        $counts   = suiteCounts($res['output']);
        $res['name']        = $name;
        $res['passed']      = $counts['passed'];
        $res['failed']      = $counts['failed'];
        if ($counts['duration_ms'] > 0) {
            $res['duration_ms'] = $counts['duration_ms'];
        }
        normalizeSuiteResult($res);

        $run['suites'][] = $res;
        $run['total']  += $res['passed'] + $res['failed'];
        $run['passed'] += $res['passed'];
        $run['failed'] += $res['failed'];
    }

    $run['duration_ms'] = (int) round((microtime(true) - $runStart) * 1000);
    $run['ok'] = $run['failed'] === 0;
    return $run;
}

/** Przebieg jednego zestawu (ten sam kształt danych co runAll). */
function runOne(string $name): array
{
    $run = [
        'ts'          => date('c'),
        'db'          => APP_CONFIG['db']['name'],
        'php'         => PHP_VERSION,
        'duration_ms' => 0,
        'suites'      => [],
        'total'       => 0,
        'passed'      => 0,
        'failed'      => 0,
    ];
    $start = microtime(true);

    $res    = runSuite($name);
    $counts = suiteCounts($res['output']);
    $res['name']        = $name;
    $res['passed']      = $counts['passed'];
    $res['failed']      = $counts['failed'];
    if ($counts['duration_ms'] > 0) {
        $res['duration_ms'] = $counts['duration_ms'];
    }
    normalizeSuiteResult($res);
    $run['suites'][] = $res;
    $run['total']  = $res['passed'] + $res['failed'];
    $run['passed'] = $res['passed'];
    $run['failed'] = $res['failed'];

    $run['duration_ms'] = (int) round((microtime(true) - $start) * 1000);
    $run['ok'] = $run['failed'] === 0;
    return $run;
}

/**
 * Wynik zestawu musi być spójny z KODEM WYJŚCIA, nie tylko z podsumowaniem
 * z outputu. Proces, który wywalił (exit≠0, np. uruchomiony zły binarny),
 * ale nie zostawił linii "OK/BŁĘDY: X przeszło" był liczony jako PASS — stąd
 * fałszywe "wszystko przeszło" przy 0 testach. Też pusta suma testów przy
 * exit=0 to anomalia warta oznaczenia.
 */
function normalizeSuiteResult(array &$res): void
{
    if ($res['exit'] !== 0 && $res['failed'] === 0) {
        $res['failed'] = 1;
    }
    if ($res['passed'] === 0 && $res['failed'] === 0) {
        $res['failed'] = 1;
        $res['output'] .= "\n[Uwaga] proces nie wykonał żadnego testu — podejrzany binarny PHP: "
            . phpBinary() . " (PHP_BINARY=" . PHP_BINARY . ")";
    }
}

// --- CLI -------------------------------------------------------------------

if ($isCli) {
    $open = in_array('--open', $argv, true);
    $run  = runAll(suiteNames());

    foreach ($run['suites'] as $s) {
        printf("  %-16s %s  (%d ms)%s\n",
            $s['name'],
            $s['failed'] === 0 ? 'PASS' : 'FAIL',
            $s['duration_ms'],
            $s['failed'] !== 0 ? ' — błąd, szczegóły w dashboardzie' : ''
        );
    }
    $history = loadHistory();
    $history[] = $run;
    saveHistory($history);

    echo "\n" . ($run['ok'] ? 'OK' : 'BŁĘDY') . ": {$run['passed']} przeszło, {$run['failed']} nie przeszło"
       . " ({$run['duration_ms']} ms, " . count($run['suites']) . " zestawów)\n";

    if ($open) {
        $url = 'http://localhost/ridemore/tests/dashboard.php';
        echo "Otwieram: $url\n";
        shell_exec(strncasecmp(PHP_OS, 'WIN', 3) === 0
            ? 'start "" "' . $url . '"'
            : 'xdg-open "' . $url . '"');
    }

    exit($run['ok'] ? 0 : 1);
}

// --- WEB -------------------------------------------------------------------

$sessionStarted = false;
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
    $sessionStarted = true;
}
$csrf = $_SESSION['dashboard_csrf'] ?? null;
if ($csrf === null) {
    $csrf = bin2hex(random_bytes(16));
    $_SESSION['dashboard_csrf'] = $csrf;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_string($csrf) || !hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        exit('Zły token CSRF.');
    }

    $action = (string) ($_POST['action'] ?? '');
    $names  = suiteNames();

    if ($action === 'run-all') {
        $run = runAll($names);
    } elseif ($action === 'run-suite') {
        $name = basename((string) ($_POST['suite'] ?? ''));
        if (!in_array($name, $names, true)) {
            http_response_code(400);
            exit('Nieznany zestaw: ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8'));
        }
        $run = runOne($name);
    } else {
        http_response_code(400);
        exit('Nieznana akcja.');
    }

    $history = loadHistory();
    $history[] = $run;
    saveHistory($history);

    // PRG — odświeżenie strony nie ponawia testów.
    header('Location: dashboard.php#ostatni', true, 302);
    exit;
}

$history = loadHistory();
$latest  = $history ? $history[count($history) - 1] : null;

function esc($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function fmtMs(int $ms): string
{
    return $ms >= 1000 ? number_format($ms / 1000, 2, ',', '') . ' s' : $ms . ' ms';
}

function fmtDate(string $ts): string
{
    $d = date_create($ts);
    return $d ? $d->format('Y-m-d H:i:s') : $ts;
}

?><!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Testy — ridemore.bike</title>
<style>
  :root{
    --bg:#F4F5F1; --paper:#fff; --ink:#15201A; --mute:#6B6A64; --hair:#E3E4DC;
    --ok:#2C6B4F; --ok-soft:#E4EFE8; --bad:#B3362D; --bad-soft:#F7E8E6;
    --mono:ui-monospace,SFMono-Regular,Consolas,monospace;
  }
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.5 system-ui,Segoe UI,Roboto,sans-serif}
  .wrap{max-width:1100px;margin:0 auto;padding:28px 20px 60px}
  h1{font-size:22px;margin:0 0 4px}
  .sub{color:var(--mute);font-size:13px;margin-bottom:20px}
  .cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:16px}
  .card{background:var(--paper);border:1px solid var(--hair);border-radius:10px;padding:14px 16px}
  .card .n{font:700 26px var(--mono)}
  .card .l{color:var(--mute);font-size:12px;margin-top:2px}
  .card.ok .n{color:var(--ok)} .card.bad .n{color:var(--bad)}
  .badge{display:inline-block;padding:2px 10px;border-radius:999px;font-size:12px;font-weight:600}
  .badge.ok{background:var(--ok-soft);color:var(--ok)} .badge.bad{background:var(--bad-soft);color:var(--bad)}
  .btn{display:inline-block;padding:8px 14px;border:1px solid var(--hair);border-radius:8px;
       background:var(--paper);color:var(--ink);font:600 13px inherit;cursor:pointer}
  .btn:hover{border-color:#C9CBC0}
  .btn.primary{background:var(--ink);border-color:var(--ink);color:#fff}
  .btn:disabled{opacity:.55;cursor:wait}
  h2{font-size:16px;margin:28px 0 10px}
  table{width:100%;border-collapse:collapse;background:var(--paper);border:1px solid var(--hair);
        border-radius:10px;overflow:hidden;font-size:14px}
  th,td{padding:8px 10px;text-align:left;border-bottom:1px solid var(--hair)}
  th{background:#FAFAF7;font-size:12px;color:var(--mute);font-weight:600}
  tr:last-child td{border-bottom:none}
  td.num{font:600 13px var(--mono);text-align:right}
  .mono{font-family:var(--mono);font-size:12px}
  details{border:1px solid var(--hair);border-radius:8px;background:var(--paper);margin:6px 0;padding:8px 12px}
  summary{cursor:pointer;font-weight:600}
  pre{background:#1B2420;color:#D7E3DA;padding:12px;border-radius:8px;overflow:auto;
      font:12px/1.5 var(--mono);white-space:pre-wrap}
  .chart{display:flex;align-items:flex-end;gap:6px;height:120px;padding:10px;background:var(--paper);
         border:1px solid var(--hair);border-radius:10px;overflow-x:auto}
  .bar{display:flex;flex-direction:column-reverse;width:22px;min-width:22px}
  .bar .ok{background:var(--ok)} .bar .bad{background:var(--bad)}
  .bar .cap{font:10px var(--mono);color:var(--mute);text-align:center;margin-top:4px}
  .empty{color:var(--mute);padding:16px;text-align:center}
  .runrow{display:flex;align-items:center;gap:10px}
  .runrow form{margin:0}
</style>
</head>
<body>
<div class="wrap">
  <h1>Testy — ridemore.bike</h1>
  <div class="sub">baza: <?= esc($latest['db'] ?? APP_CONFIG['db']['name']) ?> · PHP <?= esc(PHP_VERSION) ?>
    · środowisko: <?= esc(APP_ENV) ?></div>

  <?php if ($latest): ?>
    <?php $ok = $latest['failed'] === 0; ?>
    <div class="cards" id="ostatni">
      <div class="card"><div class="n"><?= $latest['total'] ?></div><div class="l">testów</div></div>
      <div class="card ok"><div class="n"><?= $latest['passed'] ?></div><div class="l">przeszło</div></div>
      <div class="card<?= $latest['failed'] ? ' bad' : '' ?>"><div class="n"><?= $latest['failed'] ?></div><div class="l">błędów</div></div>
      <div class="card"><div class="n"><?= count($latest['suites']) ?></div><div class="l">zestawów</div></div>
      <div class="card"><div class="n"><?= fmtMs($latest['duration_ms']) ?></div><div class="l">czas</div></div>
    </div>
    <span class="badge <?= $ok ? 'ok' : 'bad' ?>"><?= $ok ? 'WSZYSTKO PRZESZŁO' : 'SĄ BŁĘDY' ?></span>
    <span class="mono" style="color:var(--mute)"> <?= fmtDate($latest['ts']) ?></span>
  <?php else: ?>
    <div class="empty">Brak przebiegów — uruchom testy przyciskiem poniżej.</div>
  <?php endif; ?>

  <h2>Uruchom</h2>
  <div class="runrow">
    <form method="post" class="js-run">
      <input type="hidden" name="csrf" value="<?= esc($csrf) ?>">
      <input type="hidden" name="action" value="run-all">
      <button class="btn primary" type="submit" data-label="Uruchom wszystkie">Uruchom wszystkie</button>
    </form>
    <span class="mono" style="color:var(--mute)">php tests/run.php — pojedyncze zestawy:</span>
    <?php foreach (suiteNames() as $name): ?>
      <form method="post" class="js-run">
        <input type="hidden" name="csrf" value="<?= esc($csrf) ?>">
        <input type="hidden" name="action" value="run-suite">
        <input type="hidden" name="suite" value="<?= esc($name) ?>">
        <button class="btn" type="submit" data-label="<?= esc($name) ?>"><?= esc($name) ?></button>
      </form>
    <?php endforeach; ?>
  </div>

  <?php if ($latest): ?>
    <h2>Ostatni przebieg — logi wykonawcze</h2>
    <table>
      <thead><tr><th>Zestaw</th><th>Wynik</th><th class="num">Przeszło</th><th class="num">Błędy</th><th class="num">Czas</th><th>Log</th></tr></thead>
      <tbody>
      <?php foreach ($latest['suites'] as $s): ?>
        <tr>
          <td><?= esc($s['name']) ?></td>
          <td><?= $s['failed'] === 0 ? '<span class="badge ok">PASS</span>' : '<span class="badge bad">FAIL</span>' ?></td>
          <td class="num"><?= $s['passed'] ?></td>
          <td class="num"><?= $s['failed'] ?></td>
          <td class="num mono"><?= fmtMs($s['duration_ms']) ?></td>
          <td><details><summary>wyjście</summary><pre><?= esc($s['output']) ?></pre></details></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <h2>Historia — wykres (ostatnie 20 przebiegów)</h2>
  <?php
    $chartRuns = array_slice($history, -20);
    $maxTotal = 1;
    foreach ($chartRuns as $r) { $maxTotal = max($maxTotal, $r['total'] ?? 0); }
  ?>
  <div class="chart">
    <?php if (!$chartRuns): ?>
      <div class="empty">Brak historii.</div>
    <?php else: foreach ($chartRuns as $r): ?>
      <?php
        $h   = max(2, (int) round(($r['total'] / $maxTotal) * 100));
        $okH = (int) round(($r['passed'] / max(1, $r['total'])) * $h);
        $badH = $h - $okH;
      ?>
      <div class="bar" title="<?= esc(fmtDate($r['ts'])) ?> — <?= $r['passed'] ?>/<?= $r['total'] ?>">
        <?php if ($badH > 0): ?><div class="bad" style="height:<?= $badH ?>px"></div><?php endif; ?>
        <?php if ($okH > 0): ?><div class="ok" style="height:<?= $okH ?>px"></div><?php endif; ?>
        <div class="cap"><?= $r['total'] ?></div>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <h2>Historia — tabela</h2>
  <table>
    <thead><tr><th>Kiedy</th><th>Zestawy</th><th class="num">Przeszło</th><th class="num">Błędy</th><th class="num">Czas</th><th>Wynik</th><th>Logi błędów</th></tr></thead>
    <tbody>
    <?php if (!$history): ?>
      <tr><td colspan="7" class="empty">Brak historii.</td></tr>
    <?php else: foreach (array_reverse($history) as $r): ?>
      <?php
        $ok = ($r['failed'] ?? 1) === 0;
        $failedSuites = array_values(array_filter($r['suites'] ?? [], static fn($s) => ($s['failed'] ?? 1) !== 0));
      ?>
      <tr>
        <td><?= esc(fmtDate($r['ts'])) ?></td>
        <td><?= count($r['suites'] ?? []) ?></td>
        <td class="num"><?= $r['passed'] ?></td>
        <td class="num" style="color:<?= $ok ? 'var(--mute)' : 'var(--bad)' ?>"><?= $r['failed'] ?></td>
        <td class="num mono"><?= fmtMs($r['duration_ms'] ?? 0) ?></td>
        <td><span class="badge <?= $ok ? 'ok' : 'bad' ?>"><?= $ok ? 'OK' : 'BŁĘDY' ?></span></td>
        <td>
          <?php if ($failedSuites): ?>
            <details>
              <summary><?= count($failedSuites) ?> zestaw(y)</summary>
              <?php foreach ($failedSuites as $fs): ?>
                <pre><?= esc('[' . $fs['name'] . "]\n" . $fs['output']) ?></pre>
              <?php endforeach; ?>
            </details>
          <?php else: ?>
            <span style="color:var(--mute)">—</span>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<script>
  // Przycisk "Uruchom" blokuje się na czas przebiegu, żeby nie dało się
  // odpalić dwóch serii naraz i żeby było widać, że coś się dzieje.
  document.querySelectorAll('form.js-run').forEach(function (f) {
    f.addEventListener('submit', function () {
      var b = f.querySelector('button');
      b.disabled = true;
      b.textContent = b.getAttribute('data-label') + ' — trwa…';
    });
  });
</script>
</body>
</html>