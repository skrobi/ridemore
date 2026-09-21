<?php
// core/Utils/PythonBridge.php
// URUCHAMIANIE SKRYPTU PYTHONA Z PHP — jeden proces na jedno żądanie.
//
// Ten plik powstał przez WYCIĄGNIĘCIE mechaniki z Utils\AiEngineBridge, gdy
// pojawił się drugi skrypt Pythona (ai-engine/garmin.py, import z Garmin
// Connect). Cała trudna część — timeout bez wbudowanego timeoutu w proc_open,
// czytanie pipe'ów W TRAKCIE pracy procesu (inaczej pełny bufor zawiesza
// Pythona) i podniesienie max_execution_time PHP NAD własny limit — została
// raz zdiagnozowana na żywym ruchu i nie ma powodu, żeby istniała w dwóch
// kopiach, które będą się rozjeżdżać.
//
// Kontrakt wejścia/wyjścia jest wspólny dla wszystkich skryptów: JSON na
// stdin, JEDEN JSON na stdout w kształcie {"ok": bool, ...}, exit code zawsze
// 0. Interpretacja pola "ok" należy do wywołującego (silnik AI rzuca wyjątek,
// Garmin rozróżnia jeszcze `code`), dlatego ta klasa zwraca CAŁĄ kopertę.
//
// Bezpieczeństwo: proc_open z argumentami w TABLICY (nie shell_exec ze
// sklejanym stringiem) — zero ryzyka command injection. Dane wejściowe idą
// przez stdin, nie przez argv, więc ani ich rozmiar, ani treść (hasło do
// Garmina!) nie mają wpływu na budowę polecenia i nie pokazują się na liście
// procesów systemu.
namespace Utils;

class PythonBridge
{
    /**
     * @param  array $config ['python_bin' => string, 'script' => string, 'timeout_seconds' => int]
     * @param  array $payload trafia do Pythona 1:1 jako JSON na stdin
     * @param  string $label nazwa użyta w komunikatach błędów widocznych dla człowieka
     * @return array zdekodowana koperta {"ok": ...} ze stdout skryptu
     */
    public static function run(array $config, array $payload, string $label): array
    {
        if (!is_file($config['script'])) {
            throw new \RuntimeException('Brak skryptu (' . $config['script'] . ') — patrz ai-engine/README.md.');
        }

        // BUG naprawiony na żywo (2026-08-07, prawdziwa strona): domyślny PHP
        // max_execution_time (120s w typowym php.ini XAMPP) ubijał żądanie
        // fatal errorem, ZANIM poniższy licznik $deadline zdążył sam przerwać
        // proces i zwrócić czytelny komunikat. Podnosimy limit PHP z marginesem
        // NAD timeout_seconds, żeby to WŁAŚNIE poniższa pętla (proc_terminate +
        // czytelny RuntimeException) była tą, która faktycznie ubija zawieszony
        // proces — nie surowy fatal error Apache/php-fpm. set_time_limit działa
        // tylko dla TEGO requestu, nie zmienia globalnego php.ini.
        $timeout = (int) $config['timeout_seconds'];
        set_time_limit($timeout + 15);

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open([$config['python_bin'], $config['script']], $descriptors, $pipes, dirname($config['script']));
        if (!is_resource($process)) {
            throw new \RuntimeException('Nie udało się uruchomić Pythona (' . $config['python_bin'] . ') — sprawdź konfigurację w core/config.php.');
        }

        fwrite($pipes[0], json_encode($payload, JSON_UNESCAPED_UNICODE));
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        // proc_open nie ma wbudowanego timeoutu — pollujemy proc_get_status
        // do zakończenia procesu albo przekroczenia limitu, zbierając stdout/
        // stderr po drodze (WAŻNE: trzeba czytać w trakcie, nie dopiero na
        // końcu — inaczej pełny bufor pipe'a może zawiesić proces Pythona,
        // klasyczny deadlock proc_open na większym wyjściu).
        $deadline = microtime(true) + (float) $timeout;
        $stdout = '';
        $stderr = '';
        do {
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);

        if ($status['running']) {
            proc_terminate($process);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            throw new \RuntimeException('Przekroczono czas oczekiwania (' . $label . ') — spróbuj ponownie za chwilę.');
        }

        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $decoded = json_decode(trim($stdout), true);
        if (!is_array($decoded) || !array_key_exists('ok', $decoded)) {
            error_log('PythonBridge (' . $label . '): nieoczekiwane wyjście: ' . substr($stdout . ' | stderr: ' . $stderr, 0, 2000));
            throw new \RuntimeException($label . ' zwrócił nieoczekiwaną odpowiedź.');
        }

        return $decoded;
    }
}
