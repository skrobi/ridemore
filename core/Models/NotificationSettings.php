<?php

namespace Models;

use Core\Database;

/**
 * USTAWIENIA POWIADOMIEŃ Z BAZY (migr. 083, 2026-09-11) — to, co admin zmienił
 * w panelu `/admin/powiadomienia`.
 *
 * ============================================================================
 * WZORZEC JEST PRZEPISANY Z `Models\ScoringSettings` (migr. 053) I TO JEST
 * ŚWIADOME
 * ============================================================================
 * Tamta klasa rozwiązała ten sam problem dla punktacji i rozwiązała go dobrze:
 * plik zostaje źródłem wartości domyślnych, tabela trzyma wyłącznie klucze
 * realnie zmienione, a skasowanie wiersza przywraca domyślną BEZ potrzeby jej
 * znajomości. Nie dziedziczę po niej i nie scalam tabel, bo to dwie różne
 * domeny — punktacja jest regułą gry, powiadomienia regułą komunikacji —
 * a nazwa tabeli `scoring_settings` jest częścią jej dokumentacji. Wspólny
 * jest mechanizm, nie treść.
 *
 * ============================================================================
 * DWIE ZASADY, KTÓRYCH TA KLASA PILNUJE
 * ============================================================================
 * 1. DOMYŚLNE MIESZKAJĄ W KODZIE, nie w tabeli. `PARAMETRY` niżej są jedyną
 *    listą, jaką ta klasa uznaje: klucz spoza niej nie zapisze się i nie
 *    odczyta. Biała lista, nie czarna — inaczej pierwsza literówka w panelu
 *    zamieniłaby się w cichy wiersz, którego nikt już nigdy nie odczyta.
 * 2. ZAKRESY SĄ CZĘŚCIĄ DEFINICJI, nie walidacją w formularzu. Godzina poza
 *    0–23 albo ujemny budżet to nie jest „dziwne ustawienie", tylko zepsuty
 *    system powiadomień; `set()` przycina do zakresu, więc ekran nie jest
 *    ostatnią linią obrony.
 *
 * ============================================================================
 * CZEGO TU CELOWO NIE MA
 * ============================================================================
 * TREŚCI POWIADOMIEŃ. Kusi, żeby dorzucić szablony do panelu, ale treść
 * powiadomienia o skarbie zależy od poziomu ujawnienia (`Treasure::reveal()`)
 * — kod pilnuje, żeby nazwa nieodkrytego skarbu nie wyciekła do powiadomienia
 * ani do maila. Szablon edytowalny z bazy pozwoliłby tę regułę obejść
 * wpisaniem znacznika z nazwą tam, gdzie jej być nie może. Da się to zrobić
 * bezpiecznie (znaczniki z białej listy, osobne dla każdego poziomu), ale to
 * osobna praca, a nie parametr obok budżetu.
 */
final class NotificationSettings
{
    /** @var array<string,float>|null */
    private static ?array $cache = null;

    /**
     * WYŁĄCZNIE te klucze wolno ustawić z panelu.
     *
     * `default` to wartość obowiązująca, dopóki nikt nic nie zmienił — i musi
     * zgadzać się z tym, co system robił przed migracją 083. `min`/`max`
     * są twarde. `group` steruje wyłącznie układem ekranu.
     */
    public const PARAMETRY = [
        // ---- JAK CZĘSTO (budżet zachęt; transakcyjne go nie dotyczą) -----
        'budzet.push.tydzien' => [
            'group' => 'budzet', 'label' => 'Zachęty pushem — na tydzień', 'unit' => 'szt.',
            'default' => 2, 'min' => 0, 'max' => 50,
            'hint' => 'Przy więcej niż sześciu tygodniowo ludzie są 3,4× bardziej skłonni odinstalować apkę w ciągu 30 dni niż przy jednym–dwóch. Zero znaczy „żadnych zachęt pushem".'],
        'budzet.push.doba' => [
            'group' => 'budzet', 'label' => 'Zachęty pushem — na dobę', 'unit' => 'szt.',
            'default' => 1, 'min' => 0, 'max' => 20,
            'hint' => 'Sufit dzienny, niezależny od tygodniowego. Chroni przed wysłaniem całego tygodniowego budżetu jednego popołudnia.'],
        'budzet.mail.tydzien' => [
            'group' => 'budzet', 'label' => 'Zachęty mailem — na tydzień', 'unit' => 'szt.',
            'default' => 2, 'min' => 0, 'max' => 50,
            'hint' => 'Liczony OSOBNO od pusha — mail czeka w skrzynce, push przerywa dzień.'],
        'budzet.mail.doba' => [
            'group' => 'budzet', 'label' => 'Zachęty mailem — na dobę', 'unit' => 'szt.',
            'default' => 1, 'min' => 0, 'max' => 20,
            'hint' => 'Jak wyżej, dla skrzynki.'],

        // ---- W JAKIM CZASIE ----------------------------------------------
        'cisza.od' => [
            'group' => 'czas', 'label' => 'Cisza nocna — od godziny', 'unit' => 'godz.',
            'default' => 22, 'min' => 0, 'max' => 23,
            'hint' => 'Dotyczy WYŁĄCZNIE pusha; mail nikogo nie budzi i wychodzi zawsze. Nie obejmuje rzeczy transakcyjnych (wiadomość, zapis na wyjazd) — na nie ktoś czeka.'],
        'cisza.do' => [
            'group' => 'czas', 'label' => 'Cisza nocna — do godziny', 'unit' => 'godz.',
            'default' => 7, 'min' => 0, 'max' => 23,
            'hint' => 'Godzina, o której push znów wolno wysłać. Domyślnie 7, czyli cisza trwa 22:00–6:59.'],
        'okno.od' => [
            'group' => 'czas', 'label' => 'Okno wysyłki zachęt — od godziny', 'unit' => 'godz.',
            'default' => 0, 'min' => 0, 'max' => 23,
            'hint' => 'Kiedy zadanie w tle W OGÓLE zabiera się za zachęty. Domyślnie 0–24, czyli „zawsze, gdy cron się odpali" — tak samo jak przed tym panelem.'],
        'okno.do' => [
            'group' => 'czas', 'label' => 'Okno wysyłki zachęt — do godziny', 'unit' => 'godz.',
            'default' => 24, 'min' => 1, 'max' => 24,
            'hint' => 'Ustaw np. 17–20, żeby zachęty wychodziły po pracy. Warunek: crontab musi odpalać cron.php częściej niż raz dziennie, inaczej okno po prostu ominie porę uruchomienia.'],
        'okno.swiezosc_dni' => [
            'group' => 'czas', 'label' => 'Za nowe uznajemy z ostatnich', 'unit' => 'dni',
            'default' => 1, 'min' => 1, 'max' => 30,
            'hint' => 'Ile dni wstecz zadanie szuka nowych skarbów. Podniesienie ma sens, gdy cron chodzi rzadziej niż codziennie — inaczej nowość zdąży się „zestarzeć" między przebiegami.'],

        // ---- CO WYSYŁAMY (wyłączniki awaryjne) ---------------------------
        'kanal.push' => [
            'group' => 'kanal', 'label' => 'Kanał: powiadomienia w aplikacji', 'unit' => '',
            'default' => 1, 'min' => 0, 'max' => 1,
            'hint' => 'Główny wyłącznik pusha dla CAŁEGO serwisu. Zgody użytkowników zostają nietknięte — po ponownym włączeniu wszystko wraca.'],
        'kanal.mail' => [
            'group' => 'kanal', 'label' => 'Kanał: powiadomienia mailem', 'unit' => '',
            'default' => 1, 'min' => 0, 'max' => 1,
            'hint' => 'Jak wyżej, dla poczty. Nie dotyczy maili spoza bramki (reset hasła, potwierdzenia płatności) — te idą zawsze.'],
        'typ.message' => [
            'group' => 'typ', 'label' => 'Nowa wiadomość', 'unit' => '', 'transakcyjny' => true,
            'default' => 1, 'min' => 0, 'max' => 1,
            'hint' => 'TRANSAKCYJNE — ktoś na to czeka. Wyłączaj tylko awaryjnie.'],
        'typ.event_join' => [
            'group' => 'typ', 'label' => 'Ktoś dołączył do wyjazdu', 'unit' => '', 'transakcyjny' => true,
            'default' => 1, 'min' => 0, 'max' => 1,
            'hint' => 'TRANSAKCYJNE — organizator dowiaduje się o zapisie. Wyłączaj tylko awaryjnie.'],
        'typ.treasure_nearby' => [
            'group' => 'typ', 'label' => 'Nowy skarb w okolicy', 'unit' => '',
            'default' => 1, 'min' => 0, 'max' => 1,
            'hint' => 'Zachęta. Wysyłana z zadania w tle do osób z odkrytymi polami w regionie skarbu.'],
        'typ.nearby_new' => [
            'group' => 'typ', 'label' => 'Nowa trasa lub wyjazd w okolicy', 'unit' => '',
            'default' => 1, 'min' => 0, 'max' => 1,
            'hint' => 'Zachęta. Typ jest gotowy w bramce, ale NIE MA JESZCZE NADAWCY — przełącznik zadziała, gdy powstanie (Etap 1b).'],
        'typ.progress' => [
            'group' => 'typ', 'label' => 'Postęp na trasie', 'unit' => '',
            'default' => 1, 'min' => 0, 'max' => 1,
            'hint' => 'Zachęta. Też czeka na nadawcę (Etap 1a).'],
        'typ.match' => [
            'group' => 'typ', 'label' => 'Dopasowany wyjazd', 'unit' => '',
            'default' => 1, 'min' => 0, 'max' => 1,
            'hint' => 'Mail o wyjeździe pasującym do preferencji. Idzie tylko do osób, które zaznaczyły zgodę w koncie (domyślnie wyłączoną).'],
        'typ.device_ride' => [
            'group' => 'typ', 'label' => 'Przejazd dodany z licznika', 'unit' => '', 'transakcyjny' => true,
            'default' => 1, 'min' => 0, 'max' => 1,
            'hint' => 'TRANSAKCYJNE — Polar/Wahoo zgłosił nowy trening i dodaliśmy go automatem, który człowiek sam włączył przy połączonym koncie.'],
    ];

    /** Wartość obowiązująca: nadpisanie z bazy albo domyślna z `PARAMETRY`. */
    public static function get(string $key): float
    {
        if (!isset(self::PARAMETRY[$key])) {
            throw new \InvalidArgumentException("Nieznany parametr powiadomień: $key");
        }
        return self::all()[$key] ?? (float) self::PARAMETRY[$key]['default'];
    }

    public static function getInt(string $key): int
    {
        return (int) round(self::get($key));
    }

    /** Przełączniki 0/1 — czytelniej niż `getInt(...) === 1` w pięciu miejscach. */
    public static function wlaczone(string $key): bool
    {
        return self::getInt($key) === 1;
    }

    /** @return array<string,float> wyłącznie NADPISANIA (klucze realnie zmienione) */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        self::$cache = [];
        try {
            $rows = Database::connection()
                ->query('SELECT setting_key, setting_value FROM notification_settings')
                ->fetchAll();
            foreach ($rows as $r) {
                if (isset(self::PARAMETRY[$r['setting_key']])) {
                    self::$cache[$r['setting_key']] = (float) $r['setting_value'];
                }
            }
        } catch (\Throwable $e) {
            // Brak tabeli (środowisko przed migracją 083) NIE MOŻE zatrzymać
            // powiadomień — wracamy do wartości domyślnych, czyli do zachowania
            // sprzed panelu. Ta sama ostrożność co w ScoringSettings::all().
            self::$cache = [];
        }
        return self::$cache;
    }

    /**
     * Zapis jednego parametru. `null` KASUJE nadpisanie, czyli przywraca
     * domyślną — i to jest jedyny sposób powrotu, bo panel nie musi znać
     * liczby z kodu.
     */
    public static function set(string $key, ?float $value, ?int $userId): void
    {
        if (!isset(self::PARAMETRY[$key])) {
            return;
        }
        $db = Database::connection();
        if ($value === null) {
            $db->prepare('DELETE FROM notification_settings WHERE setting_key = :k')->execute(['k' => $key]);
        } else {
            // Przycięcie do zakresu TUTAJ, nie w kontrolerze: formularz jest
            // jednym z wejść, a nie jedynym, i to jest reguła domenowa
            // („godzina jest z doby"), nie sprawa ekranu.
            $meta = self::PARAMETRY[$key];
            $value = max((float) $meta['min'], min((float) $meta['max'], $value));
            $db->prepare(
                'INSERT INTO notification_settings (setting_key, setting_value, updated_by)
                 VALUES (:k, :v, :u)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)'
            )->execute(['k' => $key, 'v' => $value, 'u' => $userId]);
        }
        self::$cache = null;
    }

    /** Zapomnij cache — pod testy, które zmieniają ustawienia w locie. */
    public static function forget(): void
    {
        self::$cache = null;
    }

    /** Kiedy i przez kogo ostatnio ruszane — pod nagłówek panelu. */
    public static function lastChange(): ?array
    {
        try {
            $row = Database::connection()->query(
                'SELECT s.setting_key, s.updated_at, u.name AS who
                   FROM notification_settings s
                   LEFT JOIN users u ON u.id = s.updated_by
                  ORDER BY s.updated_at DESC LIMIT 1'
            )->fetch();
            return $row ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
