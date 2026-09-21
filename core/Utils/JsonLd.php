<?php
// core/Utils/JsonLd.php
// Dane strukturalne schema.org (JSON-LD) dla stron publicznych — Event i
// Organization/organizer, każdy z dołączonym BreadcrumbList w tym samym
// @graph (jeden <script type="application/ld+json"> na stronę zamiast kilku).
// Zwracany string trafia do $jsonLd w layout.php (patrz views/web/layout.php).
namespace Utils;

use Models\Organizer;

class JsonLd
{
    private const STATUS_MAP = [
        'published'            => 'https://schema.org/EventScheduled',
        'oczekuje_weryfikacji'  => 'https://schema.org/EventScheduled',
        'cancelled'             => 'https://schema.org/EventCancelled',
        'completed'             => 'https://schema.org/EventScheduled',
    ];

    // $eventData: tablica z EventResource::fromModel(). $canonicalUrl: pełny
    // adres strony eventu. Świadomie BEZ BreadcrumbList tutaj (od przebudowy
    // strony wg szablony/wydarzenie.html) — event-page.php renderuje teraz
    // partials/breadcrumbs.php z pełną, wielopoziomową ścieżką (Start/Wyjazdy/
    // Region/Tytuł), który sam emituje dokładniejszy BreadcrumbList; ten sam
    // wzorzec co forOrganizer() niżej — dublowanie dałoby dwa BreadcrumbList
    // na tej samej stronie.
    public static function forEvent(array $eventData, string $canonicalUrl): string
    {
        // Turnusy (patrz Models\EventEdition) — startDate to zawsze data
        // WYBRANEGO na stronie turnusu (ta sama, którą widzi odwiedzający pod
        // ?termin=, patrz EventController::show()), nie zawsze pierwszy etap
        // szablonu trasy. $canonicalUrl jest ten sam niezależnie od ?termin=
        // (View::currentCanonicalUrl() obcina query string), więc różne turnusy
        // nie tworzą duplikującej się treści w oczach wyszukiwarki.
        $editions = $eventData['editions'] ?? [];
        $selectedEditionId = $eventData['selectedEditionId'] ?? null;
        $selectedEdition = null;
        foreach ($editions as $ed) {
            if ($ed['id'] === $selectedEditionId) { $selectedEdition = $ed; break; }
        }
        $startDate = $selectedEdition['startDate'] ?? ($eventData['stages'][0]['date'] ?? null);

        // Wymagania Google dla Event (developers.google.com/search/docs/
        // appearance/structured-data/event, sprawdzone 2026-09-14): startDate
        // i location z PostalAddress są OBOWIĄZKOWE. Bez daty albo przy
        // „terminie do uzgodnienia" nie ma czego deklarować — zmyślona data
        // to dane niezgodne z treścią strony, za które Google zdejmuje wyniki
        // rozszerzone całej domenie. Wtedy strona zostaje bez znacznika Event.
        $location = self::eventLocation($eventData);
        if (!$startDate || !empty($selectedEdition['dateIsFlexible']) || $location === null) {
            return '';
        }

        $event = [
            '@type'       => 'Event',
            // Język treści tej wersji strony (nazwa i opis są już w nim —
            // patrz Resources\EventResource::przetlumacz).
            'inLanguage'  => \Core\Lang::current(),
            'name'        => $eventData['title'],
            'description' => $eventData['description'] ? self::excerpt($eventData['description'], 500) : $eventData['title'],
            'url'         => $canonicalUrl,
            'eventStatus' => self::STATUS_MAP[$eventData['statusCode']] ?? 'https://schema.org/EventScheduled',
            'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
            // Z godziną: pełny czas z przesunięciem strefy (wymóg Google);
            // bez godziny sama data — północ byłaby nieprawdą.
            'startDate'   => self::isoDate($startDate, $selectedEdition['startTime'] ?? null),
            'location'    => $location,
        ];
        $endDate = $selectedEdition['endDate'] ?? (count($eventData['stages'] ?? []) > 1 ? end($eventData['stages'])['date'] : null);
        if ($endDate && $endDate !== $startDate) {
            $event['endDate'] = $endDate;
        }
        if (!empty($eventData['coverPhotoUrl'])) {
            $event['image'] = [View::absoluteUrl($eventData['coverPhotoUrl'])];
        }
        if (!empty($eventData['organizer']['name'])) {
            $organizerLd = [
                '@type' => 'Organization',
                'name'  => $eventData['organizer']['name'],
            ];
            if (!empty($eventData['organizer']['slug'])) {
                $organizerLd['url'] = View::absoluteUrl('/organizatorzy/' . $eventData['organizer']['slug']);
            }
            $event['organizer'] = $organizerLd;
        }
        // Oferta także dla bezpłatnych (cena 0) — „bezpłatne" to dla szukającego
        // informacja, nie brak informacji. Cena = najniższa z wariantów trasy.
        $prices = array_filter(
            array_merge(
                [$eventData['pricing']['amount'] ?? null],
                array_column($eventData['variants'] ?? [], 'priceAmount')
            ),
            fn($p) => $p !== null && (float) $p > 0
        );
        if ($eventData['statusCode'] !== 'cancelled') {
            $event['offers'] = [
                '@type'         => 'Offer',
                'price'         => $eventData['isPaid'] && $prices ? (string) min(array_map('floatval', $prices)) : '0',
                'priceCurrency' => $eventData['pricing']['currency'] ?? 'PLN',
                'url'           => $canonicalUrl,
                'availability'  => $eventData['statusCode'] === 'full'
                    ? 'https://schema.org/SoldOut'
                    : 'https://schema.org/InStock',
            ];
        }

        // Inne, nieodwołane terminy tego samego wydarzenia (turnusy) jako
        // subEvent — wyszukiwarka widzi wszystkie daty, nie tylko tę aktualnie
        // wybraną na stronie (adres wszystkich jest identyczny, patrz wyżej).
        $otherEditions = array_values(array_filter(
            $editions,
            fn($ed) => $ed['id'] !== $selectedEditionId && !$ed['isCancelled'] && empty($ed['dateIsFlexible'])
        ));
        if ($otherEditions) {
            $event['subEvent'] = array_map(function ($ed) use ($eventData, $event) {
                $sub = [
                    '@type'               => 'Event',
                    'name'                => $eventData['title'],
                    'startDate'           => self::isoDate($ed['startDate'], $ed['startTime'] ?? null),
                    'eventStatus'         => 'https://schema.org/EventScheduled',
                    'eventAttendanceMode' => $event['eventAttendanceMode'],
                    'location'            => $event['location'],
                    'url'                 => $event['url'],
                ];
                if (!empty($ed['endDate']) && $ed['endDate'] !== $ed['startDate']) {
                    $sub['endDate'] = $ed['endDate'];
                }
                return $sub;
            }, $otherEditions);
        }

        return self::render([$event]);
    }

    // Data ISO 8601; z godziną — z przesunięciem strefy liczonym dla TEGO dnia
    // (czas letni/zimowy), bo sama godzina bez strefy jest dla Google
    // niejednoznaczna. Strefa WPISANA NA SZTYWNO, nie date_default_timezone_get():
    // aplikacja jej nigdzie nie ustawia, a serwer produkcyjny może stać na UTC —
    // wtedy 9:30 wpisane przez organizatora wyszłoby w Google jako 11:30.
    public static function isoDate(string $date, ?string $time): string
    {
        if (!$time) {
            return substr($date, 0, 10);
        }
        try {
            $dt = new \DateTimeImmutable(substr($date, 0, 10) . ' ' . substr($time, 0, 5), new \DateTimeZone('Europe/Warsaw'));
            return $dt->format('Y-m-d\TH:iP');
        } catch (\Exception $e) {
            return substr($date, 0, 10);
        }
    }

    // Opis bez ucięcia w pół słowa i bez surowych znaków nowej linii.
    public static function excerpt(string $text, int $max): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)));
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $cut = mb_substr($text, 0, $max);
        $space = mb_strrpos($cut, ' ');
        return rtrim($space > $max * 0.6 ? mb_substr($cut, 0, $space) : $cut, ' ,.;:—-') . '…';
    }

    private const COUNTRY_CODES = [
        'polska' => 'PL', 'poland' => 'PL', 'czechy' => 'CZ', 'česko' => 'CZ', 'slovensko' => 'SK',
        'słowacja' => 'SK', 'niemcy' => 'DE', 'deutschland' => 'DE', 'litwa' => 'LT', 'austria' => 'AT',
        'österreich' => 'AT', 'węgry' => 'HU', 'włochy' => 'IT', 'italia' => 'IT', 'hiszpania' => 'ES',
        'españa' => 'ES', 'francja' => 'FR', 'france' => 'FR', 'chorwacja' => 'HR', 'hrvatska' => 'HR',
        'słowenia' => 'SI', 'ukraina' => 'UA', 'dania' => 'DK', 'holandia' => 'NL', 'portugalia' => 'PT',
    ];

    /**
     * Adres zbiórki rozłożony na pola PostalAddress. W bazie leży jeden napis
     * w kształcie geokodera („Miejsce, Dzielnica, Miasto, województwo X,
     * Polska"), więc pola to odczyt tego kształtu, nie zgadywanie z treści.
     * Zwraca: street, locality, region, postalCode, country (kod ISO) — każde
     * może być null.
     */
    public static function addressParts(?string $address): array
    {
        $out = ['street' => null, 'locality' => null, 'region' => null, 'postalCode' => null, 'country' => null];
        $parts = array_values(array_filter(array_map('trim', explode(',', (string) $address)), 'strlen'));
        if (!$parts) {
            return $out;
        }
        foreach ($parts as $i => $p) {
            if (preg_match('/\b(\d{2}-\d{3})\b/u', $p, $m)) {
                $out['postalCode'] = $m[1];
                $rest = trim(str_replace($m[1], '', $p));
                if ($rest === '') { unset($parts[$i]); } else { $parts[$i] = $rest; }
            }
        }
        $parts = array_values($parts);
        $last = mb_strtolower(end($parts));
        if (isset(self::COUNTRY_CODES[$last])) {
            $out['country'] = self::COUNTRY_CODES[$last];
            array_pop($parts);
        }
        foreach ($parts as $i => $p) {
            if (preg_match('/^(województwo|kraj|region|voivodeship)\b/iu', $p)) {
                $out['region'] = $p;
                array_splice($parts, $i, 1);
                break;
            }
        }
        // Miejscowość czytamy tylko z adresu w kształcie geokodera (jest kraj,
        // województwo albo kod pocztowy). Ręcznie wpisane „Rynek w Lesku,
        // parking przy dworcu" nie ma miejscowości na ostatnim miejscu —
        // wtedy cały napis idzie jako ulica, bez zgadywania.
        $structured = $out['country'] || $out['region'] || $out['postalCode'];
        if ($parts && $structured) {
            $out['locality'] = array_pop($parts);
        }
        if ($parts) {
            $out['street'] = implode(', ', $parts);
        }
        return $out;
    }

    // Place z PostalAddress i współrzędnymi. Bez adresu zbiórki — sam region
    // (Place musi mieć jakiś adres); bez obu — null.
    private static function eventLocation(array $eventData): ?array
    {
        $raw = trim((string) ($eventData['meetingPointAddress'] ?? ''));
        if ($raw === '' && empty($eventData['regionLabel'])) {
            return null;
        }
        $a = self::addressParts($raw);
        $address = array_filter([
            '@type'           => 'PostalAddress',
            'streetAddress'   => $a['street'],
            'addressLocality' => $a['locality'],
            'postalCode'      => $a['postalCode'],
            'addressRegion'   => $a['region'] ?? ($eventData['regionLabel'] ?? null),
            'addressCountry'  => $a['country'],
        ]);
        $place = [
            '@type'   => 'Place',
            'name'    => $raw !== '' ? (explode(',', $raw)[0]) : $eventData['regionLabel'],
            'address' => $address,
        ];
        if (!empty($eventData['meetingPointLat']) && !empty($eventData['meetingPointLng'])) {
            $place['geo'] = [
                '@type'     => 'GeoCoordinates',
                'latitude'  => (float) $eventData['meetingPointLat'],
                'longitude' => (float) $eventData['meetingPointLng'],
            ];
        }
        return $place;
    }

    // Bez BreadcrumbList tutaj — organizer-profile.php (jak teraz event-page.php
    // wyżej) renderuje partials/breadcrumbs.php, który sam emituje swój
    // JSON-LD z dokładniejszych danych trasy; dublowanie dałoby dwa
    // BreadcrumbList na tej samej stronie.
    public static function forOrganizer(Organizer $organizer, array $reviewStats, string $canonicalUrl): string
    {
        $org = [
            '@type' => 'Organization',
            'name'  => $organizer->name,
            'url'   => $canonicalUrl,
        ];
        if (!empty($organizer->bio)) {
            $org['description'] = mb_substr(trim($organizer->bio), 0, 500);
        }
        if (!empty($organizer->avatarUrl)) {
            $org['logo'] = View::absoluteUrl($organizer->avatarUrl);
            $org['image'] = View::absoluteUrl($organizer->avatarUrl);
        }
        if (!empty($organizer->phone)) {
            $org['telephone'] = $organizer->phone;
        }
        if (!empty($organizer->city)) {
            $org['address'] = [
                '@type'         => 'PostalAddress',
                'addressLocality' => $organizer->city,
                'addressRegion'   => $organizer->regionName,
                'addressCountry'  => 'PL',
            ];
        }
        $sameAs = array_values(array_filter([
            $organizer->websiteUrl ?? null,
            $organizer->facebookUrl ?? null,
            $organizer->instagramUrl ?? null,
            $organizer->stravaUrl ?? null,
        ]));
        if ($sameAs) {
            $org['sameAs'] = $sameAs;
        }
        // aggregateRating tylko gdy są realne opinie — Google traktuje ten
        // typ restrykcyjnie, deklarowanie go bez recenzji to typowy powód
        // ręcznej penalizacji w Search Console.
        if ($reviewStats['avg'] !== null && $reviewStats['count'] > 0) {
            $org['aggregateRating'] = [
                '@type'       => 'AggregateRating',
                'ratingValue' => $reviewStats['avg'],
                'reviewCount' => $reviewStats['count'],
            ];
        }

        return self::render([$org]);
    }

    // Lista wydarzeń pod /wydarzenia (patrz Controllers\EventsListController) —
    // ItemList zbudowana z już zmapowanych kart (Resources\EventCardResource),
    // bez pełnego opisu/lokalizacji (te dostaje dopiero strona pojedynczego
    // wydarzenia, patrz forEvent() wyżej) — wystarcza wyszukiwarce do
    // rozpoznania strony jako listingu wydarzeń.
    public static function forEventList(array $events, string $canonicalUrl): string
    {
        $items = [];
        // Format „strona podsumowania" (2026-09-14): sam adres każdej pozycji.
        // Pełne Event z karty nie ma adresu zbiórki, a Event bez location to
        // błąd w Search Console na KAŻDEJ pozycji listy. Pełne dane ma strona
        // wydarzenia, do której ten adres prowadzi.
        foreach (array_values($events) as $i => $ev) {
            $items[] = [
                '@type'    => 'ListItem',
                'position' => $i + 1,
                'url'      => View::absoluteUrl('/events/' . $ev['slug']),
                'name'     => $ev['title'],
            ];
        }
        $list = [
            '@type'           => 'ItemList',
            'url'             => $canonicalUrl,
            'numberOfItems'   => count($items),
            'itemListElement' => $items,
        ];
        return self::render([$list]);
    }

    // Pytania i odpowiedzi spod wydarzenia jako FAQPage (schema.org) —
    // dopisane 2026-08-09 razem z modułem Q&A (patrz Models\EventComment::
    // faqPairsForEvent()). Osobny <script>, nie dorzucane do @graph eventu:
    // FAQPage to inny BYT niż Event, a mieszanie ich w jednym grafie jako
    // równorzędne encje myli parsery co do tego, czym strona właściwie jest.
    //
    // UWAGA CO DO EFEKTU: Google od sierpnia 2023 pokazuje wyniki rozszerzone
    // z FAQ praktycznie wyłącznie dla stron rządowych i medycznych — dla
    // serwisu rowerowego NIE należy się spodziewać rozwijanych pytań w
    // wynikach. Znacznik zostaje mimo to, bo (1) jest poprawny i darmowy,
    // (2) pomaga wyszukiwarkom i asystentom AI zrozumieć treść strony,
    // (3) polityka Google bywa odwracana. Nie obiecywać userowi "gwiazdek".
    public static function forFaq(array $pairs, string $canonicalUrl): string
    {
        if (empty($pairs)) {
            return '';
        }
        $entities = [];
        foreach ($pairs as $p) {
            $q = trim((string) ($p['question'] ?? ''));
            $a = trim((string) ($p['answer'] ?? ''));
            if ($q === '' || $a === '') {
                continue;
            }
            $entities[] = [
                '@type'          => 'Question',
                'name'           => $q,
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $a],
            ];
        }
        if (empty($entities)) {
            return '';
        }
        return self::render([[
            '@type'      => 'FAQPage',
            'url'        => $canonicalUrl,
            'mainEntity' => $entities,
        ]]);
    }

    /**
     * Jeden gotowy węzeł schema.org, bez fabryki dla konkretnego typu.
     *
     * Istnieje dla stron, których byt nie ma tu własnego buildera i mieć nie
     * musi — dziś strona znanej trasy (`Place`). Publiczne, bo wołane spoza
     * klasy; cała ochrona przed wyjściem z tagu `<script>` siedzi w render()
     * niżej i tu też obowiązuje.
     */
    public static function forNode(array $node): string
    {
        return self::render([$node]);
    }

    private static function render(array $graph): string
    {
        $doc = ['@context' => 'https://schema.org', '@graph' => $graph];
        // Bez JSON_UNESCAPED_SLASHES celowo — domyślne escapowanie "/" jako "\/"
        // jest tu jedyną ochroną przed wyjściem z tagu <script> przez sekwencję
        // "</script>" wstrzykniętą w dane pochodzące od użytkownika (tytuł,
        // opis, bio organizatora).
        $json = json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        return '<script type="application/ld+json">' . "\n" . $json . "\n" . '</script>';
    }
}
