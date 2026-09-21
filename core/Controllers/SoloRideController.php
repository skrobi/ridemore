<?php
// core/Controllers/SoloRideController.php
// PRZEJAZD SOLO — wgranie śladu bez wydarzenia (Etap 8A/14).
//
// Do migracji 049 mapa odkryć znała wyłącznie przejazdy z wyjazdów: żeby cokolwiek
// odkryć, trzeba było zapisać się na wydarzenie, pojechać i potwierdzić obecność.
// Codzienna runda po okolicy — czyli większość tego, co ludzie realnie jeżdżą —
// dla tego modułu nie istniała.
//
// Kontroler jest cienki z rozmysłem: cała logika (hash, idempotencja, pola,
// punkty) siedzi w Models\RiderActivity::recordSolo, bo to samo wejście będzie
// obsługiwać import ze Stravy, gdy dojdzie.
namespace Controllers;

use Core\Auth;
use Core\Csrf;
use Models\RiderActivity;
use Utils\Upload;
use Utils\View;

class SoloRideController
{
    public static function upload(): void
    {
        Auth::requireLogin();
        $user = Auth::user();
        $back = fn(string $param) => View::url('/admin/moje-przejazdy') . '?' . $param;

        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            self::fail('sesja');
        }
        if (empty($_FILES['gpx']['name'])) {
            self::fail('brak-pliku');
        }

        try {
            // GPX ALBO FIT (2026-08-23) — FIT konwertowany na wejściu, patrz
            // Utils\Fit. Dalej wszystko dzieje się tak samo jak zawsze.
            $token = Upload::saveTrackTemp($_FILES['gpx']);
            $gpxUrl = Upload::promoteGpxTemp($token);
            if ($gpxUrl === null) {
                self::fail('zly-plik');
            }

            $result = RiderActivity::recordSolo($user->id, CORE_PATH . '/..' . $gpxUrl, $gpxUrl);
        } catch (\RuntimeException $e) {
            // Gpx::parse rzuca czytelnym komunikatem („Trasa zbyt krótka" itd.),
            // ale nie wpuszczamy go do adresu — front ma własną, stałą listę.
            self::fail('zly-plik');
        } catch (\Throwable $e) {
            error_log('Przejazd solo: ' . $e->getMessage());
            self::fail('zly-plik');
        }

        // W APCE: sukces/duplikat prowadzi na dedykowany, pełnoekranowy ekran
        // wyniku (§11 audytu UX 2026-08-28) zamiast na listę przejazdów —
        // ten sam bodziec co ekran skarbu (`treasure-scan.php`), tylko dla
        // zakończonej jazdy. Dane lecą przez sesję (jednorazowo, kasowane przy
        // odczycie w summary()) — zbyt bogate na czytelny query string, a POST
        // → sesja → GET to ten sam wzorzec PRG co zawsze, tylko z ładunkiem.
        // Błędy (blad=...) ŚWIADOMIE zostają na starej ścieżce nawet w apce:
        // to nie jest moment nagrody, a lista przejazdów i tak renderuje się
        // poprawnie pod `body.is-app` (Faza 1 kontraktu apka-mobilna-ux.md).
        if (APP_IS_APP) {
            $_SESSION['ride_summary'] = $result === null
                ? ['duplicate' => true]
                : [
                    'cellsNew'         => (int) $result['cellsNew'],
                    'cellsTouched'     => (int) $result['cellsTouched'],
                    'distanceKm'       => (float) $result['distanceKm'],
                    'elevationGainM'   => (int) $result['elevationGainM'],
                    'pointsRide'       => (int) $result['pointsRide'],
                    'pointsEvent'      => (int) $result['pointsEvent'],
                    'pointsDiscovery'  => (int) $result['pointsDiscovery'],
                    'pointsExploration'=> (int) $result['pointsExploration'],
                    'pointsTrails'     => (int) $result['pointsTrails'],
                    'firstInCommunity' => (int) $result['firstInCommunity'],
                    'trailsReached'    => $result['trailsReached'],
                    'treasures'        => array_map(static fn(array $t): array => [
                        'name'   => $t['treasure']['name'],
                        'points' => (int) $t['points'],
                    ], $result['treasures']),
                ];
        }

        // APKA WYSYŁA ŚLAD W TLE (`assets/js/app-tracking.js`) i sama decyduje,
        // czy przejść na ekran wyniku — potrzebuje więc odpowiedzi, którą da
        // się odczytać bez parsowania HTML-a. Ten sam wzorzec co kolejka
        // skanów (`TreasureScanController::respond`): `Accept: application/json`
        // przełącza WYŁĄCZNIE format odpowiedzi, formularz na stronie dostaje
        // dokładnie to, co dotąd.
        //
        // I to jest tu jedyny sposób, żeby ekran wyniku w ogóle się pokazał:
        // `fetch` ŚLEDZI przekierowanie, więc przy PRG to ono konsumowało
        // jednorazowy klucz `ride_summary`, a nawigacja człowieka trafiała już
        // na pustkę i wracała na listę przejazdów.
        if (self::wantsJson()) {
            self::json([
                'ok'       => true,
                'duplikat' => $result === null,
                'pola'     => $result === null ? 0 : (int) $result['cellsNew'],
            ]);
        }

        if (APP_IS_APP) {
            header('Location: ' . View::url('/admin/moje-przejazdy/podsumowanie'));
            exit;
        }

        // null = ten plik był już policzony (klucz unikalny user+hash).
        // To NIE jest błąd: człowiek wgrał drugi raz to samo i ma się dowiedzieć,
        // że nic nie przepadło i nic się nie zdublowało.
        header('Location: ' . $back($result === null
            ? 'info=solo-duplikat'
            : 'info=solo-dodany&pola=' . (int) $result['cellsNew']));
        exit;
    }

    /** Czy klient prosi o wynik maszynowo (apka), zamiast o stronę. */
    private static function wantsJson(): bool
    {
        return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    }

    /**
     * Jedno wyjście z odpowiedzią JSON. `headers_sent()` w prawdziwym żądaniu
     * jest zawsze fałszywe — strażnik jest pod testy w CLI, dokładnie jak
     * w `TreasureScanController::respond()`.
     */
    private static function json(array $dane): void
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($dane, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Nieudane wgranie. Apka dostaje `{ok:false}` i po tym POZNAJE, że ślad
     * przepadł — po przekierowaniu na stronę z `?blad=` odpowiedź wyglądała
     * z jej strony identycznie jak sukces, więc kasowała bufor z nagraniem.
     */
    private static function fail(string $kod): void
    {
        if (self::wantsJson()) {
            self::json(['ok' => false, 'blad' => $kod]);
        }
        header('Location: ' . View::url('/admin/moje-przejazdy') . '?blad=' . $kod);
        exit;
    }

    /**
     * EKRAN WYNIKU JAZDY — wyłącznie w apce (§11 audytu UX 2026-08-28).
     * Sesja jest jednorazowa: odświeżenie/cofnięcie nie ma jak pokazać
     * nieaktualnych liczb drugi raz, bo klucz znika przy pierwszym odczycie —
     * ten sam odruch co flash-komunikaty gdzie indziej w serwisie.
     */
    public static function summary(): void
    {
        Auth::requireLogin();
        $data = $_SESSION['ride_summary'] ?? null;
        unset($_SESSION['ride_summary']);

        if ($data === null) {
            header('Location: ' . View::url('/admin/moje-przejazdy'));
            exit;
        }

        View::render('web', 'ride-summary-app', [
            'title'   => __('Twoja jazda | ridemore.bike'),
            'summary' => $data,
        ]);
    }

    /**
     * POWIĄZANIE przejazdu solo z turnusem — z obu stron tej samej tabeli:
     * z zakładki „Przejazdy solo" (wybierz wyjazd) i z kolumny „Ślad"
     * w zakładce „Wyjazdy" (wybierz przejazd). To jedna czynność opisana z
     * dwóch stron, więc jedna trasa i jeden kod — dwa endpointy różniłyby się
     * wyłącznie kolejnością dwóch liczb w formularzu.
     *
     * Kontroler znów jest cienki: cała mechanika (kasowanie przejazdu solo,
     * podpięcie śladu, obecność) siedzi w RiderActivity::linkSoloToEdition,
     * bo to są niezmienniki danych, a nie warstwa HTTP.
     */
    public static function link(): void
    {
        Auth::requireLogin();
        $user = Auth::user();
        // Wracamy na zakładkę, z której człowiek kliknął — wyrzucenie go na
        // inną listę niż ta, na którą patrzył, jest zawsze błędem.
        $tab = ($_POST['tab'] ?? '') === 'solo' ? 'solo' : 'wyjazdy';
        $back = fn(string $param) => View::url('/admin/moje-przejazdy') . '?tab=' . $tab . '&' . $param;

        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            header('Location: ' . $back('blad=sesja'));
            exit;
        }

        $wynik = RiderActivity::linkSoloToEdition(
            (int) ($_POST['przejazd_id'] ?? 0),
            $user->id,
            (int) ($_POST['edition_id'] ?? 0)
        );

        header('Location: ' . $back($wynik === 'ok' ? 'info=powiazano' : 'blad=powiaz-' . $wynik));
        exit;
    }

    /**
     * KASOWANIE przejazdu solo (zgłoszenie usera, 2026-08-26: „jedyna akcja
     * jaka może zostać podjęta to przypisanie trasy z wyjazdem, powinienem
     * mieć więcej możliwości"). Kontroler znów cienki — anty-IDOR i sama
     * kasacja siedzą w `RiderActivity::deleteSoloForUser`.
     *
     * Stan listy (szukanie, strona) wraca ukrytymi polami `wroc_q`/`wroc_strona`
     * — usunięcie wiersza nie ma cofać na początek nieprzefiltrowanej listy,
     * ten sam powód co przy `KnownRouteController`.
     */
    public static function delete(string $id): void
    {
        Auth::requireLogin();
        $user = Auth::user();

        $wroc = array_filter([
            'q'      => trim((string) ($_POST['wroc_q'] ?? '')),
            'strona' => (int) ($_POST['wroc_strona'] ?? 0) > 1 ? (string) (int) $_POST['wroc_strona'] : '',
        ], static fn(string $v): bool => $v !== '');
        $back = fn(string $param) => View::url('/admin/moje-przejazdy')
            . '?' . http_build_query(array_merge(['tab' => 'solo'], $wroc)) . '&' . $param;

        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            header('Location: ' . $back('blad=sesja'));
            exit;
        }

        $ok = RiderActivity::deleteSoloForUser((int) $id, $user->id);

        header('Location: ' . $back($ok ? 'info=solo-usuniety' : 'blad=solo-brak'));
        exit;
    }
}
