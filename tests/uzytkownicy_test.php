<?php
// tests/uzytkownicy_test.php
// MODERACJA KONT (migr. 058) — blokada, odblokowanie, kasowanie fejków.
//
// Najważniejszy jest tu ostatni blok: KASOWANIE. Klucze obce na `users`
// kaskadują do 25 tabel, więc pomyłka nie kończy się „zniknął jeden wiersz",
// tylko wyrwaną dziurą w cudzych wyjazdach i w niezmiennym rejestrze punktów.
// To dokładnie ten rodzaj operacji, którego nie wolno pilnować pamięcią.
use Models\UserAdmin;

/** Konto bez historii — do testów kasowania. */
function t_pusty_user(string $email = null): int
{
    $db = Core\Database::connection();
    $db->prepare('INSERT INTO users (name, email, password_hash, email_verified_at, is_admin)
                  VALUES (:n, :e, :p, NOW(), 0)')
       ->execute([
           'n' => 'TEST konto',
           'e' => $email ?? ('test-' . bin2hex(random_bytes(6)) . '@example.invalid'),
           'p' => password_hash('x', PASSWORD_DEFAULT),
       ]);

    return (int) $db->lastInsertId();
}

t_test('Blokada zapisuje powód, datę i autora', function () {
    $id = t_pusty_user();
    t_true(UserAdmin::block($id, 'Wulgaryzmy w komentarzach', t_user(0)), 'blokada przeszła');

    $u = UserAdmin::find($id);
    t_not_null($u['blocked_at'], 'data blokady zapisana');
    t_eq('Wulgaryzmy w komentarzach', $u['blocked_reason'], 'powód zapisany');
});

t_test('Blokada bez powodu jest odrzucana', function () {
    $id = t_pusty_user();
    t_false(UserAdmin::block($id, '   ', t_user(0)), 'same spacje to nie powód');
    t_null(UserAdmin::find($id)['blocked_at'], 'konto pozostało czynne');
});

t_test('Blokada jest jednorazowa — druga nie nadpisuje pierwszej', function () {
    $id = t_pusty_user();
    UserAdmin::block($id, 'Pierwszy powód', t_user(0));
    t_false(UserAdmin::block($id, 'Drugi powód', t_user(0)), 'druga blokada odrzucona');
    t_eq('Pierwszy powód', UserAdmin::find($id)['blocked_reason'], 'powód się nie zmienił');
});

t_test('Administratora nie da się zablokować z tego ekranu', function () {
    $db = Core\Database::connection();
    $id = t_pusty_user();
    $db->exec('UPDATE users SET is_admin = 1 WHERE id = ' . $id);

    t_false(UserAdmin::block($id, 'próba', t_user(0)), 'blokada admina odrzucona');
    t_null(UserAdmin::find($id)['blocked_at'], 'konto admina nietknięte');
});

t_test('Odblokowanie czyści komplet pól', function () {
    $id = t_pusty_user();
    UserAdmin::block($id, 'powód', t_user(0));
    t_true(UserAdmin::unblock($id), 'odblokowanie przeszło');

    $u = UserAdmin::find($id);
    t_null($u['blocked_at'], 'data wyczyszczona');
    t_null($u['blocked_reason'], 'powód wyczyszczony — inaczej zostałby jako duch przy czynnym koncie');
    t_false(UserAdmin::unblock($id), 'odblokowanie czynnego konta nic nie robi');
});

t_test('Zablokowany user jest widoczny dla Models\User i oznaczony', function () {
    $id = t_pusty_user();
    UserAdmin::block($id, 'powód', t_user(0));

    $u = Models\User::find($id);
    t_not_null($u, 'konto nadal istnieje — blokada to nie kasowanie');
    t_true($u->isBlocked(), 'model wie o blokadzie');
    t_true($u->isActive(), 'isActive nadal true — to inne pytanie niż blokada');
});

// --- Samoobsługowe kasowanie konta w apce (Etap 9, migr. 079, 2026-08-29) --
//
// Models\User::requestDeletion() — NATYCHMIASTOWA blokada (ten sam mechanizm
// co UserAdmin::block() wyżej) plus deletion_requested_at, żeby admin umiał
// odróżnić prośbę o odejście od moderacji. Twardego kasowania kont z treścią
// nadal nie ma — patrz testy „Kasowanie" niżej i komentarz na górze pliku.

t_test('Prośba o usunięcie: złe albo brakujące hasło jest odrzucane, gdy konto je ma', function () {
    $id = t_pusty_user(); // hasło 'x', patrz t_pusty_user()
    $user = Models\User::find($id);

    t_false($user->requestDeletion('zle-haslo'), 'złe hasło odrzucone');
    t_false($user->requestDeletion(null), 'brak hasła odrzucony, gdy konto ma hasło do sprawdzenia');
    t_null(UserAdmin::find($id)['blocked_at'], 'konto pozostało czynne po obu próbach');
});

t_test('Prośba o usunięcie: poprawne hasło blokuje konto i oznacza je do usunięcia', function () {
    $id = t_pusty_user();
    $user = Models\User::find($id);

    t_true($user->requestDeletion('x'), 'poprawne hasło przyjęte');

    $u = UserAdmin::find($id);
    t_not_null($u['blocked_at'], 'konto zablokowane od razu');
    t_not_null($u['deletion_requested_at'], 'oznaczone do ręcznego dokasowania przez admina');
    t_eq($id, (int) $u['blocked_by'], 'zablokowane przez samo siebie, nie przez admina');
});

t_test('Prośba o usunięcie: konto bez hasła (logowanie społecznościowe) nie wymaga hasła', function () {
    $id = t_pusty_user();
    Core\Database::connection()->prepare('UPDATE users SET password_hash = NULL WHERE id = :id')->execute(['id' => $id]);

    $user = Models\User::find($id);
    t_true($user->requestDeletion(null), 'brak hasła do zweryfikowania — przechodzi bez niego');
    t_not_null(UserAdmin::find($id)['blocked_at'], 'konto społecznościowe też się blokuje');
});

t_test('Prośba o usunięcie: drugi raz nic nie robi — konto już zablokowane', function () {
    $id = t_pusty_user();
    Models\User::find($id)->requestDeletion('x');

    $user2 = Models\User::find($id); // świeży odczyt stanu z bazy
    t_false($user2->requestDeletion('x'), 'druga prośba na już zablokowanym koncie odrzucona');
});

t_test('Prośba o usunięcie: filtr „do_usuniecia" widzi ją, „zablokowani" też, ale są rozróżnialne', function () {
    $id = t_pusty_user();
    Models\User::find($id)->requestDeletion('x');

    $wDoUsuniecia = array_column(UserAdmin::search(['filtr' => 'do_usuniecia'])['items'], 'id');
    $wZablokowani = array_column(UserAdmin::search(['filtr' => 'zablokowani'])['items'], 'id');
    t_true(in_array($id, $wDoUsuniecia, true), 'widoczne w kolejce do usunięcia');
    t_true(in_array($id, $wZablokowani, true), 'ten sam mechanizm blokady — widoczne też jako zablokowane');

    $counters = UserAdmin::counters();
    t_true($counters['do_usuniecia'] >= 1, 'licznik kolejki liczy co najmniej to jedno konto');
});

t_test('Puste konto da się skasować', function () {
    $id = t_pusty_user();
    t_true(UserAdmin::contentSummary($id)['puste'], 'konto bez historii');

    $r = UserAdmin::deleteIfEmpty($id, t_user(0));
    t_true($r['ok'], 'skasowane');
    t_null(UserAdmin::find($id), 'nie ma go w bazie');
});

t_test('Konto Z HISTORIĄ nie da się skasować', function () {
    // Ten test broni 25 tabel z ON DELETE CASCADE. Gdyby padł, kasowanie
    // zabierałoby ze sobą składy cudzych wyjazdów i rejestr punktów.
    $id = t_pusty_user();
    Core\Database::connection()
        ->prepare('INSERT INTO event_comments (event_id, user_id, body) VALUES
                   ((SELECT id FROM events LIMIT 1), :u, :b)')
        ->execute(['u' => $id, 'b' => 'komentarz testowy']);

    t_false(UserAdmin::contentSummary($id)['puste'], 'konto ma treść');

    $r = UserAdmin::deleteIfEmpty($id, t_user(0));
    t_false($r['ok'], 'kasowanie odrzucone');
    t_eq('ma_tresc', $r['powod'], 'powód odmowy');
    t_not_null(UserAdmin::find($id), 'konto zostało');
});

t_test('Własnego konta i konta admina nie da się skasować', function () {
    $db = Core\Database::connection();
    $ja = t_pusty_user();
    t_eq('wlasne_konto', UserAdmin::deleteIfEmpty($ja, $ja)['powod'], 'własne konto');

    $admin = t_pusty_user();
    $db->exec('UPDATE users SET is_admin = 1 WHERE id = ' . $admin);
    t_eq('admin', UserAdmin::deleteIfEmpty($admin, t_user(0))['powod'], 'konto admina');
    t_not_null(UserAdmin::find($admin), 'admin nietknięty');
});

t_test('Podsumowanie treści nazywa to, co zablokowało kasowanie', function () {
    $id = t_pusty_user();
    Core\Database::connection()
        ->prepare('INSERT INTO event_comments (event_id, user_id, body) VALUES
                   ((SELECT id FROM events LIMIT 1), :u, :b)')
        ->execute(['u' => $id, 'b' => 'komentarz testowy']);

    $s = UserAdmin::contentSummary($id);
    t_true(isset($s['pozycje']['komentarze']), 'komentarz wymieniony po nazwie: '
        . implode(', ', array_keys($s['pozycje'])));
});

t_test('Filtry listy trafiają w to, co obiecują', function () {
    $id = t_pusty_user();
    UserAdmin::block($id, 'powód', t_user(0));

    $zablokowani = UserAdmin::search(['filtr' => 'zablokowani']);
    foreach ($zablokowani['items'] as $u) {
        if ($u['blocked_at'] === null) {
            t_fail('w filtrze „zablokowani" wylądowało czynne konto');
        }
    }
    t_true($zablokowani['total'] >= 1, 'zablokowane konto jest na liście');

    // Świeżo założone konto MUSI być w „nowych" — to jest cel tego ekranu.
    $nowi = array_column(UserAdmin::search(['filtr' => 'nowi'])['items'], 'id');
    t_true(in_array((string) $id, array_map('strval', $nowi), true),
        'nowe konto widać w filtrze „nowi"');
});

t_test('Szukanie działa po e-mailu i po imieniu', function () {
    $email = 'szukajka-' . bin2hex(random_bytes(4)) . '@example.invalid';
    t_pusty_user($email);

    t_eq(1, UserAdmin::search(['szukaj' => $email])['total'], 'po pełnym e-mailu');
    t_true(UserAdmin::search(['szukaj' => 'TEST konto'])['total'] >= 1, 'po imieniu');
    t_eq(0, UserAdmin::search(['szukaj' => 'nie-ma-takiego-nigdzie-xyz'])['total'], 'po bzdurze');
});

// ============================================================
// TOKEN LOGOWANIA APLIKACJI MOBILNEJ (migr. 067).
//
// Zgłoszenie usera 2026-08-23: „logowanie nie działa poprawnie w aplikacji
// (googla) (…) autoryzacja się nie powiodła i zostaję już na WWW". Google
// blokuje OAuth w WebView, więc autoryzacja dzieje się w systemowej
// przeglądarce, a ta ma OSOBNE ciasteczka — sesja powstawała nie po tej
// stronie, co trzeba. Ten token jest jedynym mostem między nimi.
//
// TESTUJEMY GO ZACHOWANIEM, NIE SKANOWANIEM PLIKU, bo to jest mechanizm
// LOGOWANIA: kto poda ważny token, zostaje zalogowany bez hasła. Trzy
// własności, których złamanie nie daje żadnego widocznego objawu — token
// działający dwa razy, token wieczny i token leżący w bazie jawnym tekstem —
// są tu ważniejsze niż cokolwiek, co widać na ekranie.
// ============================================================

t_test('token aplikacji: wymienia się na właściwego użytkownika', function () {
    $id = t_pusty_user();
    $token = Models\AppLoginToken::issue($id);

    t_true($token !== '', 'token nie jest pusty');
    t_eq(64, strlen($token), '32 bajty losowe zapisane szesnastkowo');
    t_eq($id, Models\AppLoginToken::consume($token), 'oddaje id właściciela');
});

t_test('token aplikacji: działa DOKŁADNIE RAZ', function () {
    // Deep link potrafi zostać aktywowany dwa razy (Android bywa w tym hojny),
    // a token, który przeżywa użycie, jest kluczem do konta leżącym w logach.
    $id = t_pusty_user();
    $token = Models\AppLoginToken::issue($id);

    t_eq($id, Models\AppLoginToken::consume($token), 'pierwsze użycie loguje');
    t_null(Models\AppLoginToken::consume($token), 'drugie już nie');
});

t_test('token aplikacji: wygasły nie loguje nikogo', function () {
    $id = t_pusty_user();
    $token = Models\AppLoginToken::issue($id);

    // Cofamy ważność zamiast czekać trzech minut — sprawdzamy WARUNEK
    // wygaśnięcia w zapytaniu, a nie zegar.
    Core\Database::connection()
        ->prepare('UPDATE app_login_tokens SET expires_at = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE user_id = ?')
        ->execute([$id]);

    t_null(Models\AppLoginToken::consume($token), 'po terminie token jest bezużyteczny');
});

t_test('token aplikacji: w bazie leży HASH, nie sam token', function () {
    // Gdyby leżał jawnie, wyciek bazy oznaczałby gotowe klucze do kont —
    // ta sama zasada co przy tokenach resetu hasła.
    $id = t_pusty_user();
    $token = Models\AppLoginToken::issue($id);

    $stmt = Core\Database::connection()->prepare('SELECT token_hash FROM app_login_tokens WHERE user_id = ?');
    $stmt->execute([$id]);
    $zapisany = (string) $stmt->fetchColumn();

    t_false($zapisany === $token, 'zapis różni się od tokenu');
    t_same(hash('sha256', $token), $zapisany, 'i jest jego SHA-256');
});

t_test('token aplikacji: śmieci i pusty ciąg nie logują', function () {
    t_null(Models\AppLoginToken::consume(''), 'pusty');
    t_null(Models\AppLoginToken::consume('nie-istnieje'), 'zmyślony');
    t_null(Models\AppLoginToken::consume(str_repeat('a', 64)), 'o właściwej długości, ale nieznany');
});

t_test('token aplikacji: dwa tokeny nie mieszają się między kontami', function () {
    $a = t_pusty_user();
    $b = t_pusty_user();
    $tokenA = Models\AppLoginToken::issue($a);
    $tokenB = Models\AppLoginToken::issue($b);

    t_eq($b, Models\AppLoginToken::consume($tokenB), 'B dostaje swoje');
    t_eq($a, Models\AppLoginToken::consume($tokenA), 'A dostaje swoje');
});

// ============================================================
// SLUG PROFILU ROWERZYSTY DOMYKANY PRZY LOGOWANIU (Auth::user()).
//
// views/web/partials/header.php pokazuje link „Mój profil rowerzysty" tylko,
// gdy $currentUser->publicSlug jest ustawiony. Konta założone przed migr. 038
// (publiczny profil, /rowerzysta/{slug}) — albo takie, które z innego powodu
// nie dostały sluga przy rejestracji — miały ten link schowany NA ZAWSZE,
// dopóki ktoś ręcznie nie odpalił backfill_rider_slugs.php na produkcji.
// Zgłoszenie usera 2026-09-07: „nawet w dropdownie się nie pojawia".
//
// Auth::user() domyka to samo przy pierwszym żądaniu zalogowanego, bez
// czekania na ręczny backfill — patrz core/Core/Auth.php.
// ============================================================

t_test('Auth::user() nadaje slug kontu bez niego — z e-maila, gdy brak imienia', function () {
    $email = 'zbig.przyklad-' . bin2hex(random_bytes(4)) . '@example.invalid';
    $db = Core\Database::connection();
    $db->prepare('INSERT INTO users (name, email, password_hash, email_verified_at, is_admin)
                  VALUES (:n, :e, :p, NOW(), 0)')
       ->execute(['n' => '', 'e' => $email, 'p' => password_hash('x', PASSWORD_DEFAULT)]);
    $id = (int) $db->lastInsertId();

    t_null(Models\User::find($id)->publicSlug, 'punkt wyjścia: świeże konto nie ma sluga');

    t_auth_as($id);
    $user = Core\Auth::user();

    t_not_null($user->publicSlug, 'Auth::user() nadał slug od razu, przy pierwszym zalogowaniu');
    t_true(str_starts_with($user->publicSlug, 'zbig-przyklad'),
        'slug zbudowany z przedrostka e-maila, skoro imię jest puste: ' . $user->publicSlug);

    t_eq($user->publicSlug, Models\User::find($id)->publicSlug,
        'slug zapisany trwale w bazie, nie tylko w obiekcie z pamięci');
});

t_test('Auth::user() nie rusza konta, które slug już ma', function () {
    $id = t_pusty_user(); // ma imię 'TEST konto', patrz t_pusty_user()
    $pierwszy = Models\User::ensurePublicSlug($id); // tak jak zrobiłaby to rejestracja

    t_auth_as($id);
    $user = Core\Auth::user();

    t_eq($pierwszy, $user->publicSlug, 'slug nadany wcześniej pozostaje bez zmian po zalogowaniu');
});

t_test('Auth::user() bez sesji nadal zwraca null, bez wyjątku', function () {
    t_auth_as(null);
    t_null(Core\Auth::user(), 'brak zalogowanego usera to legalny, częsty przypadek');
});
