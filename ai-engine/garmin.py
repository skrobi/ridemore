# ai-engine/garmin.py
# MOST DO GARMIN CONNECT — wołany przez PHP (core/Utils/GarminBridge.php)
# przez proc_open, JEDEN proces na JEDNO żądanie, dokładnie tym samym
# kontraktem co analyze.py: JSON na stdin, JEDEN JSON na stdout w kształcie
# {"ok": true, "data": {...}} albo {"ok": false, "error": "...", "code": "..."}.
# Exit code zawsze 0 — PHP rozróżnia po polu "ok", nie po kodzie wyjścia.
#
# DLACZEGO PYTHON, SKORO RESZTA APLIKACJI TO PHP. Garmin nie ma publicznego
# API dla kont osobistych (Connect Developer Program to program partnerski dla
# firm, z ręczną akceptacją). Jedyna działająca droga to biblioteka
# `garminconnect`, która loguje się tak jak aplikacja mobilna — a ta istnieje
# wyłącznie w Pythonie. Most PHP->Python już był zbudowany dla silnika AI, więc
# ten plik nie wprowadza nowej klasy zależności, tylko drugie jej użycie.
#
# ŚWIADOMIE PRZYJĘTE RYZYKO: to NIE jest oficjalne API. `garth` (poprzednia
# biblioteka) umarł w marcu 2026, gdy Garmin zmienił logowanie; `garminconnect`
# przetrwał, bo podszywa się pod odcisk TLS aplikacji Androida. Każda zmiana po
# stronie Garmina może to wyłączyć z dnia na dzień — dlatego ta funkcja jest
# DODATKIEM do wgrywania plików, nigdy jedyną drogą wgrania śladu.
#
# HASŁO NIE JEST TU NIGDY ZAPISYWANE: wchodzi stdin-em (nie argv, nie plik),
# żyje w pamięci procesu tyle, ile trwa logowanie, i wychodzi stąd wyłącznie
# jako token sesyjny. PHP szyfruje ten token przed zapisem do bazy.
#
# 2FA — OGRANICZENIE, KTÓREGO NIE DA SIĘ TU OBEJŚĆ. Stan logowania dwuskładnikowego
# (sesja HTTP + CSRF Garmina) żyje w OBIEKCIE klienta i nie da się go zserializować,
# a nasz proces kończy się razem z żądaniem HTTP. Kod można więc podać wyłącznie
# OD RAZU, razem z hasłem — co działa dla aplikacji uwierzytelniającej (kod
# generowany na żądanie), a nie działa dla kodu wysyłanego mailem po fakcie.
# Taki przypadek wraca kodem błędu "mfa" i PHP mówi o tym wprost.
import json
import sys

# KRYTYCZNE na Windows, ta sama przyczyna co w analyze.py: przy strumieniach
# przekierowanych do pipe'a (proc_open) Python dobiera kodowanie z systemowej
# strony kodowej (cp1250), nie UTF-8. Nazwy aktywności z Garmina bywają
# z polskimi znakami, a GPX jest tekstem UTF-8 — bez tego wyjście wychodzi
# uszkodzone albo urwane UnicodeEncodeError-em.
sys.stdin.reconfigure(encoding='utf-8')
sys.stdout.reconfigure(encoding='utf-8')
sys.stderr.reconfigure(encoding='utf-8')

# Rodzaje aktywności, które nas interesują. Klucze z Garmina (activityType.typeKey).
# Bierzemy WYŁĄCZNIE rower — bieg czy pływanie nie mają czego odkrywać na mapie
# rowerowej, a wciągnięte po cichu zafałszowałyby kilometry i punkty.
CYCLING_KEYS = {
    'cycling',
    'road_biking',
    'mountain_biking',
    'gravel_cycling',
    'cyclocross',
    'bmx',
    'downhill_biking',
    'track_cycling',
    'recumbent_cycling',
    'e_bike_fitness',
    'e_bike_mountain',
    'e_bike_commuting',
    'commuting',
    'bike_commuting',
}

# Trenażer i jazda wirtualna to rower, ale BEZ ŚLADU GPS. Wciągnięte skończyłyby
# jako "odrzucone" przy parsowaniu GPX-a i za każdym razem zaśmiecały listę,
# więc odsiewamy je tutaj, u źródła.
INDOOR_MARKERS = ('indoor', 'virtual', 'trainer')

# Ile aktywnosci bierzemy na JEDNO zapytanie do Garmina przy przegladaniu historii.
PAGE = 100


class MfaRequired(Exception):
    """Garmin poprosił o kod 2FA, a użytkownik żadnego nie podał."""


def _is_cycling(activity: dict) -> bool:
    type_key = ((activity.get('activityType') or {}).get('typeKey') or '').lower()
    if not type_key:
        return False
    if any(marker in type_key for marker in INDOOR_MARKERS):
        return False
    return type_key in CYCLING_KEYS or 'biking' in type_key or 'cycling' in type_key


def _client(payload: dict):
    """Klient zalogowany tokenem albo hasłem — jedno wejście dla obu dróg."""
    from garminconnect import Garmin

    tokens = (payload.get('tokens') or '').strip()
    if tokens:
        # Token wchodzi jako JSON WPROST (garminconnect rozpoznaje to po kształcie
        # i nie dotyka dysku) — dzięki temu sekret nigdy nie ląduje w pliku na
        # serwerze, tylko wraca zaszyfrowany do bazy.
        client = Garmin()
        client.login(tokenstore=tokens)
        return client

    email = (payload.get('email') or '').strip()
    password = payload.get('password') or ''
    if not email or not password:
        raise ValueError('Brak danych logowania.')

    code = (payload.get('mfaCode') or '').strip()

    def prompt_mfa() -> str:
        if not code:
            raise MfaRequired()
        return code

    client = Garmin(email=email, password=password, prompt_mfa=prompt_mfa)
    client.login()
    return client


def _cmd_login(payload: dict) -> dict:
    client = _client(payload)
    return {
        'tokens': client.client.dumps(),
        'displayName': client.display_name or '',
        'fullName': client.full_name or '',
    }


def _cmd_list(payload: dict) -> dict:
    """Aktywnosci rowerowe z okna historii, ze stronicowaniem.

    DLACZEGO STRONICOWANIE, A NIE JEDNO ZAPYTANIE (blad zgloszony przez usera
    2026-08-24: „sprawdzono 16 aktywnosci, nic nowego, a ja mam pod 300 u siebie
    w Garminie"). Garmin oddaje aktywnosci WSZYSTKICH dyscyplin, a my bierzemy
    z nich tylko rower — wiec „ostatnie 50" potrafi znaczyc „16 rowerowych",
    i to akurat te, ktore czlowiek zaimportowal poprzednim razem. Reszta jego
    historii lezala glebiej, niz w ogole patrzylismy.

    Teraz przegladamy `scan` SUROWYCH aktywnosci (domyslnie 300), po `PAGE` na
    zapytanie, w JEDNYM procesie i na jednym logowaniu. `start` pozwala siegnac
    jeszcze glebiej — panel wysyla go przyciskiem „Szukaj starszych".

    Zwracamy tez `scanned`: bez tej liczby komunikat mowilby „sprawdzono 16"
    i mialby na mysli cos zupelnie innego niz czlowiek, ktory to czyta.
    """
    client = _client(payload)

    start = max(0, int(payload.get('start') or 0))
    scan = int(payload.get('scan') or 300)
    scan = max(1, min(scan, 1000))

    result = []
    scanned = 0
    while scanned < scan:
        # Strona po 100: dosc duza, zeby 300 aktywnosci zmiescilo sie w trzech
        # zapytaniach, i dosc mala, zeby pojedyncza odpowiedz nie byla ogromna.
        krok = min(PAGE, scan - scanned)
        chunk = client.get_activities(start + scanned, krok) or []
        if not chunk:
            break
        scanned += len(chunk)

        for activity in chunk:
            if not _is_cycling(activity):
                continue
            distance_m = activity.get('distance') or 0
            result.append({
                'id': str(activity.get('activityId') or ''),
                'name': activity.get('activityName') or '',
                # startTimeLocal jest tym, co czlowiek widzi w Garmin Connect —
                # data przejazdu i tak wyliczy sie potem ze znacznikow w GPX-ie
                # (Utils\Gpx::parse), tu chodzi wylacznie o rozpoznanie wiersza.
                'startedAt': activity.get('startTimeLocal') or '',
                'distanceKm': round(float(distance_m) / 1000.0, 2),
                'typeKey': ((activity.get('activityType') or {}).get('typeKey') or ''),
            })

        # Krotsza strona niz zamowiona = koniec historii, nie ma po co pytac dalej.
        if len(chunk) < krok:
            break

    return {
        'activities': result,
        'scanned': scanned,
        'start': start,
        'tokens': client.client.dumps(),
    }


def _cmd_download(payload: dict) -> dict:
    """Slady WSKAZANYCH aktywnosci — CALA PARTIA na jednym logowaniu.

    Do 2026-08-24 PHP wolal ten most osobno dla KAZDEJ aktywnosci, a kazde
    wywolanie to nowy proces Pythona i nowe logowanie do Garmina. Przy partii
    pietnastu przejazdow znaczylo to pietnascie logowan pod rzad: wolno
    (kilkadziesiat sekund, na granicy limitu czasu) i wprost prosi sie o
    blokade po stronie Garmina za zbyt czeste proby.
    
    Teraz jedno logowanie obsluguje cala partie. Blad POJEDYNCZEJ aktywnosci
    (trening bez sladu, chwilowa odmowa) nie przerywa reszty — wraca w `errors`
    i PHP zapisuje ja jako odrzucona, dokladnie jak przedtem.
    """
    from garminconnect import Garmin

    ids = payload.get('activityIds')
    if not ids:
        # Zgodnosc wsteczna: pojedyncze `activityId` dalej dziala.
        single = str(payload.get('activityId') or '').strip()
        ids = [single] if single else []

    czyste = [str(i).strip() for i in ids if str(i).strip().isdigit()]
    if not czyste:
        raise ValueError('Nieprawidlowy identyfikator aktywnosci.')

    client = _client(payload)

    files = {}
    errors = {}
    for activity_id in czyste:
        try:
            raw = client.download_activity(activity_id, dl_fmt=Garmin.ActivityDownloadFormat.GPX)
            if not raw:
                errors[activity_id] = 'Garmin nie zwrocil sladu.'
                continue
            files[activity_id] = raw.decode('utf-8', errors='replace')
        except Exception as e:  # noqa — jedna aktywnosc nie moze zabic partii
            errors[activity_id] = f'{type(e).__name__}: {e}'

    return {'files': files, 'errors': errors}


COMMANDS = {
    'login': _cmd_login,
    'list': _cmd_list,
    'download': _cmd_download,
}


def main() -> int:
    try:
        payload = json.loads(sys.stdin.read() or '{}')
    except json.JSONDecodeError as e:
        print(json.dumps({'ok': False, 'error': f'Nieprawidłowy JSON na wejściu: {e}', 'code': 'input'}))
        return 0

    command = (payload.get('command') or '').lower()
    handler = COMMANDS.get(command)
    if handler is None:
        print(json.dumps({'ok': False, 'error': f'Nieznana komenda {command!r}.', 'code': 'input'},
                         ensure_ascii=False))
        return 0

    try:
        data = handler(payload)
        print(json.dumps({'ok': True, 'data': data}, ensure_ascii=False))
    except MfaRequired:
        print(json.dumps({
            'ok': False,
            'code': 'mfa',
            'error': 'Konto wymaga kodu dwuskładnikowego.',
        }, ensure_ascii=False))
    except ImportError as e:
        # Biblioteki nie ma w środowisku — to błąd INSTALACJI, nie użytkownika,
        # i musi być rozpoznawalny osobno, żeby panel nie mówił „złe hasło".
        print(json.dumps({
            'ok': False,
            'code': 'setup',
            'error': f'Brak biblioteki garminconnect w środowisku Pythona ({e}).',
        }, ensure_ascii=False))
    except Exception as e:  # noqa — punkt wejścia CLI: każdy błąd wraca JSON-em, nigdy traceback na stdout
        name = type(e).__name__
        if 'Authentication' in name:
            code = 'auth'
        elif 'TooManyRequests' in name:
            code = 'rate'
        elif 'Connection' in name:
            code = 'conn'
        else:
            code = 'error'
        # KOMUNIKAT Z BIBLIOTEKI NIE IDZIE DO PRZEGLĄDARKI: PHP mapuje sam `code`
        # na własny tekst (patrz GarminController). Tu zostaje pełna treść, bo
        # trafia do error_loga i bez niej diagnoza „coś nie działa" jest ślepa.
        print(json.dumps({'ok': False, 'code': code, 'error': f'{name}: {e}'}, ensure_ascii=False))
    return 0


if __name__ == '__main__':
    sys.exit(main())
