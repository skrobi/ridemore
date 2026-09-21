<?php
// views/web/pages/account.php
// Przebudowa wg szablony/moje-konto.html (2026-08-01) — pionowa szyna sekcji
// zamiast poziomych zakładek, pola pogrupowane w karty .box zamiast jednego
// formularza na sekcję. Mechanizm przełączania (showSection(), #sectionTabs/
// .section-tab/.section-panel) zostaje bez zmian, patrz assets/js/ui.js —
// zmienia się tylko wygląd (.settings-* w style.css) i grupowanie markupu.
//
// Oczekuje: $user (Models\User), $nameError (?string), $passwordError (?string),
// $passwordSuccess (?string), $billingProfile (?Models\UserBillingProfile),
// $billingError (?string), $billingSuccess (?string), $preferences, $preferencesDictOptions,
// $preferencesError (?string), $preferencesSuccess (?string),
// $behavioralPattern (?array — null gdy <3 potwierdzonych zapisów, patrz
// Models\EventRsvp::behavioralPatternForUser()).
if (!defined('CORE_PATH')) { http_response_code(403); exit; }
$billingProfile = $billingProfile ?? null;
// Etap 3 (preferencje) — patrz Models\UserPreference::forUser() dla kształtu
// $preferences; obie klasy zapisane osobno (operational/aspirational), bo
// ta sama pozycja słownikowa (region) może być prawdziwa w obu naraz.
$preferences = $preferences ?? ['operational' => ['bike_type'=>[],'pace_group'=>[],'difficulty_level'=>[],'region'=>[]], 'aspirational' => ['region'=>[],'event_type'=>[]], 'distanceMinKm'=>null,'distanceMaxKm'=>null,'elevationMaxM'=>null,'groupSizePref'=>'any','notifyMatches'=>false];
$preferencesDictOptions = $preferencesDictOptions ?? ['bikeTypes'=>[],'paces'=>[],'difficulties'=>[],'regions'=>[],'eventTypes'=>[]];
$behavioralPattern = $behavioralPattern ?? null;
$inSet = fn(string $code, array $codes) => in_array($code, $codes, true);

// Który panel jest aktywny przy wczytaniu strony — domyślnie "Profil", ale
// jeśli to przeładowanie po nieudanym (albo udanym) submit innej sekcji,
// otwieramy TĘ sekcję, żeby komunikat błędu/sukcesu nie chował się za
// domyślnie wybraną zakładką, której user w ogóle nie dotykał.
$activeSection = $requestedSection ?? 'profil';
if (!empty($passwordError) || !empty($passwordSuccess)) $activeSection = 'haslo';
if (!empty($billingError) || !empty($billingSuccess)) $activeSection = 'faktura';
if (!empty($preferencesError) || !empty($preferencesSuccess)) $activeSection = 'preferencje';
$isActive = fn(string $section) => $activeSection === $section;

$regionItems = [];
foreach ($preferencesDictOptions['regions'] as $group) {
    $regionItems = array_merge($regionItems, $group['items']);
}
?>
<?php // Bez okruszków w apce — ten sam odruch co messages.php/events-list.php
      // (Fazy 1-2 przebudowy UX apki, tasks/done/apka-mobilna-ux.md). ?>
<?php // Okruszki: bramkę „nie w apce" trzyma teraz sam partial (2026-09-11) —
      // zasada obowiązywała tylko w czterech szablonach, a dotyczy wszystkich. ?>
<?php require __DIR__ . '/../partials/breadcrumbs.php'; ?>
<h1 class="display"><?= __('Moje konto') ?></h1>
<p class="desc spaced-below"><?= __('Ustawienia Ciebie jako uczestnika wyjazdów. Profil organizatora — czyli to, co widzą
    ludzie, gdy Ty coś organizujesz — jest') ?> <a href="<?= Utils\View::url('/admin/profil-rozliczeniowy') ?>"><?= __('osobno') ?></a>.</p>

<div class="settings-layout">
    <nav class="settings-rail section-tabs" id="sectionTabs" aria-label="<?= htmlspecialchars(__('Sekcje konta')) ?>">
        <button type="button" class="section-tab<?= $isActive('profil') ? ' active' : '' ?>" data-section="profil" onclick="showSection('profil', this)"><?= __('Profil') ?></button>
        <button type="button" class="section-tab<?= $isActive('haslo') ? ' active' : '' ?>" data-section="haslo" onclick="showSection('haslo', this)"><?= __('Hasło') ?></button>
        <button type="button" class="section-tab<?= $isActive('faktura') ? ' active' : '' ?>" data-section="faktura" onclick="showSection('faktura', this)"><?= __('Dane do faktury') ?></button>
        <button type="button" class="section-tab<?= $isActive('preferencje') ? ' active' : '' ?>" data-section="preferencje" onclick="showSection('preferencje', this)"><?= __('Preferencje dopasowań') ?></button>
        <div class="settings-rail__sep"></div>
        <?php // DROGA POWROTNA DO PROFILU (2026-09-13). Profil prowadzi tu panelem
              // „Twoje ustawienia"; bez tego linku ustawienia były ślepym zaułkiem
              // i nie dało się sprawdzić, jak zmiana wygląda oczami innych. ?>
        <?php if (!empty($user->publicSlug)): ?>
        <p class="settings-rail__note"><a href="<?= Utils\View::url('/rowerzysta/' . $user->publicSlug) ?>"><?= __('Zobacz swój profil rowerzysty →') ?></a></p>
        <?php endif; ?>
        <p class="settings-rail__note"><?= __('Organizujesz wyjazdy?') ?> <a href="<?= Utils\View::url('/admin/profil-rozliczeniowy') ?>"><?= __('Profil organizatora →') ?></a></p>
    </nav>

    <div class="settings-content">
        <div class="section-panel<?= $isActive('profil') ? ' active' : '' ?>" data-section="profil">
            <div class="box">
                <h3><?= __('Nazwa i zdjęcie') ?> <span class="vis vis--pub"><?= __('widoczne publicznie') ?></span></h3>
                <p class="desc" style="font-size:14px;margin:4px 0 16px;"><?= __('Zamiast adresu e-mail. Może być imię i nazwisko albo nazwa grupy.') ?></p>

                <?php if (!empty($nameError)): ?>
                <p class="form-error"><?= htmlspecialchars($nameError) ?></p>
                <?php endif; ?>

                <form method="post" enctype="multipart/form-data" action="<?= Utils\View::url('/admin/moje-konto') ?>">
                    <?= Core\Csrf::field() ?>

                    <div class="avatar-edit-row">
                        <div id="avatarPreviewWrap">
                        <?php if (!empty($user->avatarUrl)): ?>
                        <img class="avatar-edit-preview" id="avatarPreviewImg" src="<?= htmlspecialchars(Utils\Image::src($user->avatarUrl, 'av')) ?>" alt="" width="72" height="72">
                        <?php else: ?>
                        <div class="avatar-edit-preview avatar-edit-preview-initials" id="avatarPreviewInitials"><?= htmlspecialchars(mb_substr($user->name ?: $user->email, 0, 2)) ?></div>
                        <?php endif; ?>
                        </div>
                        <div>
                            <label class="upload-box" id="avatarUploadBox" style="padding:10px 16px;">
                                <?= Utils\Icon::render('camera') ?>
                                <span id="avatarUploadLabel"><?= __('Zmień zdjęcie') ?></span>
                                <input type="file" name="avatar" id="avatarInput" accept="image/jpeg,image/png,image/webp" style="display:none;">
                            </label>
                            <?php if (!empty($user->avatarUrl)): ?>
                            <label class="filter-opt"><input type="checkbox" name="remove_avatar" value="1"> <?= __('Usuń obecne zdjęcie') ?></label>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="form-field">
                        <label for="account-name"><?= __('Nazwa wyświetlana') ?></label>
                        <input id="account-name" type="text" name="name" placeholder="<?= htmlspecialchars(__('np. Twoje imię i nazwisko albo nazwa grupy')) ?>" required maxlength="150" value="<?= htmlspecialchars($user->name ?? '') ?>">
                    </div>

                    <div class="stg-save">
                        <button class="btn btn--sm" type="submit"><?= __('Zapisz profil') ?></button>
                    </div>
                </form>
            </div>

            <div class="box">
                <h3><?= __('Adres e-mail') ?> <span class="vis vis--priv"><?= __('niewidoczne') ?></span></h3>
                <p class="desc" style="font-size:14px;margin:4px 0 16px;"><?= __('Tym adresem się logujesz i na niego wysyłamy powiadomienia o Twoich zapisach.') ?></p>
                <div class="form-field">
                    <label for="account-email">E-mail</label>
                    <input id="account-email" type="email" value="<?= htmlspecialchars($user->email) ?>" disabled>
                </div>
            </div>

            <?php // POWIADOMIENIA PUSH (Etap 8 przebudowy apki, 2026-08-28) —
                  // WYŁĄCZNIE w apce (APP_IS_APP): na www nie ma urządzenia,
                  // które mogłoby dostać push, więc pokazywanie przełącznika
                  // byłoby obietnicą bez pokrycia. Stan czyta
                  // Models\PushDevice::hasActiveForUser() — zgoda = obecność
                  // aktywnego wiersza, nie osobna kolumna (patrz migr. 077). ?>
            <?php if (APP_IS_APP): ?>
            <div class="box">
                <h3><?= __('Powiadomienia push') ?></h3>
                <p class="desc" style="font-size:14px;margin:4px 0 16px;"><?= __('Nowy skarb w Twojej okolicy, ktoś dołączył
                    do Twojego wyjazdu, nowa wiadomość na czacie.') ?></p>
                <button type="button" class="btn btn-secondary btn--sm" id="accPushToggle"
                        data-on="<?= !empty($pushEnabled) ? '1' : '0' ?>">
                    <?= !empty($pushEnabled) ? __('Wyłącz powiadomienia') : __('Włącz powiadomienia') ?>
                </button>

                <?php // RODZAJE POWIADOMIEŃ (Etap 0 programu zachęt, 2026-09-11).
                      //
                      // Do tej daty zgoda była zero-jedynkowa: jedno natrętne
                      // powiadomienie wyłączało przełącznik wyżej, a razem z nim
                      // ginęły wiadomości od organizatora wyjazdu. Te przełączniki
                      // istnieją po to, żeby „nie chcę zachęt" nie znaczyło
                      // „nie chcę wiedzieć, że ktoś do mnie napisał".
                      //
                      // WIDOCZNE TYLKO PRZY WŁĄCZONYM PUSHU — bez zgody na
                      // urządzenie nie ma czego doprecyzowywać.
                      //
                      // POKAZUJEMY WYŁĄCZNIE RODZAJE, KTÓRE NAPRAWDĘ DZIŚ WYCHODZĄ.
                      // `push_progress` (zachęty o postępie) ma już kolumnę
                      // i obsługę w bramce, ale ŻADEN kod jeszcze go nie wysyła —
                      // przełącznik do niego dojdzie razem z pierwszym nadawcą.
                      // W tym projekcie kilka razy okazało się, że tekst w UI
                      // obiecywał funkcję, której kod nie realizował; to jest
                      // świadome niepowtarzanie tamtego błędu.
                      $rodzaje = [
                          'push_messages' => [__('Wiadomości i zapisy'),
                              __('Nowa wiadomość, ktoś dołączył do Twojego wyjazdu. Przychodzą od razu, o każdej porze.')],
                          'push_nearby'   => [__('Nowości w Twojej okolicy'),
                              __('Nowy skarb albo trasa w rejonie, który już odkrywasz. Najwyżej dwie w tygodniu, nigdy w nocy.')],
                      ];
                      // Tylko przy włączonym automacie z licznika (migr. 088) —
                      // ta sama zasada co wyżej: bez nadawcy nie ma przełącznika.
                      if (!empty($deviceAutoImport)) {
                          $rodzaje['push_rides'] = [__('Przejazdy z licznika'),
                              __('Polar albo Wahoo przysłał nowy trening i dodaliśmy go automatycznie.')];
                      }
                      $zgody = $pushPrefs ?? Models\NotificationGate::ZGODY_DOMYSLNE; ?>
                <?php if (!empty($pushEnabled)): ?>
                <div class="acc-notif">
                    <?php foreach ($rodzaje as $flaga => [$tytul, $opis]): ?>
                    <label class="acc-notif__row">
                        <input type="checkbox" data-rm-notif="<?= htmlspecialchars($flaga) ?>"
                               <?= !empty($zgody[$flaga]) ? 'checked' : '' ?>>
                        <span class="acc-notif__txt">
                            <b><?= htmlspecialchars($tytul) ?></b>
                            <em><?= htmlspecialchars($opis) ?></em>
                        </span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <script>
            (function () {
                var btn = document.getElementById('accPushToggle');
                if (!btn) { return; }
                btn.addEventListener('click', function () {
                    btn.disabled = true;
                    var wlaczone = btn.getAttribute('data-on') === '1';
                    var akcja;
                    if (wlaczone) {
                        var dane = new URLSearchParams();
                        dane.set('csrf_token', <?= json_encode(Core\Csrf::token()) ?>);
                        akcja = fetch(<?= json_encode(Utils\View::url('/api/devices/unregister')) ?>, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: dane.toString(),
                            credentials: 'same-origin'
                        });
                    } else if (window.RM && RM.native) {
                        akcja = RM.native.registerPush();
                    } else {
                        akcja = Promise.reject(new Error(__('Brak mostu natywnego.')));
                    }
                    akcja.then(function () { window.location.reload(); })
                        .catch(function (err) {
                            alert(err.message || __('Nie udało się zmienić ustawienia.'));
                            btn.disabled = false;
                        });
                });

            })();
            </script>
            <?php endif; ?>

            <?php // POWIADOMIENIA MAILOWE (Etap 1c programu zachęt, 2026-09-11).
                  //
                  // WIDOCZNE ZAWSZE, także na www — i to jest sedno tej sekcji.
                  // Przełączniki pushowe wyżej siedzą za `APP_IS_APP`, bo bez
                  // apki nie ma czego przełączać. Z mailem jest odwrotnie:
                  // adres ma KAŻDE konto, więc każdy musi mieć gdzie to
                  // wyłączyć. Gdyby ta sekcja też schowała się za `APP_IS_APP`,
                  // ludzie bez apki dostawaliby maile bez żadnego wyłącznika
                  // w koncie — zostałby im tylko przycisk „to jest spam".
                  //
                  // ZGODY SĄ OSOBNE OD PUSHOWYCH (decyzja usera 2026-09-11):
                  // ten sam rodzaj powiadomienia można chcieć mailem i nie
                  // chcieć pushem. `push_progress`/`mail_progress` NIE MA tu
                  // przełącznika, bo wciąż nie ma nadawcy — ta sama zasada co
                  // przy pushu: ekran pokazuje to, co naprawdę wychodzi.
                  $rodzajeMail = [
                      'mail_messages' => [__('Wiadomości'),
                          __('Ktoś napisał do Ciebie albo organizator ogłosił coś w kanale wyjazdu.')],
                      'mail_nearby'   => [__('Nowości w Twojej okolicy'),
                          __('Nowy skarb albo trasa w rejonie, który już odkrywasz. Najwyżej dwie w tygodniu.')],
                  ];
                  if (!empty($deviceAutoImport)) {
                      $rodzajeMail['mail_rides'] = [__('Przejazdy z licznika'),
                          __('Polar albo Wahoo przysłał nowy trening i dodaliśmy go automatycznie.')];
                  }
                  $zgodyMail = $pushPrefs ?? Models\NotificationGate::ZGODY_DOMYSLNE; ?>
            <div class="box" id="powiadomienia-mail">
                <h3><?= __('Powiadomienia mailem') ?></h3>
                <p class="desc" style="font-size:14px;margin:4px 0 16px;"><?= __('Maile dotyczące Twojego konta i wyjazdów,
                    na które się zapisujesz — potwierdzenia, płatności, rezygnacje — przychodzą zawsze i nie ma ich na tej liście.') ?></p>
                <div class="acc-notif">
                    <?php foreach ($rodzajeMail as $flaga => [$tytul, $opis]): ?>
                    <label class="acc-notif__row">
                        <input type="checkbox" data-rm-notif="<?= htmlspecialchars($flaga) ?>"
                               <?= !empty($zgodyMail[$flaga]) ? 'checked' : '' ?>>
                        <span class="acc-notif__txt">
                            <b><?= htmlspecialchars($tytul) ?></b>
                            <em><?= htmlspecialchars($opis) ?></em>
                        </span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php // OBSŁUGA PRZEŁĄCZNIKÓW RODZAJÓW — CELOWO POZA `APP_IS_APP`.
                  // Do Etapu 1c dotyczyły wyłącznie pusha, więc mieszkały
                  // w skrypcie sekcji pushowej, która na www w ogóle się nie
                  // renderuje. Od kanału mailowego te same przełączniki są
                  // potrzebne przeglądarce (sekcja „Powiadomienia mailowe"
                  // niżej), a skrypt schowany za tym samym `if` po prostu by
                  // nie istniał — checkboxy wyglądałyby na działające i nic
                  // by nie zapisywały. ?>
            <script>
            (function () {
                // RODZAJE POWIADOMIEŃ — zapis od razu przy przełączeniu, bez
                // przycisku „zapisz" i bez przeładowania strony. To ustawienie
                // jednego bitu, a nie formularz: kazanie tu czegokolwiek
                // zatwierdzać byłoby ceremonią wokół jednego dotknięcia.
                //
                // PRZY BŁĘDZIE PRZEŁĄCZNIK WRACA NA STARE. Zostawienie go
                // w nowej pozycji po nieudanym zapisie pokazywałoby stan,
                // którego serwer nie zna — czyli dokładnie to kłamstwo,
                // przed którym broni się reszta tego ekranu.
                document.querySelectorAll('[data-rm-notif]').forEach(function (pole) {
                    pole.addEventListener('change', function () {
                        var dane = new URLSearchParams();
                        dane.set('csrf_token', <?= json_encode(Core\Csrf::token()) ?>);
                        dane.set('flaga', pole.dataset.rmNotif);
                        dane.set('wartosc', pole.checked ? '1' : '0');
                        pole.disabled = true;
                        fetch(<?= json_encode(Utils\View::url('/api/powiadomienia/zgoda')) ?>, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: dane.toString(),
                            credentials: 'same-origin'
                        }).then(function (r) {
                            if (!r.ok) { throw new Error(__('Nie udało się zapisać ustawienia.')); }
                        }).catch(function (err) {
                            pole.checked = !pole.checked;
                            alert(err.message || __('Nie udało się zapisać ustawienia.'));
                        }).then(function () {
                            pole.disabled = false;
                        });
                    });
                });
            })();
            </script>

            <div class="box">
                <h3><?= __('Usunięcie konta') ?></h3>
                <?php // KASOWANIE KONTA W APCE (Etap 9, 2026-08-29) — samoobsługowy
                      // formularz zamiast linku mailto. Treść MÓWI PRAWDĘ o tym, co
                      // się dzieje mechanicznie (blokada od razu, pełne kasowanie
                      // ręcznie przez admina) — poprzednia wersja obiecywała
                      // automatyczną „anonimizację", której żaden kod nie realizował. ?>
                <p class="desc" style="font-size:14px;margin:4px 0 16px;"><?= __('Konto zostanie') ?> <b><?= __('natychmiast zablokowane') ?></b> <?= __('—
                    nie zalogujesz się nim ponownie, a Twój profil przestanie być widoczny. Pełne, trwałe usunięcie
                    (dotyczy m.in. wyjazdów, w których brałeś/aś udział, i historii punktów) kończy potem administrator
                    ręcznie. To nieodwracalne.') ?></p>

                <?php if (!empty($deletionError)): ?>
                <p class="form-error"><?= htmlspecialchars($deletionError) ?></p>
                <?php endif; ?>

                <form method="post" action="<?= Utils\View::url('/admin/moje-konto/usun') ?>"
                      onsubmit="return confirm(__('Na pewno? Konto zostanie zablokowane od razu, a usunięcie jest nieodwracalne.'));">
                    <?= Core\Csrf::field() ?>
                    <?php if ($user->passwordHash !== null): ?>
                    <div class="form-field">
                        <label for="account-delete-password"><?= __('Potwierdź hasłem') ?></label>
                        <input id="account-delete-password" type="password" name="current_password" required autocomplete="current-password">
                    </div>
                    <?php endif; ?>
                    <label class="checkbox-opt">
                        <input type="checkbox" name="confirm" value="1" required>
                        <?= __('Rozumiem, że to nieodwracalne, i chcę usunąć swoje konto.') ?>

                    </label>
                    <div class="stg-save">
                        <button class="btn btn-secondary btn--sm" type="submit"><?= __('Usuń konto') ?></button>
                    </div>
                </form>
            </div>
        </div>

        <div class="section-panel<?= $isActive('haslo') ? ' active' : '' ?>" data-section="haslo">
            <div class="box">
                <h3><?= __('Zmień hasło') ?></h3>
                <p class="desc" style="font-size:14px;margin:4px 0 16px;"><?= __('Zmiana hasła wyloguje Cię na pozostałych urządzeniach.') ?></p>

                <?php if (!empty($passwordSuccess)): ?>
                <p class="form-success"><?= htmlspecialchars($passwordSuccess) ?></p>
                <?php endif; ?>
                <?php if (!empty($passwordError)): ?>
                <p class="form-error"><?= htmlspecialchars($passwordError) ?></p>
                <?php endif; ?>

                <form method="post" action="<?= Utils\View::url('/admin/moje-konto/haslo') ?>">
                    <?= Core\Csrf::field() ?>
                    <div class="form-field">
                        <label for="account-current-password"><?= __('Obecne hasło') ?></label>
                        <input id="account-current-password" type="password" name="current_password" required autocomplete="current-password">
                    </div>
                    <div class="two-col">
                        <div class="form-field">
                            <label for="account-new-password"><?= __('Nowe hasło (min. 8 znaków)') ?></label>
                            <input id="account-new-password" type="password" name="new_password" required minlength="8" autocomplete="new-password">
                        </div>
                        <div class="form-field">
                            <label for="account-new-password-repeat"><?= __('Powtórz nowe hasło') ?></label>
                            <input id="account-new-password-repeat" type="password" name="new_password_repeat" required minlength="8" autocomplete="new-password">
                        </div>
                    </div>
                    <div class="stg-save">
                        <button class="btn btn--sm" type="submit"><?= __('Zmień hasło') ?></button>
                    </div>
                </form>
            </div>
        </div>

        <div class="section-panel<?= $isActive('faktura') ? ' active' : '' ?>" data-section="faktura">
            <div class="box">
                <h3><?= __('Dane do faktury') ?> <span class="vis vis--priv"><?= __('niewidoczne') ?></span></h3>
                <p class="desc" style="font-size:14px;margin:4px 0 16px;"><?= __('Potrzebne przy zapisie na płatne wydarzenie — jeśli organizator wystawi fakturę,
                    te dane trafią na nią. Dla bezpłatnych wydarzeń niepotrzebne. Przekazujemy je wyłącznie organizatorowi wyjazdu, na który się zapiszesz.') ?></p>

                <?php if (!empty($billingSuccess)): ?>
                <p class="form-success"><?= htmlspecialchars($billingSuccess) ?></p>
                <?php endif; ?>
                <?php if (!empty($billingError)): ?>
                <p class="form-error"><?= htmlspecialchars($billingError) ?></p>
                <?php endif; ?>

                <form method="post" action="<?= Utils\View::url('/admin/moje-konto/dane-adresowe') ?>">
                    <?= Core\Csrf::field() ?>
                    <div class="two-col">
                        <div class="form-field">
                            <label for="account-legal-name"><?= __('Imię i nazwisko (lub nazwa firmy)') ?></label>
                            <input id="account-legal-name" type="text" name="legal_name" required maxlength="200" value="<?= htmlspecialchars($billingProfile->legalName ?? '') ?>">
                        </div>
                        <div class="form-field">
                            <label for="account-tax-id"><?= __('NIP (opcjonalnie, dla faktury na firmę)') ?></label>
                            <input id="account-tax-id" type="text" name="tax_id" value="<?= htmlspecialchars($billingProfile->taxId ?? '') ?>">
                        </div>
                    </div>
                    <div class="form-field">
                        <label for="account-address"><?= __('Adres') ?></label>
                        <input id="account-address" type="text" name="address" required value="<?= htmlspecialchars($billingProfile->address ?? '') ?>">
                    </div>
                    <div class="stg-save">
                        <button class="btn btn--sm" type="submit"><?= __('Zapisz dane do faktury') ?></button>
                    </div>
                </form>
            </div>
        </div>

        <div class="section-panel<?= $isActive('preferencje') ? ' active' : '' ?>" data-section="preferencje">
            <p class="desc spaced-below"><b><?= __('To nigdy nie ukrywa przed Tobą wyjazdów.') ?></b> <?= __('Na liście zawsze widzisz wszystko —
                preferencje wpływają tylko na kolejność podpowiedzi i na to, o czym Cię powiadamiamy.') ?></p>

            <?php if (!empty($preferencesSuccess)): ?>
            <p class="form-success"><?= htmlspecialchars($preferencesSuccess) ?></p>
            <?php endif; ?>
            <?php if (!empty($preferencesError)): ?>
            <p class="form-error"><?= htmlspecialchars($preferencesError) ?></p>
            <?php endif; ?>

            <form method="post" action="<?= Utils\View::url('/admin/moje-konto/preferencje') ?>">
                <?= Core\Csrf::field() ?>

                <div class="box" id="jak-jezdze">
                    <h3><?= __('Jak jeżdżę') ?></h3>
                    <p class="desc" style="font-size:14px;margin:4px 0 16px;"><?= __('Trzy najważniejsze rzeczy. Reszta jest opcjonalna.') ?></p>

                    <div class="form-field">
                        <label><?= __('Na czym jeżdżę') ?></label>
                        <div class="stg-row" style="display:flex;flex-wrap:wrap;gap:8px;margin-top:6px;">
                            <?php foreach ($preferencesDictOptions['bikeTypes'] as $opt): ?>
                            <label class="stg-opt"><input type="checkbox" name="bike_types[]" value="<?= htmlspecialchars($opt['code']) ?>" <?= $inSet($opt['code'], $preferences['operational']['bike_type']) ? 'checked' : '' ?>><?= htmlspecialchars($opt['name']) ?></label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="form-field" style="margin-top:16px;">
                        <label><?= __('Gdzie najczęściej jeżdżę') ?></label>
                        <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:6px;">
                            <?php foreach ($regionItems as $opt): ?>
                            <label class="stg-opt"><input type="checkbox" name="regions[]" value="<?= htmlspecialchars($opt['code']) ?>" <?= $inSet($opt['code'], $preferences['operational']['region']) ? 'checked' : '' ?>><?= htmlspecialchars($opt['name']) ?></label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="two-col" style="margin-top:16px;">
                        <div class="form-field">
                            <label><?= __('Dystans od (km)') ?></label>
                            <input type="number" name="distance_min_km" min="0" value="<?= htmlspecialchars((string) ($preferences['distanceMinKm'] ?? '')) ?>">
                        </div>
                        <div class="form-field">
                            <label><?= __('Dystans do (km)') ?></label>
                            <input type="number" name="distance_max_km" min="0" value="<?= htmlspecialchars((string) ($preferences['distanceMaxKm'] ?? '')) ?>">
                        </div>
                    </div>

                    <div style="margin-top:18px;">
                        <button type="button" class="more" data-open="#prefMore" aria-expanded="false" style="width:100%;display:flex;align-items:center;gap:10px;min-height:44px;padding:11px 15px;background:none;border:1.5px dashed var(--hair-strong);border-radius:12px;cursor:pointer;font:inherit;font-size:14px;color:var(--ink-soft);text-align:left;"><?= __('+ Tempo, trudność, przewyższenie, wielkość grupy') ?> <em style="font-style:normal;margin-left:auto;font-size:13px;color:var(--ink-mute);"><?= __('możesz pominąć') ?></em></button>
                        <div id="prefMore" hidden style="margin-top:16px;padding-left:16px;border-left:3px solid var(--hair);display:grid;gap:16px;">
                            <div class="form-field">
                                <label><?= __('Tempo') ?></label>
                                <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:6px;">
                                    <?php foreach ($preferencesDictOptions['paces'] as $opt): ?>
                                    <label class="stg-opt"><input type="checkbox" name="paces[]" value="<?= htmlspecialchars($opt['code']) ?>" <?= $inSet($opt['code'], $preferences['operational']['pace_group']) ? 'checked' : '' ?>><?= htmlspecialchars($opt['name']) ?></label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="form-field">
                                <label><?= __('Trudność') ?></label>
                                <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:6px;">
                                    <?php foreach ($preferencesDictOptions['difficulties'] as $opt): ?>
                                    <label class="stg-opt"><input type="checkbox" name="difficulties[]" value="<?= htmlspecialchars($opt['code']) ?>" <?= $inSet($opt['code'], $preferences['operational']['difficulty_level']) ? 'checked' : '' ?>><?= htmlspecialchars($opt['name']) ?></label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="two-col" style="margin:0;">
                                <div class="form-field">
                                    <label><?= __('Maks. przewyższenie (m, opcjonalnie)') ?></label>
                                    <input type="number" name="elevation_max_m" min="0" value="<?= htmlspecialchars((string) ($preferences['elevationMaxM'] ?? '')) ?>">
                                </div>
                                <div class="form-field">
                                    <label><?= __('Preferowany rozmiar grupy') ?></label>
                                    <select name="group_size_pref">
                                        <option value="small" <?= $preferences['groupSizePref'] === 'small' ? 'selected' : '' ?>><?= __('Mała') ?></option>
                                        <option value="any" <?= $preferences['groupSizePref'] === 'any' ? 'selected' : '' ?>><?= __('Bez znaczenia') ?></option>
                                        <option value="large" <?= $preferences['groupSizePref'] === 'large' ? 'selected' : '' ?>><?= __('Duża') ?></option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="stg-save">
                        <button class="btn btn--sm" type="submit"><?= __('Zapisz preferencje') ?></button>
                    </div>
                </div>

                <div class="box">
                    <h3><?= __('Gdzie chcę kiedyś pojechać') ?></h3>
                    <p class="desc" style="font-size:14px;margin:4px 0 16px;"><?= __('To co innego niż wyżej. Tam opisujesz, jak jeździsz teraz — tu, o czym marzysz.
                        Zaznaczonych tu miejsc') ?> <b><?= __('nigdy nie odrzucimy dlatego, że dotąd tam nie jeździłeś') ?></b>.</p>
                    <div class="form-field">
                        <label><?= __('Marzę o regionie') ?></label>
                        <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:6px;">
                            <?php foreach ($regionItems as $opt): ?>
                            <label class="stg-opt"><input type="checkbox" name="aspirational_regions[]" value="<?= htmlspecialchars($opt['code']) ?>" <?= $inSet($opt['code'], $preferences['aspirational']['region']) ? 'checked' : '' ?>><?= htmlspecialchars($opt['name']) ?></label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="form-field" style="margin-top:16px;">
                        <label><?= __('Kusi mnie format (opcjonalnie)') ?></label>
                        <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:6px;">
                            <?php foreach ($preferencesDictOptions['eventTypes'] as $opt): ?>
                            <label class="stg-opt"><input type="checkbox" name="aspirational_event_types[]" value="<?= htmlspecialchars($opt['code']) ?>" <?= $inSet($opt['code'], $preferences['aspirational']['event_type']) ? 'checked' : '' ?>><?= htmlspecialchars($opt['name']) ?></label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="stg-save">
                        <button class="btn btn--sm" type="submit"><?= __('Zapisz marzenia') ?></button>
                    </div>
                </div>

                <div class="box" id="powiadomienia">
                    <h3><?= __('Powiadomienia') ?></h3>
                    <label class="stg-sw">
                        <input type="checkbox" name="notify_matches" value="1" <?= $preferences['notifyMatches'] ? 'checked' : '' ?>>
                        <span><b><?= __('Powiadamiaj mnie mailem o pasujących wyjazdach') ?></b>
                            <small><?= __('Najwyżej raz w tygodniu i tylko wtedy, gdy pojawi się coś naprawdę pasującego.') ?></small></span>
                    </label>
                    <div class="stg-save">
                        <button class="btn btn--sm" type="submit"><?= __('Zapisz powiadomienia') ?></button>
                    </div>
                </div>

                <?php
                    // Prywatność (migr. 037). Checkbox jest ODWROTNOŚCIĄ kolumny
                    // users.roster_visible — po stronie UI naturalniej brzmi „ukryj
                    // mnie", w SQL naturalniej czyta się „jestem widoczny".
                    // Ten sam komponent .stg-sw co przełącznik powiadomień wyżej.
                ?>
                <div class="box" id="prywatnosc">
                    <h3><?= __('Prywatność') ?></h3>
                    <label class="stg-sw">
                        <input type="checkbox" name="hide_from_rosters" value="1" <?= $user->rosterVisible ? '' : 'checked' ?>>
                        <span><b><?= __('Nie pokazuj mnie na listach uczestników i w peletonie') ?></b>
                            <small><?= __('Twoje imię i awatar znikną z sekcji „Kto jedzie" oraz z peletonów innych
                                rowerzystów. Nadal liczysz się do składu wyjazdu („8 osób jedzie”), a organizator
                                wciąż widzi Cię na swojej liście uczestników — to on odpowiada za wyjazd.') ?></small></span>
                    </label>
                    <div class="stg-save">
                        <button class="btn btn--sm" type="submit"><?= __('Zapisz prywatność') ?></button>
                    </div>
                </div>
            </form>

            <?php if ($behavioralPattern): ?>
            <div class="box">
                <h3><?= __('Co wiemy z Twoich zapisów') ?></h3>
                <p class="desc" style="font-size:14px;margin:4px 0 16px;"><?= __('Poza tym, co wpiszesz, patrzymy też na to, na co faktycznie się zapisujesz —
                    poza marzeniami z sekcji wyżej, których nigdy nie podważamy.') ?></p>
                <p class="desc" style="font-size:15px;"><?= __('Z Twoich zapisów wynika:') ?>

                    <?php
                        $bits = array_filter([
                            $behavioralPattern['bikeTypeLabel'],
                            $behavioralPattern['regionLabel'],
                            $behavioralPattern['distanceRangeLabel'],
                            $behavioralPattern['paceLabel'],
                        ]);
                    ?>
                    <?php require_once __DIR__ . '/../partials/region-link.php'; ?>
                    <?php foreach ($bits as $i => $b): ?><?= $i > 0 ? ', ' : '' ?><b><?= $b === $behavioralPattern['regionLabel'] ? renderRegionLinks($b) : htmlspecialchars($b) ?></b><?php endforeach; ?>.
                </p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var moreBtn = document.querySelector('.more[data-open]');
    if (!moreBtn) return;
    moreBtn.addEventListener('click', function () {
        var box = document.querySelector(moreBtn.dataset.open);
        box.hidden = !box.hidden;
        moreBtn.setAttribute('aria-expanded', String(!box.hidden));
    });
});
</script>
