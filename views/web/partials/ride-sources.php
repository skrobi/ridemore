<?php
// views/web/partials/ride-sources.php
// SKĄD WZIĄĆ ŚLAD — zakładki źródeł na ekranie „Moje przejazdy" (2026-08-23).
//
// Zgłoszenie usera: „zrób proszę zakładkami wybór sposobu dodawania (tylko
// niech wyglądają jak zakładki, a nie jak pastylki): Upload (GPX/FIT), Garmin,
// Polar, Wahoo, Coros, Suunto".
//
// DLACZEGO ZAKŁADKI, A NIE SZEŚĆ BLOKÓW POD SOBĄ: to są WARIANTY JEDNEJ
// czynności („wsadź tu swój przejazd"), a nie sześć różnych rzeczy do zrobienia.
// Człowiek wybiera swój licznik raz i nigdy więcej nie patrzy na pozostałe pięć.
//
// PLIK JEST PIERWSZY I TO NIE JEST KOLEJNOŚĆ ALFABETYCZNA. Wgranie pliku działa
// zawsze, dla każdego licznika i bez zgody kogokolwiek; integracje są wygodą,
// nie fundamentem (patrz md/features.md). Gdyby jutro wszyscy dostawcy
// pozamykali API, ta zakładka dalej robi całą robotę.
//
// ZAKŁADKA IDZIE ADRESEM (`?zrodlo=`), nie JS-em — powrót z autoryzacji OAuth
// musi umieć wskazać właściwą, a link do „tej z Polarem" ma dać się wysłać.
//
// Oczekuje w zasięgu: $zrodlo, $deviceMeta, $deviceConns, $devicePending,
// $garminOn, $plural.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

use Core\Csrf;
use Utils\DeviceApi;
use Utils\Format;
use Utils\Icon;
use Utils\View;

$zrodlo = $zrodlo ?? 'upload';
$deviceMeta = $deviceMeta ?? [];
$deviceConns = $deviceConns ?? [];
$devicePending = $devicePending ?? [];

$srcUrl = static fn(string $key): string =>
    View::url('/admin/moje-przejazdy') . ($key === 'upload' ? '' : '?zrodlo=' . $key) . '#dodaj';

// Komunikaty są WSPÓLNE dla wszystkich dostawców (te same kody w adresie),
// bo różnią się serwisem, a nie tym, co poszło nie tak.
$gMsgs = [
    'sesja'              => __('Sesja wygasła — spróbuj jeszcze raz.'),
    'brak-danych'        => __('Podaj adres e-mail i hasło.'),
    'brak-kluczy'        => __('Ta integracja nie ma jeszcze kluczy API w tym środowisku.'),
    'odmowa'             => __('Autoryzacja została przerwana — nic nie zostało podłączone.'),
    'logowanie'          => __('Serwis odrzucił te dane logowania — sprawdź adres e-mail i hasło.'),
    'kod-2fa'            => __('To konto ma logowanie dwuskładnikowe. Wpisz kod z aplikacji '
                          . 'uwierzytelniającej w polu „Kod 2FA" i spróbuj jeszcze raz. Kod '
                          . 'wysyłany e-mailem tędy nie zadziała — dociera już po tym, jak '
                          . 'logowanie zostało odrzucone.'),
    'wygaslo'            => __('Połączenie wygasło — zaloguj się jeszcze raz. Pobrane wcześniej '
                          . 'przejazdy zostają i nie policzą się drugi raz.'),
    'limit'              => __('Za dużo prób z tego konta. Spróbuj za kilkanaście minut.'),
    'limit-garmin'       => __('Serwis chwilowo odrzuca kolejne próby (za dużo zapytań). Spróbuj później.'),
    'polaczenie'         => __('Nie udało się połączyć z serwisem. Szczegóły są w logu serwera.'),
    'srodowisko'         => __('Środowisko Pythona nie ma biblioteki garminconnect — patrz ai-engine/README.md.'),
    'most'               => __('Połączenie nie zadziałało. Szczegóły są w logu serwera.'),
    'nic-nie-zaznaczono' => __('Zaznacz przynajmniej jeden przejazd do pobrania.'),
    'automat-niedostepny' => __('Automatyczne dodawanie nie jest jeszcze skonfigurowane w tym środowisku.'),
    'automat-ponownie'   => __('Żeby licznik mógł sam przysyłać nowe przejazdy, odłącz konto i połącz je '
                          . 'jeszcze raz — przy pierwszym połączeniu nie było na to zgody. Pobrane '
                          . 'przejazdy zostają.'),
];
$gError = $gMsgs[$_GET['gblad'] ?? ''] ?? null;
$gInfo = $_GET['ginfo'] ?? '';
?>
<div class="card spaced-below" id="dodaj">
    <h2 style="margin-top:0;"><?= __('Przejechałeś coś poza wyjazdem?') ?></h2>
    <p class="desc"><?= __('Wrzuć tu ślad z własnej rundy — pola i punkty liczą się tak samo jak
        z wyjazdu. Nie musisz go do niczego przypisywać.') ?></p>

    <?php // PASEK ZAKŁADEK. Kropka przy nazwie = konto podłączone, więc widać
          // to bez wchodzenia w każdą z osobna. ?>
    <div class="src-tabs" role="tablist">
        <a class="src-tab<?= $zrodlo === 'upload' ? ' is-on' : '' ?>"
           href="<?= htmlspecialchars($srcUrl('upload')) ?>"><?= __('Plik') ?> <span class="src-tab__x"><?= __('GPX/FIT') ?></span></a>
        <?php foreach ($deviceMeta as $key => $meta): ?>
        <a class="src-tab<?= $zrodlo === $key ? ' is-on' : '' ?>"
           href="<?= htmlspecialchars($srcUrl($key)) ?>"><?= htmlspecialchars($meta['label']) ?><?php
            if (!empty($deviceConns[$key])): ?><span class="src-tab__dot" title="<?= htmlspecialchars(__('konto podłączone')) ?>"></span><?php
            endif; ?></a>
        <?php endforeach; ?>
    </div>

    <div class="src-panel">
    <?php if ($gError): ?>
    <p class="form-error"><?= htmlspecialchars($gError) ?></p>
    <?php elseif ($gInfo === 'polaczono'): ?>
    <p class="form-success"><?= __('Konto połączone. Kliknij „Pobierz aktywności".') ?></p>
    <?php elseif ($gInfo === 'odlaczono'): ?>
    <p class="form-success"><?= __('Konto odłączone. Pobrane wcześniej przejazdy zostają na Twojej mapie.') ?></p>
    <?php elseif ($gInfo === 'automat-wlaczony'): ?>
    <p class="form-success"><?= __('Gotowe — nowe przejazdy rowerowe będą dodawać się same. Dostaniesz o każdym powiadomienie.') ?></p>
    <?php elseif ($gInfo === 'automat-wylaczony'): ?>
    <p class="form-success"><?= __('Automatyczne dodawanie wyłączone. Nowe przejazdy pobierzesz przyciskiem „Pobierz aktywności".') ?></p>
    <?php elseif ($gInfo === 'lista'): ?>
    <?php
    // TRZY LICZBY, NIE JEDNA (błąd zgłoszony przez usera 2026-08-24: „sprawdzono
    // 16 aktywności — nic nowego, a ja mam pod 300 w Garminie"). Wcześniej stała
    // tu liczba aktywności ROWEROWYCH podpisana jako „sprawdzono", więc zdanie
    // mówiło co innego, niż czytał człowiek. Teraz widać cały łańcuch:
    // ile przejrzeliśmy → ile z tego rowerowych → ile nowych.
    $gNowe = (int) ($_GET['nowe'] ?? 0);
    $gSkan = (int) ($_GET['sprawdzono'] ?? 0);
    $gRower = (int) ($_GET['rowerowych'] ?? 0);
    $gOd = (int) ($_GET['od'] ?? 0);
    ?>
    <p class="form-success"><?= __('Przejrzeliśmy') ?> <b><?= $gSkan ?></b>
        <?= $plural($gSkan, 'aktywność', 'aktywności', 'aktywności') ?><?php
        if ($gOd > 0): ?> <?= __('(starsze, od {n}.)', ['n' => $gOd + 1]) ?><?php endif; ?><?= __(',
        w tym') ?> <b><?= $gRower ?></b>
        <?= $plural($gRower, 'rowerową', 'rowerowe', 'rowerowych') ?><?php
        if ($gNowe > 0): ?> <?= __('— nowych do pobrania:') ?> <b><?= $gNowe ?></b>.<?php
        else: ?> <?= __('— wszystkie już tu są.') ?><?php endif; ?></p>
    <?php elseif ($gInfo === 'import'): ?>
    <?php
    $gDodane = (int) ($_GET['dodane'] ?? 0);
    $gDupl = (int) ($_GET['duplikaty'] ?? 0);
    $gOdrz = (int) ($_GET['odrzucone'] ?? 0);
    $gReszta = (int) ($_GET['pozostalo'] ?? 0);
    ?>
    <p class="form-success"><?= __('Pobrano') ?> <b><?= $gDodane ?></b>
        <?= $plural($gDodane, 'przejazd', 'przejazdy', 'przejazdów') ?><?php
        if ($gDodane > 0): ?> — <b><?= (int) ($_GET['pola'] ?? 0) ?></b>
        <?= $plural((int) ($_GET['pola'] ?? 0), 'nowe pole', 'nowe pola', __('nowych pól')) ?>
        <?= __('na Twojej mapie') ?><?php endif; ?>.<?php
        if ($gDupl > 0): ?> <?= $gDupl ?> <?= $plural($gDupl, __('był już policzony'),
            __('były już policzone'), __('było już policzonych')) ?> <?= __('wcześniej.') ?><?php endif; ?><?php
        if ($gOdrz > 0): ?> <?= __('{n} bez śladu GPS — pominięte.', ['n' => $gOdrz]) ?><?php endif; ?><?php
        if ($gReszta > 0): ?> <?= __('Zostało') ?> <b><?= $gReszta ?></b> <?= __('— kliknij „Pobierz zaznaczone"
        jeszcze raz.') ?><?php endif; ?></p>
    <?php endif; ?>

    <?php // ---------------- PLIK ---------------- ?>
    <?php if ($zrodlo === 'upload'): ?>
    <?php
    $soloMsgs = [
        'sesja'      => __('Sesja wygasła — spróbuj jeszcze raz.'),
        'brak-pliku' => __('Wybierz plik GPX albo FIT z przejazdu.'),
        'zly-plik'   => __('Nie udało się odczytać tego pliku — czy to na pewno ślad z licznika?'),
    ];
    $soloError = $soloMsgs[$_GET['blad'] ?? ''] ?? null;
    $soloInfo = $_GET['info'] ?? '';
    ?>
    <?php if ($soloError): ?>
    <p class="form-error"><?= htmlspecialchars($soloError) ?></p>
    <?php elseif ($soloInfo === 'solo-dodany'): ?>
    <p class="form-success"><?= __('Przejazd policzony —') ?> <b><?= (int) ($_GET['pola'] ?? 0) ?></b>
        <?= __('nowych pól na Twojej mapie.') ?></p>
    <?php elseif ($soloInfo === 'solo-duplikat'): ?>
    <p class="form-success"><?= __('Ten ślad już był policzony — nic się nie zdublowało.') ?></p>
    <?php endif; ?>

    <p class="desc"><?= __('Przyjmujemy') ?> <b>GPX</b> i <b>FIT</b> <?= __('— czyli to, co licznik zapisuje
        u siebie (katalog') ?> <code><?= __('Activities') ?></code> <?= __('po podłączeniu kablem) i to, co oddaje
        eksport z każdego serwisu. Ta droga działa dla') ?> <b><?= __('każdego') ?></b> <?= __('licznika i nie
        zależy od niczyjego API.') ?></p>
    <form method="post" action="<?= View::url('/admin/moje-przejazdy/solo') ?>"
          enctype="multipart/form-data" class="track-upload">
        <?= Csrf::field() ?>
        <?php // NATYWNY input[type=file] jest wizualnie ukryty (patrz .track-upload__file
              // w style.css) — bez tego przeglądarka dokładała WŁASNY przycisk „Wybierz
              // plik" obok tego samego napisu z etykiety, co dawało dwa razy tę samą
              // treść i wyglądało jak zepsuty formularz (zgłoszenie usera 2026-08-27).
              // Etykieta OPAKOWUJE input, więc kliknięcie gdziekolwiek na niej dalej
              // otwiera okno wyboru pliku — to standardowe zachowanie <label>, nie JS. ?>
        <label class="track-upload__file">
            <?= Icon::render('upload') ?>
            <span><?= __('Wybierz plik GPX/FIT') ?></span>
            <input type="file" name="gpx" accept=".gpx,.fit" required onchange="this.form.requestSubmit()">
        </label>
    </form>

    <?php // ---------------- LICZNIKI ---------------- ?>
    <?php else: ?>
    <?php
    $meta = $deviceMeta[$zrodlo] ?? [];
    $conn = $deviceConns[$zrodlo] ?? null;
    $lista = $devicePending[$zrodlo] ?? [];
    $usable = DeviceApi::isUsable($zrodlo);
    $oauth = !empty($meta['oauth']);
    $base = View::url('/admin/moje-przejazdy/licznik/' . $zrodlo);
    ?>
    <p class="desc"><?= htmlspecialchars(__($meta['note'] ?? '')) ?></p>

    <?php if (!$usable): ?>
    <?php // DOSTAWCA NIEDOSTĘPNY — mówimy CZEGO BRAKUJE, a nie „coś poszło nie
          // tak". Przycisk, który nie ma prawa zadziałać, jest gorszy niż jego
          // brak: obiecuje operację, której nie da się wykonać. ?>
    <p class="form-error" style="margin-top:12px;">
        <?php if (empty($meta['ready'])): ?>
        <?= __('Ta integracja czeka na dostęp do API — patrz opis wyżej. Do tego czasu użyj
        zakładki') ?> <b><?= __('Plik') ?></b><?= __(': pliki FIT z tego urządzenia wciągamy bez żadnego konta.') ?>

        <?php elseif ($oauth): ?>
        <?= __('Brak kluczy API w tym środowisku. Załóż klienta u dostawcy i ustaw') ?>

        <code><?= strtoupper($zrodlo) ?>_CLIENT_ID</code> <?= __('oraz') ?>

        <code><?= strtoupper($zrodlo) ?>_CLIENT_SECRET</code> <?= __('w zmiennych środowiskowych.
        Adres powrotny:') ?> <code><?= htmlspecialchars(DeviceApi::redirectUri($zrodlo)) ?></code>
        <?php else: ?>
        <?= __('Integracja wymaga Pythona i proc_open po stronie serwera — w tym
        środowisku nie są (jeszcze) skonfigurowane.') ?>

        <?php endif; ?>
    </p>

    <?php elseif ($zrodlo === 'garmin'): ?>
    <?php $garminNext = (int) ($garminNext ?? 0); ?>
    <?php require __DIR__ . '/source-garmin.php'; ?>

    <?php elseif (!$conn): ?>
    <?php // Połączenie idzie POST-em (CSRF), a dopiero serwer przekierowuje do
          // dostawcy — link wprost byłby zaproszeniem do klikania w cudze adresy
          // autoryzacji podrzucone z zewnątrz. ?>
    <form method="post" action="<?= $base ?>/polacz">
        <?= Csrf::field() ?>
        <button class="btn" type="submit"><?= __('Połącz konto {serwis}', ['serwis' => htmlspecialchars($meta['label'])]) ?></button>
    </form>

    <?php else: ?>
    <div class="track-list__row" style="border-bottom:none;padding-bottom:14px;">
        <span><?= __('Konto') ?> <b><?= htmlspecialchars($meta['label']) ?></b> <?= __('podłączone') ?><?php
            if (!empty($conn['lastSyncAt'])): ?><br><span class="desc" style="font-size:13px;">ostatnie
            pobranie: <?= htmlspecialchars(Format::dateShort(substr($conn['lastSyncAt'], 0, 10)) ?? '') ?></span><?php
            endif; ?></span>
        <span class="action-row" style="margin:0;">
            <form method="post" action="<?= $base ?>/odswiez" style="display:inline;">
                <?= Csrf::field() ?>
                <button class="btn btn--sm" type="submit"><?= __('Pobierz aktywności') ?></button>
            </form>
            <form method="post" action="<?= $base ?>/odlacz" style="display:inline;"
                  onsubmit="return confirm(__('Odłączyć konto? Pobrane przejazdy zostaną.'));">
                <?= Csrf::field() ?>
                <button class="btn btn-secondary btn--sm" type="submit"><?= __('Odłącz') ?></button>
            </form>
        </span>
    </div>

    <?php // AUTOMAT (migr. 088, 2026-09-14) — decyzja usera: przełącznik,
          // DOMYŚLNIE WYŁĄCZONY. Pokazuje się tylko wtedy, gdy dostawca ma
          // webhook i znamy jego sekret (DeviceApi::webhookReady) — inaczej
          // obiecywałby przejazdy, które nigdy nie przyjdą. Zapis od razu przy
          // przełączeniu, jak zgody powiadomień w koncie (ten sam komponent
          // `.acc-notif`), tylko zwykłym POST-em z CSRF i PRG. ?>
    <?php if (DeviceApi::webhookReady($zrodlo)): ?>
    <form method="post" action="<?= $base ?>/automat" class="acc-notif" style="margin-top:0;">
        <?= Csrf::field() ?>
        <input type="hidden" name="wlacz" value="<?= !empty($conn['autoImport']) ? '0' : '1' ?>">
        <label class="acc-notif__row">
            <input type="checkbox" <?= !empty($conn['autoImport']) ? 'checked' : '' ?>
                   onchange="this.form.requestSubmit()">
            <span class="acc-notif__txt">
                <b><?= __('Dodawaj nowe przejazdy automatycznie') ?></b>
                <em><?= __('Gdy {serwis} dostanie nowy trening rowerowy z trasą, policzymy go od razu — pola, punkty i mapa, tak jak po „Pobierz zaznaczone". Dostaniesz o tym powiadomienie. Biegi, trenażer i treningi bez trasy zostają do ręcznego pobrania.', ['serwis' => htmlspecialchars($meta['label'])]) ?></em>
            </span>
        </label>
    </form>
    <?php endif; ?>

    <?php if ($lista): ?>
    <?php // ZAZNACZONE DOMYŚLNIE WSZYSTKIE, ale lista pokazuje TEŻ rodzaj treningu:
          // przy dostawcach, u których nie mamy pewnej mapy dyscyplin, odsianie
          // „nierowerowych" po zgadywanym kluczu ukryłoby część przejazdów bez
          // słowa. Człowiek widzi, co jest, i odznacza jednym kliknięciem. ?>
    <form method="post" action="<?= $base ?>/import" data-import-progress>
        <?= Csrf::field() ?>
        <div class="track-list" style="margin-top:6px;">
            <?php foreach ($lista as $item): ?>
            <label class="track-list__row" style="cursor:pointer;">
                <span style="display:flex;align-items:center;gap:10px;min-width:0;">
                    <input type="checkbox" name="aktywnosc[]" value="<?= htmlspecialchars((string) $item['id']) ?>" checked>
                    <span style="min-width:0;">
                        <b><?= htmlspecialchars($item['name'] !== '' ? $item['name'] : __('Przejazd')) ?></b>
                        <span class="dash-sub"><?= htmlspecialchars(substr((string) $item['startedAt'], 0, 16)) ?></span>
                    </span>
                </span>
                <span><?= htmlspecialchars(Format::distance((float) $item['distanceKm'])) ?></span>
            </label>
            <?php endforeach; ?>
        </div>
        <div class="action-row">
            <button class="btn" type="submit"><?= __('Pobierz zaznaczone') ?></button>
            <span class="desc" style="margin:0;"><?= __('Ściągamy porcjami po {n} — przy dłuższej liście to jedno kliknięcie po prostu zajmie chwilę dłużej.', ['n' => \Models\DeviceImport::BATCH]) ?></span>
        </div>
    </form>
    <?php endif; ?>
    <?php endif; ?>
    <?php endif; ?>
    </div>
</div>

<?php // ŻYWY STATUS „POBIERZ ZAZNACZONE" (2026-08-26) — zgłoszenie usera: „nie
      // widać, czy coś się pobiera, ile już się udało, ile jest w trakcie, ile
      // błędów". WSPÓLNY dla Garmina i każdego dostawcy przez DeviceController
      // (Polar, Wahoo, przyszli) — oba formularze mają `data-import-progress`
      // i to jest CAŁY haczyk podpięcia, bez gałęzi po providerze: kolejny
      // dostawca dodany do DeviceController dostaje ten pasek za darmo.
      //
      // Bez fetch()/ReadableStream (stara przeglądarka) formularz zostaje
      // NIETKNIĘTY — zwykły POST i przekierowanie (PRG), jak przed tą zmianą.
      // Backend: Utils\ProgressStream + Garmin/DeviceController::import(). ?>
<script>
(function () {
    var form = document.querySelector('form[data-import-progress]');
    if (!form || typeof fetch !== 'function' || !window.ReadableStream) { return; }

    form.addEventListener('submit', function (ev) {
        // Nic nie zaznaczono — niech leci zwykły POST, serwer i tak odmówi
        // czytelnym komunikatem (gblad=nic-nie-zaznaczono). Bez tego JS
        // otwierałby pasek postępu na zero pozycji.
        var total = form.querySelectorAll('input[name="aktywnosc[]"]:checked').length;
        if (!total) { return; }
        ev.preventDefault();

        var panel = document.createElement('div');
        panel.className = 'import-progress';
        panel.setAttribute('role', 'status');
        panel.setAttribute('aria-live', 'polite');
        panel.innerHTML =
            __('<p class="import-progress__phase">Łączymy się…</p>') +
            '<div class="import-progress__bar"><div class="import-progress__fill"></div></div>' +
            '<p class="import-progress__counts"></p>';
        form.parentNode.insertBefore(panel, form);
        form.style.display = 'none';

        var faza = panel.querySelector('.import-progress__phase');
        var pasek = panel.querySelector('.import-progress__fill');
        var licznik = panel.querySelector('.import-progress__counts');
        var stan = { imported: 0, duplicate: 0, rejected: 0 };

        // ZAZNACZONE NA RAZ MOŻE BYĆ WIĘCEJ NIŻ BATCH (zgłoszenie usera,
        // 2026-09-06: zaznaczone 79, pobrane tylko 15). Serwer i tak przepuszcza
        // maks. BATCH aktywności na jedno wywołanie mostu (patrz GarminImport::BATCH
        // / DeviceImport::BATCH — ochrona przed timeoutem), więc DOBIJAMY resztę
        // sami, wysyłając ten sam formularz ponownie. Bezpieczne bez zmian po
        // stronie serwera: każde wywołanie odsiewa już przepuszczone id po
        // rejestrze `device_activities` (Models\DeviceImport::knownIds), więc
        // powtórka tej samej zaznaczonej listy nie ściąga niczego dwa razy —
        // po prostu bierze kolejną porcję z tego, co zostało. `gotowe` i `pola`
        // liczą się przez WSZYSTKIE partie naraz, więc pasek i podsumowanie na
        // końcu pokazują sumę, a nie tylko ostatnie 15.
        var gotowe = 0;
        var pola = 0;

        function pokazLicznik() {
            pasek.style.width = (total ? Math.round(gotowe / total * 100) : 0) + '%';
            licznik.textContent = __('Gotowe {a}/{b} — dodane: {c}, duplikaty: {d}, błędy: {e}', {
                a: gotowe, b: total, c: stan.imported, d: stan.duplicate, e: stan.rejected
            });
        }

        function obsluzZdarzenie(event) {
            if (event.type === 'done') {
                // Kotwica `#dodaj` siedzi NA KOŃCU adresu, za wszystkimi
                // parametrami — odcinamy ją najpierw, żeby URLSearchParams nie
                // wciągnęło jej do wartości ostatniego parametru (`pozostalo`).
                var bezKotwicy = event.redirect.split('#');
                var kotwica = bezKotwicy.length > 1 ? '#' + bezKotwicy[1] : '';
                var adresBazowy = bezKotwicy[0];
                var pytajnik = adresBazowy.indexOf('?');
                var parametry = new URLSearchParams(pytajnik >= 0 ? adresBazowy.slice(pytajnik + 1) : '');

                if (parametry.get('ginfo') !== 'import') {
                    // Błąd (gblad=…) — kończymy od razu, bez dobijania kolejnych
                    // partii, tak samo jak wcześniej.
                    window.location.href = event.redirect;
                    return;
                }

                pola += parseInt(parametry.get('pola') || '0', 10);
                if (parseInt(parametry.get('pozostalo') || '0', 10) > 0) {
                    wyslijPartie();
                    return;
                }

                // Ostatnia partia: adres z serwera niesie liczby TYLKO z niej —
                // podmieniamy na sumę wszystkich partii, żeby ekran „Moje
                // przejazdy" pokazał, ile NAPRAWDĘ weszło.
                parametry.set('dodane', String(stan.imported));
                parametry.set('duplikaty', String(stan.duplicate));
                parametry.set('odrzucone', String(stan.rejected));
                parametry.set('pola', String(pola));
                window.location.href = (pytajnik >= 0 ? adresBazowy.slice(0, pytajnik) : adresBazowy)
                    + '?' + parametry.toString() + kotwica;
                return;
            }
            if (event.type === 'batch') {
                // Garmin: cała partia leci jednym wywołaniem mostu do Pythona
                // (jedno logowanie — patrz GarminImport), więc TEN etap nie da
                // się rozbić na pozycje. Żywy licznik startuje niżej.
                faza.textContent = event.total === 1
                    ? __('Łączymy się i ściągamy 1 przejazd z Garmina…')
                    : __('Łączymy się i ściągamy {n} przejazdów z Garmina…', { n: event.total });
                pokazLicznik();
                return;
            }
            if (event.type === 'item' && event.stage === 'downloading') {
                faza.textContent = __('Pobieramy: {nazwa}…', { nazwa: event.name || __('przejazd {id}', { id: event.id }) });
                pokazLicznik();
                return;
            }
            if (event.type === 'item' && event.stage === 'done') {
                if (stan[event.status] !== undefined) { stan[event.status]++; }
                gotowe++;
                faza.textContent = gotowe < total ? __('Zapisujemy przejazdy…') : __('Gotowe.');
                pokazLicznik();
            }
        }

        function wyslijPartie() {
            fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: { 'X-Progress-Stream': '1' }
            }).then(function (resp) {
                var typ = resp.headers.get('Content-Type') || '';
                if (!resp.body || typ.indexOf('application/x-ndjson') === -1) {
                    // Strumień się nie włączył (np. sesja wygasła i serwer od razu
                    // przekierował, zanim w ogóle zaczął strumieniować) — lądujemy
                    // tam, gdzie faktycznie trafiła odpowiedź.
                    window.location.href = resp.url;
                    return;
                }

                var reader = resp.body.getReader();
                var decoder = new TextDecoder();
                var bufor = '';

                // NDJSON: jeden obiekt JSON na linię — czytamy kawałkami, dzielimy
                // po \n, a niedokończoną resztkę zostawiamy na następny fragment.
                return (function czytaj() {
                    return reader.read().then(function (krok) {
                        if (krok.done) { return; }
                        bufor += decoder.decode(krok.value, { stream: true });
                        var linie = bufor.split('\n');
                        bufor = linie.pop();
                        linie.forEach(function (linia) {
                            if (!linia.trim()) { return; }
                            try { obsluzZdarzenie(JSON.parse(linia)); } catch (e) { /* urwana linia — pomiń */ }
                        });
                        return czytaj();
                    });
                })();
            }).catch(function () {
                // Sieć padła w trakcie: to, co zdążyło się zaimportować, JUŻ jest
                // zapisane po stronie serwera (rejestr per aktywność), więc
                // bezpieczniej po prostu odświeżyć, niż zgadywać, gdzie stanęło.
                window.location.reload();
            });
        }

        wyslijPartie();
    });
})();
</script>
