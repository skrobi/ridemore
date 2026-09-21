<?php
// views/web/partials/match-suggestions.php
// Etap 2/3 (dopasowania) — widget "Pokręć z kimś", od 2026-07-31 w layoucie
// "hero + sidebar" (przebudowa wg makiety szablony/index-light.html):
// najsilniejszy match (items[0]) dostaje dużą kartę .mc, reszta trafia do
// bocznej listy .mini w .mside. Zastępuje dawny wariant "liczba jako bohater"
// (siatka N równorzędnych kart .match-card) — grupa najsilniejsza i tak
// przyciągała najwięcej uwagi, więc pokazanie jej wprost jako hero jest
// szczersze niż udawanie, że wszystkie dopasowania są sobie równe.
// Widget ma ZAWSZE wyglądać tak samo (user: "zawsze jak mam zgłoszenie") —
// gdy brak spersonalizowanych dopasowań, wywołujący (patrz
// Controllers\HomeController::looseRideFallbackCards()) wypełnia $matchCards
// realnymi, nieaspersonalizowanymi ogłoszeniami "Pokręcę z kimś" zamiast
// zostawiać tablicę pustą, więc ten partial nie musi znać żadnego "stanu
// pustego" — jeśli $matchCards jest puste, po prostu nie ma czego pokazać
// (np. strona wydarzenia, gdzie brak kandydatów dla TEGO eventu nie ma
// sensownego fallbacku).
// Współdzielony między stroną wydarzenia (pod dyskusją) i stroną główną (na
// górze) — jeden komponent Alpine (matchWidget()), różne dane wejściowe.
//
// Oczekuje: $matchCards (Resources\MatchCardResource::fromMatches() albo
// ::fromLooseRideFallback()), $matchWideningLabel (?string), $matchHeading
// (string).
if (!defined('CORE_PATH')) { http_response_code(403); exit; }
if (empty($matchCards)) return;
$matchWideningLabel = $matchWideningLabel ?? null;
$matchHeading = $matchHeading ?? __('Może Cię zainteresować');
// Tryb awaryjny (brak personalizacji) — patrz HomeController::index().
$matchIsFallback = $matchIsFallback ?? false;
?>
<div x-show="hero || reasonPrompt" x-cloak
     x-data='matchWidget(<?= json_encode($matchCards, JSON_UNESCAPED_UNICODE) ?>, <?= json_encode(Core\Auth::check()) ?>, <?= json_encode(Core\Csrf::token()) ?>, <?= json_encode(Utils\View::url('/api/matches/dismiss')) ?>, <?= json_encode($matchWideningLabel) ?>)'>
    <div class="match">
        <div class="match__hd">
            <div>
                <p class="eyebrow"><span class="blaze" style="--bz:var(--s-green);"></span><?= __('Pokręć z kimś') ?><?= $matchIsFallback ? '' : ' · ' . __('dopasowanie') ?></p>
                <h2><?= htmlspecialchars($matchHeading) ?></h2>
            </div>
            <?php // W trybie awaryjnym NIE obiecujemy dopasowania — te ogłoszenia
                  // nie są filtrowane ani po geografii, ani po profilu. ?>
            <?php if ($matchIsFallback): ?>
            <p><?= __('Otwarte ogłoszenia z całego kraju — ktoś szuka towarzystwa na wspólny wyjazd.
                Gdy zbierzemy więcej Twoich wyjazdów, zaczniemy podpowiadać trafniej.') ?></p>
            <?php else: ?>
            <p><?= __('Sprawdzamy rejon, okno terminowe i typ roweru, a potem kierujemy Cię do największej grupy,
                która już się zbiera — zamiast dziesiątek wyników, jedna propozycja i jedna rzecz do ustalenia.') ?></p>
            <?php endif; ?>
        </div>

        <template x-if="hero">
            <div class="match__grid">
                <article class="mc">
                    <p class="mc__why" x-text="hero.eyebrow"></p>
                    <h3 class="mc__t" x-text="hero.title"></h3>
                    <p class="mc__data" x-text="hero.dateLabel + (hero.metaLabel ? ' · ' + hero.metaLabel : '')"></p>
                    <p class="mc__start" x-show="hero.startLabel">
                        <?= Utils\Icon::render('pin') ?>
                        <span x-text="hero.startLabel"></span>
                    </p>
                    <?php // Peleton (2026-08-12) — NAD uzasadnieniem profilowym:
                          // „jedzie z nimi Michał" jest mocniejszym powodem niż
                          // „bo jeździsz na takim rowerze", więc czyta się pierwsze. ?>
                    <p class="mc__justification" x-show="hero.pelotonLabel" style="font-weight:600;color:var(--accent-dark);">
                        <?= Utils\Icon::render('check') ?>
                        <span x-text="hero.pelotonLabel"></span>
                    </p>
                    <p class="mc__justification" x-show="hero.profileJustification">
                        <?= Utils\Icon::render('check') ?>
                        <span x-text="hero.profileJustification"></span>
                    </p>
                    <div class="mc__proof" x-show="hero.groupSize > 0">
                        <b x-text="hero.groupSize"></b>
                        <span x-text="hero.groupSizeLabel"></span>
                    </div>
                    <div class="mc__todo" x-show="hero.alignLabel">
                        <span><?= __('Do uzgodnienia:') ?> <b x-text="hero.alignLabel"></b></span>
                    </div>
                    <div class="mc__act">
                        <a class="btn" :href="hero.url"><?= __('Zobacz wyjazd &rarr;') ?></a>
                        <button type="button" class="link-button" @click="dismiss(hero)"><?= __('Nie teraz') ?></button>
                    </div>
                </article>

                <aside class="mside" x-show="rest.length">
                    <div class="mside__box">
                        <h3><?= __('Pozostałe dopasowania') ?></h3>
                        <template x-for="m in rest" :key="m.eventId">
                            <a class="mini" :href="m.url">
                                <span>
                                    <b x-text="m.title"></b>
                                    <small x-text="m.dateLabel + (m.metaLabel ? ' · ' + m.metaLabel : '')"></small>
                                    <small x-show="m.startLabel" x-text="'Start: ' + m.startLabel"></small>
                                </span>
                                <span class="n" x-text="m.groupSize"></span>
                            </a>
                        </template>
                    </div>
                </aside>
            </div>
        </template>

        <!-- Stopień 2 odrzucenia (opcjonalny) — OSOBNY blok, nie wewnątrz template x-if:
             hero znika z `items` w tym samym momencie co ten prompt się pojawia. -->
        <div class="match-card-reason-prompt" x-show="reasonPrompt" x-cloak>
            <span style="opacity:.8;"><?= __('Powód odrzucenia „') ?><span x-text="reasonPrompt && reasonPrompt.title"></span><?= __('" (opcjonalnie):') ?></span>
            <button type="button" class="link-button" @click="submitReason('zly_termin')"><?= __('zły termin') ?></button>
            <button type="button" class="link-button" @click="submitReason('za_daleko')"><?= __('za daleko') ?></button>
            <button type="button" class="link-button" @click="submitReason('nie_moje_tempo')"><?= __('nie moje tempo') ?></button>
            <button type="button" class="link-button" @click="submitReason('po_prostu_nie')"><?= __('po prostu nie') ?></button>
            <button type="button" class="link-button" @click="reasonPrompt = null"><?= __('pomiń') ?></button>
        </div>
        <div x-show="matchWideningLabel" class="match-widget-widening" x-text="matchWideningLabel"></div>
    </div>
</div>
<script>
function matchWidget(initialItems, isLoggedIn, csrfToken, dismissUrl, wideningLabel) {
    return {
        items: initialItems,
        reasonPrompt: null,
        matchWideningLabel: wideningLabel,
        get hero() { return this.items[0] ?? null; },
        get rest() { return this.items.slice(1); },
        async dismiss(m) {
            this.items = this.items.filter(i => i.eventId !== m.eventId);
            if (!isLoggedIn) return;
            await this.post(m.eventId, null);
            this.reasonPrompt = { eventId: m.eventId, title: m.title };
        },
        async submitReason(reason) {
            if (!this.reasonPrompt) return;
            await this.post(this.reasonPrompt.eventId, reason);
            this.reasonPrompt = null;
        },
        async post(eventId, reason) {
            const body = new URLSearchParams({ csrf_token: csrfToken, eventId: String(eventId) });
            if (reason) body.set('reason', reason);
            try {
                await fetch(dismissUrl, { method: 'POST', body });
            } catch (e) {
                // Odrzucenie lokalne już się stało — brak zapisu serwerowego
                // najwyżej pokaże tę samą sugestię ponownie przy kolejnej wizycie.
            }
        },
    };
}
</script>
