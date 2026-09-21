<?php
// views/web/partials/rider-avatar.php
// Awatar rowerzysty w pasku składu (.avs) — JEDNO miejsce decydujące o tym,
// czy awatar jest klikalny.
//
// Powód powstania (2026-08-12, zgłoszenie usera): imiona i awatary były
// klikalne tylko w dwóch miejscach z siedmiu — w składzie wyjazdu, w kronice
// i we wpisach dziennika prowadziły donikąd, mimo że publiczny profil
// rowerzysty istnieje. Skoro produkt ma budować relacje, to KAŻDE wystąpienie
// człowieka musi prowadzić do jego profilu.
//
// Funkcja, nie zwykły require — renderujemy to w pętlach po kilkanaście razy
// na stronę (patrz activity-card.php, ta sama decyzja z tego samego powodu).
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

if (!function_exists('renderRiderAvatar')) {
    // $slug === null (konto bez publicznego sluga albo osoba ukryta z list)
    // → zwykły <span>, wygląda identycznie, po prostu nie prowadzi nigdzie.
    // Lepsze niż link do 404.
    //
    // $avatarUrl (2026-08-22, zgłoszenie usera: „jeśli user ma ikonkę wgraną
    // jako awatar, to i tak w aplikacji ładuje skrót"). Dopisany NA KOŃCU listy
    // parametrów, więc wywołania sprzed tej zmiany działają bez zmian — po
    // prostu dalej pokazują inicjały.
    //
    // ZDJĘCIE MA PIERWSZEŃSTWO, INICJAŁY SĄ ZAPASEM — nigdy odwrotnie i nigdy
    // oba naraz. Inicjały zostają w `alt`, więc gdy plik nie wczyta się
    // (skasowany upload, padnięty CDN, wyłączone obrazki), w kółku dalej stoją
    // dwie litery zamiast pustki albo ikony zepsutego obrazka.
    function renderRiderAvatar(string $initials, ?string $slug, string $title = '', ?string $avatarUrl = null): void
    {
        $label = htmlspecialchars($initials);
        // `Image::src` sam przepuszcza adresy zewnętrzne bez zmian (awatar
        // z Google przy logowaniu społecznościowym) i sam składa wariant `av`
        // dla własnych uploadów — tu nie ma czego rozstrzygać.
        //
        // `Image::exists()` PILNUJE OBIETNICY Z NOTY WYŻEJ (2026-09-03,
        // zgłoszenie usera). Sam adres w bazie nie znaczy, że plik jest na
        // dysku: `src()` z założenia oddaje wtedy oryginalny adres, więc
        // w kółku stała ikona zepsutego obrazka zamiast inicjałów — na każdej
        // liście składu, w każdym feedzie i na każdym profilu.
        $src = Utils\Image::exists($avatarUrl)
            ? Utils\Image::src($avatarUrl, 'av')
            : '';
        $inner = $src !== ''
            ? '<img src="' . htmlspecialchars($src) . '" alt="' . $label . '" width="34" height="34" loading="lazy">'
            : $label;

        if ($slug === null || $slug === '') {
            echo '<span', $title !== '' ? ' title="' . htmlspecialchars($title) . '"' : '', '>', $inner, '</span>';
            return;
        }
        echo '<a href="', htmlspecialchars(Utils\View::url('/rowerzysta/' . $slug)), '"',
             ' title="', htmlspecialchars($title !== '' ? $title : $initials), '">', $inner, '</a>';
    }
}

if (!function_exists('renderRiderName')) {
    // Imię jako link do profilu; bez sluga zwykły tekst. Zwraca HTML zamiast
    // echo, bo bywa wstawiane w środek zdania.
    function renderRiderName(string $name, ?string $slug): string
    {
        $safe = htmlspecialchars($name);
        if ($slug === null || $slug === '') {
            return $safe;
        }
        return '<a href="' . htmlspecialchars(Utils\View::url('/rowerzysta/' . $slug)) . '">' . $safe . '</a>';
    }
}
