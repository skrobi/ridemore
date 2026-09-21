# ai-engine/translate.py
# TŁUMACZENIE TREŚCI przez model językowy (wielojęzyczność, 2026-09-17).
# Wołane z PHP przez Utils\PythonBridge (Utils\Translator, driver `ai`) —
# ten sam most i ten sam wybór dostawcy (AI_PROVIDER: gemini/groq/ollama,
# klucze w env albo ai-engine/.env) co analyze.py.
#
# Wejście (stdin):  {"target": "en", "texts": ["...", "..."]}
# Wyjście (stdout): {"ok": true, "data": {"items": [{"text": "...", "from": "pl"}, ...]}}
#                   albo {"ok": false, "error": "..."}; exit code zawsze 0.
#
# Kod ma działać na Pythonie 3.9 (produkcja) — bez `X | None` i `match`.
import json
import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

# Te same trzy linie co w analyze.py: przez pipe Windows dobiera cp1250.
sys.stdin.reconfigure(encoding='utf-8')
sys.stdout.reconfigure(encoding='utf-8')
sys.stderr.reconfigure(encoding='utf-8')

from providers.translate_prompt import (  # noqa: E402
    build_translate_system_prompt,
    build_translate_user_content,
)

MAX_TEXTS = 60
_LANG_RE = re.compile(r'^[a-z]{2}$')


def _load_dotenv() -> None:
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


def _provider():
    name = os.environ.get('AI_PROVIDER', 'gemini').lower()
    if name == 'gemini':
        from providers.gemini_provider import GeminiProvider
        return GeminiProvider()
    if name == 'groq':
        from providers.groq_provider import GroqProvider
        return GroqProvider()
    if name == 'ollama':
        from providers.ollama_provider import OllamaProvider
        return OllamaProvider()
    raise RuntimeError('Nieznany dostawca AI_PROVIDER={!r} (obsługiwane: gemini, groq, ollama).'.format(name))


def _validate(raw: dict, texts: list) -> list:
    items = raw.get('items') if isinstance(raw, dict) else None
    if not isinstance(items, list):
        raise RuntimeError('Model nie zwrócił listy "items".')
    by_index = {}
    for it in items:
        if not isinstance(it, dict):
            continue
        try:
            i = int(it.get('i'))
        except (TypeError, ValueError):
            continue
        text = it.get('text')
        if 0 <= i < len(texts) and isinstance(text, str) and text.strip():
            src = it.get('from')
            src = src.strip().lower()[:2] if isinstance(src, str) else None
            by_index[i] = {'text': text, 'from': src if src and _LANG_RE.match(src) else None}
    # WSZYSTKO albo NIC: brak choćby jednej pozycji = model pogubił numerację,
    # więc nie ufamy też pozostałym (tłumaczenie trafiłoby do cudzego tekstu).
    if len(by_index) != len(texts):
        raise RuntimeError('Model zwrócił {} z {} tłumaczeń.'.format(len(by_index), len(texts)))
    return [by_index[i] for i in range(len(texts))]


def main() -> int:
    _load_dotenv()
    try:
        payload = json.loads(sys.stdin.read() or '{}')
    except json.JSONDecodeError:
        print(json.dumps({'ok': False, 'error': 'Niepoprawny JSON wejściowy.'}))
        return 0
    try:
        target = str(payload.get('target') or '').lower()
        texts = payload.get('texts') or []
        if not _LANG_RE.match(target):
            raise RuntimeError('Brak albo zły język docelowy.')
        if not isinstance(texts, list) or not texts or len(texts) > MAX_TEXTS or not all(isinstance(t, str) for t in texts):
            raise RuntimeError('Zła lista tekstów (1–{} napisów).'.format(MAX_TEXTS))
        provider = _provider()
        raw = provider.complete_json(build_translate_system_prompt(target), build_translate_user_content(texts))
        if os.environ.get('AI_ENGINE_DEBUG'):
            print('DEBUG raw:', json.dumps(raw, ensure_ascii=False), file=sys.stderr)
        print(json.dumps({'ok': True, 'data': {'items': _validate(raw, texts)}}, ensure_ascii=False))
    except Exception as e:  # noqa — każdy błąd wraca jako JSON
        print(json.dumps({'ok': False, 'error': str(e)}, ensure_ascii=False))
    return 0


if __name__ == '__main__':
    sys.exit(main())
