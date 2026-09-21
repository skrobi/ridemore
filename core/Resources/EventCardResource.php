<?php
// core/Resources/EventCardResource.php
namespace Resources;

class EventCardResource
{
    // Mapuje wiersz z Event::upcoming()/completed()/forParticipant() na dane
    // karty na liście wydarzeń. upcoming_edition_count (tylko z upcoming())
    // mówi, ile jeszcze innych nadchodzących turnusów ma to wydarzenie poza
    // tym pokazanym na karcie — patrz Models\EventEdition, "jedna karta na
    // wydarzenie". edition_id (tylko z forParticipant()) wskazuje KONKRETNY
    // turnus, na który user się zapisał — potrzebny do linkowania płatności/
    // rezygnacji z właściwego terminu, gdy zapisał się na więcej niż jeden.
    public static function fromRow(array $row): array
    {
        $maxParticipants = $row['max_participants'] !== null ? (int) $row['max_participants'] : null;
        $confirmedCount  = (int) $row['confirmed_count'];
        $otherEditions   = isset($row['upcoming_edition_count']) ? max(0, (int) $row['upcoming_edition_count'] - 1) : 0;
        $durationDays    = (int) $row['duration_days'];
        // Koniec okna/wyjazdu — patrz Models\EventEdition::effectiveEndDate()
        // (ta sama formuła, tu w SQL-owym kształcie bo karta dostaje surowy
        // wiersz, nie obiekt EventEdition): end_date zapisany wprost tylko dla
        // pokrec_z_kims, dla pozostałych typów wyliczany z duration_days.
        $endDate = $row['end_date'] ?? date('Y-m-d', strtotime($row['start_date'] . ' +' . ($durationDays - 1) . ' days'));

        return [
            'eventId'        => isset($row['event_id']) ? (int) $row['event_id'] : null,
            'slug'           => $row['slug'],
            // Tytuł w języku strony; listy wołają wcześniej ContentTranslation::prefetch(),
            // więc tu to już tylko odczyt z pamięci żądania.
            'title'          => \Models\ContentTranslation::text($row['title'], 'event:' . (int) ($row['event_id'] ?? $row['id'] ?? 0)),
            'eventType'      => $row['event_type_code'] ?? null,
            'organizerName'  => $row['organizer_name'] ?? null,
            'organizerAvatarUrl' => $row['organizer_avatar_url'] ?? null,
            'regionName'     => $row['region_name'] ?? null,
            // Kod regionu, nie tylko nazwa — Puls linkuje z niego do listy
            // wyjazdów z tych stron (region działa jak tag: nawigacja + wewnętrzne
            // linkowanie pod SEO). Nazwa do wyświetlenia, kod do adresu.
            'regionCode'     => $row['region_code'] ?? null,
            'difficultyLabel' => isset($row['difficulty_label']) ? __($row['difficulty_label']) : null,
            'organizerVerified' => !empty($row['organizer_verified']),
            'bikeTypeNames'  => $row['bike_type_names'] ?? null,
            'startDate'      => $row['start_date'],
            'endDate'        => $endDate,
            'dateIsFlexible' => !empty($row['date_is_flexible']),
            'startTime'      => $row['start_time'] ?? null,
            'durationDays'   => $durationDays,
            'distanceKm'     => (float) $row['distance_km'],
            'isPaid'         => $row['price_amount'] !== null,
            'priceAmount'    => $row['price_amount'] !== null ? (float) $row['price_amount'] : null,
            'currency'       => $row['currency_code'] ?? 'PLN',
            'coverPhotoUrl'  => $row['cover_photo_url'],
            // Czy wydarzenie ma w ogóle plik GPX — znacznik na karcie mówi
            // „jest co pobrać", zanim ktokolwiek w nią kliknie. Liczy się
            // KTÓRYKOLWIEK etap albo wariant; karcie nie jest potrzebne, ile
            // ich jest, tylko czy trasa istnieje jako plik.
            'hasGpx'         => !empty($row['has_gpx']),
            'meetingPointAddress' => $row['meeting_point_address'] ?? null,
            'confirmedCount' => $confirmedCount,
            'spotsLeft'      => $maxParticipants !== null ? max(0, $maxParticipants - $confirmedCount) : null,
            'distanceFromUserKm' => isset($row['distance_from_user_km']) ? round((float) $row['distance_from_user_km'], 1) : null,
            'isJoined'       => !empty($row['is_joined']),
            'myRsvpStatus'   => $row['my_rsvp_status'] ?? null,
            'otherEditionsCount' => $otherEditions,
            'editionId'      => isset($row['edition_id']) ? (int) $row['edition_id'] : null,
        ];
    }
}
