<?php
// views/web/pages/unsubscribe.php
// Wypis z powiadomień mailowych bez logowania (Etap 1c, 2026-09-11).
// Oczekuje: $cel (?array ['userId','flaga']), $opis (?string), $akcja (string),
// $zrobione (bool).
//
// Trzy stany na jednym ekranie, bo każdy inny wymagałby drugiej strony pod
// adres, który człowiek otwiera raz w życiu:
//   - zły/przeterminowany podpis  → tłumaczymy i kierujemy do konta,
//   - pytanie                     → jeden przycisk, zero pól,
//   - potwierdzenie               → i informacja, co NADAL będzie przychodzić.
if (!defined('CORE_PATH')) { http_response_code(403); exit; }
use Utils\View;
?>

<div class="auth-page">

<?php if ($cel === null): ?>
    <h1 class="display"><?= __('Ten link już nie działa') ?></h1>
    <p class="subline"><?= __('Nie rozpoznajemy tego adresu wypisu. Najczęściej znaczy to, że został skrócony
        albo przepisany przez program pocztowy — czasem, że pochodzi z bardzo starej wiadomości.') ?></p>
    <p class="desc"><?= __('Wszystkie powiadomienia możesz włączyć i wyłączyć w swoim koncie.') ?></p>
    <p><a class="btn" href="<?= View::url('/konto') ?>"><?= __('Przejdź do ustawień konta') ?></a></p>

<?php elseif ($zrobione): ?>
    <h1 class="display"><?= __('Gotowe — wypisaliśmy Cię') ?></h1>
    <p class="subline"><?= __('Nie będziesz już dostawać:') ?> <b><?= htmlspecialchars($opis) ?></b>.</p>
    <p class="desc"><?= __('Pozostałe powiadomienia zostają bez zmian — w szczególności nadal przyjdą do Ciebie
        wiadomości dotyczące wyjazdów, na które się zapisujesz, i sprawy Twojego konta.
        Jeśli zmienisz zdanie, wystarczy jeden przełącznik w koncie.') ?></p>
    <p><a class="btn btn-secondary" href="<?= View::url('/konto') ?>"><?= __('Ustawienia powiadomień') ?></a></p>

<?php else: ?>
    <h1 class="display"><?= __('Wyłączyć te powiadomienia?') ?></h1>
    <p class="subline"><?= __('Zaraz przestaniemy wysyłać:') ?> <b><?= htmlspecialchars($opis) ?></b>.</p>
    <p class="desc"><?= __('Reszta zostaje włączona — to wyłącza tylko ten jeden rodzaj.') ?></p>
    <?php // Formularz bez tokenu CSRF i to jest ŚWIADOME: człowiek jest tu
          // niezalogowany (przyszedł z maila), a dowodem uprawnienia jest
          // podpis w adresie. Patrz komentarz w UnsubscribeController. ?>
    <form method="post" action="<?= htmlspecialchars($akcja) ?>">
        <button class="btn" type="submit"><?= __('Tak, wyłącz je') ?></button>
    </form>
    <p class="desc" style="margin-top:16px;font-size:14px;"><?= __('Wolisz wybrać dokładniej, co ma przychodzić?') ?>

        <a href="<?= View::url('/konto') ?>"><?= __('Ustawienia są w koncie') ?></a>.</p>
<?php endif; ?>

</div>
