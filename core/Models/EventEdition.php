<?php
// core/Models/EventEdition.php
// Turnus — konkretny termin wyjazdu w ramach jednego wydarzenia (patrz
// migration_023_event_editions.sql). Itinerarz (Models\EventStage: trasa/
// dystans/GPX/nocleg per dzień) jest WSPÓLNYM szablonem dla wszystkich
// turnusów tego wydarzenia — dzień 1, dzień 2 z event_stages.stage_date
// odpowiada PIERWSZEMU (domyślnemu, "głównemu") terminowi = events.start_date;
// data dnia dla KOLEJNEGO turnusu to stage_date przesunięte o różnicę dni
// między jego start_date a events.start_date, patrz Resources\EventResource.
namespace Models;

use Core\Database;

class EventEdition
{
    public int $id;
    public int $eventId;
    public string $startDate;
    // NULL dla ustawka/wycieczka_wielodniowa — koniec turnusu liczy się wtedy
    // jako startDate + event_totals.duration_days - 1 (patrz Models\Event,
    // EventEdition::effectiveEndDate()). Wypełniona wprost tylko dla
    // pokrec_z_kims (patrz migration_024_pokrec_z_kims.sql).
    public ?string $endDate;
    public bool $dateIsFlexible;
    public ?string $startTime;
    public bool $isCancelled;
    public ?int $maxParticipants;
    public int $confirmedCount;

    // Koniec turnusu do wyświetlenia/filtrowania — jedno miejsce, jedna
    // formuła, niezależnie od typu wydarzenia (patrz komentarz przy $endDate
    // wyżej). $durationDays — z event_totals.duration_days (COUNT(event_stages),
    // zawsze >=1 dzięki COALESCE po stronie zapytań).
    public function effectiveEndDate(int $durationDays): string
    {
        if ($this->endDate !== null) {
            return $this->endDate;
        }
        return date('Y-m-d', strtotime($this->startDate . ' +' . ($durationDays - 1) . ' days'));
    }

    // Wszystkie turnusy eventu, posortowane chronologicznie, z liczbą
    // potwierdzonych zapisów per turnus (limit miejsc liczy się OSOBNO dla
    // każdego terminu — patrz Models\EventRsvp::join()).
    public static function forEvent(int $eventId): array
    {
        $stmt = Database::connection()->prepare("
            SELECT ed.id, ed.event_id, ed.start_date, ed.end_date, ed.date_is_flexible, ed.start_time,
                   ed.is_cancelled, ed.max_participants,
                   (SELECT COUNT(*) FROM event_rsvps r
                       JOIN dictionary_items rdi ON rdi.id = r.status_item_id AND rdi.code = 'potwierdzony'
                      WHERE r.edition_id = ed.id) AS confirmed_count
            FROM event_editions ed
            WHERE ed.event_id = :event_id
            ORDER BY ed.start_date ASC
        ");
        $stmt->execute(['event_id' => $eventId]);
        return array_map([self::class, 'fromRow'], $stmt->fetchAll());
    }

    public static function find(int $id): ?self
    {
        $stmt = Database::connection()->prepare("
            SELECT ed.id, ed.event_id, ed.start_date, ed.end_date, ed.date_is_flexible, ed.start_time,
                   ed.is_cancelled, ed.max_participants,
                   (SELECT COUNT(*) FROM event_rsvps r
                       JOIN dictionary_items rdi ON rdi.id = r.status_item_id AND rdi.code = 'potwierdzony'
                      WHERE r.edition_id = ed.id) AS confirmed_count
            FROM event_editions ed
            WHERE ed.id = :id
        ");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ? self::fromRow($row) : null;
    }

    // Domyślny turnus eventu (najbliższy nadchodzący termin, a jeśli żaden nie
    // jest już przyszły — po prostu najwcześniejszy) — pod CTA na stronie
    // eventu, gdy odwiedzający jeszcze nie wybrał konkretnej daty.
    public static function defaultForEvent(int $eventId): ?self
    {
        $editions = self::forEvent($eventId);
        if (!$editions) {
            return null;
        }
        $today = date('Y-m-d');
        foreach ($editions as $ed) {
            if (!$ed->isCancelled && $ed->startDate >= $today) {
                return $ed;
            }
        }
        return $editions[0];
    }

    private static function fromRow(array $row): self
    {
        $e = new self();
        $e->id = (int) $row['id'];
        $e->eventId = (int) $row['event_id'];
        $e->startDate = $row['start_date'];
        $e->endDate = $row['end_date'] ?? null;
        $e->dateIsFlexible = (bool) ($row['date_is_flexible'] ?? false);
        $e->startTime = $row['start_time'] ?? null;
        $e->isCancelled = (bool) $row['is_cancelled'];
        $e->maxParticipants = $row['max_participants'] !== null ? (int) $row['max_participants'] : null;
        $e->confirmedCount = (int) $row['confirmed_count'];
        return $e;
    }

    // Upsert po (event_id, start_date) — NIE usuwa-i-wstawia-od-nowa jak
    // EventStage::replaceForEvent(), bo event_rsvps.edition_id ma ON DELETE
    // CASCADE: usunięcie i ponowne wstawienie turnusu przy zwykłej edycji
    // wydarzenia (np. poprawka opisu) skasowałoby WSZYSTKIE zapisy na ten
    // termin. Data bez zmian -> ten sam wiersz, zapisy bezpieczne.
    // $startDates: string[] (Y-m-d), pierwszy element = główny termin
    // (events.start_date). Turnus USUNIĘTY z formularza znika tylko wtedy,
    // gdy nikt się jeszcze na niego nie zapisał — inaczej zostaje (ochrona
    // przed cichym skasowaniem czyichś zapisów samą edycją dat w formularzu).
    // $endDate/$dateIsFlexible/$startTime — jak $maxParticipants, aplikowane
    // jednolicie do KAŻDEGO turnusu tego zapisu (pokrec_z_kims nigdy nie ma
    // więcej niż jeden turnus, więc "jednolicie" i "tylko dla tego jednego"
    // znaczą to samo; ustawka/wycieczka_wielodniowa zostawiają je puste/domyślne).
    public static function replaceForEvent(
        int $eventId,
        array $startDates,
        ?int $maxParticipants,
        ?string $endDate = null,
        bool $dateIsFlexible = false,
        ?string $startTime = null
    ): void {
        $pdo = Database::connection();
        $startDates = array_values(array_unique(array_filter($startDates)));
        if (!$startDates) {
            return;
        }

        $upsertStmt = $pdo->prepare('
            INSERT INTO event_editions (event_id, start_date, max_participants, end_date, date_is_flexible, start_time)
            VALUES (:event_id, :start_date, :max_participants, :end_date, :date_is_flexible, :start_time)
            ON DUPLICATE KEY UPDATE max_participants = VALUES(max_participants),
                end_date = VALUES(end_date), date_is_flexible = VALUES(date_is_flexible), start_time = VALUES(start_time)
        ');
        foreach ($startDates as $date) {
            $upsertStmt->execute([
                'event_id'         => $eventId,
                'start_date'       => $date,
                'max_participants' => $maxParticipants,
                'end_date'         => $endDate,
                'date_is_flexible' => $dateIsFlexible ? 1 : 0,
                'start_time'       => $startTime,
            ]);
        }

        $placeholders = implode(',', array_fill(0, count($startDates), '?'));
        $staleStmt = $pdo->prepare("
            SELECT ed.id FROM event_editions ed
            WHERE ed.event_id = ? AND ed.start_date NOT IN ($placeholders)
              AND NOT EXISTS (SELECT 1 FROM event_rsvps r WHERE r.edition_id = ed.id)
        ");
        $staleStmt->execute(array_merge([$eventId], $startDates));
        $staleIds = $staleStmt->fetchAll(\PDO::FETCH_COLUMN);
        if ($staleIds) {
            $ph2 = implode(',', array_fill(0, count($staleIds), '?'));
            $pdo->prepare("DELETE FROM event_editions WHERE id IN ($ph2)")->execute($staleIds);
        }
    }
}
