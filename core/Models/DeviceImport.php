<?php
// core/Models/DeviceImport.php
// POBIERANIE PRZEJAZDÓW Z LICZNIKA PRZEZ OAuth (migr. 069) — Polar, Wahoo.
//
// TEN MODEL NICZEGO NIE LICZY, dokładnie jak GarminImport: pobiera listę,
// ściąga ślad i woła RiderActivity::recordSolo — TĘ SAMĄ drogę, którą przechodzi
// ręcznie wgrany plik. Odkrycia pól, punkty, skarby po drodze i prywatność
// (przycięcie okolic domu, §27) dzieją się tam, raz i dla wszystkich źródeł.
//
// REJESTR „JUŻ TO MAMY" JEST WSPÓLNY (`device_activities` z kolumną `provider`),
// więc te same dwie warstwy ochrony co przy Garminie działają dla każdego
// dostawcy:
//   1. rejestr odsiewa po identyfikatorze z API, ZANIM cokolwiek pobierzemy,
//   2. UNIQUE (user_id, gpx_hash) w `rider_activities` łapie ten sam ślad
//      wgrany wcześniej ręcznie — czyli przypadek, o którym rejestr nie ma
//      prawa wiedzieć. To jest realny scenariusz przy dwóch podpiętych
//      licznikach albo przy Wahoo i pliku z tego samego przejazdu.
namespace Models;

use Utils\DeviceApi;
use Utils\Fit;
use Utils\Format;
use Utils\MailTemplate;
use Utils\Upload;
use Utils\View;

class DeviceImport
{
    /** Ile aktywności bierzemy do przejrzenia (zasięg listy „co nowego"). */
    public const LOOKBACK = 50;

    /** Ile ściągamy na jedno kliknięcie — reszta zostaje na liście i czeka. */
    public const BATCH = 15;

    /**
     * Nowe aktywności — te, których jeszcze tędy nie przepuściliśmy.
     *
     * @return array{items:list<array<string,mixed>>,seen:int}
     */
    public static function candidates(int $userId, string $provider): array
    {
        [$token, $conn] = self::freshToken($userId, $provider);

        $activities = DeviceApi::listActivities($provider, $token, $conn['externalUserId'] ?? null, self::LOOKBACK);
        $known = self::knownIds($userId, $provider);

        $items = [];
        foreach ($activities as $activity) {
            $id = (string) ($activity['id'] ?? '');
            if ($id === '' || isset($known[$id])) {
                continue;
            }
            $items[] = $activity;
        }

        return ['items' => $items, 'seen' => count($activities)];
    }

    /**
     * Ściąga wskazane aktywności i robi z nich przejazdy solo.
     *
     * @param  list<string> $activityIds
     * @param  array<string,array<string,mixed>> $meta z listy pobranej wcześniej
     * @param  ?callable $onProgress opcjonalny odbiorca żywego statusu (Utils\ProgressStream) —
     *         null poza trybem strumieniowym, patrz DeviceController::import(). W odróżnieniu
     *         od Garmina każda aktywność to TU osobne zapytanie HTTP w PHP (bez mostu do
     *         Pythona), więc etap pobierania jest granularny naprawdę, nie tylko na papierze.
     * @return array{dodane:int,duplikaty:int,odrzucone:int,pozostalo:int,pola:int,
     *               przejazdy:list<array{sourceId:string,activityId:int,distanceKm:float,cellsNew:int}>}
     *         `przejazdy` — faktycznie dodane, pod powiadomienie automatu (migr. 088)
     */
    public static function import(int $userId, string $provider, array $activityIds, array $meta = [], ?callable $onProgress = null): array
    {
        [$token] = self::freshToken($userId, $provider);

        // Odsiewamy jeszcze raz po rejestrze: między pobraniem listy a kliknięciem
        // mogło minąć dużo czasu (albo druga karta przeglądarki).
        $known = self::knownIds($userId, $provider);
        $ids = [];
        foreach ($activityIds as $id) {
            $id = (string) $id;
            if ($id !== '' && !isset($known[$id]) && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        $partia = array_slice($ids, 0, self::BATCH);
        $total = count($partia);
        $dodane = 0;
        $duplikaty = 0;
        $odrzucone = 0;
        $pola = 0;
        $przejazdy = [];

        foreach ($partia as $i => $id) {
            // TU, w odróżnieniu od Garmina, sama próba ściągnięcia śladu JEST
            // osobnym zapytaniem HTTP w tej pętli — panel może więc pokazać
            // „pobieramy: Nazwa" naprawdę na czas trwania TEJ jednej aktywności,
            // a nie tylko na cały pakiet naraz.
            if ($onProgress) {
                $onProgress([
                    'type'  => 'item',
                    'stage' => 'downloading',
                    'id'    => $id,
                    'name'  => (string) ($meta[$id]['name'] ?? ''),
                    'done'  => $i,
                    'total' => $total,
                ]);
            }
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
                $plik = DeviceApi::downloadTrack($provider, $token, $id);
                $gpxUrl = self::saveTrack($plik, $meta[$id]['name'] ?? null);
                if ($gpxUrl === null) {
                    $odrzucone++;
                    self::remember($userId, $provider, $id, 'rejected', null, $meta[$id] ?? null);
                    $zgloszStatus('rejected');
                    continue;
                }

                $wynik = RiderActivity::recordSolo($userId, CORE_PATH . '/..' . $gpxUrl, $gpxUrl);
                if ($wynik === null) {
                    // Powtórka tego samego śladu albo ślad, z którego nie zostało
                    // ani jedno pole. Kasujemy świeżą kopię pliku, żeby nie
                    // zostawała na dysku bez niczego, co by na nią wskazywało.
                    @unlink(CORE_PATH . '/..' . $gpxUrl);
                    $duplikaty++;
                    self::remember($userId, $provider, $id, 'duplicate', null, $meta[$id] ?? null);
                    $zgloszStatus('duplicate');
                    continue;
                }

                $dodane++;
                $pola += (int) $wynik['cellsNew'];
                $przejazdy[] = [
                    'sourceId'   => $id,
                    'activityId' => (int) $wynik['activityId'],
                    'distanceKm' => (float) ($wynik['distanceKm'] ?? 0),
                    'cellsNew'   => (int) $wynik['cellsNew'],
                ];
                self::remember($userId, $provider, $id, 'imported', (int) $wynik['activityId'], $meta[$id] ?? null);
                $zgloszStatus('imported');
            } catch (\RuntimeException $e) {
                // Jedna aktywność bez pliku (trenażer, trening ręczny) albo ślad
                // za krótki dla parsera nie może przerwać całej partii.
                error_log('Import z ' . $provider . ' (' . $id . '): ' . $e->getMessage());
                $odrzucone++;
                self::remember($userId, $provider, $id, 'rejected', null, $meta[$id] ?? null);
                $zgloszStatus('rejected');
            }
        }

        return [
            'dodane'    => $dodane,
            'duplikaty' => $duplikaty,
            'odrzucone' => $odrzucone,
            'pozostalo' => max(0, count($ids) - count($partia)),
            'pola'      => $pola,
            'przejazdy' => $przejazdy,
        ];
    }

    /**
     * AUTOMATYCZNY IMPORT Z WEBHOOKA (migr. 088, 2026-09-14).
     *
     * Dostawca zgłosił nowy trening -> dla każdej osoby, która przy tym koncie
     * WŁĄCZYŁA automat, ta sama droga co „Pobierz zaznaczone" (import() niżej:
     * rejestr, recordSolo, przycięcie okolic domu §27) plus powiadomienie.
     * Nic tu nie liczy się inaczej niż przy imporcie z listy — różni się
     * wyłącznie tym, kto nacisnął przycisk.
     *
     * CZEGO AUTOMAT NIE ROBI, W ODRÓŻNIENIU OD LISTY: nie dodaje treningu, co
     * do którego nie ma pewności, że to rower z trasą (`DeviceApi::isCycling`).
     * Taki trening NIE trafia do rejestru, więc zostaje na liście „Pobierz
     * aktywności" — człowiek dalej może go dodać sam, a automat nie decyduje
     * za niego w sprawie, w której mógłby się pomylić.
     *
     * Błędy jednej osoby nie zatrzymują pozostałych i nie wracają do dostawcy:
     * odpowiedź na webhook poszła już wcześniej (patrz DeviceWebhookController).
     *
     * @param array{externalUserId:string,activityId:string,meta:?array} $event z DeviceApi::webhookEvent()
     * @return int ile przejazdów faktycznie dodano (łącznie, u wszystkich osób)
     */
    public static function fromWebhook(string $provider, array $event): int
    {
        $activityId = (string) $event['activityId'];
        $dodane = 0;

        foreach (DeviceConnection::autoImportUsers($provider, (string) $event['externalUserId']) as $userId) {
            try {
                // Wahoo potrafi przysłać to samo powiadomienie kilka razy
                // (ponowienia, aktualizacja pliku) — rejestr ucina to przed
                // jakimkolwiek zapytaniem do sieci.
                if (isset(self::knownIds($userId, $provider)[$activityId])) {
                    continue;
                }

                $meta = $event['meta'] ?? null;
                if ($meta === null) {
                    [$token] = self::freshToken($userId, $provider);
                    $meta = DeviceApi::activityDetails($provider, $token, $activityId);
                }
                if ($meta === null || !DeviceApi::isCycling($provider, $meta)) {
                    continue;
                }

                $wynik = self::import($userId, $provider, [$activityId], [$activityId => $meta]);
                foreach ($wynik['przejazdy'] as $przejazd) {
                    self::notifyImported($userId, $provider, $przejazd);
                    $dodane++;
                }
            } catch (\RuntimeException $e) {
                error_log('Automat ' . $provider . ' (' . $activityId . ', user ' . $userId . '): ' . $e->getMessage());
            }
        }

        return $dodane;
    }

    /**
     * Powiadomienie „dodaliśmy Twój przejazd" — przez bramkę, więc zgody per
     * kanał, wyłączniki z panelu i deduplikacja działają tu tak samo jak
     * wszędzie. Klucz identyfikuje TRENING u dostawcy: ponowiony webhook nie
     * wyśle drugiego powiadomienia nawet wtedy, gdyby rejestr go przepuścił.
     *
     * @param array{sourceId:string,activityId:int,distanceKm:float,cellsNew:int} $przejazd
     * @return array{push:bool,mail:bool}
     */
    public static function notifyImported(int $userId, string $provider, array $przejazd): array
    {
        $user = User::find($userId);
        if ($user === null) {
            return ['push' => false, 'mail' => false];
        }

        $pola = (int) $przejazd['cellsNew'];
        $dane = [
            'licznik' => DeviceApi::label($provider),
            'dystans' => Format::distance((float) $przejazd['distanceKm']),
            'pola'    => $pola . ' ' . Format::plural($pola, 'nowe pole', 'nowe pola', 'nowych pól'),
        ];
        $sciezka = '/przejazd/' . (int) $przejazd['activityId'];

        return \Core\Lang::with(\Models\User::langOf((int) ($userId)), static fn() => Notifier::wyslij(
            $userId,
            NotificationGate::PRZEJAZD_Z_LICZNIKA,
            'dev:' . $provider . ':' . $przejazd['sourceId'],
            [
                'title' => NotificationTexts::render('device_ride.push.title', $dane, [], true),
                'body'  => NotificationTexts::render('device_ride.push.body', $dane, [], true),
                'data'  => ['url' => View::url($sciezka)],
            ],
            [
                'to'       => (string) $user->email,
                'subject'  => NotificationTexts::render('device_ride.mail.subject', $dane, [], true),
                'template' => 'custom',
                'data'     => [
                    'bodyHtml' => NotificationTexts::render('device_ride.mail.body', $dane, [
                        'powitanie' => MailTemplate::greeting($user->displayName()),
                        'przycisk'  => MailTemplate::button(View::absoluteUrl($sciezka), __('Zobacz przejazd →')),
                    ]),
                ],
            ]
        ));
    }

    /**
     * Plik od dostawcy -> GPX na dysku.
     *
     * FIT konwertujemy TUTAJ, na wejściu (Utils\Fit), więc reszta serwisu
     * dostaje to samo, co przy wgraniu pliku ręcznie. Zwraca null, gdy z pliku
     * nie da się zrobić śladu — to jest odrzucenie tej jednej aktywności,
     * nie awaria importu.
     */
    private static function saveTrack(array $plik, ?string $nazwa): ?string
    {
        $bytes = (string) ($plik['bytes'] ?? '');
        if ($bytes === '') {
            return null;
        }

        if (($plik['format'] ?? 'gpx') === 'fit') {
            $bytes = Fit::toGpxFromString($bytes, $nazwa);
        }

        return Upload::saveGpxContents($bytes);
    }

    /**
     * Ważny token — z odświeżeniem, gdy wygasł.
     *
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    private static function freshToken(int $userId, string $provider): array
    {
        $conn = DeviceConnection::find($userId, $provider);
        $token = DeviceConnection::token($userId, $provider);
        if ($conn === null || $token === null) {
            throw new \RuntimeException('Brak połączenia z ' . DeviceApi::label($provider) . '.');
        }

        // Odświeżenie musi ZOSTAĆ ZAPISANE od razu: token po refreshu jest
        // jednorazowy u części dostawców, więc zgubienie go tutaj znaczyłoby
        // rozłączone konto przy następnym kliknięciu.
        $nowy = DeviceApi::refreshIfNeeded($provider, $token);
        if ($nowy !== null) {
            DeviceConnection::refreshToken($userId, $provider, $nowy);
            $token = $nowy;
        }

        return [$token, $conn];
    }

    /** @return array<string,true> identyfikatory już przepuszczone tędy */
    public static function knownIds(int $userId, string $provider): array
    {
        $stmt = \Core\Database::connection()->prepare(
            'SELECT activity_id FROM device_activities WHERE user_id = :u AND provider = :p'
        );
        $stmt->execute(['u' => $userId, 'p' => $provider]);

        $known = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            $known[(string) $id] = true;
        }

        return $known;
    }

    /**
     * Wpis do rejestru. INSERT IGNORE, bo wyścig dwóch kliknięć nie jest błędem —
     * liczy się to, że aktywność nie wróci na listę jako nowa.
     */
    public static function remember(
        int $userId,
        string $provider,
        string $activityId,
        string $status,
        ?int $riderActivityId,
        ?array $meta
    ): void {
        $stmt = \Core\Database::connection()->prepare(
            'INSERT IGNORE INTO device_activities
                 (user_id, provider, activity_id, status, rider_activity_id,
                  activity_name, started_at, distance_km)
             VALUES (:u, :p, :a, :s, :r, :n, :d, :km)'
        );
        $stmt->execute([
            'u'  => $userId,
            'p'  => $provider,
            'a'  => $activityId,
            's'  => $status,
            'r'  => $riderActivityId,
            'n'  => isset($meta['name']) ? mb_substr((string) $meta['name'], 0, 190) : null,
            'd'  => !empty($meta['startedAt']) ? str_replace('T', ' ', (string) $meta['startedAt']) : null,
            'km' => isset($meta['distanceKm']) ? (float) $meta['distanceKm'] : null,
        ]);
    }
}
