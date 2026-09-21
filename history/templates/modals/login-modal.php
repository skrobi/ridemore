<?php
/* ============================================================================
   LOGIN MODAL - Unified wrapper v2.0
   ============================================================================ */
?>
<div x-show="showLoginModal" 
     x-cloak
     @click.self="showLoginModal = false"
     @keydown.escape.window="showLoginModal = false"
     class="edit-route-modal__overlay">

    <div @click.stop class="modal">

        <!-- HEADER -->
        <div class="modal-header">
            <h3>Zaloguj się</h3>
            <p>Podaj email aby zabezpieczyć swój postęp</p>
            <button @click="showLoginModal = false" class="close-btn">
                <img src="<?= asset('icons/x.svg') ?>" alt="" style="width: 18px; height: 18px;">
            </button>
        </div>

        <!-- BODY -->
        <div class="modal-body">
            <div class="edit-route-modal__form-group">
                <label class="edit-route-modal__form-label">Email</label>
                <input type="email" 
                       x-model="loginEmail" 
                       class="edit-route-modal__form-input" 
                       placeholder="jan.kowalski@example.com"
                       @keydown.enter="sendMagicLink()">
                <span class="edit-route-modal__form-hint">Wyślemy Ci link do logowania</span>
            </div>
        </div>

        <!-- FOOTER -->
        <div class="modal-footer">
            <button @click="showLoginModal = false" class="btn btn-secondary">
                Anuluj
            </button>
            <button @click="sendMagicLink()" class="btn btn-primary">
                <img src="<?= asset('icons/mail.svg') ?>" alt="" class="svg-white" style="width: 20px; height: 20px;">
                Wyślij link
            </button>
        </div>

    </div>
</div>