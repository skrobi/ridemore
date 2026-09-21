# providers/ollama_provider.py
# Model lokalny (bez limitu tokenów/minutę, bez klucza API, bez kosztu) —
# dla kogoś, komu zależy na oszczędzaniu limitu Groq. Ollama musi już działać
# lokalnie (`ollama serve`, zwykle jako usługa startowa) z przynajmniej
# jednym pobranym modelem (`ollama pull <model>`) — TEN skrypt niczego nie
# instaluje ani nie ściąga sam. Który model wybrać zależy od tego, co masz
# pobrane i ile masz VRAM/RAM — sprawdź `ollama list` i ustaw nazwę w
# OLLAMA_MODEL (.env). Prompt/schemat WSPÓLNE z GroqProvider — providers/prompt.py.
#
# TRZY ZAPYTANIA, NIE JEDNO (od sesji 2026-08-07, po żywym teście na realnej,
# gęstej stronie — patrz duży komentarz nad SHORT_KEYS w prompt.py). Groq
# radzi sobie z jednym dużym zapytaniem (build_system_prompt) bez problemu w
# każdym teście — to zostaje NIEZMIENIONE w groq_provider.py. Lokalny,
# słabszy model (qwen3:8b) na długim, złożonym wejściu olewał cały 28-kluczowy
# schemat i wracał z własnym wymyślonym obiektem. Podzielenie na core/
# stages_variants/description — mniej pól i mniej kontekstu na raz w każdym
# kroku — to naprawia bez sztuczek typu wymuszony JSON Schema (który
# owszem wymusił klucze, ale rozwalił TREŚĆ i kodowanie polskich znaków,
# patrz wycofany build_json_schema() w prompt.py).
import json
import os
import re

import requests

from .base import AIProvider
from .prompt import (
    build_core_system_prompt,
    build_description_prompt,
    build_description_user_content,
    build_group_system_prompt,
    build_group_user_content,
    build_stages_variants_prompt,
    build_user_content,
)
from .treasure_prompt import (
    build_treasure_system_prompt,
    build_treasure_user_content,
)
# build_json_schema (prompt.py) istnieje, ale NIE jest tu użyte — patrz
# obszerny komentarz przy 'format' niżej (wypróbowane, wycofane, dlaczego).

DEFAULT_URL = 'http://localhost:11434'
# Placeholder — nie zakładamy, że akurat TEN model jest pobrany. Ustaw
# realną nazwę z `ollama list` w OLLAMA_MODEL (.env), inaczej dostaniesz
# czytelny błąd "nie zna modelu" zamiast cichej pomyłki.
DEFAULT_MODEL = 'llama3.1'


class OllamaProvider(AIProvider):
    def __init__(self, base_url: str = None, model: str = None, timeout: int = 300):
        self.base_url = (base_url or os.environ.get('OLLAMA_URL', DEFAULT_URL)).rstrip('/')
        self.model = model or os.environ.get('OLLAMA_MODEL', DEFAULT_MODEL)
        # 300s NA JEDNO z trzech zapytań (nie na cały extract()) — modele 30B+
        # na słabszym sprzęcie potrafią liczyć minutami, nie sekundami. Patrz
        # też timeout_seconds w core/config.php -> ai_engine, który liczy CAŁE
        # AiEngineBridge::analyze() (a więc WSZYSTKIE trzy zapytania razem) i
        # dlatego musi mieć WIĘKSZY budżet niż samo self.timeout * 3.
        self.timeout = timeout

    def _call(self, system_prompt: str, user_content_obj: dict) -> dict:
        # Wspólna mechanika HTTP dla każdego z trzech zapytań (core/
        # stages_variants/description) — jedno miejsce na obsługę błędów,
        # 'think':false i czyszczenie <think>, żeby nie rozjeżdżało się przy
        # trzech kopiach tego samego kodu.
        user_content = json.dumps(user_content_obj, ensure_ascii=False)
        try:
            resp = requests.post(
                self.base_url + '/api/chat',
                json={
                    'model': self.model,
                    'stream': False,
                    'format': 'json',  # natywny "gwarantowany JSON" Ollamy — odpowiednik response_format Groq
                    'options': {'temperature': 0},
                    # Modele "rozumujące" (Qwen3, DeepSeek-R1, gpt-oss) domyślnie generują
                    # najpierw tor myślenia — tu zadanie jest deterministyczną ekstrakcją
                    # (temperature=0), nie rozumowaniem, więc wyłączamy: szybciej i mniej
                    # okazji, żeby coś poszło nie tak z formatem. Modele bez wsparcia dla
                    # "think" po prostu ignorują to pole (Ollama API).
                    'think': False,
                    'messages': [
                        {'role': 'system', 'content': system_prompt},
                        {'role': 'user', 'content': user_content},
                    ],
                },
                timeout=self.timeout,
            )
        except requests.RequestException as e:
            raise RuntimeError(
                f'Nie połączono z Ollama pod {self.base_url} ({e}) — sprawdź, czy `ollama serve` działa '
                '(albo popraw OLLAMA_URL w .env).'
            )

        if resp.status_code == 404:
            raise RuntimeError(
                f'Ollama nie zna modelu "{self.model}" — sprawdź `ollama list` i ustaw poprawną '
                'nazwę w OLLAMA_MODEL (.env), albo pobierz go: `ollama pull {}`.'.format(self.model)
            )
        if resp.status_code != 200:
            detail = ''
            try:
                detail = resp.json().get('error', '')
            except Exception:
                pass
            raise RuntimeError('Ollama zwróciła HTTP {}{}.'.format(resp.status_code, f': {detail}' if detail else ''))

        data = resp.json()
        try:
            content = data['message']['content']
        except (KeyError, TypeError):
            raise RuntimeError('Nieoczekiwany kształt odpowiedzi Ollama.')

        # Siatka bezpieczeństwa dla 'think': False wyżej — na wypadek modelu/wersji
        # Ollamy, która i tak wleje tor myślenia do "content" (a nie do osobnego
        # "message.thinking"), zamiast czystego JSON-a.
        content = re.sub(r'<think>.*?</think>', '', content, flags=re.DOTALL).strip()

        try:
            parsed = json.loads(content)
        except json.JSONDecodeError:
            raise RuntimeError(
                'Ollama nie zwróciła poprawnego JSON-a — małe modele czasem łamią format przy dużym '
                'kontekście, spróbuj większego/innego modelu (OLLAMA_MODEL w .env).'
            )
        return parsed if isinstance(parsed, dict) else {}

    def complete_json(self, system_prompt: str, user_content_obj: dict) -> dict:
        return self._call(system_prompt, user_content_obj)

    def extract(self, context: dict, dictionaries: dict) -> dict:
        user_content_obj = build_user_content(context)

        # 1/3 — fakty skalarne + indeksy (patrz build_core_system_prompt).
        core = self._call(build_core_system_prompt(dictionaries), user_content_obj)

        # 2/3 — dzień-po-dniu + warianty, WŁASNE małe zapytanie (patrz
        # build_stages_variants_prompt) — ten sam surowy dump strony, ale
        # dużo mniejszy schemat wyjścia, więc mniej okazji do zgubienia się.
        stages_variants = self._call(build_stages_variants_prompt(), user_content_obj)

        # 3/3 — opis KOMPONOWANY z faktów z kroku 1, NIE z surowego dumpu —
        # najmniejszy wsad i wynik z trzech, patrz build_description_user_content.
        desc_result = self._call(
            build_description_prompt(),
            build_description_user_content(core, context),
        )

        return {**core, **stages_variants, 'desc': desc_result.get('desc')}

    def analyze_group(self, context: dict, dictionaries: dict) -> dict:
        # Tryb "grupa FB" (mode='group', patrz analyze.py): JEDNO zapytanie —
        # posty są już małymi, gotowymi obiektami (treść przycięta w
        # scripts/group-feed.js), a schemat wyjścia to jedna zagnieżdżona
        # tablica "posts", więc nie ma tu problemu "za dużo naraz", który
        # wymusił podział na trzy zapytania w extract() (patrz nagłówek pliku).
        return self._call(build_group_system_prompt(dictionaries), build_group_user_content(context))

    def analyze_treasure(self, context: dict, dictionaries: dict) -> dict:
        # Tryb "dodaj skarb" (mode='treasure', patrz analyze.py): JEDNO zapytanie —
        # ekstrakcja współrzędnych, nazwy, opisu, kategorii i wskazówki
        # z kontekstu strony (np. Wikipedii). Schemat prostszy niż group.
        return self._call(
            build_treasure_system_prompt(dictionaries.get('treasureCategories') or []),
            build_treasure_user_content(context, dictionaries.get('treasureCategories') or []),
        )
