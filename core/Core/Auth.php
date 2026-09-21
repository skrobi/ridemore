<?php
// core/Core/Auth.php
namespace Core;

use Models\User;
use Utils\View;

class Auth
{
    private static ?User $cachedUser = null;
    private static bool $resolved = false;

    // 30 dni — czas trzymania sesji przy zaznaczonym "Zapamiętaj mnie".
    private const REMEMBER_SECONDS = 60 * 60 * 24 * 30;

    public static function login(int $userId, bool $remember = false): void
    {
        // W APLIKACJI MOBILNEJ „Zapamiętaj mnie" jest zawsze zaznaczone, nawet
        // gdy formularz przysłał odznaczony checkbox. Tam nie ma „zamknięcia
        // przeglądarki", które ten wybór opisuje — jest ubicie procesu przez
        // system, po którym cookie sesyjne przepada i użytkownik wraca na ekran
        // logowania mimo że nigdy się nie wylogował. Drugą połowę tej decyzji
        // (parametry cookie na kolejnych żądaniach) robi core/bootstrap.php.
        $remember = $remember || (defined('APP_IS_APP') && APP_IS_APP);

        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        self::$cachedUser = null;
        self::$resolved = false;

        // Domyślny cookie sesji ginie z zamknięciem przeglądarki (cookie_lifetime=0)
        // i serwer czyści go po session.gc_maxlifetime (24 min bezczynności) —
        // stąd "krótko trzymana sesja". Zaznaczone "Zapamiętaj mnie" nadpisuje
        // cookie sesji na trwały (30 dni) i zostawia znacznik remember_me, który
        // bootstrap.php odczytuje PRZED session_start() na kolejnych żądaniach,
        // żeby ustawić te same parametry zanim sesja się w ogóle zacznie.
        $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        if ($remember) {
            setcookie(session_name(), session_id(), [
                'expires'  => time() + self::REMEMBER_SECONDS,
                'path'     => '/',
                'secure'   => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            setcookie('remember_me', '1', [
                'expires'  => time() + self::REMEMBER_SECONDS,
                'path'     => '/',
                'secure'   => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        } elseif (isset($_COOKIE['remember_me'])) {
            // Poprzednio zapamiętany, ale tym razem odznaczone — wracamy do
            // zwykłego cookie sesyjnego (ginącego z zamknięciem przeglądarki).
            setcookie(session_name(), session_id(), [
                'expires'  => 0,
                'path'     => '/',
                'secure'   => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            setcookie('remember_me', '', [
                'expires'  => time() - 3600,
                'path'     => '/',
                'secure'   => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
    }

    public static function logout(): void
    {
        unset($_SESSION['user_id']);
        session_regenerate_id(true);
        self::$cachedUser = null;
        self::$resolved = true;

        if (isset($_COOKIE['remember_me'])) {
            $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
            setcookie('remember_me', '', [
                'expires'  => time() - 3600,
                'path'     => '/',
                'secure'   => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    // Wzorzec powtórzony ~20 razy w web/routes.php i raz jako blanket-guard na
    // górze admin/routes.php: przekieruj na /logowanie i zakończ, gdy nikt nie
    // jest zalogowany. Zwraca zalogowanego usera, żeby wywołujący od razu miał
    // $user zamiast osobno wołać Auth::user() zaraz po tej bramce.
    public static function requireLogin(): User
    {
        if (!self::check()) {
            header('Location: ' . View::url('/logowanie'));
            exit;
        }
        return self::user();
    }

    // To samo co requireLogin(), plus wymóg is_admin — 403 (nie redirect,
    // w odróżnieniu od braku zalogowania) z komunikatem dopasowanym do
    // konkretnej trasy, jak dotychczas.
    public static function requireAdmin(?string $message = null): User
    {
        $message ??= __('Brak uprawnień.');
        $user = self::requireLogin();
        if (!$user->isAdmin) {
            http_response_code(403);
            echo $message;
            exit;
        }
        return $user;
    }

    // Rozwiązuje usera z sesji raz na request i cache'uje wynik — także gdy
    // to null, żeby stała sesja wskazująca na skasowane konto (usunięty
    // użytkownik, ręcznie edytowany cookie) konsekwentnie liczyła się jako
    // "niezalogowany", a nie wywalała błąd na Auth::user()->id gdzieś dalej.
    public static function user(): ?User
    {
        if (!self::$resolved) {
            $user = isset($_SESSION['user_id']) ? User::find((int) $_SESSION['user_id']) : null;

            // BLOKADA DZIAŁA NATYCHMIAST, nie od następnego logowania (migr. 058).
            // Sprawdzenie stoi tutaj, bo tędy przechodzi KAŻDE żądanie od
            // zalogowanego — gdyby siedziało tylko w formularzu logowania,
            // zablokowany komentowałby dalej z otwartej sesji, a moderacja
            // działałaby dopiero, gdyby sam się wylogował.
            if ($user !== null && $user->isBlocked()) {
                self::logout();
                $user = null;
            }

            // Każdy zarejestrowany jest "rowerzystą" i ma mieć publiczny profil
            // (/rowerzysta/{slug}) — konta sprzed migr. 038, albo takie, które z
            // jakiegoś powodu nie dostały sluga przy rejestracji, domykamy tutaj,
            // przy pierwszej okazji, zamiast czekać na ręczny backfill_rider_slugs.php
            // na produkcji. ensurePublicSlug() bierze nazwę, a bez niej przedrostek
            // e-maila (patrz Models\User::generatePublicSlug) — user zmieni później
            // na dowolny wolny w ustawieniach konta.
            if ($user !== null && $user->publicSlug === null) {
                $user->publicSlug = User::ensurePublicSlug($user->id);
            }

            self::$cachedUser = $user;
            self::$resolved = true;
        }
        return self::$cachedUser;
    }
}
