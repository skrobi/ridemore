<?php
// tests/lib.php
// MINIMALNY SILNIK TESTÓW — bez PHPUnit, bo ten projekt nie ma frameworka
// i nie ma powodu, żeby zaczynać od testów (patrz core/bootstrap.php).
// Cały silnik to jedna tablica, jedna pętla i garść asercji.
//
// DWIE RZECZY, KTÓRE ROBI ZA CIEBIE, I DLA KTÓRYCH W OGÓLE ISTNIEJE:
//
// 1. IZOLACJA TRANSAKCYJNA. Każdy test biegnie we własnej transakcji, która
//    NA KOŃCU ZAWSZE JEST WYCOFYWANA — także wtedy, gdy test przeszedł.
//    To jest cała różnica między tym plikiem a wcześniejszymi skryptami
//    test_*.php: tamte pisały do żywej bazy DEV i po każdym trzeba było
//    ręcznie sprzątać DELETE-ami, a jeden zapomniany kasownik zostawiał
//    śmieci, które psuły następne uruchomienie. Tutaj baza po przebiegu
//    wygląda identycznie jak przed nim, niezależnie od wyniku.
//
// 2. ŚWIEŻE DANE NA ŻĄDANIE. Testy nie zakładają, co jest w bazie — biorą
//    użytkowników i słowniki przez t_user()/t_category(), więc ten sam plik
//    działa na dowolnej kopii bazy DEV.
//
// OGRANICZENIE, O KTÓRYM TRZEBA WIEDZIEĆ: kod pod testem nie może sam
// otwierać transakcji ani wykonywać DDL — MySQL zatwierdziłby wtedy
// transakcję zewnętrzną i wycofanie przestałoby działać. Sprawdzone: żaden
// z testowanych modeli tego nie robi.
//
// UŻYCIE: php tests/run.php  albo  php tests/run.php skarby

if (!defined('CORE_PATH')) {
    require __DIR__ . '/../core/bootstrap.php';
}

/** @var array<int,array{name:string,fn:callable,file:string}> */
$GLOBALS['t_tests'] = [];
$GLOBALS['t_current_file'] = '';

function t_test(string $name, callable $fn): void
{
    $GLOBALS['t_tests'][] = ['name' => $name, 'fn' => $fn, 'file' => $GLOBALS['t_current_file']];
}

/** Wyjątek asercji — łapany przez runner, nie przez testowany kod. */
class TAssertionFailed extends \RuntimeException {}

function t_fail(string $message): void
{
    throw new TAssertionFailed($message);
}

function t_true($value, string $label): void
{
    if ($value !== true) {
        t_fail($label . ' — oczekiwano true, jest ' . t_dump($value));
    }
}

function t_false($value, string $label): void
{
    if ($value !== false) {
        t_fail($label . ' — oczekiwano false, jest ' . t_dump($value));
    }
}

function t_eq($expected, $actual, string $label): void
{
    // Porównanie luźne po rzutowaniu na string: z bazy liczby wracają jako
    // stringi (PDO bez emulacji), a test ma sprawdzać WARTOŚĆ, nie typ, jaki
    // akurat zwrócił sterownik. Tam, gdzie typ jest istotny, jest t_same().
    if ((string) $expected !== (string) $actual) {
        t_fail($label . ' — oczekiwano ' . t_dump($expected) . ', jest ' . t_dump($actual));
    }
}

function t_same($expected, $actual, string $label): void
{
    if ($expected !== $actual) {
        t_fail($label . ' — oczekiwano ' . t_dump($expected) . ', jest ' . t_dump($actual));
    }
}

function t_count(int $expected, $countable, string $label): void
{
    $actual = is_countable($countable) ? count($countable) : -1;
    if ($actual !== $expected) {
        t_fail($label . ' — oczekiwano ' . $expected . ' pozycji, jest ' . $actual);
    }
}

function t_null($value, string $label): void
{
    if ($value !== null) {
        t_fail($label . ' — oczekiwano null, jest ' . t_dump($value));
    }
}

function t_not_null($value, string $label): void
{
    if ($value === null) {
        t_fail($label . ' — oczekiwano wartości, jest null');
    }
}

function t_dump($value): string
{
    if (is_bool($value)) { return $value ? 'true' : 'false'; }
    if ($value === null) { return 'null'; }
    if (is_array($value)) { return 'array(' . count($value) . ')'; }
    return '"' . (string) $value . '"';
}

// --- Dane pomocnicze -------------------------------------------------
//
// Użytkowników NIE TWORZYMY — bierzemy istniejących. Tworzenie wymagałoby
// przechodzenia przez cały rejestr (hasło, słowniki, zgody), a testom
// wystarczy id, które przejdzie klucz obcy.

function t_users(int $howMany = 3): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = array_map('intval', Core\Database::connection()
            ->query('SELECT id FROM users ORDER BY id')
            ->fetchAll(\PDO::FETCH_COLUMN));
    }
    if (count($cache) < $howMany) {
        t_fail('Baza DEV ma mniej niż ' . $howMany . ' użytkowników — te testy potrzebują tylu do głosowania.');
    }
    return array_slice($cache, 0, $howMany);
}

function t_user(int $index = 0): int
{
    return t_users($index + 1)[$index];
}

/**
 * Symuluje zalogowanie/wylogowanie na potrzeby testów kontrolerów, BEZ
 * `Core\Auth::login()/logout()` — tamte wołają `session_regenerate_id()`
 * i `setcookie()`, co w CLI (ten runner) sypie ostrzeżeniami "headers already
 * sent" przy KAŻDYM wywołaniu, bo test wcześniej już coś wypisał na wyjście.
 * Auth cache'uje usera w prywatnych statykach (`$resolved`/`$cachedUser`) —
 * reflection zeruje je razem ze zmianą `$_SESSION`, żeby kolejne `Auth::user()`
 * przeczytało sesję na nowo, tak samo jak zrobiłby to prawdziwy `login()`.
 * Przeniesione z `przejazdy_solo_test.php` (2026-08-26) do wspólnego pliku,
 * żeby każdy kolejny test kontrolera nie musiał go kopiować.
 */
function t_auth_as(?int $userId): void
{
    if ($userId === null) {
        unset($_SESSION['user_id']);
    } else {
        $_SESSION['user_id'] = $userId;
    }
    $ref = new ReflectionClass(Core\Auth::class);
    foreach (['resolved' => false, 'cachedUser' => null] as $prop => $value) {
        $p = $ref->getProperty($prop);
        $p->setAccessible(true);
        $p->setValue(null, $value);
    }
}

function t_category(): int
{
    static $id = null;
    if ($id === null) {
        $id = (int) Core\Database::connection()->query('
            SELECT i.id FROM dictionary_items i
              JOIN dictionaries d ON d.id = i.dictionary_id
             WHERE d.code = "treasure_category" LIMIT 1
        ')->fetchColumn();
    }
    return $id;
}

/**
 * Skarb do testu. Domyślnie AKTYWNY i w konkretnym miejscu (50.0, 20.0) —
 * testy odległości opierają się na tym punkcie, bo łatwo policzyć w głowie,
 * ile metrów to 0,001 stopnia szerokości (~111 m).
 */
function t_treasure(array $overrides = []): array
{
    $id = Models\Treasure::save(null, array_merge([
        'name'             => 'TEST skarb',
        'category_item_id' => t_category(),
        'lat'              => 50.0,
        'lon'              => 20.0,
        'points'           => 100,
        'claim_radius_m'   => 150,
        'rarity'           => 'COMMON',
        'origin'           => 'OFFICIAL',
        'status'           => 'ACTIVE',
        'reveal_level'     => 2,
        'is_active'        => 1,
    ], $overrides), $overrides['created_by'] ?? null);

    return Models\Treasure::find($id);
}
