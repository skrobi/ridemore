// scripts/chat-paste.js (world: MAIN)
// Definiuje window.__rmiChatPaste — wstrzykiwane do karty czatu Facebooka
// (facebook.com/messages/t/<profil>) po kliknięciu "💬 Czat" w panelu.
// Wkleja przygotowaną wiadomość do pola czatu — dokładnie tak, jak moderator
// zrobiłby to ręcznie. Rozszerzenie NIGDY nie wysyła wiadomości samo:
// wysyłkę potwierdza użytkownik Enterem, z własnego konta.
//
// world: MAIN jest tu KONIECZNY — pole czatu to contenteditable sterowane
// przez Reacta; document.execCommand('insertText') z tego świata odpala
// prawdziwe zdarzenia beforeinput/input, które React odbiera (w world:
// ISOLATED te same zdarzenia nie docierają do strony i nic się nie wkleja).
window.__rmiChatPaste = function (text) {
  return new Promise((resolve) => {
    const deadline = Date.now() + 15000;
    const selectors = [
      'div[role="textbox"][contenteditable="true"]',
      'textarea[role="textbox"]',
      '[contenteditable="true"][aria-label]',
    ];
    const attempt = () => {
      let input = null;
      for (const sel of selectors) {
        input = document.querySelector(sel);
        if (input) break;
      }
      if (input) {
        input.focus();
        try {
          const ok = text ? document.execCommand('insertText', false, text) : true;
          resolve(ok ? { ok: true } : { ok: false, error: 'Wstawienie tekstu nie przeszło — wklej ręcznie (Ctrl+V).' });
        } catch (e) {
          resolve({ ok: false, error: 'Wstawienie tekstu nie przeszło — wklej ręcznie (Ctrl+V).' });
        }
        return;
      }
      if (Date.now() > deadline) {
        resolve({ ok: false, error: 'Nie znalazłem pola czatu na tej stronie — otwórz czat i wklej ręcznie.' });
        return;
      }
      setTimeout(attempt, 500);
    };
    attempt();
  });
};