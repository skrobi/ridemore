<?php
// core/Resources/EventFormResource.php
namespace Resources;

class EventFormResource
{
    // Domyślny, pusty stan formularza — nowe wydarzenie.
    public static function empty(): array
    {
        return [
            'existingId'          => null,
            'type'                => 'ustawka',
            'title'               => '',
            'description'         => '',
            'endDate'             => '',
            'dateIsFlexible'      => false,
            'startTime'           => '',
            'coverPhotoUrl'       => null,
            // Alternatywa dla wgrania pliku z dysku: link do zdjęcia, które
            // POBIERA SAM SERWER przy zapisie (patrz EventFormInput::fromRequest()
            // -> Utils\Upload::saveCoverPhotoFromUrl()). Osobne pole od
            // coverPhotoUrl wyżej — tamto to JUŻ zapisana okładka (ścieżka
            // lokalna), to jest ŹRÓDŁO do pobrania (dowolny publiczny URL).
            'coverPhotoSourceUrl' => '',
            'status'              => 'draft',
            'registrationType'    => 'internal',
            'externalUrl'         => '',
            'externalPhone'       => '',
            'externalEmail'       => '',
            'isPaid'              => false,
            'region'              => '',
            'difficulty'          => '',
            'pace'                => '',
            'bikeTypes'           => [],
            'limitParticipants'   => false,
            'minParticipants'     => null,
            'maxParticipants'     => null,
            'meetingPoint'        => ['lat' => null, 'lng' => null, 'label' => ''],
            'equipment'           => [['name' => '', 'mandatory' => true]],
            'stages'              => [self::emptyStage()],
            'additionalDates'     => [],
            'priceAmount'         => null,
            'currency'            => 'PLN',
            'priceUnit'           => 'per_person',
            'deposit'             => null,
            'paymentDeadlineDays' => null,
            'cancellationDeadlineDays' => null,
            'cancellationPolicy'  => '',
            'priceItems'          => [
                ['category' => '', 'isIncluded' => true],
                ['category' => '', 'isIncluded' => false],
            ],
            // Warianty trasy — pusta tablica = tryb wariantów wyłączony (jedna
            // trasa). Kreator dopina pierwszy wiersz przy włączeniu opcji.
            'variants'            => [],
            // WYBÓR użytkownika, nie wniosek z długości tablicy wyżej. Kreator
            // czytał kiedyś `variants.length` i przy jednym wgranym pliku sam
            // zaznaczał „kilka wariantów"; teraz przełącznik jedzie w POST
            // polem `variants_enabled` i wraca tą właśnie wartością.
            'variantsEnabled'     => false,
        ];
    }

    // Rekonstruuje stan formularza z surowego $_POST po nieudanej walidacji —
    // bez tego $fail() w web/routes.php renderowałby pusty formularz i user
    // tracił WSZYSTKO co wypełnił (cały plan wyjazdu, cennik...) przez jeden
    // literowy błąd w jednym polu. Celowo NIE przechodzi przez
    // EventFormInput::fromRequest() — ta ma efekty uboczne (przenosi
    // wgrany plik GPX/zdjęcie na stałe), których tu nie chcemy przy zwykłym
    // ponownym renderze nieudanej próby.
    public static function fromPost(array $post): array
    {
        $num = fn($v) => is_numeric($v) ? $v : null;

        $stages = [];
        foreach ((array) ($post['stages'] ?? []) as $s) {
            $stages[] = [
                'date'                 => $s['date'] ?? '',
                'title'                => $s['title'] ?? '',
                'startLabel'           => $s['start_label'] ?? '',
                'startLat'             => $num($s['start_lat'] ?? null) !== null ? (float) $s['start_lat'] : null,
                'startLng'             => $num($s['start_lng'] ?? null) !== null ? (float) $s['start_lng'] : null,
                'endLabel'             => $s['end_label'] ?? '',
                'endLat'               => $num($s['end_lat'] ?? null) !== null ? (float) $s['end_lat'] : null,
                'endLng'               => $num($s['end_lng'] ?? null) !== null ? (float) $s['end_lng'] : null,
                'distanceKm'           => $s['distance_km'] ?? '',
                'elevationM'           => $s['elevation_m'] ?? '',
                'surface'              => $s['surface'] ?? 'asfalt',
                'surfaceAsphaltPct'    => $num($s['surface_asphalt_pct'] ?? null) !== null ? (int) $s['surface_asphalt_pct'] : null,
                'surfaceGravelPct'     => $num($s['surface_gravel_pct'] ?? null) !== null ? (int) $s['surface_gravel_pct'] : null,
                'surfaceTrailPct'      => $num($s['surface_trail_pct'] ?? null) !== null ? (int) $s['surface_trail_pct'] : null,
                // Plik już leży w assets/uploads/gpx/tmp/ pod tym tokenem (wgrany
                // przez /api/gpx/parse zanim doszło do submitu głównego formularza)
                // — samo ponowne wyrenderowanie formularza nic tam nie rusza,
                // więc token zostaje ważny i "trasa.gpx — wczytano" nadal się pokaże.
                'gpxUrl'               => ($s['existing_gpx_url'] ?? null) ?: null,
                'gpxToken'             => $s['gpx_token'] ?? '',
                'accommodationType'    => $s['accommodation_type'] ?? '',
                'accommodationName'    => $s['accommodation_name'] ?? '',
                'accommodationAddress' => $s['accommodation_address'] ?? '',
                'meals'                => array_values(array_filter((array) ($s['meals'] ?? []), 'is_string')),
                'notes'                => $s['notes'] ?? '',
            ];
        }
        if (empty($stages)) {
            $stages = [self::emptyStage()];
        }

        $equipment = [];
        foreach ((array) ($post['equipment'] ?? []) as $eq) {
            $equipment[] = ['name' => $eq['name'] ?? '', 'mandatory' => !empty($eq['mandatory'])];
        }
        if (empty($equipment)) {
            $equipment = [['name' => '', 'mandatory' => true]];
        }

        $priceItems = [];
        foreach ((array) ($post['price_items'] ?? []) as $pi) {
            $priceItems[] = ['category' => $pi['category'] ?? '', 'isIncluded' => !empty($pi['is_included'])];
        }
        if (empty($priceItems)) {
            $priceItems = [['category' => '', 'isIncluded' => true], ['category' => '', 'isIncluded' => false]];
        }

        $variants = [];
        foreach ((array) ($post['variants'] ?? []) as $v) {
            // Pusty wiersz wariantu NIE liczy się jako wariant. Bez tego jedno
            // kliknięcie przełącznika (które od razu dodaje pustą pozycję)
            // zostawiało po sobie ślad, który po nieudanym zapisie wracał jako
            // „to wydarzenie ma warianty".
            $hasContent = trim((string) ($v['name'] ?? '')) !== ''
                || trim((string) ($v['gpx_token'] ?? '')) !== ''
                || trim((string) ($v['existing_gpx_url'] ?? '')) !== ''
                || trim((string) ($v['distance_km'] ?? '')) !== '';
            if (!$hasContent) {
                continue;
            }
            $variants[] = [
                'name'              => $v['name'] ?? '',
                'distanceKm'        => $v['distance_km'] ?? '',
                'elevationM'        => $v['elevation_m'] ?? '',
                'surface'           => $v['surface'] ?? 'asfalt',
                'surfaceAsphaltPct' => $num($v['surface_asphalt_pct'] ?? null) !== null ? (int) $v['surface_asphalt_pct'] : null,
                'surfaceGravelPct'  => $num($v['surface_gravel_pct'] ?? null) !== null ? (int) $v['surface_gravel_pct'] : null,
                'surfaceTrailPct'   => $num($v['surface_trail_pct'] ?? null) !== null ? (int) $v['surface_trail_pct'] : null,
                'gpxUrl'            => ($v['existing_gpx_url'] ?? null) ?: null,
                'gpxToken'          => $v['gpx_token'] ?? '',
                'priceAmount'       => $num($v['price_amount'] ?? null) !== null ? (float) $v['price_amount'] : null,
                'deposit'           => $num($v['deposit'] ?? null) !== null ? (float) $v['deposit'] : null,
                'maxParticipants'   => $num($v['max_participants'] ?? null) !== null ? (int) $v['max_participants'] : null,
            ];
        }

        $organizerMode = in_array($post['organizer_mode'] ?? '', ['self', 'existing', 'new'], true) ? $post['organizer_mode'] : 'existing';
        $organizerId = $num($post['organizer_id'] ?? null) !== null ? (int) $post['organizer_id'] : null;

        $additionalDates = array_values(array_filter(
            array_map('trim', (array) ($post['additional_edition_dates'] ?? []))
        ));

        return [
            'existingId'          => null,
            'type'                => in_array($post['type'] ?? '', ['wycieczka_wielodniowa', 'pokrec_z_kims', 'wyscig'], true) ? $post['type'] : 'ustawka',
            'title'               => $post['title'] ?? '',
            'description'         => $post['description'] ?? '',
            'endDate'             => $post['end_date'] ?? '',
            'dateIsFlexible'      => !empty($post['date_is_flexible']),
            'startTime'           => $post['start_time'] ?? '',
            'coverPhotoUrl'       => ($post['existing_cover_photo_url'] ?? null) ?: null,
            // Zachowane po nieudanej walidacji — inaczej user (albo wtyczka)
            // traciłby wklejony link przy każdym powrocie z błędem.
            'coverPhotoSourceUrl' => $post['cover_photo_source_url'] ?? '',
            'status'              => ($post['status'] ?? 'draft') === 'published' ? 'published' : 'draft',
            'registrationType'    => ($post['registration_type'] ?? 'internal') === 'external' ? 'external' : 'internal',
            'externalUrl'         => $post['external_url'] ?? '',
            'externalPhone'       => $post['external_phone'] ?? '',
            'externalEmail'       => $post['external_email'] ?? '',
            'isPaid'              => !empty($post['is_paid']),
            'region'              => $post['region'] ?? '',
            'difficulty'          => $post['difficulty'] ?? '',
            'pace'                => $post['pace'] ?? '',
            'bikeTypes'           => array_values(array_filter((array) ($post['bike_types'] ?? []), 'is_string')),
            'limitParticipants'   => !empty($post['limit_participants']),
            'minParticipants'     => $num($post['min_participants'] ?? null) !== null ? (int) $post['min_participants'] : null,
            'maxParticipants'     => $num($post['max_participants'] ?? null) !== null ? (int) $post['max_participants'] : null,
            'meetingPoint'        => [
                'lat'   => $num($post['meeting_lat'] ?? null) !== null ? (float) $post['meeting_lat'] : null,
                'lng'   => $num($post['meeting_lng'] ?? null) !== null ? (float) $post['meeting_lng'] : null,
                'label' => $post['meeting_address'] ?? '',
            ],
            'equipment'           => $equipment,
            'stages'              => $stages,
            'additionalDates'     => $additionalDates,
            'priceAmount'         => $num($post['price_amount'] ?? null) !== null ? (float) $post['price_amount'] : null,
            'currency'            => $post['currency'] ?? 'PLN',
            'priceUnit'           => $post['price_unit'] ?? 'per_person',
            'deposit'             => $num($post['deposit'] ?? null) !== null ? (float) $post['deposit'] : null,
            'paymentDeadlineDays' => $num($post['payment_deadline_days'] ?? null) !== null ? (int) $post['payment_deadline_days'] : null,
            'cancellationDeadlineDays' => $num($post['cancellation_deadline_days'] ?? null) !== null ? (int) $post['cancellation_deadline_days'] : null,
            'cancellationPolicy'  => $post['cancellation_policy'] ?? '',
            'priceItems'          => $priceItems,
            'variants'            => $variants,
            // Flaga z formularza jest nadrzędna; brak wariantów o treści i tak
            // ją gasi, żeby zaznaczony przełącznik bez ani jednej pętli nie
            // przeżył round-tripu.
            'variantsEnabled'     => ($post['variants_enabled'] ?? '0') === '1' && $variants !== [],
            // "Kogo dotyczy" (patrz event-form.php sekcja 0) — new_organizer_name
            // niesie treść szukajki niezależnie od trybu (ustawiane w Alpine
            // zawsze, nie tylko dla 'new'), więc wystarczy je odtworzyć wprost,
            // bez ponownego odpytywania bazy o etykietę wybranego organizatora.
            'organizerMode'        => $organizerMode,
            'organizerId'          => $organizerId,
            'organizerSearchQuery' => $post['new_organizer_name'] ?? '',
            'organizerConfirmed'   => $organizerMode === 'new' || ($organizerMode === 'existing' && $organizerId !== null),
            'newOrganizerEmail'    => $post['new_organizer_email'] ?? '',
            'submitterName'        => $post['submitter_name'] ?? '',
            'submitterEmail'       => $post['submitter_email'] ?? '',
        ];
    }

    private static function emptyStage(): array
    {
        return [
            'date' => '', 'title' => '',
            'startLabel' => '', 'startLat' => null, 'startLng' => null,
            'endLabel' => '', 'endLat' => null, 'endLng' => null,
            'distanceKm' => '', 'elevationM' => '', 'surface' => 'asfalt',
            'surfaceAsphaltPct' => null, 'surfaceGravelPct' => null, 'surfaceTrailPct' => null,
            'gpxUrl' => null, 'gpxToken' => '',
            'accommodationType' => '', 'accommodationName' => '', 'accommodationAddress' => '',
            'meals' => [], 'notes' => '',
        ];
    }

    // $raw — wynik Models\Event::findRawById().
    public static function fromRawEvent(array $raw): array
    {
        $stages = array_map(function (array $s) {
            return [
                'date'                  => $s['stage_date'] ?? '',
                'title'                 => $s['title'] ?? '',
                'startLabel'            => $s['start_point'] ?? '',
                'startLat'              => $s['start_lat'] !== null ? (float) $s['start_lat'] : null,
                'startLng'              => $s['start_lng'] !== null ? (float) $s['start_lng'] : null,
                'endLabel'              => $s['end_point'] ?? '',
                'endLat'                => $s['end_lat'] !== null ? (float) $s['end_lat'] : null,
                'endLng'                => $s['end_lng'] !== null ? (float) $s['end_lng'] : null,
                'distanceKm'            => (float) $s['distance_km'],
                'elevationM'            => (int) $s['elevation_gain_m'],
                'surface'               => $s['surface_code'] ?? 'asfalt',
                'surfaceAsphaltPct'     => $s['surface_asphalt_pct'] !== null ? (int) $s['surface_asphalt_pct'] : null,
                'surfaceGravelPct'      => $s['surface_gravel_pct'] !== null ? (int) $s['surface_gravel_pct'] : null,
                'surfaceTrailPct'       => $s['surface_trail_pct'] !== null ? (int) $s['surface_trail_pct'] : null,
                'gpxUrl'                => $s['gpx_url'] ?? null,
                'gpxToken'              => '',
                'accommodationType'     => $s['accommodation']['type_code'] ?? '',
                'accommodationName'     => $s['accommodation']['name'] ?? '',
                'accommodationAddress'  => $s['accommodation']['address'] ?? '',
                'meals'                 => $s['meal_codes'] ?? [],
                'notes'                 => $s['notes'] ?? '',
            ];
        }, $raw['stages'] ?: [self::emptyStage()]);

        // item_name to nazwa z rozwiązanego equipment_item_id — fallback na name
        // dotyczy tylko wierszy sprzed podpięcia słownika 'equipment_item'.
        $equipment = array_map(fn($e) => ['name' => $e['item_name'] ?? $e['name'], 'mandatory' => (bool) $e['is_mandatory']], $raw['equipment']);
        if (empty($equipment)) {
            $equipment = [['name' => '', 'mandatory' => true]];
        }

        // Turnusy (patrz Models\EventEdition) — editions[0]['startDate'] to
        // zawsze events.start_date (główny termin, ten sam co stages[0].date),
        // reszta to dodatkowe daty tego samego wyjazdu wpisane w formularzu.
        $additionalDates = [];
        $mainDateSkipped = false;
        foreach ($raw['editions'] ?? [] as $ed) {
            if (!$mainDateSkipped && $ed['startDate'] === $raw['start_date']) {
                $mainDateSkipped = true;
                continue;
            }
            $additionalDates[] = $ed['startDate'];
        }

        // Główny turnus (ten, którego startDate = events.start_date — patrz
        // komentarz przy $additionalDates wyżej) niesie też end_date/
        // date_is_flexible/start_time — mają realną treść tylko dla
        // pokrec_z_kims, dla pozostałych typów zostają puste/domyślne.
        $mainEdition = null;
        foreach ($raw['editions'] ?? [] as $ed) {
            if ($ed['startDate'] === $raw['start_date']) { $mainEdition = $ed; break; }
        }

        $pricing = $raw['pricing'];
        // category_name to nazwa z rozwiązanego inclusion_category_item_id —
        // fallback na description dotyczy tylko wierszy sprzed wdrożenia
        // kategorii (description było wtedy jedynym wpisanym tekstem).
        $priceItems = $pricing
            ? array_map(fn($i) => ['category' => $i['category_name'] ?? $i['description'], 'isIncluded' => (bool) $i['is_included']], $pricing['items'])
            : [['category' => '', 'isIncluded' => true], ['category' => '', 'isIncluded' => false]];

        $variants = array_map(fn($v) => [
            'name'              => $v['name'],
            'distanceKm'        => (float) $v['distance_km'],
            'elevationM'        => (int) $v['elevation_gain_m'],
            'surface'           => $v['surface_code'] ?? 'asfalt',
            'surfaceAsphaltPct' => $v['surface_asphalt_pct'] !== null ? (int) $v['surface_asphalt_pct'] : null,
            'surfaceGravelPct'  => $v['surface_gravel_pct'] !== null ? (int) $v['surface_gravel_pct'] : null,
            'surfaceTrailPct'   => $v['surface_trail_pct'] !== null ? (int) $v['surface_trail_pct'] : null,
            'gpxUrl'            => $v['gpx_url'] ?? null,
            'gpxToken'          => '',
            'priceAmount'       => $v['price_amount'] !== null ? (float) $v['price_amount'] : null,
            'deposit'           => $v['deposit_amount'] !== null ? (float) $v['deposit_amount'] : null,
            'maxParticipants'   => $v['max_participants'] !== null ? (int) $v['max_participants'] : null,
        ], $raw['variants'] ?? []);

        return [
            'existingId'          => (int) $raw['id'],
            'type'                => $raw['type_code'],
            'title'               => $raw['title'],
            'description'         => $raw['description'] ?? '',
            'endDate'             => $mainEdition['endDate'] ?? '',
            'dateIsFlexible'      => (bool) ($mainEdition['dateIsFlexible'] ?? false),
            'startTime'           => $mainEdition['startTime'] ?? '',
            'coverPhotoUrl'       => $raw['cover_photo_url'],
            // Zawsze puste przy edycji — to pole jest wyłącznie ŹRÓDŁEM do
            // jednorazowego pobrania. Po zapisie zdjęcie żyje już lokalnie w
            // coverPhotoUrl wyżej; podtrzymywanie tu starego linku kazałoby
            // serwerowi pobierać to samo zdjęcie od nowa przy każdej edycji.
            'coverPhotoSourceUrl' => '',
            'status'              => $raw['status_code'],
            'registrationType'    => $raw['registration_type_code'] ?? 'internal',
            'externalUrl'         => $raw['external_registration_url'] ?? '',
            'externalPhone'       => $raw['external_registration_phone'] ?? '',
            'externalEmail'       => $raw['external_registration_email'] ?? '',
            'isPaid'              => $pricing !== null,
            'region'              => $raw['region_code'] ?? '',
            'difficulty'          => $raw['difficulty_code'] ?? '',
            'pace'                => $raw['pace_code'] ?? '',
            'bikeTypes'           => $raw['bike_type_codes'],
            'limitParticipants'   => $raw['min_participants'] !== null || $raw['max_participants'] !== null,
            'minParticipants'     => $raw['min_participants'] !== null ? (int) $raw['min_participants'] : null,
            'maxParticipants'     => $raw['max_participants'] !== null ? (int) $raw['max_participants'] : null,
            'meetingPoint'        => [
                'lat'   => $raw['meeting_point_lat'] !== null ? (float) $raw['meeting_point_lat'] : null,
                'lng'   => $raw['meeting_point_lng'] !== null ? (float) $raw['meeting_point_lng'] : null,
                'label' => $raw['meeting_point_address'] ?? '',
            ],
            'equipment'           => $equipment,
            'stages'              => $stages,
            'additionalDates'     => $additionalDates,
            'priceAmount'         => $pricing ? (float) $pricing['price_amount'] : null,
            'currency'            => $pricing['currency_code'] ?? 'PLN',
            'priceUnit'           => $pricing['unit_code'] ?? 'per_person',
            'deposit'             => $pricing && $pricing['deposit_amount'] !== null ? (float) $pricing['deposit_amount'] : null,
            'paymentDeadlineDays' => $pricing['payment_deadline_days_before'] ?? null,
            'cancellationDeadlineDays' => $pricing['cancellation_deadline_days_before'] ?? null,
            'cancellationPolicy'  => $pricing['cancellation_policy'] ?? '',
            'priceItems'          => $priceItems,
            'variants'            => $variants,
            // Przy edycji stan bierze się z FAKTU: wydarzenie ma zapisane
            // warianty albo nie ma.
            'variantsEnabled'     => $variants !== [],
        ];
    }
}
