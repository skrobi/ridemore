<?php
// core/Utils/TrackPalette.php
// BAZA KOLORÓW ŚLADÓW — jedna paleta dla WSZYSTKIEGO, co rysuje się na mapie
// jako linia (2026-08-27).
//
// POWÓD POWSTANIA (zgłoszenie usera): „wszystkie te trasy są wygenerowane
// w kolorze zielonym, niezależnie od tego czy to solo przejazdy czy
// referencyjne (…) w pierwotnym założeniu jakiekolwiek ślady tras miały mieć
// różne kolory, tak aby w przypadku nakładających się śladów rozróżnić je
// kolorami (…) mam jedną wielką zieloną plamę".
//
// Do tej pory kolor per obiekt miały WYŁĄCZNIE znane trasy (migr. 064,
// `known_routes.color_index`), a wszystkie przejazdy — solo i z wyjazdów —
// szły jedną grupą w jednym kolorze `#2C6B4F`. Przy 89 przejazdach wokół
// jednego miasta daje to dokładnie to, co user opisał.
//
// DWIE RZECZY, KTÓRE TA KLASA TRZYMA RAZEM, I DLACZEGO RAZEM
// ----------------------------------------------------------
// 1. PALETĘ — kolory, którymi wolno pomalować ślad.
// 2. KOLOR WYBORU (`SELECTED`) — ten JEDEN, którym nie wolno pomalować
//    niczego innego, bo znaczy „to zaznaczyłeś".
//
// Gdyby leżały osobno, nic nie pilnowałoby, żeby paleta nie zawierała koloru
// wyboru — i właśnie tak było: `KnownRoute::COLORS` miało pod indeksem 1
// niebieski `#1F6FB2`, a podświetlenie kliknietego śladu rysuje się
// `#2B57C8` (`ridemoreFocusTrack` w assets/js/discovery-map.js). Trasa
// z indeksem 1 wyglądała więc jak zaznaczona, choć nikt jej nie kliknął.
// Niebieski wypadł z palety i ZOSTAŁ ZAREZERWOWANY; jego miejsce (indeks 1)
// zajęła oliwka — patrz nota przy stałej.
namespace Utils;

class TrackPalette
{
    /**
     * KOLORY ŚLADÓW. Kolejność jest ZNACZĄCA i nie wolno jej przestawiać:
     * indeks jest tym, co siedzi w bazie (`known_routes.color_index`,
     * `gpx_geometry.color_index`), więc przestawienie dwóch pozycji
     * przemalowałoby trasy, których nikt nie kazał przemalowywać — a kafle
     * leżą na dysku z nagłówkiem `immutable` i same się o tym nie dowiedzą.
     *
     * Sześć, nie dwadzieścia: paleta ma być ROZRÓŻNIALNA na kresce szerokości
     * 3–4 px, a nie bogata. Graf sąsiedztwa śladów jest niemal płaski, więc
     * cztery kolory wystarczyłyby do samego unikania kolizji (twierdzenie
     * o czterech barwach); dwa dodatkowe są po to, żeby przydział mógł
     * ROZKŁADAĆ kolory, a nie tylko unikać zderzeń.
     *
     * Wszystkie ciemne i nasycone, bo tło bywa dwojakie: jasna mapa OSM ORAZ
     * szara mgła (`#8B95A3` przy kryciu 0,55). Kolor jasny albo pastelowy
     * czyta się na jednym z tych teł jak wyblakły ślad, a nie jak ślad.
     *
     * INDEKS 0 ZOSTAJE BRANDOWĄ ZIELENIĄ — to ten sam odcień, którym serwis
     * rysował ślady od zawsze, więc mapa nie zmienia charakteru, tylko
     * przestaje kłamać o liczbie przejazdów.
     *
     * INDEKS 1 TO OLIWKA, NIE NIEBIESKI (zmiana 2026-08-27). Niebieski
     * `#1F6FB2` stał tu od migracji 064 i kolidował znaczeniowo z kolorem
     * WYBORU (`SELECTED`, `#2B57C8`) — dwie różne rzeczy mówiące niemal tym
     * samym odcieniem. Podmieniony JEST W MIEJSCU (indeks 1 zostaje indeksem
     * 1), więc jedyne, co się przemalowuje, to trasy, które akurat miały
     * niebieski; reszta katalogu zostaje bez zmian.
     *
     * Odcienie rozłożone po kole barw z DZIURĄ W OKOLICY NIEBIESKIEGO
     * (ok. 200–250°), która należy do `SELECTED`:
     *   5° ceglany · 30° pomarańczowy · 80° oliwka · 155° zieleń ·
     *   272° fiolet · 325° amarant
     */
    public const COLORS = [
        '#2C6B4F', // zieleń brandowa
        '#5F7A20', // oliwka  (do 2026-08-27: niebieski #1F6FB2 — patrz nota)
        '#B3382C', // ceglany
        '#7D4CA8', // fiolet
        '#D2731A', // pomarańczowy
        '#A83E76', // amarant
    ];

    /**
     * KOLOR WYBRANEGO ŚLADU — zarezerwowany, NIGDY w palecie.
     *
     * Prośba usera wprost: „jeden kolor powinien być przeznaczony dla
     * wybranego". Rysuje nim `ridemoreFocusTrack` (assets/js/discovery-map.js)
     * warstwę wektorową NA WIERZCHU kafli, gdy ktoś kliknie pozycję
     * w „Ostatniej aktywności" albo wpis na profilu.
     *
     * POWTÓRZENIE W JS JEST ŚWIADOME i ograniczone do jednej wartości — ta
     * sama zasada i ten sam powód co przy `TileController::SCALE`
     * (kolory pól odkryć): jedna strona rysuje w GD, druga w Leaflecie,
     * a wspólny plik konfiguracyjny dla obu kosztowałby więcej, niż jest
     * wart. Zmiana tutaj wymaga zmiany TAM.
     *
     * `--s-blue` z palety CSS serwisu, czyli ten sam niebieski co reszta
     * interfejsu — a nie siódmy kolor wymyślony na tę okazję.
     */
    public const SELECTED = '#2B57C8';

    /**
     * Kolor z zapisanego indeksu — jedyne miejsce zamieniające numer z bazy
     * na wartość HEX. Woła to renderer kafli, dymek na mapie i karty tras,
     * bo „ten ślad jest fioletowy" musi znaczyć to samo wszędzie.
     *
     * NULL (obiekt sprzed przydziału) daje kolor bazowy, czyli dokładnie to,
     * co serwis rysował wcześniej. Brak przydziału nie ma prawa niczego
     * zepsuć — ma tylko nie poprawiać.
     */
    public static function colorOf($index): string
    {
        if ($index === null || $index === '') {
            return self::COLORS[0];
        }
        return self::COLORS[((int) $index) % count(self::COLORS)];
    }

    public static function count(): int
    {
        return count(self::COLORS);
    }

    /**
     * WYBÓR KOLORU: najpierw taki, którego nie ma sąsiad, potem najrzadszy
     * w całym zbiorze, na końcu najniższy indeks (dla powtarzalności).
     *
     * Wyciągnięte z `Models\KnownRoute::pickColor` (migr. 064), gdy ten sam
     * przydział zaczął obsługiwać także zwykłe ślady — algorytm był już raz
     * przemyślany i przetestowany, a dwie kopie rozjechałyby się przy
     * pierwszej zmianie.
     *
     * Gdyby sąsiedzi zajęli CAŁĄ paletę — co przy sześciu kolorach znaczy
     * sześć śladów w tym samym korytarzu, a przy 89 przejazdach wokół jednego
     * miasta jest regułą, nie wyjątkiem — wygrywa kolor NAJRZADSZY WŚRÓD
     * SĄSIADÓW. Kolizja ląduje więc tam, gdzie jest jej najmniej widać,
     * zamiast trafiać w pierwszy z brzegu, a paleta rozkłada się po zbiorze
     * równo (przy wszystkich-sąsiadach-każdego wychodzi z tego czysta
     * karuzela 0,1,2,3,4,5,0,1,…).
     *
     * @param int[] $zajete indeksy kolorów sąsiadów (mogą się powtarzać —
     *                      powtórzenie JEST treścią: kolor, który ma trzech
     *                      sąsiadów, jest gorszym wyborem niż ten z jednym)
     * @param array<int,int> $uzycie indeks koloru => ile obiektów go ma
     */
    public static function pick(array $zajete, array $uzycie): int
    {
        $uSasiadow = array_count_values(array_map('intval', $zajete));

        $best = 0;
        $bestKey = null;
        for ($i = 0; $i < count(self::COLORS); $i++) {
            $key = [$uSasiadow[$i] ?? 0, $uzycie[$i] ?? 0, $i];
            if ($bestKey === null || $key < $bestKey) {
                $bestKey = $key;
                $best = $i;
            }
        }
        return $best;
    }
}
