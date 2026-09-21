# providers/groq_provider.py
# Groq: REST kompatybilny z OpenAI chat completions — wystarczy zwykły
# `requests`, bez SDK (jedna zależność mniej, patrz requirements.txt).
# Prompt/schemat WSPÓLNE z OllamaProvider — patrz providers/prompt.py.
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

GROQ_URL = 'https://api.groq.com/openai/v1/chat/completions'
# Zmienione z llama-3.3-70b-versatile po realnym HTTP 413 ("Request too
# large... TPM limit 12000") na prawdziwej stronie eventu — modele 8B mają
# na darmowym/on_demand tierze Groq wyraźnie wyższy limit tokenów na minutę
# niż 70B. Nadpisywalne przez GROQ_MODEL w .env. Jeśli tokenów mało — patrz
# providers/ollama_provider.py: lokalny model bez żadnego limitu TPM.
DEFAULT_MODEL = 'llama-3.1-8b-instant'


class GroqProvider(AIProvider):
    def __init__(self, api_key: str = None, model: str = None, timeout: int = 25):
        self.api_key = api_key or os.environ.get('GROQ_API_KEY', '')
        self.model = model or os.environ.get('GROQ_MODEL', DEFAULT_MODEL)
        self.timeout = timeout

    # Wspólna mechanika HTTP dla extract() i analyze_group() — jedno miejsce
    # na obsługę błędów (401/413/TPM), żeby tryb grupowy nie dublował 40 linii
    # obsługi requestów. Ten sam wzorzec co OllamaProvider::_call.
    def _chat(self, system_prompt: str, user_content_obj: dict) -> dict:
        user_content = json.dumps(user_content_obj, ensure_ascii=False)
        try:
            resp = requests.post(
                GROQ_URL,
                headers={'Authorization': f'Bearer {self.api_key}', 'Content-Type': 'application/json'},
                json={
                    'model': self.model,
                    'temperature': 0,
                    'response_format': {'type': 'json_object'},
                    'messages': [
                        {'role': 'system', 'content': system_prompt},
                        {'role': 'user', 'content': user_content},
                    ],
                },
                timeout=self.timeout,
            )
        except requests.RequestException as e:
            raise RuntimeError(f'Nie udało się połączyć z Groq ({e}).')

        if resp.status_code != 200:
            detail = ''
            try:
                detail = (resp.json().get('error') or {}).get('message', '')
            except Exception:
                pass
            if resp.status_code == 413 or 'tokens per minute' in detail.lower():
                # Limit TPM (nie liczby zapytań) — nawet po zmniejszeniu MAX_ELEMENTS
                # bardzo rozbudowana strona może i tak przekroczyć darmowy tier.
                raise RuntimeError(
                    'Strona jest za duża jak na limit tokenów Groq (' + detail + '). '
                    'Spróbuj ponownie za chwilę (limit odnawia się co minutę), zmniejsz '
                    'MAX_ELEMENTS w scripts/dom-features.js, albo przełącz na AI_PROVIDER=ollama.'
                )
            raise RuntimeError('Groq zwrócił HTTP {}{}.'.format(resp.status_code, f': {detail}' if detail else ''))

        data = resp.json()
        try:
            content = data['choices'][0]['message']['content']
        except (KeyError, IndexError):
            raise RuntimeError('Nieoczekiwany kształt odpowiedzi Groq.')

        try:
            return json.loads(content)
        except json.JSONDecodeError:
            raise RuntimeError('Groq nie zwrócił poprawnego JSON-a.')

    def complete_json(self, system_prompt: str, user_content_obj: dict) -> dict:
        if not self.api_key:
            raise RuntimeError('Brak GROQ_API_KEY w środowisku Pythona (ai-engine/.env albo env serwera).')
        return self._chat(system_prompt, user_content_obj)

    def extract(self, context: dict, dictionaries: dict) -> dict:
        if not self.api_key:
            raise RuntimeError(
                'Brak GROQ_API_KEY w środowisku Pythona — ustaw go w ai-engine/.env '
                '(skopiuj z .env.example) albo jako zmienną systemową. Alternatywnie: '
                'AI_PROVIDER=ollama, żeby liczyć lokalnie bez limitów tokenów.'
            )
        return self._chat(build_system_prompt(dictionaries), build_user_content(context))

    def analyze_group(self, context: dict, dictionaries: dict) -> dict:
        if not self.api_key:
            raise RuntimeError(
                'Brak GROQ_API_KEY w środowisku Pythona — ustaw go w ai-engine/.env '
                '(skopiuj z .env.example) albo jako zmienną systemową. Alternatywnie: '
                'AI_PROVIDER=ollama, żeby liczyć lokalnie bez limitów tokenów.'
            )
        return self._chat(build_group_system_prompt(dictionaries), build_group_user_content(context))

    def analyze_treasure(self, context: dict, dictionaries: dict) -> dict:
        if not self.api_key:
            raise RuntimeError(
                'Brak GROQ_API_KEY w środowisku Pythona — ustaw go w ai-engine/.env '
                '(skopiuj z .env.example) albo jako zmienną systemową. Alternatywnie: '
                'AI_PROVIDER=ollama, żeby liczyć lokalnie bez limitów tokenów.'
            )
        return self._chat(
            build_treasure_system_prompt(dictionaries.get('treasureCategories') or []),
            build_treasure_user_content(context, dictionaries.get('treasureCategories') or []),
        )
