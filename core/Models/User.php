<?php
// core/Models/User.php
namespace Models;

use Core\Database;

class User
{
    public int $id;
    public ?string $name;
    public string $email;
    public ?string $passwordHash;
    public ?string $emailVerifiedAt;
    public bool $isAdmin;
    /** Znacznik blokady moderacyjnej (migr. 058); null = konto czynne. */
    public ?string $blockedAt = null;
    public ?string $blockedReason = null;
    public bool $rosterVisible = true;
    public ?string $publicSlug = null;
    public ?string $avatarUrl;
    /** Język maili, pushy i wejścia na stronę główną (migr. 089); null = polski. */
    public ?string $lang = null;

    private const SELECT_COLUMNS = 'id, name, email, password_hash, email_verified_at, is_admin, blocked_at, blocked_reason, roster_visible, public_slug, avatar_url, lang';

    public static function find(int $id): ?self
    {
        $stmt = Database::connection()->prepare('SELECT ' . self::SELECT_COLUMNS . ' FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ? self::fromRow($row) : null;
    }

    public static function findByEmail(string $email): ?self
    {
        $stmt = Database::connection()->prepare('SELECT ' . self::SELECT_COLUMNS . ' FROM users WHERE email = :email');
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch();
        return $row ? self::fromRow($row) : null;
    }

    // Konto "puste" — sam e-mail, bez hasła. Aktywuje się po ustawieniu hasła.
    public static function createPending(string $email): self
    {
        // Język konta = język strony, na której się rejestruje (migr. 089) —
        // mail aktywacyjny i każdy następny idą w tym języku.
        $stmt = Database::connection()->prepare('INSERT INTO users (email, lang) VALUES (:email, :lang)');
        $stmt->execute(['email' => $email, 'lang' => \Core\Lang::isDefault() ? null : \Core\Lang::current()]);
        return self::find((int) Database::connection()->lastInsertId());
    }

    // Konto z logowania społecznościowego (Google/Strava) — BEZ hasła (user może
    // dodać przez "odzyskaj hasło"). $emailVerified = czy provider potwierdził
    // WŁASNOŚĆ e-maila: Google TAK (ustawiamy email_verified_at), Strava NIE
    // (e-mail wpisuje user ręcznie — nie wolno go uznać za zweryfikowany ani
    // auto-podpinać do cudzego konta, patrz SocialAuthController::stravaComplete).
    public static function createFromOAuth(string $email, ?string $name, ?string $avatarUrl, bool $emailVerified): self
    {
        $pdo = Database::connection();
        $pdo->prepare('
            INSERT INTO users (email, name, avatar_url, email_verified_at, lang)
            VALUES (:email, :name, :avatar, ' . ($emailVerified ? 'NOW()' : 'NULL') . ', :lang)
        ')->execute([
            'lang'   => \Core\Lang::isDefault() ? null : \Core\Lang::current(),
            'email'  => $email,
            'name'   => ($name !== null && $name !== '') ? $name : null,
            'avatar' => ($avatarUrl !== null && $avatarUrl !== '') ? $avatarUrl : null,
        ]);
        $userId = (int) $pdo->lastInsertId();
        // Adres publicznego profilu nadajemy OD RAZU — provider dał już nazwę,
        // więc slug wyjdzie ładny. Bez tego nowe konta nie miały żadnego
        // public_slug (backfill objął tylko konta sprzed migr. 038), a wtedy
        // nazwisko takiej osoby nigdzie w serwisie nie było klikalne.
        self::ensurePublicSlug($userId);
        return self::find($userId);
    }

    public function setPasswordAndActivate(string $plainPassword): void
    {
        $hash = password_hash($plainPassword, PASSWORD_DEFAULT);
        $stmt = Database::connection()->prepare('
            UPDATE users
            SET password_hash = :hash, email_verified_at = NOW()
            WHERE id = :id
        ');
        $stmt->execute(['hash' => $hash, 'id' => $this->id]);
        $this->passwordHash    = $hash;
        $this->emailVerifiedAt = date('Y-m-d H:i:s');
        // Moment, w którym konto z rejestracji mailowej staje się prawdziwe —
        // i jedyny pewny punkt, żeby nadać adres publicznego profilu
        // (createPending() ma sam e-mail, nazwa dochodzi dopiero tutaj).
        $this->publicSlug = self::ensurePublicSlug($this->id);
    }

    public function updateName(string $name): void
    {
        $stmt = Database::connection()->prepare('UPDATE users SET name = :name WHERE id = :id');
        $stmt->execute(['name' => $name, 'id' => $this->id]);
        $this->name = $name;
        // Siatka bezpieczeństwa dla kont, które z jakiegoś powodu nie dostały
        // sluga wcześniej — nadajemy przy pierwszej okazji, gdy jest z czego.
        if ($this->publicSlug === null) {
            $this->publicSlug = self::ensurePublicSlug($this->id);
        }
    }

    // Język konta (migr. 089) — zapisywany przełącznikiem języka i przy
    // rejestracji. Nieobsługiwany kod nie trafia do bazy: maile wysłane
    // w nieistniejącej wersji wyszłyby i tak po polsku, tylko po cichu.
    public static function setLang(int $userId, string $lang): void
    {
        if (!\Core\Lang::isSupported($lang)) {
            return;
        }
        Database::connection()
            ->prepare('UPDATE users SET lang = :lang WHERE id = :id')
            ->execute(['lang' => $lang, 'id' => $userId]);
    }

    // Język, w którym piszemy DO KOGOŚ (mail, push) — niezależnie od tego,
    // w jakim języku jest żądanie, które tę wiadomość wywołało.
    public static function langOf(int $userId): string
    {
        $stmt = Database::connection()->prepare('SELECT lang FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $lang = $stmt->fetchColumn();
        return \Core\Lang::isSupported(is_string($lang) ? $lang : null) ? $lang : \Core\Lang::DOMYSLNY;
    }

    public function updatePhone(?string $phone): void
    {
        $stmt = Database::connection()->prepare('UPDATE users SET phone = :phone WHERE id = :id');
        $stmt->execute(['phone' => $phone, 'id' => $this->id]);
    }

    public function updateAvatar(?string $avatarUrl): void
    {
        $stmt = Database::connection()->prepare('UPDATE users SET avatar_url = :url WHERE id = :id');
        $stmt->execute(['url' => $avatarUrl, 'id' => $this->id]);
        $this->avatarUrl = $avatarUrl;
    }

    // Zwraca false gdy $currentPassword się nie zgadza — trasa pokazuje wtedy błąd
    // i nie rusza hasła. Nie dotyka email_verified_at (w odróżnieniu od
    // setPasswordAndActivate(), które aktywuje świeże konto).
    public function changePassword(string $currentPassword, string $newPassword): bool
    {
        if (!$this->verifyPassword($currentPassword)) {
            return false;
        }
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = Database::connection()->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
        $stmt->execute(['hash' => $hash, 'id' => $this->id]);
        $this->passwordHash = $hash;
        return true;
    }

    // Reset po linku e-mail (patrz POST /odzyskaj-haslo/nowe) — inaczej niż
    // changePassword() nie wymaga znajomości starego hasła, bo bezpieczeństwo
    // tej ścieżki opiera się na posiadaniu jednorazowego tokenu z maila, nie
    // na potwierdzeniu dotychczasowego hasła.
    public function resetPassword(string $newPassword): void
    {
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = Database::connection()->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
        $stmt->execute(['hash' => $hash, 'id' => $this->id]);
        $this->passwordHash = $hash;
    }

    /**
     * Konto wyłączone przez moderację (migr. 058).
     *
     * ŚWIADOMIE OSOBNO OD isActive(). Tamto odpowiada na pytanie „czy konto jest
     * dokończone" (jest hasło, jest potwierdzony e-mail) i tak samo brzmi dla
     * kogoś, kto nie kliknął w link aktywacyjny. Zlanie obu w jedno znaczyłoby,
     * że zablokowany dostaje komunikat „dokończ rejestrację" — czyli poradę,
     * której wykonanie niczego nie zmieni.
     */
    public function isBlocked(): bool
    {
        return $this->blockedAt !== null;
    }

    /**
     * KASOWANIE KONTA W APCE (Etap 9 przebudowy apki, migr. 079, 2026-08-29)
     * — samoobsługowe usunięcie NATYCHMIAST blokuje konto (ten sam mechanizm
     * co moderacja, migr. 058 — `Auth::user()` już wylogowuje zablokowane
     * konta, za darmo) i oznacza je do RĘCZNEGO dokasowania przez admina.
     * Twardego, automatycznego kasowania kont z treścią świadomie nie ma —
     * skasowałoby też cudze wyjazdy, w których to konto brało udział (RSVP,
     * komentarze, rejestr punktów) — ta sama zasada, co już stoi w
     * `UserAdmin::deleteIfEmpty()`/`users-admin.php`.
     *
     * `$currentPassword` jest WYMAGANE tylko wtedy, gdy konto W OGÓLE ma
     * hasło — konta założone wyłącznie logowaniem społecznościowym (Google/
     * Strava) nie mają czego weryfikować; `AccountController` sprawdza to
     * samo PRZED wywołaniem tej metody i dla takich kont wymaga zamiast tego
     * jawnej zgody w formularzu (checkbox).
     *
     * @return bool false = złe hasło ALBO konto już zablokowane (moderacja
     *         lub wcześniejsza prośba) — nic do zrobienia drugi raz.
     */
    public function requestDeletion(?string $currentPassword): bool
    {
        if ($this->passwordHash !== null
            && ($currentPassword === null || !$this->verifyPassword($currentPassword))) {
            return false;
        }
        if ($this->blockedAt !== null) {
            return false;
        }

        $stmt = Database::connection()->prepare('
            UPDATE users
               SET blocked_at = NOW(),
                   blocked_reason = :reason,
                   blocked_by = :id,
                   deletion_requested_at = NOW()
             WHERE id = :id2 AND blocked_at IS NULL
        ');
        $stmt->execute([
            'reason' => 'Prośba użytkownika o usunięcie konta (samoobsługowe)',
            'id'     => $this->id,
            'id2'    => $this->id,
        ]);

        if ($stmt->rowCount() === 0) {
            return false;
        }
        $this->blockedAt = date('Y-m-d H:i:s');
        return true;
    }

    public function isActive(): bool
    {
        return $this->passwordHash !== null && $this->emailVerifiedAt !== null;
    }

    public function verifyPassword(string $plainPassword): bool
    {
        return $this->passwordHash !== null && password_verify($plainPassword, $this->passwordHash);
    }

    public function displayName(): string
    {
        return $this->name ?: $this->email;
    }

    private static function fromRow(array $row): self
    {
        $u = new self();
        $u->id              = (int) $row['id'];
        $u->name            = $row['name'];
        $u->email           = $row['email'];
        $u->passwordHash    = $row['password_hash'];
        $u->emailVerifiedAt = $row['email_verified_at'];
        $u->isAdmin         = (bool) $row['is_admin'];
        // Zapytania spoza tego modelu (np. skład wyjazdu) nie zawsze biorą te
        // kolumny — brak klucza znaczy „nie wiemy", a nie „zablokowany".
        $u->blockedAt       = $row['blocked_at'] ?? null;
        $u->blockedReason   = $row['blocked_reason'] ?? null;
        // Widoczność na listach uczestników i w peletonie (migr. 037). Domyślnie
        // true — także dla wierszy pobranych zapytaniem bez tej kolumny.
        $u->rosterVisible   = !isset($row['roster_visible']) || (bool) $row['roster_visible'];
        $u->publicSlug      = $row['public_slug'] ?? null;
        $u->avatarUrl       = $row['avatar_url'] ?? null;
        $u->lang            = $row['lang'] ?? null;
        return $u;
    }

    // Profil publiczny rowerzysty (migr. 038). Zwraca użytkownika po slugu —
    // sama widoczność profilu rozstrzygana jest wyżej (RiderController::show),
    // bo zależy od danych spoza tabeli users (czy ma potwierdzony przejazd).
    public static function findBySlug(string $slug): ?self
    {
        $stmt = Database::connection()
            ->prepare('SELECT ' . self::SELECT_COLUMNS . ' FROM users WHERE public_slug = :slug');
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch();
        return $row ? self::fromRow($row) : null;
    }

    // Slug nadawany raz i już się nie zmienia — adres profilu, który ktoś komuś
    // wysłał, ma dalej działać po zmianie nazwy konta. Wzorowane na
    // Organizer::generateUniqueSlug (ten sam Format::uniqueSlug).
    public static function generatePublicSlug(string $name): string
    {
        $pdo = Database::connection();
        return \Utils\Format::uniqueSlug($name, 'rowerzysta', function (string $slug) use ($pdo): bool {
            $stmt = $pdo->prepare('SELECT id FROM users WHERE public_slug = :slug');
            $stmt->execute(['slug' => $slug]);
            return (bool) $stmt->fetchColumn();
        });
    }

    // Nadaje slug, jeśli konto jeszcze go nie ma (konta sprzed migracji 038,
    // których nie objął backfill). Zwraca slug albo null, gdy nie ma z czego go
    // zbudować. Wołane leniwie — NIE przy renderowaniu list, tylko tam, gdzie
    // faktycznie potrzebny jest adres profilu.
    public static function ensurePublicSlug(int $userId): ?string
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT name, email, public_slug FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        if (!empty($row['public_slug'])) {
            return $row['public_slug'];
        }

        $slug = self::generatePublicSlug($row['name'] ?: (string) strstr((string) $row['email'], '@', true));
        $pdo->prepare('UPDATE users SET public_slug = :slug WHERE id = :id')
            ->execute(['slug' => $slug, 'id' => $userId]);
        return $slug;
    }

    // Przełącznik „nie pokazuj mnie na listach uczestników i w peletonie"
    // („Moje konto" → Prywatność). Nie ukrywa FAKTU zapisu: osoba nadal liczy
    // się do składu, znika tylko jej twarz i imię (patrz EventRsvp::rosterForEdition).
    public static function updateRosterVisibility(int $userId, bool $visible): void
    {
        Database::connection()
            ->prepare('UPDATE users SET roster_visible = :visible WHERE id = :id')
            ->execute(['visible' => $visible ? 1 : 0, 'id' => $userId]);

        // KAFLE MAPY (migr. 051) — ukrycie się musi UNIEWAŻNIĆ adresy, nie
        // tylko przestać je generować. Kafel raz zapisany pod
        // /assets/tiles/{warstwa}/u-{slug}/... jest zwykłym plikiem, który
        // Apache odda każdemu, kto zna adres — a adres składa się z jawnego
        // sluga i współrzędnych do zgadnięcia. Bez tego kasowania osoba, która
        // się wypisała z list, zostawiałaby za sobą działającą mapę.
        //
        // Kasujemy w obie strony, także przy WŁĄCZANIU widoczności: kafle mogły
        // zdążyć powstać, gdy dane były inne, a to jest moment, w którym i tak
        // dotykamy tego klucza.
        $slug = self::find($userId)?->publicSlug;
        if ($slug !== null && $slug !== '') {
            foreach ([TileSource::LAYER_TRACKS, TileSource::LAYER_HEX] as $layer) {
                TileCache::purgeKey($layer, 'u-' . $slug);
            }
        }
    }
}
