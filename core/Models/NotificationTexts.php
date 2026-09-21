<?php

namespace Models;

use Core\Database;

/**
 * TREŚCI POWIADOMIEŃ (migr. 084, 2026-09-11) — edytowalne w panelu
 * `/admin/powiadomienia`, przy rodzaju powiadomienia, którego dotyczą.
 *
 * ============================================================================
 * DWA RODZAJE PODSTAWIEŃ I TO JEST NAJWAŻNIEJSZE ROZRÓŻNIENIE W TYM PLIKU
 * ============================================================================
 * ZNACZNIKI (`{nadawca}`, `{nazwa}`) to DANE. Wstawiane są zawsze przez
 * `htmlspecialchars` — cudza nazwa wyjazdu albo imię nadawcy nie ma jak stać
 * się znacznikiem HTML w mailu wychodzącym w imieniu serwisu.
 *
 * BLOKI (`{przycisk}`, `{mapa}`, `{cytat}`) to GOTOWY HTML SKŁADANY PRZEZ KOD.
 * Admin ich nie pisze — decyduje tylko, GDZIE mają stanąć i czy w ogóle.
 * Dzięki temu przycisk zawsze wygląda jak przycisk, a mapa jest obrazkiem
 * z naszego serwera, a nie czymś, co da się podmienić w formularzu.
 *
 * ============================================================================
 * HTML W TREŚCI MAILA — NA ŻYCZENIE USERA, Z BIAŁĄ LISTĄ
 * ============================================================================
 * Do 2026-09-11 treści były czystym tekstem; user poprosił wprost o edycję
 * HTML z podglądem. Ryzyko jest realne (mail wychodzi w imieniu serwisu),
 * więc `oczyscHtml()` przepuszcza wyłącznie znaczniki formatujące i **zrywa
 * wszystkie atrybuty poza `href`**, a `href` musi być http(s) albo blokiem.
 * Odcina to `onclick`, `style`, `<script>`, `<iframe>` i `javascript:` —
 * czyli wszystko, czym dałoby się zamienić maila w coś innego, niż wygląda.
 *
 * ============================================================================
 * OCHRONA POZIOMU UJAWNIENIA SKARBU — POWÓD, DLA KTÓREGO SĄ WARIANTY
 * ============================================================================
 * Kto nie odkrył pola, nie poznaje nazwy skarbu (`Treasure::reveal()`). Gdyby
 * istniała JEDNA treść z opcjonalnym `{nazwa}`, wystarczyłby jeden wpis
 * w formularzu, żeby wyciek stał się faktem — cicho. Dlatego powiadomienie
 * o skarbie ma dwa warianty (`jawny`/`ukryty`), wariant wybiera KOD na
 * podstawie poziomu, a wariant „ukryty" NIE MA `nazwa` na swojej liście
 * znaczników: `set()` ją wycina, a `render()` i tak by jej nie podstawił.
 */
final class NotificationTexts
{
    /** @var array<string,string>|null */
    private static ?array $cache = null;

    private const MAX_DLUGOSC = 4000;

    /** Znaczniki HTML, które wolno zostawić w treści maila. */
    private const DOZWOLONE_TAGI = '<p><br><b><strong><i><em><u><ul><ol><li><h2><h3><a><span><hr><small><blockquote>';

    /**
     * BLOKI — gotowe kawałki HTML składane przez kod. Klucz to nazwa znacznika,
     * wartość to opis dla panelu. Co je odróżnia od zwykłych znaczników:
     * nie są escape'owane, bo nie pochodzą od człowieka.
     */
    public const BLOKI = [
        'powitanie' => 'Grzecznościowe „Cześć, Imię!" — puste, gdy nie znamy imienia.',
        'przycisk'  => 'Duży przycisk z linkiem prowadzącym w odpowiednie miejsce serwisu.',
        'cytat'     => 'Treść wiadomości w ramce — tak jak w dzisiejszym mailu o nowej wiadomości.',
        'mapa'      => 'Obrazek mapy okolicy, o której jest powiadomienie (generowany na serwerze).',
        'powod'     => 'Zdanie wyjaśniające, dlaczego akurat ten wyjazd — składa je silnik dopasowań.',
        'karta'     => 'Ramka z faktami o wyjeździe: tytuł, termin, dystans i ilu już jedzie.',
    ];

    /**
     * WSZYSTKIE POWIADOMIENIA I ICH TREŚCI, pogrupowane PER RODZAJ — dokładnie
     * tak, jak stoją w panelu. `typ` wiąże wpis z rodzajem z `NotificationGate`
     * (i z jego wyłącznikiem), `warianty` istnieją tam, gdzie treść zależy od
     * reguły, której nie wolno obejść z formularza.
     */
    public const POWIADOMIENIA = [
        'treasure_nearby' => [
            'label' => 'Nowy skarb w okolicy',
            'typ'   => 'treasure_nearby',
            'opis'  => 'Zachęta z zadania w tle: do osób, które mają odkryte pola w regionie nowego skarbu.',
            'kanaly' => ['push', 'mail'],
            'znaczniki' => ['region' => 'Nazwa regionu (województwa), w którym pojawił się skarb.'],
            'bloki' => ['powitanie', 'przycisk', 'mapa'],
            'warianty' => [
                'jawny'  => ['label' => 'Skarb jawny (poziom 2)', 'znaczniki' => ['nazwa' => 'Nazwa skarbu — TYLKO tutaj, bo przy tym poziomie widać ją też na mapie.']],
                'ukryty' => ['label' => 'Skarb ukryty (poziom 0–1)', 'znaczniki' => []],
            ],
            'domyslne' => [
                'push.title.jawny'  => 'Nowy skarb w Twojej okolicy',
                'push.body.jawny'   => '{nazwa}',
                'push.title.ukryty' => 'Nowy skarb w Twojej okolicy',
                'push.body.ukryty'  => 'Coś ciekawego czeka w Twojej okolicy — sprawdź mapę.',
                'mail.subject.jawny' => 'Nowy skarb w Twojej okolicy — ridemore.bike',
                'mail.body.jawny'    => "{powitanie}\n<p>W okolicy, którą już odkrywasz ({region}), pojawił się nowy skarb: <b>{nazwa}</b>.</p>\n{mapa}\n<p>Dokładne miejsce odsłoni się na mapie — tak samo jak przy pozostałych skarbach.</p>\n{przycisk}\n<p><small>Piszemy o tym raz. Jeśli akurat nie po drodze — nie wrócimy z tym samym skarbem.</small></p>",
                'mail.subject.ukryty' => 'Nowy skarb w Twojej okolicy — ridemore.bike',
                'mail.body.ukryty'    => "{powitanie}\n<p>W okolicy, którą już odkrywasz ({region}), pojawiło się coś nowego do znalezienia.</p>\n{mapa}\n<p>Dokładne miejsce odsłoni się na mapie — tak samo jak przy pozostałych skarbach.</p>\n{przycisk}\n<p><small>Piszemy o tym raz. Jeśli akurat nie po drodze — nie wrócimy z tym samym skarbem.</small></p>",
            ],
        ],

        'nearby_route' => [
            'label' => 'Nowa trasa w okolicy',
            'typ'   => 'nearby_new',
            'opis'  => 'Zachęta: w regionie, w którym masz odkryte pola, pojawiła się nowa znana trasa.',
            'kanaly' => ['push', 'mail'],
            'znaczniki' => [
                'nazwa'  => 'Nazwa trasy.',
                'region' => 'Region (województwo), w którym trasa leży.',
            ],
            'bloki' => ['powitanie', 'przycisk', 'karta', 'mapa'],
            'domyslne' => [
                'push.title'   => 'Nowa trasa w Twojej okolicy',
                'push.body'    => '{nazwa}',
                'mail.subject' => 'Nowa trasa w Twojej okolicy: {nazwa}',
                'mail.body'    => "{powitanie}\n<p>W okolicy, którą już odkrywasz ({region}), pojawiła się nowa trasa.</p>\n{karta}\n{przycisk}\n<p><small>Piszemy o tym raz. Jeśli akurat nie po drodze — nie wrócimy z tą samą trasą.</small></p>",
            ],
        ],

        'nearby_event' => [
            'label' => 'Nowy wyjazd w okolicy',
            'typ'   => 'nearby_new',
            'opis'  => 'Zachęta: ktoś opublikował wyjazd w regionie, w którym masz odkryte pola.',
            'kanaly' => ['push', 'mail'],
            'znaczniki' => [
                'nazwa'  => 'Tytuł wyjazdu.',
                'region' => 'Region (województwo), w którym wyjazd się odbywa.',
            ],
            'bloki' => ['powitanie', 'przycisk', 'karta'],
            'domyslne' => [
                'push.title'   => 'Nowy wyjazd w Twojej okolicy',
                'push.body'    => '{nazwa}',
                'mail.subject' => 'Nowy wyjazd w Twojej okolicy: {nazwa}',
                'mail.body'    => "{powitanie}\n<p>W okolicy, którą już odkrywasz ({region}), ktoś ogłosił nowy wyjazd.</p>\n{karta}\n{przycisk}\n<p><small>To tylko podpowiedź — jeśli to nie dla Ciebie, po prostu ją zignoruj.</small></p>",
            ],
        ],

        'message' => [
            'label' => 'Nowa wiadomość',
            'typ'   => 'message',
            'opis'  => 'Transakcyjne — ktoś napisał prywatnie. Poza budżetem i poza ciszą nocną.',
            'kanaly' => ['push', 'mail'],
            'znaczniki' => ['nadawca' => 'Nazwa osoby, która napisała.'],
            'bloki' => ['powitanie', 'przycisk', 'cytat'],
            'domyslne' => [
                // Push świadomie bez znaczników: treść wiadomości ani nadawca
                // nie mają wychodzić na ekran blokady telefonu.
                'push.title'   => 'Nowa wiadomość',
                'push.body'    => 'Masz nową wiadomość na Ridemore.',
                'mail.subject' => 'Nowa wiadomość od {nadawca} — ridemore.bike',
                'mail.body'    => "{powitanie}\n<p>Masz nową wiadomość od <b>{nadawca}</b> na ridemore.bike:</p>\n{cytat}\n{przycisk}",
            ],
        ],

        'message_group' => [
            'label' => 'Wiadomość w grupie wyjazdu',
            'typ'   => 'message',
            'opis'  => 'Ogłoszenie organizatora w kanale grupowym. Czat uczestników zostaje w aplikacji.',
            'kanaly' => ['push', 'mail'],
            'znaczniki' => ['wyjazd' => 'Tytuł wydarzenia.', 'nadawca' => 'Nazwa organizatora.'],
            'bloki' => ['powitanie', 'przycisk', 'cytat'],
            'domyslne' => [
                'push.title'   => 'Nowa wiadomość — {wyjazd}',
                'push.body'    => 'Sprawdź kanał grupowy wyjazdu.',
                'mail.subject' => 'Nowa wiadomość w grupie „{wyjazd}" — ridemore.bike',
                'mail.body'    => "{powitanie}\n<p>Masz nową wiadomość od <b>{nadawca}</b> na ridemore.bike:</p>\n{cytat}\n{przycisk}",
            ],
        ],

        'event_join' => [
            'label' => 'Ktoś dołączył do wyjazdu',
            'typ'   => 'event_join',
            'opis'  => 'Transakcyjne, do organizatora. Mail o zapisie idzie osobno, na adres powiadomień organizatora.',
            'kanaly' => ['push'],
            'znaczniki' => ['wyjazd' => 'Tytuł wydarzenia.'],
            'bloki' => [],
            'domyslne' => [
                'push.title' => 'Ktoś dołączył do Twojego wyjazdu',
                'push.body'  => '{wyjazd}',
            ],
        ],

        'match' => [
            'label' => 'Dopasowany wyjazd',
            'typ'   => 'match',
            'opis'  => 'Zachęta mailowa dla osób, które włączyły w koncie powiadomienia o dopasowaniach.',
            'kanaly' => ['mail'],
            'znaczniki' => [],
            'bloki' => ['powitanie', 'przycisk', 'powod', 'karta'],
            'domyslne' => [
                'mail.subject' => 'Pojawił się wyjazd, który może Cię zainteresować',
                'mail.body'    => "{powitanie}\n{powod}\n{karta}\n{przycisk}\n<p><small>To tylko podpowiedź — jeśli to nie dla Ciebie, po prostu ją zignoruj.</small></p>",
            ],
        ],

        'match_aspirational' => [
            'label' => 'Dopasowany wyjazd — marzenie z profilu',
            'typ'   => 'match',
            'opis'  => 'To samo, ale dla miejsc i formatów zaznaczonych jako „gdzie chcę kiedyś pojechać".',
            'kanaly' => ['mail'],
            'znaczniki' => [],
            'bloki' => ['powitanie', 'przycisk', 'powod', 'karta'],
            'domyslne' => [
                'mail.subject' => 'Pojawił się wyjazd, o którym marzyłeś/aś',
                'mail.body'    => "{powitanie}\n{powod}\n{karta}\n{przycisk}\n<p><small>To tylko podpowiedź — jeśli to nie dla Ciebie, po prostu ją zignoruj.</small></p>",
            ],
        ],

        'device_ride' => [
            'label' => 'Przejazd dodany z licznika',
            'typ'   => 'device_ride',
            'opis'  => 'Transakcyjne — Polar/Wahoo zgłosił nowy trening, a automat (włączony przez człowieka przy połączonym koncie) dodał go jako przejazd.',
            'kanaly' => ['push', 'mail'],
            'znaczniki' => [
                'licznik' => 'Nazwa serwisu licznika: Polar, Wahoo.',
                'dystans' => 'Dystans przejazdu, np. „42,5 km".',
                'pola'    => 'Nowe pola na mapie razem z odmianą, np. „17 nowych pól".',
            ],
            'bloki' => ['powitanie', 'przycisk'],
            'domyslne' => [
                // „z licznika {licznik}", nie „z {licznik}" — nazwy serwisów
                // się nie odmieniają, a „z Polar" brzmi jak błąd.
                'push.title'   => 'Przejazd z licznika {licznik} jest już na mapie',
                'push.body'    => '{dystans} · {pola}',
                'mail.subject' => 'Twój przejazd z licznika {licznik} jest już na mapie — ridemore.bike',
                'mail.body'    => "{powitanie}\n<p>Dodaliśmy Twój nowy przejazd z licznika <b>{licznik}</b>: {dystans}, a na mapie przybyło <b>{pola}</b>.</p>\n{przycisk}\n<p><small>Przejazdy dodają się same, bo tak ustawiłeś/aś przy połączonym koncie. Wyłączysz to w „Moich przejazdach\", tam też usuniesz przejazd, który nie powinien się liczyć.</small></p>",
            ],
        ],
    ];

    /** Przykładowe dane do podglądu — WYŁĄCZNIE panel. */
    public const PRZYKLAD = [
        'nazwa'   => 'Kapliczka pod Trzema Dębami',
        'region'  => 'małopolskie',
        'wyjazd'  => 'Weekend gravelowy w Beskidach',
        'nadawca' => 'Anna Kowalska',
        'licznik' => 'Polar',
        'dystans' => '42,5 km',
        'pola'    => '17 nowych pól',
    ];

    // ---- ODCZYT --------------------------------------------------------

    /**
     * Pełny klucz: `{powiadomienie}.{kanal}.{pole}[.{wariant}]`,
     * np. `treasure_nearby.mail.body.jawny`, `message.push.title`.
     */
    public static function klucz(string $powiadomienie, string $kanal, string $pole, ?string $wariant = null): string
    {
        // Pusty string traktujemy jak brak wariantu, bo PHP zamienia klucz
        // `null` w tablicy na `''` — a lista wariantów bywa właśnie tablicą
        // z jednym pustym kluczem tam, gdzie wariantów nie ma. Bez tego
        // powstawał klucz z kropką na końcu (`message.push.title.`), który
        // nie istnieje, i wysyłka wywracała się dopiero w cronie.
        $wariant = ($wariant === null || $wariant === '') ? null : $wariant;
        return $powiadomienie . '.' . $kanal . '.' . $pole . ($wariant !== null ? '.' . $wariant : '');
    }

    /** Treść domyślna — z definicji w kodzie. */
    public static function domyslny(string $klucz): ?string
    {
        [$powiadomienie, $reszta] = explode('.', $klucz, 2);
        return self::POWIADOMIENIA[$powiadomienie]['domyslne'][$reszta] ?? null;
    }

    /** Treść obowiązująca: zmieniona w panelu albo domyślna z kodu. */
    public static function get(string $klucz): string
    {
        $domyslna = self::domyslny($klucz);
        if ($domyslna === null) {
            throw new \InvalidArgumentException("Nieznana treść powiadomienia: $klucz");
        }
        // WIELOJĘZYCZNOŚĆ (2026-09-16): panel edytuje brzmienie POLSKIE. W innym
        // języku idzie tłumaczenie treści domyślnej ze słownika interfejsu —
        // polska poprawka z panelu nie może trafić do Anglika po polsku.
        if (!\Core\Lang::isDefault()) {
            return __($domyslna);
        }
        return self::all()[$klucz] ?? $domyslna;
    }

    /**
     * Treść z podstawionymi znacznikami i blokami — to ją wołają miejsca wysyłki.
     *
     * @param array $dane   wartości znaczników (podstawiane przez htmlspecialchars)
     * @param array $bloki  gotowy HTML per nazwa bloku (wstawiany bez zmian)
     * @param bool  $plain  true dla pusha: wynik bez żadnego HTML-a
     */
    public static function render(string $klucz, array $dane = [], array $bloki = [], bool $plain = false): string
    {
        $tekst = self::get($klucz);
        $dozwolone = self::znacznikiDla($klucz);

        // 1. DANE — zawsze escape'owane. Cudza nazwa wyjazdu nie ma jak stać
        //    się znacznikiem HTML w mailu wychodzącym w naszym imieniu.
        foreach ($dozwolone as $znacznik) {
            $wartosc = (string) ($dane[$znacznik] ?? '');
            $tekst = str_replace(
                '{' . $znacznik . '}',
                $plain ? $wartosc : htmlspecialchars($wartosc, ENT_QUOTES, 'UTF-8'),
                $tekst
            );
        }

        // 2. BLOKI — gotowy HTML z kodu, więc bez escape'owania. W pushu bloki
        //    nie mają sensu (nie ma gdzie pokazać przycisku), więc znikają.
        foreach (array_keys(self::BLOKI) as $blok) {
            $tekst = str_replace('{' . $blok . '}', $plain ? '' : (string) ($bloki[$blok] ?? ''), $tekst);
        }

        // 3. Sprzątanie po znacznikach, które zostały (niewypełnione albo
        //    wpisane wprost do bazy) — do człowieka nie może pójść `{nazwa}`.
        $tekst = preg_replace('/\{[a-z_]+\}/i', '', $tekst) ?? $tekst;
        $tekst = preg_replace('/\s*\(\s*\)/', '', $tekst) ?? $tekst;

        if ($plain) {
            $tekst = strip_tags($tekst);
            return trim(preg_replace('/\s{2,}/', ' ', $tekst) ?? $tekst);
        }
        // Puste akapity po nieużytych blokach tylko rozpychają maila.
        return trim(preg_replace('#<p>\s*</p>#', '', $tekst) ?? $tekst);
    }

    /** Znaczniki DANYCH dozwolone dla tego klucza (wariant zawęża/rozszerza). */
    public static function znacznikiDla(string $klucz): array
    {
        $czesci = explode('.', $klucz);
        $powiadomienie = $czesci[0];
        $def = self::POWIADOMIENIA[$powiadomienie] ?? null;
        if ($def === null) {
            return [];
        }
        $znaczniki = array_keys($def['znaczniki'] ?? []);

        // Wariant („jawny"/„ukryty") może DODAĆ znacznik, którego wspólna lista
        // nie zawiera — i to jest jedyne miejsce, w którym `nazwa` istnieje.
        $wariant = $czesci[3] ?? null;
        if ($wariant !== null && isset($def['warianty'][$wariant]['znaczniki'])) {
            $znaczniki = array_merge($znaczniki, array_keys($def['warianty'][$wariant]['znaczniki']));
        }
        return $znaczniki;
    }

    /** Bloki dozwolone dla danego powiadomienia. */
    public static function blokiDla(string $powiadomienie): array
    {
        return self::POWIADOMIENIA[$powiadomienie]['bloki'] ?? [];
    }

    // ---- ZAPIS ---------------------------------------------------------

    /**
     * Zapis jednej treści. Pusta albo identyczna z domyślną KASUJE nadpisanie.
     *
     * @return string[] nazwy znaczników odrzuconych jako niedozwolone — panel
     *                  ma o nich powiedzieć wprost, bo ciche wycięcie `{nazwa}`
     *                  wygląda jak zjedzony tekst, a nie jak działająca reguła.
     */
    public static function set(string $klucz, string $tekst, ?int $userId): array
    {
        $domyslna = self::domyslny($klucz);
        if ($domyslna === null) {
            return [];
        }

        // NORMALIZACJA KOŃCÓW LINII — bez niej nic tu nie działa tak, jak
        // wygląda. Przeglądarka zwraca z `<textarea>` końce linii jako CRLF,
        // a treści domyślne w kodzie mają LF, więc porównanie "tekst równy
        // domyślnemu" NIGDY nie wychodziło prawdą: jeden zapis formularza
        // robił nadpisania ze WSZYSTKICH treści, także tych nietkniętych.
        // Skutek byłby cichy i kosztowny — późniejsza poprawka brzmienia
        // w kodzie nie dotarłaby już do nikogo, kto raz kliknął "Zapisz".
        $tekst = str_replace(["\r\n", "\r"], "\n", $tekst);

        $dozwolone = array_merge(self::znacznikiDla($klucz), self::blokiDla(explode('.', $klucz)[0]));
        $usuniete  = [];
        $tekst = preg_replace_callback('/\{([a-z_]+)\}/i', static function (array $m) use ($dozwolone, &$usuniete): string {
            if (in_array($m[1], $dozwolone, true)) {
                return $m[0];
            }
            $usuniete[] = $m[1];
            return '';
        }, $tekst) ?? $tekst;

        $tekst = str_contains($klucz, '.push.')
            ? trim(strip_tags($tekst))          // push nie ma gdzie pokazać HTML-a
            : self::oczyscHtml($tekst);

        if (mb_strlen($tekst) > self::MAX_DLUGOSC) {
            $tekst = mb_substr($tekst, 0, self::MAX_DLUGOSC);
        }

        $db = Database::connection();
        if ($tekst === '' || $tekst === $domyslna) {
            $db->prepare('DELETE FROM notification_texts WHERE text_key = :k')->execute(['k' => $klucz]);
        } else {
            $db->prepare(
                'INSERT INTO notification_texts (text_key, text_value, updated_by)
                 VALUES (:k, :v, :u)
                 ON DUPLICATE KEY UPDATE text_value = VALUES(text_value), updated_by = VALUES(updated_by)'
            )->execute(['k' => $klucz, 'v' => $tekst, 'u' => $userId]);
        }
        self::$cache = null;

        return array_values(array_unique($usuniete));
    }

    /**
     * HTML przepuszczony przez białą listę.
     *
     * `strip_tags` z listą dozwolonych ZOSTAWIA atrybuty, więc samo to nie
     * wystarcza: `<a onclick=…>` przeszłoby bez szwanku. Drugi krok zdejmuje
     * więc WSZYSTKIE atrybuty poza `href`, a trzeci pilnuje, żeby `href` był
     * adresem http(s) albo blokiem — nie `javascript:`.
     */
    public static function oczyscHtml(string $html): string
    {
        $html = strip_tags($html, self::DOZWOLONE_TAGI);

        $html = preg_replace_callback('#<([a-z0-9]+)\b([^>]*)>#i', static function (array $m): string {
            $tag = strtolower($m[1]);
            if ($tag !== 'a') {
                return '<' . $tag . '>';
            }
            if (preg_match('#\bhref\s*=\s*(["\'])(.*?)\1#i', $m[2], $h)) {
                $url = trim($h[2]);
                if (preg_match('#^(https?://|\{[a-z_]+\})#i', $url)) {
                    return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">';
                }
            }
            return '<a>';
        }, $html) ?? $html;

        return trim($html);
    }

    // ---- PODGLĄD -------------------------------------------------------

    /**
     * Podgląd dla panelu — TĄ SAMĄ metodą co wysyłka, na danych z `PRZYKLAD`
     * i na przykładowych blokach. Podgląd idący inną drogą prędzej czy później
     * pokazywałby coś, czego nikt nie dostanie — a wtedy jest gorszy niż brak.
     */
    public static function podglad(string $klucz, ?string $tekstRoboczy = null): string
    {
        $plain = str_contains($klucz, '.push.');
        if ($tekstRoboczy !== null) {
            // Podgląd tekstu, którego jeszcze nie zapisano (panel, na żywo) —
            // przechodzi przez DOKŁADNIE tę samą sanityzację co zapis.
            $dozwolone = array_merge(self::znacznikiDla($klucz), self::blokiDla(explode('.', $klucz)[0]));
            $tekstRoboczy = preg_replace_callback('/\{([a-z_]+)\}/i', static fn(array $m): string =>
                in_array($m[1], $dozwolone, true) ? $m[0] : '', $tekstRoboczy) ?? $tekstRoboczy;
            $tekstRoboczy = $plain ? strip_tags($tekstRoboczy) : self::oczyscHtml($tekstRoboczy);
            return self::renderTekst($tekstRoboczy, $klucz, self::PRZYKLAD, self::blokiPrzykladowe(explode('.', $klucz)[0], explode('.', $klucz)[3] ?? null), $plain);
        }
        return self::render($klucz, self::PRZYKLAD, self::blokiPrzykladowe(explode('.', $klucz)[0], explode('.', $klucz)[3] ?? null), $plain);
    }

    /**
     * Przykładowe bloki do podglądu — idą TYM SAMYM kodem co prawdziwe.
     *
     * PER POWIADOMIENIE, a nie jeden zestaw na wszystko. Pierwsza wersja miała
     * jeden wspólny przycisk („Zobacz na mapie" → /odkrycia) i przez to podgląd
     * KŁAMAŁ: mail o wiadomości pokazywał przycisk do mapy odkryć, mail
     * o dopasowaniu tak samo. User złapał to od razu i słusznie — podgląd,
     * który pokazuje inny cel niż wysyłka, jest gorszy niż jego brak.
     */
    public static function blokiPrzykladowe(?string $powiadomienie = null, ?string $wariant = null): array
    {
        $przyciski = [
            // Wariant zmienia nie tylko treść, ale i CEL: skarb ukryty dostaje
            // gołą mapę, bo identyfikator w adresie byłby wskazówką, gdzie
            // szukać czegoś, czego mapa celowo nie pokazuje. Podgląd musi to
            // odwzorować, inaczej kłamie dokładnie w tym jednym miejscu,
            // w którym najbardziej nie wolno mu kłamać.
            'treasure_nearby'    => [$wariant === 'ukryty'
                ? 'https://ridemore.bike/odkrycia'
                : 'https://ridemore.bike/odkrycia?skarb=123', 'Zobacz na mapie →'],
            'nearby_route'       => ['https://ridemore.bike/trasy/beskidzka-petla', 'Zobacz trasę →'],
            'nearby_event'       => ['https://ridemore.bike/events/weekend-gravelowy', 'Zobacz wydarzenie →'],
            'message'            => ['https://ridemore.bike/wiadomosci/42', 'Odpowiedz →'],
            'message_group'      => ['https://ridemore.bike/wiadomosci/grupa/42', 'Otwórz kanał grupowy →'],
            'event_join'         => ['https://ridemore.bike/wydarzenia/weekend-gravelowy/uczestnicy', 'Zobacz uczestników →'],
            'match'              => ['https://ridemore.bike/events/weekend-gravelowy', 'Zobacz wydarzenie →'],
            'match_aspirational' => ['https://ridemore.bike/events/weekend-gravelowy', 'Zobacz wydarzenie →'],
            'device_ride'        => ['https://ridemore.bike/przejazd/123', 'Zobacz przejazd →'],
        ];
        [$url, $etykieta] = $przyciski[$powiadomienie] ?? ['https://ridemore.bike', 'Otwórz →'];

        return [
            'powitanie' => \Utils\MailTemplate::greeting('Marek'),
            'przycisk'  => \Utils\MailTemplate::button($url, $etykieta),
            'cytat'     => self::blokCytat('Jedziemy w sobotę o 9:00 z rynku. Kto z Wami?'),
            'mapa'      => self::blokMapa('https://ridemore.bike/assets/logo/apple-touch-icon.png', 'małopolskie'),
            'powod'     => self::blokPowod('Pasuje do Twoich tras w Beskidach i do dystansu, który zwykle wybierasz.'),
            // KARTA TEŻ ZALEŻY OD POWIADOMIENIA, nie tylko przycisk. Trasa nie
            // ma terminu ani składu — pokazywanie ich w jej podglądzie byłoby
            // tym samym kłamstwem co wspólny przycisk „Zobacz na mapie" przed
            // poprawką z tego samego dnia.
            'karta'     => $powiadomienie === 'nearby_route'
                ? self::blokKarta('Beskidzka Pętla', null, 'małopolskie', 68.0, 0)
                : self::blokKarta(self::PRZYKLAD['wyjazd'], '18–19 października', 'małopolskie', 124.0, 6),
        ];
    }

    // ---- BLOKI (gotowy HTML składany przez kod) -------------------------

    /** Treść wiadomości w ramce — ten sam wygląd co w dotychczasowym mailu. */
    public static function blokCytat(string $tresc): string
    {
        return '<p style="font-size:14px;line-height:1.6;background:#F7F5EF;border-left:3px solid #D14E1E;'
            . 'padding:10px 14px;border-radius:0 8px 8px 0;">'
            . nl2br(htmlspecialchars($tresc, ENT_QUOTES, 'UTF-8')) . '</p>';
    }

    /** Zdanie „dlaczego to" — składa je silnik dopasowań, nie panel. */
    public static function blokPowod(string $zdanie): string
    {
        return '<p style="font-size:15px;line-height:1.6;">'
            . htmlspecialchars($zdanie, ENT_QUOTES, 'UTF-8') . '</p>';
    }

    /**
     * KARTA WYJAZDU — fakty, na podstawie których dopasowanie w ogóle powstało.
     *
     * Dodana na zgłoszenie usera (2026-09-11): sam powód bez terminu, dystansu
     * i tego, ilu już jedzie, kazał człowiekowi klikać, żeby dowiedzieć się
     * rzeczy, które równie dobrze mogły stać w mailu. Puste pola po prostu
     * znikają z listy — wyjazd bez policzonego dystansu nie pokaże „0 km".
     */
    public static function blokKarta(
        string $tytul,
        ?string $termin = null,
        ?string $region = null,
        ?float $dystansKm = null,
        int $ilePotwierdzonych = 0
    ): string {
        $fakty = [];
        if ($termin !== null && $termin !== '')  { $fakty[] = $termin; }
        if ($region !== null && $region !== '')  { $fakty[] = $region; }
        if ($dystansKm !== null && $dystansKm > 0) {
            $fakty[] = rtrim(rtrim(number_format($dystansKm, 1, ',', ' '), '0'), ',') . ' km';
        }
        if ($ilePotwierdzonych > 0) {
            // Polska odmiana: 1 osoba jedzie / 2–4 osoby jadą / 5+ osób jedzie.
            $n = $ilePotwierdzonych;
            $reszta100 = $n % 100;
            $reszta10  = $n % 10;
            $fakty[] = $n === 1
                ? '1 osoba już jedzie'
                : (($reszta10 >= 2 && $reszta10 <= 4 && ($reszta100 < 12 || $reszta100 > 14))
                    ? $n . ' osoby już jadą'
                    : $n . ' osób już jedzie');
        }

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:18px 0;">'
            . '<tr><td style="background:#F7F5EF;border:1px solid #E4E2DA;border-radius:10px;padding:14px 16px;">'
            . '<div style="font-size:16px;font-weight:bold;color:#1A1A18;margin-bottom:' . ($fakty ? '6px' : '0') . ';">'
            . htmlspecialchars($tytul, ENT_QUOTES, 'UTF-8') . '</div>'
            . ($fakty
                ? '<div style="font-size:13.5px;color:#6B6A64;line-height:1.5;">'
                    . htmlspecialchars(implode(' · ', $fakty), ENT_QUOTES, 'UTF-8') . '</div>'
                : '')
            . '</td></tr></table>';
    }

    /** Obrazek mapy okolicy. Adres składa kod, więc nie da się go podmienić. */
    public static function blokMapa(string $url, string $opis = ''): string
    {
        return '<p style="margin:18px 0;"><img src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8')
            . '" alt="' . htmlspecialchars($opis, ENT_QUOTES, 'UTF-8')
            . '" width="440" style="max-width:100%;border-radius:10px;display:block;"></p>';
    }

    // ---- WEWNĘTRZNE ----------------------------------------------------

    /** Wspólny rdzeń `render()` — używany też przez podgląd tekstu roboczego. */
    private static function renderTekst(string $tekst, string $klucz, array $dane, array $bloki, bool $plain): string
    {
        foreach (self::znacznikiDla($klucz) as $znacznik) {
            $wartosc = (string) ($dane[$znacznik] ?? '');
            $tekst = str_replace('{' . $znacznik . '}',
                $plain ? $wartosc : htmlspecialchars($wartosc, ENT_QUOTES, 'UTF-8'), $tekst);
        }
        foreach (array_keys(self::BLOKI) as $blok) {
            $tekst = str_replace('{' . $blok . '}', $plain ? '' : (string) ($bloki[$blok] ?? ''), $tekst);
        }
        $tekst = preg_replace('/\{[a-z_]+\}/i', '', $tekst) ?? $tekst;
        $tekst = preg_replace('/\s*\(\s*\)/', '', $tekst) ?? $tekst;
        if ($plain) {
            return trim(preg_replace('/\s{2,}/', ' ', strip_tags($tekst)) ?? $tekst);
        }
        return trim(preg_replace('#<p>\s*</p>#', '', $tekst) ?? $tekst);
    }

    /** @return array<string,string> wyłącznie treści ZMIENIONE w panelu */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        self::$cache = [];
        try {
            $rows = Database::connection()
                ->query('SELECT text_key, text_value FROM notification_texts')
                ->fetchAll();
            foreach ($rows as $r) {
                if (self::domyslny((string) $r['text_key']) !== null) {
                    self::$cache[$r['text_key']] = (string) $r['text_value'];
                }
            }
        } catch (\Throwable $e) {
            // Brak tabeli (środowisko przed migr. 084) nie może zatrzymać
            // powiadomień — wracamy do treści z kodu.
            self::$cache = [];
        }
        return self::$cache;
    }

    public static function forget(): void
    {
        self::$cache = null;
    }
}
