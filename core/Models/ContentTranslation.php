<?php
// core/Models/ContentTranslation.php
namespace Models;

use Core\Database;
use Core\Lang;
use Utils\Translator;

/**
 * TŁUMACZENIA TREŚCI Z BAZY (migr. 089, tasks/active/wielojezycznosc.md).
 *
 * ============================================================================
 * ORYGINAŁ ZOSTAJE TAM, GDZIE BYŁ
 * ============================================================================
 * `events.description`, `known_routes.description` itd. dalej trzymają tekst
 * w języku, w którym napisał go autor — także angielskim. Żadna tabela treści
 * nie dostała kolumn językowych, żaden zapis nie wie o tłumaczeniach.
 * Tłumaczenie powstaje przy PIERWSZYM WYŚWIETLENIU w danym języku i ląduje
 * w `content_translations` pod kluczem sha1(tekst) + język docelowy.
 *
 * ============================================================================
 * NAJCZĘSTSZY PRZYPADEK NIE KOSZTUJE NIC
 * ============================================================================
 * Polak ogląda polski event: Lang::guess() rozpoznaje język z samego tekstu,
 * więc nie ma ani zapytania do bazy, ani wywołania tłumacza. Baza i tłumacz
 * wchodzą do gry dopiero, gdy tekst wygląda na inny język niż strona albo
 * gdy zgadywanie nie przesądza (wtedy decyduje silnik i wynik zapisujemy jako
 * `same` — pyta się raz).
 *
 * ============================================================================
 * CZŁOWIEK WYGRYWA, EDYCJA ORYGINAŁU UNIEWAŻNIA
 * ============================================================================
 * Poprawka organizatora zapisuje `origin = human` pod tym samym kluczem —
 * nadpisuje wynik maszyny i nigdy nie jest przez maszynę nadpisywana.
 * Zmiana oryginału zmienia hash, więc stare tłumaczenie po prostu przestaje
 * pasować; `context` (np. `event:12:description`) pozwala formularzowi korekty
 * podpowiedzieć poprzednią ręczną wersję, a sprzątaniu w cronie — skasować
 * wiersze tekstów, których już nie ma.
 *
 * BEZPIECZEŃSTWO: tłumaczymy WYŁĄCZNIE to, co wywołujący już pokazałby widzowi
 * (np. po Treasure::reveal()). Nie ma publicznego endpointu „przetłumacz
 * dowolny tekst", a tabela nie jest nigdzie czytana w całości poza cronem.
 */
final class ContentTranslation
{
    /** Pola źródłowe objęte tłumaczeniem — lista dla sprzątania w cronie. */
    public const ZRODLA = [
        ['events', 'title'], ['events', 'description'],
        ['event_stages', 'title'], ['event_stages', 'notes'],
        ['event_price_items', 'description'],
        ['event_equipment', 'name'], ['event_equipment', 'note'],
        ['event_route_variants', 'name'],
        ['organizer_profiles', 'bio'],
        ['known_routes', 'name'], ['known_routes', 'description'],
        ['treasures', 'name'], ['treasures', 'description'], ['treasures', 'hint'],
        ['emblems', 'name'], ['emblems', 'description'],
    ];

    /** @var array<string, array{text:?string,origin:string,from:?string}> cache żądania: "hash|lang" */
    private static array $cache = [];

    private static bool $failed = false;

    /** @var array<string,array> meta ostatniego tłumaczenia per kontekst (dla etykiety w widoku) */
    private static array $meta = [];

    /** `?oryginal=1` — człowiek chce zobaczyć tekst tak, jak go napisano. */
    public static function showOriginal(): bool
    {
        return !empty($_GET['oryginal']);
    }

    /** Czy w tym żądaniu tłumaczenie było potrzebne, a się nie udało (→ noindex). */
    public static function failed(): bool
    {
        return self::$failed;
    }

    /**
     * Tłumaczy pola jednej rzeczy (tytuł + opis eventu, nazwa + opis trasy…).
     * Język źródła zgadywany z CAŁOŚCI, bo sam tytuł („Gravel 100") nie przesądza.
     *
     * @param array<string,?string> $fields
     * @return array<string,?string>
     */
    public static function fields(array $fields, string $context): array
    {
        $target = Lang::current();
        $teksty = array_filter($fields, static fn($v) => is_string($v) && trim($v) !== '');
        $meta = ['translated' => false, 'from' => null, 'origin' => null, 'failed' => false, 'original' => self::showOriginal()];

        // Jeden włączony język (produkcja z flagą ['pl']) = nic nie tłumaczymy,
        // także obcojęzycznej treści na polski — strona ma być taka jak przed zmianą.
        if (!$teksty || !self::wlaczone()) {
            self::$meta[$context] = $meta;
            return $fields;
        }

        $zgadniety = Lang::guess(implode("\n", $teksty));
        if ($zgadniety === $target) {
            self::$meta[$context] = $meta;
            return $fields;
        }

        $wyniki = self::lookup(array_values($teksty), $target, $zgadniety, $context, array_keys($teksty));
        $out = $fields;
        $ludzkie = 0;
        $maszynowe = 0;
        foreach ($teksty as $klucz => $tekst) {
            $w = $wyniki[self::klucz($tekst, $target)] ?? null;
            if ($w === null) {
                $meta['failed'] = true;
                continue;
            }
            if ($w['origin'] === 'same' || $w['text'] === null) {
                continue;
            }
            $meta['translated'] = true;
            $meta['from'] = $w['from'] ?? $meta['from'];
            $w['origin'] === 'human' ? $ludzkie++ : $maszynowe++;
            if (!self::showOriginal()) {
                $out[$klucz] = $w['text'];
            }
        }
        if ($meta['translated']) {
            $meta['origin'] = $maszynowe === 0 ? 'human' : 'machine';
        }
        if ($meta['failed'] && !self::showOriginal()) {
            self::$failed = true;
        }
        self::$meta[$context] = $meta;
        return $out;
    }

    /** Jeden tekst (np. tytuł na karcie). */
    public static function text(?string $text, string $context): ?string
    {
        return self::fields(['t' => $text], $context)['t'];
    }

    /**
     * Wstępne pobranie wielu tekstów naraz (lista kart): jedno zapytanie
     * i jedno wywołanie tłumacza zamiast po jednym na kartę.
     *
     * @param array<string,?string> $texts  kontekst => tekst
     */
    public static function prefetch(array $texts): void
    {
        if (!self::wlaczone()) {
            return;
        }
        $target = Lang::current();
        $doPobrania = [];
        $konteksty = [];
        foreach ($texts as $kontekst => $t) {
            if (!is_string($t) || trim($t) === '' || Lang::guess($t) === $target || isset(self::$cache[self::klucz($t, $target)])) {
                continue;
            }
            $doPobrania[] = $t;
            $konteksty[] = (string) $kontekst;
        }
        if ($doPobrania) {
            self::lookup($doPobrania, $target, null, '', $konteksty);
        }
    }

    /**
     * prefetch() dla wierszy z bazy (lista wydarzeń): kontekst `event:{id}:title`
     * jak przy EventCardResource, więc wpis w tabeli wygląda tak samo, niezależnie
     * od tego, która strona zobaczyła tytuł pierwsza.
     */
    public static function prefetchRows(array $rows, string $field = 'title', string $prefix = 'event', string $idField = 'event_id'): void
    {
        $teksty = [];
        foreach ($rows as $r) {
            $id = (int) ($r[$idField] ?? $r['id'] ?? 0);
            $teksty[$prefix . ':' . $id . ':t'] = $r[$field] ?? null;
        }
        self::prefetch($teksty);
    }

    /** @return array{translated:bool,from:?string,origin:?string,failed:bool,original:bool}|null */
    public static function meta(string $context): ?array
    {
        return self::$meta[$context] ?? null;
    }

    /**
     * @param string[] $texts
     * @param string[] $fieldKeys  klucze pól (do kontekstu) albo pełne konteksty
     * @return array<string,array{text:?string,origin:string,from:?string}|null>
     */
    /** Tłumaczenie treści działa tylko przy więcej niż jednym włączonym języku. */
    private static function wlaczone(): bool
    {
        return count(Lang::supported()) > 1;
    }

    private static function lookup(array $texts, string $target, ?string $hint, string $context, array $fieldKeys): array
    {
        $wynik = [];
        $brak = [];
        foreach ($texts as $i => $t) {
            $k = self::klucz($t, $target);
            if (isset(self::$cache[$k])) {
                $wynik[$k] = self::$cache[$k];
            } else {
                $brak[$k] = $i;
            }
        }
        if (!$brak) {
            return $wynik;
        }

        $hashe = array_map(static fn($k) => substr($k, 0, 40), array_keys($brak));
        $ph = implode(',', array_fill(0, count($hashe), '?'));
        $stmt = Database::connection()->prepare(
            "SELECT source_hash, translated_text, origin, source_lang FROM content_translations
              WHERE target_lang = ? AND source_hash IN ($ph)"
        );
        $stmt->execute(array_merge([$target], $hashe));
        foreach ($stmt->fetchAll() as $row) {
            $k = $row['source_hash'] . '|' . $target;
            if ($row['origin'] === 'pending') {
                // W kolejce — do czasu przebiegu crona pokazujemy oryginał.
                self::$cache[$k] = $wynik[$k] = null;
                unset($brak[$k]);
                continue;
            }
            self::$cache[$k] = $wynik[$k] = [
                'text'   => $row['translated_text'],
                'origin' => $row['origin'],
                'from'   => $row['source_lang'],
            ];
            unset($brak[$k]);
        }
        if (!$brak) {
            return $wynik;
        }

        if (Translator::queued()) {
            // Tłumacz wolny (model językowy) — do kolejki, strona nie czeka.
            $ins = Database::connection()->prepare(
                "INSERT INTO content_translations (source_hash, target_lang, source_lang, source_text, origin, context)
                 VALUES (:h, :t, :s, :x, 'pending', :c)
                 ON DUPLICATE KEY UPDATE source_hash = source_hash"
            );
            foreach ($brak as $k => $i) {
                $kontekstPola = $context !== '' ? $context . ':' . ($fieldKeys[$i] ?? '') : (string) ($fieldKeys[$i] ?? '');
                $ins->execute([
                    'h' => substr($k, 0, 40),
                    't' => $target,
                    's' => $hint ?? Lang::guess($texts[$i]),
                    'x' => $texts[$i],
                    'c' => mb_substr($kontekstPola, 0, 64) ?: null,
                ]);
                self::$cache[$k] = $wynik[$k] = null;
            }
            return $wynik;
        }

        $doTlumacza = [];
        foreach ($brak as $k => $i) {
            $doTlumacza[] = $texts[$i];
        }
        $tlumaczenia = Translator::translate($doTlumacza, $target, $hint);
        if ($tlumaczenia === null) {
            foreach ($brak as $k => $i) {
                $wynik[$k] = null;
            }
            return $wynik;
        }

        $ins = Database::connection()->prepare(
            'INSERT INTO content_translations (source_hash, target_lang, source_lang, translated_text, origin, engine, context)
             VALUES (:h, :t, :s, :x, :o, :e, :c)
             ON DUPLICATE KEY UPDATE source_hash = source_hash'
        );
        $engine = (string) (APP_CONFIG['translate']['driver'] ?? 'log');
        $n = 0;
        foreach ($brak as $k => $i) {
            $tr = $tlumaczenia[$n++] ?? null;
            if ($tr === null) {
                $wynik[$k] = null;
                continue;
            }
            $same = $tr['from'] === $target || trim($tr['text']) === trim($texts[$i]);
            $rekord = [
                'text'   => $same ? null : $tr['text'],
                'origin' => $same ? 'same' : 'machine',
                'from'   => $tr['from'],
            ];
            $kontekstPola = $context !== '' ? $context . ':' . ($fieldKeys[$i] ?? '') : (string) ($fieldKeys[$i] ?? '');
            $ins->execute([
                'h' => substr($k, 0, 40),
                't' => $target,
                's' => $tr['from'],
                'x' => $rekord['text'],
                'o' => $rekord['origin'],
                'e' => $engine,
                'c' => mb_substr($kontekstPola, 0, 64) ?: null,
            ]);
            self::$cache[$k] = $wynik[$k] = $rekord;
        }
        return $wynik;
    }

    private static function klucz(string $text, string $target): string
    {
        return sha1($text) . '|' . $target;
    }

    // ------------------------------------------------------------------
    // Korekta ręczna
    // ------------------------------------------------------------------

    /** Aktualne tłumaczenie tekstu (dla formularza korekty) — bez wywoływania tłumacza. */
    public static function current(string $source, string $target): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT translated_text, origin, source_lang, updated_at FROM content_translations WHERE source_hash = ? AND target_lang = ?'
        );
        $stmt->execute([sha1($source), $target]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Poprzednia RĘCZNA wersja tego samego pola (sprzed edycji oryginału). */
    public static function previousHuman(string $context, string $currentSource, string $target): ?string
    {
        $stmt = Database::connection()->prepare(
            "SELECT translated_text FROM content_translations
              WHERE context = ? AND target_lang = ? AND origin = 'human' AND source_hash <> ?
              ORDER BY updated_at DESC LIMIT 1"
        );
        $stmt->execute([mb_substr($context, 0, 64), $target, sha1($currentSource)]);
        $v = $stmt->fetchColumn();
        return is_string($v) ? $v : null;
    }

    /**
     * Zapis poprawki człowieka. Pusty tekst = „wróć do automatu" (kasuje
     * ręczną wersję, następne wyświetlenie przetłumaczy od nowa).
     */
    public static function saveHuman(string $context, string $source, string $target, string $translation, int $userId): void
    {
        $pdo = Database::connection();
        if (trim($translation) === '') {
            $pdo->prepare('DELETE FROM content_translations WHERE source_hash = ? AND target_lang = ?')
                ->execute([sha1($source), $target]);
            return;
        }
        $pdo->prepare(
            "INSERT INTO content_translations (source_hash, target_lang, source_lang, translated_text, origin, engine, context, updated_by)
             VALUES (:h, :t, :s, :x, 'human', NULL, :c, :u)
             ON DUPLICATE KEY UPDATE translated_text = VALUES(translated_text), origin = 'human', source_text = NULL,
                                     context = VALUES(context), updated_by = VALUES(updated_by)"
        )->execute([
            'h' => sha1($source),
            't' => $target,
            's' => Lang::guess($source),
            'x' => $translation,
            'c' => mb_substr($context, 0, 64),
            'u' => $userId,
        ]);
        unset(self::$cache[self::klucz($source, $target)]);
    }

    // ------------------------------------------------------------------
    // Kolejka (driver `ai`, cron `tlumaczenia`)
    // ------------------------------------------------------------------

    /** Paczka do jednego wywołania modelu: ile tekstów i ile znaków naraz. */
    private const PACZKA_TEKSTOW = 25;
    private const PACZKA_ZNAKOW = 12000;

    /**
     * Tłumaczy teksty czekające w kolejce (`origin = pending`), najstarsze
     * pierwsze. Kończy przy pierwszej nieudanej paczce — Translator włącza
     * wtedy przerwę, a reszta poczeka na następny przebieg.
     *
     * @return array{translated:int, failed:bool}
     */
    public static function processQueue(int $limit = 200): array
    {
        $pdo = Database::connection();
        $rows = $pdo->query(
            "SELECT id, target_lang, source_text FROM content_translations
              WHERE origin = 'pending' AND source_text IS NOT NULL
              ORDER BY id LIMIT " . max(1, $limit)
        )->fetchAll();

        $paczki = [];
        foreach ($rows as $r) {
            $lang = (string) $r['target_lang'];
            $paczki[$lang] ??= [];
            $ostatnia = count($paczki[$lang]) - 1;
            if ($ostatnia < 0
                || count($paczki[$lang][$ostatnia]) >= self::PACZKA_TEKSTOW
                || array_sum(array_map(static fn($x) => mb_strlen($x['source_text']), $paczki[$lang][$ostatnia])) + mb_strlen($r['source_text']) > self::PACZKA_ZNAKOW
            ) {
                $paczki[$lang][] = [];
                $ostatnia++;
            }
            $paczki[$lang][$ostatnia][] = $r;
        }

        $upd = $pdo->prepare(
            "UPDATE content_translations
                SET translated_text = :x, origin = :o, source_lang = COALESCE(:s, source_lang), engine = :e, source_text = NULL
              WHERE id = :id AND origin = 'pending'"
        );
        $engine = (string) (APP_CONFIG['translate']['driver'] ?? 'log');
        $n = 0;
        foreach ($paczki as $lang => $listy) {
            foreach ($listy as $paczka) {
                $teksty = array_column($paczka, 'source_text');
                $wynik = Translator::translate($teksty, $lang);
                if ($wynik === null) {
                    return ['translated' => $n, 'failed' => true];
                }
                foreach ($paczka as $i => $r) {
                    $tr = $wynik[$i];
                    $same = $tr['from'] === $lang || trim($tr['text']) === trim($r['source_text']);
                    $upd->execute([
                        'x'  => $same ? null : $tr['text'],
                        'o'  => $same ? 'same' : 'machine',
                        's'  => $tr['from'],
                        'e'  => $engine,
                        'id' => $r['id'],
                    ]);
                    $n++;
                }
            }
        }
        return ['translated' => $n, 'failed' => false];
    }

    /** Ile tekstów czeka w kolejce (panel/cron). */
    public static function pendingCount(): int
    {
        return (int) Database::connection()->query("SELECT COUNT(*) FROM content_translations WHERE origin = 'pending'")->fetchColumn();
    }

    // ------------------------------------------------------------------
    // Sprzątanie (cron `noc`)
    // ------------------------------------------------------------------

    /**
     * Kasuje tłumaczenia tekstów, których nie ma już w żadnej kolumnie źródłowej
     * (edytowany opis, skasowane konto, wycofany skarb). Ręczne poprawki żyją
     * jeszcze 30 dni — formularz korekty podpowiada je po edycji oryginału.
     *
     * @return int liczba skasowanych wierszy
     */
    public static function sweep(): int
    {
        $pdo = Database::connection();
        $aktualne = [];
        foreach (self::ZRODLA as [$tabela, $kolumna]) {
            try {
                foreach ($pdo->query("SELECT SHA1($kolumna) FROM $tabela WHERE $kolumna IS NOT NULL AND $kolumna <> ''")->fetchAll(\PDO::FETCH_COLUMN) as $h) {
                    $aktualne[$h] = true;
                }
            } catch (\PDOException $e) {
                // Tabela/kolumna, której na danym środowisku jeszcze nie ma — pomijamy.
            }
        }
        $del = $pdo->prepare('DELETE FROM content_translations WHERE id = ?');
        $n = 0;
        $rows = $pdo->query(
            "SELECT id, source_hash, origin, updated_at < (NOW() - INTERVAL 30 DAY) AS stare FROM content_translations"
        )->fetchAll();
        foreach ($rows as $r) {
            if (isset($aktualne[$r['source_hash']])) {
                continue;
            }
            if ($r['origin'] === 'human' && !(int) $r['stare']) {
                continue;
            }
            $del->execute([$r['id']]);
            $n++;
        }
        return $n;
    }
}
