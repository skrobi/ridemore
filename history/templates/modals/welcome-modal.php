<?php
/* ============================================================================
   WELCOME MODAL - First-time user welcome
   ============================================================================ */
?>
<div x-show="showWelcomeModal" 
     x-cloak
     @click.self="closeWelcomeModal()"
     @keydown.escape.window="closeWelcomeModal()"
     class="edit-route-modal__overlay">

    <div @click.stop class="modal" style="max-width: 600px;">

        <!-- HEADER -->
        <div class="modal-header">
            <button @click="closeWelcomeModal()" class="close-btn">
                <img src="<?= asset('icons/x.svg') ?>" alt="" style="width: 18px; height: 18px;">
            </button>
            <h3>RIDEMORE.bike</h3>
        </div>

        <!-- BODY -->
        <div class="modal-body" style="display: flex; gap: 32px; align-items: center; padding: 40px 32px;">
            <!-- Logo po lewej -->
            <div style="flex-shrink: 0;">
                <img src="<?= asset('android-chrome-192x192.png') ?>" alt="RideMore.bike - Podejmujesz wyzwania, jeździsz więcej " style="width: 120px; height: auto;">
            </div>

            <!-- Tekst po prawej -->
            <div style="flex: 1;">
                <h1>Wystarczy że <span style="color:#1ea3b3">jedziesz</span></h1><br>
                <p style="font-size: 16px; line-height: 1.6; margin: 0;">
                    Wybierasz trasę — często w miejscu, gdzie jeszcze nie jechałeś. 
                    <b>Podejmujesz wyzwania</b>. RideMore sprawdza pełne ukończenie. <br/>
                    Za dowiezienie — odblokowujesz medal. Nie liczy się „prawie", liczy się cel.
                </p>
            </div>
        </div>

        <!-- FOOTER -->
        <div class="modal-footer">
            <button @click="closeWelcomeModal()" class="btn btn-primary" style="width: 100%;">
                Podejmij wyzwanie
            </button>
        </div>

    </div>
</div>