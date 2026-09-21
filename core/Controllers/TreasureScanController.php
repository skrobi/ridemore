<?php
// core/Controllers/TreasureScanController.php
// EKRAN SKANOWANIA SKARBU — /skarb/{code} (Etap 8D, SKA/5).
//
// Tu domyka się cała pętla: postawiony skarb → wydrukowana naklejka →
// zeskanowany kod → punkty w rejestrze. To jedyny ekran w serwisie, na który
// wchodzi się aparatem telefonu stojąc w lesie, i to przesądza o trzech
// rzeczach:
//
//   1. DWA KROKI, NIE JEDEN. Samo wejście pod adres NIE zalicza skarbu —
//      pokazuje ekran z przyciskiem. Gdyby zaliczało, wystarczyłoby otworzyć
//      link przysłany przez znajomego, a poza tym podgląd linku w komunikatorze
//      (który pobiera stronę w tle) zabierałby skarb bez wiedzy właściciela.
//   2. GEOLOKALIZACJA JEST DOBROWOLNA. Brak zgody nie blokuje — patrz
//      Models\Treasure::claim. Blokada wykluczyłaby wszystkich z wyłączonym
//      GPS-em, a to większa szkoda niż pożytek z domknięcia luki, której i tak
//      nie da się domknąć.
//   3. NIEZALOGOWANY WIDZI, CO ZNALAZŁ. Pokazujemy nazwę i kategorię, ale
//      punktów nie przyznajemy i KOD ZOSTAJE WAŻNY — po założeniu konta wraca
//      pod ten sam adres i odbiera. Odesłanie go z niczym byłoby karaniem
//      za to, że nie miał konta w chwili, gdy stał przed naklejką.
namespace Controllers;

use Core\Auth;
use Core\Csrf;
use Models\Treasure;
use Models\TreasurePhoto;
use Utils\Upload;
use Utils\View;

class TreasureScanController
{
    /** Ekran skarbu — bez zaliczania. */
    public static function show(string $code): void
    {
        self::render($code, null);
    }

    /** Odbiór skarbu — dopiero to nalicza punkty. */
    public static function claim(string $code): void
    {
        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            self::respond($code, ['ok' => false, 'reason' => 'sesja', 'points' => 0]);
            return;
        }

        $user = Auth::user();
        if ($user === null) {
            self::respond($code, ['ok' => false, 'reason' => 'zaloguj', 'points' => 0]);
            return;
        }

        // Współrzędne przychodzą z przeglądarki i mogą nie przyjść wcale.
        $lat = isset($_POST['lat']) && $_POST['lat'] !== '' ? (float) $_POST['lat'] : null;
        $lon = isset($_POST['lon']) && $_POST['lon'] !== '' ? (float) $_POST['lon'] : null;

        self::respond($code, Treasure::claim($code, $user->id, $lat, $lon, 'QR'));
    }

    /**
     * JSON dla KOLEJKI OFFLINE (Etap 7 przebudowy apki, 2026-08-28) — apka
     * wysyła zaległy skan po powrocie zasięgu W TLE (assets/js/native.js),
     * bez pokazywania tej strony użytkownikowi, więc potrzebuje wyniku, który
     * da się odczytać bez parsowania HTML-a. Zwykły formularz („Odbierz" na
     * tej stronie) nigdy nie wysyła `Accept: application/json` — dostaje
     * dokładnie to, co zawsze, pełną stronę. Zero zmiany istniejącego
     * zachowania, czysta gałąź obok.
     */
    private static function respond(string $code, array $result): void
    {
        if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
            // headers_sent() w prawdziwym żądaniu jest zawsze fałszywe (to
            // pierwsza rzecz, którą kontroler wypisuje) — strażnik istnieje
            // pod testy kontrolera w CLI, gdzie wcześniejsze testy w tym
            // samym procesie już coś wypisały i header() rzucałby ostrzeżenie
            // prosto do treści odpowiedzi.
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode([
                'ok'     => (bool) ($result['ok'] ?? false),
                'reason' => $result['reason'] ?? null,
                'points' => (int) ($result['points'] ?? 0),
                'name'   => $result['treasure']['name'] ?? null,
            ], JSON_UNESCAPED_UNICODE);
            return;
        }
        self::render($code, $result);
    }

    /**
     * ZNALAZCA DORZUCA ZDJĘCIE (SKA/14, decyzja usera 2026-08-22:
     * „admin + znalazcy").
     *
     * TRZY WARUNKI, KAŻDY Z INNEGO POWODU:
     *   1. zalogowany — bez konta nie ma czego zapisać w `uploaded_by`,
     *      a bez autora nie da się ani nałożyć limitu, ani nikogo rozliczyć;
     *   2. ZNALAZŁ TEN SKARB — to jest sedno. Zdjęcie skarbu ukrytego jest
     *      najmocniejszym spoilerem w tym module, więc dorzucić je może tylko
     *      ktoś, kto i tak już wie, jak to miejsce wygląda. Przy okazji
     *      załatwia to jakość: zdjęcie robi ten, kto tam był;
     *   3. limit na osobę — żeby jedna osoba nie zapełniła galerii sobą.
     *
     * Bez kolejki moderacyjnej — zdjęcia z relacji z wyjazdów też są publiczne
     * od razu. Dwa różne obiegi tej samej rzeczy w jednym serwisie byłyby
     * gorsze niż brak kolejki; admin kasuje pojedyncze zdjęcie.
     */
    public static function addPhoto(string $code): void
    {
        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            self::render($code, null, 'Sesja wygasła — spróbuj jeszcze raz.');
            return;
        }
        $user = Auth::user();
        $treasure = Treasure::findByCode($code);
        if ($user === null || $treasure === null) {
            self::render($code, null, 'Zaloguj się, żeby dodać zdjęcie.');
            return;
        }

        $treasureId = (int) $treasure['id'];
        if (!Treasure::foundBy($treasureId, $user->id)) {
            // Ten sam komunikat co przy braku pliku — nie tłumaczymy komuś,
            // kto tu trafił z cudzego linku, że zdjęcia są nagrodą za
            // znalezienie. Kto znalazł, ten widzi formularz.
            self::render($code, null, 'Zdjęcie może dodać osoba, która ten skarb znalazła.');
            return;
        }

        $juzMa = TreasurePhoto::countForUser($treasureId, $user->id);
        $zostalo = TreasurePhoto::PER_USER_LIMIT - $juzMa;
        if ($zostalo < 1) {
            self::render($code, null, 'Masz już ' . TreasurePhoto::PER_USER_LIMIT
                . ' zdjęcia przy tym skarbie — to limit na osobę.');
            return;
        }

        $bledy = [];
        $adresy = Upload::saveGalleryPhotos($_FILES['photos'] ?? [], $zostalo, 'gallery', $bledy);
        if (!$adresy) {
            self::render($code, null, $bledy ? implode(' · ', $bledy) : 'Nie wybrano zdjęcia.');
            return;
        }
        TreasurePhoto::add($treasureId, $adresy, $user->id);

        self::render($code, null, null, count($adresy) . (count($adresy) === 1
            ? ' zdjęcie dodane. Dzięki!' : ' zdjęcia dodane. Dzięki!'));
    }

    /**
     * ZNALAZCA KASUJE SWOJE ZDJĘCIE. Tylko swoje — cudze kasuje admin.
     */
    public static function deletePhoto(string $code): void
    {
        $user = Auth::user();
        $photo = TreasurePhoto::find((int) ($_POST['photo_id'] ?? 0));
        if (!Csrf::check($_POST['csrf_token'] ?? null) || $user === null || $photo === null) {
            self::render($code, null, 'Nie udało się usunąć zdjęcia.');
            return;
        }
        if ((int) ($photo['uploaded_by'] ?? 0) !== $user->id) {
            self::render($code, null, 'To nie jest Twoje zdjęcie.');
            return;
        }
        TreasurePhoto::delete((int) $photo['id']);
        self::render($code, null, null, 'Zdjęcie usunięte.');
    }

    /**
     * @param array|null $result wynik Treasure::claim albo null przed próbą
     * @param string|null $photoError  komunikat błędu przy dodawaniu zdjęcia
     * @param string|null $photoNotice potwierdzenie udanej operacji na zdjęciach
     */
    private static function render(string $code, ?array $result, ?string $photoError = null, ?string $photoNotice = null): void
    {
        $viewer = Auth::user();
        $treasure = $result['treasure'] ?? Treasure::findByCode($code);

        // Nieznany kod dostaje 404 — nie chcemy, żeby zgadywanie adresów
        // dawało cokolwiek poza tą samą, pustą odpowiedzią.
        if ($treasure === null && ($result['reason'] ?? '') !== 'nieznana') {
            http_response_code(404);
        }

        // Nazwa, opis i podpowiedź w języku strony — dopiero PO decyzji, co widz
        // w ogóle może zobaczyć (tłumaczymy wyłącznie to, co i tak by się pokazało).
        if ($treasure) {
            $trSkarb = \Models\ContentTranslation::fields([
                'name'        => $treasure['name'] ?? null,
                'description' => $treasure['description'] ?? null,
                'hint'        => $treasure['hint'] ?? null,
            ], 'treasure:' . (int) $treasure['id']);
            foreach ($trSkarb as $pole => $wartosc) {
                if (array_key_exists($pole, $treasure)) {
                    $treasure[$pole] = $wartosc;
                }
            }
            $treasure['translation'] = \Models\ContentTranslation::meta('treasure:' . (int) $treasure['id']);
        }

        $collection = ($treasure && $viewer)
            ? Treasure::collectionProgress($viewer->id, $treasure['region_item_id'] ? (int) $treasure['region_item_id'] : null)
            : ['found' => 0, 'total' => 0, 'label' => null];

        // KTO WIDZI GALERIE (SKA/14) — ta sama regula, ktora `Treasure::reveal()`
        // stosuje do `photo_url` na mapie, przelozona na ten ekran:
        //   skarb JAWNY (reveal_level 2)  — galeria dla kazdego,
        //   TROP i UKRYTY                 — dopiero po ZNALEZIENIU.
        // Powtarzamy ja tu, bo ekran spod QR nie idzie przez `inBounds`; gdyby
        // kiedys doszedl trzeci ekran ze zdjeciami, ta regula powinna wyladowac
        // we wspolnej metodzie zamiast w trzeciej kopii.
        $znalazl = ($treasure && $viewer) ? Treasure::foundBy((int) $treasure['id'], $viewer->id) : false;
        $jawny   = $treasure && (int) ($treasure['reveal_level'] ?? 2) >= 2;
        $galeria = ($treasure && ($jawny || $znalazl))
            ? TreasurePhoto::forTreasure((int) $treasure['id'])
            : [];
        // Dorzucic zdjecie moze WYLACZNIE znalazca i tylko w ramach limitu.
        $mozeDodac = $znalazl && $viewer !== null
            && TreasurePhoto::countForUser((int) $treasure['id'], $viewer->id) < TreasurePhoto::PER_USER_LIMIT;

        View::render('web', 'treasure-scan', [
            'title'      => $treasure ? __('Skarb: {nazwa} — ridemore.bike', ['nazwa' => $treasure['name']]) : __('Skarb — ridemore.bike'),
            // Ekran skanowania nie ma czego robić w wyszukiwarce: adres jest
            // sekretem, a strona ma sens wyłącznie z telefonu w terenie.
            'noindex'    => true,
            'code'       => $code,
            'treasure'   => $treasure,
            'result'     => $result,
            'isLoggedIn' => $viewer !== null,
            'collection' => $collection,
            'finders'    => $treasure ? Treasure::findersCount((int) $treasure['id']) : 0,
            // GALERIA (SKA/14). Widoczna WYLACZNIE dla znalazcy przy skarbie
            // Tropie/Ukrytym — ta sama regula co dla `photo_url`, tylko liczona
            // tutaj, bo ten ekran nie idzie przez `Treasure::inBounds`.
            // Skarb JAWNY (reveal_level 2) pokazuje galerie kazdemu.
            'photos'      => $galeria,
            'canAddPhoto' => $mozeDodac,
            'photoLimit'  => TreasurePhoto::PER_USER_LIMIT,
            'photoError'  => $photoError,
            'photoNotice' => $photoNotice,
            'breadcrumbs' => [Support::homeCrumb(), ['label' => __('Skarb')]],
        ]);
    }
}
