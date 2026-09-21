<?php
// core/Controllers/Admin/OrganizerAdminController.php
// Zarządzanie profilami organizatorów — edycja/dezaktywacja/weryfikacja z
// jednego miejsca, wzorem TaxonomyController. Weryfikacja świadomie NIE jest
// tu duplikowana — istnieje już pod publiczną trasą Controllers\OrganizerController::verify()
// (admin-only guard w środku), więc przyciski w tych widokach POSTują tam
// bezpośrednio.
namespace Controllers\Admin;

use Controllers\Support;
use Core\Csrf;
use Models\Dictionary;
use Models\Organizer;
use Models\OrganizerBillingProfile;
use Models\User;
use Resources\OrganizerProfileFormInput;
use Utils\View;

class OrganizerAdminController
{
    public static function index(): void
    {
        $filters = [
            'q'      => trim((string) ($_GET['q'] ?? '')),
            'status' => in_array($_GET['status'] ?? '', ['active', 'inactive'], true) ? $_GET['status'] : null,
        ];
        $result = Organizer::allForAdmin($filters);

        View::render('web', 'organizers-admin', [
            'title'       => 'Organizatorzy — panel admina — ridemore.bike',
            'organizers'  => $result['items'],
            'total'       => $result['total'],
            'filters'     => $filters,
            'info'        => ($_GET['info'] ?? '') === 'zapisane' ? 'Zapisano.' : null,
            'breadcrumbs' => [Support::homeCrumb(), Support::panelCrumb(), ['label' => 'Organizatorzy']],
        ]);
    }

    public static function editForm(string $slug): void
    {
        $organizer = Organizer::findBySlug($slug);
        if (!$organizer) {
            header('Location: ' . View::url('/admin/organizatorzy'));
            exit;
        }

        View::render('web', 'organizer-admin-edit', [
            'title'          => 'Edytuj: ' . $organizer->name . ' — panel admina — ridemore.bike',
            'organizer'      => $organizer,
            'regions'        => Dictionary::groupedLeaves('region'),
            'billingProfile' => OrganizerBillingProfile::findByUserId($organizer->userId),
            'error'          => null,
            'info'           => ($_GET['info'] ?? '') === 'zapisane' ? 'Zapisano.' : null,
            'breadcrumbs'    => [Support::homeCrumb(), Support::panelCrumb(), ['label' => 'Organizatorzy', 'url' => View::url('/admin/organizatorzy')], ['label' => $organizer->name]],
        ]);
    }

    public static function update(string $slug): void
    {
        $organizer = Organizer::findBySlug($slug);
        $targetUser = $organizer ? User::find($organizer->userId) : null;
        if (!$organizer || !$targetUser) {
            header('Location: ' . View::url('/admin/organizatorzy'));
            exit;
        }
        $breadcrumbs = [Support::homeCrumb(), Support::panelCrumb(), ['label' => 'Organizatorzy', 'url' => View::url('/admin/organizatorzy')], ['label' => $organizer->name]];

        $renderError = function (string $error) use ($organizer, $breadcrumbs) {
            View::render('web', 'organizer-admin-edit', [
                'title'          => 'Edytuj: ' . $organizer->name . ' — panel admina — ridemore.bike',
                'organizer'      => Organizer::findByUserId($organizer->userId),
                'regions'        => Dictionary::groupedLeaves('region'),
                'billingProfile' => OrganizerBillingProfile::findByUserId($organizer->userId),
                'error'          => $error,
                'info'           => null,
                'breadcrumbs'    => $breadcrumbs,
            ]);
        };

        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            $renderError('Sesja wygasła, spróbuj ponownie.');
            return;
        }

        // Ten sam walidator/zapis co Admin\BillingProfileController::saveInfo() —
        // patrz Resources\OrganizerProfileFormInput.
        $error = OrganizerProfileFormInput::apply($organizer, $targetUser, $_POST, $_FILES);
        if ($error !== null) {
            $renderError($error);
            return;
        }

        header('Location: ' . View::url('/admin/organizatorzy/' . $slug . '/edytuj?info=zapisane'));
        exit;
    }

    // Dane rozliczeniowe (nazwa firmy/adres/NIP/konto bankowe) — bez tego admin
    // nie miał jak sprawdzić, na jakie konto wypłacić/rozliczyć organizatora.
    // Ten sam model i ten sam brak walidacji poza CSRF co samoobsługowa
    // Admin\BillingProfileController::save() (organizator sam wypełnia te same pola).
    public static function saveBilling(string $slug): void
    {
        $organizer = Organizer::findBySlug($slug);
        if (!$organizer) {
            header('Location: ' . View::url('/admin/organizatorzy'));
            exit;
        }

        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            View::render('web', 'organizer-admin-edit', [
                'title'          => 'Edytuj: ' . $organizer->name . ' — panel admina — ridemore.bike',
                'organizer'      => $organizer,
                'regions'        => Dictionary::groupedLeaves('region'),
                'billingProfile' => OrganizerBillingProfile::findByUserId($organizer->userId),
                'billingError'   => 'Sesja wygasła, spróbuj ponownie.',
                'breadcrumbs'    => [Support::homeCrumb(), Support::panelCrumb(), ['label' => 'Organizatorzy', 'url' => View::url('/admin/organizatorzy')], ['label' => $organizer->name]],
            ]);
            return;
        }

        OrganizerBillingProfile::save($organizer->userId, [
            'legalName'         => $_POST['legal_name'] ?? '',
            'address'           => $_POST['address'] ?? '',
            'taxId'             => $_POST['tax_id'] ?? '',
            'bankAccountHolder' => $_POST['bank_account_holder'] ?? '',
            'bankAccount'       => $_POST['bank_account'] ?? '',
        ]);

        header('Location: ' . View::url('/admin/organizatorzy/' . $slug . '/edytuj?info=zapisane'));
        exit;
    }

    // Dezaktywacja/reaktywacja — patrz Organizer::setActive() (dlaczego nie
    // twardy DELETE: events.organizer_id ma ON DELETE RESTRICT, prawie każdy
    // realny organizator ma już wydarzenia). $_POST['redirect'] pozwala wywołać
    // tę samą akcję z listy i ze strony edycji bez dwóch tras.
    public static function toggleActive(string $slug): void
    {
        $organizer = Organizer::findBySlug($slug);
        $redirect = $_POST['redirect'] ?? '/admin/organizatorzy';
        // $redirect z formularza już przeszedł przez View::url() (ma base_path
        // z przodu, np. '/ridemore' na dev) — bez zdjęcia go najpierw, kolejne
        // View::url() niżej doklei go DRUGI RAZ ('/ridemore/ridemore/...').
        // Ten sam problem i to samo rozwiązanie co View::absoluteFromLocal().
        $basePath = rtrim(APP_CONFIG['base_path'] ?? '', '/');
        if ($basePath !== '' && strpos($redirect, $basePath) === 0) {
            $redirect = substr($redirect, strlen($basePath));
        }
        if (!$organizer) {
            header('Location: ' . View::url('/admin/organizatorzy'));
            exit;
        }

        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            header('Location: ' . View::url($redirect));
            exit;
        }

        Organizer::setActive($organizer->userId, !$organizer->isActive);
        header('Location: ' . View::url($redirect . (str_contains($redirect, '?') ? '&' : '?') . 'info=zapisane'));
        exit;
    }
}
