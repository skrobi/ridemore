<?php
// views/web/pages/treasure-print.php
// NAKLEJKA DO WYDRUKU (Etap 8D, SKA/2).
//
// Ekran robi jedną rzecz: daje kartkę, którą można wydrukować i powiesić
// w terenie. Stąd decyzje, które w zwykłym widoku byłyby dziwne:
//
//   * kod QR jako `data:` — arkusz ma być JEDNYM plikiem, więc zapisany przez
//     „drukuj do PDF" albo wysłany mailem działa bez dostępu do serwera;
//   * własne @media print zamiast dziedziczenia stylów serwisu — drukujemy
//     kartkę, nie stronę WWW, więc nagłówek, stopka i nawigacja mają zniknąć;
//   * układ w milimetrach, nie w px — bo tu naprawdę chodzi o fizyczny rozmiar
//     naklejki, a nie o to, jak wygląda na ekranie.
//
// NA NAKLEJCE NIE MA WSPÓŁRZĘDNYCH ANI PUNKTÓW. Kto ją znajduje, ma zeskanować
// i dowiedzieć się wszystkiego z telefonu; wypisanie nagrody na kartce zamienia
// skarb w tabliczkę informacyjną i psuje moment odkrycia.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }

use Utils\Qr;
use Utils\View;

$items = $items ?? [];
?>
<style>
.pr-wrap{max-width:900px;margin:0 auto;}
.pr-actions{display:flex;gap:10px;flex-wrap:wrap;margin:0 0 18px;}
.pr-sheet{display:grid;grid-template-columns:repeat(auto-fill,minmax(78mm,1fr));gap:8mm;}
.pr-card{border:1px dashed #9aa4ad;border-radius:4mm;padding:6mm;text-align:center;
  break-inside:avoid;page-break-inside:avoid;background:#fff;color:#111;}
.pr-brand{font-family:var(--f-d),sans-serif;font-weight:800;letter-spacing:.14em;
  font-size:11pt;text-transform:uppercase;margin:0 0 3mm;}
.pr-card img{display:block;width:52mm;height:52mm;margin:0 auto;}
.pr-name{font-size:10pt;font-weight:700;margin:3mm 0 1mm;}
.pr-call{font-size:8pt;color:#444;margin:0;}
.pr-code{font-family:monospace;font-size:6.5pt;color:#888;margin:2mm 0 0;}
@media print{
  /* Kartka, nie strona WWW — chowamy wszystko, co nie jest naklejką. */
  header, footer, nav, .breadcrumbs, .pr-actions, .site-header, .site-footer{display:none !important;}
  body{background:#fff !important;margin:0;}
  .pr-sheet{gap:6mm;}
  @page{margin:10mm;}
}
</style>

<div class="pr-wrap">
    <div class="pr-actions">
        <button class="btn" type="button" onclick="window.print()">Drukuj</button>
        <a class="btn btn-secondary" href="<?= View::url('/admin/skarby') ?>">Wróć do skarbów</a>
    </div>

    <?php if (!Qr::available()): ?>
    <div class="box">
        <b>Brak biblioteki do generowania kodów QR.</b>
        <p class="desc" style="margin:6px 0 0;">Na tym serwerze nie ma zależności z <code>vendor/</code>.
            Uruchom <code>composer install</code> — tak samo jak przy logowaniu społecznościowym.</p>
    </div>
    <?php elseif (!$items): ?>
    <div class="box"><p class="desc" style="margin:0;">Nie ma czego drukować — najpierw postaw skarb.</p></div>
    <?php else: ?>
    <div class="pr-sheet">
        <?php foreach ($items as $t): ?>
        <div class="pr-card">
            <p class="pr-brand">Ridemore</p>
            <img src="<?= Qr::dataUri(View::absoluteUrl('/skarb/' . $t['code']), 420) ?>"
                 alt="Kod QR skarbu">
            <p class="pr-name"><?= htmlspecialchars($t['name']) ?></p>
            <p class="pr-call">Zeskanuj i odbierz skarb</p>
            <p class="pr-code"><?= htmlspecialchars(substr((string) $t['code'], 0, 8)) ?></p>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
