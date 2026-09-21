# providers/base.py
# AIProviderInterface z dokumentacji projektowej silnika (sekcja 12) — jedna
# metoda, `extract`, żeby dojście kolejnego dostawcy (LocalLLMProvider,
# GeminiProvider) NIE wymagało zmian w analyze.py, tylko nowej klasy tutaj +
# jednej gałęzi w analyze.py:main() (patrz AI_PROVIDER w .env.example).
from abc import ABC, abstractmethod


class AIProvider(ABC):
    @abstractmethod
    def extract(self, context: dict, dictionaries: dict) -> dict:
        """Zwraca surowy dict wyniku (przed walidacją enumów w analyze.py —
        patrz _validate()) albo rzuca RuntimeError z czytelnym komunikatem
        (trafia wprost do usera panelu rozszerzenia, ma być po polsku)."""
        raise NotImplementedError

    def complete_json(self, system_prompt: str, user_content_obj: dict) -> dict:
        """Surowe zapytanie „prompt systemowy + JSON użytkownika → JSON" — dla
        zadań spoza ekstrakcji wydarzeń (tłumaczenie treści, translate.py).
        Dostawcy podpinają tu swoją mechanikę HTTP."""
        raise NotImplementedError

    def analyze_group(self, context: dict, dictionaries: dict) -> dict:
        """Tryb "grupa FB" (mode='group' w payloadzie, patrz analyze.py) — ocena
        zebranych postów z feedu grupy (czy ktoś szuka towarzystwa na przejazd)
        + uzupełnienie pól wydarzenia + redakcja wiadomości do autora.

        Domyślnie ten sam extract() — dostawcy, którzy potrzebują INNEGO
        promptu (a wszyscy obecni potrzebują), nadpisują tę metodę; dzięki
        domyślnej implementacji przyszły dostawca NIE MUSI nic wiedzieć o
        trybie grupowym, żeby reszta nie wybuchła."""
        return self.extract(context, dictionaries)

    def analyze_treasure(self, context: dict, dictionaries: dict) -> dict:
        """Tryb "dodaj skarb" (mode='treasure' w payloadzie, patrz analyze.py) —
        ekstrakcja danych skarbu z kontekstu strony internetowej (np. Wikipedii):
        współrzędne, nazwa, opis, kategoria, wskazówka.

        Domyślnie ten sam extract() — dostawcy nadpisują tę metodę z własnym
        promptem (treasure_prompt.py), dzięki czemu przyszły dostawca NIE MUSI
        nic wiedzieć o trybie skarbów, żeby reszta nie wybuchła."""
        return self.extract(context, dictionaries)
