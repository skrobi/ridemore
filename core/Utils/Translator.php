<?php
// core/Utils/Translator.php
namespace Utils;

/**
 * TŁUMACZ MASZYNOWY TREŚCI (2026-09-16, tasks/active/wielojezycznosc.md).
 *
 * TEN SAM WZORZEC CO Core\Mailer i Core\Push: driver w configu (`translate`),
 * a na dev i w testach `log` — nic nie wychodzi do zewnętrznego serwisu,
 * tłumaczenie to oryginał z dopiskiem `[EN] `, więc na ekranie od razu widać,
 * co przeszło przez tłumacza, a co zostało w oryginale.
 *
 * Drivery produkcyjne: `google` (Cloud Translation v2), `deepl` i `ai` (model
 * językowy przez ai-engine/translate.py — ten sam most i dostawca co importer
 * AI). `ai` odpowiada sekundami, więc NIE jest wołany w trakcie renderowania:
 * `queued()` mówi Models\ContentTranslation, żeby tekst trafił do kolejki,
 * a tłumaczy go `cron.php tlumaczenia`. Wszystkie trzy zwracają
 * wykryty język źródła — to jest drugie zadanie tej klasy obok tłumaczenia:
 * jeśli tekst JUŻ jest w języku docelowym, Models\ContentTranslation zapamiętuje
 * to jako `same` i nigdy więcej nie pyta.
 *
 * DWA BEZPIECZNIKI, bo to jest zewnętrzne API wołane w trakcie renderowania:
 *   - budżet znaków na dobę (`daily_chars`) — spam w opisie nie wyczyści konta,
 *   - przerwa po awarii (`pause_seconds`) — gdy serwis leży, strony nie czekają
 *     na timeout przy każdym żądaniu, tylko pokazują oryginał.
 * Oba są plikami w storage/, jak RateLimiter i blokady crona.
 */
final class Translator
{
    /**
     * @param string[] $texts
     * @return array<int,array{text:string,from:?string}>|null  NULL = nie udało się (pokaż oryginał)
     */
    /** Czy tłumaczenia idą przez kolejkę w tle zamiast od razu (driver `ai`). */
    public static function queued(): bool
    {
        return (string) (APP_CONFIG['translate']['driver'] ?? 'log') === 'ai';
    }

    public static function translate(array $texts, string $target, ?string $sourceHint = null): ?array
    {
        $texts = array_values($texts);
        if (!$texts) {
            return [];
        }
        $cfg = APP_CONFIG['translate'] ?? [];
        $driver = (string) ($cfg['driver'] ?? 'log');

        if ($driver !== 'log') {
            if (self::paused()) {
                return null;
            }
            $chars = array_sum(array_map('mb_strlen', $texts));
            if (!self::spendBudget($chars, (int) ($cfg['daily_chars'] ?? 200000))) {
                return null;
            }
        }

        try {
            $wynik = match ($driver) {
                'google' => self::google($texts, $target, $sourceHint, $cfg),
                'deepl'  => self::deepl($texts, $target, $sourceHint, $cfg),
                'ai'     => self::ai($texts, $target, $cfg),
                default  => self::logDriver($texts, $target, $sourceHint),
            };
        } catch (\Throwable $e) {
            error_log('[Translator] ' . $driver . ': ' . $e->getMessage());
            $wynik = null;
        }

        if ($wynik === null && $driver !== 'log') {
            self::pause((int) ($cfg['pause_seconds'] ?? 600));
        }
        return $wynik;
    }

    /** Dev/testy: bez sieci, z widocznym znacznikiem. */
    private static function logDriver(array $texts, string $target, ?string $hint): array
    {
        $out = [];
        foreach ($texts as $t) {
            $from = \Core\Lang::guess($t) ?? $hint ?? \Core\Lang::DOMYSLNY;
            $out[] = ['text' => $from === $target ? $t : '[' . strtoupper($target) . '] ' . $t, 'from' => $from];
        }
        $plik = CORE_PATH . '/../storage/translate.log';
        @file_put_contents($plik, date('c') . ' → ' . $target . ' (' . count($texts) . " tekstów)\n", FILE_APPEND);
        return $out;
    }

    private static function google(array $texts, string $target, ?string $hint, array $cfg): ?array
    {
        $key = (string) ($cfg['google_key'] ?? '');
        if ($key === '') {
            return null;
        }
        $body = ['q' => $texts, 'target' => $target, 'format' => 'text'];
        $odp = self::post(
            'https://translation.googleapis.com/language/translate/v2?key=' . rawurlencode($key),
            json_encode($body, JSON_UNESCAPED_UNICODE),
            ['Content-Type: application/json'],
            (int) ($cfg['timeout'] ?? 4)
        );
        $lista = $odp['data']['translations'] ?? null;
        if (!is_array($lista) || count($lista) !== count($texts)) {
            return null;
        }
        $out = [];
        foreach ($lista as $i => $t) {
            $from = strtolower(substr((string) ($t['detectedSourceLanguage'] ?? $hint ?? ''), 0, 2)) ?: null;
            $out[] = [
                'text' => html_entity_decode((string) ($t['translatedText'] ?? $texts[$i]), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'from' => $from,
            ];
        }
        return $out;
    }

    private static function deepl(array $texts, string $target, ?string $hint, array $cfg): ?array
    {
        $key = (string) ($cfg['deepl_key'] ?? '');
        if ($key === '') {
            return null;
        }
        // DeepL rozróżnia warianty angielskiego; serwis celuje w Europę.
        $cel = match ($target) { 'en' => 'EN-GB', 'pt' => 'PT-PT', default => strtoupper($target) };
        $pola = ['target_lang=' . rawurlencode($cel), 'preserve_formatting=1'];
        foreach ($texts as $t) {
            $pola[] = 'text=' . rawurlencode($t);
        }
        $odp = self::post(
            (string) ($cfg['deepl_url'] ?? 'https://api-free.deepl.com/v2/translate'),
            implode('&', $pola),
            ['Content-Type: application/x-www-form-urlencoded', 'Authorization: DeepL-Auth-Key ' . $key],
            (int) ($cfg['timeout'] ?? 4)
        );
        $lista = $odp['translations'] ?? null;
        if (!is_array($lista) || count($lista) !== count($texts)) {
            return null;
        }
        $out = [];
        foreach ($lista as $i => $t) {
            $out[] = [
                'text' => (string) ($t['text'] ?? $texts[$i]),
                'from' => strtolower(substr((string) ($t['detected_source_language'] ?? $hint ?? ''), 0, 2)) ?: null,
            ];
        }
        return $out;
    }

    /** Model językowy przez ai-engine/translate.py (Utils\PythonBridge). */
    private static function ai(array $texts, string $target, array $cfg): ?array
    {
        $most = $cfg['ai'] ?? null;
        if (!is_array($most)) {
            return null;
        }
        $odp = PythonBridge::run($most, ['target' => $target, 'texts' => $texts], 'tłumacz AI');
        if (($odp['ok'] ?? false) !== true) {
            error_log('[Translator] ai: ' . (string) ($odp['error'] ?? 'nieznany błąd'));
            return null;
        }
        $lista = $odp['data']['items'] ?? null;
        if (!is_array($lista) || count($lista) !== count($texts)) {
            return null;
        }
        $out = [];
        foreach ($lista as $i => $t) {
            $out[] = [
                'text' => is_string($t['text'] ?? null) ? $t['text'] : $texts[$i],
                'from' => is_string($t['from'] ?? null) ? $t['from'] : null,
            ];
        }
        return $out;
    }

    private static function post(string $url, string $body, array $headers, int $timeout): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(3, $timeout),
            CURLOPT_TIMEOUT        => $timeout,
        ]);
        $raw = curl_exec($ch);
        $kod = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($raw) || $kod < 200 || $kod >= 300) {
            error_log('[Translator] HTTP ' . $kod);
            return null;
        }
        $dane = json_decode($raw, true);
        return is_array($dane) ? $dane : null;
    }

    private static function paused(): bool
    {
        $plik = CORE_PATH . '/../storage/translate-pause';
        return is_file($plik) && (int) @file_get_contents($plik) > time();
    }

    private static function pause(int $seconds): void
    {
        @file_put_contents(CORE_PATH . '/../storage/translate-pause', (string) (time() + $seconds));
    }

    /** Dzienny budżet znaków; FALSE = limit wyczerpany (albo nie da się go zapisać). */
    private static function spendBudget(int $chars, int $limit): bool
    {
        $plik = CORE_PATH . '/../storage/translate-budget-' . date('Ymd');
        $fh = @fopen($plik, 'c+');
        if ($fh === false) {
            return false;
        }
        try {
            flock($fh, LOCK_EX);
            $zuzyte = (int) stream_get_contents($fh);
            if ($zuzyte + $chars > $limit) {
                return false;
            }
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, (string) ($zuzyte + $chars));
            return true;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }
}
