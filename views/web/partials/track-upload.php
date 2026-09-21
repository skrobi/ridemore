<?php
// views/web/partials/track-upload.php
// ŚLAD Z ODBYTEGO WYJAZDU — jedna kontrolka używana wszędzie, gdzie człowiek
// jest już „po wyjeździe": na stronie wydarzenia i w kronice.
//
// Powstała jako funkcja, a nie zwykły require, bo jest renderowana z dwóch
// różnych widoków o zupełnie innych zmiennych — i będzie z kolejnych, gdy
// dojdzie następne miejsce (ten sam powód co activity-card.php).
//
// DLACZEGO PEŁNY BOX Z NAGŁÓWKIEM, A NIE LINIJKA W STOPCE (zgłoszenie usera
// 2026-08-12: „w ogóle nie jest widoczne i niekoniecznie wiadomo, że można i
// trzeba to zrobić"): pierwsza wersja siedziała jako dopisek w stopce bloku
// „Relacje z tego wyjazdu" — czyli w miejscu, które w dodatku POKAZUJE SIĘ
// TYLKO WTEDY, GDY ISTNIEJE KRONIKA. Wyjazd bez kroniki nie miał tej
// kontrolki w ogóle, a przy okazji to właśnie tam jest najbardziej potrzebna:
// bez śladu nikt nie dostaje ani jednego pola na mapie odkryć.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

if (!function_exists('renderTrackUpload')) {
    /**
     * @param array $o [
     *   'eventSlug'  => string,
     *   'editionId'  => int,
     *   'tracks'     => array   wiersze EditionTrack::forEdition()
     *   'canManage'  => bool    uprawnienia do edycji wydarzenia (ślad wspólny)
     *   'isAttendee' => bool    potwierdzona obecność (ślad własny)
     *   'viewerId'   => ?int
     *   'backTo'     => ?string  'kronika' — wraca do kroniki zamiast na stronę
     *                            wydarzenia (kontrolka stoi w dwóch miejscach,
     *                            a wyrzucenie człowieka na inną stronę niż ta,
     *                            na której kliknął, to zawsze błąd)
     * ]
     */
    function renderTrackUpload(array $o): void
    {
        $tracks = $o['tracks'] ?? [];
        $canManage = !empty($o['canManage']);
        $isAttendee = !empty($o['isAttendee']);
        $viewerId = $o['viewerId'] ?? null;
        if (!$canManage && !$isAttendee) {
            return;
        }

        $url = fn(string $p) => htmlspecialchars(Utils\View::url($p));
        $shortName = static function (?string $raw): string {
            $parts = preg_split('/\s+/', trim((string) $raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (!$parts) { return __('Uczestnik'); }
            $out = $parts[0];
            if (count($parts) > 1) {
                $out .= ' ' . mb_strtoupper(mb_substr((string) end($parts), 0, 1)) . '.';
            }
            return $out;
        };
        ?>
        <section class="sec" id="slad">
            <div class="box">
                <p class="eyebrow" style="margin-top:0;"><span class="blaze" style="--bz:var(--blaze-dark);"></span><?= __('Po wyjeździe') ?></p>
                <h2><?= __('Ślad z tego wyjazdu') ?></h2>
                <p class="chr-actions__sub">
                    <?php if ($canManage): ?>
                    <?= __('Wgraj plik GPX z tego, co faktycznie przejechaliście — odkryje teren na mapie') ?>

                    <b><?= __('każdemu, kto potwierdził obecność') ?></b><?= __('. Trasa zapowiedziana przy wydarzeniu
                    do tego nie wystarcza, bo nie mówi, jak wyjazd wyszedł naprawdę.') ?>

                    <?php else: ?>
                    <?= __('Wgraj swój plik GPX z tego wyjazdu — dopiero on odkrywa teren na') ?>

                    <a href="<?= $url('/odkrycia') ?>"><?= __('Twojej mapie') ?></a><?= __('. Bez śladu przejazd nie
                    liczy się do odkryć, bo trasa zapowiedziana przy wydarzeniu nie mówi,
                    którędy pojechałeś naprawdę.') ?>

                    <?php endif; ?>
                </p>

                <?php // Wynik poprzedniej próby. Czytany z adresu, bo akcja kończy
                      // się przekierowaniem (PRG) — bez tego nieudany upload
                      // wyglądał jak przycisk, który nic nie robi. ?>
                <?php
                $trackErrors = [
                    'brak-pliku'     => __('Wybierz plik GPX.'),
                    'zly-plik'       => __('Nie udało się odczytać tego pliku — czy to na pewno GPX ze śladem?'),
                    'brak-uprawnien' => __('Ślad może wgrać organizator albo ktoś z potwierdzoną obecnością.'),
                ];
                $trackError = $trackErrors[$_GET['blad'] ?? ''] ?? null;
                ?>
                <?php if ($trackError): ?>
                <p class="form-error"><?= htmlspecialchars($trackError) ?></p>
                <?php elseif (($_GET['info'] ?? '') === 'slad-i-obecnosc'): ?>
                <?php // Wgranie własnego śladu POTWIERDZA OBECNOŚĆ (2026-08-13) —
                      // mówimy o tym wprost, bo to zmiana stanu, o którą nikt nie
                      // prosił osobno i bez komunikatu wyglądałaby na przypadek. ?>
                <p class="form-success"><?= __('Ślad wgrany — zaznaczyliśmy też, że byłeś na tym
                    wyjeździe. Odkryte pola są już przeliczone.') ?></p>
                <?php elseif (($_GET['info'] ?? '') === 'slad-niezgodny'): ?>
                <?php // Plik PRZYJĘTY, obecność nie. Mówimy dlaczego i zostawiamy
                      // decyzję człowiekowi — mógł jechać wariantem albo skrótem,
                      // a odrzucenie pliku byłoby zarzutem, nie informacją. ?>
                <p class="form-success"><?= __('Ślad wgrany i pola przeliczone. Nie zaznaczyliśmy
                    obecności automatycznie, bo trasa z pliku nie pokrywa się z zapowiedzianą —
                    jeśli byłeś, potwierdź to przyciskiem „Byłem”.') ?></p>
                <?php elseif (($_GET['info'] ?? '') === 'slad-dodany'): ?>
                <p class="form-success"><?= __('Ślad wgrany — odkryte pola są już przeliczone.') ?></p>
                <?php endif; ?>

                <?php if ($tracks): ?>
                <div class="track-list">
                    <?php foreach ($tracks as $track): ?>
                    <?php $ownerId = $track['user_id'] === null ? null : (int) $track['user_id']; ?>
                    <div class="track-list__row">
                        <span>
                            <?php // Imię skracane jak wszędzie w serwisie („Michał W."),
                                  // nigdy pełne nazwisko. ?>
                            <b><?= $ownerId === null
                                ? __('Ślad wspólny')
                                : __('Ślad: {kto}', ['kto' => htmlspecialchars($shortName($track['owner_name'] ?? ''))]) ?></b>
                            <?php if (!empty($track['label'])): ?>
                            · <?= htmlspecialchars($track['label']) ?>
                            <?php endif; ?>
                            <?php if ((float) $track['distance_km'] > 0): ?>
                            · <?= htmlspecialchars(Utils\Format::distance((float) $track['distance_km'])) ?>
                            <?php endif; ?>
                        </span>
                        <?php if ($canManage || ($ownerId !== null && $ownerId === (int) $viewerId)): ?>
                        <form method="post"
                              action="<?= $url('/wydarzenia/' . $o['eventSlug'] . '/slad/' . (int) $track['id'] . '/usun') ?>"
                              onsubmit="return confirm(__('Usunąć ten ślad? Odkryte z niego pola zostaną odjęte.'));">
                            <?= Core\Csrf::field() ?>
                            <input type="hidden" name="powrot" value="<?= htmlspecialchars((string) ($o['backTo'] ?? '')) ?>">
                            <button type="submit" class="link-button"><?= __('Usuń') ?></button>
                        </form>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php // Własny ślad ZASTĘPUJE wspólny, więc uczestnik, który już go ma,
                      // musi wiedzieć, że jego mapa liczy się z tego pliku, a nie z
                      // tego, co wgrał organizator. ?>
                <?php
                $hasOwn = false;
                foreach ($tracks as $t) {
                    if ($t['user_id'] !== null && (int) $t['user_id'] === (int) $viewerId) { $hasOwn = true; break; }
                }
                ?>
                <?php if ($hasOwn): ?>
                <p class="chr-facts" style="margin-bottom:14px;">
                    <?= __('Twoje odkrycia liczą się z') ?> <b><?= __('Twojego') ?></b> <?= __('śladu — wspólny ślad wyjazdu Ciebie
                    nie dotyczy, dopóki swój tu trzymasz.') ?>

                </p>
                <?php endif; ?>

                <form method="post" action="<?= $url('/wydarzenia/' . $o['eventSlug'] . '/slad') ?>"
                      enctype="multipart/form-data" class="track-upload">
                    <?= Core\Csrf::field() ?>
                    <input type="hidden" name="edition_id" value="<?= (int) $o['editionId'] ?>">
                    <input type="hidden" name="powrot" value="<?= htmlspecialchars((string) ($o['backTo'] ?? '')) ?>">
                    <label class="track-upload__file">
                        <span><?= __('Wybierz plik GPX') ?></span>
                        <input type="file" name="gpx" accept=".gpx" required>
                    </label>
                    <input type="text" name="label" maxlength="150" class="search-input"
                           placeholder="<?= htmlspecialchars(__('opis (opcjonalnie, np. „Dzień 2')) ?>")">
                    <?php if ($canManage && $isAttendee): ?>
                    <?php // Organizator, który sam jechał, może wgrać ślad WŁASNY zamiast
                          // wspólnego — inaczej każdy jego upload dotyczyłby wszystkich. ?>
                    <label class="track-upload__own">
                        <input type="checkbox" name="zakres" value="wlasny">
                        <?= __('to tylko mój ślad, nie wspólny') ?>

                    </label>
                    <?php endif; ?>
                    <button type="submit" class="btn"><?= __('Wgraj ślad') ?></button>
                </form>
            </div>
        </section>
        <?php
    }
}
