<?php
// views/web/partials/organizer-profile-form.php
// Oczekuje: $organizer (Models\Organizer), $regions (Dictionary::groupedLeaves('region')),
// $formAction (string, dokąd POSTować), $typeError (?string), $typeSuccess (?string).
// Współdzielone przez samoobsługowy formularz (billing-profile.php, POSTuje do
// /admin/profil-rozliczeniowy/info) i edycję z panelu admina (organizer-admin-edit.php,
// POSTuje do /admin/organizatorzy/{slug}/edytuj) — patrz Resources\OrganizerProfileFormInput,
// które przetwarza oba w jednym miejscu. Zmiana pól tutaj obejmuje od razu oba widoki.
//
// $sectioned (bool, domyślnie false) — gdy true, cztery grupy pól (Zdjęcia/
// Kim jesteście/Lokalizacja i kontakt/Bezpieczeństwo) owijają się w
// .section-panel[data-section=...] pod pionową szynę billing-profile.php
// (patrz assets/js/ui.js showSection()). Panel admina (organizer-admin-edit.php)
// zostawia $sectioned=false — te same cztery grupy renderują się jedna pod
// drugą, bez zakładek, bo ta strona ich nie ma.
// $extraZdjeciaBoxHtml (?string, surowe HTML) — dodatkowa karta w sekcji
// Zdjęcia (samoobsługowy formularz dokłada tu "Kompletność profilu";
// panel admina tego nie potrzebuje, zostaje null).
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

use Core\Csrf;
use Utils\Icon;
use Utils\View;

$regions = $regions ?? [];
$sectioned = $sectioned ?? false;
$extraZdjeciaBoxHtml = $extraZdjeciaBoxHtml ?? null;
// Walidacja w OrganizerProfileFormInput::apply() nie mówi, z KTÓREJ z czterech
// grup pochodzi błąd — najczęstsze przyczyny (typ/nr rejestru/długość bio)
// leżą w "Kim jesteście", więc to sensowny domyślny cel po nieudanym submicie.
$activeSection = !empty($typeError) ? 'dane' : 'zdjecia';
$openSection = function (string $key) use ($sectioned, $activeSection) {
    if ($sectioned) {
        echo '<div class="section-panel' . ($activeSection === $key ? ' active' : '') . '" data-section="' . $key . '">';
    }
};
$closeSection = function () use ($sectioned) {
    if ($sectioned) echo '</div>';
};
?>
<?php if (!empty($typeError)): ?>
<p class="form-error"><?= htmlspecialchars($typeError) ?></p>
<?php endif; ?>
<?php if (!empty($typeSuccess)): ?>
<p class="form-success"><?= htmlspecialchars($typeSuccess) ?></p>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" action="<?= htmlspecialchars($formAction) ?>">
    <?= Csrf::field() ?>

    <?php $openSection('zdjecia'); ?>
    <div class="box">
        <h3><?= __('Awatar') ?> <span class="vis vis--pub"><?= __('widoczny publicznie') ?></span></h3>
        <p class="desc" style="font-size:14px;margin:4px 0 16px;"><?= __('Pokazujemy go przy każdym Twoim wyjeździe na liście i u góry profilu.') ?></p>
        <div class="avatar-edit-row">
            <div id="avatarPreviewWrap">
            <?php if (!empty($organizer->avatarUrl)): ?>
            <img class="avatar-edit-preview" id="avatarPreviewImg" src="<?= htmlspecialchars(View::url($organizer->avatarUrl)) ?>" alt="<?= htmlspecialchars($organizer->name) ?>" width="72" height="72">
            <?php else: ?>
            <div class="avatar-edit-preview avatar-edit-preview-initials" id="avatarPreviewInitials"><?= htmlspecialchars(mb_substr($organizer->name, 0, 2)) ?></div>
            <?php endif; ?>
            </div>
            <div>
                <label class="upload-box" id="avatarUploadBox" style="padding:10px 16px;">
                    <?= Icon::render('camera') ?>
                    <span id="avatarUploadLabel"><?= __('Zmień awatar') ?></span>
                    <input type="file" name="avatar" id="avatarInput" accept="image/jpeg,image/png,image/webp" style="display:none;">
                </label>
                <?php if (!empty($organizer->avatarUrl)): ?>
                <label class="filter-opt"><input type="checkbox" name="remove_avatar" value="1"> <?= __('Usuń obecny awatar') ?></label>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="box">
        <h3><?= __('Zdjęcia w tle') ?> <span class="vis vis--pub"><?= __('widoczne publicznie') ?></span></h3>
        <p class="desc" style="font-size:14px;margin:4px 0 16px;"><?= __('Do pięciu. Pierwsze służy za okładkę profilu. Nie masz zdjęć? Pokażemy okładki Twoich ostatnich wyjazdów.') ?></p>
        <?php if (!empty($organizer->heroPhotoUrls)): ?>
        <div class="hero-photo-grid">
            <?php foreach ($organizer->heroPhotoUrls as $url): ?>
            <label class="hero-photo-thumb">
                <img src="<?= htmlspecialchars(View::url($url)) ?>" alt="<?= htmlspecialchars(__('Zdjęcie w tle profilu')) ?>" loading="lazy">
                <span class="hero-photo-remove"><input type="checkbox" name="remove_hero_photos[]" value="<?= htmlspecialchars($url) ?>"> <?= __('Usuń') ?></span>
            </label>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div class="hero-photo-grid" id="heroNewPreview"></div>
        <label class="upload-box" id="heroUploadBox">
            <?= Icon::render('camera') ?>
            <span id="heroUploadLabel"><?= __('Dodaj zdjęcia w tle (do 5 naraz, JPEG/PNG/WebP)') ?></span>
            <input type="file" name="hero_photos[]" id="heroInput" accept="image/jpeg,image/png,image/webp" multiple style="display:none;">
        </label>
        <div class="stg-save">
            <button class="btn btn--sm" type="submit"><?= __('Zapisz zdjęcia') ?></button>
        </div>
    </div>
    <?php if ($extraZdjeciaBoxHtml !== null) echo $extraZdjeciaBoxHtml; ?>
    <?php $closeSection(); ?>

    <?php $openSection('dane'); ?>
    <div class="box">
        <h3><?= __('Rodzaj organizatora') ?> <span class="vis vis--pub"><?= __('widoczny publicznie') ?></span></h3>
        <p class="desc" style="font-size:14px;margin:4px 0 16px;"><?= __('Uczestnik widzi to na profilu i przy każdym wyjeździe — decyduje o tym, czego może oczekiwać.') ?></p>

        <div class="tiles" style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
            <label class="stg-tile"><input type="radio" name="organizer_type" value="peer" <?= $organizer->organizerType !== 'professional_operator' ? 'checked' : '' ?>>
                <b><?= __('Organizator społecznościowy') ?></b>
                <small><?= __('Wspólne jazdy z pasji, uczestnicy jadą na własną odpowiedzialność.') ?></small></label>
            <label class="stg-tile"><input type="radio" name="organizer_type" value="professional_operator" <?= $organizer->organizerType === 'professional_operator' ? 'checked' : '' ?>>
                <b><?= __('Operator turystyczny') ?></b>
                <small><?= __('Działalność gospodarcza z wpisem do rejestru organizatorów turystyki.') ?></small></label>
        </div>

        <div id="regBox" <?= $organizer->organizerType === 'professional_operator' ? '' : 'hidden' ?> style="margin-top:16px;">
            <div class="form-field">
                <label for="reg"><?= __('Numer wpisu w rejestrze organizatorów turystyki') ?></label>
                <input id="reg" class="search-input" type="text" name="tourism_register_number" placeholder="<?= htmlspecialchars(__('np. TO/2024/00123')) ?>" value="<?= htmlspecialchars($organizer->tourismRegisterNumber ?? '') ?>">
                <p class="hint"><?= __('Pokażemy go na profilu — uczestnik może go sprawdzić w Centralnej Ewidencji.') ?></p>
            </div>
            <p class="callout"><b><?= __('Numer weryfikujemy ręcznie.') ?></b> <?= __('Do czasu potwierdzenia profil jest widoczny jako niezweryfikowany.') ?></p>
        </div>
    </div>

    <div class="box">
        <h3><?= __('O nas') ?> <span class="vis vis--pub"><?= __('widoczne publicznie') ?></span></h3>
        <p class="desc" style="font-size:14px;margin:4px 0 16px;"><?= __('Najważniejsze pole na całej stronie. Bez opinii i historii to jedyne, co ktoś o Was wie.') ?></p>
        <div class="form-field">
            <label for="bio"><?= __('Opis') ?></label>
            <textarea id="bio" class="search-input" name="bio" rows="4" maxlength="2000" placeholder="<?= htmlspecialchars(__('Kto jeździ, w jakim stylu, od kiedy. Napisz tak, jak powiedziałbyś to komuś na parkingu przed startem.')) ?>"><?= htmlspecialchars($organizer->bio ?? '') ?></textarea>
            <p class="stg-cnt" id="bioCnt"><?= mb_strlen($organizer->bio ?? '') ?> / 2000</p>
        </div>
        <div class="stg-save">
            <button class="btn btn--sm" type="submit"><?= __('Zapisz dane organizatora') ?></button>
        </div>
    </div>
    <?php $closeSection(); ?>

    <?php $openSection('kontakt'); ?>
    <div class="box">
        <h3><?= __('Gdzie działacie') ?> <span class="vis vis--pub"><?= __('widoczne publicznie') ?></span></h3>
        <div class="two-col">
            <div class="form-field">
                <label for="city"><?= __('Miasto') ?></label>
                <input id="city" class="search-input" type="text" name="city" maxlength="120" value="<?= htmlspecialchars($organizer->city ?? '') ?>">
            </div>
            <div class="form-field">
                <label for="region"><?= __('Główny region') ?></label>
                <select id="region" class="search-input" name="region">
                    <option value=""><?= __('— wybierz —') ?></option>
                    <?php foreach ($regions as $group): ?>
                    <?php if ($group['label'] === null): ?>
                    <?php foreach ($group['items'] as $r): ?>
                    <option value="<?= htmlspecialchars($r['code']) ?>" <?= ($organizer->regionCode ?? null) === $r['code'] ? 'selected' : '' ?>><?= htmlspecialchars($r['name']) ?></option>
                    <?php endforeach; ?>
                    <?php else: ?>
                    <optgroup label="<?= htmlspecialchars($group['label']) ?>">
                        <?php foreach ($group['items'] as $r): ?>
                        <option value="<?= htmlspecialchars($r['code']) ?>" <?= ($organizer->regionCode ?? null) === $r['code'] ? 'selected' : '' ?>><?= htmlspecialchars($r['name']) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="two-col">
            <div class="form-field">
                <label for="founded_year"><?= __('Rok założenia') ?></label>
                <input id="founded_year" class="search-input" type="number" name="founded_year" placeholder="<?= htmlspecialchars(__('np. 2019')) ?>" min="1900" max="<?= date('Y') ?>" value="<?= htmlspecialchars((string) ($organizer->foundedYear ?? '')) ?>">
                <p class="hint"><?= __('Pokazujemy jako „na rynku X lat" — mocny sygnał dla kogoś, kto Was nie zna.') ?></p>
            </div>
            <div class="form-field">
                <label for="languages"><?= __('Języki') ?></label>
                <input id="languages" class="search-input" type="text" name="languages" placeholder="<?= htmlspecialchars(__('np. polski, angielski')) ?>" maxlength="200" value="<?= htmlspecialchars($organizer->languages ?? '') ?>">
            </div>
        </div>
        <div class="stg-save">
            <button class="btn btn--sm" type="submit"><?= __('Zapisz lokalizację') ?></button>
        </div>
    </div>

    <div class="box">
        <h3><?= __('Kontakt') ?> <span class="vis vis--pub"><?= __('widoczny publicznie') ?></span></h3>
        <div class="two-col">
            <div class="form-field">
                <label for="phone"><?= __('Telefon') ?></label>
                <input id="phone" class="search-input" type="text" name="phone" maxlength="30" value="<?= htmlspecialchars($organizer->phone ?? '') ?>">
            </div>
            <div class="form-field">
                <label for="contact_email"><?= __('E-mail kontaktowy') ?></label>
                <input id="contact_email" class="search-input" type="email" name="contact_email" placeholder="<?= htmlspecialchars(__('kontakt@…')) ?>" maxlength="190" value="<?= htmlspecialchars($organizer->contactEmail ?? '') ?>">
                <p class="hint"><?= __('Niezależny od e-maila logowania. Tu też trafią powiadomienia o zapisach, wpłatach i pytaniach.') ?></p>
            </div>
        </div>
        <div class="two-col">
            <div class="form-field">
                <label for="website_url"><?= __('Strona internetowa') ?></label>
                <input id="website_url" class="search-input" type="url" name="website_url" placeholder="https://…" value="<?= htmlspecialchars($organizer->websiteUrl ?? '') ?>">
            </div>
            <div class="form-field">
                <label for="facebook_url">Facebook</label>
                <input id="facebook_url" class="search-input" type="url" name="facebook_url" placeholder="https://facebook.com/…" value="<?= htmlspecialchars($organizer->facebookUrl ?? '') ?>">
            </div>
        </div>
        <div class="two-col">
            <div class="form-field">
                <label for="instagram_url">Instagram</label>
                <input id="instagram_url" class="search-input" type="url" name="instagram_url" placeholder="https://instagram.com/…" value="<?= htmlspecialchars($organizer->instagramUrl ?? '') ?>">
            </div>
            <div class="form-field">
                <label for="strava_url"><?= __('Klub Strava') ?></label>
                <input id="strava_url" class="search-input" type="url" name="strava_url" placeholder="https://strava.com/clubs/…" value="<?= htmlspecialchars($organizer->stravaUrl ?? '') ?>">
            </div>
        </div>
        <div class="stg-save">
            <button class="btn btn--sm" type="submit"><?= __('Zapisz kontakt') ?></button>
        </div>
    </div>
    <?php $closeSection(); ?>

    <?php $openSection('bezpieczenstwo'); ?>
    <div class="box">
        <h3><?= __('Deklaracje') ?> <span class="vis vis--pub"><?= __('widoczne publicznie') ?></span></h3>
        <p class="desc" style="font-size:14px;margin:4px 0 16px;"><?= __('Na profilu pokazujemy wyłącznie zaznaczone pozycje. Niezaznaczonych') ?> <b><?= __('nie przedstawiamy jako braku') ?></b> <?= __('— piszemy tylko „nie zadeklarowano".') ?></p>

        <label class="stg-sw"><input type="checkbox" name="safety_route_known" value="1" <?= ($organizer->safetyRouteKnown ?? false) ? 'checked' : '' ?>>
            <span><b><?= __('Prowadzący zna trasę') ?></b><small><?= __('Wyjazd prowadzi ktoś, kto jechał ją wcześniej.') ?></small></span></label>
        <label class="stg-sw"><input type="checkbox" name="safety_first_aid_kit" value="1" <?= ($organizer->safetyFirstAidKit ?? false) ? 'checked' : '' ?>>
            <span><b><?= __('Apteczka w grupie') ?></b><small><?= __('Ktoś wiezie apteczkę na całej trasie.') ?></small></span></label>
        <label class="stg-sw"><input type="checkbox" name="safety_sweep_rider" value="1" <?= ($organizer->safetySweepRider ?? false) ? 'checked' : '' ?>>
            <span><b><?= __('Zamykający grupę') ?></b><small><?= __('Ktoś jedzie na końcu i pilnuje, żeby nikt nie został sam.') ?></small></span></label>
        <label class="stg-sw"><input type="checkbox" name="safety_support_vehicle" value="1" <?= ($organizer->safetySupportVehicle ?? false) ? 'checked' : '' ?>>
            <span><b><?= __('Samochód wsparcia') ?></b><small><?= __('Jedzie za grupą, zabiera bagaże i rowery po awarii.') ?></small></span></label>
        <label class="stg-sw"><input type="checkbox" name="safety_first_aid_certified" value="1" <?= ($organizer->safetyFirstAidCertified ?? false) ? 'checked' : '' ?>>
            <span><b><?= __('Kurs pierwszej pomocy') ?></b><small><?= __('Ktoś w ekipie ma aktualne przeszkolenie.') ?></small></span></label>
        <label class="stg-sw"><input type="checkbox" name="safety_liability_insurance" value="1" <?= ($organizer->safetyLiabilityInsurance ?? false) ? 'checked' : '' ?>>
            <span><b><?= __('Ubezpieczenie OC organizatora') ?></b><small><?= __('Polisa obejmująca prowadzenie wyjazdów.') ?></small></span></label>

        <div class="stg-save">
            <button class="btn btn--sm" type="submit"><?= __('Zapisz deklaracje') ?></button>
        </div>
    </div>
    <?php $closeSection(); ?>
</form>
