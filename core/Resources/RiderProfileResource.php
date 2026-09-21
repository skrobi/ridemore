<?php
// core/Resources/RiderProfileResource.php
// Publiczny profil rowerzysty (Etap 3) — składa dane z kilku modeli w jedną
// tablicę dla widoku `rider-profile.php`.
//
// Zasada nadrzędna: KAŻDA liczba tutaj wynika z tego, że ktoś realnie
// przejechał trasę z drugim człowiekiem (Models\EventAttendance). Nie ma tu
// żadnej metryki wyczynowej — ani tempa, ani czasu, ani rankingu. Progres
// mierzymy zasięgiem (regiony, ludzie, powroty), nie prędkością, żeby
// początkujący zbierał go dokładnie tak samo szybko jak ścigant.
namespace Resources;

use Models\Dictionary;
use Models\Discovery;
use Models\EditionTrack;
use Models\EventAttendance;
use Models\EventRsvp;
use Models\RiderConnection;
use Models\User;

class RiderProfileResource
{
    public static function fromUser(User $user): array
    {
        $rides = EventAttendance::ridesForUser($user->id);

        // --- Co ta osoba REALNIE przejechała (Etap 8, migr. 042).
        // To są dokładnie te ślady, z których naliczyły się jej pola odkryć —
        // ta sama reguła pierwszeństwa (własny ślad bije ślad organizatora),
        // to samo źródło. Mapa na profilu ma rysować to, co widać we mgle,
        // a nie coś obok.
        $realTracks = EditionTrack::effectiveForUser($user->id);
        $editionsWithTrack = array_flip(array_map('intval', array_column($realTracks, 'edition_id')));

        // Liczby „co dał ten wyjazd" — jedno zapytanie na całą historię.
        $rideStats = Discovery::statsForEditions($user->id, array_column($rides, 'edition_id'));
        foreach ($rides as $i => $r) {
            $stats = $rideStats[(int) $r['edition_id']] ?? null;
            $rides[$i]['cells_new'] = $stats['cells_new'] ?? null;
            $rides[$i]['points'] = $stats['points'] ?? null;
        }

        // --- Regiony: ile odwiedzonych z ilu istniejących.
        // Mianownik LICZONY, nie wpisany na sztywno: słownik regionów rośnie
        // (dev ma 5 pozycji, produkcja 13), a „7 z 13" musi być prawdą w obu.
        $regionCounts = [];
        foreach ($rides as $r) {
            $label = $r['region_label'];
            if ($label === null || $label === '') {
                continue;
            }
            $regionCounts[$label] = ($regionCounts[$label] ?? 0) + 1;
        }
        arsort($regionCounts);
        $regionsTotal = count(Dictionary::items('region'));

        // --- Powroty w ten sam region.
        // Metryka PRZYWIĄZANIA DO MIEJSCA, nie osiągnięcia — nagradza wierność
        // zamiast nowości, czyli dokładnie to, co robią prawdziwi lokalni
        // rowerzyści. Liczymy regiony odwiedzone więcej niż raz.
        $returns = 0;
        foreach ($regionCounts as $count) {
            if ($count > 1) {
                $returns++;
            }
        }

        // --- Peleton. Liczba i lista rozjeżdżają się celowo: lista pomija
        // osoby, które wyłączyły się z list (migr. 037), licznik nie —
        // z nimi też się jechało. Widok pokazuje więc licznik LISTY,
        // a pełną liczbę zostawiamy na później (patrz komentarz
        // w RiderConnection::countForUser).
        $peloton = RiderConnection::forUser($user->id, 12);

        $sharedKm = 0.0;
        foreach ($peloton as $p) {
            $sharedKm += (float) $p['shared_km'];
        }

        // Discovery (Etap 8, §19): ile terenu ta osoba odkryła WSPÓLNIE z
        // każdym ze swojego peletonu. Jedno zapytanie na cały peleton, nie
        // jedno na osobę. Doklejane do gotowej listy — Peleton nie jest
        // przebudowywany, dostaje tylko dodatkową liczbę przy wierszu, którą
        // widok może zignorować.
        $sharedCells = Discovery::sharedCellCounts($user->id, array_column($peloton, 'user_id'));
        foreach ($peloton as $i => $p) {
            $peloton[$i]['shared_cells'] = $sharedCells[(int) $p['user_id']] ?? 0;
        }

        return [
            'id'          => $user->id,
            'slug'        => $user->publicSlug,
            'name'        => $user->displayName(),
            'initials'    => self::initials($user),
            'avatarUrl'   => $user->avatarUrl,

            // „Sygnatura" — kim jest jako rowerzysta. Budowana z FAKTÓW
            // (najczęstszy region + najczęstszy format), nie z deklaracji,
            // więc nie wymaga od użytkownika wypełniania niczego.
            'signature'   => self::signature($rides, $regionCounts),

            'rides'       => $rides,
            'ridesCount'  => count($rides),
            // DWIE warstwy linii na „mapie wspomnień", bo to dwie różne rzeczy
            // i mylenie ich było źródłem niespójności z mapą odkryć:
            //
            //   tracks  — ślady z ODBYTYCH wyjazdów. To z nich (i tylko z nich)
            //             wzięły się pola odkryć, więc linia i odsłonięta mgła
            //             zawsze do siebie pasują.
            //   planned — trasa ZAPOWIADANA wyjazdów, na których ta osoba była,
            //             ale nikt nie wgrał śladu z realizacji. Rysowana
            //             przerywaną, żeby było widać, że pól nie dała —
            //             usunięcie jej z mapy ukryłoby powód, dla którego
            //             mapa jest pustsza, niż wynika z listy wyjazdów.
            'tracks'      => array_map(
                static fn(array $t): array => [
                    // Identyfikator śladu — potrzebny liście przejazdów obok
                    // mapy, żeby kliknięcie w pozycję podświetliło DOKŁADNIE
                    // tę linię. Bez niego dałoby się dopasować tylko po URL-u,
                    // a wielodniówka ma kilka plików na jeden wyjazd.
                    'id'    => (int) $t['id'],
                    'url'   => $t['gpx_url'],
                    'title' => $t['event_title'],
                    'slug'  => $t['event_slug'],
                    'date'  => $t['start_date'],
                    'label' => $t['label'] ?? null,
                    'km'    => (float) $t['distance_km'],
                    'own'   => $t['user_id'] !== null,
                ],
                $realTracks
            ),
            'plannedTracks' => self::plannedTracks($rides, $editionsWithTrack),
            'regions'     => $regionCounts,
            'regionsVisited' => count($regionCounts),
            'regionsTotal'   => $regionsTotal,
            'returns'     => $returns,

            'peloton'      => $peloton,
            'pelotonCount' => count($peloton),
            // Dystans przejechany Z LUDŹMI — jedyny dystans, jaki ten profil
            // zna. Nie ma tu „kilometrów ogółem", bo ridemore nie jest
            // licznikiem treningowym.
            'sharedKm'     => $sharedKm,

            'nextRide'    => EventRsvp::publicNextRideForUser($user->id),
        ];
    }

    /**
     * Trasy zapowiadane wyjazdów, z których nie ma śladu z realizacji.
     * Klucz to URL pliku, a nie turnus: cykliczna ustawka ma ten sam GPX
     * w każdym terminie i bez tego mapa rysowałaby tę samą linię kilkanaście
     * razy jedna na drugiej.
     */
    private static function plannedTracks(array $rides, array $editionsWithTrack): array
    {
        $out = [];
        foreach ($rides as $r) {
            if (empty($r['gpx_url']) || isset($editionsWithTrack[(int) $r['edition_id']])) {
                continue;
            }
            $out[$r['gpx_url']] = [
                'url'   => $r['gpx_url'],
                'title' => $r['title'],
                'slug'  => $r['slug'],
            ];
        }
        return array_values($out);
    }

    // „GRAVEL · BIESZCZADY" — dwa najmocniejsze fakty z historii, wielkimi
    // literami, jako podtytuł profilu. Pusta tablica, gdy nie ma z czego
    // (widok wtedy po prostu nie renderuje podtytułu).
    private static function signature(array $rides, array $regionCounts): array
    {
        $out = [];

        $typeLabels = [
            'ustawka'                => __('Zorganizowane wydarzenia'),
            'wycieczka_wielodniowa'  => __('Wielodniówki'),
            'pokrec_z_kims'          => __('Pokręcę z kimś'),
            'wyscig'                 => __('Wyścigi'),
        ];
        $typeCounts = [];
        foreach ($rides as $r) {
            $code = $r['event_type_code'];
            if ($code !== null && isset($typeLabels[$code])) {
                $typeCounts[$code] = ($typeCounts[$code] ?? 0) + 1;
            }
        }
        if ($typeCounts) {
            arsort($typeCounts);
            $out[] = $typeLabels[array_key_first($typeCounts)];
        }
        if ($regionCounts) {
            $out[] = (string) array_key_first($regionCounts);
        }
        return $out;
    }

    private static function initials(User $user): string
    {
        $raw = trim((string) $user->name);
        if ($raw === '') {
            $raw = (string) strstr($user->email, '@', true);
        }
        return mb_strtoupper(mb_substr($raw !== '' ? $raw : '?', 0, 2));
    }
}
