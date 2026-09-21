<?php
// core/discovery.php
// Etap 8 — WSZYSTKIE liczby punktacji Discovery w jednym miejscu (§33).
//
// Zasada: żadna wartość punktowa nie ma prawa pojawić się nigdzie indziej w
// kodzie. Balansowanie systemu ma być edycją tego pliku, nie przeszukiwaniem
// serwisu za literałami. Czyta stąd wyłącznie Models\DiscoveryScoring.
//
// Zmiana tych wartości NIE przelicza wstecz przyznanych punktów — historia
// naliczeń siedzi w point_transactions (migr. 043) i jest niezmienna. To
// celowe: gdyby zmiana konfiguracji przepisywała przeszłość, nikt nie mógłby
// jej bezpiecznie tknąć, a rejestr audytowalny przestałby cokolwiek znaczyć.
return [
    // -----------------------------------------------------------------
    // RIDE — podstawowa wartość PRZEJAZDU (Etap 8A)
    // -----------------------------------------------------------------
    // Jedyna kategoria, którą wolno zdobyć POWTÓRNIE za tę samą drogę —
    // bo jazda naprawdę się odbyła, choćby po znanej pętli. Bonusy za
    // odkrycie i za trasę są zabezpieczone kluczem unikalnym w
    // point_transactions i tam powtórka nie przejdzie (zasada FIRST
    // DISCOVERY > REPEAT VISIT).
    //
    // Świadomie NIE ma tu czasu ani prędkości: Utils\Gpx::parse() nie czyta
    // znaczników czasu, a przy śladzie wgranym przez organizatora czas i tak
    // nie byłby czasem uczestnika. Gdy dojdą przejazdy solo z własnym
    // plikiem, czas będzie można dołożyć jako kolejny składnik bez ruszania
    // rejestru.
    'ride' => [
        // 100 km = 500 pkt, weekendowe 200 km = 1 000. Liczby mają się
        // czytać dla człowieka, a nie wyglądać jak licznik.
        'points_per_km' => 5,

        // Wysiłek, nie tylko odległość: płaskie 100 km to 500 pkt, górskie
        // 100 km z 2 000 m podjazdów — 700. Uznanie dla trudniejszej trasy
        // bez robienia z nizinnych rowerzystów gorszej kategorii.
        'points_per_100m_elevation' => 10,

        // Dźwignia antyfarmingowa NA WYPADEK, gdyby ktoś zaczął wgrywać
        // dziesięć przejazdów dziennie. null = wyłączona i tak ma zostać,
        // dopóki nie zobaczymy realnego nadużycia — brief dopuszcza
        // powtarzalne punkty za prawdziwą jazdę, a limit nałożony „na wszelki
        // wypadek" karałby przede wszystkim ludzi, którzy po prostu dużo
        // jeżdżą. Stosowane w Models\RiderActivity przy naliczaniu.
        'daily_cap' => null,
    ],

    // -----------------------------------------------------------------
    // DISCOVERY — za odkrywanie nowych obszarów (§13)
    // -----------------------------------------------------------------
    'discovery' => [
        // Punkty za każde pole odkryte PIERWSZY RAZ przez tę osobę. Drugi
        // przejazd tą samą drogą nie daje nic — pilnuje tego klucz główny
        // discovery_cells, nie ten plik.
        //
        // Kalibracja przy polu ok. 500 m: typowa 60-kilometrowa trasa dotyka
        // ok. 120 pól, więc w NOWYM terenie da ok. 1 200 pkt Discovery przy
        // ok. 380 pkt za sam przejazd. Odkrywanie jest wtedy ok. 4× cenniejsze
        // od jazdy po znanym — i o to chodzi, przy zachowaniu zasady, że
        // codzienna runda po swojej pętli nadal coś daje.
        'points_per_new_cell' => 10,
    ],

    // -----------------------------------------------------------------
    // EXPLORATION — za odkrycia szczególnie wartościowe (§9)
    // W interfejsie: „NOWY TEREN". Patrz nota o nazewnictwie niżej.
    // -----------------------------------------------------------------
    // Świadomie DWIE reguły, nie dziesięć. §9 mówi wprost: najpierw podstawowe
    // odkrywanie ma działać stabilnie. Każda kolejna reguła to kolejny sposób,
    // żeby wynik przestał być zrozumiały dla człowieka.
    'exploration' => [
        // Pole, którego przed nami nie odkrył NIKT w ridemore.
        //
        // OBNIŻONE Z 15 DO 5 (decyzja usera 2026-08-13). Powód nie jest
        // techniczny, tylko psychologiczny: przy 15 pkt NAJSILNIEJSZA nagroda
        // w całym systemie przypadała za rzecz, której nie da się przypisać
        // ani wysiłkowi, ani umiejętności — tylko temu, że ktoś był wcześniej.
        //
        //   - teoria autodeterminacji (Deci/Ryan): nagroda buduje poczucie
        //     kompetencji tylko wtedy, gdy wynika z tego, CO zrobiłeś;
        //     „byłem pierwszy" nie zasila kompetencji, tylko status,
        //   - teoria sprawiedliwości (Adams): ten sam nakład i 2,5× niższy
        //     wynik, bo ktoś tam był wcześniej, to wzorcowa niesprawiedliwość
        //     porównawcza,
        //   - efekt Mateusza (Merton): przewaga wczesnych kumuluje się i
        //     zamyka grupę — nowy widzi wyniki, do których nie ma drogi.
        //
        // 5 pkt trzyma premię PONIŻEJ podstawowych 10 pkt za własne odkrycie,
        // więc zostaje dopiskiem do przejazdu, a nie jego przebiciem.
        // Najważniejszym przejazdem w systemie zostaje pierwszy przejazd
        // KAŻDEGO — a to jest jedyna rzecz, którą nowy dostaje na równi
        // z tym, kto jeździ tu od lat.
        //
        // Nie zmienia to punktów już przyznanych: rejestr jest niezmienny
        // (patrz nota na górze pliku), więc pierwsza fala zachowa swoje wyniki.
        'first_in_community_bonus' => 5,

        // Pole rzadko odwiedzane: mniej niż tylu rowerzystów miało je przed
        // nami. Wartość 3 jest umyślnie niska — inaczej "rzadkie" objęłoby
        // przy dzisiejszej skali serwisu praktycznie wszystko i bonus
        // przestałby cokolwiek znaczyć.
        'rare_cell_max_riders' => 3,
        'rare_cell_bonus'      => 5,

        // NAZEWNICTWO (decyzja usera 2026-08-13). W interfejsie ta kategoria
        // nazywa się „NOWY TEREN", a nie „Eksploracja / białe plamy
        // zamalowane". Zdarzenie jest to samo, ale język poprzedni był
        // językiem zawłaszczania: gra o sumie zerowej, w której moje pole to
        // takie, którego ty już nie zdobędziesz. Stało to w sprzeczności
        // z nagłówkiem „RAZEM ODKRYLIŚMY" na tej samej stronie.
        //
        // Nowa formuła przenosi zasługę z osoby na wspólną mapę: nie „byłem
        // pierwszy", tylko „mapa urosła o N pól dzięki Tobie". To jest różnica
        // między dobrem POZYCYJNYM a dobrem WSPÓLNYM — nagroda identyczna,
        // ale tylko druga wersja zaprasza kolejnych.
        //
        // Stałe kodu (SOURCE_EXPLORATION, klucz 'exploration') zostają:
        // zmiana nazw źródeł unieważniłaby klucz unikalny w point_transactions
        // i rozjechała historię. Nazwa dla człowieka mieszka w
        // PointLedger::LABELS.
    ],

    // -----------------------------------------------------------------
    // TRAILS — za zaliczanie znanych tras (§11)
    // -----------------------------------------------------------------
    'trails' => [
        // MODEL OD 2026-09-03 (decyzja usera, druga iteracja): trasa ma
        // JEDNĄ wartość całkowitą, którą progi dzielą między siebie RÓWNO —
        // nie osobną wartość per próg. Wcześniejsza wersja (progi 25/50/75%
        // warte mniej, ukończenie osobno i warte więcej) była nie do ogarnięcia
        // dla admina bez studiowania kodu. Trzy ustawienia:
        //
        //   1. `threshold_count` — NA ILE progów dzieli się trasa. Rozstawione
        //      równo co 100/N procent (4 => 25/50/75/100, jak dotąd).
        //   2. `default_total_points` — ile jest warta trasa ŁĄCZNIE, gdy nie
        //      da się policzyć sugestii z długości (patrz `points_per_km`
        //      niżej) i admin nie nadpisał wartości ręcznie.
        //   3. Podział jest ZAWSZE równy: total ÷ threshold_count, z resztą
        //      z zaokrąglenia dopisaną do ostatniego (100%) progu — jedyne
        //      miejsce z tą matematyką to Models\DiscoveryScoring::trailAwards().
        //
        // Procent pól trasy => próg jest przyznawany JEDEN RAZ, przy pierwszym
        // przekroczeniu. Ponowny przejazd tej samej trasy nie płaci drugi raz,
        // bo progi liczą się z pól JUŻ odkrytych, a tych nie da się odkryć
        // powtórnie.
        'threshold_count' => 4,

        // STAWKA BAZOWA per trasa (decyzja usera 2026-09-03, pierwsza
        // iteracja): bez tego każda trasa z włączonym bonusem, ale bez
        // ręcznie wpisanej wartości, płaciła DOKŁADNIE TYLE SAMO — 1000 km
        // i 50 km identycznie, co nie ma sensu. `DiscoveryScoring::
        // trailValueFor()` mnoży tę stawkę przez `distance_km` trasy, żeby
        // policzyć jej wartość CAŁKOWITĄ — dłuższa trasa jest warta więcej
        // AUTOMATYCZNIE, bez ręcznego ustawiania każdej. Ręczne nadpisanie
        // w panelu zostaje jako wyjątek dla tras wyjątkowych z innego powodu
        // niż długość.
        //
        // KALIBRACJA: 3,89 pkt/km odtwarza dokładnie `default_total_points`
        // niżej dla trasy 180 km, więc §12 zostaje prawdziwe dla „typowej"
        // długości, a rośnie/maleje proporcjonalnie dla reszty.
        'points_per_km' => 3.89,

        // Wartość CAŁKOWITA trasy, gdy nie da się policzyć sugestii z
        // długości (brak `distance_km` albo `points_per_km` wyzerowana) —
        // i przykład na ekranie „Ile to daje". Podzielona równo przez
        // `threshold_count` progów: przy 4 progach to 175 pkt na próg.
        'default_total_points' => 700,

        // §12 — znane trasy NIE MOGĄ zniszczyć eksploracji. Sprawdzian
        // proporcji, który trzeba powtórzyć przy każdej zmianie tych liczb:
        // przejechanie w całości trasy o długości 180 km daje ok. 1 800 pkt
        // Discovery i 700 pkt Trails. Ścieżka odkrywcza zostaje wyraźnie
        // silniejsza od kolekcjonowania szlaków, a nie odwrotnie.
    ],

    // -----------------------------------------------------------------
    // SKARBY / PUNKTY W TERENIE (migr. 054, 055)
    // -----------------------------------------------------------------
    'treasures' => [
        // Wartość WYJŚCIOWA dla nowego punktu. Każdy ma własną kolumnę
        // `points`, bo punkt widokowy na przełęczy jest wart więcej niż
        // tabliczka przy ścieżce — ta liczba tylko podpowiada, od czego zacząć.
        'default_points' => 50,

        // PUNKTY WYJSCIOWE WEDLUG RZADKOSCI (SKA/15). Rzadkosc nie jest
        // mnoznikiem — te liczby tylko podpowiadaja, ile wpisac zakladajac
        // skarb. Rozstrzyga zawsze kolumna `points` konkretnego skarbu, wiec
        // to, co admin widzi w formularzu, jest tym, co dostanie znalazca.
        //
        // Skok jest CELOWO nierowny (50 / 120 / 300 / 800). Rowny ciag
        // dwukrotnosci nauczylby, ze legendarny to „cztery pospolite" i oplaca
        // sie zbierac to, co po drodze; taki rozstaw sprawia, ze po legendarny
        // realnie warto nadlozyc drogi — a o to w tym module chodzi.
        'points' => [
            'COMMON'    => 50,
            'RARE'      => 120,
            'EPIC'      => 300,
            'LEGENDARY' => 800,
        ],

        // ILE POTWIERDZEN AKTYWUJE ZGLOSZONY PUNKT (SKA/3).
        //
        // Trzy, a nie jedno: jedno potwierdzenie moze pochodzic od kolegi
        // zglaszajacego i nie mowi nic. Trzy osoby, ktore niezaleznie tam
        // dojechaly, to juz dowod, ze miejsce istnieje i da sie do niego
        // dotrzec — a tego wlasnie ma dotyczyc glosowanie.
        //
        // Trzy, a nie dziesiec: przy starcie modulu dziesieciu chetnych na
        // jeden punkt po prostu sie nie zbierze i kazde zgloszenie umarloby
        // w poczekalni. Prog ma rosnac razem z ruchem, dlatego siedzi w
        // scoring_settings, a nie na sztywno w kodzie.
        'confirmations_needed' => 3,

        // Promień, w jakim trzeba stać, żeby zaliczyć. Domyślne 150 m to
        // kompromis: mniej wykluczałoby telefony z kiepskim GPS-em pod lasem,
        // więcej pozwalałoby zaliczać punkt z szosy, nie podjeżdżając do niego.
        'default_radius_m' => 150,
    ],

    // -----------------------------------------------------------------
    // PRYWATNOŚĆ (§27) — dom i miejsce startu
    // -----------------------------------------------------------------
    // Dopóki ślady wgrywało się DO WYDARZENIA, ten problem nie istniał: trasa
    // zaczynała się na zbiórce, czyli w miejscu i tak publicznym. Przejazd solo
    // (migr. 049) zmienia to całkowicie — plik z licznika zaczyna się i kończy
    // pod domem, a mapa odkryć jest publiczna.
    //
    // Rozwiązanie jest CELOWO tępe: pola w promieniu `home_trim_radius_m` od
    // pierwszego i ostatniego punktu śladu solo po prostu nie są zapisywane.
    // Nie „ukrywane", nie „anonimizowane" — nie powstają. Dane, których nie ma,
    // nie wyciekną żadnym nowym endpointem ani eksportem, o którym ktoś zapomni.
    //
    // Cena: pola przy domu nie odkryją się, nawet jeśli ktoś naprawdę tamtędy
    // jeździ. Informacja, którą chronią, jest za to nieodwracalna.
    //
    // 50 m, NIE 400 (decyzja usera 2026-08-30, po jeździe testowej: „często
    // punkt startowy to po prostu rozpoczęcie nagrywania, więc nie bardzo
    // rozumiem; poza tym punkt końcowy tam, gdzie skarb, też nie zaliczony").
    // Zarzut jest trafny: przycięcie działa BEZWARUNKOWO, a nigdzie nie
    // trzymamy adresu, więc kod nie odróżnia startu spod domu od startu
    // z parkingu w lesie. W apce „start" to w ogóle tylko miejsce, w którym
    // ktoś dotknął „Nagraj". 400 m kosztowało przy tym po jednym-dwóch polach
    // z każdego końca ORAZ skarb stojący na mecie przejazdu (punkty pod niego
    // wypadały razem z końcówką śladu, a `claim_radius_m` to zwykle 150 m).
    //
    // CO TO REALNIE ZOSTAWIA: 50 m to dziesiąta część pola, więc jako ochrona
    // adresu ten promień jest już właściwie symboliczny — chroni próg, nie
    // budynek. Świadomy wybór: kompletność odkryć ponad zgrubne zamazanie,
    // które i tak nie ukrywało hexa. Prawdziwym rozwiązaniem jest zadeklarowany
    // punkt domowy (przycinaj TYLKO wokół niego, i tylko temu, kto tego chce) —
    // dopóki go nie ma, ta liczba jest kompromisem, a nie gwarancją.
    'privacy' => [
        'home_trim_radius_m' => 50,
    ],

    // -----------------------------------------------------------------
    // LIMITY (§25 — antyfarming)
    // -----------------------------------------------------------------
    'limits' => [
        // Górna granica pól zaliczanych z JEDNEGO przejazdu. Przy źródle
        // 'event_route' nie ma jak jej przekroczyć uczciwie (najdłuższy
        // dzień etapowy to kilkaset pól), więc działa wyłącznie jako
        // bezpiecznik przed błędnym plikiem GPX ze skokiem przez pół
        // kontynentu — i będzie potrzebna naprawdę dopiero, gdy rowerzyści
        // zaczną wgrywać własne ślady.
        'max_new_cells_per_activity' => 1500,
    ],
];
