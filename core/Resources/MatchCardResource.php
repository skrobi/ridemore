<?php
// core/Resources/MatchCardResource.php
// Etap 2/3 (dopasowania) — kształtowanie surowych wyników Models\MatchEngine
// pod widget "Może Cię zainteresować" w wersji "liczba jako bohater": jeden
// dodatkowy, zbiorczy odczyt (daty/region/typy roweru) na potrzeby WIDOKU,
// żeby MatchEngine samo zostało wyłącznie warstwą oceniającą (docs/etap3 §10,
// "rozdzielenie oceniania od prezentacji").
namespace Resources;

use Core\Database;
use Utils\Format;

class MatchCardResource
{
    // $matches: wynik MatchEngine::forEdition()/forDraft()/profileMatchesForUser(),
    // każdy wpis z eventId/editionId/slug/title/classification/reason/alignOn/
    // groupSize/profileJustification (patrz Models\MatchEngine). $urlBuilder:
    // fn(string $slug): string — View::url() wstrzykiwane z zewnątrz, żeby ten
    // zasób nie zależał od konkretnego kontrolera.
    public static function fromMatches(array $matches, callable $urlBuilder): array
    {
        $editionIds = array_values(array_unique(array_filter(array_column($matches, 'editionId'))));
        $details = self::loadDetails($editionIds);

        return array_values(array_filter(array_map(function ($m) use ($details, $urlBuilder) {
            $d = $details[$m['editionId']] ?? null;
            if ($d === null) {
                return null;
            }
            $groupSize = (int) ($m['groupSize'] ?? 1);
            return [
                'eventId'        => $m['eventId'],
                'url'            => $urlBuilder($m['slug']),
                'title'          => $m['title'],
                'eyebrow'        => self::eyebrowFor($m['classification'] ?? 'para', (float) ($m['geoScore'] ?? 0.0), $m['proximityKm'] ?? null),
                'dateLabel'      => Format::dateRangeShort($d['startDate'], $d['endDate']),
                'metaLabel'      => $d['metaLabel'],
                'startLabel'     => $d['meetingPointAddress'],
                'groupSize'      => $groupSize,
                'groupSizeLabel' => self::groupSizeLabel($groupSize),
                'reason'         => $m['reason'] ?? null,
                // Samodzielna fraza z Models\MatchEngine::alignmentDetail()
                // (np. "97 km od Twojego startu") — bez przedrostka "Do
                // uzgodnienia: oś", który stał się zbędny, gdy fraza sama się
                // tłumaczy (użytkownik: "nie wydaje mi się że potrzebujemy
                // przedrostek").
                'alignLabel'     => $m['alignDetail'] ?? null,
                'profileJustification' => $m['profileJustification'] ?? null,
                // Peleton (2026-08-12) — najmocniejszy powód pokazania tej karty
                // musi być WIDOCZNY, inaczej wyjazd „awansuje" bez wyjaśnienia.
                'pelotonLabel'   => self::pelotonLabel(
                    $m['pelotonNames'] ?? [],
                    (int) ($m['pelotonRiders'] ?? 0),
                    !empty($m['pelotonOrganizer'])
                ),
            ];
        }, $matches)));
    }

    // Fallback dla strony głównej, gdy MatchEngine nie ma czego dopasować
    // (anonimowy gość albo brak profilu/deklaracji) — zamiast pustego
    // "brak dopasowań" pokazujemy realne, otwarte ogłoszenia "Pokręcę z kimś"
    // z całego serwisu (bez personalizacji), żeby widget zawsze wyglądał tak
    // samo (jedna karta hero + reszta w sidebarze), patrz
    // match-suggestions.php i Controllers\HomeController.
    // $cards: Resources\EventCardResource::fromRow() dla eventType pokrec_z_kims,
    // POSORTOWANE malejąco po confirmedCount przez wywołującego (największa
    // grupa first — ta sama zasada co w prawdziwym dopasowaniu, patrz
    // docs/etap2 "rozmiar skupiska ponad jakość pary").
    public static function fromLooseRideFallback(array $cards, callable $urlBuilder): array
    {
        return array_map(function ($ev) use ($urlBuilder) {
            $middle = $ev['bikeTypeNames'] ?: null;
            $metaParts = array_filter([$middle ? mb_strtolower($middle) : null, $ev['regionName']]);
            $groupSize = $ev['confirmedCount'];
            return [
                'eventId'        => $ev['eventId'],
                'url'            => $urlBuilder($ev['slug']),
                'title'          => $ev['title'],
                'eyebrow'        => __('Ktoś szuka towarzystwa'),
                'dateLabel'      => Format::dateRangeShort($ev['startDate'], $ev['endDate']),
                'metaLabel'      => implode(' · ', $metaParts),
                'startLabel'     => $ev['meetingPointAddress'] ?? null,
                'groupSize'      => $groupSize,
                'groupSizeLabel' => self::groupSizeLabel($groupSize),
                'reason'         => null,
                'alignLabel'     => null,
                'profileJustification' => null,
            ];
        }, $cards);
    }

    // Uczciwe wobec realnej geografii — klasyfikacja rozmiaru grupy
    // (scalenie/dołączenie/para) NIC nie mówi o odległości (daty zawsze się
    // pokrywają, to twardy filtr z Models\MatchEngine::evaluate(), nie dowód
    // podobieństwa), więc bez $geoScore nagłówek fałszywie sugerował "podobny
    // wyjazd w Twoim terminie" nawet dla kandydata 300 km dalej. Zgłoszenie
    // użytkownika po zobaczeniu tego na żywo dla Podkarpacie -> Tatry.
    // $proximityKm — realnie ZMIERZONA odległość (null, gdy którakolwiek
    // strona nie ma współrzędnych). Słowa "blisko"/"okolica" wolno użyć
    // WYŁĄCZNIE przy faktycznym pomiarze (poprawka 2026-08-09): wcześniej
    // decydował sam geoScore, który przy zgodności REGIONU osiąga 1.0 nawet
    // gdy realny dystans to 200 km — nagłówek "Blisko Ciebie" był więc
    // obietnicą, której nikt nie sprawdził. Bez pomiaru mówimy tylko to, co
    // wiemy na pewno (termin/grupa), bez twierdzeń o geografii.
    private const CLOSE_ENOUGH_KM = 60.0;

    private static function eyebrowFor(string $classification, float $geoScore, ?float $proximityKm): string
    {
        if ($classification === 'scalenie') {
            return __('Bardzo podobna trasa');
        }
        $measuredClose = $proximityKm !== null && $proximityKm <= self::CLOSE_ENOUGH_KM;
        $measuredFar   = $proximityKm !== null && $proximityKm > self::CLOSE_ENOUGH_KM;

        if ($classification === 'dolaczenie') {
            if ($measuredClose) return __('Podobny termin i okolica');
            if ($measuredFar)   return __('Podobny termin, inna okolica');
            return __('Podobny termin');
        }
        if ($measuredClose) return __('Blisko Ciebie, szuka towarzystwa');
        return __('Ktoś szuka towarzystwa');
    }

    // Deklinacja liczebnik+rzeczownik+czasownik ("1 osoba jedzie" / "3 osoby
    // jadą" / "5 osób jedzie") — reguła standardowa dla zakresu realnego przy
    // wyjazdach rowerowych (pojedyncze/kilkanaście osób), bez pretensji do
    // pełnej poprawności dla większych liczb złożonych (22, 23...).
    public static function groupSizeLabel(int $n): string
    {
        if ($n === 1) {
            return __('osoba już jedzie');
        }
        $lastDigit = $n % 10;
        $lastTwo = $n % 100;
        if ($lastDigit >= 2 && $lastDigit <= 4 && !($lastTwo >= 12 && $lastTwo <= 14)) {
            return __('osoby już jadą');
        }
        return __('osób już jedzie');
    }

    // „Jedzie z nimi Michał W." / „Prowadzi ktoś, z kim jeździsz".
    //
    // Imiona, nie liczba — „2 osoby z Twojego peletonu" jest o wiele słabsze niż
    // jedno konkretne nazwisko, a to właśnie konkret decyduje o zapisie. Dwa
    // imiona maksimum, reszta jako „i inni": karta ma jedną linijkę, nie listę.
    //
    // Organizator wspomniany PIERWSZY, gdy oba fakty zachodzą — „ktoś, z kim
    // jeździłem, to prowadzi" jest mocniejszym sygnałem niż „ktoś tam jedzie".
    private static function pelotonLabel(array $names, int $riders, bool $organizer): ?string
    {
        if ($organizer && $names) {
            return 'Prowadzi ktoś, z kim jeździsz — jedzie też ' . self::joinNames($names) . '.';
        }
        if ($organizer) {
            return __('Prowadzi ktoś, z kim już jeździłeś.');
        }
        if ($names) {
            return 'Jedzie ' . self::joinNames($names) . ' z Twojego peletonu.';
        }
        // Peleton policzony, ale bez imion do pokazania — ktoś wyłączył się
        // z list (users.roster_visible, migr. 037). Mówimy wtedy ogólnie
        // zamiast milczeć: fakt jest prawdziwy, tożsamość ukryta.
        if ($riders > 0) {
            return $riders === 1
                ? __('Jedzie ktoś z Twojego peletonu.')
                : __('Jadą osoby z Twojego peletonu.');
        }
        return null;
    }

    private static function joinNames(array $names): string
    {
        $shown = array_slice($names, 0, 2);
        $rest = count($names) - count($shown);
        $txt = implode(' i ', $shown);
        return $rest > 0 ? $txt . ' i ' . $rest . ' inn' . ($rest === 1 ? 'a osoba' : 'e') : $txt;
    }

    private static function loadDetails(array $editionIds): array
    {
        if (empty($editionIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($editionIds), '?'));
        $stmt = Database::connection()->prepare("
            SELECT ed.id AS edition_id, ed.start_date, ed.end_date,
                   COALESCE(tot.duration_days, 1) AS duration_days,
                   card_region.name AS region_name,
                   card_type.name AS event_type_name,
                   e.meeting_point_address,
                   (SELECT GROUP_CONCAT(bdi.name SEPARATOR ' / ') FROM event_bike_types ebt
                      JOIN dictionary_items bdi ON bdi.id = ebt.bike_type_item_id
                     WHERE ebt.event_id = e.id) AS bike_type_names
            FROM event_editions ed
            JOIN events e ON e.id = ed.event_id
            LEFT JOIN event_totals tot ON tot.event_id = e.id
            LEFT JOIN (
                    SELECT er.event_id, GROUP_CONCAT(reg3.name ORDER BY reg3.sort_order SEPARATOR ', ') AS name
                      FROM event_regions er
                      JOIN dictionary_items reg3 ON reg3.id = er.region_item_id
                     GROUP BY er.event_id
              ) card_region ON card_region.event_id = e.id
            LEFT JOIN dictionary_items card_type ON card_type.id = e.event_type_item_id
            WHERE ed.id IN ($placeholders)
        ");
        $stmt->execute($editionIds);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $endDate = $row['end_date'] ?? date('Y-m-d', strtotime($row['start_date'] . ' +' . ((int) $row['duration_days'] - 1) . ' days'));
            // "typ roweru · region", z awaryjnym "typ wydarzenia · region" gdy
            // organizator nie ustawił typów roweru (dopuszczalne, patrz Etap 2).
            $middle = $row['bike_type_names'] ?: $row['event_type_name'];
            $metaParts = array_filter([mb_strtolower((string) $middle), $row['region_name']]);
            $out[(int) $row['edition_id']] = [
                'startDate' => $row['start_date'],
                'endDate'   => $endDate,
                'metaLabel' => implode(' · ', $metaParts),
                'meetingPointAddress' => $row['meeting_point_address'],
            ];
        }
        return $out;
    }
}
