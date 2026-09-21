<?php
// core/Controllers/Admin/ImporterController.php
// PANEL IMPORTERA WYDARZEŃ (/admin/importer) — zarządzanie importerem z poziomu
// serwisu zamiast CLI: dodanie pojedynczego adresu, zebranie linków z kalendarza
// (strony-listy), zarządzanie stałą listą źródeł i uruchomienie ich zbiorczo.
//
// Cały import jednego adresu robi Models\EventImport::importUrl() — TEN SAM
// rdzeń, którego używa worker import_events.php (fetch -> silnik AI -> dziennik
// -> kandydat w kolejce `oczekuje_weryfikacji`). Panel niczego nie publikuje —
// kandydaci lądują w istniejącej moderacji /admin (human-in-the-loop).
//
// UWAGA ŚRODOWISKO: ekstrakcja idzie przez most PHP->Python (AiEngineBridge),
// który istnieje tylko na 'dev' (jak /api/ai/engine-analyze). Na innym
// środowisku „Zbierz linki" i lista źródeł działają, ale sam import zwróci błąd
// „silnik AI nie skonfigurowany" — panel mówi o tym wprost.
namespace Controllers\Admin;

use Controllers\Support;
use Core\Csrf;
use Core\Database;
use Models\EventImport;
use Models\EventImportSource;
use Utils\EventSourceFetcher;
use Utils\View;

class ImporterController
{
    public static function index(): void
    {
        self::render();
    }

    // Import pojedynczego adresu (pole „url") albo zaznaczonych z listy
    // zebranych linków (pole „urls[]"). Synchronicznie — na dev to sekundy na
    // adres; przy wielu adresach zwalniamy blokadę sesji i podbijamy limit czasu.
    public static function import(): void
    {
        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            self::render(['error' => __('Sesja wygasła, spróbuj ponownie.')]);
            return;
        }

        $urls = [];
        if (!empty($_POST['url'])) {
            $urls[] = trim((string) $_POST['url']);
        }
        foreach ((array) ($_POST['urls'] ?? []) as $u) {
            $u = trim((string) $u);
            if ($u !== '') {
                $urls[] = $u;
            }
        }
        $urls = array_values(array_unique(array_filter($urls, static fn($u) => preg_match('#^https?://#i', $u) === 1)));

        if (!$urls) {
            self::render(['error' => __('Podaj poprawny adres (http/https) albo zaznacz linki do importu.')]);
            return;
        }

        // Wiele adresów potrafi trwać (kilka sekund na adres) — nie trzymaj na to
        // blokady sesji i daj PHP zapas czasu (ten sam wzorzec co /api/gpx/parse).
        if (count($urls) > 1 && session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        @set_time_limit(600);

        $results = [];
        foreach ($urls as $url) {
            $res = EventImport::importUrl($url);
            $res['url'] = $url;
            $results[] = $res;
        }

        self::render(['results' => $results]);
    }

    // Zebranie linków wydarzeń ze strony-listy (kalendarz) — POZIOM 2. Działa na
    // źródłach renderowanych PO STRONIE SERWERA; dla SPA (JS) informuje wprost, że
    // trzeba użyć rozszerzenia Chrome (looksJsRendered). „all=1" bierze wszystkie
    // źródła z listy zarządzalnej.
    public static function harvest(): void
    {
        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            self::render(['error' => __('Sesja wygasła, spróbuj ponownie.')]);
            return;
        }

        $listUrls = [];
        if (!empty($_POST['all'])) {
            $listUrls = EventImportSource::urls();
        } elseif (!empty($_POST['list_url'])) {
            $u = trim((string) $_POST['list_url']);
            if (preg_match('#^https?://#i', $u) === 1) {
                $listUrls[] = $u;
            }
        }
        if (!$listUrls) {
            self::render(['error' => __('Podaj adres kalendarza (http/https) albo dodaj źródła do listy.')]);
            return;
        }

        @set_time_limit(300);
        $harvest = [];
        foreach ($listUrls as $listUrl) {
            $entry = ['listUrl' => $listUrl, 'links' => [], 'jsRendered' => false, 'error' => null];
            // Facebook/Instagram/X… — nie pobieramy (treść za logowaniem/JS);
            // kierujemy na rozszerzenie Chrome, zamiast wiszącego fetcha.
            if (EventSourceFetcher::isUnsupportedHost($listUrl)) {
                $entry['error'] = __('Facebook/Instagram i podobne — użyj rozszerzenia Chrome („🔗 Zbierz linki" na otwartej stronie). Serwer nie odczyta treści za logowaniem.');
                $harvest[] = $entry;
                continue;
            }
            try {
                $payload = EventSourceFetcher::fetch($listUrl);
                $links = EventSourceFetcher::extractEventLinks($payload['links'], $listUrl);
                if (!$links && EventSourceFetcher::looksJsRendered($payload)) {
                    $entry['jsRendered'] = true;
                }
                // Oznacz linki już zaimportowane (dedup po źródle) — żeby admin nie
                // wysyłał ich ponownie do modelu.
                foreach ($links as $l) {
                    $l['alreadyImported'] = EventImport::findEventBySourceUrl($l['url']);
                    $entry['links'][] = $l;
                }
            } catch (\Throwable $e) {
                $entry['error'] = $e->getMessage();
            }
            $harvest[] = $entry;
        }

        self::render(['harvest' => $harvest]);
    }

    public static function addSource(): void
    {
        if (Csrf::check($_POST['csrf_token'] ?? null)) {
            $url = trim((string) ($_POST['source_url'] ?? ''));
            $_SESSION['importer_flash'] = EventImportSource::add($url)
                ? __('Dodano źródło.')
                : __('Nie dodano — zły adres albo już jest na liście.');
        }
        header('Location: ' . View::url('/admin/importer'));
        exit;
    }

    public static function removeSource(): void
    {
        if (Csrf::check($_POST['csrf_token'] ?? null)) {
            $url = trim((string) ($_POST['source_url'] ?? ''));
            $_SESSION['importer_flash'] = EventImportSource::remove($url)
                ? __('Usunięto źródło.')
                : __('Nie znaleziono źródła do usunięcia.');
        }
        header('Location: ' . View::url('/admin/importer'));
        exit;
    }

    // Wspólne złożenie danych dla widoku — sekcje stałe (źródła, dziennik,
    // notka o środowisku) plus opcjonalny wynik akcji ($extra).
    private static function render(array $extra = []): void
    {
        $flash = $_SESSION['importer_flash'] ?? null;
        unset($_SESSION['importer_flash']);

        $data = array_merge([
            'title'       => __('Importer wydarzeń — ridemore.bike'),
            'noindex'     => true,
            'sources'     => EventImportSource::all(),
            'recentLog'   => self::recentLog(),
            'pending'     => self::pendingCandidates(),
            'engineReady' => APP_ENV === 'dev' && !empty(APP_CONFIG['ai_engine']),
            'flash'       => $flash,
            'breadcrumbs' => [Support::homeCrumb(), Support::panelCrumb(), ['label' => __('Importer wydarzeń')]],
            'results'     => null,
            'harvest'     => null,
            'error'       => null,
        ], $extra);

        View::render('web', 'importer-admin', $data);
    }

    /** Ostatnie ekstrakcje z dziennika importera (ai_import_logs). */
    private static function recentLog(int $limit = 20): array
    {
        try {
            $stmt = Database::connection()->prepare('
                SELECT url, source_domain, confidence, created_at
                  FROM ai_import_logs
                 ORDER BY created_at DESC
                 LIMIT :limit
            ');
            $stmt->bindValue('limit', $limit, \PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Kandydaci z importu czekający na weryfikację — link do moderacji. */
    private static function pendingCandidates(int $limit = 30): array
    {
        try {
            $stmt = Database::connection()->prepare("
                SELECT e.slug, e.title, e.start_date, e.custom_attributes
                  FROM events e
                  JOIN dictionary_items st ON st.id = e.status_item_id
                 WHERE st.code = 'oczekuje_weryfikacji'
                   AND JSON_UNQUOTE(JSON_EXTRACT(e.custom_attributes, '$.source')) = 'ridemore-importer'
                 ORDER BY e.created_at DESC
                 LIMIT :limit
            ");
            $stmt->bindValue('limit', $limit, \PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return [];
        }
    }
}
