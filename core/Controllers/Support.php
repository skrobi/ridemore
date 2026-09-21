<?php
// core/Controllers/Support.php
// Pomocnicze metody współdzielone przez kontrolery web/* — dawniej closures
// zdefiniowane na górze web/routes.php i przekazywane do każdej trasy przez
// use(...). Jako statyczne metody nie wymagają już przekazywania — każdy
// kontroler wywołuje Support::coś() bezpośrednio.
namespace Controllers;

use Core\Auth;
use Core\Csrf;
use Core\Mailer;
use Models\Dictionary;
use Models\Event;
use Models\EventEdition;
use Models\EventPermission;
use Models\EventRsvp;
use Models\OrganizerBillingProfile;
use Models\User;
use Utils\Format;
use Utils\View;

class Support
{
    // Leaflet tylko tam gdzie potrzebny — patrz $extraHead w layout.php.
    //
    // MapLibre GL + binding (@maplibre/maplibre-gl-leaflet) — oficjalna ścieżka
    // „Using Leaflet" z dokumentacji OpenFreeMap. Podkład OpenFreeMap
    // (RIDEMORE_BASE_DEFAULT w assets/js/gpx-map.js) jest WEKTOROWY (pbf + styl
    // MapLibre), więc bez renderera GL Leaflet nie ma czym go pokazać. Wersje
    // przypięte na sztywno tak jak leaflet@1.9.4; kolejność skryptów MA
    // znaczenie — binding przy starcie czyta globalne L i maplibregl.
    public static function leafletHead(): string
    {
        // Preconnect PRZED pierwszym żądaniem do kafli/fontów/sprite'ów
        // OpenFreeMap (jeden host na wszystko) — oszczędza DNS+TLS na starcie
        // mapy; crossorigin, bo MapLibre pobiera zasoby fetch-em (CORS).
        //
        // DEFER na obu ciężkich skryptach (~1 MB sam maplibre-gl): synchroniczne
        // blokowały render CAŁEJ strony do momentu pobrania i wykonania.
        // Kolejność między deferami jest zachowana (binding po maplibre-gl),
        // a gpx-map.js woła L.maplibreGL dopiero przy tworzeniu podkładu —
        // mapy stworzone WCZEŚNIEJ niż wykonały się deferowane skrypty łapią
        // kolejkę RIDEMORE_GL_CZEKA (patrz assets/js/gpx-map.js).
        return '<link rel="preconnect" href="https://tiles.openfreemap.org" crossorigin>'
            . "\n" . '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">'
            . "\n" . '<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>'
            . "\n" . '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/maplibre-gl@5.24.0/dist/maplibre-gl.css">'
            . "\n" . '<script defer src="https://cdn.jsdelivr.net/npm/maplibre-gl@5.24.0/dist/maplibre-gl.js"></script>'
            . "\n" . '<script defer src="https://cdn.jsdelivr.net/npm/@maplibre/maplibre-gl-leaflet@0.1.4/leaflet-maplibre-gl.js"></script>'
            . self::mapPerfHead();
    }

    /**
     * DZIENNIK CZASÓW MAPY — WYŁĄCZNIE W DEV (2026-09-02).
     *
     * Monitor ładowania (`ridemoreMapBusy` w assets/js/discovery-map.js) działa
     * wszędzie, bo panel „co się teraz dzieje" jest dla człowieka. Odsyłanie
     * pomiarów NA SERWER jest czym innym: to narzędzie do szukania winowajcy,
     * a nie funkcja produktu. Na produkcji byłoby dodatkowym żądaniem od każdego
     * odwiedzającego i rosnącym plikiem, którego nikt nie czyta — więc adres
     * w ogóle się tam nie pojawia, a JS bez adresu nie wysyła nic.
     *
     * Token CSRF jedzie tą samą drogą, bo endpoint ZAPISUJE — tym samym
     * nagłówkiem, którym czyta go `/api/events/{slug}/rsvp`.
     *
     * Stoi w `leafletHead()`, czyli w JEDNYM miejscu, przez które przechodzi
     * każda mapa w serwisie — tam, gdzie mieszka reszta wspólnego ładowania.
     */
    private static function mapPerfHead(): string
    {
        if (APP_ENV !== 'dev') {
            return '';
        }

        return "
" . '<script>window.RIDEMORE_MAP_PERF=' . json_encode(View::url('/api/map/perf'))
            . ';window.RIDEMORE_MAP_PERF_TOKEN=' . json_encode(Csrf::token()) . ';</script>';
    }

    // Leaflet + współdzielone helpery (ridemoreCreateMap/ridemoreAddGpxTrack, patrz
    // assets/js/gpx-map.js) — dla stron z samą mapą (wybór lokalizacji w formularzu
    // eventu), bez leaflet-gpx, którego tam nie używamy.
    public static function leafletMapHead(): string
    {
        return self::leafletHead() . "\n" . '<script src="' . View::asset('/assets/js/gpx-map.js') . '"></script>';
    }

    // To samo + leaflet-gpx — dla stron renderujących realne ślady GPX (strona
    // wydarzenia, wyniki wyszukiwania).
    public static function gpxMapHead(): string
    {
        return self::leafletMapHead() . "\n" . '<script src="https://cdn.jsdelivr.net/npm/leaflet-gpx@1.7.0/gpx.min.js"></script>';
    }

    /**
     * Mapa „id przejazdu → adres pliku GPX" pod podświetlanie z panelu
     * „Ostatnia aktywność" (ridemoreRideFeed, opcja `tracks`). JEDEN helper
     * dla /odkrycia (obie zakładki) i profilu rowerzysty (2026-08-25, uwaga
     * usera: aktywności mają być TE SAME wszędzie) — dlatego mieszka tu,
     * a nie w jednym z kontrolerów.
     *
     * Właściciel śladu wychodzi Z WIERSZA (user_id), nie z parametru — dzięki
     * temu ten sam kod obsługuje feed osobisty i SPOŁECZNOŚCI, gdzie każdy
     * wiersz ma innego autora. Dwa źródła śladu, w kolejności:
     *   1. edition_tracks przez parę (edition_id, user_id) — ślad efektywny
     *      wyjazdu (własny albo zbiorowy; EditionTrack::effectiveFor).
     *   2. rider_activities.gpx_url — pliki PRZEJAZDÓW SOLO (migr. 049),
     *      których edition_id nie dotyczy. Bez tej gałęzi solo znikałoby
     *      z podświetlania, choć to właśnie one dominują w danych.
     *
     * PLIK SOLO TYLKO DLA WŁAŚCICIELA (§27, 2026-08-26) — i to jest jedyne
     * miejsce w tym helperze, gdzie widz w ogóle ma znaczenie. Plik solo
     * zaczyna się i kończy pod czyimś domem (przycinamy POLA przed zapisem,
     * ale nie sam plik — patrz RiderActivity::recordSolo), więc jego adres nie
     * może wyjść na cudzej osi czasu: ani na mapie społeczności, ani na
     * publicznym profilu. Bez adresu wiersz feedu nadal działa — dostaje sam
     * kadr z zapisanych pól (`bounds` z Discovery::withMapBounds), czyli to
     * samo, co miał, zanim solo w ogóle tu weszło.
     *
     * Ślad WYJAZDU takiej bramki nie potrzebuje: turnus zaczyna się na
     * zbiórce ogłoszonej publicznie, nie pod czyimś blokiem.
     *
     * ADRES NIE JEST JUŻ ADRESEM PLIKU (2026-09-02, zgłoszenie usera:
     * „klikam w dużą aktywność i czekam, aż wczyta się cały GPX"). Wiersz
     * dostaje adres ENDPOINTU `/api/rides/{id}/track`, który oddaje samą
     * geometrię z `gpx_geometry` — spakowaną, kilkanaście razy mniejszą od
     * pliku i bez tętna, mocy i kadencji, których rysowanie linii nie używa.
     * Reguła „komu wolno" nie zmieniła się ani o krok: liczy ją nadal ta
     * metoda (niżej `rideGpxPath`), a endpoint pyta o nią tym samym kodem.
     *
     * OBCY DOSTAJE TEN SAM LINK DLA SOLO PUBLICZNEGO WŁAŚCICIELA, PRZYCIĘTY
     * (2026-09-10, zgłoszenie usera: „na swoim profilu każdy przejazd jest
     * kolorowany i klikalny, na czyimś powinno być tak samo"). Endpoint
     * `/api/rides/{id}/track` umie to oddać od 2026-09-03 (patrz `strona
     * przejazdu`, `strangerTrackPath()` niżej) — TA metoda była jedynym
     * miejscem, które o to nie zapytało, więc wiersz solo obcej osoby w ogóle
     * nie dostawał linku, mimo że sam endpoint by odpowiedział poprawnie.
     * `rideGpxPath()` zostaje PIERWSZYM pytaniem (szybsza ścieżka: właściciel
     * i ślady wyjazdów nie potrzebują drugiego zapytania o `visibleRiderById`);
     * `strangerTrackPath()` dopytuje TYLKO wtedy, gdy pierwsze się nie powiodło.
     *
     * @param list<array<string,mixed>> $rides wiersze z Discovery::recentActivity
     * @param int|null $viewerId kto ogląda (null = niezalogowany); decyduje
     *        WYŁĄCZNIE o tym, czy solo dostaje PEŁNY ślad (właściciel) czy
     *        PRZYCIĘTY (każdy inny, o ile właściciel jest publiczny)
     * @return array<int,string> activity_id => adres geometrii śladu
     */
    public static function trackUrlsForFeed(array $rides, ?int $viewerId = null): array
    {
        $byPair = [];
        $out = [];
        foreach ($rides as $ride) {
            if (empty($ride['id']) || empty($ride['user_id'])) {
                continue;
            }
            $dostepny = self::rideGpxPath($ride, $viewerId, $byPair) !== null
                || self::strangerTrackPath($ride) !== null;
            if ($dostepny) {
                $out[(int) $ride['id']] = View::url('/api/rides/' . (int) $ride['id'] . '/track');
            }
        }
        return $out;
    }

    /**
     * Który plik GPX opisuje TEN przejazd dla TEGO widza — jedyna definicja
     * reguły opisanej w nocie `trackUrlsForFeed` wyżej. Wyciągnięte z niej
     * (2026-09-02), gdy tę samą odpowiedź musiał poznać endpoint geometrii:
     * dwie kopie tej bramki rozjechałyby się przy pierwszej zmianie zasad
     * prywatności, a rozjazd oznaczałby wydanie cudzego śladu spod domu.
     *
     * Zwraca ŚCIEŻKĘ Z BAZY (bez `View::url`) — wołający robi z niej albo
     * adres, albo plik na dysku (`TileSource::absolutePath`).
     *
     * @param array<string,mixed> $ride wiersz z Discovery::recentActivity
     * @param array<string,?string> $byPair cache „turnus:osoba" — feed
     *        społeczności potrafi przynieść kilka wierszy tej samej pary
     */
    public static function rideGpxPath(array $ride, ?int $viewerId, array &$byPair = []): ?string
    {
        $editionId = (int) ($ride['edition_id'] ?? 0);
        $gpx = null;

        if ($editionId !== 0) {
            $pairKey = $editionId . ':' . (int) $ride['user_id'];
            if (!array_key_exists($pairKey, $byPair)) {
                // Ślad efektywny JEDNEJ pary (turnus, osoba) — dokładnie ta
                // sama reguła, którą liczą pola: własny ślad wygrywa ze
                // zbiorowym. effectiveFor zwraca LISTĘ (wielodniówki)
                // — podświetlamy pierwszy.
                $tracks = \Models\EditionTrack::effectiveFor($editionId, (int) $ride['user_id']);
                $byPair[$pairKey] = $tracks ? (string) $tracks[0]['gpx_url'] : null;
            }
            $gpx = $byPair[$pairKey];
        } elseif ($viewerId !== null && (int) $ride['user_id'] === $viewerId) {
            // Przejazd solo — wydajemy tylko jego właścicielowi.
            $gpx = $ride['gpx_url'] ?? null;
        }

        return ($gpx !== null && $gpx !== '') ? $gpx : null;
    }

    /**
     * Rowerzysta, którego profil jest PUBLICZNY — jedyna definicja tej reguły
     * w aplikacji (Etap 3, zawężone 2026-09-10).
     *
     * Dwa warunki: konto istnieje · nie wyłączyło się z list
     * (`users.roster_visible`, migr. 037). BEZ WYMOGU HISTORII — zgłoszenie
     * usera 2026-09-10: „rowerzysta się rejestruje, nigdzie nie był, nigdzie
     * jeszcze nie jechał, wchodzi na profil i co… dupa". Do 2026-09-10 była tu
     * trzecia bramka („ma choć jeden potwierdzony przejazd, wyjazdowy albo
     * solo") — usunięta świadomie: świeże konto ma prawo mieć publiczny,
     * pusty profil, a nie 404. Widok (`rider-profile.php`) odpowiada za to,
     * żeby zero wszędzie wyglądało jak zachęta, nie jak awaria.
     *
     * Wyciągnięte z RiderController::show(), gdy mapę odkryć trzeba było podać
     * także przez API (`/api/discovery/cells?scope=rider`). Dwie kopie tego
     * warunku rozjechałyby się przy pierwszej zmianie zasad prywatności, a
     * rozjazd oznaczałby wyciek mapy osoby, która się ukryła.
     *
     * Zwraca null w OBU przypadkach — wołający ma oddać to samo 404, żeby nie
     * zdradzać, czy konto istnieje, czy tylko się ukryło.
     */
    public static function visibleRider(?string $slug): ?User
    {
        if ($slug === null || $slug === '') {
            return null;
        }
        $user = User::findBySlug($slug);
        return ($user && $user->rosterVisible) ? $user : null;
    }

    /**
     * To samo pytanie, ale zadane ID-kiem zamiast slugiem (2026-09-03, strona
     * `/przejazd/{id}`). Przejazd zna WŁAŚCICIELA, nie jego adres publiczny,
     * a reguła „czyj profil jest publiczny" ma zostać jedna — więc zamiast
     * powtarzać tu jej trzy warunki, dociągamy konto i pytamy `visibleRider()`.
     */
    public static function visibleRiderById(int $userId): ?User
    {
        $user = User::find($userId);
        return $user === null ? null : self::visibleRider($user->publicSlug);
    }

    /**
     * ŚLAD PRZEJAZDU DLA KOGOŚ, KTO NIE JEST WŁAŚCICIELEM (2026-09-03).
     *
     * Bramka strony przejazdu i endpointu geometrii w jednym miejscu, bo to
     * jedna reguła: cudzy przejazd solo wolno pokazać wyłącznie wtedy, gdy
     * jego właściciel jest publicznym rowerzystą — i wyłącznie PO PRZYCIĘCIU
     * okolic domu (§27). Zwraca adres pliku, z którego ma powstać PRZYCIĘTA
     * geometria, albo null, gdy pokazywać nie wolno.
     *
     * Przejazd z wyjazdu tędy nie chodzi: jego ślad jest publiczny z natury
     * i oddaje go `rideGpxPath()` każdemu, bez przycinania.
     */
    public static function strangerTrackPath(array $ride): ?string
    {
        if ((int) ($ride['edition_id'] ?? 0) !== 0) {
            return null;
        }
        $gpx = $ride['gpx_url'] ?? null;
        if ($gpx === null || $gpx === '') {
            return null;
        }
        return self::visibleRiderById((int) $ride['user_id']) !== null ? (string) $gpx : null;
    }

    // Okruszki dla stron formularza eventu — ten sam trail co w admin/routes.php.
    public static function homeCrumb(): array
    {
        return ['label' => __('Strona główna'), 'url' => View::url('/')];
    }

    public static function panelCrumb(): array
    {
        return ['label' => __('Panel'), 'url' => View::url('/admin')];
    }

    public static function eventFormDictOptions(): array
    {
        return [
            // eventTypes dopisane 2026-08-09 — dotąd brakowało go tu, mimo że
            // reszta słowników formularza już tędy szła. Skutek: typ eventu był
            // jedynym polem kreatora hardcodowanym osobno wszędzie, gdzie go
            // używano (m.in. rozszerzenie Chrome "Importer eventów" miało własną,
            // sztywną listę 3 radiobuttonów) — dodanie nowego typu (np. 'wyscig')
            // wymagało pamiętać o aktualizacji tych miejsc z osobna. Teraz
            // '/api/dictionaries' (ta sama funkcja) niesie też typy eventu, więc
            // konsumenci mogą je odczytać stąd zamiast trzymać własną kopię.
            'eventTypes'         => Dictionary::items('event_type'),
            'difficulties'       => Dictionary::items('difficulty_level'),
            'paces'              => Dictionary::items('pace_group'),
            'bikeTypes'          => Dictionary::items('bike_type'),
            'regions'            => Dictionary::groupedLeaves('region'),
            'surfaces'           => Dictionary::items('surface_type'),
            'accommodationTypes' => Dictionary::items('accommodation_type'),
            'mealTypes'          => Dictionary::items('meal_type'),
            'currencies'         => Dictionary::items('currency'),
            'priceUnits'         => Dictionary::items('price_unit'),
            // Dopisane 2026-08-21 — tryb "Dodaj skarb" w rozszerzeniu Chrome
            // (Importer eventów) pobiera kategorie skarbów tym samym requestem
            // co słowniki eventowe (z cache 24h w chrome.storage.local).
            'treasureCategories' => Dictionary::items('treasure_category'),
        ];
    }

    // Wspólny guard dla tras wymagających uprawnień do zarządzania eventem
    // (sam organizator, współpracownik albo admin — patrz EventPermission::canEdit()).
    // Zwraca Event albo sam kończy request: redirect na /logowanie (brak loginu,
    // Auth::requireLogin()), zakończenie ciche gdy findBySlugOrFail() już wysłał
    // 404, albo 403 z komunikatem dopasowanym do konkretnej trasy.
    public static function requireEventEditPermission(string $slug, string $forbiddenMessage): Event
    {
        $user = Auth::requireLogin();
        $event = Event::findBySlugOrFail($slug);
        if (!$event) exit;
        if (!EventPermission::canEdit($user, $event->organizerId)) {
            http_response_code(403);
            echo $forbiddenMessage;
            exit;
        }
        return $event;
    }

    // Który turnus (Models\EventEdition) tej rezerwacji/akcji dotyczy —
    // $requestedId to zwykle $_GET['termin']/$_POST['edition_id'] z formularza/
    // linku (patrz event-page.php, "Wybierz termin"). Gdy brak/nieprawidłowy
    // (np. spreparowany POST albo stary zakładka sprzed dodania turnusu) —
    // pada na domyślny: najbliższy nadchodzący nieodwołany, a w ostateczności
    // pierwszy — ten sam wybór co EventEdition::defaultForEvent(), ale bez
    // dodatkowego zapytania, bo $event->editions jest już wczytane.
    public static function resolveEdition(Event $event, $requestedId): EventEdition
    {
        $editions = $event->editions;
        if ($requestedId !== null && $requestedId !== '') {
            foreach ($editions as $ed) {
                if ($ed->id === (int) $requestedId) {
                    return $ed;
                }
            }
        }
        $today = date('Y-m-d');
        foreach ($editions as $ed) {
            if (!$ed->isCancelled && $ed->startDate >= $today) {
                return $ed;
            }
        }
        return $editions[0];
    }

    // Guard bezpieczeństwa dla akcji admina/organizatora na konkretnym zapisie
    // (potwierdzenie wpłaty/zwrotu, anulowanie w czyimś imieniu, historia wpłat)
    // — requireEventEditPermission() wyżej weryfikuje TYLKO, że wywołujący
    // zarządza TYM eventem; bez tej dodatkowej kontroli spreparowany edition_id
    // wskazujący na turnus CUDZEGO wydarzenia przeszedłby dalej (EventRsvp::
    // forEditionAndUser()/confirmPayment() itd. filtrują tylko po edition_id +
    // user_id, nie po właścicielu eventu).
    public static function editionBelongsToEvent(Event $event, int $editionId): bool
    {
        foreach ($event->editions as $ed) {
            if ($ed->id === $editionId) {
                return true;
            }
        }
        return false;
    }

    // Wspólne guardy dla trasy rezerwacji płatnego eventu wewnętrznego —
    // zwraca [Event, EventEdition] albo sam kończy request (404/redirect), żeby
    // nie duplikować tego bloku w GET i POST /zapisz.
    public static function requirePayableInternalEvent(string $slug, $requestedEditionId = null): array
    {
        Auth::requireLogin();
        $event = Event::findBySlugOrFail($slug);
        if (!$event) exit;
        if (!$event->pricing || $event->registrationTypeCode !== 'internal' || !in_array($event->statusCode, ['published', 'full'], true)) {
            header('Location: ' . View::url('/events/' . $slug));
            exit;
        }
        $edition = self::resolveEdition($event, $requestedEditionId);
        $user = Auth::user();
        // 'anulowany' = wcześniej zrezygnował, 'zainteresowany' = najlżejsze
        // oznaczenie bez zobowiązania (patrz EventRsvp::markInterested()) — oba
        // pozwalają przejść do prawdziwej rezerwacji, nie traktujemy ich jak
        // istniejący aktywny zapis. Bez 'zainteresowany' na tej liście kliknięcie
        // "Zarezerwuj" po wcześniejszym "Zainteresowany" po cichu przekierowywało
        // z powrotem na stronę eventu bez żadnego komunikatu. Sprawdzane per
        // TURNUS — user może być 'potwierdzony' na jeden termin i wciąż wolno
        // mu zarezerwować inny.
        if (!in_array(EventRsvp::statusForUser($edition->id, $user->id), [null, 'anulowany', 'zainteresowany'], true)) {
            header('Location: ' . View::url('/events/' . $slug) . '?termin=' . $edition->id);
            exit;
        }
        return [$event, $edition];
    }

    // Podsumowanie płatności dla płatnego eventu wewnętrznego — te same dane
    // (kwota/zaliczka/termin/konto do przelewu) potrzebne i na stronie eventu pod
    // CTA "Zapłać" (GET /events/{slug}), i w mailu payment-reminder zaraz po
    // rezerwacji (POST /wydarzenia/{slug}/zapisz). $deadlineDate: null gdy
    // nieznany (np. brak joined_at).
    public static function paymentSummary(Event $event, ?\DateTimeInterface $deadlineDate): array
    {
        $currencySymbol = Format::currencySymbol($event->pricing->currencyCode);
        $hasDeposit = $event->pricing->depositAmount !== null && $event->pricing->depositAmount > 0;
        $organizerBilling = OrganizerBillingProfile::findByUserId($event->organizerId);
        return [
            'amountLabel'        => Format::price($event->pricing->amount, $currencySymbol),
            'depositAmountLabel' => $hasDeposit ? Format::price($event->pricing->depositAmount, $currencySymbol) : null,
            'dueNowLabel'        => Format::price($hasDeposit ? $event->pricing->depositAmount : $event->pricing->amount, $currencySymbol),
            'deadlineDateLabel'  => $deadlineDate ? Format::dateP($deadlineDate->format('Y-m-d')) : null,
            'bankAccount'        => $organizerBilling->bankAccount ?? '—',
            // Posiadacz konta bywa inny niż podmiot z faktury (patrz
            // OrganizerBillingProfile::save()) — bankAccountHolder to jedyne
            // poprawne źródło nazwiska/nazwy do przelewu, legalName tylko
            // jako fallback dla profili sprzed tego pola.
            'bankOwnerName'      => $organizerBilling->bankAccountHolder ?? $organizerBilling->legalName ?? $event->organizer->name,
        ];
    }

    // Mail "Rezygnacja z udziału potwierdzona" — wysyłany i przy samoobsłudze
    // (POST /wydarzenia/{slug}/anuluj-udzial), i gdy anuluje organizator w imieniu
    // uczestnika (POST /wydarzenia/{slug}/uczestnicy/{userId}/anuluj); ta sama
    // treść niezależnie od tego, kto kliknął.
    public static function notifyCancellation(Event $event, User $participant, string $newStatus): void
    {
        $refundPending = $newStatus === 'oczekuje_zwrotu';
        \Core\Lang::with(\Core\Lang::forEmail((string) ($participant->email)), static fn() => Mailer::sendTemplate('cancellation-confirmed', $participant->email, __('Rezygnacja z udziału potwierdzona: {tytul}', ['tytul' => $event->title]), [
            'recipientName' => $participant->name ?: $participant->email,
            'eventTitle'    => $event->title,
            'refundPending' => $refundPending,
            'amountLabel'   => ($refundPending && $event->pricing)
                ? Format::price($event->pricing->amount, Format::currencySymbol($event->pricing->currencyCode))
                : null,
        ]));
    }
}
