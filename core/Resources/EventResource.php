<?php
// core/Resources/EventResource.php
namespace Resources;

use Models\Event;

class EventResource
{
    public static function fromModel(Event $event): array
    {
        $totalDistance  = array_sum(array_map(fn($s) => $s->distanceKm, $event->stages));
        $totalElevation = array_sum(array_map(fn($s) => $s->elevationGainM, $event->stages));
        $durationDays   = count($event->stages);

        $data = [
            'id'                  => $event->id,
            'slug'                => $event->slug,
            'statusCode'          => $event->statusCode,
            'organizerId'         => $event->organizerId,
            'title'               => $event->title,
            'description'         => $event->description,
            'type'                => $event->eventTypeCode,
            'isPaid'              => $event->pricing !== null,
            'registrationType'    => $event->registrationTypeCode,
            'externalUrl'         => $event->externalRegistrationUrl,
            'externalPhone'       => $event->externalRegistrationPhone,
            'externalEmail'       => $event->externalRegistrationEmail,
            'isMultiday'          => count($event->stages) > 1,
            'totals'              => [
                'distanceKm'   => $totalDistance,
                'elevationM'   => $totalElevation,
                'durationDays' => count($event->stages),
            ],
            'meetingPointAddress' => $event->meetingPointAddress,
            'meetingPointLat'     => $event->meetingPointLat,
            'meetingPointLng'     => $event->meetingPointLng,
            'coverPhotoUrl'       => $event->coverPhotoUrl,
            // Etykiety ze słowników tłumaczy słownik interfejsu (Core\Lang) —
            // pozycje są zakładane migracjami, więc to „kod", nie treść.
            // Region ZOSTAJE w oryginale: to nazwa własna, a strony regionów
            // rozpoznają go po tej etykiecie.
            'difficultyLabel'     => $event->difficultyLabel !== null ? __($event->difficultyLabel) : null,
            'paceGroupLabel'      => $event->paceGroupLabel !== null ? __($event->paceGroupLabel) : null,
            'regionLabel'         => $event->regionLabel,
            'regionCode'          => $event->regionCode,
            'bikeTypes'           => array_map(static fn($l) => is_string($l) ? __($l) : $l, (array) $event->bikeTypeLabels),
            'equipment'           => $event->equipment,
            'minParticipants'     => $event->minParticipants,
            'maxParticipants'     => $event->maxParticipants,
            'stages'              => array_map(fn($s) => StageResource::fromModel($s), $event->stages),
            'surfaceBreakdown'    => self::aggregateSurface($event->stages),
            'pricing'             => $event->pricing ? PricingResource::fromModel($event->pricing) : null,
            'organizer'           => OrganizerResource::fromModel($event->organizer),
            // Turnusy (patrz Models\EventEdition) — event-page.php pokazuje
            // wybór terminu tylko gdy jest ich więcej niż jeden; limit/liczba
            // zapisanych to zawsze dane WYBRANEGO turnusu, nie sumy wszystkich.
            'editions'            => array_map(fn($ed) => [
                'id'              => $ed->id,
                'startDate'       => $ed->startDate,
                // endDate: patrz Models\EventEdition::effectiveEndDate() — NULL
                // stored + duration_days>1 (wycieczka_wielodniowa) wylicza się
                // stąd, pokrec_z_kims ma ją zapisaną wprost.
                'endDate'         => $ed->effectiveEndDate($durationDays),
                'dateIsFlexible'  => $ed->dateIsFlexible,
                'startTime'       => $ed->startTime,
                'maxParticipants' => $ed->maxParticipants,
                'confirmedCount'  => $ed->confirmedCount,
                'isCancelled'     => $ed->isCancelled,
            ], $event->editions),
            // Warianty trasy (pętle) — puste dla eventów bez wariantów. Gdy
            // niepuste, strona eventu pokazuje picker: wybrany wariant niesie
            // własną trasę (GPX/profil/nawierzchnia), cenę i limit. Zapełnienie
            // (spotsLeft) liczy event-page per WYBRANY turnus (EventRouteVariant::
            // confirmedCount) — tu tylko statyczne dane wariantu.
            'hasVariants'         => count($event->variants) > 0,
            'variants'            => array_map(fn($v) => [
                'id'                => $v->id,
                'name'              => $v->name,
                'distanceKm'        => $v->distanceKm,
                'elevationM'        => $v->elevationGainM,
                'gpxUrl'            => $v->gpxUrl,
                'elevationProfile'  => $v->elevationProfile,
                'surfaceLabel'      => $v->surfaceLabel,
                'surfaceAsphaltPct' => $v->surfaceAsphaltPct,
                'surfaceGravelPct'  => $v->surfaceGravelPct,
                'surfaceTrailPct'   => $v->surfaceTrailPct,
                'priceAmount'       => $v->priceAmount,
                'depositAmount'     => $v->depositAmount,
                'maxParticipants'   => $v->maxParticipants,
            ], $event->variants),
        ];

        return self::przetlumacz($event, $data);
    }

    /**
     * TREŚCI EVENTU W JĘZYKU STRONY (2026-09-16, tasks/active/wielojezycznosc.md).
     *
     * Wszystko, co napisał organizator, idzie JEDNYM wywołaniem — język źródła
     * zgaduje się z całości (sam tytuł bywa nazwą własną), a przy pierwszym
     * wyświetleniu jest jedno żądanie do tłumacza zamiast kilkunastu. Oryginał
     * w modelu zostaje nietknięty; tłumaczy się wyłącznie ta tablica, więc
     * strona, meta description i JSON-LD dostają ten sam tekst.
     */
    private static function przetlumacz(Event $event, array $data): array
    {
        $pola = [
            'title'       => $event->title,
            'description' => $event->description,
            'policy'      => $data['pricing']['cancellationPolicy'] ?? null,
        ];
        foreach ($data['stages'] as $i => $s) {
            $pola["stage{$i}_title"] = $s['title'];
            $pola["stage{$i}_notes"] = $s['notes'];
        }
        foreach ($data['variants'] as $i => $v) {
            $pola["variant{$i}"] = $v['name'];
        }
        foreach ((array) $data['equipment'] as $i => $eq) {
            $pola["eq{$i}"] = $eq['note'] ?? null;
        }

        $t = \Models\ContentTranslation::fields($pola, 'event:' . $event->id);

        $data['title'] = $t['title'];
        $data['description'] = $t['description'];
        if ($data['pricing'] !== null) {
            $data['pricing']['cancellationPolicy'] = $t['policy'];
        }
        foreach ($data['stages'] as $i => $s) {
            $data['stages'][$i]['title'] = $t["stage{$i}_title"];
            $data['stages'][$i]['notes'] = $t["stage{$i}_notes"];
            $data['stages'][$i]['includedMealLabels'] = array_map(static fn($l) => is_string($l) ? __($l) : $l, (array) ($s['includedMealLabels'] ?? []));
            if (!empty($s['accommodationLabel'])) {
                $data['stages'][$i]['accommodationLabel'] = __($s['accommodationLabel']);
            }
            if (!empty($s['surfaceLabel'])) {
                $data['stages'][$i]['surfaceLabel'] = __($s['surfaceLabel']);
            }
        }
        foreach ($data['variants'] as $i => $v) {
            $data['variants'][$i]['name'] = $t["variant{$i}"];
            if (!empty($v['surfaceLabel'])) {
                $data['variants'][$i]['surfaceLabel'] = __($v['surfaceLabel']);
            }
        }
        foreach ((array) $data['equipment'] as $i => $eq) {
            $data['equipment'][$i]['name'] = is_string($eq['name'] ?? null) ? __($eq['name']) : ($eq['name'] ?? null);
            $data['equipment'][$i]['note'] = $t["eq{$i}"];
        }
        // Meta tłumaczenia dla etykiety „Przetłumaczono automatycznie · Pokaż oryginał".
        $data['translation'] = \Models\ContentTranslation::meta('event:' . $event->id);
        return $data;
    }

    // Ważona dystansem suma podziału nawierzchni po WSZYSTKICH etapach, które
    // mają wykryty podział (surface_asphalt_pct itd. — patrz RoadSurfaceDetector).
    // Etapy bez detekcji (brak GPX / Overpass się nie powiódł) są pomijane —
    // przy wielodniowej wycieczce, gdzie tylko część dni ma GPX, pasek pokazuje
    // podział tylko dla tych dni, nie całej trasy. null gdy ŻADEN etap nie ma
    // danych — front wtedy zostaje przy starej liście etykiet ze słownika.
    private static function aggregateSurface(array $stages): ?array
    {
        $asphaltKm = 0.0;
        $gravelKm  = 0.0;
        $trailKm   = 0.0;
        $known     = false;

        foreach ($stages as $stage) {
            if ($stage->surfaceAsphaltPct === null) {
                continue;
            }
            $known = true;
            $asphaltKm += $stage->distanceKm * $stage->surfaceAsphaltPct / 100;
            $gravelKm  += $stage->distanceKm * $stage->surfaceGravelPct / 100;
            $trailKm   += $stage->distanceKm * $stage->surfaceTrailPct / 100;
        }

        if (!$known) {
            return null;
        }

        $total = $asphaltKm + $gravelKm + $trailKm;
        if ($total <= 0) {
            return null;
        }

        return [
            'asphaltPct' => (int) round($asphaltKm / $total * 100),
            'gravelPct'  => (int) round($gravelKm / $total * 100),
            'trailPct'   => (int) round($trailKm / $total * 100),
            'asphaltKm'  => round($asphaltKm, 1),
            'gravelKm'   => round($gravelKm, 1),
            'trailKm'    => round($trailKm, 1),
        ];
    }
}
