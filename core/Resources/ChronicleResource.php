<?php
// core/Resources/ChronicleResource.php
// Składa KRONIKĘ WYJAZDU (Etap 4) z danych, które serwis już ma.
//
// Kluczowa własność: kronika NIGDY nie jest pusta. Nawet zanim ktokolwiek
// dorzuci zdjęcie czy zdanie, ma już skład, trasę, datę, region i liczbę
// zawiązanych tego dnia znajomości — czyli komplet tego, co czyni ją
// zapisem wyjazdu. Wkładem użytkownika jest DOPISANIE się do gotowej strony,
// a nie napisanie relacji od zera. To jest różnica między kroniką a pustą
// kartką, o którą rozbija się większość treści tworzonych przez użytkowników.
namespace Resources;

use Models\Discovery;
use Models\EditionTrack;
use Models\Event;
use Models\EventAttendance;
use Models\EventEdition;
use Utils\Format;

class ChronicleResource
{
    public static function build(
        Event $event,
        EventEdition $edition,
        array $attended,
        array $entries,
        int $newPairs,
        // Kto ogląda — rozstrzyga, CZYJ ślad rysujemy na mapie (własny bije
        // ślad organizatora). null = gość albo ktoś, kogo tam nie było; wtedy
        // pokazujemy ślad zbiorowy.
        ?int $viewerId = null
    ): array {
        // Etapy tego wydarzenia niosą dystans, przewyższenie i ślad GPX —
        // wszystko już policzone przy zapisie wydarzenia, nic nie liczymy tu
        // od nowa (patrz Models\EventStage / Utils\Gpx).
        $distanceKm = 0.0;
        $elevationM = 0;
        $plannedGpx = [];
        foreach ($event->stages as $stage) {
            $distanceKm += (float) ($stage->distanceKm ?? 0);
            $elevationM += (int) ($stage->elevationGainM ?? 0);
            if (!empty($stage->gpxUrl)) {
                $plannedGpx[] = [
                    'url'        => $stage->gpxUrl,
                    'label'      => count($event->stages) > 1 ? 'Dzień ' . $stage->dayNumber : null,
                    'distanceKm' => (float) ($stage->distanceKm ?? 0),
                    'isOwn'      => false,
                ];
            }
        }

        // ŚLADY NA MAPIE KRONIKI (2026-08-12) — WSZYSTKIE, nie pierwszy z brzegu.
        // Wielodniówka ma osobny plik na każdy dzień, więc rysowanie jednego
        // pokazywało jeden dzień z trzech i wyglądało, jakby reszta trasy nie
        // istniała.
        //
        // Pierwszeństwo ma ŚLAD RZECZYWISTY (edition_tracks) nad trasą
        // planowaną — kronika jest zapisem tego, co się wydarzyło. A wewnątrz
        // śladów rzeczywistych własny ślad widza bije ślad organizatora
        // (EditionTrack::effectiveFor), bo to jego kronika mówi o tym, co
        // przejechał ON.
        //
        // Bez żadnego śladu rzeczywistego pokazujemy trasę planowaną, tak jak
        // dotąd — inaczej kronika starego wyjazdu straciłaby mapę.
        $actual = $viewerId !== null
            ? EditionTrack::effectiveFor($edition->id, $viewerId)
            : self::eventTracks($edition->id);

        $tracks = [];
        foreach ($actual as $t) {
            $tracks[] = [
                'url'        => $t['gpx_url'],
                'label'      => $t['label'] ?: null,
                'distanceKm' => (float) $t['distance_km'],
                'isOwn'      => $t['user_id'] !== null,
            ];
        }
        $tracksAreActual = $tracks !== [];
        if (!$tracksAreActual) {
            $tracks = $plannedGpx;
        }

        // Skład. Osoba, która wyłączyła się z list (migr. 037), NADAL LICZY SIĘ
        // do składu — była tam, to fakt o wyjeździe — ale nie pokazujemy jej
        // imienia ani nie linkujemy do profilu. Ta sama zasada co w „Kto jedzie":
        // ukrycie dotyczy tożsamości, nie faktu.
        $people = [];
        $hiddenCount = 0;
        foreach ($attended as $u) {
            if ((int) ($u['roster_visible'] ?? 1) !== 1) {
                $hiddenCount++;
                continue;
            }
            $name = trim((string) ($u['name'] ?? ''));
            if ($name === '') {
                $name = (string) strstr((string) ($u['email'] ?? ''), '@', true);
            }
            $parts = preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $short = $parts ? $parts[0] : __('Rowerzysta');
            if (count($parts) > 1) {
                $short .= ' ' . mb_strtoupper(mb_substr((string) end($parts), 0, 1)) . '.';
            }
            $people[] = [
                'userId'    => (int) $u['user_id'],
                'name'      => $short,
                'initials'  => mb_strtoupper(mb_substr($name !== '' ? $name : '?', 0, 2)),
                'slug'      => $u['public_slug'] ?? null,
                // Wgrane zdjęcie (2026-08-22). Inicjały ZOSTAJĄ w tablicy, bo są
                // zapasem: konto bez awatara i niewczytany plik mają wyglądać
                // tak samo jak dotąd.
                'avatarUrl' => $u['avatar_url'] ?? null,
            ];
        }

        $dateLabel = Format::dateP($edition->startDate) ?? $edition->startDate;

        return [
            'eventSlug'   => $event->slug,
            'editionId'   => $edition->id,
            'title'       => $event->title,
            'dateLabel'   => $dateLabel,
            'startDate'   => $edition->startDate,
            'regionLabel' => $event->regionLabel,
            'distanceKm'  => $distanceKm,
            'elevationM'  => $elevationM,
            // Wszystkie ślady do narysowania (wielodniówka = jeden na dzień)
            // + informacja, czy to zapis RZECZYWISTEGO przejazdu, czy tylko
            // trasa planowana — widok mówi o nich inaczej.
            'tracks'         => $tracks,
            'tracksAreActual'=> $tracksAreActual,
            'coverPhotoUrl' => $event->coverPhotoUrl,
            'organizerName' => $event->organizer?->name,
            'organizerSlug' => $event->organizer?->slug,

            'people'      => $people,
            // Liczba = WSZYSCY, którzy byli (z ukrytymi); lista = tylko widoczni.
            'peopleCount' => count($attended),
            'hiddenCount' => $hiddenCount,

            // Dziennik — wpisy uczestników, chronologicznie. Pusta tablica jest
            // W PORZĄDKU: kronika bez zdjęć wciąż jest kompletna, więc widok NIE
            // pokazuje wtedy pustego stanu na środku ekranu, tylko po prostu
            // pomija sekcję dziennika.
            'entries'     => $entries,

            // Wyjazd jako zdarzenie społeczne — ILU ludzi poznało się tego dnia
            // i dla ilu było to pierwsze spotkanie z tym regionem.
            'newPairs'    => $newPairs,
            'firstTimers' => EventAttendance::firstTimersInRegionForEdition($edition->id),

            // Discovery (Etap 8, §20): ile pól odkrył TEN skład. Kronika
            // dostaje to jako kolejne zdanie o tych samych ludziach, a nie
            // jako nowa sekcja — dokładnie jak firstTimers i newPairs wyżej.
            // Liczba, nie lista: odkrywanie jest tu faktem ZBIOROWYM, więc
            // mówimy ILE pól, nigdy kto które odkrył (§27).
            'newCells'    => Discovery::newCellsForEdition($edition->id),

            'metaDescription' => self::metaDescription($event->title, $dateLabel, count($attended), $event->regionLabel),
        ];
    }

    /**
     * Ślady zbiorowe turnusu (wgrane przez organizatora). Osobna metoda, bo
     * EditionTrack::effectiveFor() wymaga konkretnego widza, a kronikę ogląda
     * też gość i ktoś, kogo tam nie było.
     */
    private static function eventTracks(int $editionId): array
    {
        return array_values(array_filter(
            EditionTrack::forEdition($editionId),
            static fn(array $t) => $t['user_id'] === null
        ));
    }

    private static function metaDescription(string $title, string $date, int $people, ?string $region): string
    {
        $bits = [$date];
        if ($region) {
            $bits[] = $region;
        }
        $bits[] = $people === 1 ? __('1 uczestnik') : __('{n} uczestników', ['n' => $people]);
        return __('Kronika wyjazdu „{tytul}" — {fakty}.', ['tytul' => $title, 'fakty' => implode(' · ', $bits)]);
    }
}
