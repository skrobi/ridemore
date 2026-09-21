            <!-- ══ KROK: KIEDY ══ -->
            <section class="k-step" x-show="currentStep==='kiedy'">
                <h1 x-text="type==='pokrec_z_kims' ? 'Kiedy masz czas?' : 'Kiedy jedziecie?'"></h1>
                <p class="k-step__l" x-text="type==='pokrec_z_kims' ? __('Podaj zakres dni — dokładny termin dogadacie później.') : __('Dzień i godzina zbiórki. Godzinę można doprecyzować później.')"></p>
                <div class="k-fields">

                    <template x-if="type==='pokrec_z_kims'">
                    <div class="k-fl">
                        <span><?= __('Jak to wygląda?') ?></span>
                        <div class="k-tiles k-tiles--2">
                            <button type="button" class="k-tile" :aria-pressed="!dateIsFlexible" @click="dateIsFlexible=false"><b><?= __('Mam konkretny termin') ?></b></button>
                            <button type="button" class="k-tile" :aria-pressed="dateIsFlexible" @click="dateIsFlexible=true; startTime=''"><b><?= __('Jestem elastyczny') ?></b></button>
                        </div>
                    </div>
                    </template>
                    <input type="hidden" name="date_is_flexible" :value="dateIsFlexible ? 1 : 0">

                    <template x-if="type==='pokrec_z_kims'">
                    <div class="k-two">
                        <!-- BEZ required (usunięte 2026-08-09) — pole żyje na kroku,
                             który bywa niewidoczny (x-show) gdy user jest na innym
                             kroku przy kliknięciu "Opublikuj"; przeglądarka wtedy
                             MILCZY (nie może pokazać dymka na ukrytym polu) zamiast
                             zablokować submit z komunikatem. Walidacja przeniesiona
                             do validateBeforeSubmit() w script.php (jak tytuł/warianty). -->
                        <label class="k-fl"><span x-text="dateIsFlexible ? __('Od kiedy') : __('Data rozpoczęcia')"></span><input type="date" name="stages[0][date]" x-model="stages[0].date"></label>
                        <label class="k-fl"><span x-text="dateIsFlexible ? __('Do kiedy') : __('Data zakończenia')"></span><input type="date" name="end_date" x-model="endDate"></label>
                    </div>
                    </template>
                    <p class="k-note" x-show="type==='pokrec_z_kims'" x-text="dateIsFlexible ? __('To okno dostępności — konkretny dzień ustalicie w komentarzach.') : __('Podany zakres to rzeczywista długość wyjazdu.')"></p>

                    <template x-if="type!=='pokrec_z_kims'">
                    <div class="k-two">
                        <!-- BEZ required — patrz komentarz przy analogicznym polu
                             pokrec_z_kims wyżej w tym pliku. -->
                        <label class="k-fl"><span><?= __('Data wydarzenia') ?></span><input type="date" name="stages[0][date]" x-model="stages[0].date"></label>
                        <label class="k-fl"><span><?= __('Godzina zbiórki') ?></span><input type="time" name="start_time" x-model="startTime"></label>
                    </div>
                    </template>
                    <p class="k-note" x-show="type==='wycieczka_wielodniowa'"><?= __('Datę zakończenia policzymy sami — z liczby dni, które dodasz w planie.') ?></p>

                    <div class="k-opt" x-show="type!=='pokrec_z_kims'">
                        <button type="button" class="k-opt__b" @click="turnusOpen=!turnusOpen"><span x-text="(turnusOpen?'− ':'+ ') + __('Ten sam wyjazd w kilku terminach')"></span><em><?= __('możesz pominąć') ?></em></button>
                        <div class="k-opt__c" x-show="turnusOpen" x-cloak>
                            <p class="k-note"><?= __('Każdy termin dostanie własny limit miejsc i własną listę zapisanych. Trasę wpisujesz raz.') ?></p>
                            <template x-for="(d, di) in additionalDates" :key="di">
                                <div class="k-lr">
                                    <input type="date" :name="'additional_edition_dates['+di+']'" x-model="additionalDates[di]">
                                    <button type="button" @click="removeEditionDate(di)" aria-label="<?= htmlspecialchars(__('Usuń termin')) ?>">×</button>
                                </div>
                            </template>
                            <button type="button" class="k-addr" @click="addEditionDate()"><?= __('+ Dodaj termin') ?></button>
                        </div>
                    </div>
                </div>
            </section>
