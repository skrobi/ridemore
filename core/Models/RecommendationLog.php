<?php
// core/Models/RecommendationLog.php
// Etap 3 (preferencje) §8 — dziennik rekomendacji. Zapisywany z KONTROLERÓW
// (widget strony wydarzenia, podpowiedzi formularza, zadania powiadomień),
// NIGDY z Models\MatchEngine — warstwa licząca wynik nie może wiedzieć nic
// o interfejsie/kontekście pokazania (docs/etap3 §10, "Rozdzielenie
// oceniania od prezentacji").
namespace Models;

use Core\Database;

class RecommendationLog
{
    // Jedno wystąpienie pokazania rekomendacji. $userId null dla
    // niezalogowanych (dozwolone w schemacie — sam fakt pokazania i tak
    // wart jest zapisania pod przyszłą analizę wolumenu/klikalności
    // niezalogowanych).
    public static function record(
        ?int $userId,
        int $eventId,
        string $context,
        float $score,
        int $position,
        bool $isExploration,
        bool $isAspirational,
        float $derivedShare
    ): int {
        $pdo = Database::connection();
        $pdo->prepare('
            INSERT INTO recommendation_log
              (user_id, event_id, context, score, position, is_exploration, is_aspirational, derived_share)
            VALUES (:user_id, :event_id, :context, :score, :position, :exploration, :aspirational, :derived_share)
        ')->execute([
            'user_id'      => $userId,
            'event_id'     => $eventId,
            'context'      => $context,
            'score'        => $score,
            'position'     => $position,
            'exploration'  => $isExploration ? 1 : 0,
            'aspirational' => $isAspirational ? 1 : 0,
            'derived_share' => $derivedShare,
        ]);
        return (int) $pdo->lastInsertId();
    }

    // Dopisuje wynik do NAJNOWSZEGO wpisu dla tej pary (user, event) — jeden
    // klik może dotyczyć dowolnego z kilku wcześniejszych pokazań (formularz,
    // potem strona eventu), więc bierzemy najświeższy, nie konkretne id
    // (front nie zna id wpisu w dzienniku). Świadomie BEZ warunku "outcome IS
    // NULL" — stopień 2 odrzucenia (docs/etap3 §6, powód opcjonalnie dogrywany
    // drugim kliknięciem) woła to PO stopniu 1, który już ustawił
    // outcome='dismissed'; bez tego drugie wywołanie nie znajdowałoby już
    // żadnego wiersza i outcome_reason zostawałby zawsze pusty. COALESCE
    // chroni istniejący powód, gdyby ktoś wywołał to z $reason=null po fakcie.
    // Bez efektu dla niezalogowanych — nie ma czego dopasować bez user_id.
    public static function recordOutcome(int $userId, int $eventId, string $outcome, ?string $reason = null): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('
            SELECT id FROM recommendation_log
            WHERE user_id = :user_id AND event_id = :event_id
            ORDER BY shown_at DESC LIMIT 1
        ');
        $stmt->execute(['user_id' => $userId, 'event_id' => $eventId]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            return;
        }
        $pdo->prepare('
            UPDATE recommendation_log SET outcome = :outcome, outcome_reason = COALESCE(:reason, outcome_reason), outcome_at = NOW()
            WHERE id = :id
        ')->execute(['outcome' => $outcome, 'reason' => $reason, 'id' => $id]);
    }

    // Retencja 24 miesiące (docs/etap3 §8) — wołane z cron.php, ten sam
    // wzorzec co pozostałe idempotentne zadania nocne.
    public static function purgeOld(): int
    {
        $stmt = Database::connection()->prepare("
            DELETE FROM recommendation_log WHERE shown_at < DATE_SUB(NOW(), INTERVAL 24 MONTH)
        ");
        $stmt->execute();
        return $stmt->rowCount();
    }
}
