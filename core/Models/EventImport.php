<?php
// core/Models/EventImport.php
// Rdzeń serwerowego importera wydarzeń (patrz tasks/active/importer-wydarzen.md).
// Bierze zwalidowany wynik ekstraktora (ai-engine/analyze.py, przez
// Utils\AiEngineBridge) i zapisuje go jako KANDYDATA w kolejce weryfikacji —
// przez istniejące Models\Event::save() (NIGDY surowy INSERT), w stanie
// 'oczekuje_weryfikacji', dokładnie tą samą ścieżką, którą chodzi zgłoszenie
// „w czyimś imieniu" z EventController::finishCreate.
//
// TRZY NIEZMIENNIKI (decyzje usera 2026-09-17):
//  1. ZAWSZE human-in-the-loop — nic nie publikuje się samo, kandydat czeka na
//     approve/reject w /admin.
//  2. Organizatorem NIGDY nie jest bot importera — organizer_id to pending
//     konto REALNEGO organizatora (User::createPending + updateName), więc
//     wyświetla się realny organizator i może przejąć wydarzenie przez
//     EventController::issueClaimLink. Bot jest organizatorem WYŁĄCZNIE, gdy
//     źródło nie ma ani nazwy, ani e-maila organizatora (admin poprawia przy
//     zatwierdzaniu).
//  3. Zero nowych tabel — dedup „już zaimportowane" opiera się o ślad w
//     events.custom_attributes (JSON), a nie o osobny rejestr.
namespace Models;

use Core\Database;
use Utils\AiEngineBridge;
use Utils\EventSourceFetcher;

class EventImport
{
    // Pełny import JEDNEGO adresu: pobranie -> ekstrakcja (silnik AI) -> dziennik
    // -> kandydat w kolejce. Wspólny rdzeń dla workera CLI (import_events.php)
    // i panelu web (Admin\ImporterController), żeby logika żyła w JEDNYM miejscu.
    // Nigdy nie rzuca — zwraca ['status' => created|possible_duplicate|skipped|
    // js_rendered|error, 'slug', 'reason', 'duplicateOf'].
    public static function importUrl(string $url): array
    {
        // Facebook/Instagram/X… — treść za logowaniem/JS; serwer jej nie odczyta,
        // a scraping łamie regulamin. Odmawiamy PRZED pobraniem (bez wiszącego
        // fetcha), kierując na rozszerzenie Chrome.
        if (EventSourceFetcher::isUnsupportedHost($url)) {
            return ['status' => 'unsupported_source', 'slug' => null,
                    'reason' => 'Facebook/Instagram i podobne wymagają rozszerzenia Chrome („🧠 AI-Engine" na otwartej, wyrenderowanej stronie) — serwer nie odczyta treści za logowaniem.',
                    'duplicateOf' => null];
        }

        try {
            $payload = EventSourceFetcher::fetch($url);
        } catch (\Throwable $e) {
            return ['status' => 'error', 'slug' => null, 'reason' => $e->getMessage(), 'duplicateOf' => null];
        }

        // SPA/JS — serwer dostał samą skorupę; nie ma sensu słać jej do modelu.
        if (EventSourceFetcher::looksJsRendered($payload)) {
            return ['status' => 'js_rendered', 'slug' => null,
                    'reason' => 'strona renderowana JavaScriptem (SPA) — użyj rozszerzenia Chrome („🔗 Zbierz linki")',
                    'duplicateOf' => null];
        }

        // Wskazówka z wcześniejszych analiz tej domeny (knowledge base v1).
        $domain = parse_url($url, PHP_URL_HOST);
        if ($domain) {
            $hint = AiImportLog::domainSummary(preg_replace('/^www\./', '', $domain));
            if ($hint) {
                $payload['knowledgeHint'] = $hint;
            }
        }

        // Strażnik rozmiaru — nie wysyłaj gigantycznej strony do modelu (long
        // hang na timeout mostu, koszt). Ten sam limit co endpoint rozszerzenia.
        $maxBytes = (int) (APP_CONFIG['ai_engine']['max_payload_bytes'] ?? 2_000_000);
        if (strlen((string) json_encode($payload)) > $maxBytes) {
            return ['status' => 'error', 'slug' => null,
                    'reason' => 'Strona jest za duża do analizy (limit ' . $maxBytes . ' B).', 'duplicateOf' => null];
        }

        try {
            $extracted = AiEngineBridge::analyze($payload);
        } catch (\Throwable $e) {
            return ['status' => 'error', 'slug' => null, 'reason' => $e->getMessage(), 'duplicateOf' => null];
        }

        AiImportLog::record($url, $payload, $extracted);

        try {
            return self::queueCandidate($extracted, $payload);
        } catch (\Throwable $e) {
            return ['status' => 'error', 'slug' => null, 'reason' => $e->getMessage(), 'duplicateOf' => null];
        }
    }

    private const EVENT_TYPES = ['ustawka', 'wycieczka_wielodniowa', 'pokrec_z_kims', 'wyscig'];
    // Próg podobieństwa tytułów (procent similar_text) uznawany za możliwy
    // duplikat, gdy daty są zbieżne (±1 dzień). Dobrany zachowawczo: fałszywy
    // „to nie duplikat" tylko doda kandydata do przeglądu (człowiek i tak
    // patrzy), fałszywy „to duplikat" mógłby ukryć realnie nowe wydarzenie —
    // więc wolimy raczej NIE oznaczyć niż oznaczyć błędnie.
    private const DUP_TITLE_PCT = 88.0;

    // Zapisuje kandydata z importu. Zwraca:
    //   ['status' => 'created'|'possible_duplicate'|'skipped', 'slug' => ?string,
    //    'reason' => ?string, 'duplicateOf' => ?string]
    public static function queueCandidate(array $extracted, array $payload): array
    {
        $title = trim((string) ($extracted['title'] ?? ''));
        if ($title === '') {
            return ['status' => 'skipped', 'slug' => null, 'reason' => 'brak tytułu', 'duplicateOf' => null];
        }

        $startDate = self::resolveStartDate($extracted);
        if ($startDate === null) {
            return ['status' => 'skipped', 'slug' => null, 'reason' => 'brak daty wydarzenia', 'duplicateOf' => null];
        }

        $sourceUrl = (string) ($payload['sourceUrl'] ?? '');

        // Dedup intra-źródłowy: to źródło już raz utworzyło wydarzenie -> nie
        // twórz drugiego (ślad w custom_attributes.source_url).
        if ($sourceUrl !== '') {
            $already = self::findEventBySourceUrl($sourceUrl);
            if ($already !== null) {
                return ['status' => 'skipped', 'slug' => $already, 'reason' => 'już zaimportowane z tego adresu', 'duplicateOf' => $already];
            }
        }

        // Dedup inter-źródłowy: podobny tytuł + zbieżna data w istniejących
        // wydarzeniach -> NIE pomijamy (mógłby być realnie inny event), tylko
        // OZNACZAMY kandydata, żeby człowiek rozstrzygnął.
        $dup = self::findPossibleDuplicate($title, $startDate);

        $organizerId = self::resolveOrganizer(
            $extracted['organizer'] ?? null,
            $extracted['organizerEmail'] ?? null,
            $startDate
        );
        Organizer::ensureProfile($organizerId);

        $provenance = [
            'source'       => 'ridemore-importer',
            'source_url'   => $sourceUrl ?: null,
            'source_domain' => $sourceUrl !== '' ? preg_replace('/^www\./', '', (string) parse_url($sourceUrl, PHP_URL_HOST)) : null,
            'confidence'   => $extracted['confidence'] ?? null,
            'model'        => getenv('AI_PROVIDER') ?: null,
            'imported_at'  => date('c'),
        ];
        if ($dup !== null) {
            $provenance['duplicate_of'] = $dup['slug'];
        }

        $input = self::buildInput($extracted, $payload, $organizerId, $startDate);
        $input['status'] = 'oczekuje_weryfikacji';
        // Kto WSTAWIŁ (audyt) — źródło; nie realny organizator (ten jest w
        // organizer_id). Bez e-maila zgłaszającego: approve/reject nie ma tu
        // kogo powiadamiać (organizatora zawiadamia admin przez issueClaimLink).
        $input['submitterName'] = 'Importer: ' . ($provenance['source_domain'] ?? 'źródło zewnętrzne');
        $input['submitterEmail'] = null;
        $input['customAttributes'] = $provenance;

        $slug = Event::save($input);

        return [
            'status'      => $dup !== null ? 'possible_duplicate' : 'created',
            'slug'        => $slug,
            'reason'      => null,
            'duplicateOf' => $dup['slug'] ?? null,
        ];
    }

    // organizer_id = REALNY organizator jako pending konto (claimable), nigdy
    // bot — chyba że nie ma ani nazwy, ani e-maila (skrajny fallback).
    public static function resolveOrganizer(?string $name, ?string $email, string $startDate): int
    {
        $name = $name !== null ? trim($name) : '';
        $email = $email !== null ? trim($email) : '';
        $validEmail = filter_var($email, FILTER_VALIDATE_EMAIL) ?: null;

        if ($validEmail !== null) {
            $user = User::findByEmail($validEmail) ?? User::createPending($validEmail);
            if ($name !== '' && ($user->name ?? null) !== $name) {
                $user->updateName($name);
            }
            return (int) $user->id;
        }

        if ($name !== '') {
            // Mamy nazwę, brak e-maila: tworzymy pending konto realnego
            // organizatora z syntetycznym, unikalnym adresem — organizatorem
            // wciąż jest realny podmiot (wyświetlana nazwa = jego), a admin
            // uzupełni prawdziwy e-mail przed wydaniem linku przejęcia. Domena
            // .local jest niewysyłalna z założenia (żaden mail tam nie poleci).
            $synthetic = 'import-' . substr(sha1(mb_strtolower($name) . '|' . $startDate), 0, 16) . '@import.ridemore.local';
            $user = User::findByEmail($synthetic) ?? User::createPending($synthetic);
            if (($user->name ?? null) !== $name) {
                $user->updateName($name);
            }
            return (int) $user->id;
        }

        // Skrajny fallback: źródło nie podało organizatora. Bot jako właściciel
        // techniczny; admin przypisze realnego organizatora przy zatwierdzaniu.
        $botEmail = (defined('APP_CONFIG') ? (APP_CONFIG['event_import']['bot_email'] ?? null) : null) ?: 'importer@ridemore.bike';
        $bot = User::findByEmail($botEmail) ?? User::createPending($botEmail);
        if (($bot->name ?? null) === null || $bot->name === '') {
            $bot->updateName('Importer ridemore');
        }
        return (int) $bot->id;
    }

    // Slug wydarzenia już zaimportowanego z tego samego adresu (albo null).
    public static function findEventBySourceUrl(string $url): ?string
    {
        $stmt = Database::connection()->prepare(
            "SELECT slug FROM events
             WHERE JSON_UNQUOTE(JSON_EXTRACT(custom_attributes, '$.source_url')) = :url
             LIMIT 1"
        );
        $stmt->execute(['url' => $url]);
        $slug = $stmt->fetchColumn();
        return $slug === false ? null : (string) $slug;
    }

    // Możliwy duplikat: zbieżna data (±1 dzień) + podobny tytuł.
    public static function findPossibleDuplicate(string $title, string $startDate): ?array
    {
        $d1 = date('Y-m-d', strtotime($startDate . ' -1 day'));
        $d2 = date('Y-m-d', strtotime($startDate . ' +1 day'));
        $stmt = Database::connection()->prepare(
            'SELECT id, slug, title FROM events WHERE start_date BETWEEN :d1 AND :d2'
        );
        $stmt->execute(['d1' => $d1, 'd2' => $d2]);

        $needle = self::normalizeTitle($title);
        if ($needle === '') {
            return null;
        }
        foreach ($stmt->fetchAll() as $row) {
            $hay = self::normalizeTitle((string) $row['title']);
            if ($hay === '') {
                continue;
            }
            if ($hay === $needle) {
                return ['id' => (int) $row['id'], 'slug' => (string) $row['slug'], 'title' => (string) $row['title']];
            }
            similar_text($needle, $hay, $pct);
            if ($pct >= self::DUP_TITLE_PCT) {
                return ['id' => (int) $row['id'], 'slug' => (string) $row['slug'], 'title' => (string) $row['title']];
            }
        }
        return null;
    }

    public static function normalizeTitle(string $t): string
    {
        $t = mb_strtolower(trim($t), 'UTF-8');
        $t = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $t) ?? '';
        return trim(preg_replace('/\s+/u', ' ', $t) ?? '');
    }

    // Data startu z ekstraktora: główna data albo (dla wielodniówki) data
    // pierwszego etapu. Zwraca Y-m-d albo null (bez daty nie da się zapisać —
    // Event::save i tak by rzucił).
    private static function resolveStartDate(array $extracted): ?string
    {
        $candidates = [];
        if (!empty($extracted['dateIso'])) {
            $candidates[] = (string) $extracted['dateIso'];
        }
        foreach ((array) ($extracted['stages'] ?? []) as $s) {
            if (!empty($s['dateIso'])) {
                $candidates[] = (string) $s['dateIso'];
            }
        }
        foreach ($candidates as $c) {
            $ts = strtotime($c);
            if ($ts !== false) {
                return date('Y-m-d', $ts);
            }
        }
        return null;
    }

    // Buduje tablicę w kształcie Resources\EventFormInput::fromRequest (to, co
    // czyta Models\Event::save). Świadomie POMIJA: GPX (admin dograva), zdjęcie
    // okładkowe, warianty trasy, oraz limity uczestników (podatne na halucynację
    // — patrz reguła anty-halucynacyjna w prompt.py i pamięć projektu).
    private static function buildInput(array $extracted, array $payload, int $organizerId, string $startDate): array
    {
        $type = in_array($extracted['eventType'] ?? null, self::EVENT_TYPES, true)
            ? $extracted['eventType']
            : 'ustawka';

        $links = (array) ($payload['links'] ?? []);

        $stages = [];
        if ($type === 'wycieczka_wielodniowa' && !empty($extracted['stages'])) {
            foreach ($extracted['stages'] as $i => $s) {
                $stages[] = [
                    'date'       => $i === 0 ? $startDate : (self::isoOrNull($s['dateIso'] ?? null)),
                    'title'      => $s['title'] ?? null,
                    'startLabel' => $s['startPoint'] ?? null,
                    'endLabel'   => $s['endPoint'] ?? null,
                    'distanceKm' => is_numeric($s['distanceKm'] ?? null) ? (float) $s['distanceKm'] : 0,
                    'elevationM' => is_numeric($s['elevationM'] ?? null) ? (int) $s['elevationM'] : 0,
                    'gpxUrl'     => null,
                ];
            }
        }
        // Zawsze musi być co najmniej jeden etap z datą (Event::save bierze z
        // stages[0]['date'] datę startu). Dla typów jednodniowych i awaryjnie
        // dla wielodniówki bez etapów budujemy jeden etap.
        if (empty($stages)) {
            $stages[] = [
                'date'       => $startDate,
                'startLabel' => $extracted['meetingPointLabel'] ?? null,
                'distanceKm' => is_numeric($extracted['distanceKm'] ?? null) ? (float) $extracted['distanceKm'] : 0,
                'elevationM' => is_numeric($extracted['elevationM'] ?? null) ? (int) $extracted['elevationM'] : 0,
                'surface'    => $extracted['surface'] ?? null,
                'gpxUrl'     => null,
            ];
        } else {
            $stages[0]['date'] = $startDate;
        }

        $equipment = [];
        foreach ((array) ($extracted['whatToBring'] ?? []) as $item) {
            $name = trim((string) $item);
            if ($name !== '') {
                $equipment[] = ['name' => $name, 'mandatory' => false];
            }
        }

        // Rejestracja z importu jest ZAWSZE zewnętrzna — nie zapisujemy ludzi u
        // nas na cudze, niezweryfikowane wydarzenie. Link zapisów, jeśli model go
        // wskazał; w ostateczności adres źródła, żeby uczestnik miał dokąd trafić.
        $externalUrl = self::linkUrl($extracted['registrationLinkIndex'] ?? null, $links)
            ?? (($payload['sourceUrl'] ?? '') ?: null);

        $isPaid = ($extracted['isPaid'] ?? null) === true
            && is_numeric($extracted['priceAmount'] ?? null)
            && (float) $extracted['priceAmount'] > 0;

        $pricing = null;
        if ($isPaid) {
            $items = [];
            foreach ((array) ($extracted['priceIncluded'] ?? []) as $c) {
                if (trim((string) $c) !== '') {
                    $items[] = ['category' => trim((string) $c), 'isIncluded' => true];
                }
            }
            foreach ((array) ($extracted['priceExcluded'] ?? []) as $c) {
                if (trim((string) $c) !== '') {
                    $items[] = ['category' => trim((string) $c), 'isIncluded' => false];
                }
            }
            $pricing = [
                'amount'                   => (float) $extracted['priceAmount'],
                'currency'                 => $extracted['priceCurrency'] ?? 'PLN',
                'unit'                     => $extracted['priceUnit'] ?? 'per_person',
                'deposit'                  => null,
                'paymentDeadlineDays'      => is_numeric($extracted['paymentDeadlineDays'] ?? null) ? (int) $extracted['paymentDeadlineDays'] : null,
                'cancellationDeadlineDays' => is_numeric($extracted['cancellationDeadlineDays'] ?? null) ? (int) $extracted['cancellationDeadlineDays'] : null,
                'cancellationPolicy'       => $extracted['cancellationPolicy'] ?? null,
                'items'                    => $items,
            ];
        }

        $additionalDates = [];
        foreach ((array) ($extracted['additionalDates'] ?? []) as $d) {
            $iso = self::isoOrNull($d);
            if ($iso !== null && $iso !== $startDate) {
                $additionalDates[] = $iso;
            }
        }

        return [
            'existingId'             => null,
            'organizerId'            => $organizerId,
            'type'                   => $type,
            'title'                  => trim((string) $extracted['title']),
            'description'            => $extracted['description'] ?? null,
            'coverPhotoUrl'          => null,
            'isPaid'                 => $isPaid,
            'registrationType'       => 'external',
            'externalUrl'            => $externalUrl,
            'externalPhone'          => $extracted['registrationPhone'] ?? null,
            'externalEmail'          => filter_var((string) ($extracted['registrationEmail'] ?? ''), FILTER_VALIDATE_EMAIL) ?: null,
            'region'                 => $extracted['region'] ?? null,
            'meetingPointAddress'    => $extracted['meetingPointLabel'] ?? null,
            'meetingPointLat'        => null,
            'meetingPointLng'        => null,
            'difficulty'             => $extracted['difficulty'] ?? null,
            'pace'                   => $extracted['pace'] ?? null,
            'bikeTypes'              => array_values(array_filter((array) ($extracted['bikeTypes'] ?? []), 'is_string')),
            // Limity uczestników POMIJANE świadomie (halucynacja) — bez limitu.
            'limitParticipants'      => false,
            'minParticipants'        => null,
            'maxParticipants'        => null,
            'startTime'              => self::timeOrNull($extracted['time'] ?? null),
            'endDate'                => $type === 'pokrec_z_kims' ? self::isoOrNull($extracted['endDate'] ?? null) : null,
            'dateIsFlexible'         => $type === 'pokrec_z_kims' ? (($extracted['dateIsFlexible'] ?? null) === true) : false,
            'equipment'              => $equipment,
            'stages'                 => $stages,
            'variants'               => [],
            'additionalEditionDates' => $additionalDates,
            'pricing'                => $pricing,
        ];
    }

    private static function linkUrl($index, array $links): ?string
    {
        if (!is_int($index)) {
            return null;
        }
        foreach ($links as $l) {
            if (is_array($l) && ($l['index'] ?? null) === $index) {
                $href = trim((string) ($l['href'] ?? ''));
                return $href !== '' ? $href : null;
            }
        }
        return null;
    }

    private static function isoOrNull($v): ?string
    {
        if (!is_string($v) || trim($v) === '') {
            return null;
        }
        $ts = strtotime($v);
        return $ts !== false ? date('Y-m-d', $ts) : null;
    }

    private static function timeOrNull($v): ?string
    {
        if (!is_string($v) || trim($v) === '') {
            return null;
        }
        return preg_match('/^\d{1,2}:\d{2}/', trim($v)) ? substr(trim($v), 0, 5) : null;
    }
}
