    <div class="k-nav">
        <div class="k-nav__in">
            <button type="button" class="btn btn-secondary" x-show="stepIndex()>0" @click="back()"><?= __('Wstecz') ?></button>
            <button type="button" class="btn" x-show="!isLastStep()" @click="next()"><?= __('Dalej') ?></button>
            <?php // Publikacja prosto z paska (dopisane 2026-08-09 na prośbę
                  // usera: "na samym końcu mam dalej przycisk Wstecz, a obok
                  // niego powinien pojawić się Opublikuj, żebym nie musiał
                  // zjeżdżać na sam dół"). form="k-form" — ten pasek jest
                  // renderowany POZA <form> (patrz event-form-wizard.php), więc
                  // wysyłkę załatwia natywny atrybut form owner, bez JS-a.
                  // name/value + validateBeforeSubmit() identyczne jak przycisk
                  // na dole kroku "Podsumowanie" (step-podsumowanie.php) —
                  // oba muszą zachowywać się DOKŁADNIE tak samo, to ta sama
                  // akcja w dwóch miejscach. "Zapisz jako szkic" świadomie
                  // zostaje tylko na dole: to akcja drugorzędna, pasek ma
                  // prowadzić jedną, główną ścieżkę. ?>
            <button type="submit" form="k-form" class="btn" x-show="isLastStep()" x-cloak
                    name="status" value="published"
                    @click="if(!validateBeforeSubmit()) $event.preventDefault()"
                    x-text="type==='pokrec_z_kims' ? __('Wystaw inicjatywę') : ((organizerMode==='self' || isAdmin) ? 'Opublikuj →' : __('Zgłoś do weryfikacji →'))"></button>
        </div>
        <p class="k-save" id="kSaveTxt"><?= __('Nic nie wysyłamy, dopóki nie klikniesz Publikuj') ?></p>
    </div>
