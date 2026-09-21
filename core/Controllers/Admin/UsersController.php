<?php
// core/Controllers/Admin/UsersController.php
// UŻYTKOWNICY — przegląd i moderacja (panel admina).
//
// Cztery czynności, z których każda odpowiada na inne pytanie:
//   „kto się zarejestrował?"    → lista z filtrem „nowi"
//   „ten ktoś klnie"            → blokada z powodem
//   „ten ktoś zgubił hasło"     → wysłanie linku resetu
//   „to jest fejk"              → skasowanie, ale WYŁĄCZNIE pustego konta
//
// HASŁA NIE USTAWIAMY ZA UŻYTKOWNIKA i to jest tu najważniejsza decyzja.
// Kuszące byłoby pole „nowe hasło" w tabelce — admin wpisuje, dyktuje przez
// telefon i po sprawie. Ale wtedy hasło przechodzi przez czyjeś ręce, ląduje
// w historii przeglądarki i w logach POST-a, a konto przestaje być wyłączną
// własnością właściciela. Zamiast tego wysyłamy ten sam link, który wysyła
// formularz „odzyskaj hasło" — admin uruchamia procedurę, nie zna wyniku.
namespace Controllers\Admin;

use Controllers\Support;
use Core\Auth;
use Core\Csrf;
use Core\Mailer;
use Models\ActivationToken;
use Models\User;
use Models\UserAdmin;
use Utils\View;

class UsersController
{
    public static function index(): void
    {
        $wynik = UserAdmin::search([
            'szukaj' => $_GET['q'] ?? '',
            'filtr'  => $_GET['filtr'] ?? '',
            'strona' => (int) ($_GET['strona'] ?? 1),
        ]);

        View::render('web', 'users-admin', [
            'title'    => 'Użytkownicy — ridemore.bike',
            'noindex'  => true,
            'wynik'    => $wynik,
            'liczniki' => UserAdmin::counters(),
            // Podsumowanie treści liczymy TYLKO dla widocznej strony, nie dla
            // całej bazy: to dziewięć zapytań na konto, więc przy 30 wierszach
            // 270 zapytań — a przy tysiącu kont byłoby 9000.
            'tresc'    => array_reduce(
                $wynik['items'],
                static function (array $acc, array $u): array {
                    $acc[(int) $u['id']] = UserAdmin::contentSummary((int) $u['id']);
                    return $acc;
                },
                []
            ),
            'info'     => match ($_GET['info'] ?? '') {
                'zablokowany'  => 'Konto zablokowane. Ta osoba nie zaloguje się do czasu odblokowania.',
                'odblokowany'  => 'Konto odblokowane.',
                'skasowany'    => 'Konto skasowane.',
                'reset-wyslany'=> 'Link do ustawienia nowego hasła poszedł na adres tego konta.',
                'brak-powodu'  => 'Blokada wymaga powodu — bez niego za pół roku nikt nie będzie wiedział, czemu konto jest wyłączone.',
                'ma-tresc'     => 'Tego konta nie da się skasować, bo ma już swoją historię w serwisie. Zablokuj je zamiast kasować.',
                'admin'        => 'Konta administratora nie ruszamy z tego ekranu.',
                'wlasne'       => 'Własnego konta nie skasujesz.',
                'nie-poszlo'   => 'Nic się nie zmieniło — konto mogło zostać już zmienione w innej karcie.',
                default        => null,
            },
            'breadcrumbs' => [Support::homeCrumb(), Support::panelCrumb(), ['label' => 'Użytkownicy']],
        ]);
    }

    public static function block(string $id): void
    {
        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            self::back();
            return;
        }

        $powod = trim((string) ($_POST['powod'] ?? ''));
        if ($powod === '') {
            self::back('brak-powodu');
            return;
        }

        $ok = UserAdmin::block((int) $id, $powod, (int) Auth::user()?->id);
        self::back($ok ? 'zablokowany' : 'nie-poszlo');
    }

    public static function unblock(string $id): void
    {
        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            self::back();
            return;
        }

        self::back(UserAdmin::unblock((int) $id) ? 'odblokowany' : 'nie-poszlo');
    }

    /**
     * Link do ustawienia nowego hasła — ta sama droga co „odzyskaj hasło".
     *
     * Świadomie BEZ rate-limitu, który ma tamta trasa: tam chroni przed
     * zasypywaniem cudzej skrzynki przez anonima, tutaj po drugiej stronie
     * siedzi zalogowany admin, a cały ekran jest już za `$adminPost`.
     */
    public static function resetPassword(string $id): void
    {
        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            self::back();
            return;
        }

        $user = User::find((int) $id);
        if ($user === null) {
            self::back('nie-poszlo');
            return;
        }

        $token = ActivationToken::issueFor($user->id);
        \Core\Lang::with(\Core\Lang::forEmail((string) ($user->email)), static fn() => Mailer::sendTemplate(
            'password-reset',
            $user->email,
            __('Zresetuj hasło w ridemore.bike'),
            ['link' => View::absoluteUrl('/odzyskaj-haslo/nowe?token=' . urlencode($token))]
        ));

        self::back('reset-wyslany');
    }

    public static function delete(string $id): void
    {
        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            self::back();
            return;
        }

        $wynik = UserAdmin::deleteIfEmpty((int) $id, (int) Auth::user()?->id);

        self::back($wynik['ok'] ? 'skasowany' : match ($wynik['powod']) {
            'ma_tresc'     => 'ma-tresc',
            'admin'        => 'admin',
            'wlasne_konto' => 'wlasne',
            default        => 'nie-poszlo',
        });
    }

    /**
     * Powrót na listę Z ZACHOWANIEM filtra i strony.
     *
     * Bez tego każda blokada wyrzucała admina na pierwszą stronę bez filtra —
     * przy przeglądaniu listy „nowych" znaczyłoby to szukanie miejsca od nowa
     * po każdej decyzji.
     */
    private static function back(?string $info = null): void
    {
        $params = array_filter([
            'q'      => $_POST['wroc_q'] ?? null,
            'filtr'  => $_POST['wroc_filtr'] ?? null,
            'strona' => $_POST['wroc_strona'] ?? null,
            'info'   => $info,
        ], static fn($v): bool => $v !== null && $v !== '');

        header('Location: ' . View::url('/admin/uzytkownicy') . ($params ? '?' . http_build_query($params) : ''));
        exit;
    }
}
