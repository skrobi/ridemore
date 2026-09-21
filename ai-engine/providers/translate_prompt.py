# providers/translate_prompt.py
# TŁUMACZENIE TREŚCI UŻYTKOWNIKÓW (wielojęzyczność, 2026-09-17) — prompt dla
# ai-engine/translate.py. Wołane z PHP (Utils\Translator, driver `ai`) w tle,
# przez cron, NIE w trakcie renderowania strony: model odpowiada sekundami.
#
# Kod ma działać na Pythonie 3.9 (produkcja) — bez `X | None` i `match`.


LANG_NAMES = {
    'pl': 'Polish',
    'en': 'British English',
    'de': 'German',
    'cs': 'Czech',
    'sk': 'Slovak',
    'uk': 'Ukrainian',
}

# Słownictwo serwisu — to samo, którego używa słownik interfejsu
# (core/lang/en.php). Bez tego model tłumaczy „pole" jako „field", a „skarb"
# jako „prize", i treść rozjeżdża się z przyciskami obok.
GLOSSARY = {
    'en': {
        'skarb': 'treasure',
        'pole (na mapie odkryć)': 'hex',
        'przejazd': 'ride',
        'wyjazd': 'trip',
        'organizator': 'organiser',
        'zbiórka': 'meeting point',
        'wpisowe': 'entry fee',
        'zaliczka': 'deposit',
        'ustawka': 'group ride',
        'Pokręcę z kimś': 'Ride with someone',
        'wielodniówka': 'multi-day trip',
        'ślad (GPX)': 'track',
        'licznik (rowerowy)': 'bike computer',
        'szuter': 'gravel',
        'szosa': 'road',
        'nocleg': 'accommodation',
        'serwisówka / samochód wsparcia': 'support vehicle',
        'kronika': 'chronicle',
        'relacja': 'trip report',
        'peleton': 'peloton',
    },
}


def build_translate_system_prompt(target: str) -> str:
    lang = LANG_NAMES.get(target, target)
    glossary = GLOSSARY.get(target) or {}
    glossary_txt = ''
    if glossary:
        glossary_txt = '\nGlossary (Polish term → required translation):\n' + '\n'.join(
            '- {} → {}'.format(k, v) for k, v in glossary.items()
        ) + '\n'
    return (
        'You translate user-written content for ridemore.bike, a community platform for cycling: '
        'group rides, multi-day bike trips, races, known routes, treasures to find on a map.\n'
        'Translate EVERY item into {lang}.\n'
        'Rules:\n'
        '- Keep the meaning, tone and level of formality of the author. Do not add, summarise or explain anything.\n'
        '- Keep line breaks, lists, emoji, HTML tags, URLs, e-mail addresses, phone numbers, numbers, units, '
        'dates, prices (currency as written in the source: "150 zł" stays "150 zł") '
        'and anything in {{curly braces}} exactly as they are.\n'
        '- Do not translate proper names (places, mountains, rivers, people, clubs, companies, event names that '
        'are brands) unless there is a well-established exonym (Kraków → Krakow is NOT needed; Tatry → Tatra Mountains is fine).\n'
        '- If an item is already in {lang}, return it unchanged.\n'
        '- For each item, detect the source language as an ISO 639-1 code (pl, en, de, cs, sk, ...).\n'
        '{glossary}'
        'Answer with JSON only: {{"items": [{{"i": 0, "from": "pl", "text": "..."}}, ...]}} '
        'with exactly one entry per input item, same "i" as in the input.'
    ).format(lang=lang, glossary=glossary_txt)


def build_translate_user_content(texts: list) -> dict:
    return {'items': [{'i': i, 'text': t} for i, t in enumerate(texts)]}


