            <!-- ══ KROK: PLAN (wielodniowa) ══ -->
            <section class="k-step" x-show="currentStep==='plan'">
                <h1><?= __('Jak wygląda plan?') ?></h1>
                <p class="k-step__l"><?= __('Dzień po dniu. Meta jednego dnia staje się startem następnego — nie musisz jej wpisywać dwa razy.') ?></p>
                <div class="k-fields">
                    <template x-for="(stage, i) in stages" :key="i">
                        <div class="k-day" :style="'--dc:' + DAY_COLORS[i % DAY_COLORS.length]">
                            <div class="k-day__h">
                                <span class="k-day__n" x-text="__('Dzień {n}', {n: i+1})"></span>
                                <button type="button" class="k-day__x" x-show="stages.length>1" @click="removeStage(i)" aria-label="<?= htmlspecialchars(__('Usuń dzień')) ?>">×</button>
                            </div>
                            <div class="k-fields">
                                <label class="k-big" :class="(stage.gpxToken || stage.gpxUrl) && 'done'">
                                    <?= Utils\Icon::render('upload') ?>
                                    <span x-show="!gpxUploading[i]">
                                        <span x-text="(stage.gpxToken || stage.gpxUrl) ? __('trasa.gpx — wczytano') : 'Wgraj GPX tego dnia'"></span>
                                        <small x-show="!(stage.gpxToken || stage.gpxUrl)"><?= __('albo wpisz dystans niżej') ?></small>
                                    </span>
                                    <span x-show="gpxUploading[i]"><?= __('Analizuję trasę i nawierzchnię… (do 30 s)') ?></span>
                                    <input type="file" accept=".gpx" style="display:none;" @change="uploadGpx(i, $event)">
                                </label>
                                <input type="hidden" :name="'stages['+i+'][gpx_token]'" x-model="stage.gpxToken">
                                <input type="hidden" :name="'stages['+i+'][existing_gpx_url]'" :value="stage.gpxUrl || ''">

                                <div class="k-two">
                                    <label class="k-fl"><span><?= __('Dystans (km)') ?></span><input type="number" step="0.1" :name="'stages['+i+'][distance_km]'" x-model="stage.distanceKm" placeholder="95"></label>
                                    <label class="k-fl"><span><?= __('Przewyższenie (m)') ?></span><input type="number" :name="'stages['+i+'][elevation_m]'" x-model="stage.elevationM" placeholder="1200"></label>
                                </div>

                                <?php // Ukryte pola % + wykryty/ręczny podział nawierzchni — wspólne z krokiem TRASA (tu bez legendy, jak dotąd).
                                      $sRef = 'stage'; $sIdx = 'i'; $sLegend = false; require __DIR__ . '/gpx-surface.php'; ?>

                                <input type="hidden" :name="'stages['+i+'][start_lat]'" :value="stage.startLat">
                                <input type="hidden" :name="'stages['+i+'][start_lng]'" :value="stage.startLng">
                                <input type="hidden" :name="'stages['+i+'][end_lat]'" :value="stage.endLat">
                                <input type="hidden" :name="'stages['+i+'][end_lng]'" :value="stage.endLng">
                                <div class="k-two">
                                    <label class="k-fl"><span><?= __('Skąd startujecie?') ?> <small><?= __('opcjonalnie') ?></small></span>
                                        <input type="text" :name="'stages['+i+'][start_label]'" x-model="stage.startLabel" placeholder="<?= htmlspecialchars(__('np. Cisna')) ?>">
                                    </label>
                                    <label class="k-fl"><span><?= __('Dokąd dojeżdżacie?') ?> <small><?= __('opcjonalnie') ?></small></span>
                                        <input type="text" :name="'stages['+i+'][end_label]'" x-model="stage.endLabel" placeholder="<?= htmlspecialchars(__('np. Ustrzyki Górne')) ?>">
                                    </label>
                                </div>
                                <button type="button" class="k-addr" @click="openMap('stage', i, 'end')">
                                    <span x-text="stage.endLat ? (stage.endLat.toFixed(5)+', '+stage.endLng.toFixed(5)+__(' — popraw metę na mapie')) : __('Ustaw metę dnia na mapie')"></span>
                                </button>

                                <!-- Dopisania pominięte przez makietę: typ noclegu
                                     (dziś obok nazwy), posiłki w cenie, notatki. -->
                                <div class="k-two">
                                    <label class="k-fl"><span><?= __('Typ noclegu') ?> <small><?= __('opcjonalnie') ?></small></span>
                                        <select :name="'stages['+i+'][accommodation_type]'" x-model="stage.accommodationType">
                                            <option value=""><?= __('— brak —') ?></option>
                                            <?php foreach ($dictOptions['accommodationTypes'] as $opt): ?>
                                            <option value="<?= htmlspecialchars($opt['code']) ?>"><?= htmlspecialchars($opt['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <label class="k-fl"><span><?= __('Nocleg') ?> <small><?= __('nazwa / adres') ?></small></span><input type="text" :name="'stages['+i+'][accommodation_name]'" x-model="stage.accommodationName" placeholder="<?= htmlspecialchars(__('np. Schronisko, pokoje 2–4 os.')) ?>"></label>
                                </div>
                                <div class="k-fl"><span><?= __('Posiłki w cenie') ?></span>
                                    <div class="k-tiles k-tiles--2">
                                        <?php foreach ($dictOptions['mealTypes'] as $opt): ?>
                                        <label class="k-tile"><input type="checkbox" :name="'stages['+i+'][meals][]'" value="<?= htmlspecialchars($opt['code']) ?>" x-model="stage.meals"><?= htmlspecialchars($opt['name']) ?></label>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <label class="k-fl"><span><?= __('Notatki do dnia') ?> <small><?= __('opcjonalnie') ?></small></span><textarea :name="'stages['+i+'][notes]'" x-model="stage.notes" placeholder="<?= htmlspecialchars(__('np. trudny odcinek po deszczu, uwaga na bydło na drodze')) ?>"></textarea></label>
                            </div>
                        </div>
                    </template>
                    <button type="button" class="k-addr" @click="addStage()"><?= __('+ Dodaj kolejny dzień') ?></button>
                </div>
            </section>
