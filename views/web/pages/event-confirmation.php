<?php
// views/web/pages/event-confirmation.php
// Ekran po zapisaniu luźnego wyjazdu (Zadanie 7 specyfikacji Etapu 1) —
// jedyny mechanizm zasięgu, dopóki nie ma modułu dopasowań (Etap 2): bez
// tego zgłaszający widziałby tylko surową stronę eventu i nie wracał.
// Oczekuje w scope: $eventData (Resources\EventResource::fromModel()).
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

use Utils\Format;
use Utils\View;

$e = $eventData;
$edition = $e['editions'][0] ?? null;
$isPending = $e['statusCode'] === 'oczekuje_weryfikacji';

$dateLabel = '';
if ($edition) {
    $dateLabel = Format::dateRangeP($edition['startDate'], $edition['endDate']);
    if ($edition['dateIsFlexible']) {
        $dateLabel = __('termin do uzgodnienia (') . $dateLabel . ')';
    } elseif ($edition['startTime']) {
        $dateLabel .= ', godz. ' . substr($edition['startTime'], 0, 5);
    }
}

$bikeLabel = !empty($e['bikeTypes']) ? implode('/', $e['bikeTypes']) : null;
$startLabel = !empty($e['meetingPointAddress']) ? 'start ' . $e['meetingPointAddress'] : null;
$parts = array_values(array_filter([$e['regionLabel'] ?? null, $dateLabel ?: null, $bikeLabel, $startLabel]));

// Parametry śledzenia źródła (Zadanie 7.3) — pod pomiar skuteczności tego
// kanału (patrz "Jak zmierzymy, że się udało" w specyfikacji Etapu 1).
// LINK BEZ PARAMETRÓW KAMPANII (decyzja 2026-08-13, zgłoszenie usera:
// „aż zabija wizerunek tego"). Tekst do skopiowania na Facebooka kończył się
// adresem z trzema parametrami utm_*, dłuższymi niż sam adres wydarzenia.
//
// Świadomie NIE budujemy własnego skracacza (/r/{kod} + tabela + kontroler
// przekierowań): pomiar RĘCZNEGO wklejenia linku i tak jest niepewny (część
// serwisów obcina parametry, część ludzi przepisuje adres), więc cena — nowa
// tabela i nowa trasa publiczna — jest wyraźnie wyższa niż to, co się zyskuje.
// Ruch z takich wklejek i tak widać w statystykach jako wejścia bezpośrednie
// na konkretny slug. Gdyby pomiar kampanii stał się kiedyś ważny, wraca tu
// skracacz, a nie doklejone utm_*.
$shareLink = View::absoluteUrl('/events/' . $e['slug']);
$shareText = __('🚴 Szukam towarzystwa: {co}. Kto chętny? Szczegóły: {link}', ['co' => implode(', ', $parts), 'link' => $shareLink]);
?>
<div class="confirmation-screen">
    <div class="confirmation-check">✓</div>
    <h1 class="display" style="font-size:28px;"><?= __('Zgłoszenie przyjęte') ?></h1>

    <?php if ($isPending): ?>
    <p class="desc"><?= __('Twoje zgłoszenie trafiło do krótkiej weryfikacji, zanim będzie publicznie widoczne. O publikacji poinformujemy Cię e-mailem.') ?></p>
    <?php else: ?>
    <p class="desc"><?= __('Twój termin jest już widoczny na liście wydarzeń. Powiadomimy Cię e-mailem, gdy ktoś będzie chętny dołączyć.') ?></p>
    <?php endif; ?>

    <div class="confirmation-share">
        <div class="confirmation-share-title"><?= __('Gotowy tekst do wklejenia na Facebooku') ?></div>
        <div class="confirmation-share-text" id="shareText"><?= htmlspecialchars($shareText) ?></div>
        <div class="confirmation-share-actions">
            <button type="button" class="btn" id="shareBtn"><?= __('Udostępnij') ?></button>
            <button type="button" class="btn btn-secondary" id="copyBtn"><?= __('Kopiuj tekst') ?></button>
        </div>
        <div class="hint" id="copyHint" style="display:none;"><?= __('Skopiowano do schowka.') ?></div>
    </div>

    <p class="hint confirmation-why"><?= __('Chętni klikną odnośnik w treści i napiszą do Ciebie tutaj, na ridemore.bike — dzięki temu nikt nie zginie w prywatnych wiadomościach na Facebooku.') ?></p>

    <a class="link-button" href="<?= htmlspecialchars(View::url('/events/' . $e['slug'])) ?>"><?= __('Zobacz stronę wydarzenia →') ?></a>
</div>

<script>
(function () {
    var shareText = <?= json_encode($shareText, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var shareBtn = document.getElementById('shareBtn');
    var copyBtn = document.getElementById('copyBtn');
    var copyHint = document.getElementById('copyHint');

    // Natywne API udostępniania — głównie mobilne przeglądarki; na desktopie
    // (najczęściej brak navigator.share) degraduje się do kopiowania, tak samo
    // jak przycisk "Kopiuj tekst" obok.
    async function copyText() {
        try {
            await navigator.clipboard.writeText(shareText);
        } catch (e) {
            var ta = document.createElement('textarea');
            ta.value = shareText;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
        }
        copyHint.style.display = 'block';
        setTimeout(function () { copyHint.style.display = 'none'; }, 2500);
    }

    shareBtn.addEventListener('click', async function () {
        if (navigator.share) {
            try {
                await navigator.share({ text: shareText });
                return;
            } catch (e) {
                // Anulowane przez usera albo brak wsparcia mimo obecności API —
                // degradacja do kopiowania zamiast zostawić przycisk bez efektu.
            }
        }
        copyText();
    });
    copyBtn.addEventListener('click', copyText);
})();
</script>
