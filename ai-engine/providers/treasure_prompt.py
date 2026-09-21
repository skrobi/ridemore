# providers/treasure_prompt.py
# Prompt + schemat JSON dla trybu "Dodaj skarb" (mode='treasure').
# Ekstrakcja danych skarbu z kontekstu strony internetowej (np. Wikipedii):
# współrzędne, nazwa, opis, kategoria, wskazówka.
#
# Ten plik jest odpowiednikiem prompt.py dla trybu eventowego i groupowego:
# DRY — prompt i schemat żyją tu, nie w analyze.py, żeby nie dublować
# między dostawcami AI (Groq/Gemini/Ollama).
#
# Kategorie skarbów są DYNAMICZNE (z bazy danych ridemore.bike, Dictionary::items('treasure_category')),
# więc prompt je dostaje jako argument. Kod maski (np. 'sacral') jest
# tym, co AI zwraca w polu "cat" — rozszerzenie mapuje go na ID przez
# dopasowanie do listy kategorii.

# Krótkie klucze JSON = mniej tokenów generowanych (wąskie gardło na
# lokalnej Ollamie, patrz duży komentarz nad SHORT_KEYS w prompt.py).
TREASURE_SHORT_KEYS = {
    'name': 'name',          # nazwa skarbu (z tytułu strony / H1)
    'description': 'desc',   # opis (z pierwszego akapitu, 2-4 zdania)
    'lat': 'lat',            # szerokość geograficzna (float, -90..90)
    'lon': 'lon',            # długość geograficzna (float, -180..180)
    'category': 'cat',       # kategoria (kod słownika ridemore, np. 'sacral')
    'hint': 'hint',          # wskazówka (1 zdanie, czego szukać na miejscu)
    'confidence': 'conf',    # low/medium/high
}

_TREASURE_EXTRA_NOTES = {
    'lat': ' — szerokość geograficzna, float, (-90, 90), NIE może być 0.0',
    'lon': ' — długość geograficzna, float, (-180, 180), NIE może być 0.0',
    'cat': ' — kod kategorii z listy poniżej (jeden z podanych kodów)',
    'hint': ' — 1 zdanie: co zobaczyć na miejscu, po polsku, bez linków',
    'confidence': ': "low"|"medium"|"high"',
}


def codes(categories: list) -> str:
    """Lista kodów kategorii do wklejenia w prompt."""
    return ', '.join(c.get('code', '') for c in (categories or []) if c.get('code'))


def build_treasure_system_prompt(categories: list) -> str:
    """Prompt systemowy dla trybu "Dodaj skarb".

    Args:
        categories: lista słowników [{id, code, name, icon}] z Dictionary::items('treasure_category')
    """
    cat_list = codes(categories)
    return (
        'Dostajesz kontekst strony internetowej o miejscu (np. artykuł Wikipedii, '
        'strona turystyczna, blog podróżniczy). Zadanie: wyekstrahować dane '
        'skarbu do zapisania na ridemore.bike.\n\n'
        'SZCZEGÓŁOWE INSTRUKCJE:\n\n'
        '1. WSPÓŁRZĘDNE (lat/lon) — NAJWAŻNIEJSZE. Szukaj w:\n'
        '   a) Infoboxie (szablon {{Współrzędne}}, {{Coord}}, {{Geo}})\n'
        '   b) Geo-microformacie (class="geo", class="geo-dms")\n'
        '   c) Meta tagach (<meta name="geo.position" content="lat;lon">)\n'
        '   d) Linku Geohack (geohack.php?...) — wyciągnij parametry SK=... lub zenit=...\n'
        '   e) Tylko jeśli NIGDZIE nie ma współrzędnych — zostaw null i ustaw '
        'confidence="low". NIE zgaduj współrzędnych na podstawie nazwy miejscowości!\n\n'
        '2. NAZWA — Weź tytuł strony (H1) lub główny nagłówek. Skróć do max 160 znaków '
        '(np. "Kościół pw. Wniebowzięcia NMP w Turzańsku" → "Kościół w Turzańsku"). '
        'Dodaj kontekst geograficzny, jeśli tytuł jest ogólny (np. "Rezerwat przyrody" '
        '→ "Rezerwat Przednioście w Bieszczadach").\n\n'
        '3. OPIS — 2-4 zdania z pierwszego akapitu (leadu). Co to za miejsce, '
        'dlaczego warto, co zobaczyć. Po polsku, neutralny styl.\n\n'
        '4. KATEGORIA — wybierz JEDNĄ z podanych kodów kategorii ridemore:\n'
        f'   {cat_list}\n'
        '   Mapowanie: kościóły/kaplice→sacral, lasy/góry/jeziora→nature, '
        'widoki/punkty widokowe→landscape, zamki/ruiny/muzea→history, '
        'rzeźby/muzea lokalne→culture, trasy/obiekty sportowe→sport, '
        'inne→other. Gdy nie jesteś pewien — "other".\n\n'
        '5. WSKAZÓWKA (hint) — 1 zdanie, czego szukać na miejscu. Po polsku, '
        'konkretne (np. "Drewniana wieża za kościołem, wejście od strony cmentarza"). '
        'Bez linków, bez nazw własnych spoza strony.\n\n'
        '6. PEWNOŚĆ (confidence):\n'
        '   - "high" — współrzędne znalezione wprost w infoboxie/geo-metadanych\n'
        '   - "medium" — współrzędne z kontekstu (np. opis + Geohack)\n'
        '   - "low" — brak współrzędnych lub niepewność co do lokalizacji\n\n'
        'FAKTY WYŁĄCZNIE z danych wejściowych — nie zgaduj, nie ekstrapoluj. '
        'Jeśli czegoś nie ma, zostaw null.\n\n'
        'Odpowiedz WYŁĄCZNIE jednym obiektem JSON z DOKŁADNIE tymi kluczami '
        '(brak informacji = null):\n'
        + _schema_line(TREASURE_SHORT_KEYS, _TREASURE_EXTRA_NOTES)
        + '.'
    )


def build_treasure_user_content(context: dict, categories: list) -> dict:
    """Buduje user content (wejście dla modelu) z kontekstu strony + kategorii.

    Args:
        context: pageContext z dom-features.js (sourceUrl, pageTitle, meta, elements, links, images)
        categories: lista [{id, code, name}] z Dictionary::items('treasure_category')
    """
    return {
        'sourceUrl': context.get('sourceUrl'),
        'pageTitle': context.get('pageTitle'),
        'meta': context.get('meta'),
        'jsonLd': context.get('jsonLd'),
        'elements': context.get('elements'),
        'links': context.get('links'),
        'images': context.get('images'),
        'categories': [{'code': c.get('code'), 'name': c.get('name')} for c in (categories or [])],
    }


def _schema_line(mapping: dict, extra: dict = None) -> str:
    """Buduje linię schematu: 'pełna_nazwa (krótki_klucz[, uwaga])'."""
    extra = extra or {}
    return ', '.join(
        f'{full} ({short}{extra.get(full, "")})' for full, short in mapping.items()
    )
