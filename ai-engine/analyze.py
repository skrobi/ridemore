# ai-engine/analyze.py
# Punkt wejścia wołany przez PHP (core/Utils/AiEngineBridge.php) przez
# proc_open — JEDEN proces na JEDNO żądanie, świadomie NIE persystentny
# serwer HTTP (w odróżnieniu od bikevents/viewer.py) — most PHP odpala go
# na żądanie, więc developer niczego ręcznie nie uruchamia (patrz README.md).
#
# Wejście: JSON na stdin, kształt zgodny z scripts/dom-features.js w
# rozszerzeniu Chrome: {sourceUrl, pageTitle, meta, jsonLd, elements, links,
# images, dictionaries}.
# Wyjście: JEDEN JSON na stdout, ZAWSZE {"ok": true, "data": {...}} albo
# {"ok": false, "error": "..."} — exit code zawsze 0, PHP rozróżnia po polu
# "ok", nie po kodzie wyjścia (patrz AiEngineBridge::analyze()).
import json
import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from providers.prompt import (  # noqa: E402
    GROUP_SHORT_KEYS,
    SHORT_KEYS,
    STAGE_SHORT_KEYS,
    VARIANT_SHORT_KEYS,
)
from providers.treasure_prompt import TREASURE_SHORT_KEYS

# KRYTYCZNE na Windows: gdy stdin/stdout/stderr są przekierowane do pipe'a
# (tak jak przez proc_open w AiEngineBridge, w odróżnieniu od prawdziwej
# konsoli), Python dobiera kodowanie z systemowej strony kodowej (np.
# cp1250), NIE UTF-8. Dla stdout/stderr to rzuca UnicodeEncodeError na
# print() z polskimi znakami — PHP dostaje pusty/urwany output. Dla STDIN
# (dopisane 2026-08-07, znalezione żywym testem na realnej stronie: wpisy
# "index" tablicy "links" nagle wychodziły w innej kolejności między
# uruchomieniami tego samego payloadu — root cause: `sys.stdin.read()` bez
# tej linii dekoduje wchodzący JSON jako cp1250, więc polskie znaki w
# tekstach linków/elementów wchodzą ZNISZCZONE, co po cichu psowało
# dopasowania regex w _prioritize_links() na tekstach z polskimi znakami —
# błąd wyglądał jak "niedeterminizm modelu", a był tu). Wymuszenie UTF-8 na
# WSZYSTKICH trzech strumieniach, zanim jakikolwiek bajt zostanie
# przeczytany/zapisany, usuwa całą klasę problemu.
sys.stdin.reconfigure(encoding='utf-8')
sys.stdout.reconfigure(encoding='utf-8')
sys.stderr.reconfigure(encoding='utf-8')

MAX_ELEMENTS = 500  # redundantna górna granica — dom-features.js już ogranicza do 200

# Znalezione żywym testem (2026-08-07, real_payload z prawdziwej, gęstej
# strony wydarzenia): dom-features.js ogranicza linki do 60, ale realna
# strona (nawigacja + FAQ + sponsorzy) i tak potrafi wysłać 50+ linków bez
# wartości dla ekstrakcji — to była GŁÓWNA przyczyna przekroczenia limitu
# tokenów/minutę Groq (6000 TPM na tym koncie; jedno żądanie do tej strony
# potrzebowało ~6900+ przy 52 linkach). Zamiast obcinać "pierwsze N" (traci
# akurat te ważne, jeśli są niżej w DOM-ie, jak "Pobierz trasę GPX" czy
# "Regulamin imprezy" na tej stronie), PRIORYTETYZUJEMY linki, które faktycznie
# mogą być tym, czego szuka schemat (gpxLinkIndex/registrationLinkIndex) albo
# treściowo istotne, a resztę (nawigacja, social media, inne produkty) ucinamy.
MAX_LINKS = 20
MAX_IMAGES = 10
_INTERESTING_LINK_RE = re.compile(
    r'\.(gpx|pdf)(\?|$)|regulamin|zapis|rejestracj|zgłosz|formularz|faq|trasa|program|'
    r'gpx|ridewithgps|komunikat|oświadczenie|start\b',
    re.IGNORECASE,
)


def _prioritize_links(links: list, max_count: int) -> list:
    if not isinstance(links, list) or len(links) <= max_count:
        return links or []

    def is_interesting(link):
        text = f"{link.get('text') or ''} {link.get('href') or ''}"
        return bool(_INTERESTING_LINK_RE.search(text))

    interesting = [l for l in links if isinstance(l, dict) and is_interesting(l)]
    interesting_ids = {id(l) for l in interesting}
    rest = [l for l in links if isinstance(l, dict) and id(l) not in interesting_ids]
    # BUG naprawiony na żywo (2026-08-07, realna strona, zgłoszone przez
    # użytkownika): TU wcześniej była re-indeksacja "link['index'] = i"
    # (0..N-1 wg pozycji w przyciętej liście). To rozjeżdżało się z
    # rozszerzeniem Chrome — sidepanel.js (`linkAt()`) rozwiązuje
    # gpxLinkIndex/registrationLinkIndex wobec SWOJEJ, ORYGINALNEJ,
    # NIEPRZYCIĘTEJ tablicy `pageContext.links` zebranej w przeglądarce
    # (dom-features.js nadaje tam "index" sekwencyjnie przy zbieraniu). Po
    # przenumerowaniu tutaj model wskazywał poprawną pozycję w SWOJEJ
    # (przyciętej) liście, ale rozszerzenie pod tym samym numerem
    # odczytywało zupełnie inny link z oryginalnej listy — user widział
    # "poprawnie znaleziony" plik GPX na stronie, ale rozszerzenie wpisywało
    # URL innego linku. NIE przenumerowujemy — każdy link zachowuje swój
    # ORYGINALNY "index" (dom-features.js i tak nadaje je 0..N-1 sekwencyjnie
    # przy zbieraniu, więc te wartości są już unikalne i poprawne, tylko
    # niekoniecznie ciągłe po przycięciu — patrz przepisane _valid_index()/
    # _valid_gpx_index() niżej, które szukają po WARTOŚCI "index", nie po
    # pozycji w (przyciętej) tablicy).
    return (interesting + rest)[:max_count]


def _load_dotenv() -> None:
    # Bez python-dotenv (jedna zależność mniej, patrz requirements.txt) — ta
    # sama zasada co core/config.php: docelowo sekret z env systemowego, plik
    # .env tylko jako lokalny fallback dewelopera. Format: KLUCZ=wartość,
    # # = komentarz, puste linie pomijane. Nie nadpisuje już ustawionego env.
    path = os.path.join(os.path.dirname(os.path.abspath(__file__)), '.env')
    if not os.path.isfile(path):
        return
    with open(path, 'r', encoding='utf-8') as f:
        for line in f:
            line = line.strip()
            if not line or line.startswith('#') or '=' not in line:
                continue
            key, _, value = line.partition('=')
            key = key.strip()
            if key and key not in os.environ:
                os.environ[key] = value.strip()


# response_format={"type":"json_object"} gwarantuje tylko POPRAWNĄ SKŁADNIĘ
# JSON — NIC nie gwarantuje co do typów pól (w odróżnieniu od dawnego "strict
# tool use" po stronie Claude, wersja usunięta). Groq realnie potrafi zwrócić
# np. region jako ["tatry"] zamiast "tatry", więc KAŻDE pole trzeba sprawdzić
# typem, zanim trafi w `x in set(...)` — inaczej `list` jako klucz zestawu
# wywala "unhashable type: 'list'" (dokładnie ten błąd, złapany na żywo).
def _as_str(value):
    return value if isinstance(value, str) and value.strip() != '' else None


# Data w kształcie, który przyjmie <input type="date"> w kreatorze (YYYY-MM-DD).
# Model bywa proszony o samą datę, ale gdy odda pełny ISO ("2026-09-20T09:00")
# — a tak wygląda meta event:start_time / atrybut datetime, patrz dom-features.js
# i prompt.py — pole daty odrzuciłoby to po cichu. Ucinamy do części dziennej;
# wartość, która nie zaczyna się od daty ISO, zwracamy bez zmian (walidacja
# kreatora i tak ją odrzuci, ale nie gubimy informacji).
def _as_date(value):
    s = _as_str(value)
    if s is None:
        return None
    m = re.match(r'^\s*(\d{4}-\d{2}-\d{2})', s)
    return m.group(1) if m else s


def _as_str_list(value):
    if not isinstance(value, list):
        return []
    return [v for v in value if isinstance(v, str) and v.strip() != '']


def _as_number(value):
    return value if isinstance(value, (int, float)) and not isinstance(value, bool) else None


def _as_bool(value):
    return value if isinstance(value, bool) else None


def _clamp_enum(value, allowed_codes: set):
    return value if isinstance(value, str) and value in allowed_codes else None


# Szuka po WARTOŚCI pola "index" wpisu, NIE po jego pozycji w (być może
# przyciętej/przesortowanej przez _prioritize_links) tablicy `arr` —
# KRYTYCZNE, bo rozszerzenie Chrome (sidepanel.js::linkAt/imgAt) rozwiązuje
# gpxLinkIndex/registrationLinkIndex/photoImageIndex wobec SWOJEJ, ORYGINALNEJ
# tablicy zebranej w przeglądarce, nie wobec tej (ewentualnie przyciętej),
# którą widział model. `dom-features.js` nadaje "index" sekwencyjnie 0..N-1
# przy zbieraniu — te wartości są jedynym stabilnym punktem odniesienia
# między Pythonem i rozszerzeniem, pozycja w tablicy `arr` po stronie
# Pythona już nie jest (patrz błąd znaleziony żywo 2026-08-07, opisany przy
# _prioritize_links()).
def _valid_index(idx, arr: list):
    if not isinstance(idx, int) or isinstance(idx, bool):
        return None
    return idx if any(isinstance(item, dict) and item.get('index') == idx for item in (arr or [])) else None


def _find_by_index(idx, arr: list):
    for item in (arr or []):
        if isinstance(item, dict) and item.get('index') == idx:
            return item
    return None


# Siatka bezpieczeństwa specyficzna dla gpxLinkIndex — znaleziona żywym testem
# (2026-08-07, Gemini na realnej stronie): model wskazał indeks W ZAKRESIE,
# ale sąsiedniego, NIE-gpx linku ("Oświadczenie uczestnika" zamiast "Pobierz
# trasę GPX" tuż obok) — _valid_index() tego nie złapie, bo indeks jest
# technicznie poprawny. To jedyny z trzech *Index, dla którego mamy DETERMI-
# NISTYCZNY sposób zweryfikowania "czy to na pewno TO" (rozszerzenie .gpx w
# URL) — regIdx/imgIdx nie mają takiego twardego sygnału, zostają przy samym
# _valid_index(). UWAGA (dopisane po dodaniu gpx PER WARIANT): auto-korekta
# na "pierwszy znaleziony link .gpx" jest bezpieczna TYLKO gdy na stronie
# jest jeden jednoznaczny kandydat (typowy event bez wariantów) — przy
# KILKU plikach GPX (częste przy wariantach: każdy może mieć własny) zgadnięcie
# "pierwszego z brzegu" przypisałoby PLIK JEDNEGO WARIANTU do INNEGO —
# gorsza pomyłka niż zostawienie null. Dlatego auto-korekta tylko przy
# dokładnie jednym kandydacie; przy wielu ufamy WYŁĄCZNIE trafieniu modelu
# (a jeśli się myli, zostaje null, user dogra ręcznie).
def _valid_gpx_index(idx, links: list):
    gpx_candidates = [
        l.get('index') for l in (links or [])
        if isinstance(l, dict) and '.gpx' in (l.get('href') or '').lower()
    ]
    valid = _valid_index(idx, links)
    if valid is not None and valid in gpx_candidates:
        return valid
    if len(gpx_candidates) == 1:
        return gpx_candidates[0]
    return None


# Prompt (providers/prompt.py) instruuje model, żeby pisał KRÓTKIE klucze
# (mniej tokenów do wygenerowania, patrz komentarz w SHORT_KEYS) — ale model
# czasem i tak wraca do pełnej nazwy (instrukcja nie jest gwarancją, tak jak
# nic innego w tym pliku), więc czytamy najpierw krótki klucz, z fallbackiem
# na pełny. Zero ryzyka: to samo pole niezależnie od tego, którą formę model
# wybrał, zawsze trafia do _validate() pod pełną, kanoniczną nazwą.
def _get(d: dict, full_key: str, short_keys: dict = SHORT_KEYS):
    short_key = short_keys.get(full_key, full_key)
    return d.get(short_key, d.get(full_key))


def _get_group(d: dict, full_key: str):
    # Odpowiednik _get() dla trybu "grupa FB" (mode='group') — mapowanie
    # krótkich kluczy z GROUP_SHORT_KEYS (prompt.py), z fallbackiem na pełną
    # nazwę, dokładnie jak w _get() dla trybu strony.
    return _get(d, full_key, GROUP_SHORT_KEYS)


def _get_treasure(d: dict, full_key: str):
    # Odpowiednik _get() i _get_group() dla trybu "dodaj skarb" (mode='treasure') —
    # mapowanie krótkich kluczy z TREASURE_SHORT_KEYS (treasure_prompt.py).
    return _get(d, full_key, TREASURE_SHORT_KEYS)


# Jeden wpis "stages" = jeden dzień wycieczki wielodniowej (patrz addStage()
# w views/web/partials/wizard/script.php — tu tylko podzbiór pól, które AI
# faktycznie może wyczytać ze strony; resztę (nocleg/posiłki) user dogrywa
# ręcznie w kreatorze). gpxLinkIndex per dzień dopisane 2026-08-07, tą samą
# zasadą co gpxLinkIndex per wariant (event-form.php: Models\EventStage
# wspiera WŁASNY plik GPX per dzień) — `links` przekazywane wyłącznie pod
# _valid_gpx_index() (patrz jej komentarz o wielu kandydatach .gpx: przy
# kilku dniach z własnymi plikami auto-korekta jest wyłączona, żeby nie
# podłożyć dniowi pliku NALEŻĄCEGO DO INNEGO DNIA).
def _as_stage(raw, links: list) -> dict | None:
    if not isinstance(raw, dict):
        return None
    stage = {
        'dateIso': _as_date(_get(raw, 'dateIso', STAGE_SHORT_KEYS)),
        'title': _as_str(_get(raw, 'title', STAGE_SHORT_KEYS)),
        'startPoint': _as_str(_get(raw, 'startPoint', STAGE_SHORT_KEYS)),
        'endPoint': _as_str(_get(raw, 'endPoint', STAGE_SHORT_KEYS)),
        'distanceKm': _as_number(_get(raw, 'distanceKm', STAGE_SHORT_KEYS)),
        'elevationM': _as_number(_get(raw, 'elevationM', STAGE_SHORT_KEYS)),
        'gpxLinkIndex': _valid_gpx_index(_get(raw, 'gpxLinkIndex', STAGE_SHORT_KEYS), links),
    }
    # Dzień bez ŻADNEGO pola to szum (np. model zwrócił {} albo dict z samymi
    # złymi typami) — bez tego fill.js dołożyłby pusty dzień do planu.
    return stage if any(v is not None for v in stage.values()) else None


def _as_stage_list(value, links: list) -> list:
    if not isinstance(value, list):
        return []
    return [s for s in (_as_stage(item, links) for item in value) if s is not None]


# Wariant BEZ nazwy jest bezużyteczny (kreator i tak pomija warianty bez
# nazwy przy zapisie, patrz validateBeforeSubmit() w script.php) — odrzucamy
# go tu, żeby panel rozszerzenia nie pokazał pustego wiersza.
#
# gpxLinkIndex per wariant (dopisane 2026-08-07, na żądanie usera po
# przejrzeniu views/web/pages/event-form.php: KAŻDY wariant trasy ma tam
# WŁASNY upload GPX -> własne dystans/przewyższenie/nawierzchnia z pliku,
# patrz Models\EventRouteVariant — kreator wspierał to od migracji 031,
# ale rozszerzenie Chrome nigdy tego nie wykorzystywało, GPX per wariant
# trzeba było dograć ręcznie). `links` przekazywane tu wyłącznie pod
# _valid_gpx_index() — ta sama walidacja/siatka bezpieczeństwa co dla
# głównego pola gpxLinkIndex, ale UWAGA na komentarz nad _valid_gpx_index():
# przy wielu plikach .gpx (typowe dla wariantów) auto-korekta jest wyłączona,
# żeby nie podłożyć wariantowi pliku NALEŻĄCEGO DO INNEGO WARIANTU.
def _as_variant(raw, links: list) -> dict | None:
    if not isinstance(raw, dict):
        return None
    name = _as_str(_get(raw, 'name', VARIANT_SHORT_KEYS))
    if not name:
        return None
    return {
        'name': name,
        'distanceKm': _as_number(_get(raw, 'distanceKm', VARIANT_SHORT_KEYS)),
        'elevationM': _as_number(_get(raw, 'elevationM', VARIANT_SHORT_KEYS)),
        'gpxLinkIndex': _valid_gpx_index(_get(raw, 'gpxLinkIndex', VARIANT_SHORT_KEYS), links),
    }


def _as_variant_list(value, links: list) -> list:
    if not isinstance(value, list):
        return []
    return [v for v in (_as_variant(item, links) for item in value) if v is not None]


def _validate(result, dictionaries: dict, links: list, images: list) -> dict:
    # Ten sam efekt bezpieczeństwa co dawne "strict tool use": kod spoza
    # słownika -> null, indeks poza zakresem -> null, zły TYP -> null/[] —
    # model może się pomylić, walidacja i tak nie wpuści śmiecia do formularza.
    def codes(key):
        return {i.get('code') for i in (dictionaries.get(key) or []) if isinstance(i, dict) and isinstance(i.get('code'), str)}

    result = result if isinstance(result, dict) else {}
    allowed_bikes = codes('bikeTypes')
    g = lambda key: _get(result, key)  # noqa: E731 — lokalny alias, tylko w tej funkcji

    return {
        'title': _as_str(g('title')),
        'description': _as_str(g('description')),
        'organizer': _as_str(g('organizer')),
        'organizerEmail': _as_str(g('organizerEmail')),
        'eventType': g('eventType') if g('eventType') in ('ustawka', 'wycieczka_wielodniowa', 'pokrec_z_kims', 'wyscig') else None,
        'stages': _as_stage_list(g('stages'), links),
        'variants': _as_variant_list(g('variants'), links),
        'dateIso': _as_date(g('dateIso')),
        'time': _as_str(g('time')),
        'region': _clamp_enum(g('region'), codes('regions')),
        'meetingPointLabel': _as_str(g('meetingPointLabel')),
        'distanceKm': _as_number(g('distanceKm')),
        'elevationM': _as_number(g('elevationM')),
        'surface': _clamp_enum(g('surface'), codes('surfaces')),
        'bikeTypes': [c for c in _as_str_list(g('bikeTypes')) if c in allowed_bikes],
        'pace': _clamp_enum(g('pace'), codes('paces')),
        'difficulty': _clamp_enum(g('difficulty'), codes('difficulties')),
        'whatToBring': _as_str_list(g('whatToBring')),
        'isPaid': _as_bool(g('isPaid')),
        'priceAmount': _as_number(g('priceAmount')),
        'priceCurrency': _clamp_enum(g('priceCurrency'), codes('currencies')),
        'priceIncluded': _as_str_list(g('priceIncluded')),
        'priceExcluded': _as_str_list(g('priceExcluded')),
        'registrationPhone': _as_str(g('registrationPhone')),
        'registrationEmail': _as_str(g('registrationEmail')),
        'gpxLinkIndex': _valid_gpx_index(g('gpxLinkIndex'), links),
        'registrationLinkIndex': _valid_index(g('registrationLinkIndex'), links),
        'photoImageIndex': _valid_index(g('photoImageIndex'), images),
        'confidence': g('confidence') if g('confidence') in ('low', 'medium', 'high') else 'low',
        # Dopisane 2026-08-07 po pełnym audycie pól kreatora (nazwy 1:1 z
        # core/Resources/EventFormResource.php) — patrz komentarz nad SHORT_KEYS.
        'endDate': _as_date(g('endDate')),
        'dateIsFlexible': _as_bool(g('dateIsFlexible')),
        'additionalDates': _as_str_list(g('additionalDates')),
        'minParticipants': _as_number(g('minParticipants')),
        'maxParticipants': _as_number(g('maxParticipants')),
        'priceUnit': _clamp_enum(g('priceUnit'), codes('priceUnits')),
        'paymentDeadlineDays': _as_number(g('paymentDeadlineDays')),
        'cancellationDeadlineDays': _as_number(g('cancellationDeadlineDays')),
        'cancellationPolicy': _as_str(g('cancellationPolicy')),
    }


# --- Tryb "grupa FB" (mode='group' w payloadzie) ---
# Posty zebrane przez rozszerzenie (scripts/group-feed.js) -> model ocenia,
# który z nich to "szukam towarzystwa na przejazd rowerowy", uzupełnia pola
# wydarzenia i redaguje wiadomość do autora. Ta sama filozofia co _validate():
# kod spoza słownika -> null, zmyślony indeks -> pominięty wpis, zły typ ->
# null/[] — model może się pomylić, walidacja i tak nie wpuści śmiecia do
# formularza. WYJŚCIE: {"posts": [wpisy TYLKO pasujące, każdy z prawdziwym
# postIndex], "confidence": ogólna pewność} — panel rozszerzenia skleja
# wyniki z powrotem z zebranymi postami PO TEJ stronie, po postIndex.
def _validate_group(result, dictionaries: dict, posts: list) -> dict:
    def codes(key):
        return {i.get('code') for i in (dictionaries.get(key) or []) if isinstance(i, dict) and isinstance(i.get('code'), str)}

    result = result if isinstance(result, dict) else {}
    allowed_bikes = codes('bikeTypes')
    raw_items = result.get('posts') if isinstance(result.get('posts'), list) else []

    matched = []
    for raw in raw_items:
        if not isinstance(raw, dict):
            continue
        g = lambda key: _get_group(raw, key)  # noqa: E731 — lokalny alias, jak w _validate()
        # postIndex to jedyny stabilny punkt odniesienia między modelem a
        # postami zebranymi w przeglądarce (ten sam wzorzec co indeksy linków
        # w trybie strony) — zmyślony/niedopasowany indeks = nie wiemy, o który
        # post chodzi = wpis bezużyteczny, pomijamy.
        idx = _valid_index(g('postIndex'), posts)
        if idx is None:
            continue
        # Prompt każe modelowi umieszczać WYŁĄCZNIE pasujące posty, ale nie
        # ufamy mu: matched !== true = pomiń (model potrafi dodać wszystko).
        if _as_bool(g('matched')) is not True:
            continue
        matched.append({
            'postIndex': idx,
            'matched': True,
            'title': _as_str(g('title')),
            'description': _as_str(g('description')),
            'region': _clamp_enum(g('region'), codes('regions')),
            'dateIso': _as_str(g('dateIso')),
            'time': _as_str(g('time')),
            'meetingPointLabel': _as_str(g('meetingPointLabel')),
            'distanceKm': _as_number(g('distanceKm')),
            'pace': _clamp_enum(g('pace'), codes('paces')),
            'difficulty': _clamp_enum(g('difficulty'), codes('difficulties')),
            'bikeTypes': [c for c in _as_str_list(g('bikeTypes')) if c in allowed_bikes],
            # messageRide — krótki opis przejazdu wstawiany przez rozszerzenie
            # do STAŁEGO szablonu listu (sidepanel.js::buildAuthorMessage);
            # model nie pisze całego listu, tylko to streszczenie.
            'messageRide': _as_str(g('messageRide')),
            'confidence': g('confidence') if g('confidence') in ('low', 'medium', 'high') else 'low',
        })

    # Ogólna pewność skanu = najlepsza z pewności wpisów — trafia do
    # AiImportLog::record (dziennik ekstrakcji), nie do formularza.
    conf = (
        'high' if any(p['confidence'] == 'high' for p in matched)
        else ('medium' if any(p['confidence'] == 'medium' for p in matched) else 'low')
    )
    return {'posts': matched, 'confidence': conf}


# --- Tryb "dodaj skarb" (mode='treasure' w payloadzie) ---
# Ekstrakcja danych skarbu z kontekstu strony internetowej (np. Wikipedii):
# współrzędne geograficzne, nazwa, opis, kategoria ridemore, wskazówka.
# Współrzędne SĄ WYMAGANE — strona bez nich nie nadaje się na skarb.
# Walidacja podobna do _validate(): kod spoza słownika -> null, zły typ ->
# null, lat/lon = 0.0 lub poza zakresem -> null. WYJŚCIE: pojedynczy obiekt
# (nie tablica jak w group), panel rozszerzenia renderuje jedną kartę.
def _validate_treasure(result, dictionaries: dict) -> dict:
    def codes(key):
        return {i.get('code') for i in (dictionaries.get(key) or []) if isinstance(i, dict) and isinstance(i.get('code'), str)}

    result = result if isinstance(result, dict) else {}
    g = lambda key: _get_treasure(result, key)  # noqa: E731 — lokalny alias

    # lat/lon — błędne = nie da się zapisać skarbu (wymagane pola w formularzu)
    lat = _as_number(g('lat'))
    lon = _as_number(g('lon'))
    if lat is None or lat == 0.0 or lat < -90 or lat > 90:
        lat = None
    if lon is None or lon == 0.0 or lon < -180 or lon > 180:
        lon = None

    # Nazwa — wymagana (formularz odrzuca pustą), max 160 znaków
    name = _as_str(g('name'))
    if name and len(name) > 160:
        name = name[:160]

    # Opis — opcjonalny, max 600 znaków
    description = _as_str(g('description'))
    if description and len(description) > 600:
        description = description[:600]

    # Kategoria — kod musi istnieć w słowniku treasureCategories
    category = _clamp_enum(g('category'), codes('treasureCategories'))

    # Wskazówka — opcjonalna, max 300 znaków
    hint = _as_str(g('hint'))
    if hint and len(hint) > 300:
        hint = hint[:300]

    return {
        'name': name,
        'description': description,
        'lat': lat,
        'lon': lon,
        'category': category,
        'hint': hint,
        'confidence': g('confidence') if g('confidence') in ('low', 'medium', 'high') else 'low',
    }


def main() -> int:
    _load_dotenv()
    raw = sys.stdin.read()
    try:
        payload = json.loads(raw) if raw else {}
    except json.JSONDecodeError:
        print(json.dumps({'ok': False, 'error': 'Niepoprawny JSON wejściowy.'}))
        return 0

    elements = (payload.get('elements') or [])[:MAX_ELEMENTS]
    links = _prioritize_links(payload.get('links') or [], MAX_LINKS)
    images = (payload.get('images') or [])[:MAX_IMAGES]
    # links/images TRZEBA nadpisać w context tymi samymi (przyciętymi,
    # przeindeksowanymi) tablicami, którymi walidujemy niżej — inaczej model
    # widziałby jeden zestaw indeksów, a _valid_index() sprawdzałby wobec
    # innego (dłuższego) zestawu, co dawałoby błędne dopasowania linków.
    context = {**payload, 'elements': elements, 'links': links, 'images': images}
    dictionaries = payload.get('dictionaries') or {}

    provider_name = os.environ.get('AI_PROVIDER', 'groq').lower()
    try:
        if provider_name == 'groq':
            from providers.groq_provider import GroqProvider
            provider = GroqProvider()
        elif provider_name == 'gemini':
            # Google AI Studio — dodane 2026-08-07, bo darmowy tier Groq (6000
            # TPM) okazał się za wąski nawet po przycięciu payloadu na realnej
            # stronie; Gemini 2.5 Flash ma 1M/65k tokenów wejścia/wyjścia na
            # tym koncie. Patrz providers/gemini_provider.py, GEMINI_API_KEY
            # w .env.
            from providers.gemini_provider import GeminiProvider
            provider = GeminiProvider()
        elif provider_name == 'ollama':
            # Lokalnie, bez limitu tokenów/minutę i bez klucza API — patrz
            # providers/ollama_provider.py i ai-engine/README.md (wybór modelu
            # przez `ollama list` + OLLAMA_MODEL w .env).
            from providers.ollama_provider import OllamaProvider
            provider = OllamaProvider()
        else:
            raise RuntimeError(f'Nieznany dostawca AI_PROVIDER={provider_name!r} (obsługiwane: groq, gemini, ollama).')

        is_group_mode = (payload.get('mode') or '').lower() == 'group'
        is_treasure_mode = (payload.get('mode') or '').lower() == 'treasure'
        # Tryb "grupa FB" (mode='group') ma własny prompt i walidację —
        # analyze_group() w providerze, _validate_group() niżej.
        # Tryb "dodaj skarb" (mode='treasure') — analogicznie: analyze_treasure(),
        # _validate_treasure().
        if is_treasure_mode:
            raw_result = provider.analyze_treasure(context, dictionaries)
        elif is_group_mode:
            raw_result = provider.analyze_group(context, dictionaries)
        else:
            raw_result = provider.extract(context, dictionaries)
        # AI_ENGINE_DEBUG=1 w env — surowa (przed _validate()) odpowiedź modelu na
        # stderr, więc PHP/stdout-kontrakt zostaje nietknięty. Dodane 2026-08-07,
        # bo bez tego debugowanie "czy to model się myli, czy walidacja" wymagało
        # ręcznego odtwarzania całego wywołania za każdym razem.
        if os.environ.get('AI_ENGINE_DEBUG'):
            print('DEBUG raw_result:', json.dumps(raw_result, ensure_ascii=False), file=sys.stderr)
        if is_group_mode:
            # Tryb "grupa FB" — posty są zbierane w rozszerzeniu, więc tu
            # walidujemy wpisy WYŁĄCZNIE wobec przekazanych postów (postIndex).
            result = _validate_group(raw_result, dictionaries, payload.get('posts') or [])
        elif is_treasure_mode:
            # Tryb "dodaj skarb" — ekstrakcja z kontekstu strony.
            result = _validate_treasure(raw_result, dictionaries)
        else:
            result = _validate(raw_result, dictionaries, links, images)
        print(json.dumps({'ok': True, 'data': result}, ensure_ascii=False))
    except Exception as e:  # noqa — punkt wejścia CLI: każdy błąd wraca jako JSON, nigdy traceback na stdout
        print(json.dumps({'ok': False, 'error': str(e)}, ensure_ascii=False))
    return 0


if __name__ == '__main__':
    sys.exit(main())
