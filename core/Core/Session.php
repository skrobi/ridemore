<?php
// core/Core/Session.php
// ZWOLNIENIE BLOKADY SESJI DLA ODCZYTÓW (2026-09-02).
//
// PHP trzyma plik sesji zablokowany na wyłączność przez CAŁE żądanie, więc dwa
// żądania z tej samej przeglądarki NIE BIEGNĄ RÓWNOLEGLE — drugie czeka, aż
// pierwsze skończy. Przy zwykłej stronie to niewidoczne. Przy mapie jest
// zabójcze: jeden kadr to kilkadziesiąt kafli plus zapytania o pola i skarby,
// czyli kilkadziesiąt żądań ustawionych w kolejkę jedno za drugim.
//
// ZMIERZONE (5 nieskeszowanych kafli naraz, dev):
//   bez ciasteczka sesji  0,97 s   (0,73 · 0,73 · 0,73 · 0,74 · 0,75)
//   z ciasteczkiem sesji  3,10 s   (0,60 · 1,20 · 1,82 · 2,40 · 3,00)
// Piła w drugim wierszu to dokładnie ta blokada — każdy kafel czekał na
// poprzedni, choć serwer miał wolne ręce.
//
// TO NIE JEST NOWY POMYSŁ W TYM PROJEKCIE: dokładnie tak samo i z tego samego
// powodu robi to `/api/gpx/parse` (2026-08-09, wgrywanie wielu wariantów trasy
// naraz). Tutaj ta sama sztuczka dostaje własną nazwę, bo woła ją już kilka
// miejsc i każde powtarzało trzy linijki z tym samym warunkiem.
//
// CO WOLNO PO ZWOLNIENIU: czytać `$_SESSION` (tablica zostaje w pamięci, więc
// `Auth::user()` i `Csrf::check()` działają normalnie). CZEGO NIE WOLNO:
// liczyć na to, że ZAPIS do `$_SESSION` przetrwa żądanie — po tym wywołaniu
// nic już nie leci na dysk. Dlatego wołamy to wyłącznie na ODCZYTACH: kafle,
// mapowe GET-y, geometria śladu. Żadna z tych ścieżek nie zmienia sesji.
namespace Core;

class Session
{
    public static function release(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }
}
