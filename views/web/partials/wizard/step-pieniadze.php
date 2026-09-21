            <!-- ══ KROK: PIENIĄDZE ══ -->
            <section class="k-step" x-show="currentStep==='pieniadze'">
                <h1><?= __('Wpisowe i zapisy') ?></h1>
                <p class="k-step__l"><?= __('Pieniądze idą bezpośrednio do Ciebie — nie bierzemy prowizji.') ?></p>
                <div class="k-fields">
                    <div class="k-fl"><span><?= __('Czy jest wpisowe?') ?></span>
                        <div class="k-tiles k-tiles--2">
                            <button type="button" class="k-tile" :aria-pressed="!isPaid" @click="isPaid=false"><b><?= __('Nie, bez opłat') ?></b></button>
                            <button type="button" class="k-tile" :aria-pressed="isPaid" @click="isPaid=true"><b><?= __('Tak, jest wpisowe') ?></b></button>
                        </div>
                        <small x-show="variantsEnabled" x-cloak style="color:var(--ink-mute);"><?= __('Warianty mogą być darmowe albo płatne — jeśli płatne, cenę każdej pętli podasz niżej.') ?></small>
                    </div>

                    <div x-show="isPaid" class="k-fields" style="margin-top:0;">
                        <!-- Bez wariantów: jedna kwota. Z wariantami: cena per pętla. -->
                        <div class="k-two" x-show="!variantsEnabled">
                            <label class="k-fl"><span><?= __('Kwota') ?> <small><?= __('od osoby') ?></small></span><input type="number" step="0.01" name="price_amount" x-model="priceAmount" placeholder="620"></label>
                            <label class="k-fl"><span><?= __('Zaliczka') ?> <small><?= __('opcjonalnie') ?></small></span><input type="number" step="0.01" name="deposit" x-model="deposit" placeholder="200"></label>
                        </div>
                        <div class="k-fl" x-show="variantsEnabled"><span><?= __('Cena każdego wariantu') ?> <small><?= __('od osoby') ?></small></span>
                            <template x-for="(variant, i) in variants" :key="i">
                                <div class="k-lr">
                                    <span style="flex:1;min-width:0;" x-text="variant.name || __('Wariant {n}', {n: i+1})"></span>
                                    <input type="number" step="0.01" style="max-width:130px;" :name="'variants['+i+'][price_amount]'" x-model="variant.priceAmount" placeholder="<?= htmlspecialchars(__('cena')) ?>">
                                    <input type="number" step="0.01" style="max-width:130px;" :name="'variants['+i+'][deposit]'" x-model="variant.deposit" placeholder="<?= htmlspecialchars(__('zaliczka')) ?>">
                                </div>
                            </template>
                        </div>
                        <div class="k-two">
                            <label class="k-fl"><span><?= __('Waluta') ?></span>
                                <select name="currency" x-model="currency">
                                    <?php foreach ($dictOptions['currencies'] as $opt): ?>
                                    <option value="<?= htmlspecialchars($opt['code']) ?>"><?= htmlspecialchars($opt['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="k-fl"><span><?= __('Cena za') ?></span>
                                <select name="price_unit" x-model="priceUnit">
                                    <?php foreach ($dictOptions['priceUnits'] as $opt): ?>
                                    <option value="<?= htmlspecialchars($opt['code']) ?>"><?= htmlspecialchars($opt['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        </div>

                        <div class="k-fl"><span><?= __('Co jest w cenie?') ?></span>
                            <template x-for="(item, i) in priceItems" :key="i">
                            <template x-if="item.isIncluded">
                            <div class="k-lr">
                                <div class="organizer-search" style="flex:1;" @click.outside="if(categorySearchFocusIndex===i) closeCategoryResults()">
                                    <input type="text" :name="'price_items['+i+'][category]'" x-model="item.category" @input.debounce.300ms="searchCategories(i)" @focus="searchCategories(i)" placeholder="<?= htmlspecialchars(__('np. Nocleg')) ?>">
                                    <div class="organizer-search-results" x-show="categorySearchFocusIndex===i && categorySearchResults.length">
                                        <template x-for="r in categorySearchResults" :key="r.code">
                                            <div class="organizer-search-result" @click="selectCategory(i, r.name)" x-text="r.name"></div>
                                        </template>
                                    </div>
                                </div>
                                <input type="hidden" :name="'price_items['+i+'][is_included]'" value="1">
                                <button type="button" @click="priceItems.splice(i,1)" aria-label="<?= htmlspecialchars(__('Usuń')) ?>">×</button>
                            </div>
                            </template>
                            </template>
                            <button type="button" class="k-addr" @click="priceItems.push({category:'',isIncluded:true})"><?= __('+ Dodaj pozycję') ?></button>
                        </div>

                        <!-- Zawsze widoczne obok "Co jest w cenie?" (nie w zwiniętej
                             sekcji płatności) — makieta chowała to pod "Terminy
                             płatności i zwrotów", ale to dwie różne rzeczy: jedna
                             odpowiada "co dostajesz", druga "kiedy płacisz". Ta sama
                             para W CENIE/NIE W CENIE, oba zawsze widoczne, co w
                             dzisiejszym event-form.php — user zgłosił, że po
                             wypełnieniu "Co jest w cenie" nie mógł znaleźć drugiej
                             listy, bo była schowana gdzie indziej. -->
                        <div class="k-fl"><span><?= __('Czego nie ma w cenie') ?></span>
                            <template x-for="(item, i) in priceItems" :key="i">
                            <template x-if="!item.isIncluded">
                            <div class="k-lr">
                                <div class="organizer-search" style="flex:1;" @click.outside="if(categorySearchFocusIndex===i) closeCategoryResults()">
                                    <input type="text" :name="'price_items['+i+'][category]'" x-model="item.category" @input.debounce.300ms="searchCategories(i)" @focus="searchCategories(i)" placeholder="<?= htmlspecialchars(__('np. dojazd')) ?>">
                                    <div class="organizer-search-results" x-show="categorySearchFocusIndex===i && categorySearchResults.length">
                                        <template x-for="r in categorySearchResults" :key="r.code">
                                            <div class="organizer-search-result" @click="selectCategory(i, r.name)" x-text="r.name"></div>
                                        </template>
                                    </div>
                                </div>
                                <button type="button" @click="priceItems.splice(i,1)" aria-label="<?= htmlspecialchars(__('Usuń')) ?>">×</button>
                            </div>
                            </template>
                            </template>
                            <button type="button" class="k-addr" @click="priceItems.push({category:'',isIncluded:false})"><?= __('+ Dodaj pozycję') ?></button>
                        </div>

                        <div class="k-opt">
                            <button type="button" class="k-opt__b" @click="payMoreOpen=!payMoreOpen"><span x-text="(payMoreOpen?'− ':'+ ') + __('Terminy płatności i zwrotów')"></span><em><?= __('możesz pominąć') ?></em></button>
                            <div class="k-opt__c" x-show="payMoreOpen" x-cloak>
                                <div class="k-two">
                                    <label class="k-fl"><span><?= __('Dopłata do') ?> <small><?= __('dni przed') ?></small></span><input type="number" name="payment_deadline_days" x-model="paymentDeadlineDays" placeholder="30"></label>
                                    <label class="k-fl"><span><?= __('Anulowanie do') ?> <small><?= __('dni przed') ?></small></span><input type="number" name="cancellation_deadline_days" x-model="cancellationDeadlineDays" placeholder="<?= htmlspecialchars(__('np. 14')) ?>"></label>
                                </div>
                                <!-- Dopisanie pominięte przez makietę: polityka
                                     anulowania (dzisiejszy formularz ją ma). -->
                                <label class="k-fl"><span><?= __('Polityka anulowania') ?> <small><?= __('opcjonalnie') ?></small></span><textarea name="cancellation_policy" x-model="cancellationPolicy" placeholder="<?= htmlspecialchars(__('np. zwrot zaliczki do 14 dni przed wyjazdem')) ?>"></textarea></label>
                            </div>
                        </div>

                        <div class="k-warn" x-show="registrationType==='internal' && !billingComplete">
                            <b><?= __('Zanim opublikujesz płatny wyjazd, uzupełnij dane rozliczeniowe.') ?></b>
                            <?= __('Potrzebujemy ich, żeby uczestnicy dostali fakturę. Do tego czasu zapiszemy wszystko jako szkic — nic nie przepadnie.') ?>

                            <p style="margin-top:13px;"><a class="btn btn-secondary" href="<?= Utils\View::url('/admin/profil-rozliczeniowy') ?>"><?= __('Uzupełnij dane →') ?></a></p>
                        </div>
                        <p class="k-note" x-show="registrationType==='external'"><?= __('Płatność odbywa się na zewnętrznej stronie — dane rozliczeniowe w profilu nie są wymagane.') ?></p>
                    </div>

                    <div class="k-opt">
                        <button type="button" class="k-opt__b" @click="regBoxOpen=!regBoxOpen; if(!regBoxOpen) registrationType='internal';"><span x-text="(regBoxOpen?'− ':'+ ') + __('Zapisy prowadzę gdzie indziej')"></span><em><?= __('możesz pominąć') ?></em></button>
                        <div class="k-opt__c" x-show="regBoxOpen" x-cloak>
                            <?php // registrationType = external, gdy podano JAKĄKOLWIEK formę
                                  // (link/telefon/e-mail) — spójne z EventFormInput. ?>
                            <label class="k-fl"><span><?= __('Link do zapisów') ?></span>
                                <input type="text" name="external_url" x-model="externalUrl" @input="registrationType = (externalUrl||externalPhone||externalEmail) ? 'external' : 'internal'" placeholder="<?= htmlspecialchars(__('np. wydarzenie na Facebooku albo formularz')) ?>">
                            </label>
                            <label class="k-fl"><span><?= __('Telefon do zapisów') ?> <small><?= __('opcjonalnie') ?></small></span>
                                <input type="tel" name="external_phone" x-model="externalPhone" @input="registrationType = (externalUrl||externalPhone||externalEmail) ? 'external' : 'internal'" placeholder="<?= htmlspecialchars(__('np. 500 600 700')) ?>">
                            </label>
                            <label class="k-fl"><span><?= __('E-mail do zapisów') ?> <small><?= __('opcjonalnie') ?></small></span>
                                <input type="email" name="external_email" x-model="externalEmail" @input="registrationType = (externalUrl||externalPhone||externalEmail) ? 'external' : 'internal'" placeholder="<?= htmlspecialchars(__('np. zapisy@klub.pl')) ?>">
                            </label>
                            <small style="color:var(--ink-mute);"><?= __('Uczestnicy zobaczą podane formy zapisu — możesz podać jedną lub kilka.') ?></small>
                        </div>
                    </div>
                </div>
            </section>
