# PZL-EV – prototyp funkcjonalny (stan do kontynuacji)

Samodzielne pliki HTML (bez zależności poza fontami Google). Nie są częścią aplikacji ridemore.

| Wersja | Plik | Artefakt |
|---|---|---|
| v2 (wyjściowa, bez zmian) | `pzl-ev.html` | https://claude.ai/artifact/2TV4RXni59bMicLb97Te7M (wersja 9) |
| v3 (bieżąca) | `pzl-ev-v3.html` | https://claude.ai/artifact/CiiZfEBsHBV24Xw4EHEHAv |

## Stan v2 (2026-09-30)
- Nawigacja: Pulpit | ADMIN | Projekty (menu boczne zależne od sekcji).
- Pulpit: tabela projektów (sort, filtr) + „Wymaga uwagi” (max 5).
- Projekty: strona projektu z kartami przebiegów, konfiguracją, kosztami wg kategorii P1S; „Przebiegi (wspólne)” = okresy.
- ADMIN: Słowniki (globalne, konfiguracja importu, grupy kategorii, przegląd słowników projektów), Import RABIT (wspólny), Mapowanie CES↔P1S.
- P1S: katalog Z_KAT_ZBIORCZA → Z_KATEGORIA → Z_OPIS → strony po 25 PROJORG; wyszukiwarka od 3 znaków (max 50). Dane PWC (SP-CRDD*) z wyciągu PZLPROD.LOG.WBS, reszta przykładowa.
- Słownik „Grupy kategorii”: nazwa własna zastępuje Z_OPIS dla elementu P1S i jego podelementów.

## Zmiany v3 – ADMIN → Mapowanie CES↔P1S
Mapowanie to część administracyjna: tylko przypisanie elementów CES do elementów P1S. Bez kosztów (usunięte kwoty i tabela „Koszty CES wg kategorii P1S”).

Model wynikający z raportu mapowań SAP↔CES:
- **Raport mapowań = źródło prawdy**, zapytanie do bazy (PZLPROD), tylko odczyt. Wiersz SAP z `pspnr_ces` = odpowiednik 1:1; wiersz CES z `pspnr_sap` = element tylko w CES wskazany w SAP (korzeń projektu CES → PROJORG, `.RA` → zlecenie sprzedaży, `.02` → element `.03`).
- 1 PROJORG = wiele projektów CES; projekt CES ↔ poddrzewo zlecenia sprzedaży w SAP (`project_sap`, np. `4D02U8` ↔ `AC-LH8.1.01`). Dopasowanie tylko po `pspnr` – poziomy się różnią, opisy SWBS się powtarzają.
- **WBS CES spoza raportu dziedziczy automatycznie** odpowiednik swojego projektu CES (`project_sap`) – status INHERITED.
- **Korekty globalne** (potrzeby finansów) – z historią, mają pierwszeństwo przed raportem: korekta elementu (OVERRIDE) albo korekta projektu CES (nowy cel dla jego WBS spoza raportu). Zmiana względem raportu wymaga uzasadnienia. Usunięcie korekty przywraca raport / dziedziczenie.
- Statusy: REPORT → INHERITED → OVERRIDE → UNMAPPED (brak w raporcie i brak odpowiednika projektu CES).
- Kategoria P1S nie jest przypisywana w mapowaniu – dochodzi z WBS P1S po połączeniu (`PSPNR` = `pspnr_sap`).
- Widok: dwa drzewa w tej samej strukturze – CES | P1S; atrybuty SAP z raportu w panelu elementu, rejestr korekt.
- Drzewo CES odwzorowuje strukturę P1S przez przypisanie CES → P1S: kategoria WBS elementu docelowego → PROJORG → projekt CES → elementy (projekt CES pojawia się w każdej gałęzi, do której trafiają jego elementy). Elementy bez celu – jeden węzeł „Nieprzypisane” na górze, z propozycją dla projektów CES spoza raportu. Zaznaczenie PROJORG w P1S rozwija i podświetla jego gałąź w CES.
- Drzewo P1S wg kategoryzacji z tabeli WBS (`PZLPROD.LOG.WBS`): Z_KAT_ZBIORCZA → Z_KATEGORIA → Z_OPIS (albo nazwa ze słownika „Grupy kategorii”, ✎) → PROJORG → elementy. Pokazuje PROJORG „w mapowaniu”: projekty EV oraz cele i propozycje elementów CES, zawężane filtrem Projekt; PROJORG bez kategorii – grupa „Bez kategorii w WBS”. Łączenie z raportem: `PSPNR` = `pspnr_sap`; hierarchia elementów docelowo wg `PARENT`.
- Dane: AC-LH8 z fragmentu raportu (11 z 20 projektów CES, bez kategorii – nie ma jej w próbce), reszta przykładowa z raportem dopisanym do danych v2.

## Otwarte decyzje
- NO_P1S (odpięcie pojedynczego WBS) – nadal odłożone (M14).
- Czy nazwa grupy ma być per okres; czy grupy jako lista wyboru zamiast wolnego tekstu.
- Przebieg przypina stan słowników – czy ma też przypinać stan raportu mapowań (raport zmienia się w bazie).
- Czy korekta projektu CES ma móc przenieść także jego WBS z raportu (dziś: tylko WBS spoza raportu).
- Panel „Koszty wg kategorii P1S” na stronie projektu – zostaje czy też „nie ten etap”.
- Kategoria jest w każdym wierszu WBS; drzewo grupuje PROJORG wg jego wiersza (STUFE 1). Do potwierdzenia: czy elementy jednego PROJORG mogą mieć różne kategorie.
