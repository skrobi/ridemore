<?php
// views/web/partials/region-link.php
// NAZWA REGIONU JAKO LINK — jedno miejsce dla całego serwisu (2026-09-14,
// zgłoszenie usera: „na profilu organizatora jest podkarpackie i nie da się
// na to kliknąć — zweryfikuj, gdzie wszędzie można klikać w region").
//
// Ta sama zasada co `rider-avatar.php` dla ludzi: skoro region ma swoją
// stronę, KAŻDE wystąpienie jego nazwy ma do niej prowadzić.
//
// WEJŚCIEM JEST PODPIS, NIE KOD: zapytania oddają `region_label` jako listę
// po przecinku („podkarpackie, małopolskie" — wydarzenie przez dwa
// województwa), więc funkcja rozbija ją i linkuje KAŻDY region osobno.
// Nazwa spoza aktywnych regionów zostaje zwykłym tekstem.
//
// NIE UŻYWAĆ WEWNĄTRZ INNEGO LINKU (karta wydarzenia, trasy, organizatora,
// wpis feedu) — link w linku to niepoprawny HTML i przeglądarka rozbija kartę.
// Tam nazwa zostaje tekstem, a region jest o jedno kliknięcie dalej.
//
// UŻYCIE (znacznik zamykający PHP opisany słownie — dosłowny w komentarzu
// jednoliniowym przedwcześnie kończy blok):
//   require_once __DIR__ . '/../partials/region-link.php';
//   renderRegionLinks($label)                          — tekst z linkami, „, " między
//   renderRegionLinks($label, 'tag tag--plain', "\n")  — osobny tag na region
// Funkcja ZWRACA HTML, więc wypisuje się ją krótkim echo PHP.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

if (!function_exists('renderRegionLinks')) {
    /** Zwraca HTML (już ucieczkowany) — do wstawienia przez `<?= ?>`. */
    function renderRegionLinks(?string $labels, string $class = '', string $separator = ', '): string
    {
        $labels = trim((string) $labels);
        if ($labels === '') {
            return '';
        }
        $classAttr = $class !== '' ? ' class="' . htmlspecialchars($class) . '"' : '';
        $out = [];
        foreach (preg_split('/\s*,\s*/u', $labels, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $name) {
            $path = Models\Region::pathForName($name);
            $out[] = $path !== null
                ? '<a' . $classAttr . ' href="' . htmlspecialchars(Utils\View::url($path)) . '">' . htmlspecialchars(__($name)) . '</a>'
                : ($classAttr !== '' ? '<span' . $classAttr . '>' . htmlspecialchars(__($name)) . '</span>' : htmlspecialchars(__($name)));
        }
        return implode($separator, $out);
    }
}
