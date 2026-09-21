<?php
// core/Controllers/AuthController.php
namespace Controllers;

use Core\Auth;
use Core\Csrf;
use Core\Mailer;
use Models\ActivationToken;
use Models\User;
use Utils\MailTemplate;
use Utils\RateLimiter;
use Utils\View;

class AuthController
{
    public static function registerForm(): void
    {
        View::render('web', 'register', [
            'title'   => __('Załóż konto — ridemore.bike'),
            'noindex' => true,
        ]);
    }

    public static function register(): void
    {
        $email = trim($_POST['email'] ?? '');

        $render = fn(string $error) => View::render('web', 'register', [
            'title'     => __('Załóż konto — ridemore.bike'),
            'error'     => $error,
            'email'     => $email,
        ]);

        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            $render(__('Sesja wygasła, spróbuj ponownie.'));
            return;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $render(__('Podaj poprawny adres e-mail.'));
            return;
        }

        // Rate limit: bez tego dowolna liczba żądań mogła wielokrotnie wysyłać
        // link aktywacyjny na ten sam (cudzy) adres.
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        if (RateLimiter::tooMany('register:ip:' . $ip, 20, 3600)
            || RateLimiter::tooMany('register:email:' . strtolower($email), 3, 600)) {
            $render(__('Zbyt wiele prób. Spróbuj ponownie za kilka minut.'));
            return;
        }

        $user = User::findByEmail($email);
        if ($user && $user->isActive()) {
            $render(__('Konto z tym adresem e-mail już istnieje.'));
            return;
        }
        if (!$user) {
            $user = User::createPending($email);
        }

        $token = ActivationToken::issueFor($user->id);
        $link  = View::absoluteUrl('/rejestracja/dokoncz?token=' . urlencode($token));
        $html  = MailTemplate::render('activation', ['link' => $link]);

        try {
            Mailer::send($email, __('Dokończ rejestrację w ridemore.bike'), $html);
        } catch (\Throwable $e) {
            $render(__('Nie udało się wysłać e-maila. Spróbuj ponownie za chwilę.'));
            return;
        }

        View::render('web', 'register', [
            'title' => __('Sprawdź skrzynkę — ridemore.bike'),
            'sent'  => true,
            'email' => $email,
        ]);
    }

    public static function completeRegistrationForm(): void
    {
        $token  = $_GET['token'] ?? '';
        $userId = $token !== '' ? ActivationToken::resolveUserId($token) : null;

        View::render('web', 'register-complete', [
            'title'     => __('Ustaw hasło — ridemore.bike'),
            'token'     => $token,
            'valid'     => $userId !== null,
        ]);
    }

    public static function completeRegistration(): void
    {
        $token    = $_POST['token'] ?? '';
        $password = $_POST['password'] ?? '';
        $repeat   = $_POST['password_repeat'] ?? '';

        $render = fn(bool $valid, string $error) => View::render('web', 'register-complete', [
            'title'     => __('Ustaw hasło — ridemore.bike'),
            'token'     => $token,
            'valid'     => $valid,
            'error'     => $error,
        ]);

        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            $render(true, __('Sesja wygasła, spróbuj ponownie.'));
            return;
        }

        $userId = $token !== '' ? ActivationToken::resolveUserId($token) : null;
        if ($userId === null) {
            $render(false, null);
            return;
        }
        if (strlen($password) < 8) {
            $render(true, __('Hasło musi mieć co najmniej 8 znaków.'));
            return;
        }
        if ($password !== $repeat) {
            $render(true, __('Hasła nie są takie same.'));
            return;
        }

        $user = User::find($userId);
        $user->setPasswordAndActivate($password);
        ActivationToken::markUsed($token);
        Auth::login($userId);

        header('Location: ' . View::url('/'));
        exit;
    }

    // Reset hasła — te same tokeny co aktywacja rejestracji (account_activation_tokens,
    // patrz Models\ActivationToken), tylko inny punkt wejścia i inny efekt na końcu
    // (resetPassword() zamiast setPasswordAndActivate() — nie dotyka email_verified_at,
    // bo konto już jest aktywne). Świadomie ten sam komunikat niezależnie od tego, czy
    // e-mail faktycznie istnieje w bazie — inaczej formularz zdradzałby, które adresy
    // są zarejestrowane (w odróżnieniu od /rejestracja, gdzie to jawny "załóż konto"
    // i enumeracja nikomu nie szkodzi).
    public static function forgotPasswordForm(): void
    {
        if (Auth::check()) {
            header('Location: ' . View::url('/'));
            exit;
        }
        View::render('web', 'forgot-password', [
            'title'   => __('Odzyskaj hasło — ridemore.bike'),
            'noindex' => true,
        ]);
    }

    public static function forgotPassword(): void
    {
        $email = trim($_POST['email'] ?? '');

        $render = fn(string $error) => View::render('web', 'forgot-password', [
            'title' => __('Odzyskaj hasło — ridemore.bike'),
            'error' => $error,
            'email' => $email,
        ]);

        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            $render(__('Sesja wygasła, spróbuj ponownie.'));
            return;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $render(__('Podaj poprawny adres e-mail.'));
            return;
        }

        // Rate limit: ten sam wzorzec co POST /rejestracja — bez tego dowolna
        // liczba żądań mogła wielokrotnie wysyłać link resetu na cudzy adres.
        // Celowo bez komunikatu o zbyt wielu próbach (w odróżnieniu od
        // /rejestracja) — trasa i tak zawsze pokazuje ten sam ekran "sprawdź
        // skrzynkę" niezależnie od wyniku, żeby nie zdradzać, czy e-mail istnieje.
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $limited = RateLimiter::tooMany('reset-password:ip:' . $ip, 20, 3600)
            || RateLimiter::tooMany('reset-password:email:' . strtolower($email), 3, 600);

        $user = $limited ? null : User::findByEmail($email);
        if ($user && $user->isActive()) {
            $token = ActivationToken::issueFor($user->id);
            $link  = View::absoluteUrl('/odzyskaj-haslo/nowe?token=' . urlencode($token));
            \Core\Lang::with(\Core\Lang::forEmail((string) ($email)), static fn() => Mailer::sendTemplate('password-reset', $email, __('Zresetuj hasło w ridemore.bike'), [
                'link' => $link,
            ]));
        }

        View::render('web', 'forgot-password', [
            'title' => __('Odzyskaj hasło — ridemore.bike'),
            'sent'  => true,
            'email' => $email,
        ]);
    }

    public static function resetPasswordForm(): void
    {
        $token  = $_GET['token'] ?? '';
        $userId = $token !== '' ? ActivationToken::resolveUserId($token) : null;

        View::render('web', 'reset-password', [
            'title' => __('Ustaw nowe hasło — ridemore.bike'),
            'token' => $token,
            'valid' => $userId !== null,
        ]);
    }

    public static function resetPassword(): void
    {
        $token    = $_POST['token'] ?? '';
        $password = $_POST['password'] ?? '';
        $repeat   = $_POST['password_repeat'] ?? '';

        $render = fn(bool $valid, ?string $error) => View::render('web', 'reset-password', [
            'title' => __('Ustaw nowe hasło — ridemore.bike'),
            'token' => $token,
            'valid' => $valid,
            'error' => $error,
        ]);

        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            $render(true, __('Sesja wygasła, spróbuj ponownie.'));
            return;
        }

        $userId = $token !== '' ? ActivationToken::resolveUserId($token) : null;
        if ($userId === null) {
            $render(false, null);
            return;
        }
        if (strlen($password) < 8) {
            $render(true, __('Hasło musi mieć co najmniej 8 znaków.'));
            return;
        }
        if ($password !== $repeat) {
            $render(true, __('Hasła nie są takie same.'));
            return;
        }

        $user = User::find($userId);
        $user->resetPassword($password);
        ActivationToken::markUsed($token);
        Auth::login($userId);

        header('Location: ' . View::url('/'));
        exit;
    }

    public static function loginForm(): void
    {
        if (Auth::check()) {
            header('Location: ' . View::url('/'));
            exit;
        }
        View::render('web', 'login', [
            'title'   => __('Zaloguj się — ridemore.bike'),
            'noindex' => true,
            // Komunikat po samoobsługowym usunięciu konta (Etap 9, 2026-08-29,
            // AccountController::requestDeletion) — sesja już nie istnieje w tym
            // momencie, więc jedyne miejsce, gdzie da się to pokazać, to strona
            // logowania, dokąd przekierowuje wylogowanie.
            'info'    => ($_GET['info'] ?? '') === 'konto-do-usuniecia'
                ? __('Konto zostało zablokowane. Prośba o jego usunięcie trafiła do administratora.')
                : null,
        ]);
    }

    public static function login(): void
    {
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        $render = fn(string $error) => View::render('web', 'login', [
            'title'     => __('Zaloguj się — ridemore.bike'),
            'error'     => $error,
            'email'     => $email,
        ]);

        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            $render(__('Sesja wygasła, spróbuj ponownie.'));
            return;
        }

        $user = User::findByEmail($email);
        if (!$user) {
            // Stałe koszt password_verify, żeby brak konta nie odpowiadał zauważalnie szybciej.
            password_verify($password, '$2y$10$invalidinvalidinvalidinvalidinvalidinvalidinvalidinva');
        }

        if (!$user || !$user->isActive() || !$user->verifyPassword($password)) {
            $render(__('Nieprawidłowy e-mail lub hasło.'));
            return;
        }

        // ZABLOKOWANY DOSTAJE WŁASNY KOMUNIKAT, nie „złe hasło" (migr. 058).
        // Ukrywanie blokady pod błędem logowania brzmi bezpieczniej, ale jest
        // okrutne i bezcelowe: człowiek w kółko resetuje poprawne hasło, a my
        // i tak nie chronimy niczego — hasło już podał prawidłowo, więc to jego
        // konto. Powodu NIE pokazujemy: bywa notatką wewnętrzną.
        if ($user->isBlocked()) {
            $render(__('To konto zostało zablokowane. Napisz do nas, jeśli to pomyłka.'));
            return;
        }

        Auth::login($user->id, isset($_POST['remember']));
        header('Location: ' . View::url('/'));
        exit;
    }

    public static function logout(): void
    {
        if (Csrf::check($_POST['csrf_token'] ?? null)) {
            Auth::logout();
        }
        header('Location: ' . View::url('/'));
        exit;
    }
}
