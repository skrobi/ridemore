<?php
// views/web/partials/footer.php
if (!defined('CORE_PATH')) { http_response_code(403); exit; }
?>
<footer>
    <div class="ftr-grid">
        <div>
            <a href="<?= Utils\View::url('/') ?>" class="logo"><span class="mark" aria-hidden="true"></span><img src="<?= Utils\View::asset('/assets/logo/android-chrome-192x192.png') ?>" alt="ridemore.bike" width="192" height="192"><span class="logo-text"><?= __('ride') ?><b><?= __('more') ?></b><?= __('.bike') ?></span></a>
            <p class="ftr-tagline"><?= __('Zbierz się. Spotkaj. Pogadaj. Przejedź.') ?><br><?= __('Społeczność rowerzystów, którzy odkrywają razem.') ?></p>
        </div>
        <div>
            <h4><?= __('Wyjazdy') ?></h4>
            <ul>
                <li><a href="<?= Utils\View::url('/wydarzenia?eventTypes[]=ustawka') ?>"><?= __('Zorganizowane wydarzenia') ?></a></li>
                <li><a href="<?= Utils\View::url('/wydarzenia?eventTypes[]=wyscig') ?>"><?= __('Wyścigi') ?></a></li>
                <li><a href="<?= Utils\View::url('/wydarzenia?eventTypes[]=wycieczka_wielodniowa') ?>"><?= __('Wycieczki wielodniowe') ?></a></li>
                <li><a href="<?= Utils\View::url('/wydarzenia?bikeTypes[]=gravel') ?>"><?= __('Wyjazdy gravelowe') ?></a></li>
                <li><a href="<?= Utils\View::url('/wydarzenia?bikeTypes[]=mtb') ?>"><?= __('Wyjazdy MTB') ?></a></li>
                <li><a href="<?= Utils\View::url('/wydarzenia?eventTypes[]=pokrec_z_kims') ?>"><?= __('Pokręcę z kimś') ?></a></li>
                <li><a href="<?= Utils\View::url('/regiony') ?>"><?= __('Regiony') ?></a></li>
            </ul>
        </div>
        <div>
            <h4><?= __('Organizatorzy') ?></h4>
            <ul>
                <li><a href="<?= Utils\View::url('/organizatorzy') ?>"><?= __('Wszyscy organizatorzy') ?></a></li>
                <li><a href="<?= Utils\View::url('/dla-organizatorow') ?>"><?= __('Dla organizatorów') ?></a></li>
                <li><a href="<?= Utils\View::url('/wydarzenia/nowe') ?>"><?= __('Dodaj wyjazd') ?></a></li>
            </ul>
        </div>
        <div>
            <h4><?= __('Serwis') ?></h4>
            <ul>
                <li><a href="<?= Utils\View::url('/jak-to-dziala') ?>"><?= __('Jak to działa') ?></a></li>
                <li><a href="<?= Utils\View::url('/puls') ?>"><?= __('Puls') ?></a></li>
                <li><a href="<?= Utils\View::url('/relacje') ?>"><?= __('Kroniki wyjazdów') ?></a></li>
                <li><a href="<?= Utils\View::url('/regulamin') ?>"><?= __('Regulamin') ?></a></li>
                <li><a href="<?= Utils\View::url('/prywatnosc') ?>"><?= __('Prywatność') ?></a></li>
            </ul>
        </div>
    </div>
    <div class="ftr-bottom">
        <span>&copy; <?= date('Y') ?> ridemore.bike<?php require __DIR__ . '/lang-switch.php'; ?></span>
        <span><?= __('Łączymy ludzi, którzy chcą jeździć razem. Za przebieg wyjazdu odpowiada jego organizator.') ?></span>
    </div>
</footer>
