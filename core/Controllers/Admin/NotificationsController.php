<?php
// core/Controllers/Admin/NotificationsController.php
// PANEL POWIADOMIEŃ (2026-09-11, migr. 083) — „jakie, w jakim czasie, jak
// często", zgłoszone przez usera jako brakujące miejsce do zarządzania.
//
// Wzorzec z `Admin\PointsController`: kontroler NIE zna wartości domyślnych
// ani zakresów — bierze je z `Models\NotificationSettings::PARAMETRY`, żeby
// dołożenie parametru w modelu pokazało go w panelu bez dotykania tego pliku
// i widoku.
//
// DRUGA POŁOWA TEGO EKRANU TO DZIENNIK, NIE USTAWIENIA. `notification_log`
// zbiera od Etapu 0 dane o każdej wysyłce (i o otwarciach), ale do dziś nikt
// ich nie pokazywał — a bez odpowiedzi „ile poszło i czy ktokolwiek to
// otwiera" strojenie budżetu w górnej połowie ekranu byłoby zgadywaniem.
namespace Controllers\Admin;

use Core\Csrf;
use Core\Database;
use Core\Auth;
use Models\NotificationGate;
use Models\NotificationSettings;
use Models\NotificationTexts;
use Utils\View;

class NotificationsController
{
    /** Ile dni wstecz pokazuje statystyka. Dwa okna: tydzień i miesiąc. */
    private const OKNA_STATYSTYK = [7, 30];

    public static function index(): void
    {
        // Parametry pogrupowane tak, jak mają stanąć na ekranie. Grupy są
        // opisane tutaj, bo to jest sprawa układu, a nie domeny.
        $grupy = [
            'budzet' => ['Jak często', 'Ile ZACHĘT wolno wysłać jednej osobie. Rzeczy transakcyjne — nowa wiadomość, ktoś dołączył do wyjazdu — nie podlegają tym limitom i nigdy nie będą: na nie ktoś czeka.'],
            'czas'   => ['W jakim czasie', 'Cisza nocna chroni przed budzeniem (dotyczy tylko pusha). Okno wysyłki mówi, o której porze zadanie w tle w ogóle zabiera się za zachęty.'],
            'kanal'  => ['Kanały', 'Główne wyłączniki dla całego serwisu. Zgody użytkowników zostają nietknięte — po ponownym włączeniu wszystko wraca samo.'],
            'typ'    => ['Rodzaje powiadomień', 'Wyłącznik per rodzaj, niezależny od zgód. Przydatny, gdy jeden typ zaczyna się sypać albo chcesz uruchomić go osobno.'],
        ];

        $pola = [];
        foreach (NotificationSettings::PARAMETRY as $klucz => $meta) {
            $nadpisania = NotificationSettings::all();
            $pola[$meta['group']][] = [
                'key'        => $klucz,
                'meta'       => $meta,
                'value'      => NotificationSettings::get($klucz),
                'default'    => (float) $meta['default'],
                'overridden' => array_key_exists($klucz, $nadpisania),
            ];
        }

        View::render('web', 'notifications-admin', [
            'title'      => 'Powiadomienia — ridemore.bike',
            'noindex'    => true,
            'grupy'      => $grupy,
            'pola'       => $pola,
            'lastChange' => NotificationSettings::lastChange(),
            // Stan „tu i teraz" — żeby admin nie musiał odtwarzać w głowie,
            // co znaczą ustawione godziny o tej porze, w której patrzy.
            'terazGodzina'  => (int) date('G'),
            'terazCisza'    => NotificationGate::ciszaNocna(),
            'terazWOknie'   => NotificationGate::poraNaZachety(),
            'statystyki'    => self::statystyki(),
            'ostatnie'      => self::ostatnie(),
            'etykietyTypow' => self::etykietyTypow(),
            'teksty'        => self::teksty(),
            'przyklad'      => NotificationTexts::PRZYKLAD,
            'opisyBlokow'   => NotificationTexts::BLOKI,
            // Bloki podglądu PER POWIADOMIENIE — skrypt na stronie odświeża
            // podgląd przy pisaniu i musi mieć ten sam zestaw co serwer.
            // Jeden wspólny zestaw sprawiał, że po pierwszym dotknięciu pola
            // każdy mail pokazywał przycisk „Otwórz →" w nieokreślone miejsce
            // (a wcześniej: „Zobacz na mapie" przy mailu o wiadomości).
            'blokiPodgladu' => self::blokiPodgladu(),
            // Znaczniki usunięte przy ostatnim zapisie — panel MUSI o tym
            // powiedzieć wprost, bo ciche zniknięcie `{nazwa}` z pola wygląda
            // jak zjedzony tekst, a nie jak zadziałanie reguły.
            'usunieteZnaczniki' => array_filter(explode(',', (string) ($_GET['usunieto'] ?? ''))),
        ]);
    }

    public static function save(): void
    {
        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            header('Location: ' . View::url('/admin/powiadomienia'));
            exit;
        }

        $userId = Auth::user()?->id;
        foreach (NotificationSettings::PARAMETRY as $klucz => $meta) {
            // Nazwa pola w formularzu: kropki w `name` zamieniają się w PHP na
            // podkreślenia, więc klucz jedzie w takiej postaci i tu wraca.
            $pole = str_replace('.', '_', $klucz);

            if ((int) $meta['min'] === 0 && (int) $meta['max'] === 1) {
                // Przełącznik: niezaznaczony checkbox NIE PRZYCHODZI w POST,
                // więc jego brak znaczy 0 — inaczej nie dałoby się niczego
                // wyłączyć (klasyczna pułapka checkboxów).
                //
                // ALE „brak w POST" znaczy też „formularz tego pola w ogóle
                // nie pokazał", a to jest zupełnie co innego. Złapane na żywo:
                // rodzaje bez nadawcy (`nearby_new`, `progress`) nie mają
                // swojego wiersza na ekranie, więc pierwsze kliknięcie
                // „Zapisz" WYŁĄCZAŁO JE PO CICHU — i nikt by się o tym nie
                // dowiedział aż do dnia, w którym dostaną nadawcę.
                // Stąd ukryty znacznik obecności: bez niego pole zostaje
                // nietknięte, zamiast dostać zero.
                if (!array_key_exists('obecne_' . $pole, $_POST)) {
                    continue;
                }
                $wartosc = empty($_POST[$pole]) ? 0.0 : 1.0;
            } else {
                $surowe = trim((string) ($_POST[$pole] ?? ''));
                // PUSTE POLE = PRZYWRÓĆ DOMYŚLNĄ, a nie „zero". Powrót do
                // wartości z kodu celowo nie wymaga jej znajomości (ta sama
                // zasada co w panelu punktacji).
                $wartosc = $surowe === '' ? null : (float) str_replace(',', '.', $surowe);
            }

            // WARTOŚĆ RÓWNA DOMYŚLNEJ NIE JEST NADPISANIEM.
            //
            // Bez tej linii jeden zapis formularza zamieniał wszystkie 17
            // parametrów w jawne nadpisania — także te, których admin nie
            // dotknął, bo pole liczbowe zawsze coś zawiera. Psuło to całą
            // własność, dla której ten wzorzec powstał: tabela przestawała
            // odpowiadać na pytanie „co jest ustawione świadomie", panel
            // pokazywał „zmienione" przy każdym polu, a przyszła zmiana
            // wartości domyślnej w kodzie nie dotarłaby już do nikogo, kto
            // raz kliknął „Zapisz".
            if ($wartosc !== null && abs($wartosc - (float) $meta['default']) < 0.0001) {
                $wartosc = null;
            }

            NotificationSettings::set($klucz, $wartosc, $userId);
        }

        // TREŚCI (migr. 084). `set()` oddaje listę znaczników, które odrzucił
        // jako niedozwolone dla danego pola — najważniejszy przypadek to
        // `{nazwa}` wpisana w wariant „skarb ukryty". Nazwy lecą do adresu,
        // żeby ekran mógł powiedzieć, co i dlaczego zniknęło.
        $odrzucone = [];
        foreach (self::wszystkieKluczeTekstow() as $klucz) {
            $pole = str_replace('.', '_', $klucz);
            if (!array_key_exists($pole, $_POST)) {
                continue;
            }
            foreach (NotificationTexts::set($klucz, (string) $_POST[$pole], $userId) as $znacznik) {
                $odrzucone[$znacznik] = true;
            }
        }

        $adres = '/admin/powiadomienia?zapisano=1';
        if ($odrzucone !== []) {
            $adres .= '&usunieto=' . rawurlencode(implode(',', array_keys($odrzucone)));
        }
        header('Location: ' . View::url($adres));
        exit;
    }

    /**
     * Bloki podglądu dla skryptu na stronie — klucz jest taki, jak PREFIKS
     * pola: sam kod powiadomienia albo `kod|wariant` tam, gdzie wariant zmienia
     * cel linku (skarb jawny/ukryty). Skrypt szuka najpierw wariantu, potem
     * samego kodu, więc dołożenie wariantu nigdzie indziej niczego nie psuje.
     */
    private static function blokiPodgladu(): array
    {
        $out = [];
        foreach (NotificationTexts::POWIADOMIENIA as $kod => $def) {
            $out[$kod] = NotificationTexts::blokiPrzykladowe($kod);
            foreach (array_keys($def['warianty'] ?? []) as $wariant) {
                $out[$kod . '|' . $wariant] = NotificationTexts::blokiPrzykladowe($kod, (string) $wariant);
            }
        }
        return $out;
    }

    /** Wszystkie klucze treści — jedno miejsce, z którego bierze je zapis. */
    private static function wszystkieKluczeTekstow(): array
    {
        $klucze = [];
        foreach (NotificationTexts::POWIADOMIENIA as $kod => $def) {
            foreach (array_keys($def['domyslne']) as $reszta) {
                $klucze[] = $kod . '.' . $reszta;
            }
        }
        return $klucze;
    }

    /**
     * Edytowalne treści, pogrupowane per powiadomienie, z PODGLĄDEM.
     *
     * Podgląd liczy się TĄ SAMĄ metodą, którą posługuje się wysyłka
     * (`NotificationTexts::render`), tylko na danych z `PRZYKLAD` — inaczej
     * ekran pokazywałby coś, czego nikt nigdy nie dostanie, a to gorsze niż
     * brak podglądu.
     */
    private static function teksty(): array
    {
        $zmienione = NotificationTexts::all();
        $out = [];

        foreach (NotificationTexts::POWIADOMIENIA as $kod => $def) {
            // Warianty tam, gdzie treść zależy od reguły, której nie wolno
            // obejść z formularza (poziom ujawnienia skarbu); gdzie ich nie ma,
            // jeden „wariant" bez nazwy trzyma strukturę jednakową dla widoku.
            $warianty = $def['warianty'] ?? [null => ['label' => null]];

            $pola = [];
            foreach ($warianty as $wariant => $wDef) {
                foreach ($def['kanaly'] as $kanal) {
                    foreach ($kanal === 'push' ? ['title', 'body'] : ['subject', 'body'] as $poleNazwa) {
                        $klucz = NotificationTexts::klucz($kod, $kanal, $poleNazwa, $wariant === '' ? null : $wariant);
                        if (NotificationTexts::domyslny($klucz) === null) {
                            continue;
                        }
                        $pola[] = [
                            'key'        => $klucz,
                            'kanal'      => $kanal,
                            'pole'       => $poleNazwa,
                            'wariant'    => $wariant,
                            'label'      => self::etykietaPola($kanal, $poleNazwa),
                            'value'      => NotificationTexts::get($klucz),
                            'default'    => (string) NotificationTexts::domyslny($klucz),
                            'podglad'    => NotificationTexts::podglad($klucz),
                            'znaczniki'  => NotificationTexts::znacznikiDla($klucz),
                            'overridden' => array_key_exists($klucz, $zmienione),
                        ];
                    }
                }
            }

            $out[$kod] = [
                'def'        => $def,
                'warianty'   => $warianty,
                'pola'       => $pola,
                'bloki'      => NotificationTexts::blokiDla($kod),
                'zmieniony'  => (bool) array_filter($pola, static fn(array $p): bool => $p['overridden']),
            ];
        }
        return $out;
    }

    private static function etykietaPola(string $kanal, string $pole): string
    {
        return match ("$kanal.$pole") {
            'push.title'   => 'Tytuł powiadomienia',
            'push.body'    => 'Treść powiadomienia',
            'mail.subject' => 'Temat maila',
            'mail.body'    => 'Treść maila (HTML)',
            default        => $pole,
        };
    }

    /**
     * Ile wysłano i ile otwarto, per typ i kanał, w dwóch oknach czasu.
     *
     * Liczone JEDNYM zapytaniem po `notification_log` — to jedyny rejestr
     * tego, co faktycznie wyszło (wpis powstaje w tej samej operacji, która
     * rozstrzyga zgodę, patrz `NotificationGate::claim`).
     */
    private static function statystyki(): array
    {
        $out = [];
        foreach (self::OKNA_STATYSTYK as $dni) {
            $out[$dni] = [];
            try {
                $stmt = Database::connection()->prepare('
                    SELECT type, channel,
                           COUNT(*) AS wyslano,
                           SUM(opened_at IS NOT NULL) AS otwarto,
                           COUNT(DISTINCT user_id) AS osob
                      FROM notification_log
                     WHERE sent_at >= NOW() - INTERVAL :dni DAY
                     GROUP BY type, channel
                     ORDER BY wyslano DESC
                ');
                $stmt->execute(['dni' => $dni]);
                $out[$dni] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            } catch (\Throwable $e) {
                // Brak tabeli (środowisko przed migr. 081) nie może wywrócić
                // panelu — ustawienia wyżej są ważniejsze niż statystyka.
                $out[$dni] = [];
            }
        }
        return $out;
    }

    /** Ostatnie wysyłki — surowy podgląd, żeby dało się sprawdzić „czy poszło". */
    private static function ostatnie(int $limit = 25): array
    {
        try {
            $stmt = Database::connection()->prepare('
                SELECT n.type, n.channel, n.dedupe_key, n.sent_at, n.opened_at,
                       u.name AS who, u.email AS who_email
                  FROM notification_log n
                  LEFT JOIN users u ON u.id = n.user_id
                 ORDER BY n.sent_at DESC
                 LIMIT :limit
            ');
            $stmt->bindValue('limit', $limit, \PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Ludzkie nazwy typów do tabel statystyk — brane z definicji parametrów,
     * żeby nie powstała druga lista nazw, która rozjedzie się z pierwszą.
     */
    private static function etykietyTypow(): array
    {
        $out = [];
        foreach (NotificationSettings::PARAMETRY as $klucz => $meta) {
            if (($meta['group'] ?? '') === 'typ') {
                $out[substr($klucz, strlen('typ.'))] = $meta['label'];
            }
        }
        return $out;
    }
}
