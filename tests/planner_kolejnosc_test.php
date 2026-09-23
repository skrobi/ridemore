<?php
// tests/planner_kolejnosc_test.php
// ROUTE PLANNER — MODEL TRASY I KOLEJNOŚĆ WAYPOINTÓW (zgłoszenie 2026-09-18:
// przeciągnięty odcinek A–B dawał START → A → NOWY → B → CEL → NOWY).
//
// Model żyje w JS (assets/js/planner/route-model.js), więc test uruchamia
// PRAWDZIWY plik w Node przez tests/planner_kolejnosc.js — te same wywołania
// co UI: przeciągnięcie odcinka = insertBetween(id poprzedniego, id
// następnego), przesunięcie markera = moveWaypoint(indeks po id) — i sprawdza
// zarówno tablicę waypointów, jak i to, co idzie do /api/planer/oblicz
// (routingPayload). Samej interakcji myszą (fantomowy `click` po mouseup,
// chwytanie pinezki) Node nie odtworzy — ta część jest zweryfikowana na żywo
// w przeglądarce, opis w md/features.md (Route Planner).

function planner_kolejnosc_wynik(): array
{
    static $wynik = null;
    if ($wynik === null) {
        $linie = [];
        $kod = 0;
        exec('node ' . escapeshellarg(__DIR__ . '/planner_kolejnosc.js') . ' 2>&1', $linie, $kod);
        $dane = json_decode(implode("\n", $linie), true);
        if ($kod !== 0 || !is_array($dane) || isset($dane['error'])) {
            t_fail('Node nie uruchomił modelu trasy z assets/js/planner/route-model.js (kod ' . $kod . '): '
                . implode(' | ', array_slice($linie, 0, 5)));
        }
        $wynik = $dane;
    }
    return $wynik;
}

/** Krok sekwencji po nazwie operacji (kolejność kroków i tak jest sprawdzana osobno). */
function planner_kolejnosc_krok(string $sekwencja, int $nr): array
{
    return planner_kolejnosc_wynik()[$sekwencja][$nr];
}

t_test('TEST 1: przeciągnięcie odcinka A–B wstawia NOWY między A i B, nie na koniec', function () {
    $k = planner_kolejnosc_krok('akceptacja', 0);
    t_same(['START', 'A', 'B', 'CEL'], $k['before']['order'], 'przed');
    t_same(['START', 'A', 'NOWY', 'B', 'CEL'], $k['after']['order'], 'po: NOWY na indeksie 2');
    t_same(['start', 'via', 'via', 'via', 'end'], $k['after']['types'], 'typy wynikają z pozycji');
    t_same(['START', 'A', 'NOWY', 'B', 'CEL'], $k['after']['payloadOrder'], 'dokładnie ta kolejność idzie do routingu');
    t_same(4, $k['after']['segmentCount'], 'odcinków = punktów - 1');
});

t_test('TEST 2: przesunięcie A zmienia tylko jego współrzędne, bez nowego punktu', function () {
    $k = planner_kolejnosc_krok('akceptacja', 1);
    t_same($k['before']['ids'], $k['after']['ids'], 'te same punkty (id), ta sama kolejność');
    t_same(['START', 'A', 'NOWY', 'B', 'CEL'], $k['after']['order'], 'kolejność bez zmian');
    t_same([50.31, 20.31], $k['after']['coords'][1], 'A_new na indeksie 1');
    t_same([50.31, 20.31], $k['after']['payloadCoords'][1], 'routing dostaje A_new');
});

t_test('TEST 3: przesunięcie START — zwykły punkt, tylko typem specjalny', function () {
    $k = planner_kolejnosc_krok('akceptacja', 2);
    t_same($k['before']['ids'], $k['after']['ids'], 'bez nowego punktu');
    t_same([49.91, 19.91], $k['after']['coords'][0], 'START_new na indeksie 0');
    t_same('start', $k['after']['types'][0], 'dalej START');
    t_same([50.31, 20.31], $k['after']['coords'][1], 'A_new z poprzedniego kroku zachowane');
});

t_test('TEST 4: przesunięcie CEL', function () {
    $k = planner_kolejnosc_krok('akceptacja', 3);
    t_same($k['before']['ids'], $k['after']['ids'], 'bez nowego punktu');
    t_same([50.91, 20.91], $k['after']['coords'][4], 'CEL_new na ostatnim indeksie');
    t_same('end', $k['after']['types'][4], 'dalej CEL');
    t_same([49.91, 19.91], $k['after']['coords'][0], 'START_new zachowany');
});

t_test('TEST 5: przeciągnięcie odcinka NOWY–B wstawia NOWY2 między NOWY i B', function () {
    $k = planner_kolejnosc_krok('akceptacja', 4);
    t_same(['START', 'A', 'NOWY', 'NOWY2', 'B', 'CEL'], $k['after']['order'], 'NOWY2 na indeksie 3');
    t_same(['START', 'A', 'NOWY', 'NOWY2', 'B', 'CEL'], $k['after']['payloadOrder'], 'routing w tej kolejności');
});

t_test('TEST 6: przesunięcie NOWY2', function () {
    $k = planner_kolejnosc_krok('akceptacja', 5);
    t_same($k['before']['ids'], $k['after']['ids'], 'bez nowego punktu');
    t_same([50.51, 20.51], $k['after']['coords'][3], 'NOWY2_new na indeksie 3');
});

t_test('TEST 7: po wszystkich operacjach routing dostaje dokładnie kolejność i współrzędne z tablicy', function () {
    $k = planner_kolejnosc_krok('akceptacja', 5);
    t_same(['START', 'A', 'NOWY', 'NOWY2', 'B', 'CEL'], $k['after']['order'], 'tablica waypointów');
    t_same($k['after']['order'], $k['after']['payloadOrder'], 'payload = tablica (kolejność)');
    t_same($k['after']['coords'], $k['after']['payloadCoords'], 'payload = tablica (współrzędne)');
    t_same(['start', 'via', 'via', 'via', 'via', 'end'], $k['after']['types'], 'jeden START, jeden CEL');
    t_same([49.91, 19.91], $k['after']['payloadCoords'][0], 'START_new');
    t_same([50.31, 20.31], $k['after']['payloadCoords'][1], 'A_new');
    t_same([50.51, 20.51], $k['after']['payloadCoords'][3], 'NOWY2_new');
    t_same([50.91, 20.91], $k['after']['payloadCoords'][5], 'CEL_new');
});

t_test('Sekwencja z punktu 7: START → A → B → C → CEL, edycje po kolei', function () {
    $kroki = planner_kolejnosc_wynik()['sekwencja'];
    t_same(['START', 'A', 'B', 'C', 'CEL'], $kroki[0]['after']['order'], 'przesunięcie B nie zmienia kolejności');
    t_same(['START', 'A', 'B', 'D', 'C', 'CEL'], $kroki[1]['after']['order'], 'D między B_new i C');
    $koniec = $kroki[3]['after'];
    t_same(['START', 'A', 'B', 'D', 'C', 'CEL'], $koniec['payloadOrder'], 'routing po wszystkich edycjach');
    t_same([49.8, 19.8], $koniec['payloadCoords'][0], 'START_new');
    t_same([50.12, 20.12], $koniec['payloadCoords'][2], 'B_new');
    t_same([50.17, 20.17], $koniec['payloadCoords'][3], 'D_new');
});

t_test('Zły indeks wstawienia wybucha, zamiast po cichu dopisać punkt na koniec', function () {
    $w = planner_kolejnosc_wynik()['zlyIndeks'];
    t_same(['RangeError', 'RangeError', 'RangeError', 'RangeError', 'RangeError'], $w['errors'], '7, -1, 1.5, brak, "2"');
    t_same('RangeError', $w['moveError'], 'przesunięcie nieistniejącego punktu');
    t_same(['START', 'A', 'B', 'CEL'], $w['after']['order'], 'lista nietknięta');
});

t_test('Para, która przestała sąsiadować, nie dostaje punktu „gdzieś"', function () {
    $w = planner_kolejnosc_wynik()['nieaktualnaPara'];
    t_null($w['returned'], 'insertBetween dla A–B po wstawieniu NOWY zwraca null');
    t_same(['START', 'A', 'NOWY', 'B', 'CEL'], $w['after']['order'], 'bez drugiego wstawienia');
});

t_test('Wstawianie z jawnym indeksem: klik w mapę = koniec, indeks 0 = przed START', function () {
    $w = planner_kolejnosc_wynik()['klikIPoczatek'];
    t_same(['PRZED', 'START', 'CEL', 'KLIK'], $w['order'], 'kolejność');
    t_same(['start', 'via', 'via', 'end'], $w['types'], 'START/CEL przechodzą na nowe skrajne punkty');
});

t_test('Odcinki zawsze wyrównane do par punktów; odpowiedź dla starej kolejności odrzucona', function () {
    $w = planner_kolejnosc_wynik()['odcinki'];
    t_same([false, true, true, false], $w['afterInsert']['pending'], 'tylko dwa nowe odcinki czekają na przeliczenie');
    foreach ($w['afterInsert']['ends'] as $i => $konce) {
        t_same($w['afterInsert']['waypoints'][$i], $konce[0], "odcinek {$i} zaczyna się w punkcie {$i}");
        t_same($w['afterInsert']['waypoints'][$i + 1], $konce[1], "odcinek {$i} kończy się w punkcie " . ($i + 1));
    }
    t_true($w['afterInsert']['bToCelKept'], 'nietknięty odcinek B→CEL zachował policzoną geometrię');
    t_false($w['afterInsert']['complete'], 'trasa niekompletna → zapis zablokowany');
    t_false($w['staleApplied'], 'odpowiedź policzona przed wstawieniem odrzucona');
    t_same([false, true, true, false], $w['afterStale']['pending'], 'stara odpowiedź niczego nie nadpisała');
    t_true($w['freshApplied'], 'odpowiedź dla bieżącej wersji przyjęta');
    t_true($w['complete'], 'po niej trasa kompletna');
});

t_test('Źródła trasy: zmiana źródeł przelicza odcinki, do czasu wyniku zostaje dotychczasowa geometria', function () {
    $w = planner_kolejnosc_wynik()['kontekst'];
    t_false($w['same'], 'ten sam kontekst co był — nic do przeliczania');
    t_true($w['znane'], 'zaznaczenie „Znane trasy" + przełącznik — zmiana');
    t_same([true, true], $w['afterKnown']['pending'], 'oba odcinki czekają na przeliczenie');
    t_true($w['afterKnown']['keptGeometry'], 'rysują się dotychczasową geometrią, nie prostą');
    t_true($w['afterKnown']['versionBumped'], 'stara odpowiedź serwera nie nadpisze nowych źródeł');
    t_same(['mine' => false, 'known' => true, 'community' => false], $w['afterKnown']['payload']['sources'], 'routing dostaje zaznaczone źródła');
    t_true($w['afterKnown']['payload']['autoJoin'], 'i stan przełącznika dołączania');
    t_null($w['afterKnown']['payload']['base'], 'bez bazy');
});

t_test('Baza (konkretna trasa / GPX): wygrywa ze źródłami, zaznaczanie źródeł zmienia tylko mapę', function () {
    $w = planner_kolejnosc_wynik()['kontekst'];
    t_true($w['withBase'], 'wybór bazy przelicza trasę');
    t_false($w['sourcesUnderBase'], 'przy bazie zaznaczanie źródeł i przełącznik nie zmieniają kontekstu');
    t_true($w['versionUnderBase'], 'wersja modelu bez zmian — trasa się nie przelicza');
    t_true($w['completeUnderBase'], 'trasa dalej kompletna (zapis możliwy)');
    t_eq('VeloDunajec', $w['payloadBase']['base']['label'], 'routing dostaje bazę z nazwą');
    t_count(3, $w['payloadBase']['base']['points'], 'i z jej punktami');
    t_same(['START', 'A', 'CEL'], $w['payloadBase']['order'], 'kolejność punktów bez zmian');
});

t_test('Typ roweru: zmiana zawsze przelicza trasę (własny profil silnika), powód wyboru zostaje przy odcinku', function () {
    $w = planner_kolejnosc_wynik()['profil'];
    t_true($w['variantKept'], '`variant` z odpowiedzi zostaje przy SWOIM odcinku (pod komunikat w liście)');
    t_true($w['withJoin'], 'zmiana profilu przy włączonym przełączniku = przeliczenie');
    t_true($w['bumped'], 'stara odpowiedź (inny profil) nie nadpisze nowej');
    t_same('mtb', $w['payloadProfile'], 'routing dostaje profil');
    t_true($w['withoutJoin'], 'bez warstwy też — typ może mieć inny profil silnika');
    t_false($w['versionOff'], 'nowa wersja modelu');
    t_false($w['sameAgain'], 'ten sam typ drugi raz — nic do przeliczania');
    t_same('', $w['bogusProfile'], 'kod spoza wzorca odrzucony (serwer weźmie pierwszy typ ze słownika)');
});

t_test('Skarb „Po drodze" wstawia się między końce najbliższego odcinka', function () {
    $w = planner_kolejnosc_wynik()['skarb'];
    t_same(1, $w['seg'], 'najbliżej drugiego odcinka (A → CEL)');
    t_same(['START', 'A', 'SKARB', 'CEL'], $w['order'], 'skarb między A i CEL, CEL dalej na końcu');
    t_same(['start', 'via', 'treasure', 'end'], $w['types'], 'typ skarbu zachowany');
});

t_test('Usuwanie: START/CEL przechodzą na nowe skrajne punkty', function () {
    $w = planner_kolejnosc_wynik()['usuwanie'];
    t_same(['A', 'B', 'CEL'], $w['afterStart']['order'], 'bez START');
    t_same(['start', 'via', 'end'], $w['afterStart']['types'], 'A jest teraz START');
    t_same(['A', 'B'], $w['afterEnd']['order'], 'bez CEL');
    t_same(['start', 'end'], $w['afterEnd']['types'], 'B jest teraz CEL');
});

t_test('Limit 25 punktów: nadmiarowy punkt odrzucony, nie dopisany', function () {
    $w = planner_kolejnosc_wynik()['limit'];
    t_null($w['returned'], 'insertWaypoint zwraca null');
    t_same(25, $w['count'], 'dalej 25');
    t_same('P24', $w['last'], 'ostatni bez zmian');
});

t_test('Widoczna pinezka punktu jest uchwytem markera (nie przepuszcza myszy do mapy)', function () {
    $css = (string) file_get_contents(CORE_PATH . '/../assets/css/style.css');
    t_true((bool) preg_match('/\.planner-map-pin\{[^}]*\}/', $css, $m), 'reguła .planner-map-pin istnieje');
    t_false(str_contains($m[0], 'pointer-events:none'), 'bez pointer-events:none — inaczej złapanie punktu przesuwa mapę');
    t_false(str_contains($m[0], 'translate'), 'bez przesunięcia — pinezka ma leżeć na polu markera');
});

// --- Kreator (Etap A, tasks/active/planer-uproszczona-architektura.md) -----

t_test('Kreator: bez startu i celu nie ma zapytania; payload niesie punkty, typ roweru i styl', function () {
    $k = planner_kolejnosc_wynik()['kreator'];
    t_same(['start', 'end'], $k['empty']['missing'], 'pusty kreator: brakuje startu i celu, w kolejności pytań');
    t_false($k['empty']['ready'], 'pusty kreator nie jest gotowy');
    t_null($k['empty']['payload'], 'bez odpowiedzi nie ma zapytania');
    t_same(['end'], $k['afterStart'], 'po starcie brakuje tylko celu');
    t_same([
        'csrf_token' => 'tok',
        'start' => ['lat' => 50.06, 'lng' => 19.94],
        'end' => ['lat' => 50.03, 'lng' => 19.83],
        'profile' => 'gravel',
        'style' => 'fast',
    ], $k['payload'], 'zapytanie do /api/planer/generuj (współrzędne jako liczby)');
});

t_test('Kreator: zły styl, typ roweru i punkt nie przechodzą', function () {
    $k = planner_kolejnosc_wynik()['kreator'];
    t_same('proven', $k['badStyle'], 'nieznany styl → domyślny „Sprawdzone”');
    t_same('', $k['badProfile'], 'zły kod typu roweru → pusty (serwer weźmie pierwszy typ)');
    t_null($k['badPoint']['end'], 'szerokość 91° → punkt wyczyszczony, nie zostaje stary');
    t_same(['end'], $k['badPoint']['missing'], 'po złym punkcie znowu brakuje celu');
    t_true($k['thrown'], 'nieznany rodzaj punktu to błąd w kodzie — wybucha');
    t_same(['proven', 'fast'], $k['styles'], 'style w kreatorze = PlannerController::STYLES (bez „Odkrywczo” do Etapu D)');
});

t_test('Kreator: wynik z /api/planer/generuj ląduje w zwykłym modelu trasy i dalej się edytuje', function () {
    $w = planner_kolejnosc_wynik()['kreatorWynik'];
    t_true($w['applied'], 'odpowiedź przyjęta dla bieżącej wersji modelu');
    t_true($w['complete'], 'trasa kompletna — da się ją od razu zapisać');
    t_false($w['sameContext'], 'te same kontrolki co w kreatorze nie wywołują przeliczenia');
    t_true($w['stillComplete'], 'trasa nadal kompletna');
    t_same(['start', 'via', 'end'], $w['types'], 'ręcznie wstawiony punkt między START i CEL');
    t_same([true, true], $w['pendingAfterInsert'], 'przeliczają się tylko przecięte odcinki');
    t_same(['mine' => false, 'known' => true, 'community' => true], $w['payloadSources'], 'edycja liczy się z tymi samymi źródłami');
    t_true($w['payloadJoin'], 'i z tą samą warstwą Ridemore');
});
