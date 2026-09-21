<?php
// views/web/pages/recap-form.php
// Oczekuje: $event (Models\Event), $existing (?array — findByEventAndAuthor()), $error (?string).
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

use Utils\View;
?>
<?php // WYGLĄD KRONIKI, NIE FORMULARZA LOGOWANIA (uwaga usera 2026-08-14:
      // „za wąskie i odbiega od wyglądu samej kroniki").
      //
      // Stało tu `.auth-page` — wąska kolumna zaprojektowana pod login i hasło,
      // czyli pod dwa krótkie pola. Wpis do kroniki to akapit tekstu i cztery
      // zdjęcia, więc pisało się go przez dziurkę od klucza, a po zapisie
      // lądowało w szerokiej sekcji o zupełnie innym rytmie.
      //
      // Teraz ten sam `.sec` + `.box` co „Dziennik wyjazdu", do którego wpis
      // trafia — pisze się w tej samej ramce, w której się to potem czyta. ?>
<?php require __DIR__ . '/../partials/breadcrumbs.php'; ?>
<?php $entriesSoFar = (int) ($entriesSoFar ?? 0); ?>
<div class="op-head">
    <div class="op-head__c">
        <div class="tags"><span class="tag tag--plain"><?= __('Kronika wyjazdu') ?></span></div>
        <h1><?= $existing ? __('Edytuj wpis') : ($entriesSoFar > 0 ? __('Dopisz do kroniki') : __('Dodaj wpis do kroniki')) ?></h1>
        <p class="op-head__sub"><?= htmlspecialchars($event->title) ?></p>
    </div>
</div>

<section class="sec">
    <div class="box rec-box">
    <?php // Zdanie zależy od tego, czy to pierwszy wpis, kolejny, czy poprawka
          // — bo od migr. 052 to trzy różne sytuacje, a nie jedna. ?>
    <p class="desc" style="margin-top:0;">
        <?php if ($existing): ?>
        <?= __('Poprawiasz wpis, który już jest w kronice. Zdjęcia dodane wcześniej zostają.') ?>

        <?php elseif ($entriesSoFar > 0): ?>
        <?= __('To będzie Twój {n}. wpis w tej kronice. Kolejne wpisy nie nadpisują poprzednich — kronika czyta się jak dziennik, po kolei, więc możesz pisać na bieżąco: ze zbiórki, z postoju, po powrocie.', ['n' => $entriesSoFar + 1]) ?>

        <?php else: ?>
        <?= __('Tekst, zdjęcia albo link do filmu. Możesz wracać tu w trakcie wyjazdu i dopisywać
        kolejne wpisy — kronika ustawi je po godzinie, jak dziennik.') ?>

        <?php endif; ?>
    </p>

    <?php require __DIR__ . '/../partials/form-error.php'; ?>

    <form class="rec-form" method="post" action="<?= View::url('/wydarzenia/' . $event->slug . '/relacja') ?>" enctype="multipart/form-data">
        <?= Core\Csrf::field() ?>
        <?php // Wpis należy do TURNUSU (migr. 039) — bez tego pola trafiłby do
              // kroniki domyślnego terminu, a nie tego, na którym autor był. ?>
        <input type="hidden" name="edition_id" value="<?= (int) ($editionId ?? 0) ?>">
        <?php // ID WPISU decyduje o nadpisaniu (migr. 052). Jego BRAK znaczy
              // „dopisz nowy" — dlatego pole istnieje tylko przy edycji, a nie
              // jako puste na wszelki wypadek. ?>
        <?php if ($existing): ?>
        <input type="hidden" name="recap_id" value="<?= (int) $existing['id'] ?>">
        <?php endif; ?>

        <label class="form-field-label" for="recapBody"><?= __('Co się dzieje?') ?></label>
        <textarea class="search-input" id="recapBody" name="body" rows="6" maxlength="4000"
                  placeholder="<?= htmlspecialchars(__('Jesteśmy na zbiórce, komplet ludzi. Rusza za dziesięć minut…')) ?>"><?= htmlspecialchars($existing['body'] ?? '') ?></textarea>

        <label class="form-field-label" for="recapYt"><?= __('Film z YouTube (opcjonalnie)') ?></label>
        <input class="search-input" type="text" id="recapYt" name="youtube_url"
               placeholder="https://youtube.com/watch?v=…" value="<?= htmlspecialchars($existing['youtube_url'] ?? '') ?>">

        <?php // ZDJĘCIA JUŻ DODANE — przy edycji trzeba je pokazać (uwaga usera
              // 2026-08-14: „nie ma możliwości dodania kilku zdjęć"). Pole
              // `multiple` działało od początku, ale samo puste pole pliku nie
              // mówi ani ile zdjęć już jest, ani czy nowe je zastąpią, czy
              // dołożą. Bez tej odpowiedzi nikt nie zaryzykuje drugiej próby. ?>
        <?php if (!empty($existingPhotos)): ?>
        <label class="form-field-label"><?= __('Zdjęcia w tym wpisie ({n})', ['n' => count($existingPhotos)]) ?></label>
        <div class="rec-photos">
            <?php foreach ($existingPhotos as $ph): ?>
            <span class="rec-photo" style="background-image:url('<?= htmlspecialchars(Utils\Image::src($ph, 'thumb')) ?>')"></span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <label class="form-field-label" for="recapPhotos">
            <?= !empty($existingPhotos) ? __('Dodaj kolejne zdjęcia') : __('Zdjęcia (opcjonalnie)') ?>
        </label>
        <input class="search-input" type="file" id="recapPhotos" name="photos[]" multiple
               accept="image/jpeg,image/png,image/webp">
        <?php // Limit z Utils\Upload::saveGalleryPhotos — wpisany tu wprost, bo
              // przekroczony po cichu ucina nadmiar i user nie wie dlaczego. ?>
        <p class="rec-hint" id="recapPhotosHint"><?= __('Możesz wskazać kilka plików naraz — do {n} na jedno wysłanie. JPG, PNG lub WEBP.', ['n' => (int) ($photoLimit ?? 5)]) ?></p>

        <div class="rec-actions">
            <button class="btn" type="submit"><?= $existing ? __('Zapisz zmiany') : __('Dodaj wpis') ?></button>
            <a class="btn btn-secondary" href="<?= htmlspecialchars(View::url('/kronika/' . $event->slug) . '?termin=' . (int) ($editionId ?? 0)) ?>#dziennik"><?= __('Wróć do kroniki') ?></a>
        </div>
    </form>
    </div>
</section>
<script>
// Potwierdzenie wyboru plików. Bez tego jedyną informacją zwrotną jest napis
// przeglądarki „Wybrano pliki: 3" — po polsku bywa ucięty, a i tak nie mówi
// KTÓRE. Przy wyborze ponad limit ostrzegamy OD RAZU, zamiast po cichu uciąć
// nadmiar przy zapisie.
(function () {
    var pole = document.getElementById('recapPhotos');
    var podpis = document.getElementById('recapPhotosHint');
    if (!pole || !podpis) { return; }
    var domyslny = podpis.textContent;
    var limit = <?= (int) ($photoLimit ?? 5) ?>;
    pole.addEventListener('change', function () {
        var pliki = Array.prototype.slice.call(pole.files || []);
        if (!pliki.length) { podpis.textContent = domyslny; podpis.classList.remove('is-warn'); return; }
        var nazwy = pliki.map(function (f) { return f.name; }).join(', ');
        if (pliki.length > limit) {
            podpis.textContent = __('Wybrano {n} plików, a zapisze się {limit} pierwszych: {nazwy}', { n: pliki.length, limit: limit, nazwy: nazwy });
            podpis.classList.add('is-warn');
        } else {
            podpis.textContent = __('Do wysłania ({n}): {nazwy}', { n: pliki.length, nazwy: nazwy });
            podpis.classList.remove('is-warn');
        }
    });
})();
</script>
