            <!-- ══ KROK: TRASA (jednodniowa) ══ -->
            <section class="k-step" x-show="currentStep==='trasa'">
                <h1><?= __('Jaka trasa?') ?></h1>
                <p class="k-step__l"><?= __('Najprościej wgrać plik GPX — dystans, przewyższenie i nawierzchnię policzymy sami.') ?></p>

                <!-- Warianty trasy (pętle) — jeden wyjazd, kilka alternatywnych tras,
                     każda z własnym GPX, ceną i limitem (patrz Models\EventRouteVariant).
                     Na razie tylko typy jednodniowe (ten krok pokazuje się tylko dla ustawki). -->
                <label class="k-tile" style="width:fit-content;">
                    <input type="checkbox" x-model="variantsEnabled" @change="toggleVariants()">
                    <b><?= __('Kilka wariantów trasy (pętle)') ?></b>
                </label>
                <?php // WYBÓR użytkownika jedzie w POST osobnym polem, bo checkbox
                      // wyżej ma tylko x-model (bez name) i nigdy się nie wysyłał.
                      // Bez tego stan przełącznika po nieudanym zapisie był ZGADYWANY
                      // z długości tablicy wariantów — a jeden wgrany plik GPX
                      // wystarczał, żeby kreator sam zaznaczył „kilka wariantów"
                      // przy jednym wariancie (zgłoszenie usera 2026-08-13). ?>
                <input type="hidden" name="variants_enabled" :value="variantsEnabled ? '1' : '0'">
                <p class="k-note" x-show="variantsEnabled" x-cloak><?= __('Każdy wariant ma własny GPX, cenę i limit miejsc. Uczestnik wybiera jeden przy zapisie.') ?></p>

                <template x-if="!variantsEnabled">
                <div class="k-fields">
                    <label class="k-big" :class="(stages[0].gpxToken || stages[0].gpxUrl) && 'done'">
                        <?= Utils\Icon::render('upload') ?>
                        <span x-show="!gpxUploading[0]">
                            <span x-text="(stages[0].gpxToken || stages[0].gpxUrl) ? __('trasa.gpx — wczytano') : 'Wgraj plik GPX'"></span>
                            <small x-show="!(stages[0].gpxToken || stages[0].gpxUrl)"><?= __('Z Komoota, Stravy albo licznika') ?></small>
                        </span>
                        <span x-show="gpxUploading[0]"><?= __('Analizuję trasę i nawierzchnię… (do 30 s)') ?></span>
                        <input type="file" accept=".gpx" style="display:none;" @change="uploadGpx(0, $event)">
                    </label>

                    <p class="k-note"><?= __('Nie masz GPX? Wpisz dystans ręcznie — resztę można uzupełnić później.') ?></p>
                    <div class="k-two">
                        <label class="k-fl"><span><?= __('Dystans (km)') ?></span><input type="number" step="0.1" name="stages[0][distance_km]" x-model="stages[0].distanceKm" placeholder="45"></label>
                        <label class="k-fl"><span><?= __('Przewyższenie (m)') ?> <small><?= __('opcjonalnie') ?></small></span><input type="number" name="stages[0][elevation_m]" x-model="stages[0].elevationM" placeholder="600"></label>
                    </div>

                    <input type="hidden" name="stages[0][gpx_token]" x-model="stages[0].gpxToken">
                    <input type="hidden" name="stages[0][existing_gpx_url]" :value="stages[0].gpxUrl || ''">
                    <?php // Ukryte pola % + wykryty/ręczny podział nawierzchni — wspólne z krokiem PLAN.
                          $sRef = 'stages[0]'; $sIdx = '0'; $sLegend = true; require __DIR__ . '/gpx-surface.php'; ?>
                </div>
                </template>

                <template x-if="variantsEnabled">
                    <?php require __DIR__ . '/variants.php'; ?>
                </template>
            </section>
