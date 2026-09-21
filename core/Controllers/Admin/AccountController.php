<?php
// core/Controllers/Admin/AccountController.php
namespace Controllers\Admin;

use Controllers\Support;
use Core\Auth;
use Core\Csrf;
use Models\Dictionary;
use Models\EventRsvp;
use Models\PushDevice;
use Models\User;
use Models\UserBillingProfile;
use Models\UserPreference;
use Utils\Upload;
use Utils\View;

class AccountController
{
    public static function form(): void
    {
        $user = Auth::user();
        View::render('web', 'account', [
            'title'           => __('Moje konto — ridemore.bike'),
            'user'            => $user,
            'passwordSuccess' => ($_GET['haslo'] ?? '') === 'zmienione' ? __('Hasło zostało zmienione.') : null,
            'billingProfile'  => UserBillingProfile::findByUserId($user->id),
            'billingSuccess'  => ($_GET['dane'] ?? '') === 'zapisane' ? __('Dane zapisane.') : null,
            'preferences'     => UserPreference::forUser($user->id),
            'preferencesDictOptions' => self::preferencesDictOptions(),
            'preferencesSuccess' => ($_GET['preferencje'] ?? '') === 'zapisane' ? __('Preferencje zapisane.') : null,
            'behavioralPattern' => EventRsvp::behavioralPatternForUser($user->id),
            // Etap 8 przebudowy apki (2026-08-28) — stan przełącznika push
            // w widoku, wyłącznie w APP_IS_APP (patrz views/web/pages/account.php).
            'pushEnabled'     => PushDevice::hasActiveForUser($user->id),
            // Zgody PER RODZAJ (Etap 0 programu zachęt, 2026-09-11; migr. 081).
            // Brak wiersza w `user_preferences` znaczy „wszystko włączone" —
            // rozstrzyga to model, nie widok, bo ta sama odpowiedź jest
            // potrzebna przy każdej wysyłce.
            'pushPrefs'       => \Models\NotificationGate::zgoda($user->id),
            // Automat z licznika (migr. 088) — bez niego przełączniki
            // „Przejazdy z licznika" nie mają czego wyłączać, więc się nie pokazują.
            'deviceAutoImport' => \Models\DeviceConnection::hasAutoImport($user->id),
            // WEJŚCIE PROSTO DO SEKCJI (2026-09-13) — `?sekcja=preferencje`
            // z panelu „Twoje ustawienia" na własnym profilu. Biała lista, bo
            // wartość trafia do atrybutów w widoku; nieznana = domyślny „Profil".
            'requestedSection' => in_array($_GET['sekcja'] ?? '', ['profil', 'haslo', 'faktura', 'preferencje'], true)
                ? $_GET['sekcja'] : null,
            'breadcrumbs'     => [Support::homeCrumb(), Support::panelCrumb(), ['label' => __('Moje konto')]],
        ]);
    }

    // Etap 3 (preferencje) — te same słowniki co formularz wydarzenia
    // (Support::eventFormDictOptions()), plus event_type płaski (bez
    // hierarchii, wystarczy do checkboxów "jaki typ wyjazdu mnie kusi").
    private static function preferencesDictOptions(): array
    {
        return [
            'bikeTypes'    => Dictionary::items('bike_type'),
            'paces'        => Dictionary::items('pace_group'),
            'difficulties' => Dictionary::items('difficulty_level'),
            'regions'      => Dictionary::groupedLeaves('region'),
            'eventTypes'   => Dictionary::items('event_type'),
        ];
    }

    public static function updateName(): void
    {
        $user = Auth::user();
        $breadcrumbs = [Support::homeCrumb(), Support::panelCrumb(), ['label' => __('Moje konto')]];

        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            View::render('web', 'account', [
                'title'       => __('Moje konto — ridemore.bike'),
                'user'        => $user,
                'nameError'   => __('Sesja wygasła, spróbuj ponownie.'),
                'breadcrumbs' => $breadcrumbs,
            ]);
            return;
        }

        $name = trim($_POST['name'] ?? '');
        if ($name === '' || mb_strlen($name) > 150) {
            View::render('web', 'account', [
                'title'       => __('Moje konto — ridemore.bike'),
                'user'        => $user,
                'nameError'   => $name === '' ? __('Podaj nazwę.') : __('Nazwa jest za długa (max 150 znaków).'),
                'breadcrumbs' => $breadcrumbs,
            ]);
            return;
        }

        // Awatar — to samo konto (users.avatar_url) co na profilu organizatora
        // (patrz Resources\OrganizerProfileFormInput::apply()), tylko dostępne
        // do edycji też tutaj, bo makieta moje-konto.html traktuje go jako
        // element tożsamości uczestnika, nie tylko organizatora.
        try {
            $newAvatarUrl = Upload::saveAvatar($_FILES['avatar'] ?? []);
            if ($newAvatarUrl !== null) {
                $user->updateAvatar($newAvatarUrl);
            } elseif (!empty($_POST['remove_avatar'])) {
                $user->updateAvatar(null);
            }
        } catch (\RuntimeException $e) {
            View::render('web', 'account', [
                'title'       => __('Moje konto — ridemore.bike'),
                'user'        => $user,
                'nameError'   => $e->getMessage(),
                'breadcrumbs' => $breadcrumbs,
            ]);
            return;
        }

        $user->updateName($name);

        header('Location: ' . View::url('/admin/moje-konto'));
        exit;
    }

    public static function changePassword(): void
    {
        $user = Auth::user();
        $breadcrumbs = [Support::homeCrumb(), Support::panelCrumb(), ['label' => __('Moje konto')]];

        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            View::render('web', 'account', [
                'title'         => __('Moje konto — ridemore.bike'),
                'user'          => $user,
                'passwordError' => __('Sesja wygasła, spróbuj ponownie.'),
                'breadcrumbs'   => $breadcrumbs,
            ]);
            return;
        }

        $current = (string) ($_POST['current_password'] ?? '');
        $new     = (string) ($_POST['new_password'] ?? '');
        $repeat  = (string) ($_POST['new_password_repeat'] ?? '');

        if (mb_strlen($new) < 8) {
            View::render('web', 'account', [
                'title'         => __('Moje konto — ridemore.bike'),
                'user'          => $user,
                'passwordError' => __('Nowe hasło musi mieć min. 8 znaków.'),
                'breadcrumbs'   => $breadcrumbs,
            ]);
            return;
        }
        if ($new !== $repeat) {
            View::render('web', 'account', [
                'title'         => __('Moje konto — ridemore.bike'),
                'user'          => $user,
                'passwordError' => __('Powtórzone hasło nie zgadza się z nowym.'),
                'breadcrumbs'   => $breadcrumbs,
            ]);
            return;
        }

        if (!$user->changePassword($current, $new)) {
            View::render('web', 'account', [
                'title'         => __('Moje konto — ridemore.bike'),
                'user'          => $user,
                'passwordError' => __('Obecne hasło jest nieprawidłowe.'),
                'breadcrumbs'   => $breadcrumbs,
            ]);
            return;
        }

        header('Location: ' . View::url('/admin/moje-konto?haslo=zmienione'));
        exit;
    }

    // Dane adresowe/do faktury — potrzebne przy zakupie płatnego wydarzenia
    // (sam zakup na razie poza zakresem). Dotyczy KAŻDEGO usera jako kupującego,
    // nie tylko organizatorów — inaczej niż bio/typ organizatora w Profilu
    // organizatora, ta sekcja w Moje konto jest zawsze widoczna.
    public static function updateBillingAddress(): void
    {
        $user = Auth::user();
        $breadcrumbs = [Support::homeCrumb(), Support::panelCrumb(), ['label' => __('Moje konto')]];

        $renderError = function (string $billingError) use ($user, $breadcrumbs) {
            View::render('web', 'account', [
                'title'          => __('Moje konto — ridemore.bike'),
                'user'           => $user,
                'billingProfile' => UserBillingProfile::findByUserId($user->id),
                'billingError'   => $billingError,
                'breadcrumbs'    => $breadcrumbs,
            ]);
        };

        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            $renderError(__('Sesja wygasła, spróbuj ponownie.'));
            return;
        }

        $legalName = trim($_POST['legal_name'] ?? '');
        $address   = trim($_POST['address'] ?? '');
        if ($legalName === '' || $address === '') {
            $renderError(__('Podaj imię i nazwisko (lub nazwę firmy) oraz adres.'));
            return;
        }

        UserBillingProfile::save($user->id, [
            'legalName' => $legalName,
            'address'   => $address,
            'taxId'     => $_POST['tax_id'] ?? '',
        ]);

        header('Location: ' . View::url('/admin/moje-konto?dane=zapisane'));
        exit;
    }

    // Etap 3 (preferencje) — jedna akcja zapisuje CAŁY formularz preferencji
    // naraz (operacyjne + aspiracyjne + liczbowe), formularz wysyła zawsze
    // pełny stan zaznaczeń (patrz Models\UserPreference::save()).
    public static function updatePreferences(): void
    {
        $user = Auth::user();
        $breadcrumbs = [Support::homeCrumb(), Support::panelCrumb(), ['label' => __('Moje konto')]];

        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            View::render('web', 'account', [
                'title'                  => __('Moje konto — ridemore.bike'),
                'user'                   => $user,
                'preferences'            => UserPreference::forUser($user->id),
                'preferencesDictOptions' => self::preferencesDictOptions(),
                'preferencesError'       => __('Sesja wygasła, spróbuj ponownie.'),
                'breadcrumbs'            => $breadcrumbs,
            ]);
            return;
        }

        $arr = fn(string $key) => array_values(array_filter((array) ($_POST[$key] ?? []), 'is_string'));
        $intOrNull = fn($v) => ($v !== null && $v !== '' && is_numeric($v)) ? (int) $v : null;

        UserPreference::save($user->id, [
            'bikeTypes'              => $arr('bike_types'),
            'paces'                  => $arr('paces'),
            'difficulties'           => $arr('difficulties'),
            'regions'                => $arr('regions'),
            'aspirationalRegions'    => $arr('aspirational_regions'),
            'aspirationalEventTypes' => $arr('aspirational_event_types'),
            'distanceMinKm'          => $intOrNull($_POST['distance_min_km'] ?? null),
            'distanceMaxKm'          => $intOrNull($_POST['distance_max_km'] ?? null),
            'elevationMaxM'          => $intOrNull($_POST['elevation_max_m'] ?? null),
            'groupSizePref'          => $_POST['group_size_pref'] ?? 'any',
            'notifyMatches'          => !empty($_POST['notify_matches']),
        ]);

        // Prywatność (migr. 037). Pole w formularzu jest ODWROTNOŚCIĄ kolumny:
        // checkbox mówi „ukryj mnie", kolumna trzyma „jestem widoczny" — nazwa
        // pozytywna czyta się lepiej w SQL, nazwa negatywna lepiej w UI.
        // Wysyłane tym samym formularzem co reszta preferencji, więc brak
        // zaznaczenia = widoczny (zwykłe zachowanie checkboxa).
        User::updateRosterVisibility($user->id, empty($_POST['hide_from_rosters']));

        header('Location: ' . View::url('/admin/moje-konto?preferencje=zapisane'));
        exit;
    }

    /**
     * KASOWANIE KONTA W APCE (Etap 9, 2026-08-29) — patrz `Models\User::requestDeletion()`
     * dla mechaniki (blokada + oznaczenie do ręcznego dokasowania). Tu:
     * walidacja formularza i wylogowanie po sukcesie — dokończona akcja nie
     * może zostawić żywej sesji na koncie, które właśnie samo się zablokowało.
     */
    public static function requestDeletion(): void
    {
        $user = Auth::user();
        $breadcrumbs = [Support::homeCrumb(), Support::panelCrumb(), ['label' => __('Moje konto')]];

        $fail = static function (string $message) use ($user, $breadcrumbs): void {
            View::render('web', 'account', [
                'title'         => __('Moje konto — ridemore.bike'),
                'user'          => $user,
                'deletionError' => $message,
                'breadcrumbs'   => $breadcrumbs,
            ]);
        };

        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            $fail(__('Sesja wygasła, spróbuj ponownie.'));
            return;
        }
        if (empty($_POST['confirm'])) {
            $fail(__('Zaznacz potwierdzenie, że rozumiesz skutki usunięcia konta.'));
            return;
        }

        $currentPassword = $_POST['current_password'] ?? null;
        if (!$user->requestDeletion($currentPassword !== null ? (string) $currentPassword : null)) {
            $fail($user->passwordHash !== null
                ? __('Podane hasło jest nieprawidłowe.')
                : __('Nie udało się złożyć prośby o usunięcie konta — spróbuj ponownie.'));
            return;
        }

        // Konto właśnie samo się zablokowało — Auth::user() od następnego
        // żądania i tak by je wylogowało (blokada wylogowuje), ale robimy to
        // TERAZ, w tym samym żądaniu, żeby przekierowanie nie wróciło na
        // stronę wymagającą sesji, której już nie ma.
        Auth::logout();
        header('Location: ' . View::url('/logowanie') . '?info=konto-do-usuniecia');
        exit;
    }
}
