<?php
// core/Models/MatchEngine.php
// Etap 2 (dopasowania) — krok 2: rdzeń oceniający, bez interfejsu. Punkt
// wejścia dla trzech konsumentów: podpowiedzi na żywo w formularzu (krok 3,
// forDraft), widget na stronie wydarzenia (krok 4, forEdition) i zadanie
// nocne konsolidujące (krok 5, korzysta z forEdition per turnus).
//
// Progi (ROUTE_OVERLAP_MERGE_THRESHOLD, PROXIMITY_*_KM, *_ALIGN_KM) są
// oszacowaniami, nie wynikiem pomiaru — docs/etap2 pkt 7.4 wprost zastrzega,
// że wymagają weryfikacji na realnych danych produkcyjnych, gdy będzie ich
// wystarczająco dużo. Nie traktować jako ostatecznych.
namespace Models;

use Core\Database;
use Core\Mailer;
use Utils\Gpx;
use Utils\View;

class MatchEngine
{
    private const PROXIMITY_FULL_KM = 5.0;
    // Odległość jest KOSZTEM CIĄGŁYM, nigdy bramką (przebudowa 2026-08-09 po
    // zgłoszeniu usera: "algorytmika nie lubi twardych limitów — a co jeśli
    // jestem z Podkarpacia i poluję na event nad morzem, to 600 km?").
    // Poprzednia wersja miała PROXIMITY_MAX_KM=60 i liniowy spadek ŚCINANY DO
    // ZERA powyżej tej wartości, a zero geoScore powodowało twarde odrzucenie
    // kandydata (patrz evaluate()) — czyli twardy limit 60 km istniał, tylko
    // ukryty w funkcji oceniającej. Teraz: gładki spadek exp(-(d/R)^2), który
    // NIGDY nie osiąga zera, więc świetne dopasowanie może przebić się z
    // dowolnej odległości; o "dotkliwości" kosztu decyduje R (punkt
    // odniesienia), nie stały próg.
    //
    // R zależy od SKALI WYJAZDU — jednodniowa ustawka 200 km dalej jest
    // niepraktyczna, tygodniowa wyprawa 500 km dalej jest normą. Jedna stała
    // dla obu była z definicji błędna.
    private const GEO_REFERENCE_ONE_DAY_KM = 60.0;
    private const GEO_REFERENCE_WEEKEND_KM = 200.0;
    private const GEO_REFERENCE_MULTIDAY_KM = 500.0;
    // Wyjazd w regionie ZADEKLAROWANYM ASPIRACYJNIE ("chcę tam pojechać") —
    // user sam o to poprosił, więc odległość przestaje być wadą: mnożymy R,
    // zamiast karać. To naprawia dokładnie przypadek z pytania usera.
    // UWAGA: to NIE to samo co wpuszczenie aspiracji do geoProfile w
    // applyProfileTerms() (tam zostają świadomie pominięte, docs/etap3 §5) —
    // aspiracje nie mają PODBIJAĆ codziennego dopasowania, mają tylko ZNOSIĆ
    // karę za dystans dla tego konkretnego kierunku. To dwie różne rzeczy.
    private const ASPIRATIONAL_RANGE_MULTIPLIER = 5.0;
    // Bliski termin zaostrza koszt PŁYNNIE (nie skokowo): wyjazd za 3 dni ma
    // wyższy koszt przełączenia planów niż ten za 3 miesiące. Skala R maleje
    // liniowo do TIME_TIGHTENING_FLOOR w oknie TIME_TIGHTENING_DAYS.
    private const TIME_TIGHTENING_DAYS = 14.0;
    private const TIME_TIGHTENING_FLOOR = 0.4;
    // "Prawo do milczenia" — JEDYNA bramka jakości, na wyniku CAŁKOWITYM (nie
    // na pojedynczej osi). Poniżej tej wartości nie pokazujemy nic: pusty
    // widget czyta się jako "jeszcze mało wyjazdów" (wina rynku), zły widget
    // jako "ten serwis mnie nie rozumie" (wina produktu) — a badania nad
    // algorithm aversion pokazują, że jeden widoczny absurd kosztuje więcej
    // zaufania, niż dziesięć trafień je buduje. Wartość skalibrowana na
    // realnych danych dev (patrz komentarz na górze pliku: progi to
    // oszacowania) — do weryfikacji, gdy będzie ruch produkcyjny.
    private const MIN_MATCH_SCORE = 1.5;
    // 1 - e^(-n/k): przy k=4 różnica 2 vs 10 osób jest duża (0.39 -> 0.92),
    // różnica 25 vs 50 pomijalna (~0.998 -> ~1.000) — dokładnie proporcja
    // wymagana w docs/etap2 (hierarchia wartości dopasowania).
    private const GROUP_SIZE_SATURATION_K = 4.0;
    private const ROUTE_OVERLAP_MERGE_THRESHOLD = 0.7;
    private const START_POINT_ALIGN_KM = 3.0;
    private const DISTANCE_ALIGN_KM = 15.0;
    private const WIDEN_TIME_WINDOW_DAYS = 14;
    // Drabina poszerzania ma teraz DWA szczeble, nie trzy — dawny poziom 2
    // ("zdejmij filtr geografii") zniknął, bo geografia w ogóle przestała
    // filtrować (jest kosztem, patrz GEO_REFERENCE_*). Zostały: 1 = zdejmij
    // filtr typu roweru, 2 = poszerz zakres dat.
    private const MAX_WIDEN_LEVEL = 2;
    private const DEFAULT_LIMIT = 5;
    // Wyjazd z grupą do tej wielkości (włącznie, licząc organizatora) jest
    // "mały" pod kątem zadania nocnego — hierarchia wartości dopasowania
    // (docs/etap2) mówi kierować NOWEGO użytkownika do większej grupy, a
    // mniejszą tylko o niej POWIADOMIĆ, nie scalać automatycznie.
    private const SMALL_GROUP_MAX = 3;
    // Różnica rozmiaru grupy poniżej tej wartości nie jest warta maila —
    // "obok jest trochę więcej osób" nie uzasadnia przerywania ciszy nocnej
    // powiadomieniem (patrz pkt 7.3 docs/etap2: zakres powiadomień do
    // ustalenia, to wartość robocza).
    private const MIN_GROUP_SIZE_ADVANTAGE = 3;

    // Etap 3 (preferencje) — wagi nowych składników oceny opartych o profil
    // (deklarowany+wynikający, zmieszane rampą — patrz loadProfile()). Skala
    // dobrana tak, by profil mógł PRZEWAŻYĆ słabe dopasowanie geograficzne
    // Etapu 2, ale nie zdominować silnego pokrycia trasy (routeOverlap*3.0
    // zostaje najsilniejszym pojedynczym składnikiem). Nieprzetestowane na
    // realnych danych — jak wszystkie wagi w tym pliku (patrz komentarz na
    // górze), tym bardziej te, bo Etap 3 nie miał JESZCZE żadnych danych
    // produkcyjnych w momencie budowy.
    // PELETON (2026-08-12) — ludzie, z którymi widz FAKTYCZNIE już jechał
    // (Models\RiderConnection). Waga 2.5 leży ŚWIADOMIE pomiędzy nakładaniem się
    // tras (3.0) a geografią (2.0): podobieństwo trasy mówi, czy wyjazd jest do
    // mnie podobny, peleton mówi, czy będę tam wśród swoich — a przy decyzji
    // o wspólnej jeździe to drugie waży więcej niż sam kształt trasy.
    //
    // Nasycenie przy 3 osobach: różnica między „nikt" a „jedna znajoma osoba"
    // jest ogromna, między „trzy" a „pięć" — żadna. Bez nasycenia duże wyjazdy
    // z przypadkowo licznym peletonem wypychałyby wszystko inne.
    private const PELOTON_WEIGHT = 2.5;
    private const PELOTON_SATURATION = 3.0;
    // Organizator z peletonu to osobny, mocny sygnał — „ktoś, z kim jeździłem,
    // TO PROWADZI". Dokładnie ten przypadek, o który pytał user: jeśli ktoś
    // z Twojego kręgu organizuje wyjazd, powinien Ci się pokazać.
    private const PELOTON_ORGANIZER_BONUS = 1.2;

    private const PROFILE_BIKE_WEIGHT = 1.0;
    private const PROFILE_PACE_WEIGHT = 0.8;
    private const PROFILE_DIFFICULTY_WEIGHT = 0.6;
    private const PROFILE_GEO_WEIGHT = 1.5;
    private const PROFILE_DISTANCE_WEIGHT = 0.8;
    // Wzmocnienie groupBonus przy group_size_pref='large' (docs/etap3 §3) —
    // "duża" nie zamienia w twardy wymóg, tylko podbija istniejącą premię.
    private const GROUP_SIZE_PREF_LARGE_BOOST = 1.3;
    // Próg jakości dzielący wynik na "z profilu" i "spoza profilu" przy
    // składaniu listy (docs/etap3 §5, "Udział wyników spoza profilu") —
    // oszacowanie, jak reszta stałych w tym pliku.
    private const PROFILE_QUALITY_THRESHOLD = 3.0;
    // Dolna granica pozycji spoza profilu: "co najmniej 2 na 10" (docs/etap3
    // §5) skalowane do faktycznego limitu wyników (zwykle 3, nie 10).
    private const OFF_PROFILE_FLOOR_RATIO = 0.2;
    // Ramp z docs/etap3 §5: udział warstwy wynikającej = min(0.5, signal_strength/20).
    private const RAMP_DIVISOR = 20.0;
    private const RAMP_CAP = 0.5;
    // Minimalny warunek wejścia warstwy wynikającej — profil zbudowany z
    // jednego zdarzenia to nie profil, tylko powtórzenie tego zdarzenia.
    private const MIN_DERIVED_EVENT_COUNT = 2;
    // Osłabienie rampy o połowę przez 30 dni po edycji deklaracji.
    private const POST_EDIT_DAMPENING_DAYS = 30;

    // --- Publiczne API -------------------------------------------------

    // Dopasowania dla JUŻ ZAPISANEGO turnusu — widget na stronie wydarzenia
    // i zadanie nocne. Zwraca null-owy wynik (pusta lista), jeśli turnus nie
    // istnieje — wywołujący decyduje, czy to błąd. $viewerId (Etap 3) — widz
    // strony, jeśli zalogowany; null (np. zadanie nocne, gość) pomija profil
    // całkowicie i daje dokładnie wynik z Etapu 2.
    public static function forEdition(int $editionId, int $limit = self::DEFAULT_LIMIT, ?int $viewerId = null): array
    {
        $subject = self::loadSubjectFromEdition($editionId);
        if ($subject === null) {
            return ['matches' => [], 'wideningLevel' => 0];
        }
        return self::findCandidates($subject, $limit, $viewerId);
    }

    // Dopasowania na podstawie danych WPISYWANYCH WŁAŚNIE w formularzu,
    // jeszcze niezapisanych. $criteria:
    //   startDate (Y-m-d, wymagane), endDate (Y-m-d|null), dateIsFlexible (bool),
    //   regionItemId (int|null, pojedynczy — draft ma tylko ręczną deklarację
    //   z formularza, jeszcze bez GPX do wyliczenia reszty), meetingPointLat/Lng (float|null),
    //   bikeTypeCodes (string[], pusta = wszystkie), paceGroupItemId (int|null),
    //   distanceKm (float|null), excludeEventId (int|null — przy edycji
    //   istniejącego wydarzenia, żeby nie dopasować samego siebie), userId
    //   (int|null, Etap 3 — profil osoby WYPEŁNIAJĄCEJ formularz, patrz
    //   docs/etap3 §1 pkt 2: "trafniejsze podpowiedzi nawet przy ubogim formularzu").
    public static function forDraft(array $criteria, int $limit = self::DEFAULT_LIMIT): array
    {
        return self::findCandidates(self::normalizeDraftSubject($criteria), $limit, $criteria['userId'] ?? null);
    }

    // Surowe liczby pod pasek postępu do progu wykonalności w panelu zapisu
    // (patrz szablony/wydarzenie.html .prog__bar) — wydzielone z dawnego
    // criticalMassMessage() (niżej, teraz cienki wrapper formatujący zdanie
    // z tych samych liczb), żeby front mógł narysować pasek zamiast gołego
    // tekstu. Null, jeśli próg nie ustawiony (events.min_participants puste).
    // Świadomie BEZ warunku "już osiągnięty" tutaj (w odróżnieniu od dawnego
    // criticalMassMessage()) — pasek ma sens pokazać też po przekroczeniu
    // progu (100%+), to criticalMassMessage() decyduje, czy w ogóle coś
    // pokazać, licząc missing z tego samego wyniku.
    public static function criticalMassProgress(int $eventId, int $editionId): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT min_participants FROM events WHERE id = :id');
        $stmt->execute(['id' => $eventId]);
        $min = $stmt->fetchColumn();
        if ($min === false || $min === null) {
            return null;
        }

        $confirmedStmt = $pdo->prepare("
            SELECT COUNT(*) FROM event_rsvps r
            JOIN dictionary_items rdi ON rdi.id = r.status_item_id AND rdi.code = 'potwierdzony'
            WHERE r.edition_id = :edition_id
        ");
        $confirmedStmt->execute(['edition_id' => $editionId]);
        // +1: organizator liczy się do grupy, nawet bez własnego wiersza w event_rsvps.
        $confirmed = (int) $confirmedStmt->fetchColumn() + 1;
        $min = (int) $min;

        return [
            'confirmed' => $confirmed,
            'min'       => $min,
            'missing'   => max(0, $min - $confirmed),
        ];
    }

    // Komunikat "brakuje jeszcze N osób do progu" dla WŁASNEGO wydarzenia
    // (nie kandydata) — wykorzystuje events.min_participants, pole, które już
    // istnieje w schemacie (docs/etap2 pkt 3, "Krytyczna masa"). Null, jeśli
    // próg nie ustawiony albo już osiągnięty.
    public static function criticalMassMessage(int $eventId, int $editionId): ?string
    {
        $progress = self::criticalMassProgress($eventId, $editionId);
        if ($progress === null || $progress['missing'] <= 0) {
            return null;
        }
        $missing = $progress['missing'];
        return sprintf(__('Brakuje jeszcze %d %s do minimalnej liczby uczestników.'), $missing, $missing === 1 ? 'osoby' : 'osób');
    }

    // Etykieta dla wyniku znalezionego dopiero po poszerzeniu kryteriów
    // (docs/etap2: "Nigdy pusty wynik... oznaczamy poziom poszerzenia").
    // Null przy poziomie 0 — front nie pokazuje wtedy żadnej adnotacji.
    public static function wideningLabel(int $level): ?string
    {
        return match ($level) {
            0 => null,
            1 => __('poszerzono o różne typy rowerów'),
            // Dawny poziom 2 ("poszerzono o odległe regiony") usunięty razem z
            // filtrem geograficznym — patrz MAX_WIDEN_LEVEL.
            2 => 'poszerzono zakres dat',
            default => __('znacząco poszerzone kryteria'),
        };
    }

    // Etap 2 (dopasowania), krok 5 — zadanie nocne. Wołane z cron.php (patrz
    // Models\Event::processCompletions() dla tego samego wzorca: idempotentna
    // statyczna metoda, zwraca listę wykonanych akcji do zalogowania). Dla
    // każdego OPUBLIKOWANEGO, nadchodzącego turnusu z małą grupą sprawdza
    // najlepsze dopasowanie i — jeśli jest wyraźnie większe — wysyła
    // organizatorowi maila. Kierunek zawsze mały -> duży (docs/etap2:
    // "kieruje nowego użytkownika do większego, a mniejszy powiadamia, że
    // obok jest większy"), nigdy odwrotnie. Dedupe przez event_match_notifications
    // (UNIQUE (event_id, matched_event_id)) — ta sama para nie dostaje maila
    // co noc, dopóki oba wydarzenia istnieją.
    public static function runNightlyConsolidation(): array
    {
        $pdo = Database::connection();
        $smallEditions = $pdo->query("
            SELECT ed.id AS edition_id, e.id AS event_id, e.organizer_id,
                   (SELECT COUNT(*) FROM event_rsvps r
                      JOIN dictionary_items rdi ON rdi.id = r.status_item_id AND rdi.code = 'potwierdzony'
                     WHERE r.edition_id = ed.id) AS confirmed_count
            FROM event_editions ed
            JOIN events e ON e.id = ed.event_id
            JOIN dictionary_items st ON st.id = e.status_item_id AND st.code = 'published'
            WHERE ed.is_cancelled = 0 AND ed.start_date >= CURDATE()
        ")->fetchAll();

        $notified = [];
        foreach ($smallEditions as $row) {
            $groupSize = (int) $row['confirmed_count'] + 1;
            if ($groupSize > self::SMALL_GROUP_MAX) {
                continue;
            }

            $result = self::forEdition((int) $row['edition_id'], 1);
            if (empty($result['matches'])) {
                continue;
            }
            $best = $result['matches'][0];
            if (!in_array($best['classification'], ['scalenie', 'dolaczenie'], true)) {
                continue;
            }
            if ($best['groupSize'] - $groupSize < self::MIN_GROUP_SIZE_ADVANTAGE) {
                continue;
            }

            $eventId = (int) $row['event_id'];
            $existsStmt = $pdo->prepare('SELECT 1 FROM event_match_notifications WHERE event_id = :a AND matched_event_id = :b');
            $existsStmt->execute(['a' => $eventId, 'b' => $best['eventId']]);
            if ($existsStmt->fetchColumn()) {
                continue;
            }

            $contactStmt = $pdo->prepare('SELECT name, email FROM users WHERE id = :id');
            $contactStmt->execute(['id' => $row['organizer_id']]);
            $organizer = $contactStmt->fetch();
            if (!$organizer || empty($organizer['email'])) {
                continue;
            }

            // sendTemplate() połyka błąd wysyłki (patrz Core\Mailer) — brak
            // maila nie cofa dedupe niżej, bo w praktyce oznaczałoby to samo
            // ponawianie próby co noc dla adresu, który i tak stale zawodzi.
            \Core\Lang::with(\Core\Lang::forEmail((string) ($organizer['email'])), static fn() => Mailer::sendTemplate(
                'match-suggestion',
                $organizer['email'],
                __('Znaleźliśmy większą grupę na podobny wyjazd'),
                [
                    'recipientName' => $organizer['name'],
                    'reason'        => $best['reason'],
                    'alignOn'       => $best['alignOn'],
                    'eventLink'     => View::absoluteUrl('/events/' . $best['slug']),
                ]
            ));

            $pdo->prepare('INSERT IGNORE INTO event_match_notifications (event_id, matched_event_id) VALUES (:a, :b)')
                ->execute(['a' => $eventId, 'b' => $best['eventId']]);

            $notified[] = ['eventId' => $eventId, 'matchedEventId' => $best['eventId']];
        }

        return $notified;
    }

    // --- Wyszukiwanie i poszerzanie kryteriów ---------------------------

    // Poziom 0: wszystkie filtry twarde z docs/etap2 aktywne. Poziom 1:
    // typ roweru przestaje wykluczać (i tak nigdy nie powinien, patrz
    // bikeTypeIntersection() — to dodatkowe zabezpieczenie przed przypadkiem,
    // w którym oba wydarzenia mają NIEPOKRYWAJĄCE się zestawy, co formalnie
    // nie powinno wykluczać wg zasad, ale i tak dajemy mu tu drugą szansę).
    // Poziom 2: geografia przestaje wykluczać. Poziom 3: dodatkowo okno
    // czasowe poszerzone o WIDEN_TIME_WINDOW_DAYS w obie strony. Zatrzymuje
    // się na pierwszym poziomie, który da choć jeden wynik.
    private static function findCandidates(array $subject, int $limit, ?int $viewerId = null): array
    {
        $pool = self::candidatePool($subject['excludeEventId'], $viewerId);
        $profile = self::loadProfile($viewerId);

        for ($level = 0; $level <= self::MAX_WIDEN_LEVEL; $level++) {
            $scored = [];
            foreach ($pool as $candidate) {
                $match = self::evaluate($subject, $candidate, $level, $profile);
                // "Prawo do milczenia" (2026-08-09) — JEDYNA bramka jakości, na
                // wyniku całkowitym. Wcześniej drabina zwracała cokolwiek
                // znalazła na pierwszym niepustym szczeblu, bez progu: pomiar na
                // danych dev pokazał, że 15 z 21 dopasowań pochodziło z
                // OSTATNIEGO szczebla (poszerzone daty), czyli widget był
                // wypełniany resztkami. Lepiej nie pokazać nic.
                if ($match !== null && $match['score'] >= self::MIN_MATCH_SCORE) {
                    $scored[] = $match;
                }
            }
            if (!empty($scored)) {
                return [
                    'matches'      => self::attachPelotonNames(
                        self::composeResults($scored, $profile, $limit),
                        $viewerId
                    ),
                    'wideningLevel' => $level,
                ];
            }
        }

        return ['matches' => [], 'wideningLevel' => self::MAX_WIDEN_LEVEL];
    }

    // Etap 3 (preferencje) §5 — składa ostateczną listę z wyników już
    // przefiltrowanych/ocenionych przez evaluate(): najlepsze dopasowania
    // POWYŻEJ progu jakości, resztę miejsc wypełnia pozycjami spoza profilu
    // (aspiracyjne dopasowania pierwsze, potem systemowa eksploracja),
    // zachowując dolną granicę OFF_PROFILE_FLOOR_RATIO nawet gdy dobrych
    // dopasowań jest pod dostatkiem. Bez profilu (gość, brak zebranych
    // danych) — dokładnie zachowanie Etapu 2, bez oznaczeń eksploracji.
    // Dokłada imiona osób z peletonu do JUŻ WYBRANYCH kart (jedno zapytanie na
    // całą listę). Celowo po composeResults(), nie w pętli oceniania: ranking
    // potrzebuje tylko liczby, imiona są potrzebne wyłącznie do podpisu na
    // tych kilku kartach, które użytkownik faktycznie zobaczy.
    private static function attachPelotonNames(array $matches, ?int $viewerId): array
    {
        if ($viewerId === null || !$matches) {
            return $matches;
        }
        $withPeloton = array_values(array_filter(
            $matches,
            static fn(array $m): bool => ($m['pelotonRiders'] ?? 0) > 0
        ));
        if (!$withPeloton) {
            return $matches;
        }

        $names = RiderConnection::namesOnEditions(array_column($withPeloton, 'editionId'), $viewerId);
        return array_map(static function (array $m) use ($names): array {
            $m['pelotonNames'] = $names[$m['editionId']] ?? [];
            return $m;
        }, $matches);
    }

    private static function composeResults(array $scored, ?array $profile, int $limit): array
    {
        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

        if ($profile === null) {
            return array_map(function ($m) {
                $m['isExploration'] = false;
                return $m;
            }, array_slice($scored, 0, $limit));
        }

        $inProfile = array_values(array_filter($scored, fn($m) => $m['score'] >= self::PROFILE_QUALITY_THRESHOLD));
        $rest = array_values(array_filter($scored, fn($m) => $m['score'] < self::PROFILE_QUALITY_THRESHOLD));

        $minOffProfile = max(1, (int) ceil($limit * self::OFF_PROFILE_FLOOR_RATIO));
        $maxInProfile = max(0, $limit - $minOffProfile);

        $chosenInProfile = array_slice($inProfile, 0, $maxInProfile);
        $remainingSlots = $limit - count($chosenInProfile);

        // Pula "spoza profilu": dobre dopasowania odcięte tylko przez dolną
        // granicę + wszystko poniżej progu. Kolejność źródeł eksploracji z
        // docs/etap3 §5: najpierw zgodne z deklaracją aspiracyjną (trafniejsze,
        // bo wskazane przez samego użytkownika), dopiero potem systemowe.
        $offProfilePool = array_merge(array_slice($inProfile, $maxInProfile), $rest);
        $aspirational = array_values(array_filter($offProfilePool, fn($m) => $m['isAspirational']));
        $systemic = array_values(array_filter($offProfilePool, fn($m) => !$m['isAspirational']));

        $chosenOffProfile = array_slice(array_merge($aspirational, $systemic), 0, max(0, $remainingSlots));
        foreach ($chosenOffProfile as &$m) {
            $m['isExploration'] = true;
        }
        unset($m);
        foreach ($chosenInProfile as &$m) {
            $m['isExploration'] = false;
        }
        unset($m);

        return array_slice(array_merge($chosenInProfile, $chosenOffProfile), 0, $limit);
    }

    private static function evaluate(array $subject, array $candidate, int $wideningLevel, ?array $profile = null): ?array
    {
        if ($subject['excludeEventId'] !== null && $candidate['eventId'] === $subject['excludeEventId']) {
            return null;
        }

        $subjectStart = $subject['startDate'];
        $subjectEnd = $subject['endDate'];
        if ($wideningLevel >= 2) {
            $subjectStart = date('Y-m-d', strtotime($subjectStart . ' -' . self::WIDEN_TIME_WINDOW_DAYS . ' days'));
            $subjectEnd = date('Y-m-d', strtotime($subjectEnd . ' +' . self::WIDEN_TIME_WINDOW_DAYS . ' days'));
        }
        if (!self::datesOverlap($subjectStart, $subjectEnd, $candidate['startDate'], $candidate['endDate'])) {
            return null;
        }

        $bikeOverlap = self::bikeTypeIntersection($subject['bikeTypeCodes'], $candidate['bikeTypeCodes']);
        if ($wideningLevel < 1 && !$bikeOverlap['compatible']) {
            return null;
        }

        $routeOverlap = self::routeOverlap($subject['eventId'], $candidate['eventId']);
        $groupBonus = 1 - exp(-$candidate['confirmedCount'] / self::GROUP_SIZE_SATURATION_K);

        // Liczone PRZED geografią — geoReferenceKm() potrzebuje isAspirational,
        // żeby wiedzieć, czy znieść karę za dystans dla tego kierunku.
        $p = self::applyProfileTerms($candidate, $profile, $groupBonus);
        $groupBonus = $p['groupBonus'];

        // Region NIE jest już skalarem (migr. 074) — oba wydarzenia mogą mieć
        // kilka; bierzemy NAJLEPSZE dopasowanie z całego iloczynu, nie tylko
        // pierwszej pary.
        $regionScore = null;
        if (!empty($subject['regionItemIds']) && !empty($candidate['regionItemIds'])) {
            foreach ($subject['regionItemIds'] as $subjectRegionId) {
                foreach ($candidate['regionItemIds'] as $candidateRegionId) {
                    $s = Dictionary::regionCompatibility($subjectRegionId, $candidateRegionId);
                    $regionScore = $regionScore === null ? $s : max($regionScore, $s);
                }
            }
        }
        $proximityKm = null;
        $proximityScore = null;
        if ($subject['meetingPointLat'] !== null && $subject['meetingPointLng'] !== null
            && $candidate['meetingPointLat'] !== null && $candidate['meetingPointLng'] !== null) {
            $proximityKm = Gpx::haversineKm(
                $subject['meetingPointLat'], $subject['meetingPointLng'],
                $candidate['meetingPointLat'], $candidate['meetingPointLng']
            );
            $proximityScore = self::proximityScore($proximityKm, self::geoReferenceKm($candidate, $p['isAspirational']));
        }
        $knownGeoScores = array_filter([$regionScore, $proximityScore], fn($v) => $v !== null);
        // Geografia NIE wyklucza już nikogo (dawny "return null" przy
        // geoScore<=0 usunięty 2026-08-09) — jest wyłącznie składnikiem wyniku.
        // Nieznana geografia daje 0.0, czyli po prostu BRAK premii: kandydat
        // bez danych geo musi nadrobić czymś innym, żeby przejść przez
        // MIN_MATCH_SCORE. Wcześniej działało to odwrotnie — brak danych był
        // przepustką, bo warunek odrzucenia wymagał ZNANEJ osi geo (patrz
        // pomiar: geoScore=0.00 przechodziło nawet na poziomie ścisłym).
        $geoScore = empty($knownGeoScores) ? 0.0 : max($knownGeoScores);

        // Wagi wyłącznie do RANKOWANIA kandydatów — nigdy nie pokazywane
        // użytkownikowi (docs/etap2: "Nie pokazujemy procentu dopasowania").
        $pelotonRiders = $candidate['pelotonRiders'] ?? 0;
        $score = $geoScore * 2.0
            + $routeOverlap * 3.0
            + $groupBonus * 1.5
            + self::pelotonScore($candidate)
            + ($bikeOverlap['explicit'] ? 0.5 : 0.0)
            + $p['bikeProfile'] * self::PROFILE_BIKE_WEIGHT
            + $p['paceProfile'] * self::PROFILE_PACE_WEIGHT
            + $p['difficultyProfile'] * self::PROFILE_DIFFICULTY_WEIGHT
            + $p['geoProfile'] * self::PROFILE_GEO_WEIGHT
            + $p['distanceProfile'] * self::PROFILE_DISTANCE_WEIGHT
            - ($wideningLevel * 0.01);

        $classification = self::classify($routeOverlap, $candidate['confirmedCount']);

        return [
            'eventId'        => $candidate['eventId'],
            'editionId'      => $candidate['editionId'],
            'title'          => $candidate['title'],
            'slug'           => $candidate['slug'],
            'classification' => $classification,
            // Etap 3 §7 — ujawnienie warstwy wynikającej W MIEJSCU UŻYCIA
            // (nigdy osobny ekran "co o Tobie wiemy"). Null, gdy profil nie
            // ma udziału wynikającego (derivedShare<=0) — deklaracje własne
            // usera nie wymagają "ujawniania", to nie profilowanie.
            'profileJustification' => self::profileJustification($p, $profile),
            // geoScore/proximityKm ujawnione TU (nie tylko użyte wewnętrznie
            // do liczenia $score) — bez tego reasonMessage() musiałby zgadywać
            // geografię z samej klasyfikacji rozmiaru grupy, co dawało fałszywie
            // pewne "podobny wyjazd w Twoim terminie" nawet dla kandydata na
            // drugim końcu Polski (daty ZAWSZE się pokrywają — to twardy
            // filtr, nie sygnał podobieństwa; user złapał to na żywo).
            'reason'         => self::reasonMessage($classification, $candidate, $routeOverlap, $geoScore, $proximityKm),
            'alignOn'        => self::alignmentAxis($subject, $candidate, $proximityKm),
            'alignDetail'    => self::alignmentDetail($subject, $candidate, $proximityKm),
            'geoScore'       => $geoScore,
            // Realna, zmierzona odległość w km albo null, gdy którakolwiek
            // strona nie ma współrzędnych — Resources\MatchCardResource używa
            // tego, żeby NIE pisać "Blisko Ciebie" bez faktycznego pomiaru.
            'proximityKm'    => $proximityKm,
            // +1: organizator kandydata, patrz classify(). Ekspozycja tej
            // wartości oszczędza zadaniu nocnemu (runNightlyConsolidation)
            // drugie zapytanie tylko po to, żeby porównać rozmiary grup.
            'groupSize'      => $candidate['confirmedCount'] + 1,
            // Peleton — ujawnione, żeby karta mogła powiedzieć WPROST, dlaczego
            // ten wyjazd trafił wysoko („Jedzie z nimi Michał W."). Bez tego
            // najmocniejszy powód pokazania byłby dla użytkownika niewidoczny.
            'pelotonRiders'    => $pelotonRiders,
            'pelotonOrganizer' => !empty($candidate['pelotonOrganizer']),
            'score'          => $score,
            'wideningLevel'  => $wideningLevel,
            // Etap 3 — patrz composeResults()/Models\RecommendationLog.
            'isAspirational' => $p['isAspirational'],
            'derivedShare'   => $profile['derivedShare'] ?? 0.0,
            'hasProfile'     => $profile !== null,
        ];
    }

    // Wydzielone z evaluate() tak, by ten sam kod obsługiwał zarówno
    // dopasowanie względem konkretnego podmiotu (evaluate(), Etap 2+3) jak i
    // czyste dopasowanie profil-kandydat bez podmiotu, potrzebne
    // powiadomieniom zwykłym (docs/etap3 §6) — tam nie ma "wydarzenia
    // punktu odniesienia", tylko sam profil użytkownika kontra nowo
    // opublikowane wydarzenie. Zwraca zera na każdej osi, gdy $profile===null
    // (Etap 2, bez zmian).
    // Wspólny składnik „peletonowy" dla OBU ścieżek rankingu: dopasowania
    // względem podmiotu (evaluate()) i czystego profil-kontra-kandydat
    // (profileMatchesForUser(), strona główna i powiadomienia). Wydzielone,
    // żeby wagi nie rozjechały się między tymi dwoma miejscami.
    //
    // Zero, gdy nie ma widza (gość, forDraft) — wtedy klucze w ogóle nie
    // wchodzą do kandydata i zachowanie jest identyczne jak przed 2026-08-12.
    private static function pelotonScore(array $candidate): float
    {
        $riders = $candidate['pelotonRiders'] ?? 0;
        $share = $riders > 0 ? min(1.0, $riders / self::PELOTON_SATURATION) : 0.0;

        return $share * self::PELOTON_WEIGHT
            + (!empty($candidate['pelotonOrganizer']) ? self::PELOTON_ORGANIZER_BONUS : 0.0);
    }

    private static function applyProfileTerms(array $candidate, ?array $profile, float $groupBonus): array
    {
        $result = [
            'bikeProfile'       => 0.0,
            'paceProfile'       => 0.0,
            'difficultyProfile' => 0.0,
            'geoProfile'        => 0.0,
            'distanceProfile'   => 0.0,
            'isAspirational'    => false,
            'groupBonus'        => $groupBonus,
        ];
        if ($profile === null) {
            return $result;
        }

        foreach ($candidate['bikeTypeItemIds'] as $itemId) {
            $result['bikeProfile'] = max($result['bikeProfile'], $profile['bikeWeights'][$itemId] ?? 0.0);
        }
        if ($candidate['paceGroupItemId'] !== null) {
            $result['paceProfile'] = $profile['paceWeights'][$candidate['paceGroupItemId']] ?? 0.0;
        }
        if ($candidate['difficultyItemId'] !== null) {
            $result['difficultyProfile'] = $profile['difficultyWeights'][$candidate['difficultyItemId']] ?? 0.0;
        }
        // Wyłącznie deklaracja operacyjna ("gdzie jeżdżę") + warstwa
        // wynikająca — deklaracje aspiracyjne ŚWIADOMIE pominięte tutaj
        // (docs/etap3 §5: "Deklaracje aspiracyjne nie wchodzą do tego
        // składnika"), patrz isAspirational niżej dla ich roli osobno.
        // Event może mieć kilka regionów (migr. 074) — bierzemy NAJLEPSZE
        // dopasowanie z całego iloczynu (region profilu × region kandydata),
        // nie tylko pierwszy z listy.
        if (!empty($candidate['regionItemIds'])) {
            foreach ($profile['regionWeights'] as $regionId => $w) {
                foreach ($candidate['regionItemIds'] as $candidateRegionId) {
                    $result['geoProfile'] = max($result['geoProfile'], $w * Dictionary::regionCompatibility($regionId, $candidateRegionId));
                }
            }
        }
        if ($candidate['distanceKm'] !== null) {
            $result['distanceProfile'] = self::distanceProfileScore($candidate['distanceKm'], $profile);
        }

        $result['groupBonus'] = match ($profile['groupSizePref']) {
            // "mała" ODWRACA kierunek premii — mniejsze wyjazdy trafiają
            // wyżej (docs/etap3 §3).
            'small' => 1.0 - $groupBonus,
            'large' => min(1.0, $groupBonus * self::GROUP_SIZE_PREF_LARGE_BOOST),
            default => $groupBonus,
        };

        $result['isAspirational'] = (!empty($candidate['regionItemIds']) && array_intersect($candidate['regionItemIds'], $profile['aspirationalRegionItemIds']))
            || ($candidate['eventTypeItemId'] !== null && in_array($candidate['eventTypeItemId'], $profile['aspirationalEventTypeItemIds'], true));

        return $result;
    }

    // Etap 3 (preferencje) §7 — "Przejrzystość profilu": jednozdaniowe
    // uzasadnienie odwołujące się do zachowań, POKAZANE PRZY REKOMENDACJI
    // (nigdy jako osobny ekran podsumowujący). Tylko gdy warstwa wynikająca
    // faktycznie ma udział (derivedShare>0) — deklaracje własne usera nie
    // wymagają "ujawniania", bo user sam je świadomie ustawił. Wybiera
    // NAJSILNIEJSZĄ pasującą oś, ten sam duch co alignmentAxis() (jedno
    // zdanie, nie lista wszystkich pasujących wymiarów).
    private static function profileJustification(array $p, ?array $profile): ?string
    {
        if ($profile === null || $profile['derivedShare'] <= 0.0) {
            return null;
        }
        $axisText = [
            'bikeProfile'       => __('Bo jeździsz głównie na tym typie roweru.'),
            'geoProfile'        => __('Bo często jeździsz w tej okolicy.'),
            'paceProfile'       => __('Bo pasuje do tempa, w jakim zwykle jeździsz.'),
            'difficultyProfile' => __('Bo pasuje do poziomu trudności, jaki zwykle wybierasz.'),
            'distanceProfile'   => __('Bo dystans pasuje do tego, na co zwykle się zapisujesz.'),
        ];
        $bestAxis = null;
        $bestValue = 0.3; // próg minimalny — nie uzasadniamy czymś ledwie widocznym
        foreach ($axisText as $key => $text) {
            if ($p[$key] > $bestValue) {
                $bestValue = $p[$key];
                $bestAxis = $text;
            }
        }
        return $bestAxis;
    }

    // Etap 3 (preferencje) §6 — dopasowanie profil-kontra-katalog BEZ
    // podmiotu odniesienia, na potrzeby powiadomień zwykłych: "sprawdza NOWO
    // OPUBLIKOWANE wydarzenia i zestawia je z profilami". Inaczej niż
    // forEdition()/forDraft(), tu nie ma czyjegoś konkretnego wydarzenia do
    // porównania geografii/dat — liczy się WYŁĄCZNIE zgodność z profilem
    // (te same składniki co profilowa część evaluate()) plus podstawowe
    // filtry (opublikowane, nie zapełnione, is_cancelled=0). Zwraca puste,
    // gdy user nie ma profilu (docs/etap3: bez warstwy user dostaje 0
    // biernych powiadomień, co jest poprawne — nie ma z czego dopasować).
    public static function profileMatchesForUser(int $userId, string $publishedSince, int $limit = 5): array
    {
        $profile = self::loadProfile($userId);
        if ($profile === null) {
            return [];
        }

        $pdo = Database::connection();
        $rows = $pdo->prepare("
            SELECT e.id AS event_id, ed.id AS edition_id, e.title, e.slug,
                   (SELECT GROUP_CONCAT(er.region_item_id) FROM event_regions er WHERE er.event_id = e.id) AS region_item_ids,
                   e.meeting_point_lat, e.meeting_point_lng,
                   e.pace_group_item_id, e.difficulty_item_id, e.event_type_item_id,
                   ed.start_date, ed.end_date,
                   COALESCE(tot.duration_days, 1) AS duration_days,
                   tot.total_distance_km,
                   (SELECT COUNT(*) FROM event_rsvps r
                      JOIN dictionary_items rdi ON rdi.id = r.status_item_id AND rdi.code = 'potwierdzony'
                     WHERE r.edition_id = ed.id) AS confirmed_count,
                   -- Peleton (2026-08-12) — ta sama para faktów co w
                   -- candidatePool(). Ścieżka profil-kontra-nowe-wydarzenia
                   -- (strona główna, powiadomienia) jest osobnym zapytaniem,
                   -- więc sygnał trzeba dołożyć tutaj TAKŻE — inaczej peleton
                   -- działałby na stronie wydarzenia, a na głównej nie.
                   -- UWAGA: to wnętrze stringu PHP w podwójnych cudzysłowach,
                   -- więc w komentarzach SQL nie może paść znak cudzysłowu —
                   -- zamknąłby string i wywalił parser (zdarzyło się tu 2x).
                   (SELECT COUNT(DISTINCT r3.user_id)
                      FROM event_rsvps r3
                      JOIN dictionary_items rdi3
                        ON rdi3.id = r3.status_item_id AND rdi3.code = 'potwierdzony'
                      JOIN rider_connections rc
                        ON (rc.user_a_id = r3.user_id AND rc.user_b_id = :pel_a)
                        OR (rc.user_b_id = r3.user_id AND rc.user_a_id = :pel_b)
                     WHERE r3.edition_id = ed.id) AS peloton_riders,
                   (SELECT COUNT(*) FROM rider_connections rc2
                     WHERE (rc2.user_a_id = e.organizer_id AND rc2.user_b_id = :pel_c)
                        OR (rc2.user_b_id = e.organizer_id AND rc2.user_a_id = :pel_d)
                   ) AS peloton_organizer
            FROM events e
            JOIN event_editions ed ON ed.event_id = e.id
            JOIN dictionary_items st ON st.id = e.status_item_id AND st.code = 'published'
            LEFT JOIN event_totals tot ON tot.event_id = e.id
            WHERE ed.is_cancelled = 0 AND ed.start_date >= CURDATE()
              AND e.published_at >= :since
              AND e.organizer_id != :user_id
              AND NOT EXISTS (SELECT 1 FROM event_rsvps r2 WHERE r2.edition_id = ed.id AND r2.user_id = :user_id2)
              AND NOT EXISTS (SELECT 1 FROM recommendation_dismissals rd WHERE rd.user_id = :user_id3 AND rd.event_id = e.id)
        ");
        $rows->execute([
            'since' => $publishedSince,
            'user_id' => $userId, 'user_id2' => $userId, 'user_id3' => $userId,
            'pel_a' => $userId, 'pel_b' => $userId, 'pel_c' => $userId, 'pel_d' => $userId,
        ]);
        $result = $rows->fetchAll();
        if (empty($result)) {
            return [];
        }

        $bikeTypesByEvent = self::bikeTypesForEvents(array_unique(array_column($result, 'event_id')));
        $scored = [];
        foreach ($result as $row) {
            $candidate = self::mapRow($row, $bikeTypesByEvent);
            $groupBonus = 1 - exp(-$candidate['confirmedCount'] / self::GROUP_SIZE_SATURATION_K);
            $p = self::applyProfileTerms($candidate, $profile, $groupBonus);
            $score = $p['bikeProfile'] * self::PROFILE_BIKE_WEIGHT
                + $p['paceProfile'] * self::PROFILE_PACE_WEIGHT
                + $p['difficultyProfile'] * self::PROFILE_DIFFICULTY_WEIGHT
                + $p['geoProfile'] * self::PROFILE_GEO_WEIGHT
                + $p['distanceProfile'] * self::PROFILE_DISTANCE_WEIGHT
                + $p['groupBonus'] * 1.5
                + self::pelotonScore($candidate);
            if ($score < self::PROFILE_QUALITY_THRESHOLD) {
                continue;
            }
            // Bez podmiotu odniesienia nie ma z czym porównać pokrycia trasy,
            // więc "scalenie" nie ma tu sensu — klasyfikacja ogranicza się do
            // dołączenie/para wg samego rozmiaru grupy kandydata (patrz classify()).
            $groupSize = $candidate['confirmedCount'] + 1;
            $scored[] = [
                'eventId'        => $candidate['eventId'],
                'editionId'      => $candidate['editionId'],
                'title'          => $candidate['title'],
                'slug'           => $candidate['slug'],
                'score'          => $score,
                'classification' => $groupSize >= 2 ? 'dolaczenie' : 'para',
                'groupSize'      => $groupSize,
                'alignOn'        => null,
                'alignDetail'    => null,
                // Brak podmiotu -> brak współrzędnych do policzenia realnej
                // odległości; geoProfile (zgodność regionu z profilem) jest
                // tu najlepszym dostępnym przybliżeniem "jak blisko" dla
                // uczciwego eyebrow w Resources\MatchCardResource.
                'geoScore'       => $p['geoProfile'],
                'reason'         => self::passiveReasonMessage($candidate, $profile),
                'profileJustification' => self::profileJustification($p, $profile),
                'isAspirational' => $p['isAspirational'],
                'isExploration'  => false,
                'derivedShare'   => $profile['derivedShare'] ?? 0.0,
                'pelotonRiders'    => $candidate['pelotonRiders'] ?? 0,
                'pelotonOrganizer' => !empty($candidate['pelotonOrganizer']),
                // FAKTY O WYJEŹDZIE pod kartę w mailu (2026-09-11, zgłoszenie
                // usera: „powinno być więcej informacji o tym evencie").
                // Liczone są i tak — do rankingu — więc dołożenie ich do wyniku
                // nie kosztuje ani jednego zapytania więcej.
                'startDate'      => $candidate['startDate'] ?? null,
                'endDate'        => $candidate['endDate'] ?? null,
                'distanceKm'     => $candidate['distanceKm'] ?? null,
                'confirmedCount' => $candidate['confirmedCount'] ?? 0,
                'regionItemIds'  => $candidate['regionItemIds'] ?? [],
            ];
        }
        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
        // Imiona dokładamy dopiero do wyciętej listy — patrz attachPelotonNames().
        return self::attachPelotonNames(array_slice($scored, 0, $limit), $userId);
    }

    // Uzasadnienie dla powiadomienia BIERNEGO (bez konkretnego wydarzenia
    // odniesienia) — odwołuje się do NAJSILNIEJSZEGO pasującego wymiaru
    // profilu, w tej samej duchu co reasonMessage() (sugestia, nigdy fakt).
    private static function passiveReasonMessage(array $candidate, array $profile): string
    {
        $best = null;
        $bestWeight = 0.0;
        foreach ($candidate['bikeTypeItemIds'] as $itemId) {
            $w = $profile['bikeWeights'][$itemId] ?? 0.0;
            if ($w > $bestWeight) {
                $bestWeight = $w;
                $best = __('bo jeździsz na podobnym sprzęcie');
            }
        }
        if (!empty($candidate['regionItemIds'])) {
            foreach ($profile['regionWeights'] as $regionId => $w) {
                foreach ($candidate['regionItemIds'] as $candidateRegionId) {
                    $s = $w * Dictionary::regionCompatibility($regionId, $candidateRegionId);
                    if ($s > $bestWeight) {
                        $bestWeight = $s;
                        $best = __('bo często jeździsz w tej okolicy');
                    }
                }
            }
        }
        if ($candidate['paceGroupItemId'] !== null) {
            $w = $profile['paceWeights'][$candidate['paceGroupItemId']] ?? 0.0;
            if ($w > $bestWeight) {
                $bestWeight = $w;
                $best = __('bo pasuje do tempa, w jakim zwykle jeździsz');
            }
        }
        return 'Pojawił się nowy wyjazd, który może Cię zainteresować — ' . ($best ?? __('wygląda podobnie do tego, na co zwykle się zapisujesz')) . '.';
    }

    // --- Klasyfikacja, powód, oś do uzgodnienia -------------------------

    private static function classify(float $routeOverlap, int $candidateConfirmedCount): string
    {
        if ($routeOverlap >= self::ROUTE_OVERLAP_MERGE_THRESHOLD) {
            return 'scalenie';
        }
        // +1: organizator kandydata liczy się do grupy, nawet jeśli sam nie
        // ma wiersza w event_rsvps (nie zapisuje się na własne wydarzenie).
        $groupSize = $candidateConfirmedCount + 1;
        return $groupSize >= 2 ? 'dolaczenie' : 'para';
    }

    // Jeden słownik komunikatów po stronie serwera (docs/etap2: "Zawsze
    // podajemy powód... Front go tylko wyświetla"). Świadomie zawsze w formie
    // sugestii ("znaleźliśmy"), nigdy stwierdzenia faktu ("jedziecie razem"
    // jest zabronione) — jedno błędne, niemożliwe do bezbolesnego odrzucenia
    // sparowanie zniechęca bardziej niż brak dopasowania.
    // Geografia ZAWSZE ujawniona wprost i szczerze — nigdy tylko "podobny
    // termin" (daty pokrywają się z definicji, to twardy filtr z evaluate(),
    // nie dowód podobieństwa). Bez tego "dołączenie"/"para" brzmiało tak samo
    // pewnie siebie dla kandydata 5 km dalej i 300 km dalej, co w praktyce
    // okazało się mylące (zgłoszenie użytkownika: sugestia z Podkarpacia na
    // Tatry podpisana "podobny wyjazd w Twoim terminie").
    private static function reasonMessage(string $classification, array $candidate, float $routeOverlap, float $geoScore, ?float $proximityKm): string
    {
        if ($classification === 'scalenie') {
            return sprintf(__('Ktoś planuje bardzo podobną trasę w zbliżonym terminie — trasy pokrywają się w %d%%.'), (int) round($routeOverlap * 100));
        }

        $geoPhrase = self::geoPhrase($geoScore, $proximityKm);
        $groupSize = $candidate['confirmedCount'] + 1;

        if ($classification === 'dolaczenie') {
            $who = $groupSize >= 6
                ? sprintf(__('Znaleźliśmy grupę %d osób'), $groupSize)
                : sprintf(__('Znaleźliśmy %d osoby'), $groupSize);
            return $geoPhrase !== null
                ? sprintf(__('%s wybierających się w podobnym terminie, %s.'), $who, $geoPhrase)
                : sprintf(__('%s wybierających się w podobnym terminie.'), $who);
        }

        return $geoPhrase !== null
            ? sprintf(__('Znaleźliśmy kogoś podobnego, kto szuka towarzystwa na podobną trasę, %s.'), $geoPhrase)
            : __('Znaleźliśmy kogoś podobnego, kto szuka towarzystwa na podobną trasę.');
    }

    // Fraza geograficzna PRAWDOMÓWNA: konkretna odległość, gdy ją znamy
    // (nie "blisko"/"daleko" ocenne, tylko liczba), region tylko jako
    // zapasowe źródło, gdy nie ma współrzędnych punktu zbiórki. Null = brak
    // danych geograficznych po którejś stronie — wtedy MILCZYMY o geografii
    // zamiast zmyślać (zasada "brak danych nie wyklucza, tylko obniża wkład").
    private static function geoPhrase(float $geoScore, ?float $proximityKm): ?string
    {
        if ($proximityKm !== null) {
            if ($proximityKm <= self::PROXIMITY_FULL_KM) {
                return __('tuż przy Twoim punkcie startu');
            }
            return round($proximityKm) . ' km od Twojego punktu startu';
        }
        if ($geoScore >= 1.0) {
            return __('w tym samym regionie');
        }
        if ($geoScore > 0.0) {
            return __('w pobliskim regionie');
        }
        return null;
    }

    // Priorytet z docs/etap2: punkt startu, termin, tempo, dystans. Zwraca
    // PIERWSZĄ oś z realną rozbieżnością — nigdy listę różnic (zasada "zawsze
    // wskazujemy jedną rzecz do uzgodnienia"). Null = nic istotnego się nie
    // różni.
    private static function alignmentAxis(array $subject, array $candidate, ?float $proximityKm): ?string
    {
        if ($proximityKm !== null && $proximityKm > self::START_POINT_ALIGN_KM) {
            return 'punkt startu';
        }
        if ($subject['startDate'] !== $candidate['startDate'] || $subject['endDate'] !== $candidate['endDate']) {
            return 'termin';
        }
        if ($subject['paceGroupItemId'] !== null && $candidate['paceGroupItemId'] !== null
            && $subject['paceGroupItemId'] !== $candidate['paceGroupItemId']) {
            return 'tempo';
        }
        if ($subject['distanceKm'] !== null && $candidate['distanceKm'] !== null
            && abs($subject['distanceKm'] - $candidate['distanceKm']) > self::DISTANCE_ALIGN_KM) {
            return 'dystans';
        }
        return null;
    }

    // Samodzielna, czytelna fraza dla pigułki na karcie — BEZ przedrostka
    // "Do uzgodnienia: oś" (użytkownik: sama nazwa osi bez liczby czytała
    // się jak pretensja bez treści, a przedrostek stał się zbędny, gdy fraza
    // sama tłumaczy o co chodzi). Zawsze zwraca kompletne zdanie/frazę, gdy
    // alignmentAxis() wskazał jakąkolwiek oś — nigdy gołą nazwę.
    private static function alignmentDetail(array $subject, array $candidate, ?float $proximityKm): ?string
    {
        $axis = self::alignmentAxis($subject, $candidate, $proximityKm);
        return match ($axis) {
            'punkt startu' => $proximityKm !== null ? round($proximityKm) . ' km od Twojego startu' : 'inny punkt startu',
            'termin' => self::dayDiffLabel($subject['startDate'], $subject['endDate'], $candidate['startDate'], $candidate['endDate']),
            'tempo' => 'inne tempo grupy',
            'dystans' => ($subject['distanceKm'] !== null && $candidate['distanceKm'] !== null)
                ? round(abs($subject['distanceKm'] - $candidate['distanceKm'])) . ' km różnicy w dystansie'
                : 'inny dystans',
            default => null,
        };
    }

    // Różnica w dniach liczona z tej pary dat (start LUB koniec), która
    // faktycznie się różni — sam start mógłby wyjść 0 dni różnicy, mimo że
    // to koniec turnusu jest inny (np. ten sam dzień startu, różna długość).
    private static function dayDiffLabel(string $startA, string $endA, string $startB, string $endB): string
    {
        $days = max(
            (int) round(abs(strtotime($startA) - strtotime($startB)) / 86400),
            (int) round(abs(strtotime($endA) - strtotime($endB)) / 86400)
        );
        if ($days === 0) {
            return __('inny dokładny termin');
        }
        return ($days === 1 ? __('1 dzień różnicy') : $days . ' dni różnicy') . ' w terminie';
    }

    // --- Osie oceny ------------------------------------------------------

    private static function datesOverlap(string $startA, string $endA, string $startB, string $endB): bool
    {
        return $startA <= $endB && $startB <= $endA;
    }

    // Gładki koszt odległości znormalizowany punktem odniesienia $referenceKm
    // (patrz geoReferenceKm() niżej). Nigdy nie zwraca dokładnego zera — to
    // celowe: żadna odległość nie ma prawa SAMA wykluczyć kandydata, o
    // odrzuceniu decyduje dopiero wynik całkowity (MIN_MATCH_SCORE).
    // Kształt: 1.0 do PROXIMITY_FULL_KM, dalej exp(-(nadwyżka/R)^2) — przy
    // nadwyżce równej R spada do ~0.37, przy 2R do ~0.02.
    private static function proximityScore(float $km, float $referenceKm): float
    {
        if ($km <= self::PROXIMITY_FULL_KM) {
            return 1.0;
        }
        $excess = $km - self::PROXIMITY_FULL_KM;
        $r = max(1.0, $referenceKm);
        return exp(-pow($excess / $r, 2));
    }

    // Punkt odniesienia dla kosztu odległości — ILE KILOMETRÓW TEN WYJAZD
    // UZASADNIA. Trzy czynniki, wszystkie ciągłe/mnożone, żaden nie jest bramką:
    //   1) skala wyjazdu (jednodniowy / weekend / wielodniówka) — na tydzień w
    //      góry jedzie się z drugiego końca kraju, na poranną ustawkę nie;
    //   2) deklaracja aspiracyjna — gdy user sam wskazał ten region jako
    //      "chcę tam pojechać", dystans przestaje być wadą (mnożnik);
    //   3) bliskość terminu — im mniej czasu na przestawienie planów, tym
    //      krótszy realny zasięg (płynnie, do TIME_TIGHTENING_FLOOR).
    private static function geoReferenceKm(array $candidate, bool $isAspirational): float
    {
        $durationDays = max(1, (int) round(
            (strtotime($candidate['endDate']) - strtotime($candidate['startDate'])) / 86400
        ) + 1);

        $reference = match (true) {
            $durationDays <= 1 => self::GEO_REFERENCE_ONE_DAY_KM,
            $durationDays <= 3 => self::GEO_REFERENCE_WEEKEND_KM,
            default            => self::GEO_REFERENCE_MULTIDAY_KM,
        };

        if ($isAspirational) {
            $reference *= self::ASPIRATIONAL_RANGE_MULTIPLIER;
        }

        $daysUntilStart = (strtotime($candidate['startDate']) - strtotime(date('Y-m-d'))) / 86400;
        if ($daysUntilStart < self::TIME_TIGHTENING_DAYS) {
            $ratio = max(0.0, $daysUntilStart) / self::TIME_TIGHTENING_DAYS;
            $reference *= max(self::TIME_TIGHTENING_FLOOR, $ratio);
        }

        return $reference;
    }

    // Typ roweru NIGDY nie wyklucza sam z siebie (docs/etap2) — pusty zbiór
    // po którejkolwiek stronie oznacza "wszystkie dopuszczone". 'explicit'
    // odróżnia realne dopasowanie zbiorów (premiuje wynik) od zgodności
    // wynikającej tylko z tego, że ktoś nie ustawił żadnych ograniczeń.
    private static function bikeTypeIntersection(array $subjectCodes, array $candidateCodes): array
    {
        if (empty($subjectCodes) || empty($candidateCodes)) {
            return ['compatible' => true, 'explicit' => false];
        }
        $shared = array_intersect($subjectCodes, $candidateCodes);
        return ['compatible' => !empty($shared), 'explicit' => !empty($shared)];
    }

    // Pokrycie liczone względem KRÓTSZEJ trasy (docs/etap2) — miara oparta na
    // sumie/unii komórek przegapiłaby oczywiste scalenie krótszej trasy w
    // całości zawartej w dłuższej. Bierze najlepiej pasującą parę etapów
    // (dwa wydarzenia wielodniowe porównywane dzień po dniu, nie jako
    // zsumowana całość) — uproszczenie względem pełnego dopasowania
    // sekwencji dni, wystarczające przy typowej skali tego wydarzenia.
    private static function routeOverlap(?int $eventIdA, int $eventIdB): float
    {
        if ($eventIdA === null || $eventIdA === $eventIdB) {
            return 0.0;
        }

        $pdo = Database::connection();
        $stageIdsA = self::stageIdsForEvent($pdo, $eventIdA);
        $stageIdsB = self::stageIdsForEvent($pdo, $eventIdB);
        if (empty($stageIdsA) || empty($stageIdsB)) {
            return 0.0;
        }

        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM event_stage_cells WHERE event_stage_id = ?');
        $sharedStmt = $pdo->prepare('
            SELECT COUNT(*) FROM event_stage_cells a
            JOIN event_stage_cells b ON b.cell_x = a.cell_x AND b.cell_y = a.cell_y AND b.event_stage_id = ?
            WHERE a.event_stage_id = ?
        ');

        $best = 0.0;
        foreach ($stageIdsA as $stageIdA) {
            $countStmt->execute([$stageIdA]);
            $countA = (int) $countStmt->fetchColumn();
            if ($countA === 0) {
                continue;
            }
            foreach ($stageIdsB as $stageIdB) {
                $countStmt->execute([$stageIdB]);
                $countB = (int) $countStmt->fetchColumn();
                if ($countB === 0) {
                    continue;
                }
                $sharedStmt->execute([$stageIdB, $stageIdA]);
                $shared = (int) $sharedStmt->fetchColumn();
                $best = max($best, $shared / min($countA, $countB));
            }
        }
        return $best;
    }

    private static function stageIdsForEvent(\PDO $pdo, int $eventId): array
    {
        $stmt = $pdo->prepare('SELECT id FROM event_stages WHERE event_id = ?');
        $stmt->execute([$eventId]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    // --- Ładowanie kandydatów i podmiotu ---------------------------------

    // Filtr twardy "status inny niż opublikowany" i "wyjazd już zapełniony"
    // — status_item_id='full' jest osobnym stanem od 'published' (patrz
    // schema.sql), więc samo wymaganie code='published' pokrywa oba naraz
    // bez dodatkowego liczenia miejsc (ten sam mechanizm, który już
    // przełącza wydarzenie w 'full', jest jedynym źródłem prawdy).
    private static function candidatePool(?int $excludeEventId, ?int $viewerId = null): array
    {
        // PELETON JAKO SYGNAŁ DOPASOWANIA (2026-08-12). Dwa fakty per kandydat:
        // ilu ludzi z peletonu widza już się na niego zapisało i czy prowadzi go
        // ktoś, z kim widz realnie jeździł.
        //
        // Uzasadnienie: „Rajd Doliny Wisłoka, 53 km" to pozycja w katalogu;
        // „Michał na to jedzie" to zaproszenie. Podobieństwo trasy mówi, czy
        // wyjazd jest DO MNIE podobny — peleton mówi, czy będę tam wśród swoich,
        // a to przy decyzji o wspólnej jeździe waży więcej. Stąd waga peletonu
        // wyżej niż geografii (patrz PELOTON_* przy pozostałych wagach).
        //
        // Dla gościa (viewerId === null) fragment w ogóle nie wchodzi do
        // zapytania — zero zmiany zachowania sprzed tej daty.
        $pelotonSelect = '';
        if ($viewerId !== null) {
            // Osobne nazwy placeholderów (:pel_a…:pel_c) — przy
            // EMULATE_PREPARES=false tego samego nazwanego parametru nie da się
            // użyć dwa razy w jednym zapytaniu.
            $pelotonSelect = "
                   , (SELECT COUNT(DISTINCT r2.user_id)
                        FROM event_rsvps r2
                        JOIN dictionary_items rdi2
                          ON rdi2.id = r2.status_item_id AND rdi2.code = 'potwierdzony'
                        JOIN rider_connections rc
                          ON (rc.user_a_id = r2.user_id AND rc.user_b_id = :pel_a)
                          OR (rc.user_b_id = r2.user_id AND rc.user_a_id = :pel_b)
                       WHERE r2.edition_id = ed.id) AS peloton_riders
                   , (SELECT COUNT(*) FROM rider_connections rc2
                       WHERE (rc2.user_a_id = e.organizer_id AND rc2.user_b_id = :pel_c)
                          OR (rc2.user_b_id = e.organizer_id AND rc2.user_a_id = :pel_d)
                     ) AS peloton_organizer
            ";
        }

        $sql = "
            SELECT e.id AS event_id, ed.id AS edition_id, e.title, e.slug,
                   (SELECT GROUP_CONCAT(er.region_item_id) FROM event_regions er WHERE er.event_id = e.id) AS region_item_ids,
                   e.meeting_point_lat, e.meeting_point_lng,
                   e.pace_group_item_id, e.difficulty_item_id, e.event_type_item_id,
                   ed.start_date, ed.end_date,
                   COALESCE(tot.duration_days, 1) AS duration_days,
                   tot.total_distance_km,
                   (SELECT COUNT(*) FROM event_rsvps r
                      JOIN dictionary_items rdi ON rdi.id = r.status_item_id AND rdi.code = 'potwierdzony'
                     WHERE r.edition_id = ed.id) AS confirmed_count
                   {$pelotonSelect}
            FROM events e
            JOIN event_editions ed ON ed.event_id = e.id
            JOIN dictionary_items st ON st.id = e.status_item_id AND st.code = 'published'
            LEFT JOIN event_totals tot ON tot.event_id = e.id
            WHERE ed.is_cancelled = 0
        ";
        $params = [];
        if ($excludeEventId !== null) {
            $sql .= ' AND e.id != :exclude_event_id';
            $params['exclude_event_id'] = $excludeEventId;
        }
        // Etap 3 §6, stopień 1: "wydarzenie znika z rekomendacji dla tego
        // użytkownika" — trwałe, dopóki oba wydarzenia istnieją, nie tylko
        // w bieżącej sesji przeglądarki (patrz Models\RecommendationDismissal).
        if ($viewerId !== null) {
            $sql .= ' AND NOT EXISTS (
                SELECT 1 FROM recommendation_dismissals rd WHERE rd.user_id = :viewer_id AND rd.event_id = e.id
            )';
            $params['viewer_id'] = $viewerId;
            $params['pel_a'] = $viewerId;
            $params['pel_b'] = $viewerId;
            $params['pel_c'] = $viewerId;
            $params['pel_d'] = $viewerId;
        }

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        if (empty($rows)) {
            return [];
        }

        $bikeTypesByEvent = self::bikeTypesForEvents(array_unique(array_column($rows, 'event_id')));
        return array_map(fn($row) => self::mapRow($row, $bikeTypesByEvent), $rows);
    }

    private static function loadSubjectFromEdition(int $editionId): ?array
    {
        $stmt = Database::connection()->prepare("
            SELECT e.id AS event_id, ed.id AS edition_id, e.title, e.slug,
                   (SELECT GROUP_CONCAT(er.region_item_id) FROM event_regions er WHERE er.event_id = e.id) AS region_item_ids,
                   e.meeting_point_lat, e.meeting_point_lng,
                   e.pace_group_item_id, e.difficulty_item_id, e.event_type_item_id,
                   ed.start_date, ed.end_date,
                   COALESCE(tot.duration_days, 1) AS duration_days,
                   tot.total_distance_km,
                   (SELECT COUNT(*) FROM event_rsvps r
                      JOIN dictionary_items rdi ON rdi.id = r.status_item_id AND rdi.code = 'potwierdzony'
                     WHERE r.edition_id = ed.id) AS confirmed_count
            FROM event_editions ed
            JOIN events e ON e.id = ed.event_id
            LEFT JOIN event_totals tot ON tot.event_id = e.id
            WHERE ed.id = :edition_id
        ");
        $stmt->execute(['edition_id' => $editionId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        $bikeTypesByEvent = self::bikeTypesForEvents([(int) $row['event_id']]);
        $subject = self::mapRow($row, $bikeTypesByEvent);
        $subject['excludeEventId'] = $subject['eventId'];
        return $subject;
    }

    private static function normalizeDraftSubject(array $c): array
    {
        $startDate = $c['startDate'] ?? date('Y-m-d');
        return [
            'eventId'         => null,
            'editionId'       => null,
            'title'           => null,
            'slug'            => null,
            'regionItemIds'   => $c['regionItemIds'] ?? (isset($c['regionItemId']) && $c['regionItemId'] !== null ? [(int) $c['regionItemId']] : []),
            'meetingPointLat' => $c['meetingPointLat'] ?? null,
            'meetingPointLng' => $c['meetingPointLng'] ?? null,
            'paceGroupItemId' => $c['paceGroupItemId'] ?? null,
            'startDate'       => $startDate,
            'endDate'         => $c['endDate'] ?? $startDate,
            'distanceKm'      => $c['distanceKm'] ?? null,
            'confirmedCount'  => 0,
            'bikeTypeCodes'   => $c['bikeTypeCodes'] ?? [],
            'excludeEventId'  => $c['excludeEventId'] ?? null,
        ];
    }

    private static function mapRow(array $row, array $bikeTypesByEvent): array
    {
        $eventId = (int) $row['event_id'];
        $bikeInfo = $bikeTypesByEvent[$eventId] ?? ['codes' => [], 'itemIds' => []];
        return [
            'eventId'         => $eventId,
            'editionId'       => (int) $row['edition_id'],
            'title'           => $row['title'],
            'slug'            => $row['slug'],
            // Region NIE jest już skalarem (migr. 074) — 'region_item_ids' to
            // GROUP_CONCAT z event_regions, pusty string gdy event nie ma regionu.
            'regionItemIds'   => !empty($row['region_item_ids']) ? array_map('intval', explode(',', $row['region_item_ids'])) : [],
            'meetingPointLat' => $row['meeting_point_lat'] !== null ? (float) $row['meeting_point_lat'] : null,
            'meetingPointLng' => $row['meeting_point_lng'] !== null ? (float) $row['meeting_point_lng'] : null,
            'paceGroupItemId' => $row['pace_group_item_id'] !== null ? (int) $row['pace_group_item_id'] : null,
            // Etap 3 — potrzebne wyłącznie po stronie KANDYDATA do porównania
            // z profilem (paceProfile/difficultyProfile/isAspirational); nie
            // istniały w Etapie 2, bo tam pace/difficulty służyły wyłącznie
            // do alignmentAxis(), nie do rankingu.
            'difficultyItemId' => $row['difficulty_item_id'] !== null ? (int) $row['difficulty_item_id'] : null,
            'eventTypeItemId'  => $row['event_type_item_id'] !== null ? (int) $row['event_type_item_id'] : null,
            'startDate'       => $row['start_date'],
            'endDate'         => $row['end_date'] ?? self::deriveEndDate($row['start_date'], (int) $row['duration_days']),
            'distanceKm'      => $row['total_distance_km'] !== null ? (float) $row['total_distance_km'] : null,
            'confirmedCount'  => (int) $row['confirmed_count'],
            // Peleton (2026-08-12). Brak kluczy = zapytanie bez widza (gość albo
            // forDraft) — wtedy zera, czyli po prostu brak premii.
            'pelotonRiders'    => isset($row['peloton_riders']) ? (int) $row['peloton_riders'] : 0,
            'pelotonOrganizer' => !empty($row['peloton_organizer']),
            'bikeTypeCodes'   => $bikeInfo['codes'],
            'bikeTypeItemIds' => $bikeInfo['itemIds'],
            'excludeEventId'  => null,
        ];
    }

    private static function deriveEndDate(string $startDate, int $durationDays): string
    {
        return date('Y-m-d', strtotime($startDate . ' +' . ($durationDays - 1) . ' days'));
    }

    private static function bikeTypesForEvents(array $eventIds): array
    {
        if (empty($eventIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
        $stmt = Database::connection()->prepare("
            SELECT ebt.event_id, ebt.bike_type_item_id, bdi.code
            FROM event_bike_types ebt
            JOIN dictionary_items bdi ON bdi.id = ebt.bike_type_item_id
            WHERE ebt.event_id IN ($placeholders)
        ");
        $stmt->execute(array_values($eventIds));
        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $eventId = (int) $row['event_id'];
            $map[$eventId]['codes'][] = $row['code'];
            $map[$eventId]['itemIds'][] = (int) $row['bike_type_item_id'];
        }
        return $map;
    }

    // --- Etap 3 (preferencje) — profil zmieszany rampą ------------------

    // Zwraca null, gdy nie ma żadnej warstwy (gość, albo user bez deklaracji
    // i bez wystarczającej historii) — w tym przypadku evaluate() dodaje
    // zero do wyniku na każdej nowej osi, czyli zachowuje się DOKŁADNIE jak
    // w Etapie 2 (docs/etap3 §5, wiersz "Brak obu warstw").
    private static function loadProfile(?int $userId): ?array
    {
        if ($userId === null) {
            return null;
        }

        $declared = UserPreference::forUser($userId);
        $pdo = Database::connection();
        $statsStmt = $pdo->prepare('SELECT * FROM user_preference_stats WHERE user_id = :user_id');
        $statsStmt->execute(['user_id' => $userId]);
        $stats = $statsStmt->fetch() ?: null;

        $hasDeclared = !empty(array_filter($declared['operational']))
            || !empty(array_filter($declared['aspirational']))
            || $declared['distanceMinKm'] !== null || $declared['distanceMaxKm'] !== null
            || $declared['elevationMaxM'] !== null || $declared['groupSizePref'] !== 'any';
        $eventCount = $stats ? (int) $stats['event_count'] : 0;
        $hasDerived = $eventCount >= self::MIN_DERIVED_EVENT_COUNT;

        if (!$hasDeclared && !$hasDerived) {
            return null;
        }

        $signalStrength = $stats ? (float) $stats['signal_strength'] : 0.0;
        $derivedShare = $hasDerived ? min(self::RAMP_CAP, $signalStrength / self::RAMP_DIVISOR) : 0.0;

        // Osłabienie o połowę przez POST_EDIT_DAMPENING_DAYS po edycji
        // deklaracji (docs/etap3 §5) — user właśnie powiedział coś nowego,
        // prawdopodobnie bo dotychczasowe wyniki mu nie odpowiadały.
        if ($derivedShare > 0.0 && $declared['declaredUpdatedAt'] !== null) {
            $daysSinceEdit = (time() - strtotime($declared['declaredUpdatedAt'])) / 86400;
            if ($daysSinceEdit < self::POST_EDIT_DAMPENING_DAYS) {
                $derivedShare /= 2;
            }
        }
        $declaredShare = 1.0 - $derivedShare;

        $derivedByDict = ['bike_type' => [], 'pace_group' => [], 'difficulty_level' => [], 'region' => []];
        if ($hasDerived) {
            $stmt = $pdo->prepare('
                SELECT ups.dictionary_item_id, ups.weight, d.code AS dict_code
                FROM user_preference_signals ups
                JOIN dictionary_items di ON di.id = ups.dictionary_item_id
                JOIN dictionaries d ON d.id = di.dictionary_id
                WHERE ups.user_id = :user_id
            ');
            $stmt->execute(['user_id' => $userId]);
            foreach ($stmt->fetchAll() as $row) {
                if (isset($derivedByDict[$row['dict_code']])) {
                    $derivedByDict[$row['dict_code']][(int) $row['dictionary_item_id']] = (float) $row['weight'];
                }
            }
        }

        // Zmieszany wektor per słownik: declaredShare*1.0 dla zadeklarowanych
        // pozycji + derivedShare*waga dla sygnałów z zachowań, capowane do
        // 1.0 (gdy obie warstwy się zgadzają na tej samej pozycji).
        $buildDictWeights = function (string $dictCode) use ($declared, $derivedByDict, $declaredShare, $derivedShare): array {
            $weights = [];
            foreach ($declared['operational'][$dictCode] ?? [] as $code) {
                $id = Dictionary::id($dictCode, $code);
                if ($id !== null) {
                    $weights[$id] = ($weights[$id] ?? 0.0) + $declaredShare;
                }
            }
            foreach ($derivedByDict[$dictCode] ?? [] as $id => $w) {
                $weights[$id] = ($weights[$id] ?? 0.0) + $derivedShare * $w;
            }
            foreach ($weights as $id => $w) {
                $weights[$id] = min(1.0, $w);
            }
            return $weights;
        };

        return [
            'bikeWeights'       => $buildDictWeights('bike_type'),
            'paceWeights'       => $buildDictWeights('pace_group'),
            'difficultyWeights' => $buildDictWeights('difficulty_level'),
            'regionWeights'     => $buildDictWeights('region'),
            'aspirationalRegionItemIds'    => self::dictionaryItemIds('region', $declared['aspirational']['region']),
            'aspirationalEventTypeItemIds' => self::dictionaryItemIds('event_type', $declared['aspirational']['event_type']),
            'distanceMinKm'  => $declared['distanceMinKm'],
            'distanceMaxKm'  => $declared['distanceMaxKm'],
            'avgDistanceKm'  => $stats && $stats['avg_distance_km'] !== null ? (float) $stats['avg_distance_km'] : null,
            'groupSizePref'  => $declared['groupSizePref'],
            'derivedShare'   => $derivedShare,
        ];
    }

    private static function dictionaryItemIds(string $dictCode, array $codes): array
    {
        $ids = [];
        foreach ($codes as $code) {
            $id = Dictionary::id($dictCode, $code);
            if ($id !== null) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    // Zakres deklarowany ma pierwszeństwo przed średnią wynikającą z
    // zachowań (docs/etap3 §5 tabela "Zmiany w składnikach oceny" —
    // distanceScore: "punkt odniesienia z zakresu deklarowanego LUB z
    // avg_distance_km"). W zakresie = pełny wynik; poza zakresem opada
    // liniowo do zera na przestrzeni szerokości samego zakresu.
    private static function distanceProfileScore(float $distanceKm, array $profile): float
    {
        $min = $profile['distanceMinKm'];
        $max = $profile['distanceMaxKm'];
        if ($min !== null && $max !== null) {
            if ($distanceKm >= $min && $distanceKm <= $max) {
                return 1.0;
            }
            $edge = $distanceKm < $min ? $min - $distanceKm : $distanceKm - $max;
            $span = max(1.0, $max - $min);
            return max(0.0, 1.0 - $edge / $span);
        }
        if ($profile['avgDistanceKm'] !== null && $profile['avgDistanceKm'] > 0) {
            $diff = abs($distanceKm - $profile['avgDistanceKm']);
            return max(0.0, 1.0 - $diff / $profile['avgDistanceKm']);
        }
        return 0.0;
    }
}
