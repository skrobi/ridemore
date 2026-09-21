<?php
// core/Utils/TileRenderer.php
// RYSOWANIE KAFLA — jedyne miejsce, w którym powstaje obrazek mapy.
//
// Wejście: gotowe liczby całkowite (piksele świata z Models\GpxGeometry, środki
// pól z Utils\DiscoveryGrid). Wyjście: bajty PNG. Żadnych zapytań do bazy,
// żadnego czytania plików — to robią modele, a kontroler je skleja. Dzięki temu
// renderer da się uruchomić i zmierzyć bez stawiania żądania HTTP.
//
// NADPRÓBKOWANIE ZAMIAST ANTYALIASINGU
// ------------------------------------
// GD ma imageantialias(), ale ta funkcja NIE DZIAŁA dla linii grubszych niż
// 1 px — a ślad rysujemy grubością 3 px, bo cieńszy ginie na kaflach OSM.
// Dlatego rysujemy obraz dwa razy większy i zmniejszamy go imagecopyresampled():
// zmniejszanie z uśrednianiem JEST antyaliasingiem, tylko zrobionym ręcznie.
// Zmierzone: 16–30 ms na kafel przy nadpróbkowaniu x2, 71 ms przy x4 — stąd x2.
//
// Nadpróbkowanie x2 ma drugi, mniej oczywisty skutek: rysujemy w rozdzielczości
// zoomu o jeden WYŻSZEGO, więc do kafla trafia dwa razy więcej szczegółu śladu,
// zanim uśrednianie go zetrze. Linia na zakręcie wygląda przez to jak linia,
// a nie jak schodki.
namespace Utils;

class TileRenderer
{
    /** Ile razy większy obraz rysujemy, zanim go zmniejszymy. */
    public const SS = 2;

    /**
     * Zapas wokół kafla, w pikselach docelowych — DWIE ROLE NARAZ.
     *
     * (1) Odsiew: odcinek, którego OBA końce leżą poza kaflem, nadal może
     * przez ten kafel przechodzić — a linia o grubości 3 px wystaje półtora
     * piksela poza swój tor. Bez zapasu przy odsiewaniu segmentów do
     * pominięcia obcinalibyśmy też te, które naprawdę wchodzą w kadr.
     *
     * (2) PŁÓTNO JEST WIĘKSZE NIŻ SAM KAFEL O TEN ZAPAS Z KAŻDEJ STRONY
     * (2026-08-29, zgłoszenie usera: „jeszcze miejsca łączeń do poprawy" —
     * screeny pokazywały drobne przerwy dokładnie na granicach sąsiednich
     * kafli, NIE na ostrych zakrętach w środku — te naprawiło już zaokrąglone
     * złączenie wyżej). Przyczyna: bez zapasu granica kafla BYŁA krawędzią
     * płótna GD, a `imagesetthickness`+`imageline`/`imagefilledellipse`
     * przycinane DOKŁADNIE na krawędzi płótna potrafią zostawić rządek
     * brakujących pikseli (zmierzone na prawdziwym śladzie: identyczne
     * przerwy PRZED i PO naprawie złączeń, zawsze dokładnie na siatce 256 px
     * — to wykluczyło ostre zakręty jako przyczynę). Rysując z zapasem z obu
     * stron, granica kafla ląduje w ŚRODKU płótna, jak każdy inny piksel —
     * dopiero `finish()` wycina właściwy kafel PRZED pomniejszeniem.
     * Wartość musi przebić najszerszy promień złączenia (obwódka: promień
     * do 7 px nadpróbkowanych, czyli 3,5 px docelowych) z zapasem na
     * jądro uśredniania przy pomniejszaniu — 8 px starcza z dużym marginesem.
     */
    private const MARGIN = 8;

    /**
     * Kafel ze śladami.
     *
     * @param array<int,array{geom:array,color:string,weight:float,alpha:float,dash:bool,
     *                        casing?:string,casingWidth?:float}> $groups
     *        grupy rysowane w podanej kolejności — pierwsza pod spodem. `casing`
     *        (opcjonalny) to KOLOR OBWÓDKI — patrz nota niżej.
     */
    public static function tracks(array $groups, int $z, int $x, int $y): string
    {
        $pad = self::MARGIN * self::SS;
        $im = self::canvas($pad);
        // Piksele świata (STORE_Z) -> piksele nadpróbkowane kafla.
        //
        // PARA PRZESUNIĘĆ, NIE JEDNO (2026-08-20). Nadpróbkowanie ×2 znaczy, że
        // rysujemy w przestrzeni zoomu `z + 1`, więc przy z18 — od tej daty
        // najgłębszym generowanym — wychodziło przesunięcie MINUS JEDEN, a PHP 8
        // rzuca na tym `ArithmeticError: Bit shift by negative number`
        // (zmierzone: HTTP 500 na każdym kaflu z18). Rozbite na przesunięcie
        // w lewo i w prawo, oba nieujemne: jedno z nich jest zawsze zerem, więc
        // w pętli po punktach nie ma ani gałęzi, ani dodatkowego kosztu.
        [$shiftL, $shiftR] = self::shiftPair($z);
        // WSPÓŁRZĘDNE ZOSTAJĄ „WZGLĘDEM KAFLA" (0..limit), TAK JAK DOTĄD —
        // płótno jest większe o `$pad` z każdej strony (patrz nota przy
        // MARGIN), ale odsiew segmentów i deduplikacja pikseli w `polyline()`
        // liczą się prościej w tym samym układzie co granice kafla. Przesunięcie
        // na PRAWDZIWY piksel płótna (`+ $pad`) dzieje się dopiero przy samym
        // rysowaniu, w `polyline()`.
        $originX = $x * TileGrid::TILE * self::SS;
        $originY = $y * TileGrid::TILE * self::SS;
        $limit = TileGrid::TILE * self::SS;
        $margin = self::MARGIN * self::SS;

        foreach ($groups as $g) {
            $weightPx = max(1, (int) round(($g['weight'] ?? 3) * self::SS));

            // OBWÓDKA (2026-08-27, zgłoszenie usera: „znane trasy i ślady mają
            // dziś jeden styl i nakładają się na siebie nie do odróżnienia").
            // Klasyczna „cased line" z kartografii drogowej: ta sama geometria
            // narysowana DWA RAZY — najpierw SZERZEJ, jednolitym kolorem
            // obwódki (tu spod spodu wystają tylko brzegi), potem NORMALNĄ
            // grubością, głównym kolorem grupy, na wierzchu. Dzięki temu
            // „to jest referencja" czyta się z SAMEGO KSZTAŁTU linii —
            // niezależnie od tego, jaki main-kolor akurat przydzielił
            // `KnownRoute::assignColor` — a nie tylko z koloru, który ślad
            // (bez obwódki) też może przez przypadek mieć.
            if (!empty($g['casing'])) {
                $casingColor = self::color($im, $g['casing'], $g['alpha'] ?? 1.0);
                $casingPx = max($weightPx + 2, (int) round(
                    (($g['weight'] ?? 3) + 2 * ($g['casingWidth'] ?? 1.5)) * self::SS
                ));
                imagesetthickness($im, $casingPx);
                // ZAOKRĄGLONE ZŁĄCZENIA (2026-08-29, zgłoszenie usera: „obwódka
                // mocno postrzępiona"). `imagesetthickness` + `imageline` w GD
                // NIE UMIE łączyć odcinków w wierzchołku — każdy segment to
                // osobny prostokąt z prostymi końcami, więc na ostrym zakręcie
                // (a ślad GPS ma ich mnóstwo) między dwoma segmentami zostaje
                // klin brakującego piksela. Widać to najsilniej na SZEROKIEJ
                // obwódce, bo klin rośnie razem z grubością linii. Dokładany
                // kółkiem o średnicy grubości linii w KAŻDYM wierzchołku —
                // ten sam trik, którym SVG/Leaflet implementują `stroke-
                // linejoin: round` — wypełnia klin niezależnie od kąta.
                foreach ($g['geom'] as $geo) {
                    self::polyline($im, $geo['pts'], $shiftL, $shiftR, $originX, $originY, $limit, $margin, $casingColor, $casingPx, $pad);
                }
            }

            $color = self::color($im, $g['color'], $g['alpha'] ?? 1.0);
            imagesetthickness($im, $weightPx);
            if (!empty($g['dash'])) {
                // Kreska trasy ZAPOWIADANEJ. Wzór w pikselach nadpróbkowanych,
                // żeby po zmniejszeniu wyszedł taki sam jak w wektorowej wersji
                // mapy (dashArray '5,7').
                $style = array_merge(
                    array_fill(0, 5 * self::SS, $color),
                    array_fill(0, 7 * self::SS, IMG_COLOR_TRANSPARENT)
                );
                imagesetstyle($im, $style);
                $drawColor = IMG_COLOR_STYLED;
                // BEZ ZŁĄCZEŃ NA PRZERYWANEJ — kółko w wierzchołku wypełniłoby
                // sobą przerwę we wzorze kreski, czyli dokładnie to, co ten
                // styl ma pokazywać (§ „to jest zapowiedź, nie dokonanie").
                $jointPx = 0;
            } else {
                $drawColor = $color;
                $jointPx = $weightPx;
            }

            foreach ($g['geom'] as $geo) {
                self::polyline($im, $geo['pts'], $shiftL, $shiftR, $originX, $originY, $limit, $margin, $drawColor, $jointPx, $pad);
            }
        }

        return self::finish($im, $pad);
    }

    /**
     * Jedna linia łamana, przycięta do kafla.
     *
     * @param array<int,int> $pts płaska tablica [px, py, px, py, ...] (piksele świata)
     * @param int $jointPx średnica kółka dokładanego w KAŻDYM wierzchołku, żeby
     *        zaokrąglić złączenie dwóch grubych odcinków (0 = bez kółek — linie
     *        cienkie, gdzie klina i tak nie widać, i przerywane, gdzie kółko
     *        zamalowałoby przerwę we wzorze, patrz wołający w `tracks()`).
     * @param int $pad przesunięcie na PRAWDZIWY piksel płótna — płótno jest
     *        większe o `$pad` z każdej strony niż sam kafel (patrz nota przy
     *        MARGIN), a `$cx`/`$cy` liczą się WZGLĘDEM KAFLA (jak zawsze),
     *        więc dopiero tu, przy samym rysowaniu, dochodzi przesunięcie.
     *        Odsiew i deduplikacja pikseli (`$lastDrawnX` itd.) zostają
     *        w układzie „względem kafla" — dodanie stałej do obu stron
     *        porównania niczego by nie zmieniło, a psułoby czytelność.
     */
    private static function polyline(
        $im,
        array $pts,
        int $shiftL,
        int $shiftR,
        int $originX,
        int $originY,
        int $limit,
        int $margin,
        int $color,
        int $jointPx = 0,
        int $pad = 0
    ): void {
        $n = count($pts);
        $prevX = null; $prevY = null;
        $lastDrawnX = PHP_INT_MIN; $lastDrawnY = PHP_INT_MIN;
        $lastJointX = PHP_INT_MIN; $lastJointY = PHP_INT_MIN;

        for ($i = 0; $i < $n; $i += 2) {
            $cx = (($pts[$i] << $shiftL) >> $shiftR) - $originX;
            $cy = (($pts[$i + 1] << $shiftL) >> $shiftR) - $originY;

            if ($prevX !== null) {
                // Odsiew odcinków leżących w całości poza kaflem. Nie zastępuje
                // przycinania — te, które przecinają kafel, GD obetnie sam —
                // tylko oszczędza wywołania imageline dla śladu, z którego
                // w tym kaflu widać 200 m ze 160 km.
                $outside =
                    ($cx < -$margin && $prevX < -$margin) ||
                    ($cy < -$margin && $prevY < -$margin) ||
                    ($cx > $limit + $margin && $prevX > $limit + $margin) ||
                    ($cy > $limit + $margin && $prevY > $limit + $margin);

                if (!$outside) {
                    // Punkty lądujące w tym samym pikselu pomijamy. Przy
                    // oddaleniu to WIĘKSZOŚĆ punktów: ślad 160 km ma na z8
                    // szerokość 40 px, więc 2000 punktów opisuje 40 pikseli.
                    if ($cx !== $lastDrawnX || $cy !== $lastDrawnY) {
                        imageline($im, $prevX + $pad, $prevY + $pad, $cx + $pad, $cy + $pad, $color);
                        $lastDrawnX = $cx;
                        $lastDrawnY = $cy;
                    }
                    // ZŁĄCZENIE W WIERZCHOŁKU (patrz nota w `tracks()`) — kółko
                    // na OBU końcach odcinka, który faktycznie się narysował.
                    // Rysowane tu, nie w osobnej pętli po wszystkich punktach,
                    // bo interesuje nas WYŁĄCZNIE wierzchołek, w którym dwa
                    // narysowane odcinki się stykają — wierzchołek leżący
                    // samotnie poza kaflem (obcięty przez `$outside` z obu
                    // stron) nie ma czego łączyć.
                    if ($jointPx > 2) {
                        if ($prevX !== $lastJointX || $prevY !== $lastJointY) {
                            imagefilledellipse($im, $prevX + $pad, $prevY + $pad, $jointPx, $jointPx, $color);
                        }
                        imagefilledellipse($im, $cx + $pad, $cy + $pad, $jointPx, $jointPx, $color);
                        $lastJointX = $cx;
                        $lastJointY = $cy;
                    }
                }
            }
            $prevX = $cx;
            $prevY = $cy;
        }
    }

    /**
     * Kafel z polami odkryć.
     *
     * @param array<int,array{cellId:int,level:int}> $cells pola z gotowym stopniem skali
     * @param array<int,array{color:string,alpha:float}> $scale paleta stopni
     * @param array{color:string,alpha:float}|null $fog tło dla terenu nieodkrytego
     */
    public static function hexes(array $cells, array $scale, ?array $fog, int $z, int $x, int $y): string
    {
        // Zapas wokół płótna (patrz nota przy MARGIN w `tracks()`) — pola
        // dotykające granicy kafla nie mają tu tego samego ryzyka co grube
        // linie (wielokąt jest wypełnieniem, nie odcinkiem przycinanym przez
        // `imagesetthickness`), ale płótno i tak dzielone jest z `tracks()`,
        // więc dostaje ten sam zapas dla spójności i na wszelki wypadek przy
        // cienkim obrysie mgły.
        $pad = self::MARGIN * self::SS;
        $im = self::canvas($pad);
        [$shiftL, $shiftR] = self::shiftPair($z);   // patrz nota w tracks()
        $originX = $x * TileGrid::TILE * self::SS;
        $originY = $y * TileGrid::TILE * self::SS;

        // POLE ODKRYTE ODSŁANIA MAPĘ, NIE ZAMALOWUJE JEJ (2026-08-14, uwaga
        // usera: „odkrycia powinny ujawniać trasę, czyli być przezroczyste").
        //
        // Wcześniej było odwrotnie — mgła jako tło i pola zamalowane kolorem
        // skali. Przy typowych danych (prawie wszystko odkryte RAZ) dawało to
        // jednolitą zieloną plamę, pod którą nie było widać ani drogi, ani
        // nazwy, ani własnego śladu. Odkrycie ma odsłaniać teren, a nie
        // podmieniać go na kolor.
        //
        // Mechanika: mgła zamalowuje cały kafel, a pole odkryte WYCINA W NIEJ
        // DZIURĘ — rysowane przy WYŁĄCZONYM mieszaniu (`imagealphablending`
        // false), więc kolor pola ZASTĘPUJE piksele mgły zamiast się z nimi
        // sumować. Dla pierwszego stopnia skali tym kolorem jest pełna
        // przezroczystość, czyli po prostu goła mapa. To jest ten sam efekt,
        // co dziury w wielokącie mgły w wersji wektorowej (fill-rule evenodd),
        // tylko osiągnięty rastrowo.
        if ($fog !== null) {
            imagealphablending($im, false);
            imagefilledrectangle($im, 0, 0, imagesx($im) - 1, imagesy($im) - 1,
                self::color($im, $fog['color'], $fog['alpha']));
        }

        // Wielokąty liczymy RAZ i używamy dwa razy (wypełnienie + obrys) —
        // rzutowanie sześciu wierzchołków na pole to jedyna trygonometria
        // w tym rendererze i nie ma powodu robić jej dwukrotnie.
        $polys = [];
        foreach ($cells as $i => $cell) {
            $poly = [];
            foreach (DiscoveryGrid::cellPolygon($cell['cellId']) as [$lat, $lon]) {
                [$wx, $wy] = TileGrid::toPixel($lat, $lon);
                $poly[] = (($wx << $shiftL) >> $shiftR) - $originX + $pad;
                $poly[] = (($wy << $shiftL) >> $shiftR) - $originY + $pad;
            }
            $polys[$i] = $poly;
        }

        imagealphablending($im, false);
        foreach ($cells as $i => $cell) {
            $step = $scale[$cell['level']] ?? $scale[0];
            imagefilledpolygon($im, $polys[$i], self::color($im, $step['color'], $step['alpha']));
        }

        // OBRYS NALEŻY DO MGŁY, NIE DO POLA ODKRYTEGO.
        //
        // Dzień wcześniej biały włos siedział na polach odkrytych — miał
        // rozdzielać sąsiednie pola tego samego stopnia. Odkąd te pola są
        // przezroczyste, nie ma czego rozdzielać: obrys jest teraz KRAWĘDZIĄ
        // MGŁY, czyli granicą między tym, co odkryte, a tym, co jeszcze nie.
        // To dokładnie ta linia, którą warto widzieć — pokazuje kształt
        // własnego zasięgu, zamiast rysować siatkę na terenie.
        //
        // KOLOR OBRYSU TO KOLOR MGŁY, TYLKO GĘSTSZY — nie biel.
        //
        // Biel była dobra, dopóki obrys leżał na polu ZAMALOWANYM: czytała się
        // wtedy jak fuga między kaflami. Na jasnoszarej mgle biały włos po
        // prostu znika (zmierzone: na 65 tys. pikseli kafla obrys był
        // nierozróżnialny od antyaliasingu krawędzi). Zagęszczona mgła daje
        // rąbek, który widać z obu stron granicy: po stronie mgły jako ciemniejszy
        // brzeg, po stronie odsłoniętej jako wyraźny kontur pola.
        //
        // Rysowany PO wszystkich wypełnieniach i z włączonym mieszaniem, żeby
        // wypełnienie sąsiedniego pola nie zamalowało obrysu poprzedniego.
        imagealphablending($im, true);
        imagesetthickness($im, max(1, (int) round(0.6 * self::SS)));
        $outline = self::color(
            $im,
            $fog['outline'] ?? $fog['color'] ?? '#8B95A3',
            min(1.0, ($fog['alpha'] ?? 0.55) + 0.30)
        );
        foreach ($polys as $poly) {
            imagepolygon($im, $poly, $outline);
        }

        return self::finish($im, $pad);
    }

    /** Pusty kafel — w pełni przezroczysty PNG, zapisywany jak każdy inny. */
    public static function blank(): string
    {
        // Bez zapasu — płótno bez treści nie ma czego przycinać, a `finish()`
        // z `$pad = 0` po prostu pomniejsza całość jak dawniej.
        return self::finish(self::canvas(0), 0);
    }

    /**
     * Skleja gotowe kafle w jeden płaski obraz i wycina z nich okno o zadanym
     * rozmiarze — pod "Trasę dnia" na stronie głównej (Controllers\
     * TileController::routeOfDayMap, decyzja usera 2026-09-05: "Statyczny,
     * składany obrazek z prawdziwych kafli"). JEDYNE miejsce w serwisie, które
     * łączy kafel mapy bazowej z własną nakładką w jeden obrazek — każda inna
     * mapa serwisu stawia je jako osobne warstwy Leafletu w przeglądarce,
     * więc to nie jest drugi silnik rysowania, tylko spłaszczenie efektu tamtych
     * dwóch (mapa bazowa, kafle tego renderera) do jednego pliku.
     *
     * Wejście to same bajty PNG, bez sieci/dysku/bazy — zgodnie z zasadą
     * pliku (patrz nagłówek), o kafle bazowe i o nakładki dba wołający.
     *
     * @param array<string,?string> $basemap kafle mapy bazowej, klucz "tx,ty" (null = brak, zostaje tło)
     * @param list<array<string,?string>> $overlays nakładki tego renderera w kolejności rysowania (od najniższej), ten sam klucz "tx,ty"
     */
    public static function compose(
        array $basemap,
        array $overlays,
        int $txMin,
        int $tyMin,
        int $txMax,
        int $tyMax,
        int $cropX,
        int $cropY,
        int $outW,
        int $outH
    ): string {
        $tile = TileGrid::TILE;
        $stageW = ($txMax - $txMin + 1) * $tile;
        $stageH = ($tyMax - $tyMin + 1) * $tile;

        $stage = imagecreatetruecolor($stageW, $stageH);
        // Tło pod kafle bazowe, których nie udało się pobrać (patrz
        // Models\OsmBasemap) — neutralna szarość zamiast czerni, żeby brak
        // pojedynczego kafla nie wyglądał jak dziura, tylko jak mgiełka.
        imagealphablending($stage, false);
        imagefill($stage, 0, 0, self::color($stage, '#DDE3E8', 1.0));
        imagealphablending($stage, true);

        for ($tx = $txMin; $tx <= $txMax; $tx++) {
            for ($ty = $tyMin; $ty <= $tyMax; $ty++) {
                $dx = ($tx - $txMin) * $tile;
                $dy = ($ty - $tyMin) * $tile;
                $k = "$tx,$ty";
                self::pasteTile($stage, $basemap[$k] ?? null, $dx, $dy, $tile);
                foreach ($overlays as $layer) {
                    self::pasteTile($stage, $layer[$k] ?? null, $dx, $dy, $tile);
                }
            }
        }

        // Zaciski wyłącznie na wszelki wypadek — wołający (TileController)
        // dobiera zakres kafli TAK, żeby okno zawsze mieściło się w środku.
        $cropX = max(0, min($cropX, $stageW - $outW));
        $cropY = max(0, min($cropY, $stageH - $outH));

        $out = imagecreatetruecolor($outW, $outH);
        imagecopy($out, $stage, 0, 0, $cropX, $cropY, $outW, $outH);
        imagedestroy($stage);

        ob_start();
        imagepng($out, null, 6);
        $png = (string) ob_get_clean();
        imagedestroy($out);
        return $png;
    }

    /** Wkleja jeden kafel (bajty PNG albo brak) na płótno w podanym miejscu. */
    private static function pasteTile($stage, ?string $png, int $dx, int $dy, int $tile): void
    {
        if ($png === null || $png === '') {
            return;
        }
        $im = @imagecreatefromstring($png);
        if ($im === false) {
            return;
        }
        imagesavealpha($im, true);
        imagecopy($stage, $im, $dx, $dy, 0, 0, $tile, $tile);
        imagedestroy($im);
    }

    /**
     * Płótno WIĘKSZE OD KAFLA o `$pad` z każdej strony (patrz nota przy
     * MARGIN) — granica kafla ma wypaść w ŚRODKU płótna, nie na jego
     * krawędzi, żeby `imagesetthickness` (przycinanie grubych linii) nie
     * miało szans zostawić rządka brakujących pikseli dokładnie tam, gdzie
     * kafle się stykają. `finish()` wycina właściwy kafel z powrotem.
     */
    private static function canvas(int $pad)
    {
        $size = TileGrid::TILE * self::SS + 2 * $pad;
        $im = imagecreatetruecolor($size, $size);
        // Kolejność ma znaczenie: alphablending WYŁĄCZONE na czas wypełniania
        // tła, inaczej „przezroczysty" zmieszałby się z czernią świeżo
        // utworzonego obrazu i dałby czarną poświatę na krawędziach linii.
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagealphablending($im, true);
        return $im;
    }

    /**
     * Docina płótno z powrotem do właściwego kafla (zdejmuje `$pad` z każdej
     * strony) I DOPIERO POTEM pomniejsza nadpróbkowanie — w tej kolejności,
     * bo przycinanie PO pomniejszeniu przycinałoby już rozmyte, gotowe
     * piksele niewłaściwym współczynnikiem (pad jest w pikselach
     * nadpróbkowanych, kafel docelowy nie).
     */
    private static function finish($im, int $pad): string
    {
        if ($pad > 0) {
            $core = imagecreatetruecolor(TileGrid::TILE * self::SS, TileGrid::TILE * self::SS);
            imagealphablending($core, false);
            imagesavealpha($core, true);
            imagecopy($core, $im, 0, 0, $pad, $pad, TileGrid::TILE * self::SS, TileGrid::TILE * self::SS);
            imagedestroy($im);
            $im = $core;
        }
        if (self::SS > 1) {
            $out = imagecreatetruecolor(TileGrid::TILE, TileGrid::TILE);
            imagealphablending($out, false);
            imagesavealpha($out, true);
            imagecopyresampled($out, $im, 0, 0, 0, 0,
                TileGrid::TILE, TileGrid::TILE, imagesx($im), imagesy($im));
            imagedestroy($im);
            $im = $out;
        }
        ob_start();
        // Poziom 6 zamiast domyślnego -1 (czyli 6 w zlib, ale jawnie) — wyżej
        // kosztuje więcej czasu procesora niż oszczędza bajtów na obrazie,
        // który i tak jest w większości przezroczysty.
        imagepng($im, null, 6);
        $png = (string) ob_get_clean();
        imagedestroy($im);
        return $png;
    }

    /** '#2C6B4F' + krycie 0..1 -> kolor GD z kanałem alfa (0 = kryjący, 127 = niewidoczny). */
    private static function color($im, string $hex, float $alpha)
    {
        $hex = ltrim($hex, '#');
        return imagecolorallocatealpha(
            $im,
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
            (int) round(127 * (1 - max(0.0, min(1.0, $alpha))))
        );
    }

    /** log2(SS) — nadpróbkowanie x2 to rysowanie w rozdzielczości zoomu o 1 wyżej. */
    /**
     * Przesunięcie „piksele świata -> piksele nadpróbkowane kafla" rozbite na
     * parę NIEUJEMNYCH: [w lewo, w prawo]. Patrz nota w tracks().
     *
     * @return array{0:int,1:int}
     */
    private static function shiftPair(int $z): array
    {
        $shift = TileGrid::shiftFor($z + self::logSs());
        return [max(0, -$shift), max(0, $shift)];
    }

    private static function logSs(): int
    {
        return (int) round(log(self::SS, 2));
    }
}
