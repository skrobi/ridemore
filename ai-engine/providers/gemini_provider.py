# providers/gemini_provider.py
# Google AI Studio (Gemini API) — REST, klucz API w query string (?key=...),
# nie w nagłówku jak Groq/OpenAI-compatible. Wystarczy zwykły `requests`, bez
# SDK (patrz requirements.txt). Prompt/schemat WSPÓLNE z Groq/Ollama —
# providers/prompt.py. Dodane 2026-08-07 po tym, jak Groq (darmowy tier,
# limit 6000 TPM) okazał się za wąski nawet po przycięciu payloadu
# (patrz analyze.py::_prioritize_links) — Gemini 2.5 Flash ma 1M tokenów
# wejścia / 65k wyjścia na tym koncie, więc payload nie musi być tak
# agresywnie ucinany jak dla Groq (przycinanie w analyze.py zostaje — nie
# szkodzi, i tak jest provider-agnostyczne — ale nie jest tu koniecznością).
import json
import os

import requests

from .base import AIProvider
from .prompt import (
    build_group_system_prompt,
    build_group_user_content,
    build_system_prompt,
    build_user_content,
)
from .treasure_prompt import (
    build_treasure_system_prompt,
    build_treasure_user_content,
)

GEMINI_URL_TMPL = 'https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent'
# Sprawdzone żywo 2026-08-07, PO tym jak user realnie wyczerpał limit 20
# zapytań/dzień na gemini-flash-latest (alias -> gemini-3.6-flash) samym
# użyciem wtyczki: KAŻDY model Gemini ma WŁASNY, niezależny licznik
# darmowego tieru — gemini-2.5-flash/-lite dają HTTP 404 (już niedostępne
# dla nowych kont, mimo że są na liście GET /v1beta/models — ListModels()
# pokazuje więcej niż faktycznie działa), gemini-2.0-flash/-lite mają limit
# 0, gemini-3.1-flash-lite zadziałał ze świeżym licznikiem. Jeśli TEN
# wyczerpiesz — spróbuj innego modelu z listy (GEMINI_MODEL w .env), każdy
# ma osobny budżet. "Quota exceeded ... limit: 0" = ten model nie ma
# darmowego dostępu na TYM koncie, nie błąd kodu; "limit: N" wyczerpany = po
# prostu poczekaj na reset (prawdopodobnie 24h) albo zmień model.
DEFAULT_MODEL = 'gemini-3.1-flash-lite'


class GeminiProvider(AIProvider):
    def __init__(self, api_key: str = None, model: str = None, timeout: int = 60):
        self.api_key = api_key or os.environ.get('GEMINI_API_KEY', '')
        self.model = model or os.environ.get('GEMINI_MODEL', DEFAULT_MODEL)
        self.timeout = timeout

    # Wspólna mechanika HTTP dla extract() i analyze_group() — patrz ten sam
    # wzorzec w groq_provider.py::_chat (tam + OllamaProvider::_call): jedno
    # miejsce na obsługę błędów/limitu, żeby tryb grupowy nie dublował kodu.
    def _generate(self, system_prompt: str, user_content_obj: dict) -> dict:
        user_content = json.dumps(user_content_obj, ensure_ascii=False)
        url = GEMINI_URL_TMPL.format(model=self.model)

        try:
            resp = requests.post(
                url,
                params={'key': self.api_key},
                json={
                    # systemInstruction osobno od "contents" (rola user) — to
                    # jest odpowiednik system+user message Groq/Ollama, nie
                    # jeden zlepiony prompt.
                    'systemInstruction': {'parts': [{'text': system_prompt}]},
                    'contents': [{'role': 'user', 'parts': [{'text': user_content}]}],
                    'generationConfig': {
                        'temperature': 0,
                        # Odpowiednik response_format=json_object (Groq) / format=json
                        # (Ollama) — gwarantuje SKŁADNIĘ JSON, nie schemat/typy pól
                        # (ta sama zasada co gdzie indziej w tym pliku/projekcie —
                        # patrz komentarze w analyze.py::_validate).
                        'responseMimeType': 'application/json',
                    },
                },
                timeout=self.timeout,
            )
        except requests.RequestException as e:
            raise RuntimeError(f'Nie udało się połączyć z Gemini ({e}).')

        if resp.status_code != 200:
            detail = ''
            try:
                detail = (resp.json().get('error') or {}).get('message', '')
            except Exception:
                pass
            if resp.status_code == 429:
                raise RuntimeError(
                    'Limit zapytań/tokenów Gemini przekroczony (' + detail + '). Poczekaj chwilę '
                    'albo przełącz na inny GEMINI_MODEL/dostawcę (AI_PROVIDER).'
                )
            raise RuntimeError('Gemini zwróciło HTTP {}{}.'.format(resp.status_code, f': {detail}' if detail else ''))

        data = resp.json()

        # Blokada bezpieczeństwa/moderacji Gemini nie jest HTTP-błędem — wraca
        # 200 bez "candidates" (albo z finishReason != "STOP"), więc trzeba to
        # sprawdzić osobno, inaczej dalszy KeyError zgłosiłby mylący komunikat.
        block_reason = (data.get('promptFeedback') or {}).get('blockReason')
        if block_reason:
            raise RuntimeError(f'Gemini zablokowało treść ({block_reason}) — prawdopodobnie filtr bezpieczeństwa.')

        try:
            candidate = data['candidates'][0]
            content = candidate['content']['parts'][0]['text']
        except (KeyError, IndexError, TypeError):
            finish_reason = (data.get('candidates') or [{}])[0].get('finishReason', '?')
            raise RuntimeError(f'Nieoczekiwany kształt odpowiedzi Gemini (finishReason={finish_reason}).')

        try:
            return json.loads(content)
        except json.JSONDecodeError:
            raise RuntimeError('Gemini nie zwróciło poprawnego JSON-a.')

    def complete_json(self, system_prompt: str, user_content_obj: dict) -> dict:
        if not self.api_key:
            raise RuntimeError('Brak GEMINI_API_KEY w środowisku Pythona (ai-engine/.env albo env serwera).')
        return self._generate(system_prompt, user_content_obj)

    def extract(self, context: dict, dictionaries: dict) -> dict:
        if not self.api_key:
            raise RuntimeError(
                'Brak GEMINI_API_KEY w środowisku Pythona — ustaw go w ai-engine/.env '
                '(wygeneruj w aistudio.google.com/apikey). Alternatywnie: AI_PROVIDER=groq '
                'albo AI_PROVIDER=ollama.'
            )
        return self._generate(build_system_prompt(dictionaries), build_user_content(context))

    def analyze_group(self, context: dict, dictionaries: dict) -> dict:
        if not self.api_key:
            raise RuntimeError(
                'Brak GEMINI_API_KEY w środowisku Pythona — ustaw go w ai-engine/.env '
                '(wygeneruj w aistudio.google.com/apikey). Alternatywnie: AI_PROVIDER=groq '
                'albo AI_PROVIDER=ollama.'
            )
        return self._generate(build_group_system_prompt(dictionaries), build_group_user_content(context))

    def analyze_treasure(self, context: dict, dictionaries: dict) -> dict:
        if not self.api_key:
            raise RuntimeError(
                'Brak GEMINI_API_KEY w środowisku Pythona — ustaw go w ai-engine/.env '
                '(wygeneruj w aistudio.google.com/apikey). Alternatywnie: AI_PROVIDER=groq '
                'albo AI_PROVIDER=ollama.'
            )
        return self._generate(
            build_treasure_system_prompt(dictionaries.get('treasureCategories') or []),
            build_treasure_user_content(context, dictionaries.get('treasureCategories') or []),
        )
