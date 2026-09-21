<?php
// cron.php
// Do podpięcia pod prawdziwy harmonogram systemowy (Windows Task Scheduler
// lokalnie, cron na hostingu). Ten sam mechanizm
// (Models\Event::processCompletions()) jest też wywoływany oportunistycznie
// przy każdym wejściu na stronę główną (web/routes.php), więc cron.php nie
// jest ściśle wymagany do działania — jest "prawdziwym" rozwiązaniem na
// wypadek zbyt małego ruchu na stronie, żeby nie polegać wyłącznie na wizytach.
//
// ============================================================================
// DWA WPISY W CRONTABIE, NIE JEDEN (2026-09-12)
// ============================================================================
// Skrypt przyjmuje GRUPĘ zadań jako pierwszy argument:
//
//   APP_ENV=prod php /pełna/ścieżka/cron.php noc      # 3:15 — utrzymanie, przeliczenia, maile
//   APP_ENV=prod php /pełna/ścieżka/cron.php dzien    # 17:15 — zachęty (push + mail)
//   APP_ENV=prod php /pełna/ścieżka/cron.php tlumaczenia  # co 10 min — kolejka tłumaczeń AI
//   php cron.php                                      # WSZYSTKO (dev, ręcznie)
//
// Po co rozdział: od migr. 081 zachęty nie wychodzą PUSHEM między 22:00 a 7:00
// (`NotificationGate::CISZA_OD`/`CISZA_DO`). Przebieg o północy nie wyśle więc
// ani jednego pusha o skarbie czy nowości w okolicy — i nie jest to usterka,
// tylko działanie zgodne z zamiarem. Zadania nocne (przeliczenia, dopasowania)
// potrzebują nocy, zachęty potrzebują popołudnia: ludzie planują jazdę
// wieczorem, nie o świcie. Do 2026-09-12 wszystko siedziało w jednym wpisie
// i jedna z tych dwóch potrzeb musiała przegrać.
//
// CO GDZIE TRAFIŁO I DLACZEGO AKURAT TAK:
//   - `noc` dostała WSZYSTKIE maile, w tym dopasowania i rekomendacje.
//     Cisza nocna dotyczy WYŁĄCZNIE pusha (`wolnoZachecac()` sprawdza
//     `$kanal === PUSH`), a `DOPASOWANIE` świadomie nie istnieje w kanale push
//     (patrz nota przy `NotificationGate::ZGODA`) — te kanały są mailowe
//     w całości, więc nocny przebieg im nie szkodzi.
//   - `PreferenceNotifier` ZOSTAJE w grupie nocnej razem z
//     `DerivedPreference::recomputeAll()`, bo kanał zwykły korzysta z profilu
//     Z TEGO SAMEGO PRZEBIEGU. Przeniesienie go na popołudnie zabrałoby tę
//     gwarancję po cichu, nic w zamian nie dając (mail i tak wychodzi o każdej
//     porze).
//   - `dzien` to dokładnie te dwa zadania, które mają połowę pushową:
//     `runTreasuresNearby()` i `runNewInRegion()`.
//
// ============================================================================
// UWAGA — APP_ENV NIE JEST TU WYMUSZONE, W ODRÓŻNIENIU OD SKRYPTÓW
// backfill_*.php/run_migrations.php (2026-09-10). Te są WYŁĄCZNIE
// produkcyjne, więc mają `putenv('APP_ENV=prod')` na sztywno. TEN plik
// celowo działa na obu środowiskach (dev: Windows Task Scheduler lokalnie,
// prod: cron na hostingu) — hardcode'owanie 'prod' złamałoby lokalne
// uruchamianie. Zamiast tego środowisko musi ustawić APP_ENV SAM, w
// definicji zadania cron:
//
//   crontab (hosting):  APP_ENV=prod php /pełna/ścieżka/do/cron.php noc
//     (albo linia `APP_ENV=prod` na górze całego pliku crontab — obejmie
//     też inne zadania w tym samym pliku)
//   Windows Task Scheduler (dev): bez APP_ENV w akcji — brak zmiennej
//     domyślnie spada na 'dev' w core/bootstrap.php, czyli dokładnie tam,
//     gdzie ma być na tej maszynie.
//
// `.htaccess` (`SetEnv APP_ENV prod`) NIE DZIAŁA dla crona w ogóle — to
// ustawienie obowiązuje wyłącznie żądania przechodzące przez Apache, a cron
// odpala PHP bezpośrednio z CLI, z pominięciem serwera WWW. Jeśli ten skrypt
// jest już wpięty w harmonogram na hostingu BEZ `APP_ENV=prod` w poleceniu,
// każde dotychczasowe uruchomienie łączyło się z bazą 'dev' (najpewniej
// kończąc się dokładnie tym błędem dostępu, co przy backfill_regions.php) —
// czyli zadania niżej, w tym maile dopasowań i powiadomienia push, mogły
// nigdy realnie nie zadziałać na produkcyjnych danych.
//
// DLATEGO PIERWSZA LINIA WYJŚCIA MÓWI, GDZIE JESTEŚMY (2026-09-12) — ten sam
// zwyczaj co w `run_migrations.php`. Zły APP_ENV jest wtedy widoczny w logu
// crona od razu, a nie dopiero po tym, jak ktoś zauważy, że maile nie doszły.

// TYLKO Z WIERSZA POLECEŃ (2026-09-12). `.htaccess` blokuje dostępem Apache
// `run_migrations.php`, `backfill_elevation_profiles.php` i `tiles.php`, ale
// TEGO pliku nie blokował — a jest w katalogu serwowanym, więc
// `https://.../cron.php` był publicznym spustem dziesięciu zadań, w tym
// wysyłki maili i pushy. Dziś ratował przypadek: bez `APP_ENV` żądanie HTTP
// spada na konfigurację 'dev', której na produkcji nie ma, więc kończyło się
// błędem bazy. To jest zabezpieczenie przez pomyłkę, nie przez projekt —
// i przestałoby działać w tej samej chwili, w której ktoś ustawiłby APP_ENV
// na poziomie serwera. Blokada Apache jest dokładana OSOBNO w .htaccess;
// ta linia jest drugim zamkiem, bo `.htaccess` bywa nadpisany przy wdrożeniu.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/core/bootstrap.php';

const CRON_GRUPY = ['wszystko', 'noc', 'dzien', 'tlumaczenia'];

$grupa = $argv[1] ?? 'wszystko';
if (!in_array($grupa, CRON_GRUPY, true)) {
    fwrite(STDERR, "Nieznana grupa zadań: '$grupa'. Dozwolone: " . implode(', ', CRON_GRUPY) . "\n");
    exit(2);
}

echo 'APP_ENV=' . APP_ENV . ', baza=' . APP_CONFIG['db']['name'] . ', grupa=' . $grupa . "\n";

// ════════════════════════════════════════════════════════════════════════════
// JEDEN PRZEBIEG NARAZ (2026-09-12)
// ════════════════════════════════════════════════════════════════════════════
// `DerivedPreference::recomputeAll()` odtwarza WSZYSTKIE profile wynikające za
// każdym uruchomieniem, a `Emblem::sync()` chodzi po wszystkich trasach
// z emblematem — koszt rośnie z bazą, więc przebieg, który dziś trwa sekundy,
// kiedyś przestanie kończyć się przed następnym. Dwa nachodzące na siebie
// przebiegi to podwójna praca i wyścig na wysyłce: `NotificationGate::claim()`
// broni się kluczem unikalnym, ale dopiero PO tym, jak oba przebiegi policzyły
// swoje.
//
// Blokada jest PER GRUPA, nie globalna: zablokowany (albo wiszący) przebieg
// nocny nie ma prawa zabrać popołudniu jego pory — to dwa niezależne
// harmonogramy i jedna wspólna blokada sprzęgłaby je bez potrzeby.
//
// `flock` bez `LOCK_NB` czekałby w kolejce, a tego akurat nie chcemy: lepiej,
// żeby drugi przebieg powiedział „już trwa" i skończył, niż żeby stał godzinę
// i wystartował o nieswojej porze. Kod wyjścia 3 odróżnia to od awarii (1)
// i od złego argumentu (2). Uchwyt zwalnia się sam z końcem procesu.
//
// `flock` na Windowsie działa (dev też jest chroniony), ale na sieciowych
// systemach plików bywa zawodny — dlatego NIEMOŻNOŚĆ założenia blokady nie
// zatrzymuje przebiegu, tylko go odnotowuje. Blokada ma chronić przed
// nachodzeniem, a nie stać się nowym powodem, dla którego cron nie działa.
$plikBlokady = __DIR__ . '/storage/cron-' . $grupa . '.lock';
$uchwyt = @fopen($plikBlokady, 'c');
if ($uchwyt === false) {
    fwrite(STDERR, "Uwaga: nie udało się otworzyć $plikBlokady — przebieg idzie BEZ blokady.\n");
} elseif (!flock($uchwyt, LOCK_EX | LOCK_NB)) {
    echo "Przebieg grupy '$grupa' już trwa — kończę bez działania.\n";
    exit(3);
}

$noc   = $grupa === 'noc'   || $grupa === 'wszystko';
$dzien = $grupa === 'dzien' || $grupa === 'wszystko';
$tlumaczenia = $grupa === 'tlumaczenia' || $grupa === 'wszystko';

// KOLEJKA TŁUMACZEŃ (driver `ai`, 2026-09-17) — model językowy odpowiada
// sekundami, więc strona zapisuje nieprzetłumaczony tekst jako `pending`
// i pokazuje oryginał, a tłumaczy go ten przebieg. Osobna grupa, bo ma chodzić
// często (co ~10 min), a nie raz na dobę; blokada per grupa pilnuje, żeby
// wolny przebieg nie nachodził na następny. Przy innym driverze kolejka jest
// pusta i przebieg kończy się jednym zapytaniem.
if ($tlumaczenia && count(Core\Lang::supported()) > 1) {
    $kolejka = Models\ContentTranslation::processQueue((int) (getenv('TRANSLATE_QUEUE_LIMIT') ?: 200));
    echo $kolejka['translated'] . ' tekst(ów) przetłumaczono'
        . ($kolejka['failed'] ? ' — tłumacz nie odpowiedział, reszta poczeka' : '')
        . ', w kolejce: ' . Models\ContentTranslation::pendingCount() . ".\n";
}

if ($noc) {
    $completed = Models\Event::processCompletions();
    echo count($completed) . " event(y) oznaczone jako zakończone.\n";

    $expired = Models\Event::expireStalePendingPayments();
    echo count($expired) . " rezerwacj(a/e/i) wygasło z braku wpłaty.\n";

    $removedGpxTemp = Utils\Upload::cleanupOrphanedGpxTemp();
    echo $removedGpxTemp . " porzucon(y/ych) plik(ów) tymczasowych GPX usunięto.\n";

    // Etap 2 (dopasowania), krok 5 — patrz Models\MatchEngine::runNightlyConsolidation().
    // Świadomie TYLKO tutaj (nie oportunistycznie na stronie głównej jak wyżej) —
    // to zadanie wysyła maile, więc nie powinno się uruchamiać częściej niż raz
    // na noc niezależnie od ruchu na stronie.
    $matched = Models\MatchEngine::runNightlyConsolidation();
    echo count($matched) . " powiadomien(ie/ia) o większym dopasowaniu wysłano.\n";

    // Etap 3 (preferencje), krok 5 — warstwa wynikająca, patrz Models\DerivedPreference.
    // Odtwarzana W CAŁOŚCI co uruchomienie (nigdy przyrostowo), więc kolejność
    // względem powyższych zadań nie ma znaczenia.
    $recomputed = Models\DerivedPreference::recomputeAll();
    echo count($recomputed) . " profil(e/i) wynikając(y/e) przeliczono.\n";

    $purgedLog = Models\RecommendationLog::purgeOld();
    echo $purgedLog . " stary(ch) wpis(ów) dziennika rekomendacji usunięto.\n";

    // Etap 3 (preferencje), krok 4 i 7 — dwa NIEZALEŻNE kanały powiadomień,
    // patrz Models\PreferenceNotifier. Świadomie PO przeliczeniu warstwy
    // wynikającej wyżej — kanał zwykły korzysta z profilu z tego samego przebiegu.
    // To jest powód, dla którego oba zostały w grupie NOCNEJ (patrz nagłówek):
    // rozdzielenie ich od `recomputeAll()` zabrałoby tę gwarancję.
    $aspirational = Models\PreferenceNotifier::runAspirational();
    echo count($aspirational) . " powiadomien(ie/ia) aspiracyjnych wysłano.\n";

    $regular = Models\PreferenceNotifier::runRegular();
    echo count($regular) . " powiadomien(ie/ia) zwykłych o dopasowaniu wysłano.\n";

    // EMBLEMATY (migr. 087, 2026-09-11) — przyznanie wszystkim, którzy domknęli
    // trasę z emblematem. Po wgraniu śladu robi to już `RiderActivity` dla
    // pojedynczej osoby; ten przebieg jest potrzebny z innego powodu niż
    // „na wszelki wypadek": pokrycie potrafi dojść do 100% BEZ nowego przejazdu.
    // Wystarczy, że admin przypnie emblemat do trasy, którą ktoś ma już całą,
    // albo podmieni jej przebieg na krótszy. Bez tego kroku taki człowiek czekałby
    // na emblemat do swojej następnej jazdy.
    //
    // Zapytanie jest ZBIOROWE (jedno na trasę, nie jedno na użytkownika), więc
    // koszt rośnie z liczbą tras z emblematem, a nie z liczbą kont.
    $emblematy = Models\Emblem::sync();
    echo $emblematy . " emblemat(ów) nadano.\n";

    // TŁUMACZENIA TREŚCI (wielojęzyczność, migr. 089) — kasowanie tłumaczeń
    // tekstów, których już nie ma (edytowany opis, skasowany profil). Tabela
    // trzyma cudze treści, więc nie może rosnąć o każdą wersję każdego opisu;
    // ręczne poprawki żyją jeszcze 30 dni jako podpowiedź w formularzu korekty.
    $tlumaczenia = Models\ContentTranslation::sweep();
    echo $tlumaczenia . " nieaktualn(ych) tłumaczeń skasowano.\n";
}

if ($dzien) {
    // Etap 8 (push apki mobilnej) — patrz Models\PushNotifier. Świadomie TYLKO
    // tutaj (nie oportunistycznie na stronie głównej), tą samą zasadą co
    // dopasowania wyżej: to zadanie wysyła powiadomienia, więc nie powinno
    // odpalać się częściej niż raz na dobę niezależnie od ruchu na stronie.
    //
    // TO JEST CAŁA ZAWARTOŚĆ GRUPY DZIENNEJ i to nie przypadek: oba zadania
    // niżej mają połowę PUSHOWĄ, a ta nie wyjdzie między 22:00 a 7:00
    // (`NotificationGate::ciszaNocna`). Połowa mailowa wychodzi o każdej porze
    // od Etapu 1c (migr. 082), więc nocny przebieg nie znaczył „zero
    // powiadomień" — znaczył „zero pushy", co jest równie niedobre, tylko
    // trudniejsze do zauważenia.
    $pushed = Models\PushNotifier::runTreasuresNearby();
    echo count($pushed) . " powiadomien(ie/ia) o nowych skarbach w okolicy wysłano (push i/lub mail).\n";

    // Etap 1b programu zachęt (2026-09-11) — nowa TRASA albo nowy WYJAZD w regionie,
    // w którym ktoś ma odkryte pola. Ta sama definicja okolicy co przy skarbach
    // (region administracyjny, nie promień GPS) i ta sama bramka, więc obowiązuje
    // ten sam budżet: zachęty nie sumują się ponad 2 na tydzień na kanał.
    $nowosci = Models\PushNotifier::runNewInRegion();
    echo count($nowosci) . " powiadomien(ie/ia) o nowościach w okolicy wysłano (push i/lub mail).\n";
}
