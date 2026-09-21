<?php
// index.php
require __DIR__ . '/core/bootstrap.php';

$uri = \Core\Router::stripBasePath($_SERVER['REQUEST_URI']);

if (str_starts_with($uri, '/api')) {
    require __DIR__ . '/api/routes.php';
} elseif (str_starts_with($uri, '/admin')) {
    require __DIR__ . '/admin/routes.php';
} else {
    // Jeden adres na stronę (SEO, 2026-09-14): /events/x/ i /events/x
    // odpowiadały oba 200, czyli dwa adresy tej samej treści. Tylko strony
    // publiczne — /admin ma katalog, któremu Apache sam dokleja ukośnik,
    // więc przekierowanie w drugą stronę dałoby tam pętlę.
    //
    // Warunek liczy się na ścieżce PRZED zdjęciem prefiksu języka: `/en/` to
    // po zdjęciu `/`, a ma przekierować na `/en` tak samo jak `/events/x/`.
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '';
    $local = substr($path, strlen(rtrim(APP_CONFIG['base_path'] ?? '', '/')));
    if (in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true) && strlen($local) > 1 && str_ends_with($path, '/')) {
        $query = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
        header('Location: ' . \Utils\View::url($uri) . ($query ? '?' . $query : ''), true, 301);
        exit;
    }

    // WEJŚCIE NA STRONĘ GŁÓWNĄ WG WYBRANEGO JĘZYKA (2026-09-16). Jedyne
    // przekierowanie językowe w serwisie: goły `/` (wpisana domena, start
    // apki) prowadzi tam, gdzie człowiek sam się przełączył. Każdy inny adres
    // pokazuje język ze swojego prefiksu — patrz Core\Lang. Roboty nie mają
    // ciasteczek ani sesji, więc zawsze widzą `/` po polsku.
    if (in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true) && $uri === '/' && \Core\Lang::isDefault()) {
        $preferred = \Core\Lang::preferred();
        if ($preferred !== null && $preferred !== \Core\Lang::DOMYSLNY) {
            $query = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
            header('Location: ' . \Core\Lang::with($preferred, fn() => \Utils\View::url('/')) . ($query ? '?' . $query : ''), true, 302);
            exit;
        }
    }

    require __DIR__ . '/web/routes.php';
}
