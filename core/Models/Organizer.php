<?php
// core/Models/Organizer.php
namespace Models;

use Core\Database;
use Utils\Format;

class Organizer
{
    public string $name;
    public string $email;
    public ?string $avatarUrl;
    public string $slug;
    public ?string $bio;
    /** @var string[] */
    public array $heroPhotoUrls;
    public string $organizerType;
    public ?string $tourismRegisterNumber;
    public float $ratingAvg;
    public int $reviewCount;
    public int $eventsOrganizedCount;
    public float $attendanceConfirmedRate;
    public int $userId;
    public bool $isVerified;
    public ?string $city;
    public ?string $regionCode;
    public ?string $regionName;
    public ?int $foundedYear;
    public ?string $languages;
    public ?string $phone;
    // Publiczny e-mail kontaktowy — niezależny od $email (adres logowania).
    // Gdy ustawiony, zastępuje $email jako odbiorca powiadomień o wydarzeniach
    // tego organizatora, patrz notificationEmail().
    public ?string $contactEmail;
    public ?string $websiteUrl;
    public ?string $facebookUrl;
    public ?string $instagramUrl;
    public ?string $stravaUrl;
    public bool $safetyRouteKnown;
    public bool $safetyFirstAidKit;
    public bool $safetySweepRider;
    public bool $safetySupportVehicle;
    public bool $safetyFirstAidCertified;
    public bool $safetyLiabilityInsurance;
    public bool $isUnclaimed;
    public bool $isActive;

    public static function findByUserId(int $userId): self
    {
        return self::findByColumn('u.id', $userId);
    }

    public static function findBySlug(string $slug): ?self
    {
        try {
            return self::findByColumn('op.slug', $slug);
        } catch (\RuntimeException $e) {
            return null;
        }
    }

    private static function findByColumn(string $column, $value): self
    {
        $pdo = Database::connection();
        // rating_avg/events_organized_count w organizer_profiles to zaseedowane,
        // nigdzie nie przeliczane kolumny (ten sam problem opisany przy
        // EventReview::statsFor()) — liczymy je na żywo jako correlated
        // subquery w tym samym SELECT (wzorzec jak w Organizer::search()),
        // zamiast dwóch osobnych zapytań — ta metoda woła się przy KAŻDYM
        // wyświetleniu strony eventu (przez Event::findBySlug()), więc to
        // było 3 round-tripy do bazy zamiast jednego.
        $stmt = $pdo->prepare("
            SELECT u.id AS user_id, u.name, u.email, u.avatar_url, u.phone, u.password_hash, op.slug, op.bio,
                   op.hero_photo_urls, op.is_active,
                   op.attendance_confirmed_rate, op.tourism_register_number, dit.code AS organizer_type_code,
                   dvs.code AS verification_status_code,
                   op.city, op.founded_year, op.languages, op.contact_email,
                   op.website_url, op.facebook_url, op.instagram_url, op.strava_url,
                   op.safety_route_known, op.safety_first_aid_kit, op.safety_sweep_rider,
                   op.safety_support_vehicle, op.safety_first_aid_certified, op.safety_liability_insurance,
                   dreg.code AS region_code, dreg.name AS region_name,
                   (SELECT AVG(rating) FROM event_reviews WHERE target_user_id = u.id) AS rating_avg,
                   (SELECT COUNT(*) FROM event_reviews WHERE target_user_id = u.id) AS review_count,
                   (SELECT COUNT(*) FROM events e
                       JOIN dictionary_items est ON est.id = e.status_item_id AND est.code = 'completed'
                      WHERE e.organizer_id = u.id) AS events_organized_count
            FROM users u
            JOIN organizer_profiles op ON op.user_id = u.id
            JOIN dictionary_items dit ON dit.id = op.organizer_type_item_id
            JOIN dictionary_items dvs ON dvs.id = op.verification_status_item_id
            LEFT JOIN dictionary_items dreg ON dreg.id = op.region_item_id
            WHERE $column = :value
        ");
        $stmt->execute(['value' => $value]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new \RuntimeException("Brak profilu organizatora dla $column=$value");
        }
        $userId = (int) $row['user_id'];

        $o = new self();
        $o->userId = $userId;
        // Świeżo zarejestrowani użytkownicy (e-mail + hasło) nie mają jeszcze
        // wypełnionego users.name — bez tego rzuciłoby TypeError (właściwość string).
        $o->name = $row['name'] ?: $row['email'];
        $o->email = $row['email'];
        $o->avatarUrl = $row['avatar_url'];
        $o->slug = $row['slug'];
        $o->bio = $row['bio'];
        $o->heroPhotoUrls = $row['hero_photo_urls'] !== null ? (json_decode($row['hero_photo_urls'], true) ?: []) : [];
        $o->organizerType = $row['organizer_type_code'];
        $o->tourismRegisterNumber = $row['tourism_register_number'];
        $o->ratingAvg = $row['rating_avg'] !== null ? round((float) $row['rating_avg'], 1) : 0.0;
        $o->reviewCount = (int) $row['review_count'];
        $o->eventsOrganizedCount = (int) $row['events_organized_count'];
        $o->attendanceConfirmedRate = (float) $row['attendance_confirmed_rate'];
        $o->isVerified = $row['verification_status_code'] === 'verified';
        $o->city = $row['city'];
        $o->regionCode = $row['region_code'];
        $o->regionName = $row['region_name'];
        $o->foundedYear = $row['founded_year'] !== null ? (int) $row['founded_year'] : null;
        $o->languages = $row['languages'];
        $o->phone = $row['phone'];
        $o->contactEmail = $row['contact_email'];
        $o->websiteUrl = $row['website_url'];
        $o->facebookUrl = $row['facebook_url'];
        $o->instagramUrl = $row['instagram_url'];
        $o->stravaUrl = $row['strava_url'];
        $o->safetyRouteKnown = (bool) $row['safety_route_known'];
        $o->safetyFirstAidKit = (bool) $row['safety_first_aid_kit'];
        $o->safetySweepRider = (bool) $row['safety_sweep_rider'];
        $o->safetySupportVehicle = (bool) $row['safety_support_vehicle'];
        $o->safetyFirstAidCertified = (bool) $row['safety_first_aid_certified'];
        $o->safetyLiabilityInsurance = (bool) $row['safety_liability_insurance'];
        // Konto założone automatycznie przy zgłoszeniu eventu "w czyimś imieniu"
        // (User::createPending()) nie ma jeszcze hasła — patrz sekcja przejęcia
        // profilu na organizer-profile.php i POST /organizatorzy/{slug}/przejmij.
        $o->isUnclaimed = $row['password_hash'] === null;
        $o->isActive = (bool) $row['is_active'];
        return $o;
    }

    /**
     * Czy to konto JEST organizatorem.
     *
     * Rola nie ma własnej kolumny na `users` i mieć nie musi: organizatorem
     * jest ten, kto ma wiersz w `organizer_profiles`, a wiersz powstaje przy
     * pierwszym wystawionym wydarzeniu (`ensureProfile`) albo przez świadome
     * „zostań organizatorem" w panelu. Jedna definicja, egzekwowana przez samo
     * istnienie danych — flaga na koncie mogłaby się z tym rozjechać.
     *
     * Do 2026-08-13 nikt o to nie pytał i panel pokazywał KAŻDEMU zalogowanemu
     * „Profil organizatora", „Płatności" i profil rozliczeniowy — czyli
     * większości kont, które są zwykłymi rowerzystami.
     */
    public static function hasProfile(int $userId): bool
    {
        $stmt = Database::connection()->prepare('SELECT 1 FROM organizer_profiles WHERE user_id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        return $stmt->fetchColumn() !== false;
    }

    // Tworzy profil organizatora przy pierwszym dodawanym evencie, bez
    // osobnego kroku "zostań organizatorem" — spójne z resztą apki (rejestracja
    // też jest bez tarcia). Domyślnie 'peer'/'unverified'; no-op jeśli już istnieje.
    public static function ensureProfile(int $userId): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id FROM organizer_profiles WHERE user_id = :id');
        $stmt->execute(['id' => $userId]);
        if ($stmt->fetch()) {
            return;
        }

        $userStmt = $pdo->prepare('SELECT name, email FROM users WHERE id = :id');
        $userStmt->execute(['id' => $userId]);
        $user = $userStmt->fetch();
        $slug = self::generateUniqueSlug($user['name'] ?: $user['email']);

        $pdo->prepare('
            INSERT INTO organizer_profiles (user_id, slug, organizer_type_item_id, verification_status_item_id)
            VALUES (:id, :slug, :type_id, :status_id)
        ')->execute([
            'id'        => $userId,
            'slug'      => $slug,
            'type_id'   => Dictionary::id('organizer_type', 'peer'),
            'status_id' => Dictionary::id('verification_status', 'unverified'),
        ]);
    }

    // Format::uniqueSlug() (slugify + dosalanie -2, -3... aż do unikalności)
    // skierowane na organizer_profiles.slug.
    public static function generateUniqueSlug(string $name): string
    {
        $pdo = Database::connection();
        return Format::uniqueSlug($name, 'organizator', function (string $slug) use ($pdo): bool {
            $stmt = $pdo->prepare('SELECT id FROM organizer_profiles WHERE slug = :slug');
            $stmt->execute(['slug' => $slug]);
            return (bool) $stmt->fetch();
        });
    }

    public static function updateBio(int $userId, ?string $bio): void
    {
        Database::connection()
            ->prepare('UPDATE organizer_profiles SET bio = :bio WHERE user_id = :id')
            ->execute(['bio' => $bio, 'id' => $userId]);
    }

    // $urls: lista URL-i (string[]), w kolejności wyświetlania w mozaice hero.
    // Puste [] czyści kolumnę (NULL) — wraca wtedy automatyczny fallback do
    // cover_photo_url eventów, patrz recentCoverPhotos().
    public static function updateHeroPhotos(int $userId, array $urls): void
    {
        Database::connection()
            ->prepare('UPDATE organizer_profiles SET hero_photo_urls = :urls WHERE user_id = :id')
            ->execute(['urls' => $urls ? json_encode(array_values($urls)) : null, 'id' => $userId]);
    }

    // Numer wpisu w rejestrze organizatorów turystyki ma sens tylko dla
    // 'professional_operator' — przy przełączeniu na 'peer' czyścimy go, żeby
    // nie zostawało nieaktualne dane po zmianie typu.
    public static function updateType(int $userId, string $organizerTypeCode, ?string $tourismRegisterNumber): void
    {
        if (!in_array($organizerTypeCode, ['peer', 'professional_operator'], true)) {
            throw new \InvalidArgumentException(__('Nieprawidłowy typ organizatora'));
        }
        $isProfessional = $organizerTypeCode === 'professional_operator';

        Database::connection()
            ->prepare('UPDATE organizer_profiles SET organizer_type_item_id = :type_id, tourism_register_number = :reg WHERE user_id = :id')
            ->execute([
                'type_id' => Dictionary::id('organizer_type', $organizerTypeCode),
                'reg'     => $isProfessional ? $tourismRegisterNumber : null,
                'id'      => $userId,
            ]);
    }

    // Miasto/region/rok założenia/języki — czyste dane opisowe, edytowalne
    // razem z bio na /admin/profil-rozliczeniowy.
    public static function updateProfileDetails(int $userId, array $data): void
    {
        Database::connection()
            ->prepare('
                UPDATE organizer_profiles
                SET city = :city, region_item_id = :region_id, founded_year = :founded_year, languages = :languages
                WHERE user_id = :id
            ')
            ->execute([
                'city'        => $data['city'],
                'region_id'   => Dictionary::id('region', $data['regionCode'] ?? null),
                'founded_year'=> $data['foundedYear'],
                'languages'   => $data['languages'],
                'id'          => $userId,
            ]);
    }

    // Cała sekcja "Kontakt i social media" formularza — e-mail kontaktowy razem
    // z linkami, jednym zapisem. $links['contactEmail']: patrz notificationEmail()
    // niżej — to on (nie users.email) dostaje powiadomienia o wydarzeniach, gdy ustawiony.
    public static function updateContactInfo(int $userId, array $links): void
    {
        Database::connection()
            ->prepare('
                UPDATE organizer_profiles
                SET contact_email = :contact_email, website_url = :website, facebook_url = :facebook,
                    instagram_url = :instagram, strava_url = :strava
                WHERE user_id = :id
            ')
            ->execute([
                'contact_email' => $links['contactEmail'],
                'website'       => $links['websiteUrl'],
                'facebook'      => $links['facebookUrl'],
                'instagram'     => $links['instagramUrl'],
                'strava'        => $links['stravaUrl'],
                'id'            => $userId,
            ]);
    }

    // E-mail, na który mają iść powiadomienia o wydarzeniach tego organizatora
    // (nowy zapis, płatność, pytanie, opinia, relacja) — kontaktowy z profilu,
    // jeśli ustawiony, w przeciwnym razie e-mail logowania (dotychczasowe
    // zachowanie). Przyjmuje User (nie samego Organizer) i działa też dla
    // organizatorów bez jeszcze istniejącego organizer_profiles — wtedy po
    // prostu nie ma czego znaleźć i wraca $organizerUser->email jak dawniej.
    public static function notificationEmail(User $organizerUser): string
    {
        $stmt = Database::connection()->prepare('SELECT contact_email FROM organizer_profiles WHERE user_id = :id');
        $stmt->execute(['id' => $organizerUser->id]);
        $contactEmail = $stmt->fetchColumn();
        return ($contactEmail !== false && $contactEmail !== null && $contactEmail !== '') ? $contactEmail : $organizerUser->email;
    }

    public static function updateSafety(int $userId, array $flags): void
    {
        Database::connection()
            ->prepare('
                UPDATE organizer_profiles SET
                    safety_route_known = :route_known, safety_first_aid_kit = :first_aid_kit,
                    safety_sweep_rider = :sweep_rider, safety_support_vehicle = :support_vehicle,
                    safety_first_aid_certified = :first_aid_certified, safety_liability_insurance = :liability_insurance
                WHERE user_id = :id
            ')
            ->execute([
                'route_known'         => !empty($flags['routeKnown']) ? 1 : 0,
                'first_aid_kit'       => !empty($flags['firstAidKit']) ? 1 : 0,
                'sweep_rider'         => !empty($flags['sweepRider']) ? 1 : 0,
                'support_vehicle'     => !empty($flags['supportVehicle']) ? 1 : 0,
                'first_aid_certified' => !empty($flags['firstAidCertified']) ? 1 : 0,
                'liability_insurance' => !empty($flags['liabilityInsurance']) ? 1 : 0,
                'id'                  => $userId,
            ]);
    }

    // Admin-only (patrz guard w web/routes.php POST /organizatorzy/{slug}/weryfikacja)
    // — minimalny przełącznik, bez osobnego workflow wniosków o weryfikację.
    public static function setVerified(int $userId, bool $verified): void
    {
        Database::connection()
            ->prepare('UPDATE organizer_profiles SET verification_status_item_id = :status_id WHERE user_id = :id')
            ->execute([
                'status_id' => Dictionary::id('verification_status', $verified ? 'verified' : 'unverified'),
                'id'        => $userId,
            ]);
    }

    // Dezaktywacja z /admin/organizatorzy — ukrywa profil z publicznej listy/
    // wyszukiwania/sitemapy (patrz filtr is_active w search()/allSlugsForSitemap()),
    // NIE kasuje żadnych danych i nie blokuje organizatorowi dostępu do własnego
    // self-service (billing-profile.php ładuje przez findByUserId, bez filtra).
    public static function setActive(int $userId, bool $active): void
    {
        Database::connection()
            ->prepare('UPDATE organizer_profiles SET is_active = :active WHERE user_id = :id')
            ->execute(['active' => $active ? 1 : 0, 'id' => $userId]);
    }

    // Lekkie zapytanie pod /sitemap-organizers.xml — organizer_profiles nie ma
    // kolumny updated_at (patrz schema), więc created_at jako lastmod to
    // świadomy kompromis: sitemapowe lastmod jest tylko wskazówką dla Google,
    // nie musi być idealnie dokładne.
    public static function allSlugsForSitemap(): array
    {
        $stmt = Database::connection()->query('SELECT slug, created_at FROM organizer_profiles WHERE is_active = 1 ORDER BY created_at DESC');
        return $stmt->fetchAll();
    }

    // Dopasowanie po domenie strony WWW — pod "Importer eventów" (rozszerzenie
    // Chrome): rozszerzenie skanuje stronę organizatora spoza ridemore.bike i
    // chce zaproponować ISTNIEJĄCY profil zamiast każdorazowo zakładać nowego
    // organizatora. Porównanie po hoście (bez "www."), nie LIKE na całym
    // URL-u — inaczej "rowery-bieszczady.pl" złapałoby przypadkiem
    // "rowery-bieszczady.pl.jakis-inny-serwis.com". website_url bywa bez
    // schematu (admin wpisuje "rowery-bieszczady.pl" wprost), stąd
    // normalizeHost() dokleja "https://" zanim odda parse_url() do roboty.
    // Zbiór z website_url jest z założenia mały (nie każdy organizator go ma)
    // — jedno zapytanie + porównanie w PHP, bez LIKE po całej tabeli.
    public static function findByWebsiteDomain(string $domain): array
    {
        $domain = self::normalizeHost($domain);
        if ($domain === '') {
            return [];
        }

        $stmt = Database::connection()->query("
            SELECT u.id AS user_id, u.name, op.slug, op.website_url
            FROM organizer_profiles op
            JOIN users u ON u.id = op.user_id
            WHERE op.is_active = 1 AND op.website_url IS NOT NULL AND op.website_url <> ''
        ");

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $host = self::normalizeHost($row['website_url']);
            if ($host === '') {
                continue;
            }
            if ($host === $domain || str_ends_with($host, '.' . $domain) || str_ends_with($domain, '.' . $host)) {
                $out[] = [
                    'id'      => (int) $row['user_id'],
                    'name'    => $row['name'],
                    'slug'    => $row['slug'],
                    'website' => $row['website_url'],
                ];
            }
        }
        return $out;
    }

    // "example.com", "https://www.example.com/kontakt" -> "example.com".
    // Puste/niesparsowalne wejście -> "" (wołający po prostu nic nie dopasuje).
    private static function normalizeHost(string $urlOrHost): string
    {
        $s = trim($urlOrHost);
        if ($s === '') {
            return '';
        }
        if (!preg_match('#^https?://#i', $s)) {
            $s = 'https://' . $s;
        }
        $host = parse_url($s, PHP_URL_HOST);
        if (!$host) {
            return '';
        }
        return preg_replace('/^www\./', '', strtolower($host));
    }

    // Statystyka sitewide pod sekcję "Dowód" na stronie głównej (landing).
    // Świadomie BEZ średniej attendance_confirmed_rate — to martwa, zaseedowana
    // kolumna (patrz komentarz przy organizer-profile.php, gdzie z tego samego
    // powodu usunięto "Potwierdzona obecność %" z profilu organizatora).
    public static function totalCount(): int
    {
        return (int) Database::connection()->query('SELECT COUNT(*) FROM organizer_profiles WHERE is_active = 1')->fetchColumn();
    }

    // Statystyki na żywo — organizer_profiles.rating_avg/events_organized_count
    // są zaseedowane raz i nigdy nieprzeliczane, więc nie ufamy im tutaj.
    public static function stats(int $userId): array
    {
        $pdo = Database::connection();

        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM events e
            JOIN dictionary_items st ON st.id = e.status_item_id AND st.code = 'completed'
            WHERE e.organizer_id = :id
        ");
        $stmt->execute(['id' => $userId]);
        $completedCount = (int) $stmt->fetchColumn();

        $cancelledStmt = $pdo->prepare("
            SELECT COUNT(*) FROM events e
            JOIN dictionary_items st ON st.id = e.status_item_id AND st.code = 'cancelled'
            WHERE e.organizer_id = :id AND YEAR(e.start_date) = YEAR(CURDATE())
        ");
        $cancelledStmt->execute(['id' => $userId]);
        $cancelledThisYear = (int) $cancelledStmt->fetchColumn();

        $memberSinceStmt = $pdo->prepare('SELECT YEAR(created_at) FROM organizer_profiles WHERE user_id = :id');
        $memberSinceStmt->execute(['id' => $userId]);
        $memberSinceYear = (int) $memberSinceStmt->fetchColumn();

        return [
            'completedCount'    => $completedCount,
            'cancelledThisYear' => $cancelledThisYear,
            'memberSinceYear'   => $memberSinceYear,
        ];
    }

    // Miernik kompletności profilu — jedno źródło prawdy dla dwóch miejsc:
    // banner "Widok właściciela" na publicznym profilu (OrganizerController::show())
    // i karta "Kompletność profilu" w samoobsługowym /admin/profil-rozliczeniowy
    // (billing-profile.php). Każda pozycja to coś realnie sprawdzalnego — żadnej
    // fabrykowanej metryki. $organizer musi być już wczytany (unikamy drugiego
    // zapytania — wywołujący i tak zwykle ma go pod ręką).
    public static function completeness(self $organizer): array
    {
        $hasAnySocial = $organizer->websiteUrl || $organizer->facebookUrl
            || $organizer->instagramUrl || $organizer->stravaUrl;
        $hasAnySafety = $organizer->safetyRouteKnown || $organizer->safetyFirstAidKit
            || $organizer->safetySweepRider || $organizer->safetySupportVehicle
            || $organizer->safetyFirstAidCertified || $organizer->safetyLiabilityInsurance;

        $items = [
            ['key' => 'photos',   'label' => __('Awatar i zdjęcia w tle'),    'done' => (bool) ($organizer->avatarUrl && $organizer->heroPhotoUrls)],
            ['key' => 'location', 'label' => __('Miasto i region'),           'done' => (bool) ($organizer->city && $organizer->regionCode)],
            ['key' => 'phone',    'label' => __('Telefon'),                  'done' => (bool) $organizer->phone],
            ['key' => 'bio',      'label' => __('Opis „O nas"'),              'done' => !empty($organizer->bio)],
            ['key' => 'founded',  'label' => __('Rok założenia'),            'done' => $organizer->foundedYear !== null],
            ['key' => 'web',      'label' => __('Strona albo Facebook'),     'done' => (bool) ($organizer->websiteUrl || $organizer->facebookUrl)],
            ['key' => 'safety',   'label' => __('Deklaracje bezpieczeństwa'), 'done' => $hasAnySafety],
        ];
        $done = count(array_filter($items, fn($i) => $i['done']));
        $total = count($items);

        return [
            'done'    => $done,
            'total'   => $total,
            'percent' => $total > 0 ? (int) round($done / $total * 100) : 0,
            'items'   => $items,
        ];
    }

    // Chipy "specjalizacji" — wyprowadzone z tego, co organizator faktycznie
    // organizuje (typy rowerów i regiony po wszystkich jego eventach), nie
    // deklarowane ręcznie.
    public static function specializationChips(int $userId): array
    {
        $pdo = Database::connection();

        $bikeStmt = $pdo->prepare('
            SELECT DISTINCT di.name FROM event_bike_types ebt
            JOIN events e ON e.id = ebt.event_id
            JOIN dictionary_items di ON di.id = ebt.bike_type_item_id
            WHERE e.organizer_id = :id
            ORDER BY di.name
        ');
        $bikeStmt->execute(['id' => $userId]);
        $bikeTypes = array_column($bikeStmt->fetchAll(), 'name');

        $regionStmt = $pdo->prepare('
            SELECT DISTINCT di.name FROM events e
            JOIN event_regions er ON er.event_id = e.id
            JOIN dictionary_items di ON di.id = er.region_item_id
            WHERE e.organizer_id = :id
            ORDER BY di.name
        ');
        $regionStmt->execute(['id' => $userId]);
        $regions = array_column($regionStmt->fetchAll(), 'name');

        return array_merge($bikeTypes, $regions);
    }

    // "Jak jeździmy — w liczbach" — liczone na żywo z ostatnich $sample
    // zakończonych eventów organizatora (ten sam duch co ratingAvg/
    // attendanceConfirmedRate: nigdy nie przechowywane ręcznie, zawsze świeże).
    // Wszystkie pola null gdy organizator nie ma jeszcze zakończonych eventów.
    public static function ridingProfile(int $userId, int $sample = 12): array
    {
        $pdo = Database::connection();

        $idsStmt = $pdo->prepare("
            SELECT e.id FROM events e
            JOIN dictionary_items st ON st.id = e.status_item_id AND st.code = 'completed'
            WHERE e.organizer_id = :id
            ORDER BY e.start_date DESC
            LIMIT $sample
        ");
        $idsStmt->execute(['id' => $userId]);
        $eventIds = array_map('intval', array_column($idsStmt->fetchAll(), 'id'));

        if (empty($eventIds)) {
            return ['difficultyLabel' => null, 'paceLabel' => null, 'avgGroupSize' => null, 'surfaceLabel' => null, 'distanceRangeLabel' => null];
        }

        $placeholders = implode(',', array_fill(0, count($eventIds), '?'));

        // Trudność i tempo w jednym zapytaniu (ten sam $eventIds) zamiast dwóch
        // osobnych — LEFT JOIN (nie INNER) na obu, bo difficulty_item_id i
        // pace_group_item_id są nullable niezależnie od siebie: event bez
        // ustawionej trudności ma wciąż liczyć się do listy temp, i odwrotnie.
        // Dedup + sort_order odtwarzane w PHP (asort() po wartości sort_order,
        // zachowując klucze-nazwy), bo SQL DISTINCT na dwóch niezależnych
        // wymiarach naraz dawałby distinct PARY, nie distinct osobno per kolumna.
        $attrStmt = $pdo->prepare("
            SELECT dd.name AS difficulty_name, dd.sort_order AS difficulty_sort,
                   dp.name AS pace_name, dp.sort_order AS pace_sort
            FROM events e
            LEFT JOIN dictionary_items dd ON dd.id = e.difficulty_item_id
            LEFT JOIN dictionary_items dp ON dp.id = e.pace_group_item_id
            WHERE e.id IN ($placeholders)
        ");
        $attrStmt->execute($eventIds);
        $difficultyMap = [];
        $paceMap = [];
        foreach ($attrStmt->fetchAll() as $row) {
            if ($row['difficulty_name'] !== null) {
                $difficultyMap[$row['difficulty_name']] = (int) $row['difficulty_sort'];
            }
            if ($row['pace_name'] !== null) {
                $paceMap[$row['pace_name']] = (int) $row['pace_sort'];
            }
        }
        asort($difficultyMap);
        asort($paceMap);
        $difficulties = array_keys($difficultyMap);
        $paces = array_keys($paceMap);

        $groupStmt = $pdo->prepare("
            SELECT AVG(cnt) FROM (
                SELECT (
                    SELECT COUNT(*) FROM event_rsvps r
                    JOIN dictionary_items rdi ON rdi.id = r.status_item_id
                    WHERE r.event_id = e.id AND rdi.code = 'potwierdzony'
                ) AS cnt
                FROM events e WHERE e.id IN ($placeholders)
            ) t
        ");
        $groupStmt->execute($eventIds);
        $avgGroupSize = $groupStmt->fetchColumn();

        $surfaceStmt = $pdo->prepare("
            SELECT di.name, COUNT(*) AS cnt FROM event_stages es
            JOIN dictionary_items di ON di.id = es.surface_item_id
            WHERE es.event_id IN ($placeholders)
            GROUP BY di.id, di.name
            ORDER BY cnt DESC
            LIMIT 1
        ");
        $surfaceStmt->execute($eventIds);
        $topSurface = $surfaceStmt->fetch();

        $surfaceLabel = null;
        if ($topSurface) {
            $totalStmt = $pdo->prepare("
                SELECT COUNT(*) FROM event_stages
                WHERE event_id IN ($placeholders) AND surface_item_id IS NOT NULL
            ");
            $totalStmt->execute($eventIds);
            $totalStages = (int) $totalStmt->fetchColumn();
            if ($totalStages > 0) {
                $pct = (int) round(((int) $topSurface['cnt']) / $totalStages * 100);
                $surfaceLabel = $pct . '% ' . mb_strtolower(__($topSurface['name']));
            }
        }

        // Zakres dystansu DNIA (nie eventu) — jeden wielodniowy wyjazd ma kilka
        // etapów o różnych długościach, MIN/MAX liczone po wierszach
        // event_stages, nie po zsumowanym dystansie eventu. Pod "Jak wygląda
        // wyjazd" (makieta organizator-v3.html, wiersz "Dzień").
        $rangeStmt = $pdo->prepare("
            SELECT MIN(distance_km) AS min_km, MAX(distance_km) AS max_km
            FROM event_stages
            WHERE event_id IN ($placeholders) AND distance_km > 0
        ");
        $rangeStmt->execute($eventIds);
        $range = $rangeStmt->fetch();
        $distanceRangeLabel = null;
        if ($range && $range['min_km'] !== null) {
            $min = (int) round((float) $range['min_km']);
            $max = (int) round((float) $range['max_km']);
            $distanceRangeLabel = $min === $max ? $min . ' km' : $min . '–' . $max . ' km';
        }

        return [
            'difficultyLabel' => $difficulties ? implode(', ', array_map('__', $difficulties)) : null,
            'paceLabel'       => $paces ? implode(', ', array_map('__', $paces)) : null,
            'avgGroupSize'    => $avgGroupSize !== null ? (int) round((float) $avgGroupSize) : null,
            'surfaceLabel'    => $surfaceLabel,
            'distanceRangeLabel' => $distanceRangeLabel,
        ];
    }

    private static function heroPhotoUrlsFor(int $userId): array
    {
        $stmt = Database::connection()->prepare('SELECT hero_photo_urls FROM organizer_profiles WHERE user_id = :id');
        $stmt->execute(['id' => $userId]);
        $raw = $stmt->fetchColumn();
        return $raw ? (json_decode($raw, true) ?: []) : [];
    }

    // Do mozaiki zdjęć w hero profilu — własne zdjęcia organizatora (patrz
    // updateHeroPhotos()) mają pierwszeństwo; gdy nie ustawił żadnych, fallback
    // na realne cover_photo_url ostatnich wydarzeń, jak dotychczas.
    public static function recentCoverPhotos(int $userId, int $limit = 5): array
    {
        return self::coverPhotosWithSource($userId, $limit)['urls'];
    }

    /**
     * To samo co `recentCoverPhotos()`, plus SKĄD te zdjęcia są — pod opis
     * kafli na karcie organizatora (2026-09-16). Karta podpisywała wszystko
     * jako „zdjęcie z wyjazdu", a przy ustawionych `hero_photo_urls` to są
     * zdjęcia z profilu, niekoniecznie z jakiegokolwiek wyjazdu.
     *
     * @return array{urls:list<string>,source:string} source: 'profile' | 'events'
     */
    public static function coverPhotosWithSource(int $userId, int $limit = 5): array
    {
        $hero = self::heroPhotoUrlsFor($userId);
        if ($hero) {
            return ['urls' => array_slice($hero, 0, $limit), 'source' => 'profile'];
        }

        $stmt = Database::connection()->prepare("
            SELECT cover_photo_url FROM events
            WHERE organizer_id = :id AND cover_photo_url IS NOT NULL
            ORDER BY start_date DESC
            LIMIT $limit
        ");
        $stmt->execute(['id' => $userId]);
        return ['urls' => array_column($stmt->fetchAll(), 'cover_photo_url'), 'source' => 'events'];
    }

    // Łączna liczba zdjęć pod kafel "+N" w galerii (odjąć liczbę faktycznie
    // pokazanych kafli z recentCoverPhotos()) — analogicznie z pierwszeństwem
    // własnych zdjęć organizatora nad zliczeniem eventowych okładek.
    public static function totalCoverPhotoCount(int $userId): int
    {
        $hero = self::heroPhotoUrlsFor($userId);
        if ($hero) {
            return count($hero);
        }

        $stmt = Database::connection()->prepare('
            SELECT COUNT(*) FROM events
            WHERE organizer_id = :id AND cover_photo_url IS NOT NULL
        ');
        $stmt->execute(['id' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    // Pojedyncze duże zdjęcie hero pod nagłówkiem profilu (organizator-v3.html)
    // — w odróżnieniu od recentCoverPhotos() (goła lista URL-i do mozaiki),
    // to zwraca TAKŻE tytuł/datę źródłowego wydarzenia, żeby podpis pod
    // zdjęciem ("Wyjazd „X", wrzesień 2025") był prawdziwy, nie zmyślony jak
    // w samej makiecie. Własne zdjęcia organizatora (hero_photo_urls) nie są
    // przypisane do żadnego konkretnego wyjazdu, więc dostają podpis null.
    public static function heroCover(int $userId): ?array
    {
        $hero = self::heroPhotoUrlsFor($userId);
        if ($hero) {
            return ['url' => $hero[0], 'eventTitle' => null, 'eventDate' => null];
        }

        $stmt = Database::connection()->prepare('
            SELECT cover_photo_url, title, start_date FROM events
            WHERE organizer_id = :id AND cover_photo_url IS NOT NULL
            ORDER BY start_date DESC
            LIMIT 1
        ');
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return ['url' => $row['cover_photo_url'], 'eventTitle' => $row['title'], 'eventDate' => $row['start_date']];
    }

    // Lista/wyszukiwarka wielu organizatorów naraz — w odróżnieniu od reszty tej
    // klasy (zawsze jeden już znany userId/slug), pod /organizatorzy. Ocena i
    // liczba wyjazdów liczone na żywo tym samym wzorcem co ratingAvg/
    // eventsOrganizedCount w findByColumn() — nigdy z zaszytych, nieprzeliczanych
    // kolumn organizer_profiles.
    //
    // $filters: q (string), regions (string[] kodów 'region'), type
    //   (kod 'organizer_type': 'peer'|'professional_operator'|null — NIEZALEŻNE
    //   od weryfikacji, jeden organizator może być społecznościowy i zweryfikowany
    //   naraz), verifiedOnly (bool), bikeTypes (string[] kodów 'bike_type'),
    //   minRating (float|null).
    // $sort: 'rating' (domyślne) | 'events'.
    // Zwraca ['items' => [...], 'total' => int]. Nieprzejęte profile (bez hasła)
    // CELOWO nie są odfiltrowywane — to jedyny sposób, żeby ktoś na nie trafił
    // i mógł je przejąć (patrz POST /organizatorzy/{slug}/przejmij).
    public static function search(array $filters, string $sort, int $limit, int $offset): array
    {
        $pdo = Database::connection();

        // Dezaktywowane z /admin/organizatorzy (patrz setActive()) nie mają się
        // pojawiać w publicznym wyszukiwaniu — w odróżnieniu od nieprzejętych
        // profili (patrz komentarz niżej), to nie jest "do przejęcia", tylko
        // świadomie schowane przez admina.
        $where = ['op.is_active = 1'];
        $params = [];

        $q = trim($filters['q'] ?? '');
        if ($q !== '') {
            $where[] = '(u.name LIKE :q OR op.city LIKE :q2)';
            $params['q'] = '%' . $q . '%';
            $params['q2'] = '%' . $q . '%';
        }

        $regions = array_values(array_filter((array) ($filters['regions'] ?? []), fn($c) => $c !== ''));
        if ($regions) {
            $placeholders = [];
            foreach ($regions as $i => $code) {
                $key = 'region' . $i;
                $placeholders[] = ":$key";
                $params[$key] = $code;
            }
            $where[] = 'dreg.code IN (' . implode(',', $placeholders) . ')';
        }

        $type = $filters['type'] ?? null;
        if (in_array($type, ['peer', 'professional_operator'], true)) {
            $where[] = 'dit.code = :type';
            $params['type'] = $type;
        }
        if (!empty($filters['verifiedOnly'])) {
            $where[] = "dvs.code = 'verified'";
        }

        $bikeTypes = array_values(array_filter((array) ($filters['bikeTypes'] ?? []), fn($c) => $c !== ''));
        if ($bikeTypes) {
            $placeholders = [];
            foreach ($bikeTypes as $i => $code) {
                $key = 'bike' . $i;
                $placeholders[] = ":$key";
                $params[$key] = $code;
            }
            $where[] = 'EXISTS (
                SELECT 1 FROM events be
                JOIN event_bike_types ebt ON ebt.event_id = be.id
                JOIN dictionary_items bdi ON bdi.id = ebt.bike_type_item_id
                WHERE be.organizer_id = u.id AND bdi.code IN (' . implode(',', $placeholders) . ')
            )';
        }

        $minRating = $filters['minRating'] ?? null;
        if ($minRating !== null) {
            $where[] = '(SELECT AVG(rating) FROM event_reviews WHERE target_user_id = u.id) >= :min_rating';
            $params['min_rating'] = $minRating;
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $baseFrom = "
            FROM organizer_profiles op
            JOIN users u ON u.id = op.user_id
            JOIN dictionary_items dit ON dit.id = op.organizer_type_item_id
            JOIN dictionary_items dvs ON dvs.id = op.verification_status_item_id
            LEFT JOIN dictionary_items dreg ON dreg.id = op.region_item_id
            $whereSql
        ";

        $countStmt = $pdo->prepare("SELECT COUNT(*) $baseFrom");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        // avg_rating/completed_count to aliasy z SELECT — MySQL pozwala się do
        // nich odwołać w ORDER BY (w odróżnieniu od WHERE).
        $orderSql = $sort === 'events'
            ? 'ORDER BY completed_count DESC, u.name ASC'
            : 'ORDER BY avg_rating IS NULL, avg_rating DESC, u.name ASC';

        $stmt = $pdo->prepare("
            SELECT u.id AS user_id, u.name, u.email, u.avatar_url, op.slug, op.city,
                   dit.code AS organizer_type_code, dvs.code AS verification_status_code,
                   dreg.name AS region_name,
                   (SELECT AVG(rating) FROM event_reviews WHERE target_user_id = u.id) AS avg_rating,
                   (SELECT COUNT(*) FROM event_reviews WHERE target_user_id = u.id) AS review_count,
                   (SELECT COUNT(*) FROM events ce
                        JOIN dictionary_items cst ON cst.id = ce.status_item_id AND cst.code = 'completed'
                    WHERE ce.organizer_id = u.id) AS completed_count
            $baseFrom
            $orderSql
            LIMIT $limit OFFSET $offset
        ");
        $stmt->execute($params);

        $items = array_map(fn($row) => [
            'userId'         => (int) $row['user_id'],
            'name'           => $row['name'] ?: $row['email'],
            'avatarUrl'      => $row['avatar_url'],
            'slug'           => $row['slug'],
            'city'           => $row['city'],
            'organizerType'  => $row['organizer_type_code'],
            'isVerified'     => $row['verification_status_code'] === 'verified',
            'regionName'     => $row['region_name'],
            'ratingAvg'      => $row['avg_rating'] !== null ? round((float) $row['avg_rating'], 1) : null,
            'reviewCount'    => (int) $row['review_count'],
            'completedCount' => (int) $row['completed_count'],
        ], $stmt->fetchAll());

        return ['items' => $items, 'total' => $total];
    }

    // Listowanie pod /admin/organizatorzy — w odróżnieniu od search() (publiczne,
    // filtruje po is_active) pokazuje WSZYSTKICH, aktywnych i nieaktywnych, żeby
    // admin miał skąd przywrócić dezaktywowany profil. $filters: q (string,
    // dopasowuje nazwę/email), status ('active'|'inactive'|null = wszyscy).
    public static function allForAdmin(array $filters = [], int $limit = 200, int $offset = 0): array
    {
        $pdo = Database::connection();
        $where = [];
        $params = [];

        $q = trim($filters['q'] ?? '');
        if ($q !== '') {
            $where[] = '(u.name LIKE :q OR u.email LIKE :q)';
            $params['q'] = '%' . $q . '%';
        }
        if (($filters['status'] ?? null) === 'active') {
            $where[] = 'op.is_active = 1';
        } elseif (($filters['status'] ?? null) === 'inactive') {
            $where[] = 'op.is_active = 0';
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $baseFrom = "
            FROM organizer_profiles op
            JOIN users u ON u.id = op.user_id
            JOIN dictionary_items dit ON dit.id = op.organizer_type_item_id
            JOIN dictionary_items dvs ON dvs.id = op.verification_status_item_id
            $whereSql
        ";

        $countStmt = $pdo->prepare("SELECT COUNT(*) $baseFrom");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT u.id AS user_id, u.name, u.email, u.password_hash, op.slug, op.is_active, op.created_at,
                   dit.code AS organizer_type_code, dvs.code AS verification_status_code,
                   (SELECT COUNT(*) FROM events ev WHERE ev.organizer_id = u.id) AS events_count
            $baseFrom
            ORDER BY op.created_at DESC
            LIMIT $limit OFFSET $offset
        ");
        $stmt->execute($params);

        $items = array_map(fn($row) => [
            'userId'        => (int) $row['user_id'],
            'name'          => $row['name'] ?: $row['email'],
            'email'         => $row['email'],
            'slug'          => $row['slug'],
            'organizerType' => $row['organizer_type_code'],
            'isVerified'    => $row['verification_status_code'] === 'verified',
            'isActive'      => (bool) $row['is_active'],
            'isUnclaimed'   => $row['password_hash'] === null,
            'eventsCount'   => (int) $row['events_count'],
            'createdAt'     => $row['created_at'],
        ], $stmt->fetchAll());

        return ['items' => $items, 'total' => $total];
    }
}
