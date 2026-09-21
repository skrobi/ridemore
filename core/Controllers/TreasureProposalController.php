<?php
// core/Controllers/TreasureProposalController.php
// ZGŁOSZENIE SKARBU PRZEZ ROWERZYSTĘ (Etap 8D, SKA/4).
//
// To NIE jest okrojony panel admina, tylko inny formularz do innego zadania.
// Admin stawia punkt i od razu decyduje o jego wartości, zasięgu, rzadkości
// i widoczności. Rowerzysta zgłasza MIEJSCE — mówi „tu coś jest, warto tu
// podjechać" — i to wszystko, czego od niego chcemy.
//
// Dlatego formularz ma pięć pól zamiast dwunastu. Reszta ustawia się sama:
//   status  = PROPOSED   (czeka na potwierdzenia, patrz SKA/3)
//   origin  = COMMUNITY  (widać, skąd przyszedł)
//   points  = 0          (zgodnie z modelem: zaczyna od zera i zyskuje)
//   reveal  = 2          (domyślnie jawny — zgłaszający może wybrać Trop lub
//                         Ukryty, ale Jawny jest bezpiecznym startem)
//
// Żadnego z tych pól NIE bierzemy z POST-a (poza reveal_level, który jest
// wybieralny w formularzu). Gdyby przyszły z formularza inne wartości,
// wystarczyłoby podmienić je w narzędziach przeglądarki, żeby zgłosić sobie
// aktywny skarb za 800 punktów.
namespace Controllers;

use Core\Auth;
use Core\Csrf;
use Models\Dictionary;
use Models\MapLayer;
use Models\TileCache;
use Models\TileSource;
use Models\Treasure;
use Utils\Upload;
use Utils\View;

class TreasureProposalController
{
    public static function form(): void
    {
        Auth::requireLogin();

        // ISTNIEJĄCE SKARBY NA MAPIE — warstwa pokazująca wszystkie punkty, żeby
        // zgłaszający nie proponował duplikatów. Pełne współrzędne, bo to Wejście
        // autoryzowane (w odróżnieniu od publicznego /api/treasures, które przycina
        // pozycję ukrytych skarbów do środka pola).
        // Liczone RAZ — trafia i do widoku (lista „w poczekalni"), i do bramki
        // limitu niżej. Dwa osobne zapytania o to samo rozjechałyby się przy
        // pierwszej zmianie warunku.
        $moje = Treasure::proposedBy(Auth::user()->id);

        $allTreasures = Treasure::all(true);
        $treasurePins = array_map(static function (array $t) {
            return [
                'id'    => (int) $t['id'],
                'lat'   => (float) $t['lat'],
                'lon'   => (float) $t['lon'],
                'name'  => $t['name'],
                'cat'   => $t['category_label'] ?? '',
                'rarity'=> $t['rarity'] ?? 'COMMON',
                'status'=> $t['status'] ?? 'ACTIVE',
            ];
        }, $allTreasures);

        // PEŁNOEKRANOWY SZABLON W APCE (Faza 3 przebudowy UX apki, 2026-08-29) —
        // ten sam wzorzec co DiscoveryController::index(): kontroler wybiera
        // szablon na podstawie APP_IS_APP, dane zostają te same.
        // `mine` (zgłoszenia w poczekalni) świadomie pomijane w apce — patrz
        // nagłówek treasure-propose-app.php.
        // WARSTWY KONTEKSTOWE (Etap 4 `tasks/done/warstwy-mapy.md`, decyzja
        // usera 2026-08-29: „kontrolka tak, silnik nie"). Do tej pory ekran miał
        // kontrolkę z JEDNĄ pozycją wpisaną wprost w szablonie („Istniejące
        // skarby"), więc zgłaszający nie widział ani mgły odkryć, ani tras —
        // a to one mówią, czy miejsce w ogóle bywa odwiedzane.
        //
        // `only` BEZ węzła „Skarby": ten ekran rysuje istniejące skarby sam,
        // własną warstwą antyduplikatową, i to ona zostaje pod tą nazwą
        // w kontrolce. Dokładnie ta sama zasada co w panelu admina.
        $mapLayers = MapLayer::tree('all', [
            'loggedIn' => true,
            'only'     => ['cells', 'heat', 'slady', 'trails'],
        ]);
        $mapSources = [];
        foreach (MapLayer::tileKeysFor($mapLayers) as $layerKey => $trackKey) {
            $mapSources[$layerKey] = TileCache::urlTemplate(TileSource::LAYER_TRACKS, $trackKey);
        }

        View::render('web', APP_IS_APP ? 'treasure-propose-app' : 'treasure-propose', [
            'mapLayers'     => $mapLayers,
            'mapSources'    => $mapSources,
            'title'         => __('Zgłoś skarb — ridemore.bike'),
            'noindex'       => true,
            'bodyClass'     => APP_IS_APP ? 'map-page' : null,
            'categories'    => Dictionary::items('treasure_category'),
            'mine'          => $moje,
            'needed'        => Treasure::confirmationsNeeded(),
            // LIMIT ZNANY PRZED WYPEŁNIENIEM, nie po wysłaniu (2026-08-29).
            // `save()` odrzuca nadmiarowe zgłoszenie przekierowaniem, które
            // gubi CAŁY formularz — a w apce razem z nim ZDJĘCIE zrobione
            // przed chwilą w terenie, którego nie da się odtworzyć spod domu.
            // Ta sama liczba, ta sama zasada, tylko policzona o jeden ekran
            // wcześniej: skoro wiemy, że nie przyjmiemy, mówimy to zanim
            // człowiek cokolwiek włoży.
            'limitOsiagniety' => count($moje) >= self::MAX_OPEN,
            'maxOpen'         => self::MAX_OPEN,
            'treasurePins'  => $treasurePins,
            'info'          => match ($_GET['info'] ?? '') {
                'zapisane'    => __('Zgłoszenie przyjęte. Pokaże się na mapie jako punkt do potwierdzenia.'),
                'brak-danych' => __('Brakuje nazwy albo miejsca na mapie.'),
                'za-duzo'     => __('Masz już kilka zgłoszeń w poczekalni. Poczekaj, aż ktoś je potwierdzi.'),
                default       => null,
            },
            'extraHead'     => Support::gpxMapHead(),
            'breadcrumbs'   => [
                Support::homeCrumb(),
                ['label' => __('Odkrycia'), 'url' => View::url('/odkrycia')],
                ['label' => __('Zgłoś skarb')],
            ],
        ]);
    }

    public static function save(): void
    {
        Auth::requireLogin();
        $user = Auth::user();

        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            self::back();
            return;
        }

        $lat = self::coord($_POST['lat'] ?? null);
        $lon = self::coord($_POST['lon'] ?? null);
        $name = trim((string) ($_POST['name'] ?? ''));
        $reveal = in_array((int) ($_POST['reveal_level'] ?? 2), [0, 1, 2], true)
            ? (int) $_POST['reveal_level'] : 2;

        if ($lat === null || $lon === null || $name === '') {
            self::back('brak-danych');
            return;
        }

        // LIMIT OTWARTYCH ZGŁOSZEŃ NA OSOBĘ. Nie chodzi o złośliwców — tych
        // zatrzymuje próg potwierdzeń, bo ich punkty i tak nigdy nie ruszą
        // z poczekalni. Chodzi o zapał: ktoś przegląda mapę wieczorem i zgłasza
        // czterdzieści miejsc z pamięci, po czym poczekalnia admina przestaje
        // być czytelna, a ludzie w terenie i tak nie zdążą tego potwierdzić.
        // Limit wymusza rytm „zgłaszam to, przy czym faktycznie bywam".
        if (count(Treasure::proposedBy($user->id)) >= self::MAX_OPEN) {
            self::back('za-duzo');
            return;
        }

        // ZDJĘCIE ZE ZGŁOSZENIA (2026-08-29, kontrakt tasks/done/zglos-skarb-w-terenie.md).
        // Kolumna `treasures.photo_url` istnieje od początku modułu i
        // `Treasure::save()` ją zapisuje — brakowało wyłącznie pola w formularzu
        // i tej jednej linijki. ZERO migracji, zero nowej tabeli.
        //
        // Ten sam helper co okładka skarbu w panelu admina i okładka wydarzenia:
        // limity, dozwolone formaty i skalowanie są tam już rozstrzygnięte.
        // Zwraca null przy braku pliku, więc zgłoszenie bez zdjęcia przechodzi
        // jak dotąd — pole jest opcjonalne.
        //
        // UWAGA NA PRZYSZŁOŚĆ: `Treasure::reveal()` zeruje `photo_url` dla
        // Tropu (1) i Ukrytego (0), żeby zdjęcie nie zdradzało zagadki. Zdjęcie
        // zapisze się więc do bazy, ale zobaczy je dopiero ten, kto odkryje
        // punkt. To poprawne — ta sama bramka co wszędzie — i dlatego formularz
        // uprzedza o tym jednym zdaniem.
        $photoUrl = null;
        if (!empty($_FILES['photo']['name'])) {
            $photoUrl = Upload::saveCoverPhoto($_FILES['photo']);
        }

        Treasure::save(null, [
            'name'             => mb_substr($name, 0, 160),
            'description'      => trim((string) ($_POST['description'] ?? '')) ?: null,
            'photo_url'        => $photoUrl,
            'hint'             => null,
            'category_item_id' => (int) ($_POST['category_item_id'] ?? 0) ?: null,
            'region_item_id'   => Treasure::guessRegion($lat, $lon),
            'lat'              => $lat,
            'lon'              => $lon,
            // Wartości narzucone — patrz komentarz na górze pliku.
            'points'           => 0,
            'claim_radius_m'   => Treasure::defaultRadius(),
            'rarity'           => 'COMMON',
            'origin'           => 'COMMUNITY',
            'status'           => 'PROPOSED',
            'reveal_level'     => $reveal,
            'is_active'        => 1,
        ], $user->id);

        self::back('zapisane');
    }

    /** Ile zgłoszeń jednej osoby może naraz czekać na potwierdzenia. */
    private const MAX_OPEN = 5;

    /** Przecinek na kropkę — polska klawiatura numeryczna daje przecinek. */
    private static function coord($raw): ?float
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        $value = (float) str_replace(',', '.', $raw);
        return ($value >= -90 && $value <= 180 && $value != 0.0) ? $value : null;
    }

    private static function back(?string $info = null): void
    {
        header('Location: ' . View::url('/skarby/zglos') . ($info ? '?info=' . $info : ''));
        exit;
    }
}
