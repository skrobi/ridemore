            <!-- ══ KROK: KOGO DOTYCZY ══ (pominięte w makiecie, dopisane —
                 patrz plan: pełna sekcja "Kogo dotyczy" z dzisiejszego
                 event-form.php, 1:1, tylko w nowym opakowaniu .k-step) -->
            <section class="k-step" x-show="currentStep==='kogo'">
                <template x-if="type!=='pokrec_z_kims'">
                <div>
                    <h1><?= __('Kogo dotyczy to wydarzenie?') ?></h1>
                    <p class="k-step__l"><?= __('Możesz zgłosić dla siebie, albo w czyimś imieniu.') ?></p>
                    <div class="k-fields">
                        <input type="hidden" name="organizer_mode" x-model="organizerMode">
                        <input type="hidden" name="organizer_id" :value="organizerId">
                        <input type="hidden" name="new_organizer_name" :value="organizerSearchQuery">

                        <?php if ($isLoggedIn): ?>
                        <label class="k-tile" style="width:fit-content;">
                            <input type="checkbox" :checked="organizerMode==='self'" @change="organizerMode = $event.target.checked ? 'self' : 'existing'">
                            <b><?= __('To ja jestem organizatorem') ?></b>
                        </label>
                        <?php endif; ?>

                        <div x-show="organizerMode!=='self'">
                            <div class="k-fl organizer-search" x-show="!organizerConfirmed" @click.outside="showOrganizerResults=false">
                                <span><?= __('Organizator') ?></span>
                                <input type="text" x-model="organizerSearchQuery" @input.debounce.300ms="searchOrganizers()" @focus="showOrganizerResults=true" placeholder="<?= htmlspecialchars(__('Zacznij pisać nazwę lub e-mail organizatora...')) ?>">
                                <div class="organizer-search-results" x-show="showOrganizerResults && organizerSearchQuery.trim().length >= 2">
                                    <template x-for="r in organizerSearchResults" :key="r.id">
                                        <div class="organizer-search-result" @click="selectOrganizer(r)" x-text="r.label"></div>
                                    </template>
                                    <div class="organizer-search-result organizer-search-new" @click="selectNewOrganizer()">
                                        <?= __('+ Dodaj „') ?><span x-text="organizerSearchQuery"></span><?= __('” jako nowego organizatora') ?>

                                    </div>
                                </div>
                            </div>

                            <div class="organizer-selected" x-show="organizerConfirmed">
                                <span x-show="organizerMode==='existing'"><?= __('Wybrany organizator:') ?> <b x-text="organizerSearchQuery"></b></span>
                                <span x-show="organizerMode==='new'"><?= __('Nowy organizator (założymy dla niego profil):') ?> <b x-text="'„' + organizerSearchQuery + '”'"></b></span>
                                <button type="button" class="link-button" @click="changeOrganizer()"><?= __('Zmień') ?></button>
                            </div>

                            <div x-show="organizerMode==='new' && organizerConfirmed" class="k-fl">
                                <span><?= __('E-mail organizatora') ?> <small><?= __('do kontaktu w sprawie przejęcia profilu') ?></small></span>
                                <input type="email" name="new_organizer_email" x-model="newOrganizerEmail" placeholder="<?= htmlspecialchars(__('kontakt@...')) ?>">
                            </div>

                            <div class="k-note"><?= __('To zgłoszenie trafi do weryfikacji, zanim będzie publicznie widoczne.') ?></div>
                            <div class="k-two" x-show="!isLoggedIn">
                                <label class="k-fl"><span><?= __('Twoje imię') ?> <small><?= __('do kontaktu') ?></small></span><input type="text" name="submitter_name" x-model="submitterName"></label>
                                <!-- BEZ required — sprawdzane już w validateBeforeSubmit()
                                     (script.php, emailOk(submitterEmail)), patrz komentarz
                                     w step-kiedy.php. -->
                                <label class="k-fl"><span><?= __('Twój e-mail') ?></span><input type="email" name="submitter_email" x-model="submitterEmail"></label>
                            </div>
                        </div>
                    </div>
                </div>
                </template>

                <template x-if="type==='pokrec_z_kims' && !isLoggedIn">
                <div>
                    <h1><?= __('Twój kontakt') ?></h1>
                    <p class="k-step__l"><?= __('Bez konta zgłoszenie trafi do krótkiej weryfikacji, zanim będzie publicznie widoczne — o publikacji poinformujemy Cię e-mailem.') ?></p>
                    <div class="k-fields">
                        <div class="k-two">
                            <label class="k-fl"><span><?= __('Twoje imię') ?> <small><?= __('opcjonalnie') ?></small></span><input type="text" name="submitter_name" x-model="submitterName"></label>
                            <!-- BEZ required — patrz komentarz przy analogicznym polu wyżej w tym pliku. -->
                            <label class="k-fl"><span><?= __('Twój e-mail') ?></span><input type="email" name="submitter_email" x-model="submitterEmail"></label>
                        </div>
                    </div>
                </div>
                </template>
            </section>
