# Mapa kodu ridemore.bike

Zestaw dokumentów nawigacyjnych po aplikacji. Cel: **znaleźć właściwy plik i metodę
bez czytania całego kodu**. Każdy plik opisuje inną warstwę aplikacji.

> Zmapowano: 2026-08-06, zweryfikowane względem kodu: 2026-08-19. To pomoc
> nawigacyjna, nie źródło prawdy — numery linii i
> szczegóły mogą się zdezaktualizować. Zawsze weryfikuj konkretny plik przed edycją.
> Po większej zmianie w danej warstwie dopisz/popraw odpowiedni plik `md/`.

## Jak korzystać

1. Nie wiesz gdzie coś jest → otwórz **ten** plik, znajdź warstwę, przejdź do jej `md/`.
2. Szukasz trasy/URL → [`routing.md`](routing.md).
3. Szukasz logiki HTTP (walidacja, redirecty, maile z akcji) → [`controllers.md`](controllers.md).
4. Szukasz zapytań do bazy / logiki domenowej → [`models.md`](models.md).
5. Szukasz jak dane trafiają do widoku/JSON → [`resources.md`](resources.md).
6. Szukasz HTML/CSS/JS → [`views-and-frontend.md`](views-and-frontend.md).
7. Szukasz tabeli/kolumny/migracji → [`database.md`](database.md).
8. Szukasz helpera (format daty, upload, GPX, slug, mail) → [`utils.md`](utils.md).
9. "Gdzie jest cała funkcja X" (turnusy, dopasowania, czat...) → [`features.md`](features.md).

## Warstwy (spis dokumentów)

| Dokument | Co obejmuje |
|---|---|
| [`architecture.md`](architecture.md) | Cykl żądania, bootstrap, router, config, usługi rdzenia (`Auth`/`Database`/`Csrf`/`View`), konwencje, dev vs prod, obsługa błędów, gotchy. **Zacznij tu.** |
| [`routing.md`](routing.md) | Trzy tablice tras: `web/`, `api/`, `admin/` — URL → `Kontroler@metoda`, z pułapkami kolejności. |
| [`controllers.md`](controllers.md) | `core/Controllers/*` + `Admin/*` + `Support` — każda akcja HTTP jednolinijkowo. |
| [`models.md`](models.md) | `core/Models/*` — indeks metod, do której tabeli sięga każdy model, wzorzec statycznych finderów. |
| [`resources.md`](resources.md) | `core/Resources/*` — warstwa mapująca modele/POST na tablice dla widoków, JSON i zapisu. |
| [`utils.md`](utils.md) | `core/Utils/*` + `core/Core/*` — helpery bez stanu (View, Format, Gpx, Upload, Mailer, OAuthProvider, ...). |
| [`views-and-frontend.md`](views-and-frontend.md) | `views/` (layout, strony, partiale, maile), `assets/css/style.css` (mapa sekcji), `assets/js`, wzorce Alpine.js. |
| [`database.md`](database.md) | Wykaz tabel, workflow migracji (dev vs prod), słowniki (`dictionaries`), klucze/relacje. |
| [`features.md`](features.md) | Duże funkcje end-to-end (turnusy, pokręć z kimś, dopasowania Etap 2/3, czat grupowy, logowanie społ., zapisy zewnętrzne, skarby) → które pliki je realizują. Sprzężone z pamięcią `memory/`. |
| [`roadmap-swiat.md`](roadmap-swiat.md) | Roadmapa „Świata Ridemore" (Etap 8A–8H): kolejność zależności, stan etapów i decyzje, do których nie wracamy. Kierunek, nie mapa kodu. |

## Skrót architektury (1 akapit)

PHP 8.1, własny mini-MVC bez frameworka. `index.php` ładuje `core/bootstrap.php`
(autoloader, sesja, config, handler błędów), po czym wg prefiksu URL włącza jeden z
trzech plików tras: `web/routes.php`, `api/routes.php` (JSON), `admin/routes.php`
(za `Auth::requireLogin()`). Trasa → statyczna metoda kontrolera w `core/Controllers`.
Kontroler woła modele (`core/Models`, zapytania PDO), składa dane przez zasoby
(`core/Resources`) i renderuje `Utils\View::render('web', 'nazwa', $data)`, co wkłada
`views/web/pages/nazwa.php` w `views/web/layout.php`. Jeden arkusz `assets/css/style.css`,
interakcje na Alpine.js z CDN. Baza MySQL, migracje ręczne (patrz [`database.md`](database.md)).
