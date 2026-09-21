<?php
// core/Utils/Format.php
namespace Utils;

class Format
{
    // Separatory liczb wg języka strony (2026-09-16): po polsku 1 200,5,
    // po angielsku 1,200.5. Jednostki i waluty zostają — to nie tłumaczenie.
    public static function price(float $amount, string $currency = 'zł'): string
    {
        [$dec, $tys] = \Core\Lang::numberSeparators();
        return number_format($amount, 0, $dec, $tys) . ' ' . $currency;
    }

    // Kod waluty (PLN/EUR/...) -> symbol do wyświetlenia. Nieznany kod
    // wraca bez zmian, więc nowa waluta w słowniku nie wywala widoku.
    public static function currencySymbol(string $currencyCode): string
    {
        return match ($currencyCode) {
            'PLN' => 'zł',
            'EUR' => '€',
            default => $currencyCode,
        };
    }

    public static function distance(float $km): string
    {
        [$dec, $tys] = \Core\Lang::numberSeparators();
        return rtrim(rtrim(number_format($km, 1, $dec, $tys), '0'), $dec) . ' km';
    }

    /**
     * POLSKA LICZBA MNOGA — `1 pole · 2 pola · 5 pól` (2026-09-03).
     *
     * Ta sama reguła stała dotąd jako lokalna domknięta funkcja `$plural`
     * w dziesięciu widokach (`trail.php`, `rider-profile.php`, `discovery.php`,
     * `chronicle.php`, `pulse.php`, …) — dziesięć identycznych kopii jednego
     * warunku. Nowy kod bierze ją stąd; przepisywanie tamtych to osobne
     * porządki, nie warunek tej zmiany.
     */
    //
    // Od 2026-09-16 deleguje do Core\Lang::plural() — po polsku wynik jest
    // identyczny, w innym języku formy przychodzą ze słownika.
    public static function plural(int $n, string $one, string $few, string $many): string
    {
        return \Core\Lang::plural($n, $one, $few, $many);
    }

    /**
     * CZAS TRWANIA PRZEJAZDU (2026-09-03, strona `/przejazd/{id}`) — sekundy
     * z `rider_activities.moving_seconds` na „2 h 14 min".
     *
     * Kolumna istnieje od migr. 049, ale do tej pory nie miała ani jednego
     * czytelnika, więc nie było gdzie tego formatować. Bez godzin przy
     * przejazdach krótszych niż godzina („48 min"), bez sekund zawsze — GPS
     * i tak liczy je z dokładnością pauzy na skrzyżowaniu.
     *
     * null (brak danych) NIE JEST zerem: plik bez znaczników czasu nie mówi
     * „jechał 0 minut", tylko „nie wiadomo".
     */
    public static function duration(?int $seconds): ?string
    {
        if ($seconds === null || $seconds <= 0) {
            return null;
        }
        $minuty = (int) round($seconds / 60);
        $godziny = intdiv($minuty, 60);
        $reszta = $minuty % 60;

        return $godziny > 0 ? $godziny . ' h ' . $reszta . ' min' : $reszta . ' min';
    }

    public static function dateP(?string $date): ?string
    {
        if (!$date) return null;
        // Nazwy miesięcy wg języka strony (Core\Lang::months). Kolejność
        // dzień–miesiąc–rok zostaje też po angielsku (brytyjska).
        $months = \Core\Lang::months('long');
        $ts = strtotime($date);
        return (int) date('j', $ts) . ' ' . $months[(int) date('n', $ts)] . ' ' . date('Y', $ts);
    }

    // ROK DOKŁADANY TYLKO WTEDY, GDY NIE JEST BIEŻĄCY (2026-09-11, zgłoszenie
    // usera: „w dymku śladu mam «25 maj» i nie wiadomo, którego roku").
    //
    // Do tej daty ta metoda nie zwracała roku NIGDY, i przez lata było to
    // nieszkodliwe: jedynym jej odbiorcą były karty nadchodzących wydarzeń,
    // gdzie rok wynika z kontekstu. Odkąd ten sam format opisuje ARCHIWUM
    // (przejazdy, aktywność na profilu, dymki na mapie — rzeczy sprzed dwóch
    // sezonów), „25 maj" przestało być datą i stało się zagadką.
    //
    // WARUNKOWO, a nie zawsze: doklejanie „2026" do każdej daty w tym roku
    // zaszumiłoby najczęstszy przypadek (wyjazd za dwa tygodnie) informacją,
    // której nikt nie potrzebuje. Rok pojawia się dokładnie wtedy, kiedy
    // czytelnik nie może go wydedukować — czyli gdy jest inny niż dzisiejszy.
    // Dotyczy to tak samo przeszłości, jak przyszłości („5 mar 2027").
    public static function dateShort(?string $date): ?string
    {
        if (!$date) return null;
        $months = \Core\Lang::months('short');
        $ts = strtotime($date);
        $out = (int) date('j', $ts) . ' ' . $months[(int) date('n', $ts)];
        return date('Y', $ts) === date('Y') ? $out : $out . ' ' . date('Y', $ts);
    }

    // Zakres dat (pokrec_z_kims — okno dostępności albo konkretny wielodniowy
    // termin, patrz Models\EventEdition::effectiveEndDate()) w wersji krótkiej
    // ("9–10 sie") i długiej ("9–10 sierpnia 2026"), pojedyncza data gdy
    // start===koniec. Miesiąc dokładany tylko raz, gdy obie daty w tym samym
    // miesiącu — inaczej dwie pełne daty rozdzielone półpauzą.
    public static function dateRangeShort(string $start, string $end): string
    {
        if ($start === $end) {
            return self::dateShort($start);
        }
        $months = \Core\Lang::months('short');
        $startTs = strtotime($start);
        $endTs = strtotime($end);
        if (date('n Y', $startTs) === date('n Y', $endTs)) {
            // Rok wg tej samej reguły co w dateShort() — inaczej „9–10 sie"
            // gubiłoby go tam, gdzie pojedyncza data by go pokazała.
            $rok = date('Y', $endTs) === date('Y') ? '' : ' ' . date('Y', $endTs);
            return (int) date('j', $startTs) . '–' . (int) date('j', $endTs) . ' ' . $months[(int) date('n', $endTs)] . $rok;
        }
        return self::dateShort($start) . ' – ' . self::dateShort($end);
    }

    public static function dateRangeP(string $start, string $end): string
    {
        if ($start === $end) {
            return self::dateP($start);
        }
        $months = \Core\Lang::months('long');
        $startTs = strtotime($start);
        $endTs = strtotime($end);
        if (date('n Y', $startTs) === date('n Y', $endTs)) {
            return (int) date('j', $startTs) . '–' . (int) date('j', $endTs) . ' ' . $months[(int) date('n', $endTs)] . ' ' . date('Y', $endTs);
        }
        return self::dateP($start) . ' – ' . self::dateP($end);
    }

    public static function dateTime(?string $date): ?string
    {
        if (!$date) return null;
        return date('d.m.Y H:i', strtotime($date));
    }

    // Polskie znaki podmieniane JAWNIE, przed iconv-em. Sam
    // iconv('ASCII//TRANSLIT') daje wynik zależny od locale systemu i na
    // Windowsie zamienia „ś" na dwa znaki (`'s`), po czym apostrof leci do
    // myślnika — tak powstał w bazie slug `kasia-wi-sniewska` zamiast
    // `kasia-wisniewska`. Tabela poniżej daje ten sam wynik na każdym systemie.
    private const DIACRITICS = [
        'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n',
        'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
        'Ą' => 'A', 'Ć' => 'C', 'Ę' => 'E', 'Ł' => 'L', 'Ń' => 'N',
        'Ó' => 'O', 'Ś' => 'S', 'Ź' => 'Z', 'Ż' => 'Z',
    ];

    public static function slugify(string $text): string
    {
        $text = strtr($text, self::DIACRITICS);
        // iconv zostaje dla RESZTY alfabetów (niemieckie umlauty, czeskie
        // haczyki w nazwach tras przygranicznych). Gdy padnie, pracujemy dalej
        // na tekście po podmianie polskich znaków, zamiast zwracać pustkę.
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT', $text);
        if ($converted !== false) {
            $text = $converted;
        }
        $text = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $text));
        return trim($text, '-');
    }

    // Slugify + "dosalanie" -2, -3... aż trafi na wolny slug — wspólny
    // mechanizm dla Event (tabela events) i Organizer (organizer_profiles),
    // które wcześniej miały tę samą pętlę skopiowaną osobno. $exists sprawdza
    // zajętość KONKRETNEGO kandydata w danej tabeli — Format nie musi nic
    // wiedzieć o bazie, tylko wywołujący zna tabelę/kolumnę/wyjątki.
    public static function uniqueSlug(string $text, string $fallback, callable $exists): string
    {
        $base = self::slugify($text);
        if ($base === '') {
            $base = $fallback;
        }

        $slug = $base;
        $suffix = 2;
        while ($exists($slug)) {
            $slug = $base . '-' . $suffix;
            $suffix++;
        }
        return $slug;
    }
}
