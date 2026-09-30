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
- Usunięty słownik „Wymagane źródła projektów” (i wszystko, co z niego korzystało: kompletność źródeł w Imporcie RABIT, na Pulpicie, w gotowości projektu i przy nowym przebiegu). Zakres danych projektu wynika z jego węzła / PROJORG w drzewie P1S (a z nich – potrzebne elementy WBS/PSP), a paczka z RABIT może obejmować wiele projektów (np. całe PWC). Przebieg przypina wszystkie zaimportowane pliki i wybiera z nich wiersze elementów projektu. Konfiguracja importu = tylko „Prefiksy plików RABIT”.
- Dane: AC-LH8 z fragmentu raportu (11 z 20 projektów CES, bez kategorii – nie ma jej w próbce), reszta przykładowa z raportem dopisanym do danych v2.

## Zmiany v3 – kreator projektu
- Kroki: Podstawowe → Projekty P1S → Słowniki projektu → Foldery → Podsumowanie (krok CAM usunięty – CAM jest w słowniku „WP i CAM”).
- Zakres projektu z drzewa P1S rozwijanego w dół: Z_KAT_ZBIORCZA → Z_KATEGORIA → Z_OPIS → PROJORG (strony po 25). Checkbox na każdym poziomie – można zaznaczyć kilka grup z różnych poziomów i pojedyncze PROJORG; grupa obejmuje wszystkie swoje PROJORG i ich elementy WBS/PSP. Zastępuje „Program indywidualny / pula”. PROJORG należący do innego projektu jest pomijany (pojedynczo wskazany PROJORG ma pierwszeństwo przed grupą; wśród grup – projekt utworzony wcześniej). Zapis: `sel` (grupy) + `p1s` (pojedyncze PROJORG).
- Słowniki projektu (WP i CAM, Harmonogram i budżet, dla CAS także Stawki CAS) wczytywane z Excela: jeden plik z arkuszami (szablon) albo osobne pliki / CSV; kolumny po nagłówkach; walidacja jak przy zapisie (element P1S w zakresie i nie w innym projekcie, jeden WP na element, Cost Category, CAM spoza listy – ostrzeżenie, WP w harmonogramie musi być w „WP i CAM”, liczby, daty, Start ≤ Koniec). Słownik z błędami nie zostanie zapisany. Szablon do pobrania ma elementy P1S z zakresu.
- Po utworzeniu słownik projektu można pobrać jako Excel i wczytać ponownie: podgląd +nowe / ~zmienione / −usunięte, zapis z historią (zamknięcie starych wierszy).
- Podsumowanie = baza analityczna: drzewo (kategoria → PROJORG → element P1S) połączone z WP, CAM, BAC, datami i liczbą elementów CES z mapowania; braki (element z kosztami bez WP, WP bez budżetu), zestawienie wg CAM, eksport xlsx.
- Excel: biblioteka SheetJS z cdnjs, ładowana dopiero przy pierwszym użyciu; pobieranie plików przez capability `downloads` (widz potwierdza zapis).

## Zmiany v3 – słownik Cost Category
- Globalny słownik „Cost Category”: element kosztowy z kosztów rzeczywistych (ACTUALS_CES) → Opis, Obszar, Cost Category (33 pozycje, np. `0051105550` PZL Mat Consump → Direct Materials, `0092212550` PZL Machining (W30) → Manufacturing › Manufacturing and QA labor). `0057100000` Proj Sttlmnt Bill jest bez kategorii.
- Projekt ma słownik „Cost Category – zmiany w projekcie” (opcjonalny): zmienia lub dodaje pozycje, ma pierwszeństwo przed globalnym. Strona zmian pokazuje słownik efektywny projektu i braki względem kosztów projektu. Przykład: F16 – `0057120550` PZL Travel → Other Direct Cost.
- Oba słowniki: edycja w aplikacji z historią oraz Excel (pobierz / wczytaj z podglądem różnic); numer elementu kosztowego zapisany w Excelu jako liczba jest uzupełniany zerami do 10 znaków. W kreatorze – opcjonalny arkusz „Cost Category (projekt)”.
- Przebieg przypina oba słowniki. Walidacja: element kosztowy z kosztów projektu, którego nie ma w słowniku (globalnym ani projektu) – błąd blokujący z przyciskiem „Dodaj do Cost Category projektu”; element w słowniku bez kategorii – ostrzeżenie. Przykład: F16 T40 – `0057713550`.

## Otwarte decyzje
- NO_P1S (odpięcie pojedynczego WBS) – nadal odłożone (M14).
- Czy nazwa grupy ma być per okres; czy grupy jako lista wyboru zamiast wolnego tekstu.
- Przebieg przypina stan słowników – czy ma też przypinać stan raportu mapowań (raport zmienia się w bazie).
- Czy korekta projektu CES ma móc przenieść także jego WBS z raportu (dziś: tylko WBS spoza raportu).
- Panel „Koszty wg kategorii P1S” na stronie projektu – zostaje czy też „nie ten etap”.
- Kategoria jest w każdym wierszu WBS; drzewo grupuje PROJORG wg jego wiersza (STUFE 1). Do potwierdzenia: czy elementy jednego PROJORG mogą mieć różne kategorie.
- Zakres z grupy: czy nowe PROJORG, które później pojawią się w zaznaczonej grupie, mają wchodzić do projektu automatycznie (prototyp: tak – przynależność liczona z grupy).
- Baza analityczna także na stronie projektu (po zmianie słowników), nie tylko w kreatorze.
- Kolumna „Cost Category” w słowniku „WP i CAM” ma dziś stałe wartości Labor / Material / Subcontract – czy ma przyjmować kategorie ze słownika Cost Category (np. Engineering labor, Direct Materials)?
- Brak elementu kosztowego w Cost Category – dziś błąd blokujący (jak brak stawki); czy wystarczy ostrzeżenie.
