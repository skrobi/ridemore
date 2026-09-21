<?php
// core/Resources/OrganizerProfileFormInput.php
namespace Resources;

use Models\Organizer;
use Models\User;
use Utils\Upload;

class OrganizerProfileFormInput
{
    // Waliduje i zapisuje $_POST/$_FILES z partials/organizer-profile-form.php —
    // jedyne miejsce z tą logiką, współdzielone przez samoobsługowy formularz
    // (POST /admin/profil-rozliczeniowy/info, $targetUser === zalogowany user)
    // i edycję cudzego profilu z panelu admina (POST /admin/organizatorzy/{slug}/edytuj,
    // $targetUser === edytowany organizator), żeby nie utrzymywać dwóch kopii
    // tej samej walidacji. Zwraca komunikat błędu, albo null przy sukcesie.
    public static function apply(Organizer $organizer, User $targetUser, array $post, array $files): ?string
    {
        $type = $post['organizer_type'] ?? '';
        if (!in_array($type, ['peer', 'professional_operator'], true)) {
            return __('Wybierz typ organizatora.');
        }

        $registerNumber = trim($post['tourism_register_number'] ?? '');
        if ($type === 'professional_operator' && $registerNumber === '') {
            return __('Podaj nr wpisu w rejestrze organizatorów turystyki.');
        }

        $bioInput = trim($post['bio'] ?? '');
        if (mb_strlen($bioInput) > 2000) {
            return __('Opis jest za długi (max 2000 znaków).');
        }

        $city = trim($post['city'] ?? '');
        if (mb_strlen($city) > 120) {
            return __('Nazwa miasta jest za długa.');
        }

        $foundedYearInput = trim($post['founded_year'] ?? '');
        $foundedYear = null;
        if ($foundedYearInput !== '') {
            if (!ctype_digit($foundedYearInput) || (int) $foundedYearInput < 1900 || (int) $foundedYearInput > (int) date('Y')) {
                return __('Podaj poprawny rok założenia.');
            }
            $foundedYear = (int) $foundedYearInput;
        }

        $languages = trim($post['languages'] ?? '');
        if (mb_strlen($languages) > 200) {
            return __('Lista języków jest za długa.');
        }

        $phone = trim($post['phone'] ?? '');
        if (mb_strlen($phone) > 30) {
            return __('Numer telefonu jest za długi.');
        }

        // Publiczny, niezależny od loginu — patrz Organizer::notificationEmail().
        // Puste pole = wróć do e-maila logowania (nie błąd), walidujemy format
        // tylko gdy coś wpisano.
        $contactEmail = trim($post['contact_email'] ?? '');
        if ($contactEmail !== '' && !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
            return __('Podaj poprawny adres e-mail kontaktowy.');
        }

        // Puste pole = brak linku (nie błąd) — walidujemy format tylko gdy coś wpisano.
        $socialUrls = [];
        foreach (['website_url', 'facebook_url', 'instagram_url', 'strava_url'] as $key) {
            $value = trim($post[$key] ?? '');
            if ($value !== '' && !filter_var($value, FILTER_VALIDATE_URL)) {
                return __('Podaj poprawny adres URL (z http:// lub https://).');
            }
            $socialUrls[$key] = $value !== '' ? $value : null;
        }

        try {
            $newAvatarUrl = Upload::saveAvatar($files['avatar'] ?? []);
            if ($newAvatarUrl !== null) {
                $targetUser->updateAvatar($newAvatarUrl);
            } elseif (!empty($post['remove_avatar'])) {
                $targetUser->updateAvatar(null);
            }

            $removedHeroPhotos = array_filter((array) ($post['remove_hero_photos'] ?? []), 'is_string');
            $keptHeroPhotos = array_values(array_diff($organizer->heroPhotoUrls, $removedHeroPhotos));
            $newHeroPhotos = Upload::saveGalleryPhotos($files['hero_photos'] ?? [], 5, 'organizer-covers');
            Organizer::updateHeroPhotos($organizer->userId, array_slice(array_merge($keptHeroPhotos, $newHeroPhotos), 0, 8));
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }

        Organizer::updateType($organizer->userId, $type, $registerNumber !== '' ? $registerNumber : null);
        Organizer::updateBio($organizer->userId, $bioInput !== '' ? $bioInput : null);
        Organizer::updateProfileDetails($organizer->userId, [
            'city'        => $city !== '' ? $city : null,
            'regionCode'  => $post['region'] ?? null,
            'foundedYear' => $foundedYear,
            'languages'   => $languages !== '' ? $languages : null,
        ]);
        Organizer::updateContactInfo($organizer->userId, [
            'contactEmail' => $contactEmail !== '' ? $contactEmail : null,
            'websiteUrl'   => $socialUrls['website_url'],
            'facebookUrl'  => $socialUrls['facebook_url'],
            'instagramUrl' => $socialUrls['instagram_url'],
            'stravaUrl'    => $socialUrls['strava_url'],
        ]);
        Organizer::updateSafety($organizer->userId, [
            'routeKnown'         => !empty($post['safety_route_known']),
            'firstAidKit'        => !empty($post['safety_first_aid_kit']),
            'sweepRider'         => !empty($post['safety_sweep_rider']),
            'supportVehicle'     => !empty($post['safety_support_vehicle']),
            'firstAidCertified'  => !empty($post['safety_first_aid_certified']),
            'liabilityInsurance' => !empty($post['safety_liability_insurance']),
        ]);
        $targetUser->updatePhone($phone !== '' ? $phone : null);

        return null;
    }
}
