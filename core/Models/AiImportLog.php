<?php
// core/Models/AiImportLog.php
// "Pamięć/uczenie" silnika AI (dokumentacja projektowa, sekcja 10) w wersji
// odpowiedniej do obecnej skali — MySQL zamiast Vector Database (FAISS/
// Chroma/Qdrant): tabela istnieje wyłącznie do przeglądu jakości ekstrakcji
// (ile razy, jakie domeny, jaka pewność), NIE do treningu/porównań
// podobieństwa — to zostaje świadomie na potem, gdyby wolumen to uzasadnił
// (patrz md/features.md). Świadomie NIE zapisujemy pełnego payloadu wejścia
// (elementy DOM) — tylko fingerprint (liczba elementów, domena) — żeby nie
// puchnąć bazą treścią stron trzecich.
namespace Models;

use Core\Database;

class AiImportLog
{
    // "Knowledge base" v1 (patrz md/features.md) — zamiast wektorowego
    // podobieństwa (świadomie odłożone wyżej), najprostsza rzecz, która
    // faktycznie pomaga: ostatnia DOBRA (confidence != 'low') analiza TEJ
    // SAMEJ domeny, wstrzyknięta do promptu jako WSKAZÓWKA (patrz
    // AiEngineBridge::analyze() i providers/prompt.py po stronie Pythona).
    // Model-agnostyczne z założenia: żyje w PHP/MySQL, nie w wagach/kontekście
    // żadnego konkretnego modelu, więc działa tak samo pod Groq jak pod
    // Ollamę — a rośnie automatycznie z każdym kolejnym realnym skanem.
    public static function domainSummary(?string $domain, int $limit = 5): ?array
    {
        if (!$domain) {
            return null;
        }

        $pdo = Database::connection();
        $rows = $pdo->prepare('
            SELECT result_json, confidence, created_at
            FROM ai_import_logs
            WHERE source_domain = :domain
            ORDER BY created_at DESC
            LIMIT ' . (int) $limit . '
        ');
        $rows->execute(['domain' => $domain]);
        $rows = $rows->fetchAll();
        if (!$rows) {
            return null;
        }

        // Pierwszy wiersz z confidence high/medium — nie zawsze najnowszy
        // (jeśli ostatni skan był 'low', wcześniejszy dobry jest lepszą
        // wskazówką niż nic). Same 'low' albo brak danych -> null (lepiej
        // nie podsuwać modelowi niepewnego kontekstu).
        $best = null;
        foreach ($rows as $row) {
            if (($row['confidence'] ?? 'low') !== 'low') {
                $best = $row;
                break;
            }
        }
        if (!$best) {
            return null;
        }

        $decoded = json_decode($best['result_json'] ?? '', true);
        if (!is_array($decoded)) {
            return null;
        }

        // Tylko pola, które mają sens jako "co wiemy o tym organizatorze z
        // przeszłości" — nie kopiujemy dat/tras/opisu (per-event, nie
        // per-domena). Puste/null wycinamy, żeby nie puchnąć promptem.
        $fields = ['organizer', 'region', 'bikeTypes', 'pace', 'difficulty', 'surface', 'priceCurrency'];
        $hint = array_filter(
            array_intersect_key($decoded, array_flip($fields)),
            static fn ($v) => $v !== null && $v !== [] && $v !== ''
        );
        if (!$hint) {
            return null;
        }
        $hint['sampleCount'] = count($rows);
        return $hint;
    }

    public static function record(string $url, array $input, array $result): int
    {
        $domain = null;
        $host = parse_url($url, PHP_URL_HOST);
        if ($host) {
            $domain = preg_replace('/^www\./', '', $host);
        }

        $pdo = Database::connection();
        $pdo->prepare('
            INSERT INTO ai_import_logs (url, source_domain, element_count, confidence, result_json)
            VALUES (:url, :domain, :element_count, :confidence, :result_json)
        ')->execute([
            'url'           => mb_substr($url, 0, 500),
            'domain'        => $domain ? mb_substr($domain, 0, 190) : null,
            'element_count' => count($input['elements'] ?? []),
            'confidence'    => $result['confidence'] ?? null,
            'result_json'   => json_encode($result, JSON_UNESCAPED_UNICODE),
        ]);
        return (int) $pdo->lastInsertId();
    }
}
