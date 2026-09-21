<?php
// views/web/pages/notifications-admin.php
// PANEL POWIADOMIEŃ — /admin/powiadomienia (2026-09-11, migr. 083).
//
// Odpowiada na zgłoszenie usera: „czy jest gdzieś miejsce do zarządzania
// powiadomieniami — jakie, w jakim czasie, jak często". Do tej daty wszystkie
// te decyzje siedziały w stałych `NotificationGate`, a pora wysyłki nawet nie
// w aplikacji, tylko w crontabie na serwerze.
//
// KOMPONENTY SĄ POŻYCZONE Z `points-admin.php`, NIE NAPISANE OD NOWA:
// `.rate`/`.rate__in`/`.rate__hint` + `.is-set` („zmienione") to dokładnie ten
// sam problem co przy stawkach punktowych — liczba z jednostką, podpowiedzią
// i wartością domyślną, do której wraca się wyczyszczeniem pola. Tabele to
// `.dash-table`, ta sama co w panelu punktów. Przełączniki 0/1 używają
// `.acc-notif__row` z ekranu konta, gdzie mieszkają zgody użytkownika — ta
// sama rzecz wizualnie, tylko po naszej stronie.
//
// Widok NIE ZNA nazw parametrów ani ich wartości domyślnych — wszystko
// przychodzi z `NotificationSettings::PARAMETRY` przez kontroler, więc nowy
// parametr w modelu pojawia się tu sam.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

use Core\Csrf;
use Utils\View;

$pole = static fn(string $klucz): string => str_replace('.', '_', $klucz);
?>
<h1 class="display">Powiadomienia</h1>
<p class="desc spaced-below">
    Co wysyłamy, w jakim czasie i jak często. Te ustawienia obowiązują <b>cały serwis</b> —
    osobiste zgody ludzi mieszkają w ich kontach i nic tutaj ich nie rusza.
</p>

<?php if (!empty($_GET['zapisano'])): ?>
<p class="form-success">Zapisano ustawienia powiadomień.</p>
<?php endif; ?>

<?php // STAN „TU I TERAZ" jest pierwszą rzeczą na ekranie, bo najczęstsze
      // pytanie do tego panelu brzmi „czemu nic nie wychodzi", a odpowiedź
      // zwykle zależy od godziny, o której się patrzy. ?>
<div class="box">
    <h3>Teraz jest <?= sprintf('%02d:00', (int) $terazGodzina) ?></h3>
    <ul class="desc" style="margin:8px 0 0;padding-left:18px;">
        <li><b><?= $terazCisza ? 'Trwa cisza nocna' : 'Poza ciszą nocną' ?></b> —
            <?= $terazCisza
                ? 'zachęty pushem są wstrzymane. Maile idą dalej (nikogo nie budzą), rzeczy transakcyjne też.'
                : 'zachęty pushem mogą wychodzić.' ?></li>
        <li><b><?= $terazWOknie ? 'Jesteśmy w oknie wysyłki' : 'Poza oknem wysyłki' ?></b> —
            <?= $terazWOknie
                ? 'gdyby zadanie w tle odpaliło się teraz, zachęty poszłyby.'
                : 'zachęty nie wyjdą, nawet jeśli zadanie w tle odpali się w tej chwili.' ?></li>
    </ul>
    <?php if ($lastChange): ?>
    <p class="hint" style="margin:12px 0 0;">Ostatnia zmiana:
        <code><?= htmlspecialchars((string) $lastChange['setting_key']) ?></code>,
        <?= htmlspecialchars((string) $lastChange['updated_at']) ?><?= $lastChange['who'] ? ', ' . htmlspecialchars((string) $lastChange['who']) : '' ?>.</p>
    <?php endif; ?>
</div>

<form method="post" action="<?= View::url('/admin/powiadomienia') ?>">
    <?= Csrf::field() ?>

    <?php foreach ($grupy as $kod => [$tytul, $opisGrupy]): ?>
    <?php if (empty($pola[$kod])) { continue; } ?>
    <div class="box">
        <h3><?= htmlspecialchars($tytul) ?></h3>
        <p class="desc" style="margin-top:0;"><?= htmlspecialchars($opisGrupy) ?></p>

        <?php
            // Przełączniki i liczby renderują się inaczej, więc rozdzielam je
            // przed pętlą — inaczej `.rate-grid` dostałby w środku wiersze,
            // które nie są stawkami, i siatka by się rozjechała.
            $przelaczniki = [];
            $liczby = [];
            foreach ($pola[$kod] as $p) {
                $czyPrzelacznik = (int) $p['meta']['min'] === 0 && (int) $p['meta']['max'] === 1;
                if ($czyPrzelacznik) { $przelaczniki[] = $p; } else { $liczby[] = $p; }
            }
        ?>

        <?php if ($liczby): ?>
        <div class="rate-grid">
            <?php foreach ($liczby as $p): ?>
            <div class="rate<?= $p['overridden'] ? ' is-set' : '' ?>">
                <label for="f-<?= $pole($p['key']) ?>">
                    <?= htmlspecialchars($p['meta']['label']) ?>
                    <?php if ($p['overridden']): ?><i>zmienione</i><?php endif; ?>
                </label>
                <div class="rate__in">
                    <input type="number" id="f-<?= $pole($p['key']) ?>" name="<?= $pole($p['key']) ?>"
                           value="<?= htmlspecialchars((string) (int) $p['value']) ?>"
                           min="<?= (int) $p['meta']['min'] ?>" max="<?= (int) $p['meta']['max'] ?>" step="1"
                           placeholder="<?= (int) $p['default'] ?>">
                    <span><?= htmlspecialchars($p['meta']['unit']) ?></span>
                </div>
                <p class="rate__hint">
                    <?= htmlspecialchars($p['meta']['hint']) ?><br>
                    Domyślnie: <b><?= (int) $p['default'] ?></b> — wyczyść pole, żeby wrócić.
                </p>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($przelaczniki && $kod !== 'typ'): ?>
        <div class="acc-notif">
            <?php foreach ($przelaczniki as $p): ?>
            <label class="acc-notif__row">
                <input type="hidden" name="obecne_<?= $pole($p['key']) ?>" value="1">
                <input type="checkbox" name="<?= $pole($p['key']) ?>" value="1" <?= $p['value'] >= 1 ? 'checked' : '' ?>>
                <span class="acc-notif__txt">
                    <b><?= htmlspecialchars($p['meta']['label']) ?><?php
                        if ($p['overridden']): ?> · <span style="color:var(--accent);">zmienione</span><?php endif; ?></b>
                    <em><?= htmlspecialchars($p['meta']['hint']) ?></em>
                </span>
            </label>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($kod === 'typ'): ?>
        <?php
            // RODZAJE POWIADOMIEŃ — wyłącznik I TREŚĆ w jednym miejscu, bo to
            // jest jedna rzecz: „co wysyłamy i jak to brzmi". Rozdzielenie ich
            // na dwie sekcje ekranu (pierwsza wersja tego panelu) okazało się
            // nie do zarządzania: trzeba było skakać po stronie, żeby w ogóle
            // zobaczyć, czego dotyczy edytowany tekst.
            //
            // Wyłącznik jest PER TYP z bramki, a powiadomień bywa na jeden typ
            // więcej niż jedno (`message` to wiadomość prywatna i grupowa),
            // więc checkbox renderujemy przy PIERWSZYM wystąpieniu typu.
            // Powtórzony miałby tę samą nazwę w formularzu i cicho nadpisywał
            // poprzedni — klasyczna pułapka checkboxów.
            $przelacznikiPoTypie = [];
            foreach ($przelaczniki as $p) {
                $przelacznikiPoTypie[substr($p['key'], strlen('typ.'))] = $p;
            }
            $uzyteTypy = [];
        ?>
        <?php foreach ($teksty as $kodPow => $pow): ?>
        <?php
            $typ = $pow['def']['typ'];
            $sw  = $przelacznikiPoTypie[$typ] ?? null;
            $pierwszyDlaTypu = !isset($uzyteTypy[$typ]);
            $uzyteTypy[$typ] = true;
        ?>
        <div class="ntf-item<?= $pow['zmieniony'] ? ' is-set' : '' ?>">
            <div class="ntf-item__head">
                <?php if ($sw && $pierwszyDlaTypu): ?>
                <label class="ntf-item__sw" title="Wyłącz ten rodzaj dla całego serwisu">
                    <input type="hidden" name="obecne_<?= $pole($sw['key']) ?>" value="1">
                    <input type="checkbox" name="<?= $pole($sw['key']) ?>" value="1" <?= $sw['value'] >= 1 ? 'checked' : '' ?>>
                </label>
                <?php else: ?>
                <span class="ntf-item__sw ntf-item__sw--shared" title="Wspólny wyłącznik z powiadomieniem wyżej">&#8627;</span>
                <?php endif; ?>

                <div class="ntf-item__txt">
                    <b><?= htmlspecialchars($pow['def']['label']) ?><?php
                        if ($pow['zmieniony']): ?> <span style="color:var(--accent);font-weight:400;">· treść zmieniona</span><?php endif; ?></b>
                    <em><?= htmlspecialchars($pow['def']['opis']) ?></em>
                </div>

                <button type="button" class="ntf-item__edit" data-ntf-toggle="ntf-<?= htmlspecialchars($kodPow) ?>"
                        aria-expanded="false" title="Edytuj treść">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M12 20h9"></path>
                        <path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"></path>
                    </svg>
                    <span>Treść</span>
                </button>
            </div>

            <div class="ntf-item__body" id="ntf-<?= htmlspecialchars($kodPow) ?>" hidden>
                <?php if (!empty($pow['def']['warianty'])): ?>
                <p class="hint" style="margin:0 0 14px;">To powiadomienie ma <b>dwa warianty</b> — wybiera je system,
                    nie Ty, na podstawie tego, czy skarb jest już widoczny na mapie. Dzięki temu nazwa skarbu,
                    którego odbiorca jeszcze nie odkrył, nie ma jak trafić do powiadomienia.</p>
                <?php endif; ?>

                <?php $ostatniWariant = '__brak__'; ?>
                <?php foreach ($pow['pola'] as $f): ?>
                    <?php if (($f['wariant'] ?? null) !== $ostatniWariant): $ostatniWariant = $f['wariant'] ?? null; ?>
                        <?php if ($ostatniWariant !== null && isset($pow['def']['warianty'][$ostatniWariant])): ?>
                        <h4 class="ntf-var"><?= htmlspecialchars($pow['def']['warianty'][$ostatniWariant]['label']) ?></h4>
                        <?php endif; ?>
                    <?php endif; ?>

                    <div class="ntf-field">
                        <label for="t-<?= $pole($f['key']) ?>">
                            <?= htmlspecialchars($f['label']) ?>
                            <?php if ($f['overridden']): ?><i>zmienione</i><?php endif; ?>
                        </label>
                        <textarea id="t-<?= $pole($f['key']) ?>" name="<?= $pole($f['key']) ?>"
                                  rows="<?= $f['pole'] === 'body' && $f['kanal'] === 'mail' ? 8 : 2 ?>"
                                  data-ntf-src="<?= htmlspecialchars($f['key']) ?>"
                                  data-ntf-plain="<?= $f['kanal'] === 'push' ? '1' : '0' ?>"
                                  spellcheck="true"><?= htmlspecialchars($f['value']) ?></textarea>

                        <div class="ntf-tags">
                            <?php foreach ($f['znaczniki'] as $zn): ?>
                            <button type="button" class="ntf-tag" data-ntf-insert="{<?= htmlspecialchars($zn) ?>}"
                                    data-ntf-target="t-<?= $pole($f['key']) ?>">{<?= htmlspecialchars($zn) ?>}</button>
                            <?php endforeach; ?>
                            <?php if ($f['kanal'] === 'mail' && $f['pole'] === 'body'): ?>
                                <?php foreach ($pow['bloki'] as $bl): ?>
                                <button type="button" class="ntf-tag ntf-tag--blok" data-ntf-insert="{<?= htmlspecialchars($bl) ?>}"
                                        data-ntf-target="t-<?= $pole($f['key']) ?>"
                                        title="<?= htmlspecialchars($opisyBlokow[$bl] ?? '') ?>">{<?= htmlspecialchars($bl) ?>}</button>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <?php if (!$f['znaczniki'] && !($f['kanal'] === 'mail' && $f['pole'] === 'body' && $pow['bloki'])): ?>
                            <span class="hint" style="margin:0;">Tekst stały — bez podstawień.</span>
                            <?php endif; ?>
                        </div>

                        <?php // PODGLĄD. Serwer renderuje go przy wejściu na stronę DOKŁADNIE
                              // tą samą metodą co wysyłka; JS odświeża go przy pisaniu, żeby
                              // efekt było widać od razu, a nie dopiero po zapisie. ?>
                        <div class="ntf-prev<?= $f['kanal'] === 'push' ? ' ntf-prev--push' : '' ?>"
                             data-ntf-prev="<?= htmlspecialchars($f['key']) ?>"><?php
                            if ($f['kanal'] === 'push') {
                                echo htmlspecialchars($f['podglad']);
                            } else {
                                echo $f['podglad'];
                            }
                        ?></div>

                        <?php if ($f['overridden']): ?>
                        <p class="hint" style="margin:6px 0 0;">Wyczyść pole, żeby wrócić do oryginalnego brzmienia.</p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <?php if (!empty($usunieteZnaczniki)): ?>
        <p class="form-success" style="background:#FDF2EE;border-color:#D14E1E;margin-top:16px;">
            Usunięto znaczniki, których nie wolno użyć w tym polu:
            <?php foreach ($usunieteZnaczniki as $i => $zn): ?><?= $i ? ', ' : '' ?><code>{<?= htmlspecialchars($zn) ?>}</code><?php endforeach; ?>.
            Najczęstszy przypadek to <code>{nazwa}</code> w wariancie „skarb ukryty" — nazwa skarbu,
            którego odbiorca jeszcze nie odkrył, nie może wyjść w powiadomieniu.
        </p>
        <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <div class="stg-save">
        <button class="btn" type="submit">Zapisz ustawienia</button>
    </div>
</form>

<?php // PODGLĄD NA ŻYWO + rozwijanie edytora.
      //
      // Serwer renderuje pierwszy podgląd tą samą metodą co wysyłka
      // (`NotificationTexts::podglad`), więc to, co widać po wejściu na stronę,
      // jest PRAWDĄ. Skrypt niżej tylko odświeża go w trakcie pisania — jego
      // podstawianie jest UPROSZCZONE i świadomie: nie sanityzuje HTML-a,
      // bo i tak zrobi to serwer przy zapisie. Podgląd może więc przez chwilę
      // pokazać więcej, niż zostanie zapisane; po „Zapisz" wraca prawda.
      //
      // Dane do podstawień idą z PHP jednym blokiem JSON, żeby JS nie musiał
      // znać ani nazw znaczników, ani wyglądu bloków. ?>
<script>
(function () {
    var PRZYKLAD = <?= json_encode($przyklad, JSON_UNESCAPED_UNICODE) ?>;
    // Bloki PER POWIADOMIENIE (klucz pola zaczyna się od jego kodu) — ten sam
    // zestaw, którym posłużył się serwer przy pierwszym renderowaniu podglądu.
    var BLOKI_WG_POWIADOMIENIA = <?= json_encode($blokiPodgladu, JSON_UNESCAPED_UNICODE) ?>;

    // Rozwijanie edytora treści. `hidden` zamiast klasy, bo to jest stan
    // widoczności, a nie wygląd — i działa bez CSS-a.
    document.querySelectorAll('[data-ntf-toggle]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var box = document.getElementById(btn.dataset.ntfToggle);
            if (!box) { return; }
            var otwarte = box.hidden;
            box.hidden = !otwarte;
            btn.setAttribute('aria-expanded', otwarte ? 'true' : 'false');
            btn.classList.toggle('is-open', otwarte);
            if (otwarte) { odswiezWszystkie(box); }
        });
    });

    // Wstawianie znacznika w miejsce kursora — szybciej i bez literówek niż
    // przepisywanie go z podpowiedzi ręcznie.
    document.querySelectorAll('[data-ntf-insert]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var ta = document.getElementById(btn.dataset.ntfTarget);
            if (!ta) { return; }
            var s = ta.selectionStart || 0, e = ta.selectionEnd || 0;
            ta.value = ta.value.slice(0, s) + btn.dataset.ntfInsert + ta.value.slice(e);
            ta.selectionStart = ta.selectionEnd = s + btn.dataset.ntfInsert.length;
            ta.focus();
            odswiez(ta);
        });
    });

    function podstaw(tekst, plain, klucz) {
        // Klucz pola to `kod.kanal.pole[.wariant]`. Najpierw szukamy zestawu
        // wariantowego (skarb jawny/ukryty mają różne cele linku), potem
        // zwykłego — dokładnie tak, jak robi to serwer.
        var czesci = String(klucz || '').split('.');
        var bloki = BLOKI_WG_POWIADOMIENIA[czesci[0] + '|' + (czesci[3] || '')]
            || BLOKI_WG_POWIADOMIENIA[czesci[0]] || {};
        Object.keys(PRZYKLAD).forEach(function (k) {
            tekst = tekst.split('{' + k + '}').join(plain ? PRZYKLAD[k] : escapeHtml(PRZYKLAD[k]));
        });
        Object.keys(bloki).forEach(function (k) {
            tekst = tekst.split('{' + k + '}').join(plain ? '' : bloki[k]);
        });
        // Znaczniki, których to pole nie zna, znikają — dokładnie tak, jak
        // zrobi z nimi serwer przy zapisie.
        tekst = tekst.replace(/\{[a-z_]+\}/gi, '').replace(/\s*\(\s*\)/g, '');
        return plain ? tekst.replace(/<[^>]*>/g, '').replace(/\s{2,}/g, ' ').trim() : tekst.trim();
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function odswiez(ta) {
        var prev = document.querySelector('[data-ntf-prev="' + ta.dataset.ntfSrc + '"]');
        if (!prev) { return; }
        var plain = ta.dataset.ntfPlain === '1';
        var wynik = podstaw(ta.value, plain, ta.dataset.ntfSrc);
        if (plain) { prev.textContent = wynik; } else { prev.innerHTML = wynik; }
    }

    function odswiezWszystkie(root) {
        (root || document).querySelectorAll('[data-ntf-src]').forEach(odswiez);
    }

    document.querySelectorAll('[data-ntf-src]').forEach(function (ta) {
        ta.addEventListener('input', function () { odswiez(ta); });
    });
})();
</script>

<?php // DZIENNIK — druga połowa ekranu i powód, dla którego pierwsza ma sens:
      // budżet stroi się na podstawie tego, ile faktycznie wychodzi i czy
      // ktokolwiek to otwiera, a nie na przeczucie. ?>
<div class="box">
    <h3>Co poszło</h3>
    <p class="desc" style="margin-top:0;">Z dziennika wysyłek. Kolumna „otwarte" dotyczy wyłącznie
        powiadomień w aplikacji — maile nie mają licznika otwarć i świadomie nie mają w środku
        piksela śledzącego.</p>

    <?php foreach ($statystyki as $dni => $wiersze): ?>
    <h3 style="margin:18px 0 10px;font-family:var(--f-m);font-size:12px;text-transform:uppercase;letter-spacing:.06em;color:var(--ink-mute);">
        Ostatnie <?= (int) $dni ?> dni</h3>
    <?php if (!$wiersze): ?>
    <p class="desc" style="margin-top:0;">Nic nie wysłano.</p>
    <?php else: ?>
    <div class="dash-table">
        <?php foreach ($wiersze as $w): ?>
        <div class="dash-row">
            <div class="dash-cell dash-cell-title" data-label="Rodzaj">
                <?= htmlspecialchars($etykietyTypow[$w['type']] ?? $w['type']) ?>
                <div class="dash-sub"><?= $w['channel'] === 'mail' ? 'mailem' : 'w aplikacji' ?></div>
            </div>
            <div class="dash-cell" data-label="Wysłano"><?= (int) $w['wyslano'] ?></div>
            <div class="dash-cell" data-label="Do osób"><?= (int) $w['osob'] ?></div>
            <div class="dash-cell" data-label="Otwarte"><?= $w['channel'] === 'mail' ? '—' : (int) $w['otwarto'] ?></div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php endforeach; ?>
</div>

<?php if ($ostatnie): ?>
<div class="box">
    <h3>Ostatnie wysyłki</h3>
    <p class="desc" style="margin-top:0;">Surowy podgląd — do sprawdzenia „czy w ogóle poszło".
        Klucz powtórki identyfikuje RZECZ, której powiadomienie dotyczyło, więc ta sama wartość
        nie ma prawa pojawić się drugi raz w tym samym kanale.</p>
    <div class="dash-table">
        <?php foreach ($ostatnie as $o): ?>
        <div class="dash-row">
            <div class="dash-cell dash-cell-title" data-label="Do kogo">
                <?= htmlspecialchars((string) ($o['who'] ?: $o['who_email'] ?: 'konto usunięte')) ?>
                <div class="dash-sub"><?= htmlspecialchars($etykietyTypow[$o['type']] ?? $o['type']) ?>
                    · <?= $o['channel'] === 'mail' ? 'mailem' : 'w aplikacji' ?></div>
            </div>
            <div class="dash-cell" data-label="Kiedy"><?= htmlspecialchars((string) $o['sent_at']) ?></div>
            <div class="dash-cell" data-label="Klucz"><code><?= htmlspecialchars((string) $o['dedupe_key']) ?></code></div>
            <div class="dash-cell" data-label="Otwarte"><?= $o['opened_at'] ? htmlspecialchars((string) $o['opened_at']) : '—' ?></div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
