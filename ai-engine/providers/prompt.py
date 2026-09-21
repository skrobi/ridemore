# providers/prompt.py
# Prompt + serializacja elementów WSPÓLNE dla wszystkich dostawców (Groq,
# Ollama, przyszli) — DRY: AI_PROVIDER w analyze.py przełącza tylko SPOSÓB
# wywołania API, nie treść zapytania. Trzymane osobno od groq_provider.py,
# odkąd doszedł drugi dostawca (Ollama) — duplikacja promptu w dwóch
# plikach groziłaby rozjazdem (np. schemat zmieniony w jednym, nie w drugim).
from datetime import date

# Krótkie klucze JSON = mniej tokenów GENEROWANYCH przez model, a to jest
# wąskie gardło na lokalnym Ollama (dekodowanie ~3-4 tok/s na słabszym
# GPU/CPU vs ~4-8ms input eval na token) — patrz pomiar w sesji 2026-08-07:
# ~97s z ~102s całości szło na generowanie odpowiedzi, nie na przetworzenie
# promptu. Krócej pisany JSON = krótszy czas, bez żadnej zmiany jakości
# ekstrakcji. CAŁKOWICIE niewidoczne poza tym plikiem + analyze.py::_validate
# — PHP/JS (fill.js, sidepanel.js) dostają jak dawniej pełne nazwy kluczy,
# bo _validate() mapuje z powrotem. Jedna prawda o mapowaniu — tu.
#
# UWAGA — WYPRÓBOWANE I WYCOFANE (sesja 2026-08-07, syntetyczny payload):
# instrukcja "pomijaj klucz, jeśli nie masz wartości" (zamiast "wpisz null")
# dawała ~45% szybciej na qwen3:8b, ALE powtarzalnie (4/4 testów) wywoływała
# halucynacje, których wcześniej NIE było — np. "organizer": "example.com"
# zgadywane z samej domeny sourceUrl, mimo wyraźnego zakazu w prompcie.
# Wniosek: dla słabszego modelu decyzja "czy w ogóle dodać klucz" jest
# RYZYKOWNIEJSZA niż decyzja "co wpisać w istniejący klucz" — więcej stopni
# swobody = więcej okazji do zgadywania. Krótkie nazwy kluczy zostają — to
# samo przemianowanie, nie zmiana decyzji, więc nie miało tego efektu.
#
# DALSZY WNIOSEK — WYPRÓBOWANY NA REALNEJ STRONIE (2026-08-07, ta sama
# zasada w większej skali): na DŁUGIM, gęstym wejściu (realna strona: 62
# elementy + 52 linki, nie garstka jak w syntetycznym teście) qwen3:8b
# olewał CAŁY schemat (28 wymaganych kluczy na raz) i zwracał WŁASNY,
# wymyślony obiekt. Ta sama zasada co wyżej, tylko po stronie WEJŚCIA i
# LICZBY kluczy naraz, nie tylko po stronie "null vs pomiń": im więcej
# jednocześnie model musi utrzymać w głowie (długi kontekst + długi
# schemat), tym łatwiej mu się pogubić. Naprawa: `OllamaProvider` NIE woła
# już jednego wielkiego zapytania o wszystko — dzieli je na kilka mniejszych,
# prostszych (patrz build_core_system_prompt/build_stages_variants_prompt/
# build_description_prompt niżej + ollama_provider.py). Groq nigdy nie
# wykazał tego problemu w testach, więc build_system_prompt() (pełny,
# jednorazowy) ZOSTAJE jego ścieżką — nie psuj działającego.
SHORT_KEYS = {
    'title': 't', 'description': 'desc', 'organizer': 'org', 'eventType': 'etype',
    'dateIso': 'date', 'time': 'time', 'region': 'reg', 'meetingPointLabel': 'mpl',
    'distanceKm': 'dist', 'elevationM': 'elev', 'surface': 'surf', 'bikeTypes': 'bikes',
    'pace': 'pace', 'difficulty': 'diff', 'whatToBring': 'bring', 'isPaid': 'paid',
    'priceAmount': 'price', 'priceCurrency': 'cur', 'priceIncluded': 'pinc',
    'priceExcluded': 'pexc', 'registrationPhone': 'rphone', 'registrationEmail': 'remail',
    'gpxLinkIndex': 'gpxIdx', 'photoImageIndex': 'imgIdx', 'registrationLinkIndex': 'regIdx',
    'stages': 'stages', 'variants': 'variants', 'confidence': 'conf',
    # Dopisane 2026-08-07 po pełnym audycie `views/web/partials/wizard/*.php`
    # (KREATOR — nie event-form.php, który jest formularzem EDYCJI; nazwy pól
    # zweryfikowane wprost w core/Resources/EventFormResource.php, nie zgadywane):
    'endDate': 'edate', 'dateIsFlexible': 'flex', 'additionalDates': 'moredates',
    'minParticipants': 'minp', 'maxParticipants': 'maxp', 'priceUnit': 'punit',
    'paymentDeadlineDays': 'paydl', 'cancellationDeadlineDays': 'canceldl',
    'cancellationPolicy': 'cancelpol',
    # Dopisane 2026-08-07 — user: organizator bez dopasowania w bazie
    # (organizerMode='new' w script.php) wymaga MAILA do późniejszego
    # przejęcia profilu (validateBeforeSubmit() w script.php BLOKUJE zapis
    # bez tego, niezależnie od zalogowania) — dziś user musi go wpisać
    # ręcznie, mimo że często jest na stronie źródłowej (sekcja "Kontakt").
    'organizerEmail': 'oemail',
}
# Podzbiór SHORT_KEYS bez "desc"/"stages"/"variants" — te trzy dostają
# WŁASNE, mniejsze zapytania w OllamaProvider (patrz wyżej). Kolejność
# zachowana z SHORT_KEYS (dict w Pythonie 3.7+ pamięta wstawianie).
CORE_KEYS = {k: v for k, v in SHORT_KEYS.items() if v not in ('desc', 'stages', 'variants')}
# gpxLinkIndex dopisane 2026-08-07 — event-form.php wspiera WŁASNY plik GPX
# per DZIEŃ wielodniówki (Models\EventStage), tak samo jak per wariant niżej
# (user: "a co z wielodniówkami gdzie każdy dzień ma gpx?").
STAGE_SHORT_KEYS = {
    'dateIso': 'date', 'title': 't', 'startPoint': 'sp', 'endPoint': 'ep',
    'distanceKm': 'dist', 'elevationM': 'elev', 'gpxLinkIndex': 'gpxIdx',
}
# gpxLinkIndex dopisane 2026-08-07 — event-form.php wspiera WŁASNY plik GPX
# per wariant (Models\EventRouteVariant), nie tylko dla głównego eventu.
VARIANT_SHORT_KEYS = {'name': 'name', 'distanceKm': 'dist', 'elevationM': 'elev', 'gpxLinkIndex': 'gpxIdx'}

_EXTRA_NOTES = {
    'eventType': ': "ustawka"|"wycieczka_wielodniowa"|"pokrec_z_kims"|"wyscig"',
    'bikeTypes': ' — tablica kodów', 'whatToBring': ' — tablica',
    'isPaid': ' — bool', 'priceIncluded': ' — tablica', 'priceExcluded': ' — tablica',
    'stages': ' — tablica obiektów, patrz niżej', 'variants': ' — tablica obiektów, patrz niżej',
    'confidence': ': "low"|"medium"|"high"',
    'dateIsFlexible': ' — bool', 'additionalDates': ' — tablica dat YYYY-MM-DD',
    'minParticipants': ' — liczba', 'maxParticipants': ' — liczba',
    'paymentDeadlineDays': ' — liczba dni', 'cancellationDeadlineDays': ' — liczba dni',
}

_STR_OR_NULL = {'type': ['string', 'null']}
_NUM_OR_NULL = {'type': ['number', 'null']}
_INT_OR_NULL = {'type': ['integer', 'null']}
_STR_ARRAY = {'type': 'array', 'items': {'type': 'string'}}

_STAGE_SCHEMA = {
    'type': 'object',
    'properties': {
        'date': _STR_OR_NULL, 't': _STR_OR_NULL, 'sp': _STR_OR_NULL, 'ep': _STR_OR_NULL,
        'dist': _NUM_OR_NULL, 'elev': _NUM_OR_NULL,
    },
}
_VARIANT_SCHEMA = {
    'type': 'object',
    'properties': {'name': _STR_OR_NULL, 'dist': _NUM_OR_NULL, 'elev': _NUM_OR_NULL},
}

# WYPRÓBOWANE NA ŻYWEJ, REALNEJ STRONIE (2026-08-07) I OSTATECZNIE
# NIEUŻYWANE przez ollama_provider.py — zostawione tu jako gotowy budulec,
# nie martwy kod, na wypadek nowszej Ollamy/innego modelu. Historia: podanie
# tego schematu jako `format` (obiekt, nie string "json") faktycznie
# wymusiło poprawne klucze przez constrained decoding — ALE jednocześnie
# popsuło coś gorszego: treść pól stała się bezsensowna, a polskie znaki
# wyszły zakodowane krzywo (mojibake). Zob. decyzję w ollama_provider.py przy
# `'format'`. Nie włączać ponownie bez żywego testu na realnej stronie.
def build_json_schema() -> dict:
    props = {}
    for full, short in SHORT_KEYS.items():
        if short in ('bikes', 'bring', 'pinc', 'pexc'):
            props[short] = _STR_ARRAY
        elif short in ('dist', 'elev', 'price'):
            props[short] = _NUM_OR_NULL
        elif short in ('gpxIdx', 'imgIdx', 'regIdx'):
            props[short] = _INT_OR_NULL
        elif short == 'paid':
            props[short] = {'type': ['boolean', 'null']}
        elif short == 'stages':
            props[short] = {'type': 'array', 'items': _STAGE_SCHEMA}
        elif short == 'variants':
            props[short] = {'type': 'array', 'items': _VARIANT_SCHEMA}
        elif short == 'conf':
            props[short] = {'type': 'string', 'enum': ['low', 'medium', 'high']}
        else:
            props[short] = _STR_OR_NULL
    return {
        'type': 'object',
        'properties': props,
        'required': list(props.keys()),
        'additionalProperties': False,
    }


def codes(items):
    return [i.get('code') for i in (items or [])]


def _schema_line(mapping: dict, extra: dict = None) -> str:
    # "pełna_nazwa (krótki_klucz[, uwaga])" — model widzi PO CO skrót
    # istnieje (pełny sens klucza), ale ma pisać tylko krótki.
    extra = extra or {}
    return ', '.join(
        f'{full} ({short}{extra.get(full, "")})' for full, short in mapping.items()
    )


# --- Akapity WSPÓLNE między build_system_prompt() (Groq, pełny, jednorazowy)
# i build_core_system_prompt()/build_stages_variants_prompt() (Ollama,
# podzielone) — DRY, żeby anti-halucynacyjne sformułowania nie rozjechały
# się między ścieżkami. Patrz duży komentarz nad SHORT_KEYS po "co i dlaczego".

def _intro_para(today: str) -> str:
    return (
        'Wyodrębniasz i SKŁADASZ dane o wydarzeniu rowerowym z modelu elementów strony '
        'internetowej (tekst + pozycja + styl + rodzic — NIE z gotowego HTML-a), żeby wstępnie '
        f'wypełnić formularz na ridemore.bike. Dzisiejsza data: {today} — użyj jej, żeby przeliczyć '
        'względne określenia daty ("najbliższa sobota", "za dwa tygodnie") na format YYYY-MM-DD.\n\n'
        'DATĘ (date) bierz NAJPIERW ze źródeł maszynowych, dopiero potem z widocznego tekstu: '
        'meta "event:start_time"/"article:published_time", pole "startDate" w JSON-LD, albo znacznik '
        '"@YYYY-MM-DDThh:mm..." doklejony do tekstu elementu (pochodzi z atrybutu datetime). Na wielu '
        'stronach (zwłaszcza budowanych dynamicznie) widoczna data bywa porozbijana na osobne kawałki '
        '("20", "wrz", "2026") i nie da się jej złożyć z tekstu — wtedy te źródła są jedyną pewną datą. '
        'Zawsze zwróć YYYY-MM-DD (samą datę, bez godziny — godzina idzie do "time").\n\n'
        'FAKTY bierzesz WYŁĄCZNIE z danych wejściowych (meta, JSON-LD, elementy, linki, obrazy) — '
        'nie zgaduj, nie wymyślaj, nie ekstrapoluj z ogólnej wiedzy o kolarstwie. "sourceUrl" to '
        'TYLKO identyfikator strony, NIE dowód na nazwę organizatora — samej domeny/adresu URL nie '
        'traktuj jako faktu o organizatorze, chyba że nazwa organizatora faktycznie WYSTĘPUJE w '
        'tekście strony. Gdy faktu nie ma albo nie masz pewności, zostaw null (albo pustą tablicę '
        'dla list) — NIE zgaduj tylko po to, żeby klucz miał jakąkolwiek wartość.\n\n'
    )


def _dictionaries_para(dictionaries: dict) -> str:
    return (
        'Pola reg/surf/pace/diff/bikes/cur/punit MUSZĄ być jednym z kodów z list niżej albo null — '
        'kod spoza listy i tak zostanie odrzucony przy walidacji:\n'
        f'region (reg): {codes(dictionaries.get("regions"))}\n'
        f'nawierzchnia (surf): {codes(dictionaries.get("surfaces"))}\n'
        f'tempo (pace): {codes(dictionaries.get("paces"))}\n'
        f'trudność (diff): {codes(dictionaries.get("difficulties"))}\n'
        f'rowery (bikes): {codes(dictionaries.get("bikeTypes"))}\n'
        f'waluta (cur): {codes(dictionaries.get("currencies"))}\n'
        f'cena za (punit): {codes(dictionaries.get("priceUnits"))}\n\n'
    )


def _index_para() -> str:
    return (
        'Pola gpxIdx/regIdx/imgIdx odnoszą się do liczby "index" w tablicach "links"/"images" z '
        'danych wejściowych — podaj numer dokładnie takiego wpisu, NIGDY nie wpisuj własnego adresu '
        'URL. To TRZY RÓŻNE role, nie jedna: gpxIdx tylko dla linku do pliku trasy (".gpx" w adresie, '
        'albo tekst linku typu "pobierz trasę"/"GPX"), regIdx tylko dla linku do zapisów/rejestracji '
        '(np. "zapisz się", "formularz zgłoszeniowy"). Jeśli jest tylko JEDEN link na stronie i pełni '
        'jedną z tych ról — wypełnij TYLKO odpowiadające jej pole, drugie zostaw null (NIE kopiuj tego '
        'samego indeksu do obu pól na zapas).\n\n'
    )


def _event_type_para(mention_stages: bool) -> str:
    stages_tail = ' — wtedy na PÓŹNIEJSZE pytanie o "stages" odpowiesz dzień po dniu' if mention_stages else ''
    return (
        'etype: "wyscig", gdy strona opisuje RYWALIZACJĘ (pomiar czasu, klasyfikacja/wyniki, kategorie '
        'wiekowe/open, opłata startowa, licencja np. PZKol, słowa "wyścig"/"zawody"/"maraton MTB"/'
        '"czasówka") — ma pierwszeństwo, jeśli te sygnały występują. "wycieczka_wielodniowa", gdy '
        f'strona opisuje wydarzenie trwające WIĘCEJ niż jeden dzień (osobny plan/trasa na każdy dzień)'
        f'{stages_tail}. "ustawka" dla rekreacyjnego wyjazdu jednodniowego bez pomiaru czasu. '
        '"pokrec_z_kims" dla nieformalnej, luźnej wspólnej jazdy bez sztywnego programu. null, gdy nie '
        'da się rozstrzygnąć.\n\n'
    )


# Dopisane 2026-08-07 po pełnym audycie pól kreatora (partials/wizard/*.php,
# nazwy zweryfikowane w core/Resources/EventFormResource.php) na żądanie
# usera "sprawdź co ma aplikacja i zrób te same pola w rozszerzeniu".
def _extra_fields_para() -> str:
    return (
        'moredates: gdy strona wymienia KILKA konkretnych terminów TEGO SAMEGO wydarzenia (np. cykl '
        'wyjazdów "10, 17, 24 sierpnia") — tablica dat YYYY-MM-DD OPRÓCZ głównej daty (date). Pusta '
        'tablica, gdy jest tylko jeden termin. flex: true, TYLKO gdy strona explicite mówi, że termin '
        'nie jest jeszcze ustalony/jest do potwierdzenia (np. "termin zostanie ogłoszony", "elastyczny '
        'termin") — NIE zgaduj na podstawie braku daty, wtedy po prostu date=null.\n\n'
        'minp/maxp: limit liczby uczestników CAŁEGO wydarzenia (np. "max 30 osób", "grupa 10-15 '
        'osób") — TYLKO jeśli strona podaje to wprost, nie szacuj.\n\n'
        'punit: czy cena jest za osobę czy za grupę/zespół (np. "cena od zespołu" -> per_team) — kod z '
        'listy niżej. paydl/canceldl: liczba dni przed wydarzeniem, do kiedy trzeba dopłacić/można się '
        'wycofać (np. "dopłata do 30 dni przed startem" -> paydl=30). cancelpol: WYŁĄCZNIE skopiowany/'
        'skrócony tekst zasad odwołania/zwrotu, jeśli strona go podaje (np. "zwrot zaliczki do 14 dni '
        'przed") — to NIE jest pole do kompozycji jak "desc", tylko do kopiowania faktów.\n\n'
        'oemail: e-mail KONTAKTOWY ORGANIZATORA/klubu jako podmiotu (np. z sekcji "Kontakt"/"O nas", '
        'stopki strony) — NIE mylić z remail (registrationEmail), który jest do zapisów na TEN '
        'konkretny event i może wskazywać na kogoś innego (np. biuro zawodów). Jeśli strona ma tylko '
        'JEDEN adres e-mail i nie da się rozstrzygnąć której roli służy — możesz przypisać do obu.\n\n'
    )


def _knowledge_hint_para() -> str:
    return (
        'Masz też KONTEKST Z WCZEŚNIEJSZYCH ANALIZ tej samej domeny (pole "knowledgeHint" w '
        'danych wejściowych, jeśli obecne) — potraktuj to jako WSKAZÓWKĘ (np. "ten organizator '
        'zwykle jeździ w tym regionie"), NIE jako pewnik: zawsze priorytet ma to, co faktycznie '
        'widzisz na AKTUALNEJ stronie, bo konkretny event może się różnić.\n\n'
    )


def build_system_prompt(dictionaries: dict) -> str:
    # Pełny, jednorazowy prompt — WYŁĄCZNIE ścieżka Groq (patrz duży komentarz
    # nad SHORT_KEYS: Ollama od tej sesji dzieli to na mniejsze zapytania).
    today = date.today().isoformat()
    stage_schema = _schema_line(STAGE_SHORT_KEYS)
    variant_schema = _schema_line(VARIANT_SHORT_KEYS)
    top_schema = _schema_line(SHORT_KEYS, _EXTRA_NOTES)
    return (
        _intro_para(today)
        + 'WYJĄTEK — pole "desc" (description): to jedyne miejsce, gdzie masz SKOMPONOWAĆ tekst (2-6 '
        'zdań), nie tylko go skopiować. Jeśli strona ma gotowy opis, możesz go wykorzystać/skrócić. '
        'Jeśli nie ma (albo jest szczątkowy) — ułóż zwięzły, zachęcający opis z faktów, które już '
        'zebrałeś (trasa, dystans, miejsce zbiórki, cena, co w cenie) — bez dodawania faktów spoza '
        'kontekstu.\n\n'
        + _dictionaries_para(dictionaries)
        + _index_para()
        + _event_type_para(mention_stages=False)
        + 'stages: jeden wpis na dzień wielodniówki, gdy etype="wycieczka_wielodniowa" (inaczej []). '
        'Jeśli KAŻDY dzień ma WŁASNY, ODRĘBNY link do pliku GPX (nie jeden wspólny plik na całą '
        'trasę) — dodaj "gpxIdx" do wpisu dnia (ta sama zasada co gpxIdx wariantu niżej). Jeśli dni '
        'współdzielą jeden plik albo nie masz pewności który jest czyj — zostaw null, NIE zgaduj.\n\n'
        'variants: gdy strona opisuje KILKA wariantów dystansu/trasy TEGO SAMEGO wydarzenia (np. '
        '"300 km / 200 km / 100 km", "trasa rodzinna i sportowa") — jeden wpis na wariant. Pusta '
        'tablica, gdy jest tylko jedna trasa. Jeśli KAŻDY wariant ma WŁASNY, ODRĘBNY link do pliku '
        'GPX (nie jeden wspólny plik dla całego wydarzenia) — dodaj do wpisu wariantu "gpxIdx" '
        '(ta sama zasada co główne pole gpxIdx: numer "index" z tablicy "links", NIGDY własny URL). '
        'Jeśli warianty współdzielą jeden plik albo nie masz pewności który jest czyj — zostaw null, '
        'NIE zgaduj (przypisanie NIEWŁAŚCIWEGO pliku do wariantu jest gorsze niż brak pliku).\n\n'
        + _extra_fields_para()
        + _knowledge_hint_para()
        + 'Odpowiedz WYŁĄCZNIE jednym obiektem JSON z DOKŁADNIE tymi kluczami najwyższego poziomu '
        '(pełna nazwa pola i jej sens w nawiasie, ale PISZ krótki klucz z nawiasu; brak informacji = '
        f'null, dla list []; nie pomijaj żadnego klucza): {top_schema}.\n\n'
        f'Klucze jednego wpisu "stages" (dzień wielodniówki, reszta null gdy brak): {stage_schema}.\n'
        f'Klucze jednego wpisu "variants": {variant_schema}.'
    )


# --- Ollama: prompt podzielony na 3 mniejsze zapytania (core/stages_variants/
# description) — patrz duży komentarz nad SHORT_KEYS i ollama_provider.py.

def build_core_system_prompt(dictionaries: dict) -> str:
    # Zapytanie 1/3: wszystkie pola SKALARNE + indeksy + confidence. Bez
    # "desc" (kompozycja tekstu to inny rodzaj zadania, patrz
    # build_description_prompt) i bez "stages"/"variants" (osobne, bo to
    # zagnieżdżone tablice obiektów — właśnie ta złożoność, w połączeniu z
    # resztą schematu, gubiła model na realnej stronie).
    today = date.today().isoformat()
    core_schema = _schema_line(CORE_KEYS, _EXTRA_NOTES)
    return (
        _intro_para(today)
        + _dictionaries_para(dictionaries)
        + _index_para()
        + _event_type_para(mention_stages=True)
        + _extra_fields_para()
        + _knowledge_hint_para()
        + 'Odpowiedz WYŁĄCZNIE jednym obiektem JSON z DOKŁADNIE tymi kluczami (pełna nazwa pola i jej '
        'sens w nawiasie, ale PISZ krótki klucz z nawiasu; brak informacji = null, dla list []; nie '
        f'pomijaj żadnego klucza): {core_schema}.'
    )


def build_stages_variants_prompt() -> str:
    # Zapytanie 2/3 (WARUNKOWE — tylko gdy warto, patrz ollama_provider.py):
    # WYŁĄCZNIE dzień-po-dniu i warianty dystansu, jeśli występują. Nie
    # potrzebuje słowników/indeksów/reszty schematu — mniejszy prompt, mniej
    # okazji do zgubienia formatu.
    stage_schema = _schema_line(STAGE_SHORT_KEYS)
    variant_schema = _schema_line(VARIANT_SHORT_KEYS)
    return (
        'Szukasz WYŁĄCZNIE dwóch rzeczy w tym modelu elementów strony wydarzenia rowerowego: planu '
        'dzień-po-dniu (wielodniówka) i wariantów dystansu/trasy. FAKTY WYŁĄCZNIE z danych '
        'wejściowych, nie zgaduj.\n\n'
        'stages: jeden wpis NA DZIEŃ, tylko jeśli strona opisuje wydarzenie trwające WIĘCEJ niż jeden '
        'dzień z osobnym planem na każdy dzień. Pusta tablica, jeśli to wyjazd jednodniowy. Jeśli '
        'KAŻDY dzień ma WŁASNY, ODRĘBNY link do pliku GPX (nie jeden wspólny plik na całą trasę) — '
        'dodaj "gpxIdx": numer "index" z tablicy "links" (NIGDY własny URL). Jeśli dni współdzielą '
        'jeden plik albo nie masz pewności który jest czyj — zostaw null, NIE zgaduj.\n\n'
        'variants: gdy strona opisuje KILKA wariantów dystansu/trasy TEGO SAMEGO wydarzenia (np. '
        '"300 km / 200 km / 100 km", "trasa rodzinna i sportowa") — jeden wpis na wariant. Pusta '
        'tablica, gdy jest tylko jedna trasa. Jeśli KAŻDY wariant ma WŁASNY, ODRĘBNY link do pliku '
        'GPX (nie jeden wspólny plik dla całego wydarzenia) — dodaj "gpxIdx": numer "index" z tablicy '
        '"links" w danych wejściowych (NIGDY własny URL). Jeśli warianty współdzielą jeden plik albo '
        'nie masz pewności który jest czyj — zostaw null, NIE zgaduj (błędne przypisanie pliku do '
        'wariantu jest gorsze niż brak pliku).\n\n'
        'Odpowiedz WYŁĄCZNIE jednym obiektem JSON z dokładnie kluczami "stages" i "variants" (obie '
        'tablice, [] gdy nie dotyczy — nie pomijaj żadnego z tych dwóch kluczy).\n'
        f'Klucze jednego wpisu "stages": {stage_schema}.\n'
        f'Klucze jednego wpisu "variants": {variant_schema}.'
    )


def build_description_prompt() -> str:
    # Zapytanie 3/3: kompozycja OPISU z już wyciągniętych faktów (patrz
    # build_description_user_content) — NIE z surowego dumpu strony, więc
    # to najmniejsze i najprostsze z trzech zapytań (mały wsad, mały wynik).
    return (
        'Twoje zadanie: napisz zwięzły, zachęcający opis (2-6 zdań, po polsku) wydarzenia '
        'rowerowego dla formularza na ridemore.bike, na podstawie faktów podanych w JSON-ie od usera '
        '(NIE dodawaj faktów spoza tego, co dostałeś). Jeśli w danych jest "rawExcerpt" z gotowym '
        'opisem ze strony — możesz go wykorzystać/skrócić zamiast pisać od zera. Odpowiedz WYŁĄCZNIE '
        'jednym obiektem JSON z jednym kluczem: {"desc": "..."}.'
    )


def build_description_user_content(facts: dict, context: dict) -> dict:
    # "facts" to już wyciągnięte przez zapytanie 1/3 pola (krótkie klucze) —
    # dajemy modelowi TYLKO to, co już wie, plus krótki fragment surowego
    # tekstu strony (nie cały dump elementów — to właśnie by przywróciło
    # problem "za dużo naraz", którego ta cała ścieżka ma unikać).
    texts = [
        (e.get('text') or '').strip()
        for e in sorted(
            context.get('elements') or [],
            key=lambda e: ((e.get('rect') or {}).get('y', 0), (e.get('rect') or {}).get('x', 0)),
        )
    ]
    excerpt = ' '.join(t for t in texts if t)[:800]
    return {'facts': facts, 'rawExcerpt': excerpt}


def serialize_elements(elements: list) -> str:
    # Jedyna "interpretacja" po stronie kodu: sortowanie wg pozycji (y, potem
    # x) = kolejność czytania strony — fakt geometryczny, nie semantyczny
    # (patrz dokumentacja projektowa, sekcja 5 — "co jest czym" zostaje dla
    # modelu). Limit spójny z MAX_ELEMENTS w scripts/dom-features.js.
    ordered = sorted(
        elements or [],
        key=lambda e: ((e.get('rect') or {}).get('y', 0), (e.get('rect') or {}).get('x', 0)),
    )
    lines = []
    for e in ordered[:200]:
        rect = e.get('rect') or {}
        style = e.get('style') or {}
        parent = e.get('parentId')
        text = (e.get('text') or '').replace('\n', ' ')
        lines.append(
            '#{id} <{tag}> y={y} x={x} {w}x{h} fs={fs} fw={fw} parent=#{parent} : "{text}"'.format(
                id=e.get('id'), tag=e.get('tag'), y=rect.get('y'), x=rect.get('x'),
                w=rect.get('w'), h=rect.get('h'), fs=style.get('fontSize'), fw=style.get('fontWeight'),
                parent=parent if parent is not None else '-', text=text,
            )
        )
    return '\n'.join(lines)


def build_user_content(context: dict) -> dict:
    content = {
        'sourceUrl': context.get('sourceUrl'),
        'pageTitle': context.get('pageTitle'),
        'meta': context.get('meta'),
        'jsonLd': context.get('jsonLd'),
        'links': context.get('links'),
        'images': context.get('images'),
        'elements': serialize_elements(context.get('elements')),
    }
    # Tylko gdy PHP faktycznie znalazło historię tej domeny (patrz
    # AiImportLog::domainSummary) — nie dorzucamy klucza "na wszelki
    # wypadek" z wartością null, to by tylko kosztowało tokeny na wejściu
    # za darmo (patrz też instrukcja w build_system_prompt powyżej).
    hint = context.get('knowledgeHint')
    if hint:
        content['knowledgeHint'] = hint
    return content


# --- Tryb "grupa FB" (mode='group' w payloadzie, patrz analyze.py) ---
# Rozszerzenie Chrome (scripts/group-feed.js) zbiera POSTY z feedu grupy na
# Facebooku (autor/treść/linki — WYŁĄCZNIE to, co użytkownik widzi na
# załadowanej stronie), a silnik ma TRZY zadania naraz: (1) ocenić każdy post,
# czy ktoś SZUKA TOWARZYSTWA na przejazd rowerowy, (2) uzupełnić skrótowo
# napisany post w pola wydarzenia ridemore.bike, (3) napisać KRÓTKI opis
# przejazdu ("messageRide") — wstawiany potem przez rozszerzenie do STAŁEGO
# szablonu listu do autora (szablon uzgodniony z psychologiem, żyje w
# sidepanel.js::buildAuthorMessage — model NIE pisze całego listu, bo odstąpiłby
# od uzgodnionej struktury; AI robi tylko to, co potrafi: streszcza jazdę).
# Ta sama zasada co SHORT_KEYS: krótkie klucze JSON = mniej tokenów do
# wygenerowania (wąskie gardło na lokalnej Ollamie), _validate_group() w
# analyze.py czyta z fallbackiem na pełną nazwę.
GROUP_SHORT_KEYS = {
    'postIndex': 'pIdx', 'matched': 'match', 'title': 't', 'description': 'desc',
    'region': 'reg', 'dateIso': 'date', 'time': 'time', 'meetingPointLabel': 'mpl',
    'distanceKm': 'dist', 'pace': 'pace', 'difficulty': 'diff', 'bikeTypes': 'bikes',
    'messageRide': 'msgRide', 'confidence': 'conf',
}

_GROUP_EXTRA_NOTES = {
    'matched': ' — bool; tylko "true", gdy post JASNO pokazuje szukanie towarzystwa',
    'bikeTypes': ' — tablica kodów',
    'messageRide': ' — KRÓTKI (1-2 zdania) opis przejazdu do wstawienia w list (cel, termin, dystans, tempo, zbiórka)',
    'confidence': ': "low"|"medium"|"high"',
}


def build_group_system_prompt(dictionaries: dict) -> str:
    today = date.today().isoformat()
    post_schema = _schema_line(GROUP_SHORT_KEYS, _GROUP_EXTRA_NOTES)
    return (
        'Dostajesz posty z grupy na Facebooku (autor/treść/link — wyłącznie to, co '
        f'moderator widzi na ekranie; dzisiejsza data: {today}). Zadanie TRÓJETAPOWE:\n'
        '1. Dla KAŻDEGO posta oceń, czy ktoś SZUKA TOWARZYSTWA/CHĘTNYCH na przejazd '
        'rowerowy (np. "szukam kogoś na sobotę", "pojedzie ktoś?", "chętnie pojadę z '
        'kimś", "trzeba by się zebrać w kilka osób"). Posty ogłaszające WŁASNE '
        'wydarzenia/treningi, sprzedaż, pytania niezwiązane ze wspólną jazdą albo '
        'same relacje/zdjęcia NIE pasują. W odpowiedzi umieszczaj WYŁĄCZNIE posty '
        'pasujące — resztę po prostu pomiń.\n'
        '2. Dla każdego pasującego posta uzupełnij dane wydarzenia na ridemore.bike: '
        'skróty i półsłówka ludzi ("sobota rano", "nad jeziorem", "tempko 25") rozwiń '
        'w konkretne pola. Nazwa autora MASZ w danych posta i trafi do formularza z '
        'postów — NIE ma jej w schemacie, nie zgaduj jej tu. Pole "desc" skomponuj '
        '(2-4 zdania) z faktów posta (cel jazdy, dystans, miejsce, termin) — bez '
        'dodawania faktów spoza posta. Gdy faktu nie ma albo nie masz pewności — '
        'null/pusta tablica, NIE zgaduj.\n'
        '3. Dla każdego pasującego posta napisz "msgRide": KRÓTKI (1-2 zdania, '
        'po polsku) neutralny opis przejazdu do wstawienia w list do autora '
        '(cel jazdy, termin, dystans, tempo, miejsce zbiórki — z faktów posta, '
        'bez dodawania nowych). To NIE jest list: pełny list składa rozszerzenie '
        'ze stałego szablonu. Nie używaj imienia autora ani form rodzajowych '
        '("szukał/szukała"), nie podawaj linków ani nazw innych aplikacji.\n\n'
        + _dictionaries_para(dictionaries)
        + 'Pole "pIdx" to pole "index" posta z danych wejściowych (ten sam wzorzec '
        'co indeksy linków w trybie strony: wskazujesz ISTNIEJĄCY numer, NIGDY nie '
        'wymyślasz własnego). "date": YYYY-MM-DD — przelicz względne określenia '
        '("najbliższa sobota", "za tydzień") na datę wg dzisiejszej daty.\n\n'
        'Odpowiedz WYŁĄCZNIE jednym obiektem JSON z jednym kluczem "posts" — '
        f'tablicą POSTÓW PASUJĄCYCH (puste, gdy żaden nie pasuje); każdy wpis z '
        f'DOKŁADNIE tymi kluczami (pełna nazwa i sens w nawiasie, ale PISZ krótki '
        f'klucz z nawiasu; brak informacji = null, dla list []; nie pomijaj żadnego '
        f'klucza): {post_schema}.'
    )


def build_group_user_content(context: dict) -> dict:
    # Posty są już gotowymi, małymi obiektami (treść przycięta w
    # scripts/group-feed.js) — nie ma tu odpowiednika serialize_elements();
    # wysyłamy je 1:1. "sourceUrl" zostaje w payloadzie jako identyfikator,
    # żeby most PHP mógł zapisać dziennik (AiImportLog::record).
    return {
        'sourceUrl': context.get('sourceUrl'),
        'pageTitle': context.get('pageTitle'),
        'groupName': context.get('groupName'),
        'posts': context.get('posts'),
    }
