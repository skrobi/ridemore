<?php
// core/Models/DiscoveryScoring.php
// Etap 8 — jedyny czytelnik core/discovery.php i jedyne miejsce, w którym
// zapadają decyzje "ile punktów". Reszta modułu pyta o wynik, nie o wartości.
//
// Klasa jest bezstanowa; konfiguracja wczytywana raz na żądanie.
namespace Models;

class DiscoveryScoring
{
    private static ?array $config = null;

    /**
     * Konfiguracja punktacji: PLIK jako wartości domyślne, TABELA jako
     * nadpisania z panelu admina (migr. 053).
     *
     * Kolejność jest tu całą decyzją. Plik zostaje źródłem domyślnych wartości
     * i — co ważniejsze — uzasadnień: to w core/discovery.php stoi sprawdzian
     * proporcji między Discovery a Trails, którego nie da się zapisać
     * w kolumnie INT. Tabela trzyma wyłącznie to, co admin realnie zmienił,
     * więc pusta tabela znaczy „jak przed migracją", a skasowanie wiersza to
     * powrót do domyślnej BEZ znajomości liczby.
     */
    public static function config(): array
    {
        if (self::$config === null) {
            self::$config = ScoringSettings::applyTo(require CORE_PATH . '/discovery.php');
        }
        return self::$config;
    }

    /** Kasuje zapamiętaną konfigurację — po zapisie stawki w panelu. */
    public static function forgetConfig(): void
    {
        self::$config = null;
    }

    /** Wartości domyślne, z pominięciem nadpisań — panel pokazuje „było / jest". */
    public static function defaults(): array
    {
        return require CORE_PATH . '/discovery.php';
    }

    /**
     * Punkty za sam PRZEJAZD — dystans i przewyższenie ze śladu.
     *
     * Jedyna kategoria, którą wolno zdobyć powtórnie za tę samą drogę: jazda
     * naprawdę się odbyła, choćby po znanej pętli. Zabezpieczenie przed
     * powtórkami dotyczy bonusów (odkrycia, trasy) i siedzi w kluczu unikalnym
     * point_transactions, nie tutaj.
     *
     * Zaokrąglenie na SAMYM KOŃCU, nie po każdym składniku — inaczej 0,4 pkt
     * z przewyższenia przepadałoby przy każdym przejeździe, a przy setce
     * wyjazdów robi się z tego realna różnica.
     */
    public static function forRide(float $distanceKm, int $elevationGainM): int
    {
        $cfg = self::config()['ride'];

        return (int) round(
            $distanceKm * (float) $cfg['points_per_km']
            + ($elevationGainM / 100) * (float) $cfg['points_per_100m_elevation']
        );
    }

    /**
     * Dzienny limit punktów za jazdę albo null, gdy wyłączony (domyślnie).
     * Stosuje go Models\RiderActivity przy naliczaniu — ta klasa tylko podaje
     * wartość, bo do sprawdzenia limitu trzeba znać dzisiejszy dorobek, a to
     * już jest pytanie do rejestru, nie do konfiguracji.
     */
    public static function dailyRideCap(): ?int
    {
        $cap = self::config()['ride']['daily_cap'] ?? null;
        return $cap === null ? null : (int) $cap;
    }

    /**
     * Punkty za odkrycia z jednego przejazdu.
     *
     * $newCellRiderCounts to liczba rowerzystów, którzy mieli dane pole PRZED
     * tym przejazdem — po jednej pozycji na każde POLE FAKTYCZNIE NOWE dla tej
     * osoby (pola powtórzone nie trafiają tu w ogóle, bo nie są odkryciem).
     * 0 oznacza pole nietknięte wcześniej przez nikogo w ridemore.
     *
     * @param int[] $newCellRiderCounts
     * @return array{discovery:int, exploration:int, firstInCommunity:int, rare:int}
     */
    public static function forDiscoveries(array $newCellRiderCounts): array
    {
        $cfg = self::config();
        $perCell = (int) $cfg['discovery']['points_per_new_cell'];
        $firstBonus = (int) $cfg['exploration']['first_in_community_bonus'];
        $rareMax = (int) $cfg['exploration']['rare_cell_max_riders'];
        $rareBonus = (int) $cfg['exploration']['rare_cell_bonus'];

        $firstInCommunity = 0;
        $rare = 0;
        foreach ($newCellRiderCounts as $before) {
            if ($before === 0) {
                $firstInCommunity++;
            } elseif ($before < $rareMax) {
                // Tylko JEDEN z dwóch bonusów na pole — pierwsze odkrycie w
                // społeczności jest już maksymalną nagrodą za rzadkość i
                // dokładanie do niej drugiej premii podwajałoby wartość tego
                // samego faktu.
                $rare++;
            }
        }

        return [
            'discovery'        => count($newCellRiderCounts) * $perCell,
            'exploration'      => $firstInCommunity * $firstBonus + $rare * $rareBonus,
            'firstInCommunity' => $firstInCommunity,
            'rare'             => $rare,
        ];
    }

    /**
     * Progi ukończenia OSIĄGNIĘTE przy danym pokryciu trasy, wraz z wartością
     * każdego z nich.
     *
     * ZWRACA WSZYSTKIE osiągnięte progi, nie „przekroczone właśnie teraz".
     * Poprzednia wersja porównywała stan przed i po przejeździe, żeby nie
     * zapłacić drugi raz — od Etapu 8A robi to za nas klucz unikalny w
     * point_transactions, więc cała ta arytmetyka (i wynikająca z niej
     * nieidempotentność, opisana wtedy jako pułapka) była już tylko
     * skomplikowanym sposobem na to samo.
     *
     * MODEL OD 2026-09-03 (druga iteracja, decyzja usera): $totalPoints to
     * ILE JEST WARTA CAŁA TRASA — jedna liczba, nie jedna per próg. Progi
     * dzielą ją między siebie RÓWNO (`splitEqually()`); reszta z zaokrąglenia
     * dopada do OSTATNIEGO progu (100%, ukończenie), żeby wcześniejsze zostały
     * idealnie równe, a nie „prawie". `null` = weź wartość domyślną z
     * `core/discovery.php` (`default_total_points`).
     *
     * @return array<int,int> próg% => punkty, rosnąco po progu
     */
    public static function trailAwards(float $pct, ?int $totalPoints = null): array
    {
        $thresholds = self::trailThresholds();
        $total = $totalPoints ?? (int) self::config()['trails']['default_total_points'];
        $shares = self::splitEqually($total, count($thresholds));

        $out = [];
        foreach ($thresholds as $i => $threshold) {
            if ($pct + 0.0001 < $threshold) {
                continue;
            }
            $out[$threshold] = $shares[$i];
        }
        return $out;
    }

    /**
     * Dzieli $total na $count RÓWNYCH części sumujących się dokładnie do
     * $total — reszta z zaokrąglenia (int nie dzieli się zawsze bez reszty)
     * dopada do OSTATNIEJ części, więc wszystkie wcześniejsze progi trasy są
     * identyczne co do grosza, a nie „w przybliżeniu równe".
     *
     * Współdzielona przez `trailAwards()` (naliczanie i podgląd w panelu) —
     * jedna matematyka podziału, niezależnie skąd wzięła się suma.
     *
     * @return list<int>
     */
    public static function splitEqually(int $total, int $count): array
    {
        if ($count <= 0) {
            return [];
        }
        $total = max(0, $total);
        $base = intdiv($total, $count);
        $remainder = $total - $base * $count;

        $out = array_fill(0, $count, $base);
        $out[$count - 1] += $remainder;
        return $out;
    }

    /**
     * Wartość trasy DO PRZYZNANIA: jawne nadpisanie z panelu (`known_routes`),
     * a w jego braku sugestia z długości — NIGDY płaska wartość domyślna bez
     * związku z tym, ile trasa faktycznie ma kilometrów.
     *
     * PO CO TA METODA ISTNIEJE: bez niej każda trasa z włączonym bonusem, ale
     * bez ręcznie wpisanej wartości, płaciła DOKŁADNIE TYLE SAMO — 1000 km
     * i 50 km identycznie (zgłoszenie usera 2026-09-03). To jest JEDYNE
     * miejsce, które o tym decyduje; KnownRoute::syncProgress(),
     * TrailController::show() i panel admina pytają TYLKO ją, żeby naliczanie
     * i podgląd w panelu nigdy się nie rozjechały.
     *
     * @return array{total:int, source:'override'|'distance'|'default'}
     */
    public static function trailValueFor(float $distanceKm, ?int $totalOverride): array
    {
        if ($totalOverride !== null) {
            return ['total' => $totalOverride, 'source' => 'override'];
        }

        $rate = (float) (self::config()['trails']['points_per_km'] ?? 0);
        if ($rate > 0 && $distanceKm > 0) {
            return ['total' => (int) round($distanceKm * $rate), 'source' => 'distance'];
        }

        // Brak znanej długości (albo stawka wyzerowana w panelu) — jedyny
        // przypadek, w którym zostaje wartość domyślna zamiast policzonej.
        return ['total' => (int) self::config()['trails']['default_total_points'], 'source' => 'default'];
    }

    /**
     * Scala LEGACY kolumny `known_routes.bonus_points` / `completion_bonus`
     * (do 2026-09-03: dwie osobne wartości — progi cząstkowe kontra
     * ukończenie, z innym wzorem podziału) w JEDNO nadpisanie total, którego
     * oczekuje `trailValueFor()`.
     *
     * Sumowanie, nie wybór jednej: admin, który wcześniej wpisał np. 600 i
     * 900, chciał, żeby trasa była warta 1500 — ta wartość ma przeżyć zmianę
     * modelu, nawet jeśli podział na progi teraz wygląda inaczej (RÓWNO,
     * zamiast więcej za ukończenie). Panel od tej zmiany pisze już tylko do
     * `bonus_points` i zostawia `completion_bonus` w spokoju (NULL na nowych
     * trasach) — nie warto migrować kolumny, której nikt osobno nie czyta.
     */
    public static function legacyRouteOverrideTotal(?int $bonusPoints, ?int $completionBonus): ?int
    {
        if ($bonusPoints === null && $completionBonus === null) {
            return null;
        }
        return (int) $bonusPoints + (int) $completionBonus;
    }

    /**
     * Progi ukończenia trasy, rosnąco, rozstawione RÓWNO co 100/N procent
     * (N = `threshold_count` z konfiguracji) — pod pasek postępu w widoku
     * i pod listę „progów do zdjęcia", gdy pokrycie trasy spadnie.
     *
     * @return list<int>
     */
    public static function trailThresholds(): array
    {
        $count = max(1, (int) (self::config()['trails']['threshold_count'] ?? 4));
        $steps = [];
        for ($i = 1; $i <= $count; $i++) {
            $steps[] = (int) round($i * 100 / $count);
        }
        return $steps;
    }

    /**
     * Promień wokół pierwszego i ostatniego punktu śladu SOLO, z którego pola
     * w ogóle nie są zapisywane (§27). Patrz nota w core/discovery.php —
     * dane, których nie ma, nie wyciekną żadnym przyszłym endpointem.
     */
    public static function homeTrimRadiusM(): int
    {
        return (int) (self::config()['privacy']['home_trim_radius_m'] ?? 0);
    }

    public static function maxNewCellsPerActivity(): int
    {
        return (int) self::config()['limits']['max_new_cells_per_activity'];
    }
}
