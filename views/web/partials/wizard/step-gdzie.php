            <!-- ══ KROK: GDZIE ══ -->
            <section class="k-step" x-show="currentStep==='gdzie'">
                <h1 x-text="type==='pokrec_z_kims' ? __('Gdzie chcesz jechać?') : __('Skąd startujecie?')"></h1>
                <p class="k-step__l" x-text="type==='pokrec_z_kims' ? __('Wystarczy region. Punkt na mapie jest opcjonalny.') : __('Wskaż punkt na mapie — nie każde miejsce zbiórki ma adres.')"></p>
                <div class="k-fields">
                    <label class="k-fl"><span><?= __('Region') ?></span>
                        <!-- BEZ required — walidacja w validateBeforeSubmit()
                             (script.php), patrz komentarz w step-kiedy.php. -->
                        <select name="region" x-model="region">
                            <option value=""><?= __('Wybierz region') ?></option>
                            <?php foreach ($dictOptions['regions'] as $group): ?>
                            <?php if ($group['label'] === null): ?>
                            <?php foreach ($group['items'] as $opt): ?>
                            <option value="<?= htmlspecialchars($opt['code']) ?>"><?= htmlspecialchars($opt['name']) ?></option>
                            <?php endforeach; ?>
                            <?php else: ?>
                            <optgroup label="<?= htmlspecialchars($group['label']) ?>">
                                <?php foreach ($group['items'] as $opt): ?>
                                <option value="<?= htmlspecialchars($opt['code']) ?>"><?= htmlspecialchars($opt['name']) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                            <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <input type="hidden" name="meeting_lat" :value="meetingPoint.lat">
                    <input type="hidden" name="meeting_lng" :value="meetingPoint.lng">
                    <button type="button" class="k-big" :class="meetingPoint.lat && 'done'" @click="openMap('meetingPoint')">
                        <?= Utils\Icon::render('pin') ?>
                        <span>
                            <span x-text="meetingPoint.lat ? 'Punkt ustawiony' : __('Ustaw punkt zbiórki na mapie')"></span>
                            <small x-show="!meetingPoint.lat"><?= __('Kliknij, otworzy się mapa') ?></small>
                            <small x-show="meetingPoint.lat" x-text="meetingPoint.lat ? (meetingPoint.lat.toFixed(5) + ', ' + meetingPoint.lng.toFixed(5) + __(' — dotknij, żeby poprawić')) : ''"></small>
                        </span>
                    </button>

                    <!-- Makieta ogranicza to pole do ustawka/wielo — dzisiejszy
                         formularz pokazuje je dla WSZYSTKICH typów (też
                         pokrec_z_kims), dopisanie pominiętego pola. -->
                    <label class="k-fl"><span><?= __('Jak nazwać to miejsce?') ?> <small><?= __('opcjonalnie') ?></small></span>
                        <input type="text" name="meeting_address" x-model="meetingPoint.label" placeholder="<?= htmlspecialchars(__('np. Parking leśny, Cisna')) ?>"></label>
                </div>
            </section>
