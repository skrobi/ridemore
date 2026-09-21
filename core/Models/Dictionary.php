<?php
// core/Models/Dictionary.php
namespace Models;

use Core\Database;

class Dictionary
{
    // Cache w pamięci procesu, per request (statyczne właściwości nie
    // przeżywają requestu w standardowym modelu PHP-FPM/mod_php) — id() jest
    // wołane dziesiątki razy na zapis pojedynczego eventu (etapy, cennik,
    // sprzęt), zawsze po te same, rzadko zmieniające się pary (dict, code).
    // Bez cache'a to tyle samo osobnych round-tripów do bazy.
    private static array $idCache = [];
    // Aktywne pozycje danego słownika (np. 'region', 'bike_type') do budowy
    // filtrów bez hardkodowania wartości w widoku — nowa pozycja w bazie
    // pojawia się w UI automatycznie, bez zmian w kodzie.
    //
    // Zwraca tylko LIŚCIE (pozycje bez aktywnych dzieci) — dla słowników bez
    // hierarchii (wszystkie oprócz 'region') warunek jest zawsze prawdziwy
    // (nic ich nie wskazuje jako rodzica), więc zachowanie jest identyczne
    // jak przed dodaniem parent_id. Dla 'region' ukrywa kontenery-kraje
    // (np. "Polska"), zostawiając tylko wybieralne regiony — patrz
    // migration_013_dictionary_hierarchy.sql.
    public static function items(string $dictionaryCode): array
    {
        $stmt = Database::connection()->prepare('
            -- `id` i `icon` doszly przy skarbach (migr. 055): formularz musi
            -- zapisac wybor kluczem obcym, a nie kodem, a kategorie maja symbol
            -- pokazywany na mapie. Reszta wolajacych bierze co potrzebuje
            -- i ignoruje nadmiar.
            SELECT di.id, di.code, di.name, di.icon
            FROM dictionary_items di
            JOIN dictionaries d ON d.id = di.dictionary_id
            WHERE d.code = :code AND di.is_active = 1
              AND NOT EXISTS (
                  SELECT 1 FROM dictionary_items child
                  WHERE child.parent_id = di.id AND child.is_active = 1
              )
            ORDER BY di.sort_order ASC
        ');
        $stmt->execute(['code' => $dictionaryCode]);
        // Nazwy pozycji w języku strony (wielojęzyczność, 2026-09-16). Pozycje
        // zakłada migracja, więc to „kod", a nie treść — tłumaczy je słownik
        // interfejsu. Formularze zapisują KOD, nie nazwę, więc nic się nie psuje.
        return self::przetlumaczNazwy($stmt->fetchAll());
    }

    /** @param array<int,array> $rows */
    private static function przetlumaczNazwy(array $rows): array
    {
        if (\Core\Lang::isDefault()) {
            return $rows;
        }
        foreach ($rows as &$row) {
            if (isset($row['name']) && is_string($row['name'])) {
                $row['name'] = __($row['name']);
            }
        }
        return $rows;
    }

    // Jak items(), ale zachowuje grupowanie po elemencie najwyższego poziomu —
    // pod UI, gdzie hierarchia ma się przełożyć na wizualne pogrupowanie (np.
    // <optgroup> per kraj w select regionu, nagłówki krajów w filtrach).
    // Element bez aktywnych dzieci (każda pozycja w słownikach bez hierarchii,
    // plus każdy prawdziwy liść) trafia do własnej grupy z label=null — widok
    // renderuje ją jako zwykłą, niepogrupowaną opcję, dokładnie jak items()
    // dotąd. Element z dziećmi zbiera WSZYSTKIE aktywne liście z całego swojego
    // poddrzewa (dowolna głębokość) pod jedną grupę z label = jego nazwa.
    public static function groupedLeaves(string $dictionaryCode): array
    {
        $collectLeaves = function (array $nodes) use (&$collectLeaves): array {
            $leaves = [];
            foreach ($nodes as $node) {
                if (!$node['isActive']) continue;
                $activeChildren = array_filter($node['children'], fn($c) => $c['isActive']);
                if (empty($activeChildren)) {
                    $leaves[] = ['code' => $node['code'], 'name' => $node['name']];
                } else {
                    $leaves = array_merge($leaves, $collectLeaves($node['children']));
                }
            }
            return $leaves;
        };

        $groups = [];
        foreach (self::tree($dictionaryCode) as $node) {
            if (!$node['isActive']) continue;
            $activeChildren = array_filter($node['children'], fn($c) => $c['isActive']);
            if (empty($activeChildren)) {
                $groups[] = ['label' => null, 'items' => [['code' => $node['code'], 'name' => $node['name']]]];
            } else {
                $groups[] = ['label' => $node['name'], 'items' => $collectLeaves($node['children'])];
            }
        }
        return $groups;
    }

    // (dictionaryCode, itemCode) -> id dictionary_items, albo null gdy pusty
    // kod/nie znaleziono. Jedyne miejsce w aplikacji, które robi ten lookup —
    // formularz eventu wysyła same kody, zapis do bazy potrzebuje id.
    public static function id(string $dictionaryCode, ?string $itemCode): ?int
    {
        if ($itemCode === null || $itemCode === '') {
            return null;
        }
        $cacheKey = $dictionaryCode . ':' . $itemCode;
        if (array_key_exists($cacheKey, self::$idCache)) {
            return self::$idCache[$cacheKey];
        }
        $stmt = Database::connection()->prepare('
            SELECT di.id
            FROM dictionary_items di
            JOIN dictionaries d ON d.id = di.dictionary_id
            WHERE d.code = :dict AND di.code = :item
        ');
        $stmt->execute(['dict' => $dictionaryCode, 'item' => $itemCode]);
        $id = $stmt->fetchColumn();
        return self::$idCache[$cacheKey] = ($id !== false ? (int) $id : null);
    }

    // Lista wszystkich słowników — pod selektor w /admin/taksonomia.
    public static function dictionaries(): array
    {
        $stmt = Database::connection()->query('
            SELECT id, code, name, description FROM dictionaries ORDER BY name ASC
        ');
        return $stmt->fetchAll();
    }

    // Pełne drzewo pozycji (aktywnych I nieaktywnych — admin musi widzieć
    // wszystko, żeby móc przełączać/edytować) danego słownika. Jedno płaskie
    // zapytanie + budowa drzewa w PHP grupowaniem po parent_id — prostsze niż
    // rekurencyjne CTE i spójne z resztą aplikacji (lekkie kształtowanie
    // danych w PHP zamiast w SQL, patrz np. EventComment::forEvent()).
    //
    // NIESIE `meta` I `icon` (dołożone w Etapie 2 warstw mapy, 2026-08-26 —
    // do tej daty `tree()` ich nie wybierało, choć kolumny istniały od dawna).
    // `meta` wraca ZDEKODOWANE do tablicy — pusty JSON/NULL daje `[]`, więc
    // wołający nigdy nie sprawdza `is_string`/`json_decode` sam. Koszt dla
    // jedynego dotychczasowego wołającego (panel taksonomii) jest zerowy:
    // dwie dodatkowe kolumny w tym samym, już wykonywanym zapytaniu.
    public static function tree(string $dictionaryCode): array
    {
        $stmt = Database::connection()->prepare('
            SELECT di.id, di.parent_id, di.code, di.name, di.sort_order, di.is_active,
                   di.meta, di.icon
            FROM dictionary_items di
            JOIN dictionaries d ON d.id = di.dictionary_id
            WHERE d.code = :code
            ORDER BY di.sort_order ASC, di.name ASC
        ');
        $stmt->execute(['code' => $dictionaryCode]);
        $rows = $stmt->fetchAll();

        $byId = [];
        foreach ($rows as $row) {
            $byId[$row['id']] = [
                'id'        => (int) $row['id'],
                'parentId'  => $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
                'code'      => $row['code'],
                'name'      => \Core\Lang::isDefault() ? $row['name'] : __($row['name']),
                'sortOrder' => (int) $row['sort_order'],
                'isActive'  => (bool) $row['is_active'],
                'meta'      => $row['meta'] !== null ? (json_decode($row['meta'], true) ?? []) : [],
                'icon'      => $row['icon'],
                'children'  => [],
            ];
        }
        $roots = [];
        foreach ($byId as $id => &$node) {
            if ($node['parentId'] !== null && isset($byId[$node['parentId']])) {
                $byId[$node['parentId']]['children'][] = &$node;
            } else {
                $roots[] = &$node;
            }
        }
        unset($node);
        return $roots;
    }

    // Etap 2 (dopasowania) — zgodność dwóch pozycji słownika 'region' z
    // uwzględnieniem hierarchii parent_id (nie flat exact-match jak filtr
    // strony głównej, patrz Event::buildFilterClauses). Skala 0–1:
    // ten sam region -> 1.0, jeden jest przodkiem drugiego (np. wydarzenie
    // otagowane samą "Polską" a drugie konkretnym regionem) -> 0.6,
    // rodzeństwo (wspólny rodzic, np. dwa różne regiony w tym samym kraju)
    // -> 0.3, bez związku -> 0.0. Brak regionu po którejkolwiek stronie
    // (null) też daje 0.0 — to nie wyklucza kandydata (patrz zasada "brak
    // danych nie wyklucza"), tylko zeruje wkład TEJ osi, geografia i tak
    // dostaje drugą szansę przez odległość punktów startu (Models\MatchEngine
    // bierze wyższy z dwóch wyników, nie sumę).
    public static function regionCompatibility(?int $a, ?int $b): float
    {
        if ($a === null || $b === null) {
            return 0.0;
        }
        if ($a === $b) {
            return 1.0;
        }

        $ancestorsA = self::ancestorChain($a);
        $ancestorsB = self::ancestorChain($b);
        if (in_array($b, $ancestorsA, true) || in_array($a, $ancestorsB, true)) {
            return 0.6;
        }

        $parentA = $ancestorsA[1] ?? null;
        $parentB = $ancestorsB[1] ?? null;
        if ($parentA !== null && $parentA === $parentB) {
            return 0.3;
        }

        return 0.0;
    }

    // [$id, parent($id), grandparent($id), ...] aż do korzenia. Hierarchia
    // dziś ma najwyżej 2 poziomy (patrz migration_013_dictionary_hierarchy.sql),
    // ale pętla działa dla dowolnej głębokości bez zmian.
    private static function ancestorChain(int $id): array
    {
        $chain = [$id];
        $current = self::findItem($id);
        while ($current !== null && $current['parentId'] !== null) {
            $chain[] = $current['parentId'];
            $current = self::findItem($current['parentId']);
        }
        return $chain;
    }

    // Pojedyncza pozycja + kod jej słownika — pod prefill formularza edycji i
    // przekierowanie z powrotem na właściwy ?dict= po zapisie.
    public static function findItem(int $id): ?array
    {
        $stmt = Database::connection()->prepare('
            SELECT di.id, di.dictionary_id, d.code AS dictionary_code, di.parent_id,
                   di.code, di.name, di.sort_order, di.is_active
            FROM dictionary_items di
            JOIN dictionaries d ON d.id = di.dictionary_id
            WHERE di.id = :id
        ');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        return [
            'id'             => (int) $row['id'],
            'dictionaryId'   => (int) $row['dictionary_id'],
            'dictionaryCode' => $row['dictionary_code'],
            'parentId'       => $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
            'code'           => $row['code'],
            'name'           => $row['name'],
            'sortOrder'      => (int) $row['sort_order'],
            'isActive'       => (bool) $row['is_active'],
        ];
    }

    // Zwraca null przy naruszeniu UNIQUE(dictionary_id, code) zamiast wywalać
    // wyjątkiem — ten sam wzorzec co EventRecap::create()/EventReview::create().
    public static function createItem(int $dictionaryId, ?int $parentId, string $code, string $name, int $sortOrder): ?int
    {
        $pdo = Database::connection();
        try {
            $pdo->prepare('
                INSERT INTO dictionary_items (dictionary_id, parent_id, code, name, sort_order)
                VALUES (:dict_id, :parent_id, :code, :name, :sort_order)
            ')->execute([
                'dict_id'    => $dictionaryId,
                'parent_id'  => $parentId,
                'code'       => $code,
                'name'       => $name,
                'sort_order' => $sortOrder,
            ]);
        } catch (\PDOException $e) {
            if ((int) $e->errorInfo[1] === 1062) {
                return null;
            }
            throw $e;
        }
        // Czyści cache id() — bez tego id() wołane w tym samym requestcie po
        // utworzeniu nowej pozycji (rzadkie, ale możliwe w /admin/taksonomia)
        // wciąż zwróciłoby zapamiętane "nie znaleziono" sprzed insertu.
        self::$idCache = [];
        return (int) $pdo->lastInsertId();
    }

    public static function updateItem(int $id, string $code, string $name, int $sortOrder): bool
    {
        try {
            Database::connection()->prepare('
                UPDATE dictionary_items SET code = :code, name = :name, sort_order = :sort_order
                WHERE id = :id
            ')->execute(['code' => $code, 'name' => $name, 'sort_order' => $sortOrder, 'id' => $id]);
        } catch (\PDOException $e) {
            if ((int) $e->errorInfo[1] === 1062) {
                return false;
            }
            throw $e;
        }
        self::$idCache = [];
        return true;
    }

    // Jedyna forma "usuwania" pozycji słownika. Świadomie bez twardego
    // DELETE — pozycje bywają referencjonowane przez historyczne dane (np.
    // events.region_item_id), więc trwałe kasowanie byłoby destrukcyjne i
    // niepotrzebne — is_active już istnieje dokładnie po to.
    public static function setActive(int $id, bool $active): void
    {
        Database::connection()->prepare('
            UPDATE dictionary_items SET is_active = :active WHERE id = :id
        ')->execute(['active' => $active ? 1 : 0, 'id' => $id]);
    }

    // Podpowiedzi po nazwie (LIKE) do typeahead — jak items(), tylko liście i
    // ograniczone do pasujących. Pod pola, gdzie użytkownik wpisuje tekst i
    // dostaje sugestie istniejących pozycji zamiast wybierać z zamkniętej
    // listy (patrz resolveOrCreate() niżej — kategoria pozycji cennika w
    // formularzu eventu działa dokładnie tak samo jak szukajka organizatora).
    public static function searchItems(string $dictionaryCode, string $q, int $limit = 10): array
    {
        $q = trim($q);
        if ($q === '') {
            return [];
        }
        $stmt = Database::connection()->prepare('
            SELECT di.code, di.name
            FROM dictionary_items di
            JOIN dictionaries d ON d.id = di.dictionary_id
            WHERE d.code = :code AND di.is_active = 1 AND di.name LIKE :q
              AND NOT EXISTS (
                  SELECT 1 FROM dictionary_items child
                  WHERE child.parent_id = di.id AND child.is_active = 1
              )
            ORDER BY di.sort_order ASC
            LIMIT ' . (int) $limit . '
        ');
        $stmt->execute(['code' => $dictionaryCode, 'q' => '%' . $q . '%']);
        return $stmt->fetchAll();
    }

    // (dictionaryCode, nazwa) -> id pozycji o tej nazwie (dopasowanie bez
    // rozróżniania wielkości liter) — jeśli nie istnieje, tworzy ją od razu
    // jako nową pozycję najwyższego poziomu (bez rodzica) i zwraca nowe id.
    // Pod pola typu "wpisz albo wybierz z podpowiedzi" (np. kategoria pozycji
    // cennika w formularzu eventu), gdzie zamknięta lista zarządzana wyłącznie
    // przez /admin/taksonomia byłaby zbyt sztywna.
    public static function resolveOrCreate(string $dictionaryCode, string $name): ?int
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $pdo = Database::connection();
        $findStmt = $pdo->prepare('
            SELECT di.id
            FROM dictionary_items di
            JOIN dictionaries d ON d.id = di.dictionary_id
            WHERE d.code = :code AND LOWER(di.name) = LOWER(:name)
            LIMIT 1
        ');
        $findStmt->execute(['code' => $dictionaryCode, 'name' => $name]);
        $existingId = $findStmt->fetchColumn();
        if ($existingId !== false) {
            return (int) $existingId;
        }

        $dictIdStmt = $pdo->prepare('SELECT id FROM dictionaries WHERE code = :code');
        $dictIdStmt->execute(['code' => $dictionaryCode]);
        $dictionaryId = $dictIdStmt->fetchColumn();
        if ($dictionaryId === false) {
            return null;
        }

        $sortStmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM dictionary_items WHERE dictionary_id = :dict_id');
        $sortStmt->execute(['dict_id' => $dictionaryId]);
        $sortOrder = (int) $sortStmt->fetchColumn() + 1;

        $code = str_replace('-', '_', \Utils\Format::slugify($name));
        $newId = self::createItem((int) $dictionaryId, null, $code, $name, $sortOrder);
        if ($newId !== null) {
            return $newId;
        }
        // Kolizja kodu (np. dwa jednoczesne submity z tą samą nazwą) — ktoś
        // inny właśnie ją utworzył, spróbuj odczytać zamiast się wywalać.
        $findStmt->execute(['code' => $dictionaryCode, 'name' => $name]);
        $id = $findStmt->fetchColumn();
        return $id !== false ? (int) $id : null;
    }
}
