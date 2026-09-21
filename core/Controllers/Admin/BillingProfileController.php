<?php
// core/Controllers/Admin/BillingProfileController.php
namespace Controllers\Admin;

use Controllers\Support;
use Core\Auth;
use Core\Csrf;
use Models\Dictionary;
use Models\Organizer;
use Models\OrganizerBillingProfile;
use Resources\OrganizerProfileFormInput;
use Utils\View;

class BillingProfileController
{
    // Nie każdy zalogowany user ma jeszcze organizer_profiles (dopiero pierwszy
    // dodany event go tworzy, patrz Organizer::ensureProfile()) — sekcja "o
    // organizatorze" ma się wtedy w ogóle nie pokazywać, nie tworzymy profilu
    // "na siłę".
    private static function loadOrganizer(int $userId): ?Organizer
    {
        try {
            return Organizer::findByUserId($userId);
        } catch (\RuntimeException $e) {
            return null;
        }
    }

    public static function form(): void
    {
        $organizer = self::loadOrganizer(Auth::user()->id);
        View::render('web', 'billing-profile', [
            'title'        => __('Profil organizatora — ridemore.bike'),
            'profile'      => OrganizerBillingProfile::findByUserId(Auth::user()->id),
            'organizer'    => $organizer,
            'completeness' => $organizer ? Organizer::completeness($organizer) : null,
            'regions'      => Dictionary::groupedLeaves('region'),
            'typeSuccess'  => ($_GET['info'] ?? '') === 'zapisane' ? __('Zapisano.') : null,
            'breadcrumbs'  => [Support::homeCrumb(), Support::panelCrumb(), ['label' => __('Profil organizatora')]],
        ]);
    }

    public static function save(): void
    {
        $user = Auth::user();
        $breadcrumbs = [Support::homeCrumb(), Support::panelCrumb(), ['label' => __('Profil organizatora')]];

        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            $organizer = self::loadOrganizer($user->id);
            View::render('web', 'billing-profile', [
                'title'        => __('Profil organizatora — ridemore.bike'),
                'profile'      => OrganizerBillingProfile::findByUserId($user->id),
                'organizer'    => $organizer,
                'completeness' => $organizer ? Organizer::completeness($organizer) : null,
                'error'        => __('Sesja wygasła, spróbuj ponownie.'),
                'breadcrumbs'  => $breadcrumbs,
            ]);
            return;
        }

        OrganizerBillingProfile::save($user->id, [
            'legalName'         => $_POST['legal_name'] ?? '',
            'address'           => $_POST['address'] ?? '',
            'taxId'             => $_POST['tax_id'] ?? '',
            'bankAccountHolder' => $_POST['bank_account_holder'] ?? '',
            'bankAccount'       => $_POST['bank_account'] ?? '',
        ]);

        header('Location: ' . View::url('/admin/profil-rozliczeniowy'));
        exit;
    }

    // Typ organizatora + nr rejestru turystyki + bio — dawniej w "Moje konto",
    // przeniesione tutaj razem z resztą informacji, którymi zarządza organizator.
    public static function saveInfo(): void
    {
        $user = Auth::user();
        $breadcrumbs = [Support::homeCrumb(), Support::panelCrumb(), ['label' => __('Profil organizatora')]];
        $organizer = self::loadOrganizer($user->id);

        if (!$organizer) {
            // Formularz w ogóle się nie pokazuje bez organizer_profiles — bezpośredni
            // POST od kogoś, kto nigdy nie dodał eventu, to no-op.
            header('Location: ' . View::url('/admin/profil-rozliczeniowy'));
            exit;
        }

        $renderError = function (string $typeError) use ($user, $breadcrumbs) {
            $reloaded = Organizer::findByUserId($user->id);
            View::render('web', 'billing-profile', [
                'title'        => __('Profil organizatora — ridemore.bike'),
                'profile'      => OrganizerBillingProfile::findByUserId($user->id),
                'organizer'    => $reloaded,
                'completeness' => Organizer::completeness($reloaded),
                'regions'      => Dictionary::groupedLeaves('region'),
                'typeError'    => $typeError,
                'breadcrumbs'  => $breadcrumbs,
            ]);
        };

        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            $renderError(__('Sesja wygasła, spróbuj ponownie.'));
            return;
        }

        // Ten sam walidator/zapis co Admin\OrganizerAdminController::update() — patrz
        // Resources\OrganizerProfileFormInput. Tu $targetUser jest zawsze zalogowanym
        // userem (self-service), tam — edytowanym organizatorem.
        $error = OrganizerProfileFormInput::apply($organizer, $user, $_POST, $_FILES);
        if ($error !== null) {
            $renderError($error);
            return;
        }

        header('Location: ' . View::url('/admin/profil-rozliczeniowy?info=zapisane'));
        exit;
    }
}
