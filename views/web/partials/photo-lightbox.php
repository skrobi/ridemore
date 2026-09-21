<?php
// views/web/partials/photo-lightbox.php
// PODGLĄD ZDJĘCIA — JEDEN lightbox dla całego serwisu (2026-08-22).
//
// Powód powstania (zgłoszenie usera): „galeria zdjęć otwiera z osobna zdjęcie
// i to małe, a powinno zadziałać lightbox". Audyt pokazał, że serwis miał DWA
// zachowania kliknięcia w zdjęcie:
//   kronika        — modal <dialog>, pełny plik  ✔
//   strona wyjazdu — <a target="_blank"> do wariantu `thumb` (320 px), czyli
//                    nowa karta z MINIATURĄ zamiast powiększenia  ✘ (3 galerie)
//   profil org.    — to samo co wyżej                              ✘
// Oba objawy z jednego błędu: link prowadził do miniatury, nie do oryginału.
//
// Kod jest PRZENIESIONY z chronicle.php, nie napisany od nowa — tam działał
// i to on jest wzorcem. Zmieniło się jedno: obsługuje teraz dwa rodzaje
// wyzwalacza, bo strony mają różne siatki i nie ma powodu ich ujednolicać
// tylko po to, żeby dzielić modal.
//
// UŻYCIE — dołącz RAZ na stronę, poniżej treści:
//     require __DIR__ . '/../partials/photo-lightbox.php';
// i oznacz kafelki jednym z dwóch sposobów:
//     <button class="ph-thumb" data-full="/pelny.jpg">        (siatka kronikowa)
//     <a class="ph-link" href="/pelny.jpg"><img src="/thumb.jpg"></a>
//
// DLACZEGO DWA: `.ph-thumb` to kafel-przycisk (tło CSS, kwadrat) i bez JS nie
// robi nic. `.ph-link` to prawdziwy link do PEŁNEGO pliku — bez JS otwiera
// zdjęcie w nowej karcie, czyli zachowuje się jak dotąd, tylko wreszcie
// pokazuje oryginał, a nie miniaturę. Nowe galerie pisz jako `.ph-link`.
//
// Bez biblioteki: to ok. 40 linijek, a każda lightboksowa biblioteka przynosi
// własne style, własne zdarzenia i własny sposób zamykania, który trzeba potem
// uzgadniać z resztą serwisu.
//
// Element <dialog> zamiast własnego <div> z overlayem — daje za darmo
// zamykanie Escape'em, blokadę tła i pułapkę na fokus, czyli dokładnie to,
// co przy ręcznej implementacji zawsze wypada z zakresu.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

// Strona może dołączyć ten plik z kilku miejsc (np. galeria wyjazdu i galerie
// w relacjach) — drugi <dialog> o tym samym id psułby `getElementById`.
if (defined('RIDEMORE_PHOTO_LIGHTBOX')) { return; }
define('RIDEMORE_PHOTO_LIGHTBOX', true);
?>
<?php // PODPIS W PODGLĄDZIE (2026-09-16) — opcjonalny `data-caption` na
      // wyzwalaczu (`.ph-thumb` albo `a.ph-link`). Galeria „Zdjęcia z regionu"
      // podpisuje źródło każdego zdjęcia i ten podpis nie może zniknąć akurat
      // wtedy, gdy zdjęcie się powiększa. Bez atrybutu nic się nie zmienia. ?>
<dialog class="ph-modal" id="phModal">
    <img alt="<?= htmlspecialchars(__('Zdjęcie z wyjazdu')) ?>">
    <p class="ph-modal__cap" hidden></p>
    <button type="button" class="ph-modal__x" aria-label="<?= htmlspecialchars(__('Zamknij podgląd')) ?>">×</button>
</dialog>
<script>
(function () {
    var modal = document.getElementById('phModal');
    // Brak wsparcia dla <dialog> (stare przeglądarki) — nie podpinamy nic
    // i `.ph-link` dalej otwiera pełne zdjęcie w nowej karcie.
    if (!modal || typeof modal.showModal !== 'function') { return; }
    var obraz = modal.querySelector('img');
    var podpis = modal.querySelector('.ph-modal__cap');

    function otworz(src, wyzwalacz) {
        var tekst = wyzwalacz.getAttribute('data-caption') || '';
        obraz.src = src;
        obraz.alt = tekst || __('Zdjęcie z wyjazdu');
        podpis.textContent = tekst;
        podpis.hidden = tekst === '';
        modal.showModal();
    }

    // DELEGACJA NA DOKUMENCIE, nie pętla po kafelkach: galerie bywają w treści
    // doładowywanej po wejściu (relacje, wpisy kroniki), a pętla podpięłaby się
    // wyłącznie do tego, co istniało w chwili wykonania skryptu.
    document.addEventListener('click', function (e) {
        var kafel = e.target.closest('.ph-thumb');
        if (kafel && kafel.dataset.full) {
            otworz(kafel.dataset.full, kafel);
            return;
        }
        var link = e.target.closest('a.ph-link');
        if (link) {
            // Modyfikatory zostawiamy przeglądarce: Ctrl/Cmd/środkowy przycisk
            // znaczy „otwórz w nowej karcie" i przechwycenie tego byłoby
            // zabraniem użytkownikowi czegoś, co działało.
            if (e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) { return; }
            e.preventDefault();
            otworz(link.getAttribute('href'), link);
            return;
        }
        // Klik w tło zamyka. Sprawdzamy, czy trafiliśmy w SAM <dialog>, bo
        // jego prostokąt obejmuje też obszar poza obrazkiem — inaczej klik
        // w zdjęcie zamykałby podgląd, co jest odruchowo błędne.
        if (e.target === modal || e.target.closest('.ph-modal__x')) {
            modal.close();
        }
    });

    // Zwolnienie pamięci po zamknięciu: bez tego pełny plik zostaje podpięty
    // do <img> i trzyma się do końca wizyty, także gdy nikt już go nie ogląda.
    modal.addEventListener('close', function () { obraz.removeAttribute('src'); });
})();
</script>
