            <!-- ══ KROK: O CZYM ══ -->
            <section class="k-step" x-show="currentStep==='oczym'">
                <h1 x-text="type==='pokrec_z_kims' ? 'Napisz, co planujesz' : __('Jak to opisać?')"></h1>
                <p class="k-step__l" x-text="type==='pokrec_z_kims' ? __('Krótko i po ludzku — dokąd, w jakim tempie, czego szukasz.') : __('Tytuł zobaczą na liście. Opis przeczytają ci, którzy klikną.')"></p>
                <div class="k-fields">
                    <!-- Oba pola BEZ required (usunięte 2026-08-09) — walidacja w
                         validateBeforeSubmit() (script.php), patrz komentarz przy
                         polu daty w step-kiedy.php (ten sam powód). -->
                    <label class="k-fl"><span><?= __('Tytuł') ?></span>
                        <input type="text" name="title" x-model="title" @input="titleManuallyEdited=true" placeholder="<?= htmlspecialchars(__('np. Wielka Pętla Bieszczadzka')) ?>">
                        <small x-show="type==='pokrec_z_kims'"><?= __('Podpowiemy tytuł z regionu i dat — możesz go zmienić.') ?></small>
                    </label>
                    <label class="k-fl"><span><?= __('Opis') ?></span>
                        <textarea name="description" x-model="description" :placeholder="type==='pokrec_z_kims' ? __('Napisz jak do znajomych — dokąd, w jakim tempie, czego szukasz...') : __('Dla kogo, jaka atmosfera, co warto wiedzieć...')"></textarea>
                    </label>
                    <!-- Makieta ogranicza upload zdjęcia do ustawka/wielo —
                         dzisiejszy formularz pokazuje je dla WSZYSTKICH typów,
                         dopisanie pominiętego pola. -->
                    <label class="k-big" :class="coverPhotoSelected && 'done'">
                        <?= Utils\Icon::render('camera') ?>
                        <span>
                            <span x-text="coverPhotoSelected ? __('Zdjęcie dodane') : __('Dodaj zdjęcie')"></span>
                            <small x-show="!coverPhotoSelected"><?= __('Bez zdjęcia pokażemy profil trasy') ?></small>
                        </span>
                        <input type="file" name="cover_photo" x-ref="coverPhotoInput" accept="image/jpeg,image/png,image/webp" style="display:none;" @change="onCoverPhotoChange($event)">
                    </label>
                    <?php // Usunięcie wybranego zdjęcia (dopisane 2026-08-09 —
                          // pytanie usera: "jak załączę zdjęcie i będę chciał się
                          // rozmyślić, to jak mam je usunąć?"; odpowiedź brzmiała
                          // "nie da się"). MUSI być RODZEŃSTWEM <label> wyżej, nie
                          // dzieckiem — klik w cokolwiek wewnątrz <label> otwiera
                          // okno wyboru pliku, więc przycisk w środku najpierw
                          // czyściłby, a zaraz potem otwierał picker. ?>
                    <p class="k-note" x-show="coverPhotoSelected" x-cloak>
                        <button type="button" class="link-button" @click="clearCoverPhoto()"><?= __('Usuń wybrane zdjęcie') ?></button>
                    </p>
                    <!-- Druga, RÓWNORZĘDNA droga na okładkę: link zamiast pliku
                         z dysku. Serwer sam pobiera zdjęcie spod tego adresu
                         przy zapisie wydarzenia (Resources\EventFormInput::fromRequest()
                         -> Utils\Upload::saveCoverPhotoFromUrl()) i zapisuje je
                         lokalnie jak każdy inny upload — przeglądarka NIE wysyła
                         wtedy żadnych bajtów obrazu, tylko sam adres. Dzięki temu
                         duże zdjęcie nigdy nie robi wielkiego POST-a (który
                         potrafił zostać odrzucony przez firewall hostingu, ZANIM
                         dotarł do aplikacji — zgłoszenie usera 2026-08-09).
                         Widoczne (nie ukryte) świadomie: rozszerzenie Chrome
                         wypełnia to pole jak każde inne, a user WIDZI co zostało
                         wstawione i może wkleić link sam. Plik z dysku ma
                         pierwszeństwo, gdy podano oba (patrz EventFormInput). -->
                    <label class="k-fl"><span><?= __('…albo wklej link do zdjęcia') ?> <small><?= __('pobierzemy je za Ciebie') ?></small></span>
                        <input type="url" name="cover_photo_source_url" x-model="coverPhotoSourceUrl"
                               placeholder="https://…/zdjecie.jpg" :disabled="coverPhotoSelected">
                        <small x-show="coverPhotoSelected" x-cloak><?= __('Wybrano plik z dysku — link nie jest potrzebny.') ?></small>
                    </label>
                </div>
            </section>
