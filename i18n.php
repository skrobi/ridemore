<?php
// i18n.php — narzędzie słownika interfejsu (wielojęzyczność, tasks/active/wielojezycznosc.md).
//
//   php i18n.php stats   [kod]   ile tekstów jest, ile przetłumaczonych
//   php i18n.php missing [kod]   lista tekstów bez tłumaczenia (jeden na linię)
//   php i18n.php export  [kod]   brakujące jako JSON {"polski": ""} — do przetłumaczenia
//   php i18n.php js      [kod]   przebudowuje core/lang/{kod}-js.php (podzbiór dla JavaScriptu)
//   php i18n.php unused  [kod]   wpisy słownika, których nie ma już w kodzie
//
// SKĄD TEKSTY: argumenty __() / __n() w PHP (także sklejane '…' . '…' i z ternary),
// __('…') w JavaScripcie (assets/js i skrypty w widokach), nazwy pozycji słowników
// z bazy, treści domyślne powiadomień i kilka stałych pokazywanych przez __()
// w miejscu użycia. Klucz = polski tekst ze ściśniętymi białymi znakami — dokładnie
// tak, jak szuka go Core\Lang::t().
//
// Tylko CLI — blokada PHP_SAPI tu i w .htaccess (FilesMatch), jak cron.php i tiles.php.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/core/bootstrap.php';

$polecenie = $argv[1] ?? 'stats';
$lang = $argv[2] ?? 'en';
if (!preg_match('~^[a-z]{2}$~', $lang) || $lang === Core\Lang::DOMYSLNY) {
    fwrite(STDERR, "Podaj kod języka innego niż polski.\n");
    exit(2);
}

function i18n_norm(string $s): string
{
    return preg_replace('/\s+/u', ' ', trim($s));
}

/** Wartość literału PHP (pojedyncze i podwójne cudzysłowy bez interpolacji). */
function i18n_literal(string $tok): ?string
{
    if ($tok[0] === "'") {
        return str_replace(["\\'", '\\\\'], ["'", '\\'], substr($tok, 1, -1));
    }
    if ($tok[0] === '"') {
        $inner = substr($tok, 1, -1);
        return preg_match('/(?<!\\\\)\$/', $inner) ? null : stripcslashes($inner);
    }
    return null;
}

/**
 * Argumenty wywołania od tokenu '(' — każdy jako lista wariantów tekstu
 * (ternary daje dwa, sklejanie literałów jeden, wyrażenie dynamiczne zero).
 *
 * @return array<int,string[]>
 */
function i18n_args(array $t, int $open): array
{
    $args = [];
    $cur = [];
    $depth = 0;
    for ($i = $open; $i < count($t); $i++) {
        $x = is_array($t[$i]) ? $t[$i][1] : $t[$i];
        if (in_array($x, ['(', '[', '{'], true)) {
            $depth++;
            if ($depth === 1) {
                continue;
            }
        } elseif (in_array($x, [')', ']', '}'], true)) {
            $depth--;
            if ($depth === 0) {
                $args[] = $cur;
                break;
            }
        } elseif ($x === ',' && $depth === 1) {
            $args[] = $cur;
            $cur = [];
            continue;
        }
        $cur[] = $t[$i];
    }
    $out = [];
    foreach ($args as $arg) {
        $galezie = [[]];
        $d = 0;
        foreach ($arg as $tok) {
            $x = is_array($tok) ? $tok[1] : $tok;
            if (in_array($x, ['(', '['], true)) $d++;
            if (in_array($x, [')', ']'], true)) $d--;
            if ($d === 0 && ($x === '?' || $x === ':')) {
                $galezie[] = [];
                continue;
            }
            $galezie[count($galezie) - 1][] = $tok;
        }
        $warianty = [];
        foreach ($galezie as $g) {
            $tekst = '';
            $ok = false;
            foreach ($g as $tok) {
                if (is_array($tok) && in_array($tok[0], [T_WHITESPACE, T_COMMENT], true)) continue;
                if ($tok === '.') continue;
                if (is_array($tok) && $tok[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $v = i18n_literal($tok[1]);
                    if ($v === null) { $ok = false; break; }
                    $tekst .= $v;
                    $ok = true;
                    continue;
                }
                $ok = false;
                break;
            }
            if ($ok && trim($tekst) !== '') {
                $warianty[] = $tekst;
            }
        }
        $out[] = $warianty;
    }
    return $out;
}

/** @return array{0:array<string,true>,1:array<string,true>} [wszystkie, używane w JS] */
function i18n_scan(): array
{
    $wszystkie = [];
    $js = [];
    $katalogi = ['views', 'core', 'api', 'admin', 'web'];
    $pliki = ['cron.php', 'index.php'];
    foreach ($katalogi as $k) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/' . $k, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $p = str_replace('\\', '/', $f->getPathname());
            if ($f->getExtension() === 'php' && !str_contains($p, '/core/lang/')) {
                $pliki[] = $p;
            }
        }
    }
    $jsRegex = '/(?<![\w$])__\(\s*(\'(?:[^\'\\\\\n]|\\\\.)*\'|"(?:[^"\\\\\n]|\\\\.)*")/u';
    foreach ($pliki as $plik) {
        $t = token_get_all(file_get_contents($plik));
        foreach ($t as $i => $tok) {
            if (is_array($tok) && $tok[0] === T_INLINE_HTML) {
                if (preg_match_all($jsRegex, $tok[1], $m)) {
                    foreach ($m[1] as $lit) {
                        $v = i18n_norm(stripcslashes(substr($lit, 1, -1)));
                        if ($v !== '') { $wszystkie[$v] = true; $js[$v] = true; }
                    }
                }
                continue;
            }
            // __() / __n(), a także lokalne $plural(n, …) i Format::plural(n, …) —
            // formy liczby mnogiej tłumaczy Lang::plural, każdą osobno.
            $jakPlural = is_array($tok) && (($tok[0] === T_VARIABLE && $tok[1] === '$plural')
                || ($tok[0] === T_STRING && $tok[1] === 'plural' && is_array($t[$i - 1] ?? null) && $t[$i - 1][0] === T_DOUBLE_COLON));
            if (!$jakPlural && (!is_array($tok) || $tok[0] !== T_STRING || !in_array($tok[1], ['__', '__n'], true))) continue;
            $j = $i + 1;
            while (isset($t[$j]) && is_array($t[$j]) && $t[$j][0] === T_WHITESPACE) $j++;
            if (($t[$j] ?? null) !== '(') continue;
            $args = i18n_args($t, $j);
            $ktore = $tok[1] === '__' ? [0] : [1, 2, 3];  // __n i oba warianty plural: formy to argumenty 1–3
            foreach ($ktore as $n) {
                foreach ($args[$n] ?? [] as $v) {
                    $wszystkie[i18n_norm($v)] = true;
                }
            }
        }
    }
    foreach (glob(__DIR__ . '/assets/js/*.js') as $plik) {
        if (preg_match_all($jsRegex, file_get_contents($plik), $m)) {
            foreach ($m[1] as $lit) {
                $v = i18n_norm(stripcslashes(substr($lit, 1, -1)));
                if ($v !== '') { $wszystkie[$v] = true; $js[$v] = true; }
            }
        }
    }

    // Teksty pokazywane przez __() w miejscu użycia, a zapisane poza wywołaniem.
    $stale = [
        [Models\NotificationTexts::class, 'POWIADOMIENIA', static function (array $v) {
            $out = [];
            foreach ($v as $p) { foreach ($p['domyslne'] ?? [] as $t) { $out[] = $t; } }
            return $out;
        }],
        [Utils\DeviceApi::class, 'META', static fn(array $v) => array_values(array_filter(array_column($v, 'note')))],
        [Utils\Gpx::class, 'METRIC_CHANNELS', static fn(array $v) => array_column($v, 'label')],
        [Controllers\UnsubscribeController::class, 'OPISY', static fn(array $v) => array_merge(array_values($v), ['te powiadomienia'])],
        [Models\PointLedger::class, 'LABELS', static fn(array $v) => array_values($v)],
    ];
    foreach ($stale as [$klasa, $stala, $wyciag]) {
        try {
            $wartosc = (new ReflectionClassConstant($klasa, $stala))->getValue();
            foreach ($wyciag($wartosc) as $txt) {
                if (is_string($txt) && trim($txt) !== '') {
                    // Treści powiadomień mają HTML i złamania linii — klucz tak jak w Lang::t.
                    $wszystkie[i18n_norm($txt)] = true;
                }
            }
        } catch (Throwable $e) {
            fwrite(STDERR, "pominięto $klasa::$stala: {$e->getMessage()}\n");
        }
    }

    // Słowniki z bazy (bez regionów — nazwy własne; kraje tak).
    $pdo = Core\Database::connection();
    $rows = $pdo->query("
        SELECT di.name, di.meta, d.code AS dict, di.parent_id,
               (SELECT COUNT(*) FROM dictionary_items c WHERE c.parent_id = di.id) AS dzieci
          FROM dictionary_items di JOIN dictionaries d ON d.id = di.dictionary_id
    ")->fetchAll();
    foreach ($rows as $r) {
        if ($r['dict'] === 'region' && (int) $r['dzieci'] === 0 && $r['parent_id'] !== null) {
            continue;
        }
        $wszystkie[i18n_norm($r['name'])] = true;
        $meta = $r['meta'] ? json_decode($r['meta'], true) : null;
        foreach (($meta['contexts'] ?? []) as $ctx) {
            if (!empty($ctx['hint'])) {
                $wszystkie[i18n_norm($ctx['hint'])] = true;
            }
        }
    }

    unset($wszystkie['']);
    ksort($wszystkie);
    ksort($js);
    return [$wszystkie, $js];
}

[$wszystkie, $js] = i18n_scan();
$slownik = Core\Lang::dictionary($lang);

switch ($polecenie) {
    case 'stats':
        $jest = count(array_filter(array_keys($wszystkie), static fn($k) => isset($slownik[$k])));
        printf("tekstów: %d, przetłumaczonych (%s): %d, brakuje: %d, w JS: %d\n", count($wszystkie), $lang, $jest, count($wszystkie) - $jest, count($js));
        break;
    case 'missing':
        foreach (array_keys($wszystkie) as $k) {
            if (!isset($slownik[$k])) echo $k, "\n";
        }
        break;
    case 'export':
        $brak = [];
        foreach (array_keys($wszystkie) as $k) {
            if (!isset($slownik[$k])) $brak[$k] = '';
        }
        echo json_encode($brak, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
        break;
    case 'js':
        $out = [];
        foreach (array_keys($js) as $k) {
            if (isset($slownik[$k]) && is_string($slownik[$k])) $out[$k] = $slownik[$k];
        }
        $plik = __DIR__ . '/core/lang/' . $lang . '-js.php';
        file_put_contents($plik, "<?php\n// WYGENEROWANE: php i18n.php js $lang — nie edytuj ręcznie, edytuj core/lang/$lang.php.\n// Podzbiór słownika dla JavaScriptu (window.RM_I18N w partials/head.php).\nreturn " . var_export($out, true) . ";\n");
        printf("zapisano %s (%d wpisów)\n", $plik, count($out));
        break;
    case 'unused':
        foreach (array_keys($slownik) as $k) {
            if (is_string($k) && !isset($wszystkie[$k]) && !str_contains($k, '|') && $k[0] !== '@') echo $k, "\n";
        }
        break;
    default:
        fwrite(STDERR, "Nieznane polecenie.\n");
        exit(2);
}
