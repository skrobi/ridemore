// assets/js/native.js
// MOST DO FUNKCJI NATYWNYCH — jedno API dla stron, dwie implementacje pod spodem.
//
// Serwis działa w dwóch miejscach naraz: w przeglądarce i w WebView aplikacji
// Capacitora, która ładuje TEN SAM adres (patrz tasks/active/apka-mobilna.md).
// Gdyby każda strona sama sprawdzała `window.Capacitor`, rozjazd między nimi
// byłby kwestią czasu — a rozjazd oznacza funkcję, która działa na jednym
// ekranie i milczy na drugim. Dlatego całe rozpoznanie siedzi TUTAJ, a strony
// wołają `RM.native.*` i dostają najlepsze, co w danym środowisku możliwe.
//
// ZASADA: most nigdy nie rzuca dlatego, że czegoś nie ma. Brak pluginu =
// zejście do odpowiednika przeglądarkowego (geolokalizacja) albo uczciwy
// komunikat (skaner), nigdy cicha porażka.
(function () {
    'use strict';

    var RM = window.RM = window.RM || {};

    // ŚCIEŻKA BAZOWA SERWISU — `/ridemore` w dev, pusta na produkcji.
    // Wyliczana z adresu TEGO pliku, a nie wstawiana z PHP-a: `native.js` jest
    // statyczny i cache'owany po adresie, więc nie może nieść w sobie niczego
    // zależnego od środowiska. Podstawienie jej na sztywno rozwaliłoby dev
    // albo produkcję — zawsze jedno z dwojga.
    var BASE_PATH = (function () {
        var tag = document.currentScript
            || document.querySelector('script[src*="/assets/js/native.js"]');
        if (!tag) { return ''; }
        // `pathname` odcina ?v=<filemtime>, które dokłada Utils\View::asset.
        return new URL(tag.src, window.location.origin).pathname
            .replace(/\/assets\/js\/native\.js$/, '');
    })();

    // Apka = WebView Capacitora. Sprawdzamy MOST, nie User-Agenta: UA służy
    // serwerowi (bootstrap.php) do decyzji o layoucie, ale o tym, czy da się
    // wywołać plugin, rozstrzyga wyłącznie obecność `Capacitor` w oknie.
    function isNative() {
        return !!(window.Capacitor && window.Capacitor.isNativePlatform && window.Capacitor.isNativePlatform());
    }

    function plugin(name) {
        return (isNative() && window.Capacitor.Plugins && window.Capacitor.Plugins[name]) || null;
    }

    var native = {
        get available() { return isNative(); },

        // POZYCJA — jedna obietnica zamiast callbacków `navigator.geolocation`.
        //
        // Wynik jest CELOWO spłaszczony do {lat, lon, accuracy}: dokładnie to,
        // czego potrzebują wszystkie wywołania w serwisie (zaliczenie skarbu,
        // potwierdzenie zgłoszenia, wyszukiwanie w pobliżu), i dokładnie to, co
        // oba źródła — plugin i przeglądarka — potrafią dać w tym samym kształcie.
        // Nazwa `lon`, nie `lng`, bo tak nazywają się pola w API skarbów.
        // DOMYŚLNE USTAWIENIA POPRAWIONE 2026-08-29 po zgłoszeniu z terenu:
        // „Could not obtain location in time. Try a higher timeout. Jakby GPS
        // zwieszał apkę".
        //
        // Stare wartości (timeout 10 s, maximumAge 0, wysoka dokładność) są
        // złe akurat w tym zastosowaniu. `maximumAge: 0` ZABRANIA użycia
        // pozycji, którą system ma już w ręku, więc każde wywołanie budziło
        // odbiornik od zera — a zimny fix GPS na dworze to zwykle 15-30 s,
        // nie 10. Efekt: błąd zamiast pozycji, i to dokładnie w chwili, gdy
        // user stoi przy skarbie i patrzy na pustą mapę.
        //
        //   timeout 30 s     — tyle realnie trwa zimny fix.
        //   maximumAge 30 s  — pozycja sprzed pół minuty jest w rowerowym
        //                      zastosowaniu wystarczająco świeża, a pojawia
        //                      się NATYCHMIAST. Pinezka i tak jest
        //                      przeciągalna, a mapa daje się przesunąć.
        //
        // Wołający, któremu zależy na precyzji, nadpisuje to jawnie —
        // interesuje nas, żeby DOMYŚLNIE ekran zapełniał się od razu.
        position: function (opts) {
            opts = opts || {};
            var options = {
                enableHighAccuracy: opts.highAccuracy !== false,
                timeout: opts.timeout || 30000,
                maximumAge: opts.maximumAge === undefined ? 30000 : opts.maximumAge
            };

            var geo = plugin('Geolocation');
            if (geo) {
                return geo.getCurrentPosition(options).then(function (pos) {
                    return { lat: pos.coords.latitude, lon: pos.coords.longitude, accuracy: pos.coords.accuracy };
                }, function (err) {
                    // GAŁĄŹ NATYWNA TEŻ PRZECHODZI PRZEZ `positionError`
                    // (2026-09-10). Do tej pory nie przechodziła: przeglądarka
                    // dostawała polski komunikat, a APKA — czyli jedyne
                    // miejsce, gdzie ten kod naprawdę biega w terenie —
                    // surowy tekst wtyczki po angielsku. Stąd zgłoszenie
                    // „Could not obtain location in time. Try a higher timeout"
                    // pokazane człowiekowi na ekranie. Nota nad `positionError`
                    // mówi „jeden komunikat dla obu źródeł błędu"; ta linijka
                    // sprawia, że to wreszcie prawda.
                    throw new Error(native.positionError(err));
                });
            }

            if (!navigator.geolocation) {
                return Promise.reject(new Error(__('Twoja przeglądarka nie udostępnia lokalizacji.')));
            }
            return new Promise(function (resolve, reject) {
                navigator.geolocation.getCurrentPosition(function (pos) {
                    resolve({ lat: pos.coords.latitude, lon: pos.coords.longitude, accuracy: pos.coords.accuracy });
                }, function (err) {
                    reject(new Error(native.positionError(err)));
                }, options);
            });
        },

        // Jeden komunikat dla obu źródeł błędu. Dotąd każde z siedmiu wywołań
        // geolokalizacji pisało własny tekst (albo nie pisało żadnego), więc
        // odmowa uprawnień wyglądała raz jak awaria, raz jak nic.
        positionError: function (err) {
            var code = err && err.code;
            if (code === 1) return __('Brak zgody na lokalizację — włącz ją w ustawieniach telefonu.');
            if (code === 2) return __('Nie udało się ustalić pozycji. Wyjdź na otwartą przestrzeń i spróbuj ponownie.');
            if (code === 3) return __('Ustalanie pozycji trwało zbyt długo. Spróbuj ponownie.');

            // WTYCZKA NATYWNA NIE UŻYWA KODÓW W3C — ma własne, tekstowe
            // („OS-PLUG-GLOC-…") i komunikat po angielsku. Rozpoznajemy więc
            // po treści, bo to jedyne, co daje. Trzy przypadki, te same trzy
            // co wyżej; wszystko inne dostaje zdanie ogólne, ale POLSKIE —
            // angielski komunikat wtyczki nie ma prawa wyjść na ekran.
            var tresc = (err && err.message) || '';
            if (/denied|permission|not allowed/i.test(tresc)) {
                return __('Brak zgody na lokalizację — włącz ją w ustawieniach telefonu.');
            }
            if (/time|timeout/i.test(tresc)) {
                return __('Ustalanie pozycji trwało zbyt długo. Spróbuj ponownie.');
            }
            if (/unavailable|disabled|location services/i.test(tresc)) {
                return __('Lokalizacja jest wyłączona. Włącz ją w ustawieniach telefonu.');
            }
            return __('Nie udało się ustalić pozycji. Wyjdź na otwartą przestrzeń i spróbuj ponownie.');
        },

        // ŻYWE ŚLEDZENIE POZYCJI (Etap 1 przebudowy apki, 2026-08-28) —
        // w odróżnieniu od `position()` (jeden odczyt) woła `onUpdate` przy
        // KAŻDEJ zmianie. Potrzebne do kropki „tu jestem" na pełnoekranowej
        // mapie. Id watcha oddajemy zawsze jako Promise: plugin i tak jest
        // asynchroniczny (`Promise<string>`), więc przeglądarkowy numeryczny
        // id owijamy w `Promise.resolve`, żeby wołający miał JEDEN kształt
        // niezależnie od źródła. `onUpdate(pozycja, blad)` — ten sam spłaszczony
        // kształt {lat, lon, accuracy} i ten sam `positionError()` co wyżej.
        watchPosition: function (opts, onUpdate) {
            opts = opts || {};
            var options = {
                enableHighAccuracy: opts.highAccuracy !== false,
                // Ten sam powód co przy `position()` wyżej: 10 s to mniej, niż
                // trwa zimny fix, a nasłuch z za krótkim czasem zgłasza błąd
                // zamiast czekać. `maximumAge` zostaje 0 — tutaj chodzi
                // o STRUMIEŃ pozycji („tu jestem" na mapie), a nie o jeden
                // szybki odczyt, więc stara pozycja nie ma czego przyspieszyć.
                timeout: opts.timeout || 30000,
                maximumAge: opts.maximumAge || 0
            };

            var geo = plugin('Geolocation');
            if (geo) {
                return geo.watchPosition(options, function (pos, err) {
                    if (err) { onUpdate(null, new Error(native.positionError(err))); return; }
                    onUpdate({ lat: pos.coords.latitude, lon: pos.coords.longitude, accuracy: pos.coords.accuracy }, null);
                });
            }

            if (!navigator.geolocation) {
                return Promise.reject(new Error(__('Twoja przeglądarka nie udostępnia lokalizacji.')));
            }
            var id = navigator.geolocation.watchPosition(function (pos) {
                onUpdate({ lat: pos.coords.latitude, lon: pos.coords.longitude, accuracy: pos.coords.accuracy }, null);
            }, function (err) {
                onUpdate(null, new Error(native.positionError(err)));
            }, options);
            return Promise.resolve(id);
        },

        // Odpowiednik dla obu źródeł — `id` to to, co oddał `watchPosition`
        // (po odpakowaniu z Promise).
        clearWatch: function (id) {
            var geo = plugin('Geolocation');
            if (geo) { return geo.clearWatch({ id: id }); }
            if (navigator.geolocation) { navigator.geolocation.clearWatch(id); }
            return Promise.resolve();
        },

        // NAGRYWANIE W TLE (Etap 5b przebudowy apki, 2026-08-28) —
        // ŚWIADOMIE OSOBNY PLUGIN od `watchPosition`/`clearWatch` wyżej.
        // `@capacitor/geolocation` (użyty tam) NIE gwarantuje pozycji, gdy
        // apka jest zwinięta — dobry do kropki „tu jestem" na ekranie, zły do
        // nagrywania przejazdu. `@capacitor-community/background-geolocation`
        // dostaje ZAMIAST TEGO własną usługę pierwszoplanową na Androidzie
        // (stąd `backgroundTitle`/`backgroundMessage` — treść jej powiadomienia,
        // wymaganego przez system, nie kosmetyka) i tryb tła na iOS.
        // W przeglądarce nie ma żadnego odpowiednika — w odróżnieniu od
        // geolokalizacji jednorazowej, strona nie może dostawać pozycji, gdy
        // karta jest w tle, więc most tu NIE UDAJE, że coś takiego zrobi.
        startTracking: function (opts, callback) {
            opts = opts || {};
            var bg = plugin('BackgroundGeolocation');
            if (!bg) {
                return Promise.reject(new Error(__('Nagrywanie w tle działa tylko w aplikacji.')));
            }
            return bg.addWatcher({
                backgroundTitle: opts.title || __('Ridemore nagrywa przejazd'),
                backgroundMessage: opts.message || __('Śledzimy trasę, żeby odkryć pola i skarby po drodze.'),
                // 25 m, nie 15 (2026-08-29, po zgłoszeniu o baterii). Filtr jest
                // ODLEGŁOŚCIOWY, więc sam skaluje się z prędkością: przy 20 km/h
                // to punkt co ~4,5 s, przy 40 km/h co ~2,3 s. Dla porównania
                // OwnTracks w trybie „move" (ich odpowiednik nagrywania
                // przejazdu) bierze fix co 10 s i sam pisze, że kosztuje to jak
                // nawigacja. 15 m dawało przy 20 km/h punkt co 2,7 s, czyli
                // czterokrotnie częściej niż oni — bez zysku dla kształtu śladu,
                // który i tak liczy się na polach ~500 m.
                distanceFilter: opts.distanceFilter || 25,
                requestPermissions: true,
                stale: false
            }, function (pozycja, blad) {
                if (blad) { callback(null, new Error(blad.message || __('Błąd pozycji w tle.'))); return; }
                if (!pozycja) { return; }
                callback({
                    lat: pozycja.latitude, lon: pozycja.longitude, accuracy: pozycja.accuracy,
                    speed: pozycja.speed, time: pozycja.time
                }, null);
            });
        },

        // `id` to to, co oddał `startTracking` (Promise<string>).
        stopTracking: function (id) {
            var bg = plugin('BackgroundGeolocation');
            if (!bg || !id) { return Promise.resolve(); }
            return bg.removeWatcher({ id: id });
        },

        // ZGODA NA POWIADOMIENIA — geolokalizacja pyta SAMA przy pierwszym
        // `startTracking()` (plugin robi to wewnątrz `addWatcher`), ale
        // powiadomienia lokalne to OSOBNE uprawnienie systemowe, więc pytamy
        // je jawnie, zanim cokolwiek spróbuje coś wysłać.
        requestBackgroundPermission: function () {
            var notif = plugin('LocalNotifications');
            if (!notif) { return Promise.resolve({ granted: false }); }
            return notif.requestPermissions().then(function (r) {
                return { granted: r && r.display === 'granted' };
            });
        },

        // POWIADOMIENIE LOKALNE — w apce prawdziwy plugin; w przeglądarce
        // odpowiednik Web Notifications API, JEŚLI zgoda już jest (nie
        // wymuszamy pytania z tego wywołania — to zrobiłoby z cichego alertu
        // natrętny popup przy pierwszym wejściu na stronę).
        // `o.url` (opcjonalnie) — ŚCIEŻKA w tym serwisie, otwierana po
        // tapnięciu w powiadomienie. Świadomie ścieżka, nie pełny adres:
        // origin i base_path dokłada niżej sam most, więc treść powiadomienia
        // nie ma jak wyprowadzić nikogo na obcy host (ta sama ostrożność co
        // w `adresSkarbu`, tylko tańsza — nie ma czego sprawdzać).
        notify: function (o) {
            var notif = plugin('LocalNotifications');
            if (notif) {
                return notif.schedule({ notifications: [{
                    id: o.id || Date.now() % 2147483647,
                    title: o.title, body: o.body,
                    extra: o.url ? { url: o.url } : undefined
                }] });
            }
            try {
                if (window.Notification && Notification.permission === 'granted') {
                    new Notification(o.title, { body: o.body });
                }
            } catch (e) {}
            return Promise.resolve();
        },

        // STAN SIECI (@capacitor/network) — most wystawia go jawnie, bo od
        // 2026-08-29 potrzebują go DWIE niezależne rzeczy: kolejka skanów
        // (miała własne, wewnętrzne odczyty niżej) i wskaźnik offline w pasku.
        //
        // `navigator.onLine` jako zapas: w przeglądarce to jedyne, co jest,
        // a w apce bez wtyczki lepszy zgrubny sygnał niż milczenie. Uwaga na
        // jego znaną słabość — mówi „mam interfejs sieciowy", nie „mam
        // internet", więc bywa optymistyczny. W apce wtyczka odpowiada trafniej.
        isOnline: function () {
            var siec = plugin('Network');
            if (!siec) { return Promise.resolve(navigator.onLine !== false); }
            return siec.getStatus().then(function (s) { return !!s.connected; })
                       .catch(function () { return navigator.onLine !== false; });
        },

        // Nasłuch zmian. Wywołuje `cb(online)` przy KAŻDEJ zmianie; nie woła
        // od razu stanem bieżącym — o to pyta się `isOnline()`, żeby wołający
        // sam decydował, czy potrzebuje stanu startowego.
        onNetworkChange: function (cb) {
            var siec = plugin('Network');
            if (siec) {
                siec.addListener('networkStatusChange', function (stan) {
                    cb(!!(stan && stan.connected));
                });
                return;
            }
            window.addEventListener('online', function () { cb(true); });
            window.addEventListener('offline', function () { cb(false); });
        },

        // APARAT (@capacitor/camera, Etap 6, 2026-08-28) — jedno zdjęcie na
        // wywołanie, oddane jako `File` gotowy do wsadzenia w ISTNIEJĄCY
        // `<input type=file multiple>` (patrz delegacja `[data-rm-camera-for]`
        // niżej) — formularz zostaje zwykłym multipart POST-em, zero zmian
        // po stronie serwera. `takePhoto`, NIE przestarzałe `getPhoto` — ta
        // wersja pluginu oznaczyła je jako deprecated na rzecz osobnych
        // `takePhoto`/`chooseFromGallery`. Brak odpowiednika w przeglądarce:
        // tam formularz i tak ma zwykły `<input type=file>`, który na
        // telefonie sam proponuje aparat jako źródło.
        takePhoto: function (opts) {
            opts = opts || {};
            var cam = plugin('Camera');
            if (!cam) {
                return Promise.reject(new Error(__('Aparat działa tylko w aplikacji.')));
            }
            return cam.takePhoto({
                quality: opts.quality || 82,
                correctOrientation: true,
                saveToGallery: false
            }).then(function (wynik) {
                if (!wynik || !wynik.webPath) {
                    throw new Error(__('Nie udało się zrobić zdjęcia.'));
                }
                // `webPath` to specjalny adres pluginu (nie zwykły URL) —
                // jedyny udokumentowany sposób zamiany go na Blob to fetch.
                return fetch(wynik.webPath)
                    .then(function (r) { return r.blob(); })
                    .then(function (blob) {
                        var ext = blob.type === 'image/png' ? 'png'
                            : (blob.type === 'image/webp' ? 'webp' : 'jpg');
                        return new File([blob], 'zdjecie-' + Date.now() + '.' + ext, {
                            type: blob.type || 'image/jpeg'
                        });
                    });
            });
        },

        // WYSŁANIE PLIKU DO INNEJ APLIKACJI (@capacitor/filesystem +
        // @capacitor/share, 2026-09-10) — po to, żeby GPX trasy trafił
        // z ridemore.bike wprost do OsmAnda, Locusa, Komoota, Garmina czy
        // czegokolwiek, co user ma na telefonie.
        //
        // POWÓD ISTNIENIA: `<a href="…gpx" download>` w apce NIE DZIAŁA.
        // Sprawdzone w źródłach Capacitora 8.5: adres z tego samego hosta
        // wraca do WebView (`Bridge.launchIntent` → `return false`), a WebView
        // nie ma ustawionego `DownloadListener` — ani przez Capacitora (brak
        // go w całym `@capacitor/android`), ani przez nasze `MainActivity`.
        // Dotknięcie „Pobierz GPX" kończyło się więc niczym.
        //
        // `canShareFile` istnieje osobno, bo od niego zależy NAPIS na przycisku
        // (assets/js/app-share.js): bez obu wtyczek zostaje „Pobierz GPX"
        // i systemowe pobieranie, z nimi — „Wyślij do nawigacji" i arkusz
        // wyboru aplikacji. Przycisk nie ma prawa obiecywać drugiego, gdy
        // umie tylko pierwsze.
        canShareFile: function () {
            return !!(plugin('Filesystem') && plugin('Share'));
        },

        shareFile: function (url, nazwa, tytul) {
            var fs = plugin('Filesystem');
            var share = plugin('Share');
            if (!fs || !share) {
                return Promise.reject(new Error(__('Wysyłanie pliku działa tylko w aplikacji.')));
            }

            // `credentials: 'include'` — część plików leży za bramką sesji
            // (ślad przejazdu), a apka ma tę sesję w cookie first-party.
            return fetch(url, { credentials: 'include' })
                .then(function (r) {
                    if (!r.ok) { throw new Error(__('Nie udało się pobrać pliku.')); }
                    return r.blob();
                })
                .then(function (blob) {
                    return new Promise(function (resolve, reject) {
                        var czyt = new FileReader();
                        // Wtyczka przyjmuje WYŁĄCZNIE base64, a `readAsDataURL`
                        // to jedyna droga do niego z Bloba bez własnej pętli po
                        // bajtach. Odcinamy prefiks `data:…;base64,`.
                        czyt.onload = function () {
                            var s = String(czyt.result);
                            resolve(s.slice(s.indexOf(',') + 1));
                        };
                        czyt.onerror = function () { reject(new Error(__('Nie udało się odczytać pliku.'))); };
                        czyt.readAsDataURL(blob);
                    });
                })
                .then(function (base64) {
                    // KATALOG PODRĘCZNY, nie „Dokumenty": to plik przelotowy,
                    // oddany innej aplikacji i niepotrzebny nam ani chwili
                    // dłużej. System sprząta go sam, gdy zabraknie miejsca.
                    return fs.writeFile({
                        path: nazwa,
                        data: base64,
                        directory: 'CACHE'
                    }).then(function () {
                        return fs.getUri({ path: nazwa, directory: 'CACHE' });
                    });
                })
                .then(function (wynik) {
                    return share.share({
                        title: tytul || nazwa,
                        files: [wynik.uri]
                    });
                });
        },

        // PUSH (@capacitor/push-notifications, Etap 8, 2026-08-28) — pyta
        // o zgodę systemową, rejestruje urządzenie i wysyła token na
        // ISTNIEJĄCY endpoint (POST /api/devices/register — Models\PushDevice).
        // Wołane WYŁĄCZNIE gdy user sam włączył przełącznik w koncie
        // (views/web/pages/account.php) — nigdy automatycznie przy starcie
        // apki, ten sam odruch co zgoda na nagrywanie w tle (Etap 5b): pytanie
        // o uprawnienie systemowe MUSI iść za jawną decyzją w apce, nie
        // wyprzedzać jej. Brak odpowiednika w przeglądarce — jak `startTracking()`.
        registerPush: function () {
            var push = plugin('PushNotifications');
            if (!push) {
                return Promise.reject(new Error(__('Powiadomienia push działają tylko w aplikacji.')));
            }
            return push.requestPermissions().then(function (stan) {
                if (stan.receive !== 'granted') {
                    throw new Error(__('Brak zgody na powiadomienia.'));
                }
                return new Promise(function (resolve, reject) {
                    push.addListener('registration', function (token) { resolve(token.value); });
                    push.addListener('registrationError', function (blad) {
                        reject(new Error((blad && blad.error) || __('Nie udało się zarejestrować urządzenia.')));
                    });
                    push.register();
                });
            }).then(function (token) {
                var platforma = (window.Capacitor && window.Capacitor.getPlatform)
                    ? window.Capacitor.getPlatform() : 'android';
                var dane = new URLSearchParams();
                dane.set('csrf_token', window.RM_CSRF || '');
                dane.set('platform', platforma);
                dane.set('token', token);
                return fetch(window.location.origin + BASE_PATH + '/api/devices/register', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: dane.toString(),
                    credentials: 'same-origin'
                });
            });
        },

        // SKANER KODU QR (@capacitor-mlkit/barcode-scanning, Etap 4).
        //
        // Oddaje ADRES z naklejki albo null, gdy user zrezygnował. Wybrałem
        // `startScan` z podglądem pod stroną, a nie androidowy `scan()` (gotowe
        // okno Google): `scan()` nie istnieje na iOS, więc byłyby dwie różne
        // ścieżki do utrzymania i tylko jedna z nich testowana na co dzień.
        //
        // Podgląd kamery rysuje się POD WebView, więc na czas skanowania
        // strona musi być przezroczysta — robi to klasa `rm-scan-on` na <html>
        // (style w style.css). Bez niej user patrzy na swoją stronę zamiast
        // na obraz z aparatu i wygląda to jak zawieszenie.
        scan: function () {
            var skaner = plugin('BarcodeScanner');
            if (!skaner) {
                return Promise.reject(new Error(__('Skanowanie działa tylko w aplikacji Ridemore.')));
            }
            if (trwaSkan) {
                return Promise.reject(new Error(__('Skaner już pracuje.')));
            }

            return skaner.requestPermissions().then(function (stan) {
                // 'limited' to iOS: user dał dostęp węższy niż pełny, ale
                // aparat działa — odmawianie tutaj byłoby uznaniem zgody za brak zgody.
                if (stan && stan.camera !== 'granted' && stan.camera !== 'limited') {
                    throw new Error(__('Bez zgody na aparat nie odczytam kodu. Zgodę włączysz w ustawieniach telefonu.'));
                }
                return podglad(skaner);
            });
        },

        // ADRES Z KODU — albo null, gdy to nie jest nasza naklejka.
        //
        // Kod QR przynosi obcy tekst i nie wolno mu ufać: bez tego filtra
        // zeskanowanie dowolnej naklejki z miasta wyprowadzałoby użytkownika
        // z aplikacji na cudzą stronę, z sesją Ridemore w tym samym WebView.
        // Wymagamy TEGO SAMEGO ORIGINU co strona, którą apka ma otwartą —
        // przy testach na lokalnym serwerze naklejki produkcyjne słusznie
        // nie przejdą, bo to inny adres.
        adresSkarbu: function (surowy) {
            var url;
            try {
                url = new URL(String(surowy), window.location.origin);
            } catch (e) {
                return null;
            }
            if (url.origin !== window.location.origin) { return null; }
            // Kod naklejki to heks z Utils\Qr; wzorzec trzymamy szeroko
            // (litery/cyfry/–/_), ale bez slashy — żeby nie dało się dopisać
            // dalszej ścieżki za kodem.
            if (!/\/skarb\/[A-Za-z0-9_-]+\/?$/.test(url.pathname)) { return null; }
            return url.href;
        }
    };

    // --- skaner: podgląd i sprzątanie po nim ---------------------------------
    // Stan trzymany w domknięciu, nie na obiekcie: dwa równoległe skanowania
    // dzieliłyby jeden natywny podgląd i drugie ubiłoby pierwsze.
    var trwaSkan = false;

    function podglad(skaner) {
        return new Promise(function (resolve, reject) {
            trwaSkan = true;

            var warstwa = document.createElement('div');
            warstwa.className = 'rm-scan';
            warstwa.innerHTML =
                '<div class="rm-scan__ramka" aria-hidden="true"></div>' +
                __('<p class="rm-scan__hint">Skieruj aparat na kod QR z naklejki</p>');

            var anuluj = document.createElement('button');
            anuluj.type = 'button';
            anuluj.className = 'rm-scan__anuluj';
            anuluj.textContent = 'Anuluj';
            warstwa.appendChild(anuluj);

            document.documentElement.classList.add('rm-scan-on');
            document.body.appendChild(warstwa);

            var uchwyt = null;
            var skonczone = false;

            // JEDNO WYJŚCIE dla wszystkich zakończeń (kod, anulowanie, błąd) —
            // przy trzech osobnych ścieżkach prędzej czy później któraś zostawia
            // włączony aparat i przezroczystą stronę, czyli apkę nie do użycia.
            function koniec(wynik, blad) {
                if (skonczone) { return; }
                skonczone = true;
                trwaSkan = false;

                Promise.resolve(uchwyt)
                    .then(function (h) { return h && h.remove ? h.remove() : null; })
                    .catch(function () { /* nasłuch mógł nie powstać */ })
                    .then(function () { return skaner.stopScan(); })
                    .catch(function () { /* podgląd mógł nie wystartować */ })
                    .then(function () {
                        document.documentElement.classList.remove('rm-scan-on');
                        warstwa.remove();
                        if (blad) { reject(blad); } else { resolve(wynik); }
                    });
            }

            anuluj.addEventListener('click', function () { koniec(null, null); });

            uchwyt = skaner.addListener('barcodesScanned', function (zdarzenie) {
                var kody = (zdarzenie && zdarzenie.barcodes) || [];
                if (!kody.length) { return; }
                koniec(kody[0].rawValue || kody[0].displayValue || null, null);
            });

            Promise.resolve(uchwyt)
                .then(function () { return skaner.startScan({ formats: ['QR_CODE'] }); })
                .catch(function (err) {
                    koniec(null, new Error((err && err.message) || __('Nie udało się uruchomić aparatu.')));
                });
        });
    }

    RM.native = native;

    // Przycisk skanowania z dolnego paska (partials/app-nav.php). Podpięcie
    // przez delegację, bo pasek renderuje się w layoucie, a ten skrypt ma
    // `defer` — kolejność i tak by wyszła, ale delegacja przeżyje też pasek
    // dorysowany później.
    // LOGOWANIE SPOŁECZNOŚCIOWE — MUSI WYJŚĆ POZA APKĘ I WRÓCIĆ DEEP LINKIEM.
    //
    // Google odrzuca OAuth w WebView (`disallowed_useragent`), więc autoryzacja
    // z definicji dzieje się w systemowej przeglądarce. Ta ma OSOBNE ciasteczka:
    // zalogowanie się tam nie loguje aplikacji, a `oauth2state` zapisany po
    // jednej stronie nie istnieje po drugiej — dokładnie stąd brał się komunikat
    // „Weryfikacja logowania nie powiodła się" i zostawanie w przeglądarce
    // (zgłoszenie usera 2026-08-23).
    //
    // Parametr `app=1` mówi serwerowi, żeby zakończył OAuth nie sesją, tylko
    // jednorazowym tokenem w deep linku (patrz SocialAuthController i migr. 067).
    // Poza apką ten kod nie robi NIC — link działa po staremu.
    document.addEventListener('click', function (e) {
        var link = e.target.closest && e.target.closest('[data-rm-oauth]');
        if (!link || !isNative()) { return; }

        var browser = plugin('Browser');
        if (!browser) { return; } // brak pluginu = zostaje zwykłe przejście

        e.preventDefault();
        var url = new URL(link.href, window.location.origin);
        url.searchParams.set('app', '1');
        browser.open({ url: url.href });
    });

    // POWRÓT Z PRZEGLĄDARKI — system oddaje nam adres `bike.ridemore.app://auth?token=…`.
    // Token wymieniamy na sesję zwykłą nawigacją WebView, czyli po stronie,
    // po której cookie ma w końcu wylądować.
    (function () {
        var app = plugin('App');
        if (!app) { return; }

        app.addListener('appUrlOpen', function (zdarzenie) {
            var adres = (zdarzenie && zdarzenie.url) || '';
            if (adres.indexOf('://auth') === -1) { return; }

            // URL z niestandardowym schematem — `new URL` radzi sobie z nim
            // różnie na różnych silnikach, więc token wyciągamy wprost.
            var token = (adres.match(/[?&]token=([^&]+)/) || [])[1];
            if (!token) { return; }

            var browser = plugin('Browser');
            if (browser) { browser.close(); } // zamykamy kartę logowania

            window.location.href = window.location.origin
                + BASE_PATH + '/auth/app?token=' + token;
        });
    })();

    // TAPNIĘCIE W POWIADOMIENIE OTWIERA TO, CZEGO DOTYCZY (2026-08-29).
    // Bez tego notka „Zaliczono: {skarb}" była ślepym zaułkiem — mówiła, że
    // coś ciekawego minąłeś, i nie dawała jak tego przeczytać. Nawigujemy
    // WYŁĄCZNIE ścieżką złożoną tutaj z origin + base_path; `extra.url`
    // z powiadomienia jest traktowane jak dane, nie jak gotowy adres.
    (function () {
        var notif = plugin('LocalNotifications');
        if (!notif) { return; }
        notif.addListener('localNotificationActionPerformed', function (zdarzenie) {
            var sciezka = zdarzenie && zdarzenie.notification
                && zdarzenie.notification.extra && zdarzenie.notification.extra.url;
            if (typeof sciezka !== 'string' || sciezka.charAt(0) !== '/' || sciezka.charAt(1) === '/') { return; }
            window.location.href = window.location.origin + BASE_PATH + sciezka;
        });
    })();

    // TAPNIĘCIE W POWIADOMIENIE PUSH (Etap 2 programu zachęt, 2026-09-11).
    //
    // Robi dwie rzeczy naraz i w tej kolejności:
    //   1. NAWIGUJE tam, czego powiadomienie dotyczy — ta sama zasada co przy
    //      powiadomieniach lokalnych wyżej: `data.url` jest DANYMI, nie gotowym
    //      adresem, więc składamy go z origin + base_path i przepuszczamy tylko
    //      ścieżkę zaczynającą się od jednego ukośnika.
    //   2. ODSYŁA POTWIERDZENIE OTWARCIA (`nid` z ładunku). To jedyne źródło
    //      `notification_log.opened_at`, czyli jedyna odpowiedź na pytanie, czy
    //      ktokolwiek te powiadomienia otwiera — a bez niej strojenie budżetu
    //      jest zgadywaniem.
    //
    // Potwierdzenie leci „w tle" i jego błąd jest CELOWO połykany: nawigacja do
    // treści jest ważniejsza niż statystyka, a człowiek nie ma co zrobić
    // z komunikatem o nieudanym pomiarze.
    //
    // Listener rejestruje się BEZWARUNKOWO przy starcie apki (nie w
    // `registerPush()`), bo tapnięcie dotyczy powiadomień wysłanych dawno temu —
    // także takich, które przyszły, zanim ten ekran się otworzył.
    (function () {
        var push = plugin('PushNotifications');
        if (!push) { return; }

        push.addListener('pushNotificationActionPerformed', function (zdarzenie) {
            var dane = (zdarzenie && zdarzenie.notification && zdarzenie.notification.data) || {};

            if (dane.nid) {
                var body = new URLSearchParams();
                body.set('csrf_token', window.RM_CSRF || '');
                body.set('nid', String(dane.nid));
                fetch(window.location.origin + BASE_PATH + '/api/powiadomienia/otwarte', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString(),
                    credentials: 'same-origin'
                }).catch(function () { /* patrz komentarz wyżej — pomiar nie może przeszkadzać */ });
            }

            var sciezka = dane.url;
            if (typeof sciezka !== 'string' || sciezka.charAt(0) !== '/' || sciezka.charAt(1) === '/') { return; }
            window.location.href = window.location.origin + BASE_PATH + sciezka;
        });
    })();

    // KOLEJKA ZESKANOWANYCH KODÓW BEZ ZASIĘGU (Etap 7 przebudowy apki,
    // 2026-08-28) — skan w terenie bez sygnału NIE MOŻE przepaść. Zamiast
    // nawigacji na `/skarb/{kod}` (i tak nic by nie pobrała bez sieci) kod
    // ląduje w kolejce (`@capacitor/preferences`, przetrwa restart) i zalicza
    // się SAM, gdy wróci zasięg — bez ponownego skanowania. `app/www/index.html`
    // (ekran „brak połączenia", Etap 3) od dawna to obiecywał w treści; teraz
    // jest to prawdą.
    (function () {
        var K_QUEUE = 'rm_scan_queue';
        var przetwarzanie = false;

        function prefsGet(klucz) {
            var p = plugin('Preferences');
            if (p) { return p.get({ key: klucz }).then(function (r) { return r.value; }); }
            try { return Promise.resolve(localStorage.getItem(klucz)); } catch (e) { return Promise.resolve(null); }
        }
        function prefsSet(klucz, wartosc) {
            var p = plugin('Preferences');
            if (p) { return p.set({ key: klucz, value: wartosc }); }
            try { localStorage.setItem(klucz, wartosc); } catch (e) {}
            return Promise.resolve();
        }
        function wczytajKolejke() {
            return prefsGet(K_QUEUE).then(function (raw) {
                try { return raw ? JSON.parse(raw) : []; } catch (e) { return []; }
            });
        }
        function zapiszKolejke(lista) {
            return prefsSet(K_QUEUE, JSON.stringify(lista));
        }

        // DOŁĄCZENIE DO KOLEJKI — wołane z klik-handlera skanera niżej, gdy
        // urządzenie jest offline w chwili skanowania. Pozycja to NAJLEPSZY
        // WYSIŁEK: GPS działa bez sieci, ale krótki timeout, żeby nie trzymać
        // człowieka w lesie — `Treasure::claim()` i tak akceptuje brak lat/lon.
        native.queueScan = function (kod) {
            return native.position({ timeout: 3000 }).catch(function () { return null; })
                .then(function (poz) {
                    return wczytajKolejke().then(function (lista) {
                        lista.push({
                            code: kod,
                            lat: poz ? poz.lat : null,
                            lon: poz ? poz.lon : null,
                            queuedAt: Date.now()
                        });
                        return zapiszKolejke(lista);
                    });
                });
        };

        // ZALICZENIE Z SAMEJ POZYCJI, BEZ ZASIĘGU (2026-08-29) — mijasz skarb
        // w promieniu, telefon nie ma sieci. TA SAMA kolejka co skany: różni je
        // wyłącznie brak `code` i inny endpoint przy wysyłce, a cała reszta
        // (trwałość, ponowienie po powrocie zasięgu, jednorazowość) jest
        // dokładnie tym samym problemem i nie zasługuje na drugą implementację.
        native.queueClaim = function (lat, lon) {
            return wczytajKolejke().then(function (lista) {
                lista.push({ code: null, lat: lat, lon: lon, queuedAt: Date.now() });
                return zapiszKolejke(lista);
            });
        };

        // WYSYŁKA ZALEGŁEGO SKANU — TEN SAM endpoint co przycisk „Odbierz" na
        // stronie skarbu (POST /skarb/{code}), z nagłówkiem `Accept`, który
        // każe `TreasureScanController` odpowiedzieć JSON-em zamiast pełną
        // stroną (patrz `respond()` w kontrolerze) — apka nic nie pokazuje
        // w tym momencie, więc parsowanie HTML-a byłoby zbędnym ryzykiem.
        function wyslijZalegly(pozycja) {
            // Bez kodu = zaliczenie z pozycji: inny endpoint, ale ta sama
            // droga. `/api/treasures/claim` sam znajduje WSZYSTKIE skarby
            // w promieniu przysłanego punktu, więc jedno żądanie może zamknąć
            // kilka zaległości naraz.
            var url = pozycja.code
                ? window.location.origin + BASE_PATH + '/skarb/' + encodeURIComponent(pozycja.code)
                : window.location.origin + BASE_PATH + '/api/treasures/claim';
            var dane = new URLSearchParams();
            dane.set('csrf_token', window.RM_CSRF || '');
            if (pozycja.lat !== null) { dane.set('lat', pozycja.lat); }
            if (pozycja.lon !== null) { dane.set('lon', pozycja.lon); }

            return fetch(url, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' },
                body: dane.toString(),
                credentials: 'same-origin'
            }).then(function (r) { return r.json(); }).catch(function () { return null; });
        }

        // Powody, przy których PONAWIANIE nic nie da (skarb już zdobyty, kod
        // nieznany/wycofany, brak konta) — zdejmujemy z kolejki. `sesja`
        // (CSRF wygasł) i brak odpowiedzi (nadal offline mimo zdarzenia)
        // zostają — spróbujemy przy następnej okazji.
        var POWODY_TRWALE = ['juz_zaliczona', 'nieznana', 'nieaktywna', 'zaloguj'];

        function przetworzKolejke() {
            if (przetwarzanie || !window.RM_CSRF) { return; }
            przetwarzanie = true;
            wczytajKolejke().then(function (lista) {
                if (!lista.length) { przetwarzanie = false; return; }

                var zostaje = [];
                var kolejno = lista.reduce(function (lancuch, pozycja) {
                    return lancuch.then(function () {
                        return wyslijZalegly(pozycja).then(function (wynik) {
                            if (wynik === null) { zostaje.push(pozycja); return; } // nadal offline/błąd

                            // Zaliczenie z pozycji ma inny kształt odpowiedzi
                            // (`claimed[]`, nie `ok`/`reason`) — samo dojście
                            // do serwera kończy sprawę: pusta lista znaczy, że
                            // w tym punkcie nie było już czego zaliczyć,
                            // a ponawianie tego nie zmieni.
                            if (!pozycja.code) {
                                (wynik.claimed || []).forEach(function (c, i) {
                                    native.notify({
                                        id: 910000000 + ((pozycja.queuedAt + i) % 80000000),
                                        title: __('Zaliczono: {nazwa}', { nazwa: c.name }),
                                        body: __('Minięte bez zasięgu — +{n} pkt.', { n: c.points || 0 }),
                                        url: c.code ? '/skarb/' + c.code : null
                                    });
                                });
                                return; // zdjęte z kolejki
                            }

                            if (wynik.ok) {
                                native.notify({
                                    id: 900000000 + (pozycja.queuedAt % 90000000),
                                    title: wynik.name || __('Skarb zaliczony'),
                                    body: __('Zeskanowany w terenie — +{n} pkt.', { n: wynik.points || 0 })
                                });
                                return; // zdjęte z kolejki
                            }
                            if (POWODY_TRWALE.indexOf(wynik.reason) !== -1) { return; } // trwała odmowa, zdjęte
                            zostaje.push(pozycja); // tymczasowe — spróbuj później
                        });
                    });
                }, Promise.resolve());

                return kolejno.then(function () { return zapiszKolejke(zostaje); });
            }).then(function () { przetwarzanie = false; })
              .catch(function () { przetwarzanie = false; });
        }

        var siecKolejki = plugin('Network');
        if (siecKolejki) {
            siecKolejki.addListener('networkStatusChange', function (stan) {
                if (stan && stan.connected) { przetworzKolejke(); }
            });
        }
        // Też przy starcie strony — kolejka mogła doczekać zasięgu z zamkniętą apką.
        przetworzKolejke();
    })();

    document.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('[data-rm-scan]');
        if (!btn) return;
        e.preventDefault();

        native.scan().then(function (kod) {
            // null = user anulował. To nie jest błąd i nie zasługuje na komunikat.
            if (kod === null) { return; }

            var adres = native.adresSkarbu(kod);
            if (adres === null) {
                alert(__('To nie jest kod skarbu Ridemore.'));
                return;
            }

            // OFFLINE (Etap 7) — nawigacja pod adres i tak nic by nie pobrała.
            // Kod ląduje w kolejce i zaliczy się sam, gdy wróci zasięg.
            var siec = plugin('Network');
            (siec ? siec.getStatus().then(function (s) { return !!s.connected; })
                  : Promise.resolve(navigator.onLine))
                .then(function (online) {
                    if (online) {
                        // Zaliczenie robi dopiero POST na tej stronie — wejście
                        // pod adres tylko pokazuje skarb (TreasureScanController).
                        window.location.href = adres;
                        return;
                    }
                    native.queueScan(kod).then(function () {
                        alert(__('Brak zasięgu — zapisano. Skarb zaliczy się sam, gdy wrócisz do sieci.'));
                    });
                });
        }).catch(function (err) {
            alert(err.message);
        });
    });

    // PRZYCISK „ZRÓB ZDJĘCIE" (Etap 6, 2026-08-28) — `data-rm-camera-for="id"`
    // dokłada zrobione zdjęcie do ISTNIEJĄCEGO `<input type=file multiple>`
    // o tym id, przez DataTransfer, żeby formularz zostawał zwykłym
    // multipart POST-em (zero zmian po stronie serwera). DOKŁADAMY do tego,
    // co już jest w polu, a nie nadpisujemy — kilka naciśnięć albo zdjęcie +
    // wybór z galerii mają się zsumować. Delegacja jak przy skanowaniu wyżej.
    document.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('[data-rm-camera-for]');
        if (!btn) return;
        e.preventDefault();

        var input = document.getElementById(btn.getAttribute('data-rm-camera-for'));
        if (!input) { return; }

        var pierwotnyTekst = btn.textContent;
        btn.disabled = true;
        native.takePhoto().then(function (plik) {
            var dt = new DataTransfer();
            // DOKŁADAMY TYLKO DO POLA WIELOPLIKOWEGO. Do 2026-08-29 jedynym
            // odbiorcą była galeria skarbu (`multiple`), więc dokładanie było
            // zawsze poprawne. Zgłoszenie skarbu ma pole na JEDNO zdjęcie
            // (okładka, `treasures.photo_url`) — tam dokładanie ustawiłoby
            // dwuelementową listę na polu, które przyjmuje jeden plik, a to
            // każda przeglądarka rozstrzyga po swojemu. Przy polu bez
            // `multiple` kolejne naciśnięcie ma po prostu ZASTĄPIĆ zdjęcie,
            // bo dokładnie tego user oczekuje po „zrób jeszcze raz".
            if (input.multiple) {
                for (var i = 0; i < input.files.length; i++) { dt.items.add(input.files[i]); }
            }
            dt.items.add(plik);
            input.files = dt.files;
            input.dispatchEvent(new Event('change', { bubbles: true }));
        }).catch(function (err) {
            alert(err.message || __('Nie udało się zrobić zdjęcia.'));
        }).then(function () {
            btn.disabled = false;
            btn.textContent = pierwotnyTekst;
        });
    });
})();
