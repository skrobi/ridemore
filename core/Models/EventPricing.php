<?php
// core/Models/EventPricing.php
namespace Models;

use Core\Database;

class EventPricing
{
    public float $amount;
    public string $currencyCode;
    public ?float $depositAmount;
    public ?int $paymentDeadlineDaysBefore;
    public ?int $cancellationDeadlineDaysBefore;
    public ?string $cancellationPolicy;
    public array $items = [];

    public static function findByEventId(int $eventId): ?self
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare("
            SELECT ep.*, cur.code AS currency_code
            FROM event_pricing ep
            JOIN dictionary_items cur ON cur.id = ep.currency_item_id
            WHERE ep.event_id = :event_id
        ");
        $stmt->execute(['event_id' => $eventId]);
        $row = $stmt->fetch();
        if (!$row) return null;

        $p = new self();
        $p->amount = (float) $row['price_amount'];
        $p->currencyCode = $row['currency_code'];
        $p->depositAmount = $row['deposit_amount'] !== null ? (float) $row['deposit_amount'] : null;
        $p->paymentDeadlineDaysBefore = $row['payment_deadline_days_before'];
        $p->cancellationDeadlineDaysBefore = $row['cancellation_deadline_days_before'];
        $p->cancellationPolicy = $row['cancellation_policy'];

        $itemsStmt = $pdo->prepare("
            SELECT epi.description, epi.is_included, cat.name AS category_name
            FROM event_price_items epi
            LEFT JOIN dictionary_items cat ON cat.id = epi.inclusion_category_item_id
            WHERE epi.event_id = :event_id
            ORDER BY epi.sort_order ASC
        ");
        $itemsStmt->execute(['event_id' => $eventId]);
        // category_name to nazwa z rozwiązanego inclusion_category_item_id —
        // fallback na description dotyczy tylko wierszy sprzed tej funkcji
        // (kategoria była wtedy nieużywana, description = jedyny wpisany tekst).
        $p->items = array_map(fn($r) => [
            'category'   => $r['category_name'] ?? $r['description'],
            'isIncluded' => (bool) $r['is_included'],
        ], $itemsStmt->fetchAll());

        return $p;
    }

    // Usuwa istniejący cennik eventu i (gdy $pricing nie jest null) wstawia nowy
    // — spójne z "brak wiersza w event_pricing = darmowa ustawka". Wywoływane
    // wewnątrz transakcji przez Event::save(). event_price_items wskazuje na
    // events.id bezpośrednio (nie przez event_pricing.id), więc kasujemy osobno.
    //
    // $pricing: null (darmowe) albo ['amount','currency' (kod dict),'unit' (kod
    //   dict price_unit),'deposit','paymentDeadlineDays','cancellationDeadlineDays',
    //   'cancellationPolicy', 'items' => [['category','isIncluded'], ...]]
    public static function replaceForEvent(int $eventId, ?array $pricing): void
    {
        $pdo = Database::connection();
        $pdo->prepare('DELETE FROM event_price_items WHERE event_id = :event_id')->execute(['event_id' => $eventId]);
        $pdo->prepare('DELETE FROM event_pricing WHERE event_id = :event_id')->execute(['event_id' => $eventId]);

        if ($pricing === null) {
            return;
        }

        $pdo->prepare('
            INSERT INTO event_pricing
              (event_id, price_amount, currency_item_id, price_unit_item_id, deposit_amount, payment_deadline_days_before, cancellation_deadline_days_before, cancellation_policy)
            VALUES
              (:event_id, :amount, :currency_id, :unit_id, :deposit, :payment_deadline_days, :cancellation_deadline_days, :policy)
        ')->execute([
            'event_id'                   => $eventId,
            'amount'                     => $pricing['amount'],
            'currency_id'                => Dictionary::id('currency', $pricing['currency'] ?? 'PLN'),
            'unit_id'                    => Dictionary::id('price_unit', $pricing['unit'] ?? 'per_person'),
            'deposit'                    => ($pricing['deposit'] ?? null) ?: null,
            'payment_deadline_days'      => ($pricing['paymentDeadlineDays'] ?? null) ?: null,
            'cancellation_deadline_days' => ($pricing['cancellationDeadlineDays'] ?? null) ?: null,
            'policy'                     => ($pricing['cancellationPolicy'] ?? null) ?: null,
        ]);

        $itemStmt = $pdo->prepare('
            INSERT INTO event_price_items (event_id, inclusion_category_item_id, description, is_included, sort_order)
            VALUES (:event_id, :category_id, :description, :is_included, :sort_order)
        ');
        foreach (array_values($pricing['items'] ?? []) as $i => $item) {
            $category = trim($item['category'] ?? '');
            if ($category === '') continue;
            // Pozycja cennika to wyłącznie wybrana/wpisana kategoria (patrz
            // event-form.php + /api/dictionaries/inclusion_category/search) —
            // jeśli nie istnieje jeszcze w słowniku, resolveOrCreate() dokłada
            // ją od razu, dokładnie jak nowy organizator przy zgłaszaniu eventu.
            // description (NOT NULL w schemacie) trzyma tę samą nazwę — nie ma
            // już osobnego wolnego opisu obok kategorii.
            $categoryId = Dictionary::resolveOrCreate('inclusion_category', $category);
            $itemStmt->execute([
                'event_id'    => $eventId,
                'category_id' => $categoryId,
                'description' => $category,
                'is_included' => !empty($item['isIncluded']) ? 1 : 0,
                'sort_order'  => $i,
            ]);
        }
    }

    // Konkretna data, po której samoobsługowe anulowanie jest zablokowane —
    // null gdy brak ograniczenia (cancellationDeadlineDaysBefore nieustawione)
    // albo brak daty startu do policzenia (np. event bez żadnego etapu).
    public function cancellationDeadlineDate(?string $eventStartDate): ?\DateTime
    {
        if ($this->cancellationDeadlineDaysBefore === null || !$eventStartDate) {
            return null;
        }
        return (new \DateTime($eventStartDate))->modify("-{$this->cancellationDeadlineDaysBefore} days");
    }

    // Egzekwowane w web/routes.php (POST /wydarzenia/{slug}/anuluj-udzial) i
    // w CTA na event-page.php — organizator anulujący ręcznie w imieniu
    // uczestnika (patrz /uczestnicy/{userId}/anuluj) NIE jest tym ograniczony,
    // tylko samoobsługa uczestnika.
    public function isCancellationDeadlinePassed(?string $eventStartDate): bool
    {
        $deadline = $this->cancellationDeadlineDate($eventStartDate);
        return $deadline !== null && new \DateTime() > $deadline;
    }
}
