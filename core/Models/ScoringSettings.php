<?php
// core/Models/ScoringSettings.php
// NADPISANIA PUNKTACJI Z BAZY (migr. 053) — to, co admin zmienił w panelu.
//
// Nie jest to „konfiguracja przeniesiona do bazy", tylko WARSTWA NA NIEJ:
// core/discovery.php zostaje źródłem wartości domyślnych i uzasadnień (tam stoi
// sprawdzian proporcji między Discovery a Trails, którego nie da się zapisać
// w kolumnie), a tabela trzyma wyłącznie klucze realnie zmienione.
//
// Wynika z tego zachowanie, na którym zależy najbardziej: pusta tabela znaczy
// „jak przed migracją", a skasowanie wiersza to powrót do domyślnej wartości
// BEZ potrzeby jej znajomości.
//
// KLUCZ JEST ŚCIEŻKĄ: 'discovery.points_per_new_cell', 'trails.threshold_count'.
// Płasko, bo parametrów jest kilkanaście i będą przybywać — kolumna na każdy
// znaczyłaby migrację przy każdym nowym.
namespace Models;

use Core\Database;

class ScoringSettings
{
    /** @var array<string,float>|null */
    private static ?array $cache = null;

    /**
     * WYŁĄCZNIE te klucze wolno nadpisać z panelu.
     *
     * Biała lista, nie czarna: konfiguracja zawiera też rzeczy, które NIE są
     * stawkami i których zmiana przez formularz byłaby awarią, a nie
     * kalibracją — promień przycięcia śladu przy domu (prywatność, §27) czy
     * limity ochronne zapytań. Lista jest tu, a nie w kontrolerze, bo to
     * reguła domenowa: „co wolno stroić", a nie „co pokazuje formularz".
     *
     * Etykiety idą do panelu — admin ma widzieć, co ustawia, a nie ścieżkę
     * w tablicy.
     */
    public const EDITABLE = [
        'discovery.points_per_new_cell' => [
            'label' => 'Za każde nowe pole', 'unit' => 'pkt',
            'hint'  => 'Podstawa całej gry — mnoży się przez liczbę pól odkrytych przejazdem.'],
        'exploration.first_in_community_bonus' => [
            'label' => 'Nowy teren', 'unit' => 'pkt',
            'hint'  => 'Dodatek za pole, którego nikt wcześniej nie miał. Celowo niski — uzasadnienie w core/discovery.php.'],
        'exploration.rare_cell_bonus' => [
            'label' => 'Pole rzadkie', 'unit' => 'pkt',
            'hint'  => 'Dodatek za pole, przez które przejechało niewiele osób.'],
        'exploration.rare_cell_max_riders' => [
            'label' => 'Próg „pola rzadkiego"', 'unit' => 'osób',
            'hint'  => 'Do ilu odkrywców pole liczy się jeszcze jako rzadkie.'],
        'ride.points_per_km' => [
            'label' => 'Za kilometr', 'unit' => 'pkt/km',
            'hint'  => 'Jedyna kategoria, którą można zdobyć powtórnie za tę samą drogę.'],
        'ride.points_per_100m_elevation' => [
            'label' => 'Za 100 m przewyższenia', 'unit' => 'pkt',
            'hint'  => 'Premia za teren, nie za dystans.'],
        'trails.points_per_km' => [
            'label' => 'Znana trasa — stawka za km', 'unit' => 'pkt/km',
            'hint'  => 'Baza sugestii dla KAŻDEJ trasy: długość × ta stawka = ile jest warta łącznie. Dłuższa trasa dostaje więcej automatycznie, bez ustawiania jej osobno w sekcji „Znana trasa — punktacja" niżej.'],
        'trails.threshold_count' => [
            'label' => 'Znana trasa — liczba progów', 'unit' => 'progów',
            'hint'  => 'Na ile etapów dzieli się trasa (rozstawionych równo, np. 4 = 25/50/75/100%). Każdy etap, łącznie z ukończeniem, jest wart tyle samo.'],
        'trails.default_total_points' => [
            'label' => 'Znana trasa — wartość domyślna', 'unit' => 'pkt',
            'hint'  => 'Ile jest warta trasa ŁĄCZNIE, gdy nie da się policzyć sugestii z długości (i przykład na ekranie „Ile to daje").'],
        'treasures.default_points' => [
            'label' => 'Skarb — punkty domyślne', 'unit' => 'pkt',
            'hint'  => 'Wartość wyjściowa dla nowego punktu w terenie. Każdy punkt ma własną — ta tylko podpowiada, od czego zacząć.'],
        // RZADKOSC USTAWIA PUNKTY WYJSCIOWE, A NIE MNOZNIK — i to jest tu
        // jedyna nieoczywista decyzja. Mnoznik nakladalby sie na wpisana recznie
        // wartosc skarbu: admin ustawiajacy 250 pkt przy rzadkosci EPIC dostalby
        // po cichu 500 i nie mialby jak tego zobaczyc w formularzu. Tak kazdy
        // skarb ma DOKLADNIE tyle punktow, ile widac w jego polu, a rzadkosc
        // podpowiada tylko, od czego zaczac.
        'treasures.points.COMMON' => [
            'label' => 'Skarb pospolity — punkty', 'unit' => 'pkt',
            'hint'  => 'Wartość podpowiadana przy zakładaniu skarbu tej rzadkości.'],
        'treasures.points.RARE' => [
            'label' => 'Skarb rzadki — punkty', 'unit' => 'pkt', 'hint' => ''],
        'treasures.points.EPIC' => [
            'label' => 'Skarb epicki — punkty', 'unit' => 'pkt', 'hint' => ''],
        'treasures.points.LEGENDARY' => [
            'label' => 'Skarb legendarny — punkty', 'unit' => 'pkt',
            'hint'  => 'Zarezerwuj dla miejsc, do których naprawdę trzeba podjechać — inaczej słowo „legendarny" przestanie coś znaczyć.'],
        'treasures.confirmations_needed' => [
            'label' => 'Skarb społecznościowy — potwierdzenia', 'unit' => 'osób',
            'hint'  => 'Ile osób musi potwierdzić zgłoszony punkt, żeby stał się aktywny i zaczął płacić. Niżej = szybciej rośnie mapa, wyżej = mniej śmieci.'],
        'treasures.default_radius_m' => [
            'label' => 'Skarb — promień zaliczenia', 'unit' => 'm',
            'hint'  => 'Jak blisko trzeba być, żeby punkt się zaliczył. Mniej wyklucza słaby GPS pod lasem, więcej pozwala zaliczać z szosy.'],
    ];

    /** @return array<string,float> klucz => wartość, wyłącznie nadpisania */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        self::$cache = [];
        try {
            $rows = Database::connection()
                ->query('SELECT setting_key, setting_value FROM scoring_settings')
                ->fetchAll();
            foreach ($rows as $r) {
                self::$cache[$r['setting_key']] = (float) $r['setting_value'];
            }
        } catch (\Throwable $e) {
            // Brak tabeli (środowisko przed migracją) nie może wywrócić
            // naliczania punktów — wracamy do wartości z pliku.
            self::$cache = [];
        }
        return self::$cache;
    }

    /**
     * Wkłada nadpisania w gotową tablicę konfiguracji.
     *
     * Ścieżka klucza jest rozbijana po kropkach i wpisywana W ISTNIEJĄCE
     * miejsce. Klucza, którego w pliku nie ma, NIE tworzymy — wiersz
     * w tabeli po usuniętym parametrze byłby wtedy cichym śmieciem, który
     * dokłada do konfiguracji pole nieznane reszcie kodu.
     */
    public static function applyTo(array $config): array
    {
        foreach (self::all() as $key => $value) {
            if (!isset(self::EDITABLE[$key])) {
                continue;
            }
            $parts = explode('.', $key);
            $ref = &$config;
            $ok = true;
            foreach ($parts as $part) {
                if (!is_array($ref) || !array_key_exists($part, $ref)) {
                    $ok = false;
                    break;
                }
                $ref = &$ref[$part];
            }
            if ($ok) {
                // Całkowite stawki zostają całkowite: różnica jest widoczna
                // w panelu (5 zamiast 5.0000) i w opisach transakcji.
                $ref = ($value == (int) $value) ? (int) $value : $value;
            }
            unset($ref);
        }
        return $config;
    }

    /** Wartość domyślna (z pliku) dla klucza — pod panel, żeby pokazać „było". */
    public static function defaultFor(string $key, array $defaults): int|float|null
    {
        $ref = $defaults;
        foreach (explode('.', $key) as $part) {
            if (!is_array($ref) || !array_key_exists($part, $ref)) {
                return null;
            }
            $ref = $ref[$part];
        }
        return is_numeric($ref) ? $ref + 0 : null;
    }

    /**
     * Zapis jednej stawki. `null` KASUJE nadpisanie, czyli przywraca domyślną —
     * i to jest jedyny sposób powrotu, bo panel nie musi znać liczby z pliku.
     */
    public static function set(string $key, ?float $value, ?int $userId): void
    {
        if (!isset(self::EDITABLE[$key])) {
            return;
        }
        $db = Database::connection();
        if ($value === null) {
            $db->prepare('DELETE FROM scoring_settings WHERE setting_key = :k')->execute(['k' => $key]);
        } else {
            $db->prepare(
                'INSERT INTO scoring_settings (setting_key, setting_value, updated_by)
                 VALUES (:k, :v, :u)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)'
            )->execute(['k' => $key, 'v' => $value, 'u' => $userId]);
        }
        self::$cache = null;
        DiscoveryScoring::forgetConfig();
    }

    /** Kiedy i przez kogo ostatnio ruszana — pod nagłówek panelu. */
    public static function lastChange(): ?array
    {
        try {
            $row = Database::connection()->query(
                'SELECT s.setting_key, s.updated_at, u.name AS who
                   FROM scoring_settings s
                   LEFT JOIN users u ON u.id = s.updated_by
                  ORDER BY s.updated_at DESC LIMIT 1'
            )->fetch();
            return $row ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
