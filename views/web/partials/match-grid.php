<?php
// views/web/partials/match-grid.php
// DOPASOWANIA JAKO SIATKA — pierwsza sekcja obszaru wyników na /wydarzenia
// (2026-08-14, prośba usera).
//
// DLACZEGO OSOBNY PARTIAL, A NIE match-suggestions.php
// ----------------------------------------------------
// Tamten jest kontrolką strony głównej: układ „hero + boczna lista", w którym
// najmocniejsze dopasowanie dostaje dużą kartę, a reszta schodzi do listy przy
// krawędzi. Na stronie głównej to ma sens — dopasowania są tam JEDYNĄ treścią
// w tym miejscu i mogą sobie pozwolić na hierarchię.
//
// Na liście wyjazdów rola jest inna: to nagłówek listy, po którym natychmiast
// idą karty wyników. Hierarchia „jeden duży + reszta mała" biłaby się z siatką
// pod spodem o to, co jest ważniejsze, a użytkownik przyszedł tu przeglądać, nie
// dostać jedną rekomendację. Stąd równorzędne karty w rzędzie: „oto trasy
// dobrane pod Ciebie", a nie „oto TA jedna".
//
// Świadomie BEZ Alpine (odrzucanie „nie moje tempo", rozwijanie powodów) —
// tamte akcje należą do widgetu, który jest o JEDNYM dopasowaniu. Tu karta ma
// prowadzić do wyjazdu i tyle; każdy dodatkowy przycisk konkurowałby z listą
// wyników, do której ta sekcja ma być wstępem.
//
// Oczekuje: $matchCards (Resources\MatchCardResource::fromMatches()).
if (!defined('CORE_PATH')) { http_response_code(403); exit; }
if (empty($matchCards)) return;
?>
<section class="match-grid-sec" aria-labelledby="h2-dopasowania">
    <div class="sec-head">
        <h2 id="h2-dopasowania"><?= __('Dobrane pod Ciebie') ?></h2>
        <span><?= __('na podstawie Twoich wyjazdów — rejon, termin i typ roweru') ?></span>
    </div>

    <div class="match-grid">
        <?php foreach ($matchCards as $c): ?>
        <a class="mg-card" href="<?= htmlspecialchars($c['url']) ?>">
            <span class="mg-card__eyebrow">
                <span class="blaze" style="--bz:var(--s-green);"></span><?= htmlspecialchars($c['eyebrow']) ?>
            </span>
            <h3 class="mg-card__t"><?= htmlspecialchars($c['title']) ?></h3>
            <p class="mg-card__m">
                <?= htmlspecialchars($c['dateLabel']) ?><?php
                if (!empty($c['metaLabel'])): ?> · <?= htmlspecialchars($c['metaLabel']) ?><?php endif; ?>
            </p>

            <?php // POWÓD, dla którego ta karta tu jest — bez niego „dopasowanie"
                  // jest twierdzeniem bez pokrycia. Kolejność od najmocniejszego:
                  // peleton (znajomi realnie tam jadą) bije geografię, a ta bije
                  // ogólne uzasadnienie z profilu. Pokazujemy JEDEN — trzy powody
                  // na karcie w siatce to ściana tekstu, nie argument. ?>
            <?php
            $powod = $c['pelotonLabel'] ?? null;
            if ($powod === null && !empty($c['alignLabel'])) { $powod = $c['alignLabel']; }
            if ($powod === null && !empty($c['profileJustification'])) { $powod = $c['profileJustification']; }
            ?>
            <?php if ($powod): ?>
            <p class="mg-card__why"><?= htmlspecialchars($powod) ?></p>
            <?php endif; ?>

            <span class="mg-card__ft">
                <?php if (!empty($c['groupSizeLabel'])): ?>
                <span><?= htmlspecialchars($c['groupSizeLabel']) ?></span>
                <?php endif; ?>
                <span class="mg-card__go"><?= __('Zobacz →') ?></span>
            </span>
        </a>
        <?php endforeach; ?>
    </div>
</section>
