<?php
// core/Models/GarminImport.php
// POBIERANIE PRZEJAZDÓW Z GARMIN CONNECT (migr. 068).
//
// TEN MODEL NICZEGO NIE LICZY. Pobiera listę aktywności rowerowych, ściąga
// ślad i woła RiderActivity::recordSolo — TĘ SAMĄ drogę, którą przechodzi
// ręcznie wgrany plik i import archiwum (Models\ArchiveImport). Odkrycia pól,
// punkty, skarby po drodze i prywatność (przycięcie okolic domu, §27) dzieją
// się tam, raz. Gdyby Garmin jutro przestał działać, nie tracimy nic poza
// wygodą wejścia.
//
// „SPRAWDZAJ, CZY JUŻ TAKICH NIE MA" — DWIE NIEZALEŻNE WARSTWY:
//   1. Rejestr `device_activities` (wspólny dla wszystkich liczników, migr. 069)
//      odsiewa aktywności, które już przeszły tędy
//      — po identyfikatorze z Garmina, ZANIM cokolwiek pobierzemy. Dzięki temu
//      na liście widać wyłącznie nowe przejazdy, a nie całą historię konta.
//   2. UNIQUE (user_id, gpx_hash) w `rider_activities` łapie ten sam ślad
//      wgrany wcześniej ręcznie albo z archiwum — czyli przypadek, którego
//      rejestr nie ma prawa znać. Bez tego ktoś, kto najpierw wgrał plik
//      z licznika, a potem podłączył Garmina, policzyłby tę rundę dwa razy.
namespace Models;

use Utils\GarminBridge;
use Utils\Upload;

class GarminImport
{
    /**
     * Ile SUROWYCH aktywności przeglądamy przy jednym kliknięciu.
     *
     * BŁĄD ZGŁOSZONY PRZEZ USERA (2026-08-24): „sprawdzono 16 aktywności, nie ma
     * nic nowego, a ja mam pod 300 u siebie w Garminie". Stało tu 50 — i była to
     * liczba aktywności WSZYSTKICH dyscyplin. Po odsianiu do roweru zostawało
     * 16, akurat tych zaimportowanych poprzednim razem, więc odpowiedź brzmiała
     * „nic nowego", mimo że reszta historii czekała tuż za oknem.
     *
     * 300 to jedno kliknięcie na typową historię kilku lat, a nie sufit: głębiej
     * sięga `$start` (przycisk „Szukaj starszych"). Przeglądanie dzieje się
     * w JEDNYM procesie Pythona i na jednym logowaniu — trzy zapytania po 100.
     */
    public const LOOKBACK = 300;

    /**
     * Ile ściągamy na jedno kliknięcie. Każdy ślad to osobne zapytanie do
     * Garmina PLUS parsowanie kilku tysięcy punktów i liczenie pól siatki —
     * bez limitu żądanie kończy się timeoutem gdzieś w połowie. Z limitem
     * import jest PONAWIALNY: reszta zostaje na liście i czeka.
     */
    public const BATCH = 15;

    /**
     * Nowe aktywności rowerowe — te, których jeszcze tędy nie przepuściliśmy.
     *
     * @return array{items:list<array{id:string,name:string,startedAt:string,distanceKm:float}>,seen:int}
     * @throws \RuntimeException gdy most nie odpowiada
     */
    public static function candidates(int $userId, int $start = 0): array
    {
        $tokens = self::sessionToken($userId);
        if ($tokens === null) {
            throw new \RuntimeException(__('Brak połączenia z Garmin Connect.'));
        }

        $answer = GarminBridge::call('list', [
            'tokens' => $tokens,
            'scan'   => self::LOOKBACK,
            'start'  => max(0, $start),
        ]);
        if (($answer['ok'] ?? false) !== true) {
            throw new GarminBridgeError((string) ($answer['code'] ?? 'error'));
        }

        $activities = $answer['data']['activities'] ?? [];
        if (!empty($answer['data']['tokens'])) {
            DeviceConnection::refreshToken($userId, 'garmin', ['session' => (string) $answer['data']['tokens']]);
        }

        $known = DeviceImport::knownIds($userId, 'garmin');
        $items = [];
        foreach ($activities as $activity) {
            $id = (string) ($activity['id'] ?? '');
            if ($id === '' || !ctype_digit($id) || isset($known[$id])) {
                continue;
            }
            $items[] = [
                'id'         => $id,
                'name'       => (string) ($activity['name'] ?? ''),
                'startedAt'  => (string) ($activity['startedAt'] ?? ''),
                'distanceKm' => (float) ($activity['distanceKm'] ?? 0),
            ];
        }

        // TRZY RÓŻNE LICZBY, bo mówią o trzech różnych rzeczach i mylenie ich
        // było właśnie tym błędem: `scanned` to ile aktywności PRZEJRZELIŚMY
        // (wszystkich dyscyplin), `seen` ile z nich było rowerowych, a `items`
        // ile z tych rowerowych jest dla nas nowych.
        $scanned = (int) ($answer['data']['scanned'] ?? count($activities));

        return [
            'items'   => $items,
            'seen'    => count($activities),
            'scanned' => $scanned,
            'start'   => max(0, $start),
            // Skąd zacząć, gdy człowiek poprosi o starsze. Równe zero znaczy
            // „to już koniec historii" — Garmin oddał mniej, niż pytaliśmy.
            'next'    => $scanned >= self::LOOKBACK ? max(0, $start) + $scanned : 0,
        ];
    }

    /**
     * Ściąga wskazane aktywności i robi z nich przejazdy solo.
     *
     * @param  list<string> $activityIds
     * @param  array<string,array{name?:string,startedAt?:string,distanceKm?:float}> $meta z listy pobranej z Garmina
     * @param  ?callable $onProgress opcjonalny odbiorca żywego statusu (Utils\ProgressStream) —
     *         null poza trybem strumieniowym, patrz GarminController::import()
     * @return array{dodane:int,duplikaty:int,odrzucone:int,pozostalo:int,pola:int}
     */
    public static function import(int $userId, array $activityIds, array $meta = [], ?callable $onProgress = null): array
    {
        $tokens = self::sessionToken($userId);
        if ($tokens === null) {
            throw new \RuntimeException(__('Brak połączenia z Garmin Connect.'));
        }

        // Odsiewamy jeszcze raz po rejestrze: między pobraniem listy a kliknięciem
        // „Zaimportuj" mogło minąć dużo czasu (albo druga karta przeglądarki).
        $known = DeviceImport::knownIds($userId, 'garmin');
        $ids = [];
        foreach ($activityIds as $id) {
            $id = (string) $id;
            if (ctype_digit($id) && !isset($known[$id]) && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        $partia = array_slice($ids, 0, self::BATCH);
        $dodane = 0;
        $duplikaty = 0;
        $odrzucone = 0;
        $pola = 0;

        // CAŁA PARTIA JEDNYM WYWOŁANIEM MOSTU (2026-08-24). Każde wywołanie to
        // nowy proces Pythona i nowe logowanie do Garmina — piętnaście osobnych
        // znaczyło kilkadziesiąt sekund i piętnaście logowań pod rząd, czyli
        // prośbę o blokadę po ich stronie. Teraz jedno logowanie obsługuje
        // wszystkie pliki, a błąd pojedynczej aktywności wraca w `errors`.
        if (!$partia) {
            return ['dodane' => 0, 'duplikaty' => 0, 'odrzucone' => 0, 'pozostalo' => 0, 'pola' => 0];
        }

        $total = count($partia);
        // Ten most nie da się rozbić na zdarzenia per aktywność (patrz komentarz
        // wyżej — jedno logowanie, jedno wywołanie) — panel dostaje więc JEDNO
        // zdarzenie „ściągamy z Garmina" na całą partię, a żywy licznik startuje
        // dopiero w pętli niżej, na etapie, który faktycznie da się mierzyć.
        if ($onProgress) {
            $onProgress(['type' => 'batch', 'stage' => 'downloading', 'total' => $total]);
        }

        $answer = GarminBridge::call('download', ['tokens' => $tokens, 'activityIds' => $partia]);
        if (($answer['ok'] ?? false) !== true) {
            throw new GarminBridgeError((string) ($answer['code'] ?? 'error'));
        }
        $pliki = $answer['data']['files'] ?? [];
        $bledy = $answer['data']['errors'] ?? [];

        foreach ($partia as $i => $id) {
            $zgloszStatus = static function (string $status) use ($onProgress, $id, $meta, $i, $total): void {
                if ($onProgress) {
                    $onProgress([
                        'type'   => 'item',
                        'stage'  => 'done',
                        'id'     => $id,
                        'name'   => (string) ($meta[$id]['name'] ?? ''),
                        'status' => $status,
                        'done'   => $i + 1,
                        'total'  => $total,
                    ]);
                }
            };

            try {
                $gpx = (string) ($pliki[$id] ?? '');
                if ($gpx === '') {
                    // Aktywność bez śladu (trenażer, trening ręczny) albo chwilowa
                    // odmowa — jedna nieudana nie może przerwać całej partii.
                    error_log('Garmin import (' . $id . '): ' . ($bledy[$id] ?? 'brak pliku'));
                    $odrzucone++;
                    DeviceImport::remember($userId, 'garmin', $id, 'rejected', null, $meta[$id] ?? null);
                    $zgloszStatus('rejected');
                    continue;
                }

                $gpxUrl = Upload::saveGpxContents($gpx);
                if ($gpxUrl === null) {
                    $odrzucone++;
                    DeviceImport::remember($userId, 'garmin', $id, 'rejected', null, $meta[$id] ?? null);
                    $zgloszStatus('rejected');
                    continue;
                }

                $wynik = RiderActivity::recordSolo($userId, CORE_PATH . '/..' . $gpxUrl, $gpxUrl);
                if ($wynik === null) {
                    // recordSolo zwraca null i przy powtórce tego samego pliku,
                    // i przy śladzie, z którego nie zostało ani jedno pole
                    // (np. po przycięciu okolic domu). Kasujemy świeżą kopię,
                    // żeby nie została na dysku bez niczego, co by na nią wskazywało.
                    @unlink(CORE_PATH . '/..' . $gpxUrl);
                    $duplikaty++;
                    DeviceImport::remember($userId, 'garmin', $id, 'duplicate', null, $meta[$id] ?? null);
                    $zgloszStatus('duplicate');
                    continue;
                }

                $dodane++;
                $pola += (int) $wynik['cellsNew'];
                DeviceImport::remember($userId, 'garmin', $id, 'imported', (int) $wynik['activityId'], $meta[$id] ?? null);
                $zgloszStatus('imported');
            } catch (\RuntimeException $e) {
                // Gpx::parse rzuca przy śladzie za krótkim/bez punktów — to jest
                // odrzucenie tej aktywności, nie awaria importu.
                error_log('Garmin import (' . $id . '): ' . $e->getMessage());
                $odrzucone++;
                DeviceImport::remember($userId, 'garmin', $id, 'rejected', null, $meta[$id] ?? null);
                $zgloszStatus('rejected');
            }
        }

        DeviceConnection::refreshToken($userId, 'garmin', ['session' => $tokens]);

        return [
            'dodane'     => $dodane,
            'duplikaty'  => $duplikaty,
            'odrzucone'  => $odrzucone,
            'pozostalo'  => max(0, count($ids) - count($partia)),
            'pola'       => $pola,
        ];
    }

    /**
     * Token sesji Garmina z połączenia (migr. 069: wspólna tabela dostawców).
     *
     * Garmin jest tu wyjątkiem — nie ma OAuth, więc jego token sesji siedzi
     * pod kluczem `session` w tym samym, zaszyfrowanym polu, w którym pozostali
     * dostawcy trzymają komplet OAuth. Rejestr „już to mamy" jest wspólny
     * (Models\DeviceImport), bo pytanie „czy tę aktywność już wciągnęliśmy"
     * nie zależy od tego, z czyjego serwera przyszła.
     */
    private static function sessionToken(int $userId): ?string
    {
        $token = DeviceConnection::token($userId, 'garmin');
        if (!is_array($token)) {
            return null;
        }
        if (isset($token['session']) && $token['session'] !== '') {
            return (string) $token['session'];
        }

        // ZGODNOŚĆ WSTECZ Z MIGRACJĄ 069 (błąd znaleziony u usera 2026-08-24:
        // „Połączenie nie zadziałało" tuż po migracji, mimo podłączonego konta).
        // Do 069 w kolumnie leżał SUROWY token Garmina (`{"di_token": …}`),
        // a od niej leży koperta `{"session": …}`. Przeniesione wiersze mają
        // więc kształt, którego nowy odczyt nie rozpoznaje — i połączenie
        // wyglądało na nieistniejące. Migracja skopiowała szyfrogramy jeden do
        // jednego i nie mogła ich przepakować (klucz jest w konfiguracji, nie
        // w bazie), więc rozpoznajemy stary kształt TUTAJ, po polu `di_token`.
        // Kosztuje jedną gałąź i oszczędza „odłącz i połącz ponownie" każdemu,
        // kto miał podpięte konto przed migracją.
        if (isset($token['di_token'])) {
            return json_encode($token, JSON_UNESCAPED_UNICODE);
        }

        return null;
    }
}
