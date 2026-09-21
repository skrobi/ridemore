// scripts/treasure-fill.js
// Wypełnianie formularza zgłoszenia skarbu (/skarby/zglos) na ridemore.bike.
// Wstrzykiwany do ISOLATED world przez sidepanel.js po otwarciu /skarby/zglos.
//
// Dane wejściowe: window.postMessage z JSON {name, description, lat, lon, category}
//   - name: string (nazwa skarbu)
//   - description: string|null (opis)
//   - lat: float (szerokość geograficzna)
//   - lon: float (długość geograficzna)
//   - category: string|null (kod kategorii, np. 'sacral', 'nature')
//
// Wypełnia pola formularza i wywołuje istniejącą ustaw() (vanilla JS na stronie).
// Zwraca postMessage {ok: true/false, filled: [...]} do sidepanel.js.

(function () {
    'use strict';

    // Słownik mapowania kodów kategorii na ich nazwy w <select>.
    // Kod -> fragment nazwy do wyszukania w opcjach. Gdy kod jest równy
    // nazwie (typowe), wyszukujemy po kodzie. Gdy nie pasuje — staramy się
    // znaleźć略略 via startsWith/Includes.
    var CATEGORY_LABELS = {
        'sacral':     'sakral',
        'nature':     'przyrod',
        'landscape':  'krajobraz',
        'history':    'historyczn',
        'culture':    'kulturow',
        'sport':      'sport',
        'other':      'inne',
    };

    function findCategoryOption(code) {
        if (!code) return null;
        var select = document.getElementById('zgKat');
        if (!select) return null;

        var codeLower = code.toLowerCase();
        var options = select.options;

        // 1. Dokładne dopasowanie kodu (case-insensitive)
        for (var i = 0; i < options.length; i++) {
            if (options[i].value && options[i].text.toLowerCase().indexOf(codeLower) !== -1) {
                return options[i].value;
            }
        }

        // 2. Dopasowanie przez fragment kodu (np. 'sacral' -> 'Sakralne')
        var fragment = CATEGORY_LABELS[codeLower];
        if (fragment) {
            var fragLower = fragment.toLowerCase();
            for (var j = 0; j < options.length; j++) {
                if (options[j].value && options[j].text.toLowerCase().indexOf(fragLower) !== -1) {
                    return options[j].value;
                }
            }
        }

        return null;
    }

    function waitForForm(cb, attempts) {
        attempts = attempts || 0;
        if (document.getElementById('zgNazwa')) {
            cb();
        } else if (attempts < 100) {
            setTimeout(function () { waitForForm(cb, attempts + 1); }, 50);
        }
    }

    function fill(data) {
        var filled = [];

        // Nazwa
        var nameEl = document.getElementById('zgNazwa');
        if (nameEl && data.name) {
            nameEl.value = String(data.name).substring(0, 160);
            nameEl.dispatchEvent(new Event('input', { bubbles: true }));
            nameEl.dispatchEvent(new Event('change', { bubbles: true }));
            filled.push('name');
        }

        // Opis
        var descEl = document.getElementById('zgOpis');
        if (descEl && data.description) {
            descEl.value = String(data.description).substring(0, 600);
            descEl.dispatchEvent(new Event('input', { bubbles: true }));
            descEl.dispatchEvent(new Event('change', { bubbles: true }));
            filled.push('description');
        }

        // Kategoria (kod -> ID z <select>)
        var catEl = document.getElementById('zgKat');
        if (catEl && data.category) {
            var catId = findCategoryOption(data.category);
            if (catId) {
                catEl.value = catId;
                catEl.dispatchEvent(new Event('change', { bubbles: true }));
                filled.push('category');
            }
        }

        // Współrzędne (ukryte pola + ustaw pinezkę)
        if (typeof data.lat === 'number' && typeof data.lon === 'number') {
            if (typeof window.ustaw === 'function') {
                window.ustaw(data.lat, data.lon, 'z rozszerzenia');
                filled.push('lat', 'lon');
            } else {
                // Fallback: ustaw pola ręcznie (ustaw() jeszcze się nie załadowała)
                var latEl = document.getElementById('zgLat');
                var lonEl = document.getElementById('zgLon');
                if (latEl && lonEl) {
                    latEl.value = data.lat.toFixed(5);
                    lonEl.value = data.lon.toFixed(5);
                    filled.push('lat', 'lon');
                }
            }
        }

        return filled;
    }

    // Odbiór danych z sidepanel.js
    window.addEventListener('message', function (e) {
        if (!e.data || e.data.type !== 'RMI_FILL_TREASURE') return;

        var data = e.data.payload || {};
        waitForForm(function () {
            var filled = fill(data);
            window.postMessage({
                type: 'RMI_TREASURE_FILLED',
                ok: filled.length > 0,
                filled: filled,
            }, '*');
        });
    });
})();
