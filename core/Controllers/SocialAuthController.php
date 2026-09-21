<?php
// core/Controllers/SocialAuthController.php
// Logowanie społecznościowe (Google/Strava) na bazie league/oauth2-client.
// Google zwraca ZWERYFIKOWANY e-mail → dopasowanie/utworzenie konta wprost.
// Strava NIE zwraca e-maila (usunięte z API) → po autoryzacji dopytujemy o
// e-mail w osobnym kroku; e-mail jest wtedy NIEZWERYFIKOWANY, więc NIE wolno
// go auto-podpinać do istniejącego konta (ryzyko przejęcia).
namespace Controllers;

use Core\Auth;
use Core\Csrf;
use Models\AppLoginToken;
use Models\OAuthIdentity;
use Models\User;
use Utils\OAuthProvider;
use Utils\View;

class SocialAuthController
{
    /**
     * Schemat deep linku aplikacji mobilnej — musi być IDENTYCZNY z `appId`
     * w app/capacitor.config.ts oraz z intent-filterem w AndroidManifest.xml
     * i CFBundleURLTypes w Info.plist. Rozjazd nie da błędu: telefon po prostu
     * nie będzie wiedział, że ten adres należy do apki, i zostawi użytkownika
     * w przeglądarce — czyli dokładnie w usterce, którą to naprawia.
     */
    private const APP_SCHEME = 'bike.ridemore.app';

    public static function redirect(string $provider): void
    {
        if (!OAuthProvider::isConfigured($provider)) {
            self::disabled($provider);
            return;
        }

        // PRZEPŁYW Z APLIKACJI oznaczamy PARAMETREM, nie User-Agentem.
        //
        // To żądanie wychodzi z SYSTEMOWEJ PRZEGLĄDARKI, nie z WebView — bo
        // Google odmawia OAuth w WebView — więc `APP_IS_APP` jest tu z definicji
        // fałszywe i nie ma z czego rozpoznać apki. Znacznik ląduje w sesji
        // przeglądarki i dożywa do callbacku (przy Stravie także przez ekran
        // dopytania o e-mail).
        if (($_GET['app'] ?? '') === '1') {
            $_SESSION['oauth_app'] = true;
        }

        $p = OAuthProvider::make($provider);
        $url = $p->getAuthorizationUrl();
        // state w sesji = ochrona przed CSRF na callbacku (weryfikowana niżej).
        $_SESSION['oauth2state'] = $p->getState();
        header('Location: ' . $url);
        exit;
    }

    /**
     * ODBIÓR LOGOWANIA W APLIKACJI — `/auth/app?token=…`.
     *
     * Wołane przez WebView apki po tym, jak system otworzył deep link
     * wystawiony na końcu OAuth w przeglądarce. Dopiero TUTAJ powstaje sesja
     * i dopiero tutaj jest ona po właściwej stronie: w cookie WebView.
     */
    public static function appHandoff(): void
    {
        $userId = AppLoginToken::consume((string) ($_GET['token'] ?? ''));

        if ($userId === null) {
            // JEDEN komunikat na wszystkie przyczyny (nieznany, wygasły, użyty)
            // — rozróżnienie byłoby podpowiedzią dla zgadującego.
            self::fail(__('Logowanie wygasło. Spróbuj jeszcze raz.'));
            return;
        }

        Auth::login($userId, true);
        header('Location: ' . View::url('/'));
        exit;
    }

    public static function callback(string $provider): void
    {
        if (!OAuthProvider::isConfigured($provider)) {
            self::disabled($provider);
            return;
        }

        $state = $_GET['state'] ?? '';
        $savedState = $_SESSION['oauth2state'] ?? null;
        unset($_SESSION['oauth2state']);
        if ($state === '' || $savedState === null || !hash_equals((string) $savedState, (string) $state)) {
            self::fail(__('Weryfikacja logowania nie powiodła się. Spróbuj ponownie.'));
            return;
        }
        if (empty($_GET['code'])) {
            self::fail(__('Logowanie zostało anulowane.'));
            return;
        }

        // Cała reszta (wymiana kodu na token, pobranie profilu, ORAZ obsługa w
        // bazie) w jednym try — inaczej błąd DB (np. brak tabeli
        // user_oauth_identities przed migracją 029) leciał jako surowy 500.
        // Prawdziwa przyczyna do error_log hostingu; user dostaje czytelny
        // komunikat. Sukces kończy się header()+exit w loginUser(), więc nie
        // dochodzi tu do "cichego przejścia".
        try {
            $p = OAuthProvider::make($provider);
            $token = $p->getAccessToken('authorization_code', ['code' => $_GET['code']]);
            $owner = $p->getResourceOwner($token)->toArray();

            if ($provider === 'google') {
                self::handleGoogle($owner);
            } else {
                self::handleStrava($owner);
            }
        } catch (\Throwable $e) {
            error_log(sprintf('OAuth callback (%s) failed: %s w %s:%d', $provider, $e->getMessage(), $e->getFile(), $e->getLine()));
            self::fail('Nie udało się dokończyć logowania przez ' . self::label($provider) . '. Spróbuj ponownie za chwilę.');
        }
    }

    // --- Google: e-mail zweryfikowany przez providera ---
    private static function handleGoogle(array $owner): void
    {
        $sub = (string) ($owner['sub'] ?? '');
        if ($sub === '') {
            self::fail(__('Google nie zwróciło identyfikatora konta.'));
            return;
        }

        // 1) Już podłączone konto Google → od razu login.
        $userId = OAuthIdentity::findUserId('google', $sub);
        if ($userId !== null) {
            self::loginUser($userId);
            return;
        }

        // 2) Zweryfikowany e-mail → auto-podłącz do istniejącego / utwórz nowe.
        $email = trim((string) ($owner['email'] ?? ''));
        if ($email === '' || empty($owner['email_verified'])) {
            self::fail(__('Google nie potwierdziło Twojego adresu e-mail.'));
            return;
        }
        $existing = User::findByEmail($email);
        if ($existing) {
            OAuthIdentity::link($existing->id, 'google', $sub);
            self::loginUser($existing->id);
            return;
        }
        $user = User::createFromOAuth($email, $owner['name'] ?? null, $owner['picture'] ?? null, true);
        OAuthIdentity::link($user->id, 'google', $sub);
        self::loginUser($user->id);
    }

    // --- Strava: brak e-maila → krok dopytania (stravaComplete niżej) ---
    private static function handleStrava(array $owner): void
    {
        $athleteId = (string) ($owner['id'] ?? '');
        if ($athleteId === '') {
            self::fail(__('Strava nie zwróciła identyfikatora konta.'));
            return;
        }

        // Już podłączone → login bez pytania o e-mail.
        $userId = OAuthIdentity::findUserId('strava', $athleteId);
        if ($userId !== null) {
            self::loginUser($userId);
            return;
        }

        $name = trim(((string) ($owner['firstname'] ?? '')) . ' ' . ((string) ($owner['lastname'] ?? '')));
        $_SESSION['strava_pending'] = [
            'id'     => $athleteId,
            'name'   => $name !== '' ? $name : null,
            'avatar' => $owner['profile'] ?? null,
        ];
        header('Location: ' . View::url('/auth/strava/dokoncz'));
        exit;
    }

    public static function stravaCompleteForm(): void
    {
        if (empty($_SESSION['strava_pending'])) {
            header('Location: ' . View::url('/logowanie'));
            exit;
        }
        self::renderStravaForm(null);
    }

    public static function stravaComplete(): void
    {
        $pending = $_SESSION['strava_pending'] ?? null;
        if (!$pending) {
            header('Location: ' . View::url('/logowanie'));
            exit;
        }

        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            self::renderStravaForm(__('Sesja wygasła, spróbuj ponownie.'));
            return;
        }
        $email = trim($_POST['email'] ?? '');
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            self::renderStravaForm(__('Podaj poprawny adres e-mail.'));
            return;
        }

        // E-mail wpisany ręcznie = NIEZWERYFIKOWANY. Jeśli należy do istniejącego
        // konta — NIE podpinamy (to byłoby przejęcie cudzego konta); prosimy
        // zalogować się dotychczasową metodą.
        if (User::findByEmail($email)) {
            self::renderStravaForm(__('Ten adres e-mail ma już konto. Zaloguj się nim (hasłem lub przez Google) — Stravę podepniemy do niego innym razem.'));
            return;
        }

        $user = User::createFromOAuth($email, $pending['name'] ?? null, $pending['avatar'] ?? null, false);
        OAuthIdentity::link($user->id, 'strava', $pending['id']);
        unset($_SESSION['strava_pending']);
        self::loginUser($user->id);
    }

    private static function renderStravaForm(?string $error): void
    {
        $pending = $_SESSION['strava_pending'] ?? [];
        View::render('web', 'strava-complete', [
            'title'         => __('Dokończ logowanie przez Stravę — ridemore.bike'),
            'suggestedName' => $pending['name'] ?? '',
            'error'         => $error,
            'noindex'       => true,
        ]);
    }

    private static function loginUser(int $userId): void
    {
        // PRZEPŁYW Z APLIKACJI KOŃCZY SIĘ TOKENEM, NIE SESJĄ.
        //
        // Jesteśmy w systemowej przeglądarce, więc `Auth::login()` założyłby
        // sesję DOKŁADNIE TAM, GDZIE NIE TRZEBA — użytkownik zostałby zalogowany
        // w Chrome, o co nie prosił, a aplikacja dalej nic by o tym nie wiedziała.
        // Zamiast tego wystawiamy jednorazowy token i oddajemy go apce deep
        // linkiem; sesja powstanie w appHandoff(), już w WebView.
        if (!empty($_SESSION['oauth_app'])) {
            unset($_SESSION['oauth_app']);
            header('Location: ' . self::APP_SCHEME . '://auth?token=' . urlencode(AppLoginToken::issue($userId)));
            exit;
        }

        Auth::login($userId, true);
        header('Location: ' . View::url('/'));
        exit;
    }

    private static function fail(string $message): void
    {
        View::render('web', 'login', [
            'title'   => __('Zaloguj się — ridemore.bike'),
            'error'   => $message,
            'noindex' => true,
        ]);
    }

    private static function disabled(string $provider): void
    {
        http_response_code(400);
        echo 'Logowanie przez ' . self::label($provider) . ' jest w tej chwili wyłączone.';
    }

    private static function label(string $provider): string
    {
        return $provider === 'strava' ? __('Stravę') : __('Google');
    }
}
