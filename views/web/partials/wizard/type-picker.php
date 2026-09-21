<!-- ===== KROK 0: wybór typu ===== -->
<section class="k-wrap k-pick" x-show="!wizardStarted" x-cloak>
    <h1><?= __('Co chcesz wystawić?') ?></h1>
    <p class="k-pick__l"><?= __('Wybierz jedno — dalej zapytamy tylko o to, co dla niego potrzebne.') ?></p>

    <button type="button" class="k-pk" style="--bz:var(--s-green);" @click="startWizard('pokrec_z_kims')">
        <h2><?= __('Pokręć z kimś') ?></h2>
        <p><?= __('Masz wolny termin i trasę w głowie, ale nie chcesz jechać sam.') ?></p>
        <p class="k-pk__m"><span><b><?= __('3 pytania') ?></b> <?= __('· ok. 40 sekund') ?></span><span><?= __('Zawsze bez opłat') ?></span></p>
    </button>

    <button type="button" class="k-pk" style="--bz:var(--s-red);" @click="startWizard('ustawka')">
        <h2><?= __('Zorganizowane wydarzenie') ?></h2>
        <p><?= __('Jeden dzień, konkretna godzina zbiórki, jedna trasa.') ?></p>
        <p class="k-pk__m"><span><b><?= __('5 kroków') ?></b> <?= __('· ok. 3 minuty') ?></span><span><?= __('Może wystawić każdy') ?></span></p>
    </button>

    <!-- Formularz jednodniowy identyczny jak "Zorganizowane wydarzenie" (patrz komentarz
         w migration_033_wyscig_event_type.sql) — tu tylko inny typ + kolor,
         reszta kroku dzieje się sama (visibleSteps() w script.php). Warianty
         trasy (Krok "Trasa") są tu najważniejszą funkcją — jeden wyścig,
         kilka dystansów do wyboru, każdy z własnym GPX/ceną/limitem. -->
    <button type="button" class="k-pk" style="--bz:var(--s-purple);" @click="startWizard('wyscig')">
        <h2><?= __('Wyścig') ?></h2>
        <p><?= __('Jeden dzień, jeden start — często z kilkoma dystansami do wyboru.') ?></p>
        <p class="k-pk__m"><span><b><?= __('5 kroków') ?></b> <?= __('· ok. 3 minuty') ?></span><span><?= __('Może wystawić każdy') ?></span></p>
    </button>

    <button type="button" class="k-pk" style="--bz:var(--s-blue);" @click="startWizard('wycieczka_wielodniowa')">
        <h2><?= __('Wycieczka wielodniowa') ?></h2>
        <p><?= __('Dwa dni lub więcej, etapy, noclegi, zwykle z wpisowym.') ?></p>
        <p class="k-pk__m"><span><b><?= __('7 kroków') ?></b> <?= __('· ok. 8 minut') ?></span><span><?= __('Możesz przerwać i wrócić') ?></span></p>
    </button>
</section>
