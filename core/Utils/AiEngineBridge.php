<?php
// core/Utils/AiEngineBridge.php
// Most PHP -> Python dla silnika AI (ai-engine/analyze.py). Powód, dla
// którego to w ogóle istnieje: rozszerzenie Chrome (JS) nie potrafi odpalić
// procesu Pythona, a nie chcemy zmuszać usera do ręcznego trzymania
// osobnego serwera w terminalu (tak jak siostrzane narzędzie bikevents
// wymaga `py viewer.py`) — więc PHP (i tak działa cały czas pod XAMPP w
// trybie dev) odpala Pythona NA ŻĄDANIE, jeden proces na jedno żądanie.
//
// Mechanika uruchomienia (proc_open, timeout, czytanie pipe'ów bez deadlocka)
// mieszka od 2026-08-23 w Utils\PythonBridge — wspólnie z drugim skryptem
// Pythona (ai-engine/garmin.py). Tutaj zostaje to, co dotyczy WYŁĄCZNIE
// silnika AI: konfiguracja i zamiana koperty {"ok": ...} na wynik albo wyjątek.
//
// Bezpieczeństwo: patrz PythonBridge (argumenty w tablicy, wejście przez
// stdin). Wywołujący (api/routes.php) i tak blokuje ten most poza
// APP_ENV==='dev', to tu jest druga, niezależna warstwa ostrożności.
namespace Utils;

class AiEngineBridge
{
    // $payload trafia do Pythona 1:1 jako JSON na stdin. Zwraca tablicę
    // (pole "data" z odpowiedzi analyze.py) albo rzuca RuntimeException z
    // komunikatem bezpiecznym do pokazania w panelu rozszerzenia.
    public static function analyze(array $payload): array
    {
        $config = APP_CONFIG['ai_engine'] ?? null;
        if (!$config) {
            throw new \RuntimeException('Silnik AI nie jest skonfigurowany w tym środowisku.');
        }

        $decoded = PythonBridge::run($config, $payload, 'silnik AI');

        if ($decoded['ok'] !== true) {
            throw new \RuntimeException((string) ($decoded['error'] ?? 'Nieznany błąd silnika AI.'));
        }

        return is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
    }
}
