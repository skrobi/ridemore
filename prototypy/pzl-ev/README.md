# PZL-EV – prototyp funkcjonalny (stan do kontynuacji)

Samodzielny plik `pzl-ev.html` (bez zależności poza fontami Google). Nie jest częścią aplikacji ridemore.
Opublikowany jako artefakt: https://claude.ai/artifact/2TV4RXni59bMicLb97Te7M (wersja 9).

## Stan na 2026-09-30
- Nawigacja: Pulpit | ADMIN | Projekty (menu boczne zależne od sekcji).
- Pulpit: tabela projektów (sort, filtr) + „Wymaga uwagi” (max 5).
- Projekty: strona projektu z kartami przebiegów, konfiguracją, kosztami wg kategorii P1S; „Przebiegi (wspólne)” = okresy.
- ADMIN: Słowniki (globalne, konfiguracja importu, grupy kategorii, przegląd słowników projektów), Import RABIT (wspólny), Mapowanie CES↔P1S.
- Mapowanie: drag & drop / klik → podgląd zmiany (rodzaj reguły, stan przed/po, kategoria po zapisie) → „Zapisz”.
- P1S: katalog Z_KAT_ZBIORCZA → Z_KATEGORIA → Z_OPIS → strony po 25 PROJORG; wyszukiwarka od 3 znaków (max 50). Dane PWC (SP-CRDD*) z wyciągu PZLPROD.LOG.WBS, reszta przykładowa.
- Słownik „Grupy kategorii”: nazwa własna zastępuje Z_OPIS dla elementu P1S i jego podelementów.

## Otwarte decyzje
- NO_P1S (odpięcie pojedynczego WBS dziedziczącego regułę projektu) – odłożone (M14).
- Czy nazwa grupy ma być per okres; czy grupy jako lista wyboru zamiast wolnego tekstu.
