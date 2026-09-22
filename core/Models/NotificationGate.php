<?php

namespace Models;

use Core\Database;

/**
 * BRAMKA POWIADOMIEŃ — jedno miejsce, które rozstrzyga, czy wolno wysłać
 * (Etap 0 programu zachęt, 2026-09-11; migr. 081).
 *
 * ============================================================================
 * DLACZEGO BRAMKA, A NIE WARUNKI W MIEJSCACH WYSYŁKI
 * ============================================================================
 * Miejsc wysyłki jest dziś cztery i będzie ich więcej. Gdyby każde samo
 * sprawdzało zgodę, budżet i powtórki, to przy piątym typie powiadomienia
 * ktoś by o czymś zapomniał — a cena zapomnienia jest tu wyjątkowo wysoka:
 * człowiek nie wyłącza JEDNEGO natrętnego powiadomienia, tylko cały
 * przełącznik, i traci razem z nim wiadomości od organizatora wyjazdu.
 *
 * `Core\Push` zostaje głupi — on umie tylko wysłać. Decyzja „czy wolno"
 * mieszka tutaj, bo to decyzja o człowieku, nie o transporcie.
 *
 * ============================================================================
 * TRZY RZECZY, KTÓRYCH TA KLASA PILNUJE
 * ============================================================================
 * 1. ZGODA PER TYP. Do 2026-09-11 zgoda była zero-jedynkowa (aktywny wiersz
 *    w `push_devices` = wszystko). Trzy flagi w `user_preferences` dzielą ją
 *    na wiadomości / okolicę / postęp. BRAK WIERSZA ZNACZY „WŁĄCZONE" —
 *    nadrzędny przełącznik push jest już świadomym opt-inem, więc pytanie
 *    o zgodę drugi raz byłoby pytaniem o zgodę na zgodę.
 *
 * 2. BUDŻET I CISZA NOCNA — tylko dla ZACHĘT, nigdy dla transakcyjnych.
 *    Nikt nie wyłącza powiadomienia o wiadomości, na którą czeka, i nikt nie
 *    chce jej dostać o 6:59 zamiast o 22:01. Zachęta jest odwrotnie: to my
 *    zaczepiamy człowieka, więc to my mamy limit.
 *
 * 3. DEDUPLIKACJA PRZEZ BAZĘ, NIE PRZEZ PAMIĘĆ PISZĄCEGO. `UNIQUE (user_id,
 *    type, dedupe_key)` sprawia, że drugie wywołanie z tym samym kluczem
 *    fizycznie nie ma jak nic wysłać. To jest odpowiedź na zgłoszenie usera
 *    z 2026-09-11: „jeśli raz już poinformowałem, to nie ma sensu powtarzać".
 *
 * ============================================================================
 * ZASADA, KTÓRA WYSZŁA Z TAMTEJ ROZMOWY I JEST WAŻNIEJSZA NIŻ KOD NIŻEJ
 * ============================================================================
 * POWIADAMIAMY O ZDARZENIU, NIE O STANIE. „Zostały Ci 3 pola" to stan —
 * będzie prawdziwy jutro, za miesiąc i za rok, więc każdy system oparty na
 * stanie wymaga sztucznego cooldownu i i tak kiedyś zaspamuje. „Zbliżyłeś
 * się dzisiejszym przejazdem" albo „pojawiła się nowa trasa" to zdarzenia:
 * zdarzają się raz, więc deduplikacja wychodzi z natury wyzwalacza.
 * Klucz `dedupeKey` ma identyfikować RZECZ (`kr:42`, `msg:1987`), nie datę.
 * Typ, który ma prawo wracać cyklicznie, wpisuje okres do klucza
 * (`tyg:2026-W37`) — i wtedy unikalność dalej coś znaczy.
 */
final class NotificationGate
{
    // ---- TYPY ----------------------------------------------------------
    // Wartość idzie do kolumny `type` i nigdy się nie zmienia (dziennik jest
    // rejestrem tego, co FAKTYCZNIE wyszło). Ludzkie nazwy są w widoku.
    public const WIADOMOSC        = 'message';
    public const ZAPIS_NA_WYJAZD  = 'event_join';
    public const SKARB_W_OKOLICY  = 'treasure_nearby';
    /** Etap 1b — nowa trasa/wyjazd w regionie Twoich odkryć. */
    public const NOWOSC_W_OKOLICY = 'nearby_new';
    /** Etap 1a — postęp na trasie, wyzwalany zdarzeniem, nie stanem. */
    public const POSTEP           = 'progress';
    /**
     * Dopasowany wyjazd (`Models\PreferenceNotifier`) — istniał na długo przed
     * bramką i chodził własnym rytmem. Od Etapu 1c liczy się do tego samego
     * budżetu co reszta, bo inaczej limit „2 zachęty w tygodniu" był
     * deklaracją: bramka nie widziała tych maili, więc człowiek mógł dostać
     * trzy zaczepki, a licznik pokazywał dwie.
     */
    public const DOPASOWANIE      = 'match';
    /**
     * Przejazd dodany AUTOMATEM z licznika (migr. 088, `DeviceImport::fromWebhook`).
     * Człowiek sam włączył automat przy połączonym koncie i sam właśnie
     * wgrał trening do Polara/Wahoo — dlatego transakcyjne.
     */
    public const PRZEJAZD_Z_LICZNIKA = 'device_ride';

    /**
     * TRANSAKCYJNE: człowiek ich oczekuje, bo są odpowiedzią na coś, co sam
     * zrobił albo w czym bierze udział. Nie podlegają budżetowi ani ciszy.
     */
    private const TRANSAKCYJNE = [self::WIADOMOSC, self::ZAPIS_NA_WYJAZD, self::PRZEJAZD_Z_LICZNIKA];

    // ---- KANAŁY (Etap 1c, 2026-09-11; migr. 082) -----------------------
    // Push i mail idą RÓWNOLEGLE, nie jako fallback — decyzja usera. Kanał
    // siedzi w kluczu unikalności dziennika, więc to samo zdarzenie ma prawo
    // wyjść obydwoma rurami, ale każdą tylko raz.
    public const PUSH = 'push';
    public const MAIL = 'mail';
    public const KANALY = [self::PUSH, self::MAIL];

    /**
     * Który przełącznik w koncie rządzi którym typem — OSOBNO DLA KAŻDEGO
     * KANAŁU (decyzja usera 2026-09-11). Można chcieć nowości o okolicy
     * mailem i nie chcieć ich pushem: to nie jest ta sama decyzja, bo push
     * zaczepia w trakcie dnia, a mail czeka w skrzynce.
     */
    private const ZGODA = [
        self::PUSH => [
            self::WIADOMOSC        => 'push_messages',
            self::ZAPIS_NA_WYJAZD  => 'push_messages',
            self::SKARB_W_OKOLICY  => 'push_nearby',
            self::NOWOSC_W_OKOLICY => 'push_nearby',
            self::POSTEP           => 'push_progress',
            self::PRZEJAZD_Z_LICZNIKA => 'push_rides',
        ],
        self::MAIL => [
            self::WIADOMOSC        => 'mail_messages',
            self::ZAPIS_NA_WYJAZD  => 'mail_messages',
            self::SKARB_W_OKOLICY  => 'mail_nearby',
            self::NOWOSC_W_OKOLICY => 'mail_nearby',
            self::POSTEP           => 'mail_progress',
            self::PRZEJAZD_Z_LICZNIKA => 'mail_rides',
            // Dopasowania mają WŁASNĄ, starszą zgodę — zebraną świadomie,
            // domyślnie wyłączoną i opisaną w koncie od Etapu 3. Migracja jej
            // nie odwołuje i nie zastępuje: ludzie odpowiedzieli na to pytanie
            // raz i drugi raz ich o to nie pytamy.
            self::DOPASOWANIE      => 'notify_matches',
        ],
        // `DOPASOWANIE` świadomie NIE ISTNIEJE w kanale push: nie ma nadawcy,
        // który by je pushem wysyłał. Gdyby kiedyś powstał, próba wyjdzie
        // wyjątkiem na testach, zamiast po cichu podpiąć się pod cudzą zgodę.
    ];

    /**
     * Domyślny stan zgód, gdy user nigdy nie dotknął ustawień.
     *
     * Powiadomienia własne serwisu są tu domyślnie WŁĄCZONE (brak wiersza
     * znaczy „nie dotykałem ustawień", nie „odmawiam"), a chroni przed
     * natręctwem budżet i wypis jednym kliknięciem. `notify_matches` jest
     * wyjątkiem i zostaje przy swoim `false`, bo tak zostało zebrane.
     */
    public const ZGODY_DOMYSLNE = [
        'push_messages' => true, 'push_nearby' => true, 'push_progress' => true,
        'mail_messages' => true, 'mail_nearby' => true, 'mail_progress' => true,
        'notify_matches' => false,
        // migr. 088 — dochodzą wyłącznie do osób, które same włączyły automat.
        'push_rides' => true, 'mail_rides' => true,
    ];

    // ---- BUDŻET I CISZA — OD 2026-09-11 USTAWIALNE (migr. 083) ---------
    //
    // Stałe niżej ZOSTAJĄ i są nadal prawdą, tylko w innej roli: to są teraz
    // WARTOŚCI DOMYŚLNE, a obowiązującą podaje `Models\NotificationSettings`
    // (panel `/admin/powiadomienia`). Wzorzec z `ScoringSettings`: kod trzyma
    // domyślne i uzasadnienia, tabela wyłącznie to, co admin realnie zmienił.
    //
    // UZASADNIENIE TYCH AKURAT LICZB, którego nie da się zapisać w kolumnie:
    // przy >6 powiadomieniach tygodniowo ludzie są 3,4× bardziej skłonni
    // odinstalować apkę w ciągu 30 dni niż przy 1–2, a 46% wyłącza je przy
    // 2–5 tygodniowo, jeśli nie widzą w nich sensu. Bierzemy dolny koniec
    // tego przedziału, bo zachęta bez wartości kosztuje więcej niż zachęta
    // niewysłana. Kto podnosi te liczby w panelu, robi to wbrew tym danym —
    // i dlatego ta nota zostaje tutaj, a podpowiedź przy polu powtarza ją
    // adminowi w skróconej formie.
    //
    // Stałych używają też testy (`BUDZET_DOBA` w scenariuszu budżetu), więc
    // znikać im nie wolno — a poza tym są ostatnią linią obrony, gdy tabeli
    // jeszcze nie ma (środowisko przed migracją 083).
    public const BUDZET_TYDZIEN = 2;
    public const BUDZET_DOBA    = 1;

    // Cisza nocna. Godziny wg zegara serwera — serwis jest polski i serwer
    // stoi w tej samej strefie co jego użytkownicy; przy pierwszym koncie
    // spoza Polski to założenie trzeba będzie zdjąć (patrz nota w README).
    public const CISZA_OD = 22;
    public const CISZA_DO = 7;

    /**
     * „Czy wolno wysłać TO, TEMU, RAZ" — i jednocześnie zajęcie miejsca
     * w dzienniku. Zwraca id wpisu (do pomiaru otwarć) albo `null`, gdy nie
     * wolno: brak zgody, wyczerpany budżet, cisza nocna albo to samo już
     * poszło.
     *
     * ZAPIS JEST CZĘŚCIĄ DECYZJI, nie jej następstwem — `INSERT IGNORE`
     * rozstrzyga powtórkę atomowo, w bazie. Gdyby sprawdzać osobno
     * („czy było?" → „wyślij" → „zapisz"), dwa równoległe przebiegi crona
     * wysłałyby to samo dwa razy; przy powiadomieniach to nie jest teoria,
     * bo cron i żądanie HTTP potrafią trafić w tę samą sekundę.
     *
     * Wołający wysyła TYLKO gdy dostał id. Kolejność jest zamierzona: lepiej
     * mieć w dzienniku wpis o powiadomieniu, które nie doszło (sieć, wygasły
     * token), niż wysłać drugi raz to samo.
     */
    public static function claim(int $userId, string $type, string $dedupeKey = '', string $kanal = self::PUSH): ?int
    {
        if (!in_array($kanal, self::KANALY, true)) {
            throw new \InvalidArgumentException("Nieznany kanał powiadomienia: $kanal");
        }
        if (!isset(self::ZGODA[$kanal][$type])) {
            throw new \InvalidArgumentException("Nieznany typ powiadomienia: $type");
        }
        // WYŁĄCZNIKI Z PANELU (migr. 083) — sprawdzane PRZED zgodą i przed
        // dziennikiem, bo „tego w ogóle dziś nie wysyłamy" jest decyzją
        // serwisu, a nie odmową konkretnego człowieka. Gdyby wpis do dziennika
        // powstawał mimo wyłączenia, po ponownym włączeniu deduplikacja
        // uznałaby te powiadomienia za już wysłane i nikt by ich nie dostał.
        if (!self::wlaczony($type, $kanal)) {
            return null;
        }
        if (!self::zgoda($userId)[self::ZGODA[$kanal][$type]]) {
            return null;
        }
        if (!self::transakcyjny($type) && !self::wolnoZachecac($userId, $kanal)) {
            return null;
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare('
            INSERT IGNORE INTO notification_log (user_id, type, dedupe_key, channel)
            VALUES (:user_id, :type, :klucz, :kanal)
        ');
        $stmt->execute(['user_id' => $userId, 'type' => $type, 'klucz' => $dedupeKey, 'kanal' => $kanal]);

        return $stmt->rowCount() === 1 ? (int) $pdo->lastInsertId() : null;
    }

    /** Zgody usera z domyślnymi — brak wiersza znaczy „wszystko włączone". */
    public static function zgoda(int $userId): array
    {
        $stmt = Database::connection()->prepare('
            SELECT push_messages, push_nearby, push_progress,
                   mail_messages, mail_nearby, mail_progress, notify_matches,
                   push_rides, mail_rides
              FROM user_preferences WHERE user_id = :user_id
        ');
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return self::ZGODY_DOMYSLNE;
        }
        $zgody = [];
        foreach (array_keys(self::ZGODY_DOMYSLNE) as $flaga) {
            $zgody[$flaga] = (bool) $row[$flaga];
        }
        return $zgody;
    }

    /**
     * Zapis JEDNEJ flagi. Osobno od `UserPreference::save()` świadomie: tamta
     * metoda obsługuje formularz preferencji jazdy i przy okazji rusza
     * `declared_updated_at`, które napędza osłabienie rampy dopasowań.
     * Wyłączenie powiadomień nie jest deklaracją o tym, jak lubisz jeździć.
     */
    public static function ustawZgode(int $userId, string $flaga, bool $wlaczona): void
    {
        if (!in_array($flaga, array_keys(self::ZGODY_DOMYSLNE), true)) {
            throw new \InvalidArgumentException("Nieznana flaga zgody: $flaga");
        }
        // Wiersz może nie istnieć (user nigdy nie zapisał preferencji jazdy),
        // a wtedy pozostałe kolumny mają wziąć swoje wartości domyślne.
        Database::connection()->prepare("
            INSERT INTO user_preferences (user_id, `$flaga`)
            VALUES (:user_id, :wartosc)
            ON DUPLICATE KEY UPDATE `$flaga` = VALUES(`$flaga`)
        ")->execute(['user_id' => $userId, 'wartosc' => $wlaczona ? 1 : 0]);
    }

    /**
     * ADRES WYPISU DO STOPKI MAILA (Etap 1c) — bez logowania i bez wiersza
     * w bazie: podpis HMAC z `user_id` i flagi, liczony sekretem aplikacji.
     *
     * DLACZEGO NIE `Models\ActivationToken`, skoro istnieje. Dwa powody, oba
     * twarde. Po pierwsze `issueFor()` zaczyna od `UPDATE ... SET used_at =
     * NOW() WHERE user_id = :uid AND used_at IS NULL`, czyli wydanie tokenu
     * wypisu UNIEWAŻNIŁOBY czekający link aktywacyjny albo reset hasła tej
     * samej osoby — mail z zachętą kasowałby komuś możliwość wejścia na konto.
     * Po drugie tamte tokeny żyją godzinę, a link w mailu musi działać wtedy,
     * kiedy ktoś odkopie wiadomość sprzed pół roku.
     *
     * Podpis obejmuje TAKŻE flagę, więc link z maila o skarbach nie wyłączy
     * niczego innego niż maile o skarbach.
     */
    public static function adresWypisu(int $userId, string $flaga): string
    {
        return \Utils\View::absoluteUrl('/powiadomienia/wypisz')
            . '?u=' . $userId . '&f=' . rawurlencode($flaga) . '&k=' . self::podpisWypisu($userId, $flaga);
    }

    /**
     * Odwrotność `adresWypisu()`: `['userId' => int, 'flaga' => string]` albo
     * `null`, gdy podpis nie pasuje. `hash_equals` zamiast `===`, żeby czas
     * porównania nie zdradzał, ile znaków podpisu już się zgadza.
     */
    public static function rozwiazWypis(?string $u, ?string $flaga, ?string $podpis): ?array
    {
        $userId = (int) $u;
        $flaga  = (string) $flaga;
        if ($userId <= 0 || !isset(self::ZGODY_DOMYSLNE[$flaga]) || $podpis === null) {
            return null;
        }
        if (!hash_equals(self::podpisWypisu($userId, $flaga), $podpis)) {
            return null;
        }
        return ['userId' => $userId, 'flaga' => $flaga];
    }

    private static function podpisWypisu(int $userId, string $flaga): string
    {
        $sekret = (string) (APP_CONFIG['notifications']['unsubscribe_key'] ?? '');
        if ($sekret === '') {
            throw new \RuntimeException('Brak klucza podpisującego linki wypisu (notifications.unsubscribe_key).');
        }
        // Skrócony do 32 znaków — pełne 64 nie dokładają tu bezpieczeństwa,
        // a adres w stopce maila bywa łamany przez klienty pocztowe.
        return substr(hash_hmac('sha256', $userId . '|' . $flaga, $sekret), 0, 32);
    }

    /** Potwierdzenie otwarcia — zasila pomiar (Etap 2). Idempotentne. */
    public static function oznaczOtwarte(int $id, int $userId): void
    {
        Database::connection()->prepare('
            UPDATE notification_log SET opened_at = NOW()
             WHERE id = :id AND user_id = :user_id AND opened_at IS NULL
        ')->execute(['id' => $id, 'user_id' => $userId]);
    }

    /**
     * Czy serwis W OGÓLE wysyła dziś ten typ tym kanałem (panel, migr. 083).
     *
     * To jest wyłącznik po NASZEJ stronie, nie zgoda odbiorcy — dlatego gasi
     * powiadomienie, nie ruszając niczyich ustawień: po ponownym włączeniu
     * wszystko wraca samo. Brak tabeli albo nieznany parametr znaczy
     * „włączone", czyli zachowanie sprzed panelu.
     */
    public static function wlaczony(string $type, string $kanal): bool
    {
        try {
            return NotificationSettings::wlaczone('kanal.' . $kanal)
                && NotificationSettings::wlaczone('typ.' . $type);
        } catch (\InvalidArgumentException $e) {
            return true;
        }
    }

    /**
     * Czy TERAZ jest pora, żeby zadanie w tle zabrało się za zachęty
     * (parametry `okno.od`/`okno.do`, migr. 083).
     *
     * Osobna rzecz od ciszy nocnej i celowo: cisza mówi „nie budź człowieka",
     * a okno mówi „wysyłaj wtedy, kiedy to ma sens" — np. po pracy, gdy ludzie
     * planują jazdę. Domyślnie 0–24, czyli zachowanie sprzed panelu: decyduje
     * sam crontab.
     *
     * WARUNEK, KTÓREGO KOD NIE SPRAWDZI ZA ADMINA: okno ma sens tylko wtedy,
     * gdy `cron.php` odpala się częściej niż raz dziennie. Przy jednym nocnym
     * przebiegu okno 17–20 znaczy po prostu „nigdy". Podpowiedź w panelu mówi
     * o tym wprost.
     */
    public static function poraNaZachety(?int $godzina = null): bool
    {
        $godzina ??= (int) date('G');
        $od = NotificationSettings::getInt('okno.od');
        $do = NotificationSettings::getInt('okno.do');

        // Okno „przez północ" (np. 20–2) czytamy jako sumę dwóch kawałków —
        // inaczej każda taka para znaczyłaby „nigdy", cicho i bez ostrzeżenia.
        return $od <= $do
            ? ($godzina >= $od && $godzina < $do)
            : ($godzina >= $od || $godzina < $do);
    }

    /** Która flaga zgody rządzi tym typem w tym kanale (na użytek `Notifier`). */
    public static function flagaZgody(string $type, string $kanal): string
    {
        if (!isset(self::ZGODA[$kanal][$type])) {
            throw new \InvalidArgumentException("Nieznana para typ/kanał: $type / $kanal");
        }
        return self::ZGODA[$kanal][$type];
    }

    public static function transakcyjny(string $type): bool
    {
        return in_array($type, self::TRANSAKCYJNE, true);
    }

    /**
     * Czy o tej godzinie milczymy. Parametr istnieje WYŁĄCZNIE po to, żeby dało
     * się to sprawdzić testem — `date()` bez niego byłby nie do podstawienia,
     * a cisza nocna jest regułą, której nie chcę weryfikować „przez lekturę"
     * (jedno pomylone `>=` i powiadomienia budzą ludzi o 22:30 albo milczą
     * cały dzień).
     */
    public static function ciszaNocna(?int $godzina = null): bool
    {
        $godzina ??= (int) date('G');
        $od = NotificationSettings::getInt('cisza.od');
        $do = NotificationSettings::getInt('cisza.do');

        // `od >= do` to normalna cisza przez północ (22→7). Odwrotne ustawienie
        // (np. 7→22) też ma sens — „milcz w ciągu dnia" — więc obsługujemy oba,
        // zamiast zakładać, że admin pomylił pola.
        return $od >= $do
            ? ($godzina >= $od || $godzina < $do)
            : ($godzina >= $od && $godzina < $do);
    }

    /**
     * Budżet + cisza nocna. Liczone WYŁĄCZNIE po zachętach — transakcyjne
     * nie zajmują miejsca w limicie, bo inaczej rozmowa na czacie wyczerpałaby
     * tygodniowy budżet w kwadrans i zachęty nigdy by nie wyszły.
     *
     * BUDŻET JEST LICZONY OSOBNO DLA KAŻDEGO KANAŁU (decyzja usera, Etap 1c):
     * push i mail nie zabierają sobie miejsca. Uzasadnienie jest w kosztach,
     * nie w symetrii — push przerywa dzień, mail czeka w skrzynce, więc jedno
     * powiadomienie każdego rodzaju to nie to samo co dwa tego samego.
     *
     * CISZA NOCNA DOTYCZY WYŁĄCZNIE PUSHA. Mail nikogo nie budzi, a człowiek
     * zobaczy go rano niezależnie od tego, o której wyszedł. Przy okazji znika
     * pułapka z Etapu 0: nocny przebieg crona blokował CAŁY job z zachętami,
     * bo cisza obowiązywała wszystkich; teraz mailowa połowa wyjdzie zawsze.
     */
    private static function wolnoZachecac(int $userId, string $kanal): bool
    {
        if ($kanal === self::PUSH && self::ciszaNocna()) {
            return false;
        }
        // OKNO WYSYŁKI (migr. 083) obejmuje OBA kanały, w odróżnieniu od ciszy.
        // Cisza odpowiada na pytanie „czy wolno teraz budzić", okno na „czy to
        // jest pora, w której w ogóle zaczepiamy" — a tej drugiej odpowiedzi
        // mail nie ma powodu mieć innej niż push. Domyślnie 0–24, więc dla
        // nieruszanego panelu ta linia niczego nie zmienia.
        if (!self::poraNaZachety()) {
            return false;
        }

        $miejsca = implode(',', array_map(static fn($t) => "'" . $t . "'", self::TRANSAKCYJNE));
        $stmt = Database::connection()->prepare("
            SELECT
              SUM(sent_at >= NOW() - INTERVAL 7 DAY) AS tydzien,
              SUM(sent_at >= NOW() - INTERVAL 1 DAY) AS doba
            FROM notification_log
            WHERE user_id = :user_id
              AND channel = :kanal
              AND type NOT IN ($miejsca)
              AND sent_at >= NOW() - INTERVAL 7 DAY
        ");
        $stmt->execute(['user_id' => $userId, 'kanal' => $kanal]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

        // Limity z panelu (migr. 083); stałe klasy są ich wartościami domyślnymi.
        return (int) ($row['tydzien'] ?? 0) < NotificationSettings::getInt('budzet.' . $kanal . '.tydzien')
            && (int) ($row['doba'] ?? 0) < NotificationSettings::getInt('budzet.' . $kanal . '.doba');
    }
}
