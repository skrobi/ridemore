            <!-- ══ KROK: DLA KOGO ══ -->
            <section class="k-step" x-show="currentStep==='dlakogo'">
                <h1 x-text="type==='pokrec_z_kims' ? __('Na czym jedziesz?') : __('Dla kogo to jest?')"></h1>
                <p class="k-step__l" x-text="type==='pokrec_z_kims' ? __('To pomaga dobrać towarzystwo. Możesz pominąć.') : __('Dzięki temu trafi do właściwych osób. Wszystko można pominąć.')"></p>
                <div class="k-fields">
                    <div class="k-fl"><span x-text="type==='pokrec_z_kims' ? __('Twój rower') : 'Jakie rowery?'"></span>
                        <div class="k-tiles k-tiles--2">
                            <?php foreach ($dictOptions['bikeTypes'] as $opt): ?>
                            <label class="k-tile"><input type="checkbox" name="bike_types[]" value="<?= htmlspecialchars($opt['code']) ?>" x-model="bikeTypes"><?= htmlspecialchars($opt['name']) ?></label>
                            <?php endforeach; ?>
                        </div>
                        <small style="color:var(--ink-mute);"><?= __('Nic nie zaznaczasz = jadą wszyscy.') ?></small>
                    </div>

                    <?php // DYSTANS I GPX NAD TEMPEM (uwaga usera 2026-08-13).
                          // Przy „Pokręcę z kimś" pytanie brzmi najpierw DOKĄD
                          // i JAK DALEKO, a dopiero potem JAK SZYBKO — tempo bez
                          // dystansu nic nie znaczy („spokojnie" na 30 km i na
                          // 200 km to dwie różne propozycje). Oba pola wyszły też
                          // ze zwijanego bloku „możesz pominąć": to nie jest
                          // szczegół, tylko sedno ogłoszenia. ?>
                    <template x-if="type==='pokrec_z_kims'">
                    <div class="k-two">
                        <label class="k-fl"><span><?= __('Orientacyjny dystans (km)') ?></span><input type="number" step="0.1" name="stages[0][distance_km]" x-model="stages[0].distanceKm" placeholder="<?= htmlspecialchars(__('np. 60')) ?>"></label>
                        <label class="k-fl"><span><?= __('Plik GPX') ?></span>
                            <input type="hidden" name="stages[0][gpx_token]" x-model="stages[0].gpxToken">
                            <input type="hidden" name="stages[0][existing_gpx_url]" :value="stages[0].gpxUrl || ''">
                            <label class="k-big" :class="(stages[0].gpxToken || stages[0].gpxUrl) && 'done'" style="margin-top:7px;">
                                <?= Utils\Icon::render('upload') ?>
                                <span x-show="!gpxUploading[0]" x-text="(stages[0].gpxToken || stages[0].gpxUrl) ? __('trasa.gpx — wczytano') : 'Wgraj plik GPX'"></span>
                                <span x-show="gpxUploading[0]"><?= __('Analizuję trasę… (do 30 s)') ?></span>
                                <input type="file" accept=".gpx" style="display:none;" @change="uploadGpx(0, $event)">
                            </label>
                        </label>
                    </div>
                    </template>

                    <div class="k-fl"><span><?= __('Tempo') ?></span>
                        <div class="k-tiles k-tiles--3">
                            <?php foreach ($dictOptions['paces'] as $opt): ?>
                            <button type="button" class="k-tile" :aria-pressed="pace==='<?= htmlspecialchars($opt['code']) ?>'" @click="pace='<?= htmlspecialchars($opt['code']) ?>'"><b><?= htmlspecialchars($opt['name']) ?></b></button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="k-opt">
                        <button type="button" class="k-opt__b" @click="whoMoreOpen=!whoMoreOpen"><span x-text="(whoMoreOpen?'− ':'+ ') + (type==='pokrec_z_kims' ? __('Trudność, limit osób, sprzęt, orientacyjna trasa') : __('Trudność, liczba miejsc, sprzęt'))"></span><em><?= __('możesz pominąć') ?></em></button>
                        <div class="k-opt__c" x-show="whoMoreOpen" x-cloak>
                            <div class="k-fl"><span><?= __('Trudność') ?></span>
                                <div class="k-tiles k-tiles--3">
                                    <?php foreach ($dictOptions['difficulties'] as $opt): ?>
                                    <button type="button" class="k-tile" :aria-pressed="difficulty==='<?= htmlspecialchars($opt['code']) ?>'" @click="difficulty='<?= htmlspecialchars($opt['code']) ?>'"><b><?= htmlspecialchars($opt['name']) ?></b></button>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <label class="k-tile" style="width:fit-content;"><input type="checkbox" x-model="limitParticipants"><b><?= __('Ogranicz liczbę uczestników') ?></b></label>
                            <div class="k-two" x-show="limitParticipants">
                                <label class="k-fl"><span><?= __('Najmniej osób') ?></span><input type="number" name="min_participants" x-model="minParticipants" min="1" placeholder="<?= htmlspecialchars(__('np. 4')) ?>"></label>
                                <label class="k-fl"><span><?= __('Najwięcej osób') ?></span><input type="number" name="max_participants" x-model="maxParticipants" min="1" placeholder="<?= htmlspecialchars(__('np. 20')) ?>"></label>
                            </div>

                            <div class="k-fl"><span><?= __('Co trzeba mieć ze sobą') ?></span>
                                <template x-for="(item, i) in equipment" :key="i">
                                    <div class="k-lr">
                                        <div class="organizer-search" style="flex:1;" @click.outside="if(equipmentSearchFocusIndex===i) closeEquipmentResults()">
                                            <input type="text" :name="'equipment['+i+'][name]'" x-model="item.name" @input.debounce.300ms="searchEquipment(i)" @focus="searchEquipment(i)" placeholder="<?= htmlspecialchars(__('np. kask, oświetlenie')) ?>">
                                            <div class="organizer-search-results" x-show="equipmentSearchFocusIndex===i && equipmentSearchResults.length">
                                                <template x-for="r in equipmentSearchResults" :key="r.code">
                                                    <div class="organizer-search-result" @click="selectEquipment(i, r.name)" x-text="r.name"></div>
                                                </template>
                                            </div>
                                        </div>
                                        <label style="display:flex;align-items:center;gap:6px;font-size:14px;white-space:nowrap;"><input type="checkbox" :name="'equipment['+i+'][mandatory]'" value="1" x-model="item.mandatory" style="width:auto;min-height:auto;"><?= __('obowiązkowy') ?></label>
                                        <button type="button" @click="equipment.splice(i,1)" aria-label="<?= htmlspecialchars(__('Usuń')) ?>">×</button>
                                    </div>
                                </template>
                                <button type="button" class="k-addr" @click="equipment.push({name:'',mandatory:true})"><?= __('+ Dodaj pozycję') ?></button>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
