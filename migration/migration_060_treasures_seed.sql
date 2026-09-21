-- migration_060_treasures_seed.sql
-- STARTOWY ZESTAW SKARBOW — 100 miejsc w calej Polsce.
--
-- PLIK JEST WYGENEROWANY, nie pisany recznie (generator: scratchpad/gen_skarby.php)
-- i to jest tu najwazniejsza informacja. Powod: kolumna `cell_id` to identyfikator
-- pola siatki odkryc, liczony przez Utils\DiscoveryGrid::pointToCell — czystym
-- SQL-em nie da sie go wyliczyc. Recznie wpisany bylby zgadywaniem, a od niego
-- zalezy zaliczanie skarbu ze sladu GPX (Models\Treasure::claimAlongTrack
-- zawezá kandydatow wlasnie po polu).
--
-- MIGRACJA JEST IDEMPOTENTNA. Kody skarbow sa DETERMINISTYCZNE (skrot z nazwy
-- i wspolrzednych), a `treasures.code` ma klucz unikalny — powtorne uruchomienie
-- nie wstawi ani jednego duplikatu. Mozna ja wiec puscic na test, potem na prod,
-- a w razie watpliwosci jeszcze raz.
--
-- WSZYSTKIE PUNKTY WCHODZA JAKO ACTIVE, origin OFFICIAL i reveal_level 2
-- (widoczne od razu). To zestaw startowy, ktory ma dac mapie tresc od pierwszego
-- dnia — ukrywanie czegokolwiek na starcie znaczyloby puste mapy u wszystkich.
-- Punktacja: domyslna z konfiguracji (patrz scoring_settings), bez wyroznien.
--
-- REGION ZOSTAJE PUSTY (decyzja usera 2026-08-16). Kolumna `region` w danych
-- ponizej opisuje POCHODZENIE punktu i nic wiecej — `treasures.region_item_id`
-- celowo nie jest wypelniane. Skarb i tak jest odnajdywany przez mape i przez
-- pole siatki, a przypisanie regionalne mialoby sens dopiero, gdyby powstala
-- osobna nawigacja „skarby wedlug regionow".
--
-- WERYFIKACJA WSPOLRZEDNYCH: czesc punktow ma w zrodle adnotacje PRZYBLIZONE
-- (srodek miejscowosci zamiast konkretnego obiektu) — sa uzyteczne jako cel
-- wyjazdu, ale przed wydrukiem naklejek warto je sprawdzic w terenie.

-- 1. Kategorie, ktorych slownik jeszcze nie mial.
--    `icon` trzyma KLUCZ do Utils\Icon, nie znak — patrz migracja 057.
INSERT IGNORE INTO dictionaries (code, name) VALUES ('treasure_category', 'Kategoria skarbu');
INSERT IGNORE INTO dictionary_items (dictionary_id, code, name, icon, sort_order, is_active)
SELECT d.id, 'sakralne', 'Miejsce sakralne', 'tre-monument', 110, 1 FROM dictionaries d WHERE d.code = 'treasure_category';
INSERT IGNORE INTO dictionary_items (dictionary_id, code, name, icon, sort_order, is_active)
SELECT d.id, 'przyroda', 'Przyroda', 'tre-water', 120, 1 FROM dictionaries d WHERE d.code = 'treasure_category';
INSERT IGNORE INTO dictionary_items (dictionary_id, code, name, icon, sort_order, is_active)
SELECT d.id, 'krajobraz', 'Krajobraz', 'tre-viewpoint', 130, 1 FROM dictionaries d WHERE d.code = 'treasure_category';
INSERT IGNORE INTO dictionary_items (dictionary_id, code, name, icon, sort_order, is_active)
SELECT d.id, 'geologia', 'Geologia', 'tre-pass', 140, 1 FROM dictionaries d WHERE d.code = 'treasure_category';
INSERT IGNORE INTO dictionary_items (dictionary_id, code, name, icon, sort_order, is_active)
SELECT d.id, 'militaria', 'Militaria', 'tre-monument', 150, 1 FROM dictionaries d WHERE d.code = 'treasure_category';
INSERT IGNORE INTO dictionary_items (dictionary_id, code, name, icon, sort_order, is_active)
SELECT d.id, 'archeologia', 'Archeologia', 'tre-monument', 160, 1 FROM dictionaries d WHERE d.code = 'treasure_category';

-- 2. Skarby. Kategoria i region dowiazywane po KODZIE/NAZWIE, nie po id —
--    identyfikatory slownikow roznia sie miedzy testem a produkcja.
INSERT IGNORE INTO treasures
    (code, name, description, category_item_id, region_item_id, lat, lon, cell_id,
     points, claim_radius_m, rarity, origin, status, reveal_level, is_active)
SELECT s.code, s.name, s.descr,
       (SELECT i.id FROM dictionary_items i JOIN dictionaries d ON d.id = i.dictionary_id
         WHERE d.code = 'treasure_category' AND i.code = s.kat LIMIT 1),
       (SELECT i.id FROM dictionary_items i JOIN dictionaries d ON d.id = i.dictionary_id
         WHERE d.code = 'region' AND i.name = s.region LIMIT 1),
       s.lat, s.lon, s.cell_id, 50, 150, 'COMMON', 'OFFICIAL', 'ACTIVE', 2, 1
  FROM (
        SELECT '1b5fadba57ffcc36' AS code, 'Rezerwat Sine Wiry' AS name, 'Przełomowa dolina rzeczna w Ciśniańsko-Wetlińskim PK.' AS descr, 'przyroda' AS kat, 49.2574600 AS lat, 22.4288800 AS lon, 5764606000468337435 AS cell_id, 'Bieszczady
' AS region
        UNION ALL SELECT 'd26bf66ce2ce1201' AS code, 'Grzbiet Otrytu' AS name, 'Widokowy podjazd szutrem na grzbiet 780 m n.p.m.' AS descr, 'punkt_widokowy' AS kat, 49.2413900 AS lat, 22.6366500 AS lon, 5764606032680592151 AS cell_id, 'Bieszczady
' AS region
        UNION ALL SELECT 'd265987eb58c7aa8' AS code, 'Opuszczone wsie Hulskie, Krywe i Tworylne' AS name, 'Cerkwiska, cmentarze i fundamenty domów po wysiedlonych wsiach.' AS descr, 'zabytek' AS kat, 49.2395200 AS lat, 22.5275700 AS lon, 5764606017648206614 AS cell_id, 'Bieszczady
' AS region
        UNION ALL SELECT '68c844a1140fce00' AS code, 'Cerkiew w Smolniku nad Sanem' AS name, 'Drewniana cerkiew na liście UNESCO, przy Szlaku Filmowym.' AS descr, 'sakralne' AS kat, 49.2095200 AS lat, 22.6879200 AS lon, 5764606044491752207 AS cell_id, 'Bieszczady
' AS region
        UNION ALL SELECT '45f990bc3715cb22' AS code, 'Cerkiew w Michniowcu' AS name, 'XIX-wieczna cerkiew z unikatowym półkolistym ikonostasem.' AS descr, 'sakralne' AS kat, 49.3359100 AS lat, 22.2873400 AS lon, 5764605969329824558 AS cell_id, 'Bieszczady
' AS region
        UNION ALL SELECT '211f1f50ee500821' AS code, 'Jezioro Solińskie i zapora w Solinie' AS name, 'Największy sztuczny zbiornik w Polsce.' AS descr, 'krajobraz' AS kat, 49.3955300 AS lat, 22.4534800 AS lon, 5764605986509693756 AS cell_id, 'Bieszczady
' AS region
        UNION ALL SELECT '9d91b75716f9eabf' AS code, 'Cmentarz wojenny w Łupkowie' AS name, 'Cmentarz z I wojny światowej przy historycznej przełęczy kolejowej.' AS descr, 'zabytek' AS kat, 49.2418500 AS lat, 22.0658300 AS lon, 5764605948928729879 AS cell_id, 'Bieszczady
' AS region
        UNION ALL SELECT 'ba7120d5748d128f' AS code, 'Połonina Wetlińska' AS name, 'Jedna z najbardziej rozpoznawalnych panoram Bieszczadów.' AS descr, 'punkt_widokowy' AS kat, 49.1640500 AS lat, 22.5357200 AS lon, 5764606028385624836 AS cell_id, 'Bieszczady
' AS region
        UNION ALL SELECT 'c0c8d7fa95ea37ff' AS code, 'Ruiny dworu w Bystrem' AS name, 'Pozostałości dawnej zabudowy dworskiej sprzed wysiedleń.' AS descr, 'zabytek' AS kat, 49.3163100 AS lat, 22.7256600 AS lon, 5764606035901817641 AS cell_id, 'Bieszczady
' AS region
        UNION ALL SELECT 'aecbb5892c1a006d' AS code, 'Wołosate i dolina Górnego Sanu' AS name, 'Najdalej na południe wysunięty zakątek Polski.' AS descr, 'krajobraz' AS kat, 49.0673100 AS lat, 22.6797300 AS lon, 5764606061671621357 AS cell_id, 'Bieszczady
' AS region
        UNION ALL SELECT '39c550cc91d7c652' AS code, 'Szumy nad Tanwią' AS name, 'Kaskady rzeki Tanew na skałach wapiennych.' AS descr, 'przyroda' AS kat, 50.3943500 AS lat, 23.1959300 AS lon, 5764605963961115697 AS cell_id, 'Roztocze
' AS region
        UNION ALL SELECT 'd351403f058c3db0' AS code, 'Czartowe Pole' AS name, 'Ruiny XIX-wiecznej papierni i głazy w wąwozie.' AS descr, 'ciekawostka' AS kat, 50.4410400 AS lat, 23.1080300 AS lon, 5764605944633762877 AS cell_id, 'Roztocze
' AS region
        UNION ALL SELECT 'bc3ee7d12b639dd0' AS code, 'Kamieniołom w Nowinach Horynieckich' AS name, '200-metrowe skalne wyrobisko ukryte w sosnowym lesie.' AS descr, 'geologia' AS kat, 50.2198000 AS lat, 23.4027100 AS lon, 5764606017648206854 AS cell_id, 'Roztocze
' AS region
        UNION ALL SELECT 'fb44f1b6cccc462e' AS code, 'Cerkiew w Radrużu' AS name, 'Jeden z najstarszych drewnianych zespołów cerkiewnych w Polsce.' AS descr, 'sakralne' AS kat, 50.1766100 AS lat, 23.4015100 AS lon, 5764606023016915964 AS cell_id, 'Roztocze
' AS region
        UNION ALL SELECT 'c048768096d00626' AS code, 'Ruiny cerkwi w Kniaziach' AS name, 'Plener finałowych scen filmu „Zimna wojna".' AS descr, 'zabytek' AS kat, 50.3366600 AS lat, 23.4958100 AS lon, 5764606015500723235 AS cell_id, 'Roztocze
' AS region
        UNION ALL SELECT '524de6e8af2c623f' AS code, 'Kościół na wodzie w Zwierzyńcu' AS name, 'Kościół św. Jana Nepomucena na wyspie stawu.' AS descr, 'sakralne' AS kat, 50.6090100 AS lat, 22.9678500 AS lon, 5764605901684089959 AS cell_id, 'Roztocze
' AS region
        UNION ALL SELECT '5af71301403ece5a' AS code, 'Bunkry Linii Mołotowa' AS name, 'Sowieckie schrony bojowe z 1940 roku wzdłuż dawnej granicy.' AS descr, 'militaria' AS kat, 50.0294000 AS lat, 23.0701000 AS lon, 5764605994025886679 AS cell_id, 'Roztocze
' AS region
        UNION ALL SELECT '7f191417672c0a8e' AS code, 'Długi Goraj' AS name, 'Najwyższy szczyt Roztocza.' AS descr, 'punkt_widokowy' AS kat, 50.3013100 AS lat, 23.4521100 AS lon, 5764606013353239579 AS cell_id, 'Roztocze
' AS region
        UNION ALL SELECT 'f2cce52889bd6504' AS code, 'Zamek w Baranowie Sandomierskim' AS name, 'Renesansowa rezydencja nazywana „małym Wawelem".' AS descr, 'zabytek' AS kat, 50.5020000 AS lat, 21.5356100 AS lon, 5764605705189336140 AS cell_id, 'Podkarpacie
' AS region
        UNION ALL SELECT '93dd9a48607635b1' AS code, 'Wieża widokowa na Magdalence' AS name, 'Punkt fotograficzny z drewnianym kościółkiem i ławeczkami.' AS descr, 'punkt_widokowy' AS kat, 50.0125900 AS lat, 22.1292100 AS lon, 5764605857660675027 AS cell_id, 'Podkarpacie
' AS region
        UNION ALL SELECT '5cdcad8d0923fb9f' AS code, 'Wiatraki w Soninie' AS name, 'Zabytkowy zespół wiatraków typu koźlak.' AS descr, 'ciekawostka' AS kat, 50.0630600 AS lat, 22.2847500 AS lon, 5764605873766802399 AS cell_id, 'Podkarpacie
' AS region
        UNION ALL SELECT '6699d5221e918556' AS code, 'Skansen w Markowej i Muzeum Ulmów' AS name, 'Muzeum Polaków Ratujących Żydów im. Rodziny Ulmów.' AS descr, 'zabytek' AS kat, 50.0227300 AS lat, 22.3170400 AS lon, 5764605883430478806 AS cell_id, 'Podkarpacie
' AS region
        UNION ALL SELECT 'c21c898a990d622a' AS code, 'Pałac w Łańcucie' AS name, 'Jedna z najpiękniejszych rezydencji arystokratycznych w Polsce.' AS descr, 'zabytek' AS kat, 50.0686700 AS lat, 22.2345900 AS lon, 5764605865176867809 AS cell_id, 'Podkarpacie
' AS region
        UNION ALL SELECT '2a9e089ba060197e' AS code, 'Arboretum w Bolestraszycach' AS name, 'Kolekcja rzadkich drzew i krzewów pod Przemyślem.' AS descr, 'przyroda' AS kat, 49.8175900 AS lat, 22.8629900 AS lon, 5764605990804661155 AS cell_id, 'Podkarpacie
' AS region
        UNION ALL SELECT 'a4f5e79e2f708653' AS code, 'Karpacka Troja' AS name, 'Zrekonstruowane grodzisko sprzed ok. 4000 lat.' AS descr, 'archeologia' AS kat, 49.7320800 AS lat, 21.4332200 AS lon, 5764605792162423694 AS cell_id, 'Podkarpacie
' AS region
        UNION ALL SELECT 'c45870a484b16002' AS code, 'Forty Twierdzy Przemyśl' AS name, 'Pierścień XIX-wiecznych fortyfikacji austro-węgierskich.' AS descr, 'militaria' AS kat, 49.8216900 AS lat, 22.8613200 AS lon, 5764605990804661156 AS cell_id, 'Podkarpacie
' AS region
        UNION ALL SELECT '89a360db65a0953d' AS code, 'Schron kolejowy w Stępinie' AS name, 'Niedokończona kwatera dowodzenia Wehrmachtu z 1940 roku.' AS descr, 'militaria' AS kat, 49.8720400 AS lat, 21.5914200 AS lon, 5764605796457391025 AS cell_id, 'Podkarpacie
' AS region
        UNION ALL SELECT '806ce6b17b2f269d' AS code, 'Wielka Czantoria' AS name, 'Wieża widokowa na jednym z najwyższych szczytów Beskidu Śląskiego.' AS descr, 'punkt_widokowy' AS kat, 49.6964000 AS lat, 18.8274200 AS lon, 5764605413131559813 AS cell_id, 'Beskidy
' AS region
        UNION ALL SELECT 'a3c7e08610347aff' AS code, 'Skrzyczne' AS name, 'Najwyższy szczyt Beskidu Śląskiego (1257 m) ze schroniskiem PTTK.' AS descr, 'punkt_widokowy' AS kat, 49.6844100 AS lat, 19.0320300 AS lon, 5764605444270072707 AS cell_id, 'Beskidy
' AS region
        UNION ALL SELECT '842a2f596996f88f' AS code, 'Barania Góra — źródła Wisły' AS name, 'Miejsce, w którym symbolicznie rodzi się najdłuższa polska rzeka.' AS descr, 'ciekawostka' AS kat, 49.6087100 AS lat, 19.0079000 AS lon, 5764605450712523632 AS cell_id, 'Beskidy
' AS region
        UNION ALL SELECT '52ea7c322545907e' AS code, 'Ochodzita' AS name, 'Jeden z najbardziej widokowych zjazdów w Beskidzie Śląskim.' AS descr, 'punkt_widokowy' AS kat, 49.5456100 AS lat, 18.9551200 AS lon, 5764605450712523617 AS cell_id, 'Beskidy
' AS region
        UNION ALL SELECT 'deab4f74fa3b06d0' AS code, 'Malinowska Skała' AS name, 'Punkt widokowy na szlaku grzbietowym pod Skrzycznem.' AS descr, 'punkt_widokowy' AS kat, 49.6558300 AS lat, 19.0008400 AS lon, 5764605443196330876 AS cell_id, 'Beskidy
' AS region
        UNION ALL SELECT 'f22be46bc8308193' AS code, 'Wieża widokowa na Chełmku' AS name, 'Wieża na pograniczu Beskidów i Jury.' AS descr, 'punkt_widokowy' AS kat, 50.0999300 AS lat, 19.2396200 AS lon, 5764605419574010857 AS cell_id, 'Beskidy
' AS region
        UNION ALL SELECT '6461240e640c0310' AS code, 'Magurski Park Narodowy' AS name, 'Najcichsza i najdziksza część Beskidu Niskiego.' AS descr, 'przyroda' AS kat, 49.5092500 AS lat, 21.4698300 AS lon, 5764605826522162008 AS cell_id, 'Beskidy
' AS region
        UNION ALL SELECT '459368c2d7a47365' AS code, 'Cerkiew łemkowska w Kwiatoniu' AS name, 'Cerkiew łemkowska wpisana na listę UNESCO.' AS descr, 'sakralne' AS kat, 49.5008300 AS lat, 21.1738100 AS lon, 5764605783572489046 AS cell_id, 'Beskidy
' AS region
        UNION ALL SELECT 'cd63406b8d57ad0e' AS code, 'Zamek Ogrodzieniec' AS name, 'Największa ruina Szlaku Orlich Gniazd.' AS descr, 'zabytek' AS kat, 50.4531200 AS lat, 19.5520400 AS lon, 5764605419574010944 AS cell_id, 'Jura
' AS region
        UNION ALL SELECT '4f3f46179300125a' AS code, 'Zamek Bobolice' AS name, 'Odbudowany zamek bliźniaczy z widokiem na Mirów.' AS descr, 'zabytek' AS kat, 50.6134100 AS lat, 19.4932500 AS lon, 5764605389509239912 AS cell_id, 'Jura
' AS region
        UNION ALL SELECT '8aeb9fa862a1fa79' AS code, 'Zamek Mirów' AS name, 'Malownicza ruina zamku tuż obok Bobolic.' AS descr, 'zabytek' AS kat, 50.6145400 AS lat, 19.4752600 AS lon, 5764605386288014440 AS cell_id, 'Jura
' AS region
        UNION ALL SELECT '3d262c2d1ad0f535' AS code, 'Ruiny zamku w Olsztynie' AS name, 'Efektowna ruina na wapiennym wzgórzu pod Częstochową.' AS descr, 'zabytek' AS kat, 50.7497000 AS lat, 19.2742000 AS lon, 5764605339043374218 AS cell_id, 'Jura
' AS region
        UNION ALL SELECT '705b0732193bb871' AS code, 'Skała Miłości' AS name, 'Charakterystyczny ostaniec skalny owiany legendą.' AS descr, 'geologia' AS kat, 50.8294400 AS lat, 19.2911500 AS lon, 5764605330453439646 AS cell_id, 'Jura
' AS region
        UNION ALL SELECT '0e60641af070555d' AS code, 'Okiennik Wielki' AS name, 'Jedna z największych bram skalnych w Polsce.' AS descr, 'geologia' AS kat, 50.5241100 AS lat, 19.5230300 AS lon, 5764605405615367250 AS cell_id, 'Jura
' AS region
        UNION ALL SELECT '605e2a82a84517d1' AS code, 'Pustynia Błędowska' AS name, 'Największy obszar piasków lotnych w Polsce.' AS descr, 'krajobraz' AS kat, 50.3406800 AS lat, 19.5240400 AS lon, 5764605430311429156 AS cell_id, 'Jura
' AS region
        UNION ALL SELECT '11479369f42f439d' AS code, 'Pałac Raczyńskich w Złotym Potoku' AS name, 'Pałac ze stawem „Amerykan" na Szlaku Orlich Gniazd.' AS descr, 'zabytek' AS kat, 50.7138200 AS lat, 19.4397800 AS lon, 5764605368034403457 AS cell_id, 'Jura
' AS region
        UNION ALL SELECT 'a3b551c7cbdfe60b' AS code, 'Zamek Książ' AS name, 'Jeden z największych zamków w Polsce, z podziemiami z czasów III Rzeszy.' AS descr, 'zabytek' AS kat, 50.8423100 AS lat, 16.2915700 AS lon, 5764604886998066337 AS cell_id, 'Sudety
' AS region
        UNION ALL SELECT '52c845f6aa013f0a' AS code, 'Zamek Bolków' AS name, 'XIII-wieczny zamek górujący nad miastem.' AS descr, 'zabytek' AS kat, 50.9217600 AS lat, 16.0978700 AS lon, 5764604848343360693 AS cell_id, 'Sudety
' AS region
        UNION ALL SELECT '5ae3a4ae7c7218a3' AS code, 'Zamek Grodno' AS name, 'Malowniczo położony nad Jeziorem Bystrzyckim.' AS descr, 'zabytek' AS kat, 50.7497800 AS lat, 16.4108500 AS lon, 5764604917062837386 AS cell_id, 'Sudety
' AS region
        UNION ALL SELECT '3a54e22468c36e43' AS code, 'Śnieżka' AS name, 'Najwyższy szczyt Sudetów (1602 m) z obserwatorium.' AS descr, 'punkt_widokowy' AS kat, 50.7363200 AS lat, 15.7397200 AS lon, 5764604820426073223 AS cell_id, 'Sudety
' AS region
        UNION ALL SELECT '6950d7927ebfe3db' AS code, 'Kolorowe Jeziorka' AS name, 'Zalane wyrobiska dawnej kopalni barwiące wodę na różne kolory.' AS descr, 'geologia' AS kat, 50.8299200 AS lat, 15.9734100 AS lon, 5764604841900909726 AS cell_id, 'Sudety
' AS region
        UNION ALL SELECT 'b96227f70caf409a' AS code, 'Wieża widokowa na Wielkiej Sowie' AS name, 'Punkt widokowy na sieci tras MTB Gór Sowich.' AS descr, 'punkt_widokowy' AS kat, 50.6803200 AS lat, 16.4857000 AS lon, 5764604937463932025 AS cell_id, 'Sudety
' AS region
        UNION ALL SELECT '1e2b33b848425520' AS code, 'Sokołowsko' AS name, 'Dawne uzdrowisko przeciwgruźlicze, „dolnośląskie Davos".' AS descr, 'zabytek' AS kat, 50.6847100 AS lat, 16.2323300 AS lon, 5764604899882968186 AS cell_id, 'Sudety
' AS region
        UNION ALL SELECT '28fecdf2852f12ed' AS code, 'Mauzoleum Totenburg' AS name, 'Monumentalna budowla na wzgórzu w centrum Wałbrzycha.' AS descr, 'ciekawostka' AS kat, 50.7649400 AS lat, 16.2972300 AS lon, 5764604898809226382 AS cell_id, 'Sudety
' AS region
        UNION ALL SELECT '668736bc02cab95f' AS code, 'Dolina Baryczy' AS name, 'Jeden z największych kompleksów stawów rybnych w Europie.' AS descr, 'przyroda' AS kat, 51.5038700 AS lat, 17.1095100 AS lon, 5764604918136579400 AS cell_id, 'Sudety
' AS region
        UNION ALL SELECT 'd099a378122bbfac' AS code, 'Twierdza Boyen' AS name, 'XIX-wieczna gwiaździsta fortyfikacja pruska z muzeum.' AS descr, 'militaria' AS kat, 54.0341600 AS lat, 21.7499200 AS lon, 5764605245627836383 AS cell_id, 'Mazury
' AS region
        UNION ALL SELECT 'a95d93dc8cdf6420' AS code, 'Bunkry w Mamerkach' AS name, 'Kompleks kwatery głównej niemieckich wojsk lądowych.' AS descr, 'militaria' AS kat, 54.1850300 AS lat, 21.6441600 AS lon, 5764605208046872583 AS cell_id, 'Mazury
' AS region
        UNION ALL SELECT 'ac5a6eea1cb16c69' AS code, 'Wilczy Szaniec' AS name, 'Dawna kwatera główna Hitlera w Gierłoży.' AS descr, 'militaria' AS kat, 54.0806500 AS lat, 21.4942900 AS lon, 5764605201604421611 AS cell_id, 'Mazury
' AS region
        UNION ALL SELECT '022e7ad92cb568c6' AS code, 'Piramida w Rapie' AS name, 'Tajemnicza XIX-wieczna budowla grobowa w kształcie piramidy.' AS descr, 'ciekawostka' AS kat, 54.3099400 AS lat, 22.0217700 AS lon, 5764605245627836457 AS cell_id, 'Mazury
' AS region
        UNION ALL SELECT '2d86d9c9a2244e03' AS code, 'Sztynort' AS name, 'Ruiny pałacu Lehndorffów i osada żeglarska nad Mamrami.' AS descr, 'zabytek' AS kat, 54.1321800 AS lat, 21.6844200 AS lon, 5764605222005516281 AS cell_id, 'Mazury
' AS region
        UNION ALL SELECT 'c5643b15fc457aca' AS code, 'Puszcza Piska' AS name, 'Jeden z największych kompleksów leśnych w Polsce.' AS descr, 'przyroda' AS kat, 53.6016700 AS lat, 21.5980600 AS lon, 5764605285356283755 AS cell_id, 'Mazury
' AS region
        UNION ALL SELECT 'ecec804cffc88c5d' AS code, 'Zamek w Rynie' AS name, 'Krzyżacki zamek położony między jeziorami.' AS descr, 'zabytek' AS kat, 53.9388700 AS lat, 21.5460000 AS lon, 5764605229521708997 AS cell_id, 'Mazury
' AS region
        UNION ALL SELECT '11e614524575efa2' AS code, 'Grodzisko Galindów' AS name, 'Ślady osadnictwa bałtyjskiego ludu Galindów.' AS descr, 'archeologia' AS kat, 53.9751200 AS lat, 21.6355200 AS lon, 5764605237037901775 AS cell_id, 'Mazury
' AS region
        UNION ALL SELECT '609680c74d9ac758' AS code, 'Kamienne kręgi w Odrach' AS name, 'Gocki cmentarz z kręgami sprzed ok. 1800 lat.' AS descr, 'archeologia' AS kat, 53.8983100 AS lat, 17.9935500 AS lon, 5764604711978149818 AS cell_id, 'Kaszuby
' AS region
        UNION ALL SELECT 'a146242939050371' AS code, 'Kamienne kręgi w Węsiorach' AS name, 'Trzy kręgi kamienne, 20 kurhanów i ok. 110 grobów.' AS descr, 'archeologia' AS kat, 54.2177800 AS lat, 17.8400000 AS lon, 5764604643258673168 AS cell_id, 'Kaszuby
' AS region
        UNION ALL SELECT '2ea874b6e04cf9a4' AS code, 'Wieżyca' AS name, 'Wieża widokowa na najwyższym wzniesieniu Pomorza.' AS descr, 'punkt_widokowy' AS kat, 54.2235100 AS lat, 18.1234300 AS lon, 5764604684060862482 AS cell_id, 'Kaszuby
' AS region
        UNION ALL SELECT '6f95e7cdca6eefe6' AS code, 'Zamek w Łapalicach' AS name, 'Niedokończony prywatny „polski Camelot" z lat 80.' AS descr, 'ciekawostka' AS kat, 54.3462500 AS lat, 18.1292800 AS lon, 5764604666880993331 AS cell_id, 'Kaszuby
' AS region
        UNION ALL SELECT '137efdfb0a11e772' AS code, 'Skansen w Wdzydzach Kiszewskich' AS name, 'Najstarszy skansen na Pomorzu.' AS descr, 'zabytek' AS kat, 54.0108500 AS lat, 17.9441300 AS lon, 5764604688355829721 AS cell_id, 'Kaszuby
' AS region
        UNION ALL SELECT '9c5d4d55a336a12c' AS code, 'Bory Tucholskie' AS name, 'Wielki kompleks leśny poprzecinany rzeką Brdą.' AS descr, 'przyroda' AS kat, 53.8151700 AS lat, 17.5648300 AS lon, 5764604660438542244 AS cell_id, 'Kaszuby
' AS region
        UNION ALL SELECT 'e8a761d89b58d330' AS code, 'Odwrócony dom w Szymbarku' AS name, 'Dom postawiony „do góry nogami" obok najdłuższej deski świata.' AS descr, 'ciekawostka' AS kat, 54.2048200 AS lat, 18.1061000 AS lon, 5764604684060862477 AS cell_id, 'Kaszuby
' AS region
        UNION ALL SELECT '963ca5f1327faf64' AS code, 'Ruchome wydmy Słowińskiego PN' AS name, 'Jedyna taka wędrująca wydma w tej części Europy.' AS descr, 'przyroda' AS kat, 54.7555200 AS lat, 17.4715300 AS lon, 5764604510114687139 AS cell_id, 'Kaszuby
' AS region
        UNION ALL SELECT '3af0e19d1d6da958' AS code, 'Biskupin' AS name, 'Zrekonstruowana osada obronna sprzed ok. 2700 lat.' AS descr, 'archeologia' AS kat, 52.7854900 AS lat, 17.7415500 AS lon, 5764604833310975635 AS cell_id, 'Wielkopolska
' AS region
        UNION ALL SELECT '15d3a649f48444a8' AS code, 'Rezerwat Meteorytów w Morasku' AS name, 'Jedyne w Polsce miejsce z zachowanymi kraterami meteorytowymi.' AS descr, 'geologia' AS kat, 52.4900900 AS lat, 16.8960400 AS lon, 5764604750632855110 AS cell_id, 'Wielkopolska
' AS region
        UNION ALL SELECT 'b3617b930310cc26' AS code, 'Parowozownia Wolsztyn' AS name, 'Jedyna czynna zabytkowa parowozownia w Europie.' AS descr, 'ciekawostka' AS kat, 52.1062500 AS lat, 16.1130900 AS lon, 5764604688355829219 AS cell_id, 'Wielkopolska
' AS region
        UNION ALL SELECT '592bd5ef9c98a874' AS code, 'Zamek w Kórniku' AS name, 'Neogotycki zamek z rozległym arboretum.' AS descr, 'zabytek' AS kat, 52.2439200 AS lat, 17.0908800 AS lon, 5764604812909880838 AS cell_id, 'Wielkopolska
' AS region
        UNION ALL SELECT '3d1fb6910c948499' AS code, 'Park Orientacji Przestrzennej w Owińskach' AS name, 'Jedyny taki „ogród zmysłów" w Polsce.' AS descr, 'ciekawostka' AS kat, 52.5109200 AS lat, 16.9744600 AS lon, 5764604759222789707 AS cell_id, 'Wielkopolska
' AS region
        UNION ALL SELECT 'e0b5f1710bc37a4c' AS code, 'Katedra Gnieźnieńska' AS name, 'Pierwsza stolica Polski i słynne Drzwi Gnieźnieńskie.' AS descr, 'sakralne' AS kat, 52.5367000 AS lat, 17.5928000 AS lon, 5764604846195877458 AS cell_id, 'Wielkopolska
' AS region
        UNION ALL SELECT '7d7ad75023abcf33' AS code, 'Kościół w Wierzenicy' AS name, 'XVI-wieczny drewniany kościół nad Jeziorem Swarzędzkim.' AS descr, 'sakralne' AS kat, 52.4634800 AS lat, 17.0689200 AS lon, 5764604779623884351 AS cell_id, 'Wielkopolska
' AS region
        UNION ALL SELECT 'cf75fa1f8249f069' AS code, 'Łysica i gołoborza' AS name, 'Najwyższy szczyt regionu z polami skalnych rumowisk.' AS descr, 'geologia' AS kat, 50.8921100 AS lat, 20.8952400 AS lon, 5764605558086706350 AS cell_id, 'Świętokrzyskie
' AS region
        UNION ALL SELECT '25a7788c17efa2f9' AS code, 'Klasztor na Świętym Krzyżu' AS name, 'Jeden z najstarszych klasztorów benedyktyńskich w Polsce.' AS descr, 'sakralne' AS kat, 50.8594500 AS lat, 21.0529700 AS lon, 5764605586003993766 AS cell_id, 'Świętokrzyskie
' AS region
        UNION ALL SELECT '8384c700d903631c' AS code, 'Zamek Krzyżtopór' AS name, '„Kalendarzowy" pałac: 365 okien, 52 komnaty, 12 sal, 4 baszty.' AS descr, 'zabytek' AS kat, 50.7140700 AS lat, 21.3104700 AS lon, 5764605642912310401 AS cell_id, 'Świętokrzyskie
' AS region
        UNION ALL SELECT 'c7587bf31b17812f' AS code, 'Ruiny zamku w Chęcinach' AS name, 'Warownia górująca nad okolicą, ikona regionu.' AS descr, 'zabytek' AS kat, 50.7975900 AS lat, 20.4596500 AS lon, 5764605506547098774 AS cell_id, 'Świętokrzyskie
' AS region
        UNION ALL SELECT 'dea7ed7a2a455b5a' AS code, 'Tropy tetrapoda w Zachełmiu' AS name, 'Jedne z najstarszych na świecie śladów zwierząt lądowych.' AS descr, 'geologia' AS kat, 50.9691700 AS lat, 20.6905600 AS lon, 5764605517284517057 AS cell_id, 'Świętokrzyskie
' AS region
        UNION ALL SELECT '1169bf0495d21eae' AS code, 'Sandomierz — Stare Miasto' AS name, 'Jeden z najpiękniejszych rynków w Polsce.' AS descr, 'zabytek' AS kat, 50.6791900 AS lat, 21.7494800 AS lon, 5764605712705528952 AS cell_id, 'Świętokrzyskie
' AS region
        UNION ALL SELECT '821b14cd46e3ea3e' AS code, 'Zamek w Niedzicy' AS name, 'Zamek „Dunajec" nad Jeziorem Czorsztyńskim.' AS descr, 'zabytek' AS kat, 49.4224900 AS lat, 20.3196400 AS lon, 5764605668682113858 AS cell_id, 'Małopolska
' AS region
        UNION ALL SELECT '3aa284fda70e3683' AS code, 'Ruiny zamku w Czorsztynie' AS name, 'Ruina naprzeciw Niedzicy, po drugiej stronie jeziora.' AS descr, 'zabytek' AS kat, 49.4351100 AS lat, 20.3132000 AS lon, 5764605665460888390 AS cell_id, 'Małopolska
' AS region
        UNION ALL SELECT '20387ffb5f994b4c' AS code, 'Trzy Korony' AS name, 'Jeden z najbardziej rozpoznawalnych szczytów widokowych w Polsce.' AS descr, 'punkt_widokowy' AS kat, 49.4140000 AS lat, 20.4139700 AS lon, 5764605683714499392 AS cell_id, 'Małopolska
' AS region
        UNION ALL SELECT 'f37d94d773849688' AS code, 'Sokolica' AS name, 'Druga co do wysokości góra Pienin, znana z sosny na skale.' AS descr, 'punkt_widokowy' AS kat, 49.4179200 AS lat, 20.4400200 AS lon, 5764605686935724865 AS cell_id, 'Małopolska
' AS region
        UNION ALL SELECT 'ea7908ca33595aa7' AS code, 'Przełom Dunajca' AS name, 'Jeden z najpiękniejszych przełomów rzecznych w Europie.' AS descr, 'krajobraz' AS kat, 49.4226900 AS lat, 20.4594600 AS lon, 5764605689083208515 AS cell_id, 'Małopolska
' AS region
        UNION ALL SELECT '6d9df4bbc40205ff' AS code, 'Willa Koliba' AS name, 'Pierwszy budynek w stylu zakopiańskim Witkiewicza.' AS descr, 'zabytek' AS kat, 49.2943300 AS lat, 19.9429700 AS lon, 5764605630027408163 AS cell_id, 'Małopolska
' AS region
        UNION ALL SELECT '548203c009e5dbe5' AS code, 'Dwór Tetmajerów w Łopusznej' AS name, 'Zabytkowy dwór, oddział Muzeum Tatrzańskiego.' AS descr, 'zabytek' AS kat, 49.4739300 AS lat, 20.1277900 AS lon, 5764605633248633679 AS cell_id, 'Małopolska
' AS region
        UNION ALL SELECT '181480c9996d6a25' AS code, 'Maczuga Herkulesa' AS name, 'Charakterystyczny wapienny ostaniec w Ojcowskim PN.' AS descr, 'geologia' AS kat, 50.2429100 AS lat, 19.7829600 AS lon, 5764605480777294860 AS cell_id, 'Małopolska
' AS region
        UNION ALL SELECT '2f280eea4f3fc8f5' AS code, 'Meczet w Kruszynianach' AS name, 'Najstarszy zachowany meczet tatarski w Polsce.' AS descr, 'sakralne' AS kat, 53.1777600 AS lat, 23.8137600 AS lon, 5764605671903340282 AS cell_id, 'Podlasie
' AS region
        UNION ALL SELECT '5c09f5eed9cb3136' AS code, 'Meczet w Bohonikach' AS name, 'Drugi z zabytkowych meczetów tatarskich Podlasia.' AS descr, 'sakralne' AS kat, 53.3906200 AS lat, 23.5914700 AS lon, 5764605608552572723 AS cell_id, 'Podlasie
' AS region
        UNION ALL SELECT '7aa6f19791d86c7d' AS code, 'Rezerwat Pokazowy Żubrów' AS name, 'Żubry w warunkach zbliżonych do naturalnych.' AS descr, 'przyroda' AS kat, 52.7053500 AS lat, 23.7962000 AS lon, 5764605736327849598 AS cell_id, 'Podlasie
' AS region
        UNION ALL SELECT 'f24f1a719ac67c40' AS code, 'Święta Góra Grabarka' AS name, 'Prawosławne sanktuarium pokryte tysiącami krzyży.' AS descr, 'sakralne' AS kat, 52.4165300 AS lat, 23.0059600 AS lon, 5764605660092180019 AS cell_id, 'Podlasie
' AS region
        UNION ALL SELECT '4a50e45da83d09d5' AS code, 'Kraina Otwartych Okiennic' AS name, 'Kolorowe, ręcznie zdobione okiennice podlaskich chat.' AS descr, 'ciekawostka' AS kat, 52.9394100 AS lat, 23.4456800 AS lon, 5764605651502245563 AS cell_id, 'Podlasie
' AS region
        UNION ALL SELECT 'e1324f0c0a50c21a' AS code, 'Klasztor Pokamedulski w Wigrach' AS name, 'Klasztor na półwyspie Jeziora Wigry.' AS descr, 'sakralne' AS kat, 54.0689600 AS lat, 23.0852300 AS lon, 5764605436753881064 AS cell_id, 'Podlasie
' AS region
        UNION ALL SELECT '9847ad19b69c3d27' AS code, 'Tykocin — rynek i synagoga' AS name, 'Jedno z najlepiej zachowanych małych miasteczek regionu.' AS descr, 'zabytek' AS kat, 53.2067100 AS lat, 22.7672400 AS lon, 5764605514063292162 AS cell_id, 'Podlasie
' AS region
        UNION ALL SELECT '773261d7ae4cd8e7' AS code, 'Twierdza Osowiec' AS name, 'Carska twierdza ukryta pośród bagien Biebrzy.' AS descr, 'militaria' AS kat, 53.4747400 AS lat, 22.6520200 AS lon, 5764605458228717385 AS cell_id, 'Podlasie
' AS region
        UNION ALL SELECT '258adbc3e99b094a' AS code, 'Trygław' AS name, 'Drugi co do wielkości głaz narzutowy w Polsce.' AS descr, 'geologia' AS kat, 53.9315500 AS lat, 16.2609600 AS lon, 5764604452132628419 AS cell_id, 'Zachodniopomorskie
' AS region
        UNION ALL SELECT 'f68ab12a80394164' AS code, 'Głaz Mszczonowski' AS name, 'Jeden z największych głazów narzutowych na Mazowszu.' AS descr, 'geologia' AS kat, 51.9141800 AS lat, 20.4543700 AS lon, 5764605354075760049 AS cell_id, 'Mazowsze
' AS region
        UNION ALL SELECT '2c9528d4d91283dd' AS code, 'Kościół w Dębnie Podhalańskim' AS name, 'Gotycki drewniany kościół z listy UNESCO.' AS descr, 'sakralne' AS kat, 49.4659300 AS lat, 20.2113700 AS lon, 5764605647207277389 AS cell_id, 'Małopolska' AS region
  ) AS s;

