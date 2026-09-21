<?php
// views/web/partials/threshold-badge.php
// ODZNAKA PROGÓW PUNKTOWYCH ZNANEJ TRASY — N-kąt złożony z klinów, po jednym
// klinie na próg (2026-09-11).
//
// PO CO: progi wypisane tekstem („zdobyte za progi 20/40/60/80/100%") user
// odrzucił jako niezrozumiałe i przysłał render — pięciokąt, w którym każde
// ramię to jeden próg. Pierwsze podejście (małe, blade kliny, bez pustego
// środka) odesłał z powrotem: „zobacz co wygenerowałeś, a jaka była moja
// propozycja". Stąd tutaj:
//   * PIERŚCIEŃ, nie tarcza — środek zostaje pusty, jak w renderze,
//   * białe przerwy między klinami (obrys `--paper` na każdym klinie),
//   * obwódka całego kształtu rysowana NA KOŃCU, czyli na wierzchu przerw —
//     pięciokąt ma czystą krawędź także wtedy, gdy nic nie jest zdobyte,
//   * gradient na zdobytych klinach (`--blaze` → `--blaze-dark`),
//   * klin „w trakcie": próg, do którego widz właśnie jedzie, rośnie OD
//     ŚRODKA proporcjonalnie do postępu między poprzednim a tym progiem —
//     odpowiada na „ile mi jeszcze brakuje", czego lista progów nie mówiła,
//   * `<title>` na każdym klinie („40% trasy · +76 pkt · zdobyte") — to jest
//     właściwe miejsce na liczby, które zaśmiecały podpis kafla.
//
// LICZBA KLINÓW WYNIKA Z LICZBY PROGÓW, nie jest wpisana na sztywno: licznik
// progów to ustawienie globalne w /admin/punkty
// (Models\DiscoveryScoring::trailThresholds(), dziś 5, wcześniej bywało 4).
//
// KLIN „ZDOBYTY" = widz ma co najmniej tyle procent trasy — TA SAMA reguła,
// którą liczy `DiscoveryScoring::trailAwards()`, więc odznaka i liczba punktów
// obok niej nie mogą się rozjechać.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

if (!function_exists('renderThresholdBadge')) {
    /**
     * @param array<int|string,int|string> $thresholds próg procentowy => punkty za ten próg
     * @param float|null                   $pct        postęp widza (null = gość/brak konta)
     * @return array{svg:string,done:int,steps:int} pusty `svg`, gdy trasa nie ma progów
     */
    function renderThresholdBadge(array $thresholds, ?float $pct): array
    {
        if (!$thresholds) {
            return ['svg' => '', 'done' => 0, 'steps' => 0];
        }

        // Normalizacja: klucze bywają tekstem (idą z konfiguracji), a i tak
        // muszą iść rosnąco, żeby pierwszy klin był najniższym progiem.
        $map = [];
        foreach ($thresholds as $próg => $punkty) {
            $map[(int) $próg] = (int) $punkty;
        }
        ksort($map);
        $keys = array_keys($map);
        $steps = count($keys);

        $rOut = 45.0;
        $rIn  = 14.0;
        $punkt = static fn (float $a, float $r): string
            => sprintf('%.2f,%.2f', 50.0 + $r * cos($a), 50.0 + $r * sin($a));
        // Start od góry (-90°), kliny zgodnie z ruchem wskazówek zegara:
        // najniższy próg na godzinie 12, 100% tuż przed powrotem do góry.
        $kat = static fn (int $i): float => -M_PI / 2 + $i * (2 * M_PI / $steps);

        $done = 0;
        $parts = '';
        foreach ($keys as $i => $próg) {
            $a1 = $kat($i);
            $a2 = $kat($i + 1);
            $zdobyty = $pct !== null && $pct >= $próg;
            if ($zdobyty) {
                $done++;
            }
            $opis = sprintf(
                __('%d%% trasy · +%s pkt%s'),
                $próg,
                number_format($map[$próg], 0, ',', ' '),
                $zdobyty ? ' · zdobyte' : ''
            );
            $parts .= '<path class="pts-badge__w' . ($zdobyty ? ' pts-badge__w--done' : '') . '" d="'
                . 'M' . $punkt($a1, $rOut) . ' L' . $punkt($a2, $rOut)
                . ' L' . $punkt($a2, $rIn) . ' L' . $punkt($a1, $rIn) . ' Z">'
                . '<title>' . htmlspecialchars($opis) . '</title></path>';

            // Klin „w trakcie" — z natury rzeczy tylko dla PIERWSZEGO
            // niezdobytego progu: dla dalszych ułamek wychodzi ujemny i warunek
            // go odsiewa, więc nie ma tu osobnej gałęzi „który to po kolei".
            if (!$zdobyty && $pct !== null) {
                $poprzedni = $i === 0 ? 0 : $keys[$i - 1];
                $rozpietosc = max(1, $próg - $poprzedni);
                $ulamek = max(0.0, min(1.0, ($pct - $poprzedni) / $rozpietosc));
                if ($ulamek > 0.02) {
                    $rNow = $rIn + ($rOut - $rIn) * $ulamek;
                    $parts .= '<path class="pts-badge__w pts-badge__w--now" d="'
                        . 'M' . $punkt($a1, $rNow) . ' L' . $punkt($a2, $rNow)
                        . ' L' . $punkt($a2, $rIn) . ' L' . $punkt($a1, $rIn) . ' Z">'
                        . '<title>' . htmlspecialchars(sprintf(__('w drodze do %d%%'), $próg)) . '</title></path>';
                }
            }
        }

        $rama = 'M' . implode(' L', array_map(
            static fn (int $i): string => $punkt($kat($i), $rOut),
            range(0, $steps - 1)
        )) . ' Z';
        $parts .= '<path class="pts-badge__frame" d="' . $rama . '"/>';

        $tytul = $pct !== null
            ? sprintf(__('%d z %d progów zdobytych'), $done, $steps)
            : sprintf(__('%d progów do zdobycia'), $steps);

        // Gradient ma STAŁE id: na stronie stoi jedna taka odznaka, a gdyby
        // kiedyś stało ich kilka, wszystkie chcą dokładnie tego samego
        // gradientu — powtórzone id jest wtedy nieszkodliwe.
        $svg = '<svg viewBox="0 0 100 100" class="pts-badge" role="img">'
            . '<title>' . htmlspecialchars($tytul) . '</title>'
            . '<defs><linearGradient id="pts-grad" x1="0" y1="0" x2="0" y2="1">'
            . '<stop offset="0" stop-color="var(--blaze)"/>'
            . '<stop offset="1" stop-color="var(--blaze-dark)"/>'
            . '</linearGradient></defs>'
            . $parts
            . '</svg>';

        return ['svg' => $svg, 'done' => $done, 'steps' => $steps];
    }
}
