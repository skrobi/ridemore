<?php
// core/Resources/EventFormInput.php
namespace Resources;

use Utils\Gpx;
use Utils\RateLimiter;
use Utils\Upload;

class EventFormInput
{
    // Tłumaczy surowe $_POST/$_FILES z formularza dodawania/edycji na kształt
    // oczekiwany przez Models\Event::save(). Jedyne miejsce, które zna nazwy
    // pól formularza (stages[i][...], equipment[i][...] itd.).
    public static function fromRequest(array $post, array $files, int $organizerId, ?int $existingId): array
    {
        $num = fn($v) => is_numeric($v) ? $v : null;

        $bikeTypes = array_values(array_filter((array) ($post['bike_types'] ?? []), 'is_string'));

        $equipment = [];
        foreach ((array) ($post['equipment'] ?? []) as $eq) {
            $name = trim($eq['name'] ?? '');
            if ($name === '') continue;
            $equipment[] = ['name' => $name, 'mandatory' => !empty($eq['mandatory'])];
        }

        $stages = [];
        foreach ((array) ($post['stages'] ?? []) as $s) {
            $gpxUrl = null;
            $token = trim($s['gpx_token'] ?? '');
            if ($token !== '') {
                $gpxUrl = Upload::promoteGpxTemp($token);
            }
            if ($gpxUrl === null) {
                // Edycja bez wgrania nowego pliku — zachowaj to, co już było.
                $gpxUrl = ($s['existing_gpx_url'] ?? '') ?: null;
            }

            // Profil wysokości nie jedzie przez formularz (za duży na ukryte pole) —
            // doliczany tu z pliku, który już mamy na dysku pod docelową ścieżką.
            $elevationProfile = null;
            if ($gpxUrl !== null) {
                try {
                    $parsed = Gpx::parse(CORE_PATH . '/../' . ltrim($gpxUrl, '/'));
                    $elevationProfile = $parsed['elevationProfile'];
                } catch (\RuntimeException $e) {
                    $elevationProfile = null;
                }
            }

            $stages[] = [
                'date'                  => $s['date'] ?? null,
                'title'                 => $s['title'] ?? null,
                'startLabel'            => $s['start_label'] ?? null,
                'startLat'              => $num($s['start_lat'] ?? null) !== null ? (float) $s['start_lat'] : null,
                'startLng'              => $num($s['start_lng'] ?? null) !== null ? (float) $s['start_lng'] : null,
                'endLabel'              => $s['end_label'] ?? null,
                'endLat'                => $num($s['end_lat'] ?? null) !== null ? (float) $s['end_lat'] : null,
                'endLng'                => $num($s['end_lng'] ?? null) !== null ? (float) $s['end_lng'] : null,
                'distanceKm'            => $num($s['distance_km'] ?? null) !== null ? (float) $s['distance_km'] : 0,
                'elevationM'            => $num($s['elevation_m'] ?? null) !== null ? (int) $s['elevation_m'] : 0,
                'surface'               => $s['surface'] ?? null,
                // Podział % z detekcji Overpass (patrz POST /api/gpx/parse) —
                // przychodzi gotowy z ukrytych pól, NIE liczony tu ponownie
                // (drugie zapytanie do Overpass przy każdym submicie byłoby
                // zbędnym powtórzeniem tej samej, wolnej operacji).
                'surfaceAsphaltPct'     => $num($s['surface_asphalt_pct'] ?? null) !== null ? (int) $s['surface_asphalt_pct'] : null,
                'surfaceGravelPct'      => $num($s['surface_gravel_pct'] ?? null) !== null ? (int) $s['surface_gravel_pct'] : null,
                'surfaceTrailPct'       => $num($s['surface_trail_pct'] ?? null) !== null ? (int) $s['surface_trail_pct'] : null,
                'gpxUrl'                => $gpxUrl,
                'elevationProfile'      => $elevationProfile,
                'accommodationType'     => $s['accommodation_type'] ?? null,
                'accommodationName'     => $s['accommodation_name'] ?? null,
                'accommodationAddress'  => $s['accommodation_address'] ?? null,
                'meals'                 => array_values(array_filter((array) ($s['meals'] ?? []), 'is_string')),
                'notes'                 => $s['notes'] ?? null,
            ];
        }

        // Warianty trasy (pętle) — każdy = nazwa + własna trasa (GPX jak w
        // etapach: token z tmp -> stała ścieżka, profil doliczany z pliku) +
        // własna cena/zaliczka/limit. Pusta nazwa = pusty wiersz, pomija go
        // EventRouteVariant::replaceForEvent. Brak wariantów = event bez
        // wariantów (jedna trasa event_stages), jak dotąd.
        $variants = [];
        foreach ((array) ($post['variants'] ?? []) as $v) {
            $gpxUrl = null;
            $token = trim($v['gpx_token'] ?? '');
            if ($token !== '') {
                $gpxUrl = Upload::promoteGpxTemp($token);
            }
            if ($gpxUrl === null) {
                $gpxUrl = ($v['existing_gpx_url'] ?? '') ?: null;
            }
            $elevationProfile = null;
            if ($gpxUrl !== null) {
                try {
                    $parsed = Gpx::parse(CORE_PATH . '/../' . ltrim($gpxUrl, '/'));
                    $elevationProfile = $parsed['elevationProfile'];
                } catch (\RuntimeException $e) {
                    $elevationProfile = null;
                }
            }
            $variants[] = [
                'name'              => trim($v['name'] ?? ''),
                'distanceKm'        => $num($v['distance_km'] ?? null) !== null ? (float) $v['distance_km'] : 0,
                'elevationM'        => $num($v['elevation_m'] ?? null) !== null ? (int) $v['elevation_m'] : 0,
                'surface'           => $v['surface'] ?? null,
                'surfaceAsphaltPct' => $num($v['surface_asphalt_pct'] ?? null) !== null ? (int) $v['surface_asphalt_pct'] : null,
                'surfaceGravelPct'  => $num($v['surface_gravel_pct'] ?? null) !== null ? (int) $v['surface_gravel_pct'] : null,
                'surfaceTrailPct'   => $num($v['surface_trail_pct'] ?? null) !== null ? (int) $v['surface_trail_pct'] : null,
                'gpxUrl'            => $gpxUrl,
                'elevationProfile'  => $elevationProfile,
                'priceAmount'       => $num($v['price_amount'] ?? null) !== null ? (float) $v['price_amount'] : null,
                'depositAmount'     => $num($v['deposit'] ?? null) !== null ? (float) $v['deposit'] : null,
                'maxParticipants'   => $num($v['max_participants'] ?? null) !== null ? (int) $v['max_participants'] : null,
            ];
        }

        $priceItems = [];
        foreach ((array) ($post['price_items'] ?? []) as $pi) {
            $category = trim($pi['category'] ?? '');
            if ($category === '') continue;
            $priceItems[] = ['category' => $category, 'isIncluded' => !empty($pi['is_included'])];
        }

        // Dodatkowe turnusy (patrz Models\EventEdition) — pierwszy termin to
        // stages[0]['date'] (główny, events.start_date), reszta to kolejne
        // wyjazdy tego samego wydarzenia w innych terminach.
        $additionalEditionDates = array_values(array_filter(
            array_map('trim', (array) ($post['additional_edition_dates'] ?? []))
        ));

        // Płatność decyduje przełącznik "Czy jest wpisowe?" (wybór bezpłatne/płatne
        // zostaje też dla wariantów — pętle mogą być darmowe albo płatne). Gdy
        // płatny event MA warianty, event_pricing.price_amount (NOT NULL) dostaje
        // najniższą cenę wariantu jako bazę pod "od X zł"; faktyczną kwotę bierze
        // booking z WYBRANEGO wariantu (patrz Models\EventRouteVariant).
        $namedVariants = array_values(array_filter($variants, fn($v) => $v['name'] !== ''));
        $hasVariants = count($namedVariants) > 0;
        $variantPrices = [];
        foreach ($namedVariants as $v) {
            if ($v['priceAmount'] !== null && $v['priceAmount'] > 0) {
                $variantPrices[] = $v['priceAmount'];
            }
        }

        $isPaid = !empty($post['is_paid']);

        $coverPhotoUrl = null;
        if (!empty($files['cover_photo']) && ($files['cover_photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $coverPhotoUrl = Upload::saveCoverPhoto($files['cover_photo']);
        }
        // Zdjęcie z zewnętrznego linku (dopisane 2026-08-09) — pole ukryte, nie
        // pokazywane w normalnym formularzu, wypełniane wyłącznie przez
        // rozszerzenie Chrome ("Importer eventów"), patrz komentarz przy
        // Utils\Upload::saveCoverPhotoFromUrl(). Rate-limit per IP tutaj (nie w
        // Upload.php — ten formularz jest dostępny bez logowania, więc to
        // jedyny sensowny klucz), żeby to pole nie stało się otwartym
        // narzędziem do masowego odpytywania dowolnych adresów przez nasz serwer.
        if ($coverPhotoUrl === null && trim($post['cover_photo_source_url'] ?? '') !== '') {
            if (RateLimiter::tooMany('cover-photo-url:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 15, 600)) {
                throw new \InvalidArgumentException(__('Zbyt wiele prób pobrania zdjęcia z linku — spróbuj ponownie za kilka minut.'));
            }
            try {
                $coverPhotoUrl = Upload::saveCoverPhotoFromUrl($post['cover_photo_source_url']);
            } catch (\RuntimeException $e) {
                // Upload::* rzuca \RuntimeException (spójnie z saveCoverPhoto()
                // wyżej) — tu jednak PRZEPAKOWUJEMY na \InvalidArgumentException,
                // bo tylko ten typ EventController::create()/update() pokazuje
                // userowi wprost (patrz tamten komentarz "treść jest bezpieczna
                // i pomocna"); zwykły \RuntimeException wpadłby w ogólny catch-all
                // i pokazał generyczne "spróbuj ponownie", gubiąc konkretny powód
                // (zły link / zdjęcie za duże / link niedozwolony).
                throw new \InvalidArgumentException($e->getMessage(), 0, $e);
            }
        }
        if ($coverPhotoUrl === null) {
            $coverPhotoUrl = ($post['existing_cover_photo_url'] ?? null) ?: null;
        }

        return [
            'existingId'          => $existingId,
            'organizerId'         => $organizerId,
            'type'                => in_array($post['type'] ?? '', ['wycieczka_wielodniowa', 'pokrec_z_kims', 'wyscig'], true) ? $post['type'] : 'ustawka',
            // Pola specyficzne dla pokrec_z_kims (patrz event-form.php sekcja
            // "Kiedy masz czas") — dla pozostałych dwóch typów ignorowane przez
            // Event::save() (wymuszone tam na null/false), zbędne żeby je stąd
            // warunkować po typie.
            'endDate'             => trim($post['end_date'] ?? '') ?: null,
            'dateIsFlexible'      => !empty($post['date_is_flexible']),
            'startTime'           => trim($post['start_time'] ?? '') ?: null,
            'title'               => trim($post['title'] ?? ''),
            'description'         => $post['description'] ?? null,
            'coverPhotoUrl'       => $coverPhotoUrl,
            'status'              => ($post['status'] ?? 'draft') === 'published' ? 'published' : 'draft',
            'isPaid'              => $isPaid,
            // "external" = organizator podał JAKĄKOLWIEK zewnętrzną formę zapisu
            // (link / telefon / e-mail) — nie tylko link. Wyprowadzane z treści,
            // nie z samego ukrytego pola registration_type, żeby "zewnętrzne" bez
            // żadnej formy nie zostawiło uczestnika bez sposobu zapisu.
            'registrationType'    => (trim($post['external_url'] ?? '') !== '' || trim($post['external_phone'] ?? '') !== '' || trim($post['external_email'] ?? '') !== '') ? 'external' : 'internal',
            'externalUrl'         => trim($post['external_url'] ?? '') ?: null,
            'externalPhone'       => trim($post['external_phone'] ?? '') ?: null,
            'externalEmail'       => filter_var(trim($post['external_email'] ?? ''), FILTER_VALIDATE_EMAIL) ?: null,
            'region'              => $post['region'] ?? null,
            'meetingPointAddress' => $post['meeting_address'] ?? null,
            'meetingPointLat'     => $num($post['meeting_lat'] ?? null) !== null ? (float) $post['meeting_lat'] : null,
            'meetingPointLng'     => $num($post['meeting_lng'] ?? null) !== null ? (float) $post['meeting_lng'] : null,
            'difficulty'          => $post['difficulty'] ?? null,
            'pace'                => $post['pace'] ?? null,
            'bikeTypes'           => $bikeTypes,
            'limitParticipants'   => !empty($post['limit_participants']),
            'minParticipants'     => $num($post['min_participants'] ?? null) !== null ? (int) $post['min_participants'] : null,
            'maxParticipants'     => $num($post['max_participants'] ?? null) !== null ? (int) $post['max_participants'] : null,
            'equipment'           => $equipment,
            'stages'              => $stages,
            'variants'            => $variants,
            'additionalEditionDates' => $additionalEditionDates,
            'pricing'             => $isPaid ? [
                // Płatny event z wariantami: baza = najniższa cena wariantu ("od X
                // zł"); faktyczną kwotę bierze booking z wybranego wariantu.
                'amount'              => ($hasVariants && $variantPrices) ? min($variantPrices) : ($num($post['price_amount'] ?? null) !== null ? (float) $post['price_amount'] : 0),
                'currency'            => $post['currency'] ?? 'PLN',
                'unit'                => $post['price_unit'] ?? 'per_person',
                'deposit'             => $hasVariants ? null : ($num($post['deposit'] ?? null) !== null ? (float) $post['deposit'] : null),
                'paymentDeadlineDays' => $num($post['payment_deadline_days'] ?? null) !== null ? (int) $post['payment_deadline_days'] : null,
                'cancellationDeadlineDays' => $num($post['cancellation_deadline_days'] ?? null) !== null ? (int) $post['cancellation_deadline_days'] : null,
                'cancellationPolicy'  => $post['cancellation_policy'] ?? null,
                'items'               => $priceItems,
            ] : null,
        ];
    }
}
