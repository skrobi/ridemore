            <!-- ══ KROK: PODSUMOWANIE ══ -->
            <section class="k-step" x-show="currentStep==='podsumowanie'">
                <h1><?= __('Sprawdź i opublikuj') ?></h1>
                <p class="k-step__l"><?= __('Wszystko można jeszcze poprawić — kliknij „zmień” przy dowolnej pozycji.') ?></p>

                <?php require __DIR__ . '/../form-error.php'; ?>

                <div class="k-sum">
                    <div class="k-sum__r"><span class="k-sum__k"><?= __('Tytuł') ?></span><span class="k-sum__v" :class="!title && 'empty'" x-text="title || __('nie podano')"></span><button type="button" class="link-button" @click="goToStep('oczym')"><?= __('zmień') ?></button></div>
                    <div class="k-sum__r"><span class="k-sum__k"><?= __('Termin') ?></span><span class="k-sum__v" :class="!stages[0].date && 'empty'" x-text="stages[0].date || __('nie podano')"></span><button type="button" class="link-button" @click="goToStep('kiedy')"><?= __('zmień') ?></button></div>
                    <div class="k-sum__r"><span class="k-sum__k"><?= __('Region') ?></span><span class="k-sum__v" :class="!region && 'empty'" x-text="regionLabels[region] || __('nie podano')"></span><button type="button" class="link-button" @click="goToStep('gdzie')"><?= __('zmień') ?></button></div>
                    <template x-if="type==='ustawka' || type==='wyscig'">
                    <div class="k-sum__r"><span class="k-sum__k"><?= __('Dystans') ?></span><span class="k-sum__v" :class="!stages[0].distanceKm && 'empty'" x-text="stages[0].distanceKm ? stages[0].distanceKm + ' km' : __('nie podano')"></span><button type="button" class="link-button" @click="goToStep('trasa')"><?= __('zmień') ?></button></div>
                    </template>
                    <template x-if="type==='wycieczka_wielodniowa'">
                    <div class="k-sum__r"><span class="k-sum__k"><?= __('Liczba dni') ?></span><span class="k-sum__v" x-text="stages.length===1 ? __('1 dzień') : __('{n} dni', {n: stages.length})"></span><button type="button" class="link-button" @click="goToStep('plan')"><?= __('zmień') ?></button></div>
                    </template>
                    <template x-if="type!=='pokrec_z_kims'">
                    <div class="k-sum__r"><span class="k-sum__k"><?= __('Wpisowe') ?></span><span class="k-sum__v" x-text="isPaid && priceAmount ? priceAmount + __(' zł') : __('bez opłat')"></span><button type="button" class="link-button" @click="goToStep('pieniadze')"><?= __('zmień') ?></button></div>
                    </template>
                    <template x-if="type!=='pokrec_z_kims'">
                    <div class="k-sum__r"><span class="k-sum__k"><?= __('Organizator') ?></span><span class="k-sum__v" x-text="organizerMode==='self' ? __('Ja') : (organizerSearchQuery || __('nie wybrano'))"></span><button type="button" class="link-button" @click="goToStep('kogo')"><?= __('zmień') ?></button></div>
                    </template>
                </div>

                <div class="k-pv">
                    <p class="k-pv__h"><?= __('Tak zobaczą to inni na liście wyjazdów') ?></p>
                    <div class="k-pv__c"><div class="k-pvc">
                        <div class="k-pvc__v"><span class="k-pvc__f" :style="'--bz:' + TYPE_META[type].color"><i></i><span x-text="TYPE_META[type].name"></span></span></div>
                        <div class="k-pvc__b">
                            <p class="k-pvc__t" x-text="title || __('Tytuł wydarzenia')"></p>
                            <p class="k-pvc__d" x-text="previewMetaLine()"></p>
                            <p class="k-pvc__p" :style="!isPaid && 'color:var(--s-green)'" x-text="isPaid && priceAmount ? __('{n} zł / osoba', {n: priceAmount}) : 'Bez wpisowego'"></p>
                        </div>
                    </div></div>
                </div>

                <!-- Dopasowania — reużywa 1:1 matchSuggestions/dismissMatch()/
                     submitDismissReason() z Alpine (dziś odpalane live w kroku
                     "Zbiórka", tu odpalane raz przy wejściu na podsumowanie —
                     patrz enterSummaryStep()). -->
                <div class="k-ms" x-show="matchSuggestions.length" x-cloak>
                    <h2><?= __('Ktoś planuje to samo') ?></h2>
                    <p><?= __('Zanim opublikujesz — może wolisz dołączyć do gotowej grupy?') ?></p>
                    <template x-for="m in matchSuggestions" :key="m.url">
                        <div class="k-msc">
                            <p class="k-msc__w" x-text="m.reason"></p>
                            <p class="k-msc__t" x-text="m.title"></p>
                            <p class="k-msc__m" x-show="m.profileJustification" x-text="m.profileJustification"></p>
                            <p class="k-msc__m" x-show="m.alignLabel"><?= __('Do uzgodnienia:') ?> <b x-text="m.alignLabel"></b></p>
                            <div class="k-msc__a">
                                <a class="btn" :href="m.url" target="_blank" rel="noopener"><?= __('Zobacz i dołącz →') ?></a>
                                <button type="button" class="link-button" @click="dismissMatch(m.eventId, m.url, m.title)"><?= __('Nie, wystawiam swój') ?></button>
                            </div>
                        </div>
                    </template>
                    <div class="k-msc" x-show="matchReasonPrompt" x-cloak>
                        <span style="opacity:.8;"><?= __('Powód odrzucenia „') ?><span x-text="matchReasonPrompt && matchReasonPrompt.title"></span><?= __('” (opcjonalnie):') ?></span>
                        <div class="k-msc__a">
                            <button type="button" class="link-button" @click="submitDismissReason('zly_termin')"><?= __('zły termin') ?></button>
                            <button type="button" class="link-button" @click="submitDismissReason('za_daleko')"><?= __('za daleko') ?></button>
                            <button type="button" class="link-button" @click="submitDismissReason('nie_moje_tempo')"><?= __('nie moje tempo') ?></button>
                            <button type="button" class="link-button" @click="submitDismissReason('po_prostu_nie')"><?= __('po prostu nie') ?></button>
                            <button type="button" class="link-button" @click="skipDismissReason()"><?= __('pomiń') ?></button>
                        </div>
                    </div>
                    <p x-show="matchWideningLabel" style="font-size:13px;opacity:.7;margin-top:10px;" x-text="matchWideningLabel"></p>
                </div>

                <div style="margin-top:26px;">
                    <div class="k-note" x-show="isPaid && registrationType==='internal' && !billingComplete && organizerMode==='self'" style="margin-bottom:12px;">
                        <?= __('Bez uzupełnionego profilu rozliczeniowego wydarzenie zostanie zapisane jako szkic.') ?>

                    </div>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;">
                        <button class="btn btn-secondary" type="submit" name="status" value="draft" @click="if(!validateBeforeSubmit()) $event.preventDefault()" x-show="type!=='pokrec_z_kims' && (organizerMode==='self' || isAdmin)"><?= __('Zapisz jako szkic') ?></button>
                        <button class="btn" type="submit" name="status" value="published" @click="if(!validateBeforeSubmit()) $event.preventDefault()" x-text="type==='pokrec_z_kims' ? __('Wystaw inicjatywę') : ((organizerMode==='self' || isAdmin) ? __('Opublikuj wydarzenie →') : __('Zgłoś do weryfikacji →'))"></button>
                    </div>
                </div>
            </section>
