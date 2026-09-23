# `tasks/` — kontrakty implementacyjne

Zadanie z tego katalogu jest **kontraktem**: jego cel, wymagania, zakres
i kryteria akceptacji traktujemy jak wymagania implementacyjne (patrz
[`CLAUDE.md`](../CLAUDE.md), §9). To nie jest dokumentacja aplikacji — mapa kodu
żyje w [`md/`](../md/README.md).

## Trzy miejsca, jeden sens

| Gdzie | Co tam leży |
|---|---|
| `active/` | **Praca otwarta.** Jeśli plik jest tutaj, coś w nim zostało do zrobienia. |
| `done/` | Kontrakty zamknięte. Zostają w repo, bo trzymają **uzasadnienia decyzji** i opis pułapek, nie tylko listę zadań. |
| katalog główny | Rzeczy odłożone, z sufiksem `-ODLOZONE` w nazwie i notą na górze pliku, czemu. |

**Reguła stanu: status etapu stoi W JEGO NAGŁÓWKU.** Nie w treści niżej, nie
w sekcji wykonania na końcu. Powód jest praktyczny — 2026-09-12 okazało się, że
`tasks/active/` pokazywał dziewięć zadań w toku, z których osiem było zrobione:
stan dało się odczytać tylko czytając każdy plik do końca. Nagłówek etapu
kończy się więc jednym z: `— ZROBIONY <data>`, `— OTWARTY`,
`— OTWARTY, ZABLOKOWANY za <czym>`.

Zamykając kontrakt: dopisz sekcję `## WYKONANIE — KONTRAKT ZAMKNIĘTY <data>`
(kryterium po kryterium, z tym co je potwierdza), przenieś plik do `done/`
i popraw odnośniki `tasks/active/...` w kodzie i w `md/` — komentarze w kodzie
odsyłają do tych ścieżek i po przeniesieniu wiszą w powietrzu.

## Stan na 2026-09-12

### Otwarte (`active/`)

- **[`apka-przeglad-2026-09.md`](active/apka-przeglad-2026-09.md)** — od 2026-09-11
  **jedyny aktywny plan dla apki mobilnej**. Etapy 1–3 zrobione; **Etap 5
  (telefon) otwarty**, a **Etap 4 (domknięcie offline) świadomie za nim
  zablokowany** — nie ma sensu domykać cache'u podkładu, póki nikt nie sprawdził
  w terenie, czy jego limity wystarczają.
- **[`apka-offline.md`](active/apka-offline.md)** — Etapy 1–2 zrobione,
  **Etap 3 otwarty**: widoczne odkrywanie pól bez zasięgu (odpowiednik
  `DiscoveryGrid::pointToCell()` w JS + lokalna nakładka „jeszcze niewysłane").
  Niezmiennik, od którego nie odchodzimy: lokalne pola to PODGLĄD, punktów nie
  przyznaje przeglądarka.
- **[`apka-mobilna.md`](active/apka-mobilna.md)** — kod wszystkich etapów
  napisany, etapy 3–9 mają status „gotowe, niesprawdzone na urządzeniu".
  Dziś czyta się jako **opis JAK to zrobiono**; pozostała praca jest w Etapie 5
  przeglądu wyżej. Push czeka dodatkowo na plik konta serwisowego Firebase.

**Wszystkie trzy sprowadzają się do jednej rzeczy: apka nigdy nie ruszyła na
fizycznym telefonie.** Dopóki to się nie stanie, reszta jest wróżeniem.

- **[`warstwa-routingu-ridemore.md`](active/warstwa-routingu-ridemore.md)** —
  od 2026-09-18: planer preferuje sprawdzone odcinki Ridemore nad OSRM.
  Faza 0 (rowerowy OSRM), Etap 1 (MVP) i Etapy 2a–2c (świeżość, profile,
  powód wyboru) zrobione; weryfikacja UI czeka na zalogowanie w przeglądarce;
  2d–2f zablokowane (dane z produkcji / decyzja o zmianie schematu).
- **[`planer-uproszczona-architektura.md`](active/planer-uproszczona-architektura.md)** —
  od 2026-09-23 **PROPOZYCJA, czeka na akceptację**: zmiana kierunku planera
  („gdzie warto pojechać”, kreator 4 pytań, pętla, skarby po drodze, dane BOT).
  Po akceptacji zamraża część etapów kontraktu wyżej.

### Odłożone

- [`nawigacja-w-apce-ODLOZONE.md`](nawigacja-w-apce-ODLOZONE.md) — 2026-09-11,
  decyzja usera: „odpuśćmy robienie nawigacji od zera". Zostaje w repo za dwie
  rzeczy: zmierzony stan apki i opis przeszkody offline (tryb apki zależy od
  User-Agenta, więc service worker nie może cache'ować dokumentów).

### Zamknięte (`done/`)

`apka-mobilna-skorupa` · `apka-mobilna-ux` · `avatary-linkowanie-profili` ·
`mapa-apka-bez-zoomu` · `mapa-apka-kadr-na-mnie` · `powiadomienia-zachety` ·
`strona-przejazdu` · `warstwy-mapy` · `zglos-skarb-w-terenie` ·
`znane-trasy-kafle`

> Część z nich ma w kryteriach punkty oznaczone **„wymaga telefonu"** (gesty
> zoomu, aparat, GPS). Kontrakt jest zamknięty, bo kod jest kompletny i nie da
> się tam zrobić więcej bez urządzenia — te punkty zbiera Etap 5 przeglądu apki,
> nie osobne otwarte zadanie.
