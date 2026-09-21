<?php
// core/Models/Event.php
namespace Models;

use Core\Database;
use Core\Mailer;
use Utils\Format;
use Utils\View;

class Event
{
    public int $id;
    public string $slug;
    public string $title;
    public ?string $description;
    public string $eventTypeCode;
    public string $statusCode;
    public int $organizerId;
    public ?int $minParticipants;
    public ?int $maxParticipants;
    public ?string $meetingPointAddress;
    public ?float $meetingPointLat = null;
    public ?float $meetingPointLng = null;
    public ?string $coverPhotoUrl;
    public ?string $difficultyLabel;
    public ?string $paceGroupLabel;
    public ?string $regionLabel = null;
    // Kod PIERWSZEGO regionu (po sort_order) — pod link do strony regionu (2026-09-14).
    public ?string $regionCode = null;
    public array $bikeTypeLabels = [];
    public array $equipment = [];
    public array $stages = [];
    public ?EventPricing $pricing = null;
    /** @var EventEdition[] */
    public array $editions = [];
    // Warianty trasy (pętle) — puste dla eventów bez wariantów (dotychczasowe
    // zachowanie). Gdy niepuste, zastępują pojedynczą trasę+cenę: uczestnik
    // wybiera wariant przy zapisie. Patrz Models\EventRouteVariant.
    public array $variants = [];
    public Organizer $organizer;
    public string $registrationTypeCode = 'internal';
    public ?string $externalRegistrationUrl = null;
    public ?string $externalRegistrationPhone = null;
    public ?string $externalRegistrationEmail = null;
    public ?string $submitterName = null;
    public ?string $submitterEmail = null;

    public static function findBySlug(string $slug): ?self
    {
        $stmt = Database::connection()->prepare("
            SELECT e.*, det.code AS event_type_code, st.code AS status_code,
                   diff.name AS difficulty_label, pace.name AS pace_group_label,
                   rt.code AS registration_type_code, reg.name AS region_label, reg.code AS region_code
            FROM events e
            JOIN dictionary_items det ON det.id = e.event_type_item_id
            JOIN dictionary_items st ON st.id = e.status_item_id
            LEFT JOIN dictionary_items diff ON diff.id = e.difficulty_item_id
            LEFT JOIN dictionary_items pace ON pace.id = e.pace_group_item_id
            LEFT JOIN dictionary_items rt ON rt.id = e.registration_type_item_id
            LEFT JOIN (
                SELECT er.event_id,
                       GROUP_CONCAT(reg3.name ORDER BY reg3.sort_order SEPARATOR ', ') AS name,
                       SUBSTRING_INDEX(GROUP_CONCAT(reg3.code ORDER BY reg3.sort_order SEPARATOR ','), ',', 1) AS code
                  FROM event_regions er
                  JOIN dictionary_items reg3 ON reg3.id = er.region_item_id
                 GROUP BY er.event_id
            ) reg ON reg.event_id = e.id
            WHERE e.slug = :slug
        ");
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch();
        if (!$row) return null;

        $e = new self();
        $e->id = (int) $row['id'];
        $e->slug = $row['slug'];
        $e->title = $row['title'];
        $e->description = $row['description'];
        $e->eventTypeCode = $row['event_type_code'];
        $e->statusCode = $row['status_code'];
        $e->organizerId = (int) $row['organizer_id'];
        $e->minParticipants = $row['min_participants'];
        $e->maxParticipants = $row['max_participants'];
        $e->meetingPointAddress = $row['meeting_point_address'];
        $e->meetingPointLat = $row['meeting_point_lat'] !== null ? (float) $row['meeting_point_lat'] : null;
        $e->meetingPointLng = $row['meeting_point_lng'] !== null ? (float) $row['meeting_point_lng'] : null;
        $e->coverPhotoUrl = $row['cover_photo_url'];
        $e->difficultyLabel = $row['difficulty_label'];
        $e->paceGroupLabel = $row['pace_group_label'];
        $e->regionLabel = $row['region_label'];
        $e->regionCode = $row['region_code'] ?? null;
        $e->registrationTypeCode = $row['registration_type_code'] ?? 'internal';
        $e->externalRegistrationUrl = $row['external_registration_url'];
        $e->externalRegistrationPhone = $row['external_registration_phone'] ?? null;
        $e->externalRegistrationEmail = $row['external_registration_email'] ?? null;
        $e->submitterName = $row['submitter_name'];
        $e->submitterEmail = $row['submitter_email'];

        $pdo = Database::connection();
        $bikeStmt = $pdo->prepare('
            SELECT di.name FROM event_bike_types ebt
            JOIN dictionary_items di ON di.id = ebt.bike_type_item_id
            WHERE ebt.event_id = :event_id
        ');
        $bikeStmt->execute(['event_id' => $e->id]);
        $e->bikeTypeLabels = array_column($bikeStmt->fetchAll(), 'name');

        // eq.name to fallback dla wierszy sprzed podpięcia słownika 'equipment_item'
        // (patrz Dictionary::resolveOrCreate() w Event::save()) — dla nowych/
        // edytowanych wierszy pokrywa się z nazwą z eqi, ale join daje aktualną
        // nazwę nawet gdy pozycja słownika zostanie później przemianowana.
        $eqStmt = $pdo->prepare('
            SELECT eq.name, eq.is_mandatory, eq.note, eqi.name AS item_name
            FROM event_equipment eq
            LEFT JOIN dictionary_items eqi ON eqi.id = eq.equipment_item_id
            WHERE eq.event_id = :event_id
        ');
        $eqStmt->execute(['event_id' => $e->id]);
        $e->equipment = array_map(fn($r) => [
            'name'        => $r['item_name'] ?? $r['name'],
            'isMandatory' => (bool) $r['is_mandatory'],
            'note'        => $r['note'],
        ], $eqStmt->fetchAll());

        $e->stages = EventStage::findByEventId($e->id);
        $e->pricing = EventPricing::findByEventId($e->id);
        $e->editions = EventEdition::forEvent($e->id);
        $e->variants = EventRouteVariant::findByEventId($e->id);
        $e->organizer = Organizer::findByUserId((int) $row['organizer_id']);

        return $e;
    }

    // Wzorzec powtórzony ~20 razy w web/routes.php: pobierz event po slugu,
    // a gdy nie istnieje — sam wypisz 404 (kod + komunikat) i zwróć null.
    // Wywołujący i tak musi zrobić `if (!$event) return;` (closure nie może
    // przerwać się przez wywołanie funkcji), ale to skraca resztę z 4 linii do 1.
    // Trasy z INNYM zachowaniem na 404 (np. GET /events/{slug} renderujące
    // pełny widok "Nie znaleziono") celowo zostają przy zwykłym findBySlug().
    public static function findBySlugOrFail(string $slug): ?self
    {
        $event = self::findBySlug($slug);
        if (!$event) {
            http_response_code(404);
            echo __('Nie znaleziono wydarzenia.');
        }
        return $event;
    }

    // Ten sam wzorzec co findBySlugOrFail(), tylko odpowiedź JSON — dla
    // api/routes.php (2 miejsca), gdzie zwykły tekst zepsułby kontrakt endpointu.
    public static function findBySlugOrFailJson(string $slug): ?self
    {
        $event = self::findBySlug($slug);
        if (!$event) {
            http_response_code(404);
            echo json_encode(['error' => __('Nie znaleziono')]);
        }
        return $event;
    }

    // Surowe dane pod formularz edycji — kody słownikowe (nie etykiety), zagnieżdżone
    // etapy/sprzęt/cennik. Osobna ścieżka od findBySlug(), bo ten kształt jest
    // specyficzny dla formularza (patrz Resources\EventFormResource).
    public static function findRawById(int $id): ?array
    {
        $pdo = Database::connection();

        $stmt = $pdo->prepare('
            SELECT e.*,
                et.code AS type_code,
                st.code AS status_code,
                rt.code AS registration_type_code,
                dif.code AS difficulty_code,
                pace.code AS pace_code,
                reg.code AS region_code
            FROM events e
            JOIN dictionary_items et ON et.id = e.event_type_item_id
            JOIN dictionary_items st ON st.id = e.status_item_id
            LEFT JOIN dictionary_items rt ON rt.id = e.registration_type_item_id
            LEFT JOIN dictionary_items dif ON dif.id = e.difficulty_item_id
            LEFT JOIN dictionary_items pace ON pace.id = e.pace_group_item_id
            LEFT JOIN (
                SELECT er.event_id,
                       GROUP_CONCAT(reg3.name ORDER BY reg3.sort_order SEPARATOR ", ") AS name,
                       SUBSTRING_INDEX(GROUP_CONCAT(reg3.code ORDER BY reg3.sort_order SEPARATOR ","), ",", 1) AS code
                  FROM event_regions er
                  JOIN dictionary_items reg3 ON reg3.id = er.region_item_id
                 GROUP BY er.event_id
            ) reg ON reg.event_id = e.id
            WHERE e.id = :id
        ');
        $stmt->execute(['id' => $id]);
        $event = $stmt->fetch();
        if (!$event) {
            return null;
        }

        $bikeTypesStmt = $pdo->prepare('
            SELECT di.code FROM event_bike_types ebt
            JOIN dictionary_items di ON di.id = ebt.bike_type_item_id
            WHERE ebt.event_id = :id
        ');
        $bikeTypesStmt->execute(['id' => $id]);
        $event['bike_type_codes'] = $bikeTypesStmt->fetchAll(\PDO::FETCH_COLUMN);

        $equipmentStmt = $pdo->prepare('
            SELECT eq.name, eq.is_mandatory, eqi.name AS item_name
            FROM event_equipment eq
            LEFT JOIN dictionary_items eqi ON eqi.id = eq.equipment_item_id
            WHERE eq.event_id = :id
        ');
        $equipmentStmt->execute(['id' => $id]);
        $event['equipment'] = $equipmentStmt->fetchAll();

        $stagesStmt = $pdo->prepare('
            SELECT es.*, surf.code AS surface_code
            FROM event_stages es
            LEFT JOIN dictionary_items surf ON surf.id = es.surface_item_id
            WHERE es.event_id = :id
            ORDER BY es.day_number ASC
        ');
        $stagesStmt->execute(['id' => $id]);
        $stages = $stagesStmt->fetchAll();

        // Nocleg/posiłki dla WSZYSTKICH etapów naraz (IN (...)) zamiast osobnego
        // zapytania per etap w pętli niżej — dla wycieczki wielodniowej to była
        // 2x liczba etapów dodatkowych round-tripów do bazy.
        $stageIds = array_column($stages, 'id');
        $accommodationByStage = [];
        $mealCodesByStage = [];
        if ($stageIds !== []) {
            $placeholders = implode(',', array_fill(0, count($stageIds), '?'));

            $accommodationStmt = $pdo->prepare("
                SELECT sa.stage_id, sa.name, sa.address, acc.code AS type_code
                FROM event_stage_accommodations sa
                JOIN dictionary_items acc ON acc.id = sa.accommodation_type_item_id
                WHERE sa.stage_id IN ($placeholders)
            ");
            $accommodationStmt->execute($stageIds);
            foreach ($accommodationStmt->fetchAll() as $row) {
                // Zakładamy najwyżej jeden wiersz noclegu per etap (jak oryginalne
                // fetch() pojedyncze) — pierwszy napotkany wygrywa, kolejne pomijamy.
                $stageId = $row['stage_id'];
                unset($row['stage_id']);
                $accommodationByStage[$stageId] ??= $row;
            }

            $mealsStmt = $pdo->prepare("
                SELECT sm.stage_id, meal.code
                FROM event_stage_meals sm
                JOIN dictionary_items meal ON meal.id = sm.meal_type_item_id
                WHERE sm.stage_id IN ($placeholders) AND sm.is_included = 1
            ");
            $mealsStmt->execute($stageIds);
            foreach ($mealsStmt->fetchAll() as $row) {
                $mealCodesByStage[$row['stage_id']][] = $row['code'];
            }
        }

        foreach ($stages as &$stage) {
            $stage['accommodation'] = $accommodationByStage[$stage['id']] ?? null;
            $stage['meal_codes'] = $mealCodesByStage[$stage['id']] ?? [];
        }
        unset($stage);
        $event['stages'] = $stages;

        $pricingStmt = $pdo->prepare('
            SELECT ep.*, cur.code AS currency_code, unit.code AS unit_code
            FROM event_pricing ep
            JOIN dictionary_items cur ON cur.id = ep.currency_item_id
            JOIN dictionary_items unit ON unit.id = ep.price_unit_item_id
            WHERE ep.event_id = :id
        ');
        $pricingStmt->execute(['id' => $id]);
        $pricing = $pricingStmt->fetch();
        if ($pricing) {
            $itemsStmt = $pdo->prepare('
                SELECT epi.description, epi.is_included, cat.name AS category_name
                FROM event_price_items epi
                LEFT JOIN dictionary_items cat ON cat.id = epi.inclusion_category_item_id
                WHERE epi.event_id = :id
                ORDER BY epi.sort_order ASC
            ');
            $itemsStmt->execute(['id' => $id]);
            $pricing['items'] = $itemsStmt->fetchAll();
        }
        $event['pricing'] = $pricing ?: null;

        $event['editions'] = array_map(fn($ed) => [
            'startDate'       => $ed->startDate,
            'endDate'         => $ed->endDate,
            'dateIsFlexible'  => $ed->dateIsFlexible,
            'startTime'       => $ed->startTime,
            'maxParticipants' => $ed->maxParticipants,
            'confirmedCount'  => $ed->confirmedCount,
        ], EventEdition::forEvent($id));

        $variantsStmt = $pdo->prepare('
            SELECT v.*, surf.code AS surface_code
            FROM event_route_variants v
            LEFT JOIN dictionary_items surf ON surf.id = v.surface_item_id
            WHERE v.event_id = :id
            ORDER BY v.sort_order ASC, v.id ASC
        ');
        $variantsStmt->execute(['id' => $id]);
        $event['variants'] = $variantsStmt->fetchAll();

        return $event;
    }

    // Lekka lista pod panel "Moje wydarzenia" / listing admina. $organizerId
    // null => wszystkie wydarzenia (widok admina), inaczej tylko tego organizatora.
    public static function forDashboard(?int $organizerId): array
    {
        $pdo = Database::connection();
        $where = $organizerId !== null ? 'WHERE e.organizer_id = :organizer_id' : '';
        // Suma potwierdzonych ze WSZYSTKICH turnusów naraz — ten widok (tabela
        // admina/organizatora) pokazuje jeden wiersz na wydarzenie, nie per
        // termin; szczegółowy rozkład per turnus jest na stronie "Uczestnicy".
        $confirmedCountSql = self::confirmedCountByEventSql();

        // has_any_rsvp — czy jest choćby JEDEN zapis (dowolny status) na to
        // wydarzenie, niezależnie od turnusu — dokładany tu (nie osobnym
        // zapytaniem per wiersz) pod przycisk "Usuń" w dashboard.php, patrz
        // EventController::delete()/EventRsvp::existsAnyForEvent().
        $stmt = $pdo->prepare("
            SELECT e.id, e.slug, e.title, e.start_date, e.max_participants,
                   e.submitter_name, e.submitter_email, e.custom_attributes,
                   st.code AS status_code, u.name, u.email,
                   (u.password_hash IS NULL) AS organizer_unclaimed,
                   $confirmedCountSql AS confirmed_count,
                   EXISTS (SELECT 1 FROM event_rsvps r2 WHERE r2.event_id = e.id) AS has_any_rsvp,
                   -- Rozbicie stanu płatności — dopisane 2026-08-09 pod kolumnę
                   -- Płatności w dashboard.php (zgłoszenie usera: brakowało
                   -- informacji ILE potwierdzonych, a ILE jeszcze czeka).
                   -- Dotąd ta wiedza żyła wyłącznie w osobnej zakładce
                   -- /admin/platnosci — z listy wydarzeń nie dało się jej poznać.
                   --
                   -- payment_confirmed_at (a nie sam status potwierdzony) jest
                   -- jedynym uczciwym miernikiem zapłacenia: dla wydarzeń
                   -- DARMOWYCH status potwierdzony oznacza po prostu udział,
                   -- bez żadnej wpłaty — patrz Models\EventRsvp::confirmPayment(),
                   -- która stempluje tę kolumnę dopiero przy pełnym rozliczeniu.
                   EXISTS (SELECT 1 FROM event_pricing ep WHERE ep.event_id = e.id) AS is_paid_event,
                   (SELECT COUNT(*) FROM event_rsvps rp
                     WHERE rp.event_id = e.id AND rp.payment_confirmed_at IS NOT NULL
                   ) AS payments_confirmed_count,
                   (SELECT COUNT(*) FROM event_rsvps r3
                      JOIN dictionary_items rdi3 ON rdi3.id = r3.status_item_id
                     WHERE r3.event_id = e.id
                       AND rdi3.code IN ('oczekuje_platnosci','oczekuje_doplaty')
                   ) AS payments_awaiting_count,
                   (SELECT COUNT(*) FROM event_rsvps r4
                      JOIN dictionary_items rdi4 ON rdi4.id = r4.status_item_id
                     WHERE r4.event_id = e.id AND rdi4.code = 'oczekuje_zwrotu'
                   ) AS refunds_awaiting_count,
                   -- Dyskusja pod wydarzeniem — pod wejście do moderacji wprost
                   -- z wiersza panelu (2026-08-09). unanswered liczy TYLKO
                   -- pytania top-level bez odpowiedzi organizatora, czyli realną
                   -- listę zadań; odpowiedź nie potrzebuje odpowiedzi.
                   (SELECT COUNT(*) FROM event_comments cc WHERE cc.event_id = e.id) AS comments_count,
                   (SELECT COUNT(*) FROM event_comments cq
                     WHERE cq.event_id = e.id AND cq.parent_comment_id IS NULL
                       AND cq.is_organizer_reply = 0
                       AND NOT EXISTS (SELECT 1 FROM event_comments cr
                                        WHERE cr.parent_comment_id = cq.id AND cr.is_organizer_reply = 1)
                   ) AS comments_unanswered,
                   -- Suma wszystkiego, co wymaga działania — zostaje pod kafelek
                   -- triage i ostrzeżenie w wierszu (jedna liczba, jedna decyzja).
                   (SELECT COUNT(*) FROM event_rsvps r5
                      JOIN dictionary_items rdi5 ON rdi5.id = r5.status_item_id
                     WHERE r5.event_id = e.id
                       AND rdi5.code IN ('oczekuje_platnosci','oczekuje_doplaty','oczekuje_zwrotu')
                   ) AS pending_payments_count
            FROM events e
            JOIN dictionary_items st ON st.id = e.status_item_id
            JOIN users u ON u.id = e.organizer_id
            $where
            ORDER BY (st.code = 'oczekuje_weryfikacji') DESC, e.start_date ASC
        ");
        $stmt->execute($organizerId !== null ? ['organizer_id' => $organizerId] : []);
        return $stmt->fetchAll();
    }

    // Lekkie zapytanie pod /sitemap-events.xml — tylko slug/updated_at/status,
    // bez żadnych JOIN-ów poza dictionary_items na status (ta trasa nie
    // potrzebuje nic więcej z Event::findBySlug()). 'published', 'full'
    // (komplet uczestników — strona dalej publiczna; dopisane 2026-09-14,
    // pełne wyjazdy wypadały z sitemapy) i 'completed' to statusy z realną,
    // publiczną stroną wartą indeksowania —
    // 'draft'/'oczekuje_weryfikacji'/'cancelled' celowo pominięte.
    public static function allIndexableForSitemap(): array
    {
        $stmt = Database::connection()->query("
            SELECT e.slug, e.updated_at
            FROM events e
            JOIN dictionary_items st ON st.id = e.status_item_id
            WHERE st.code IN ('published', 'full', 'completed')
            ORDER BY e.updated_at DESC
        ");
        return $stmt->fetchAll();
    }

    // Trwałe usunięcie — wywoływać tylko dla eventów w statusie 'draft' (walidacja
    // w warstwie route, spójnie z bramką publikacji w save()). Wszystkie tabele
    // potomne mają ON DELETE CASCADE, więc jedno DELETE wystarcza.
    public static function delete(int $id): void
    {
        Database::connection()->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => $id]);
    }

    // Zatwierdzenie zgłoszenia "w czyimś imieniu" (status 'oczekuje_weryfikacji')
    // → 'published'. Formularz zgłoszenia pokazuje wyłącznie przycisk publikacji
    // (patrz event-form.php, x-show="organizerMode==='self'" ukrywa "zapisz jako
    // szkic" dla zgłoszeń), więc jedyny sensowny stan docelowy po zatwierdzeniu
    // to opublikowane — nie ma czego przywracać z formularza. Guard w WHERE
    // (nie tylko w warstwie route) — bezpieczne wywołanie na dowolnym statusie.
    public static function approveSubmission(int $id): void
    {
        $pdo = Database::connection();
        $pdo->prepare('
            UPDATE events
            SET status_item_id = :published_id, published_at = COALESCE(published_at, NOW())
            WHERE id = :id AND status_item_id = :pending_id
        ')->execute([
            'published_id' => Dictionary::id('event_status', 'published'),
            'pending_id'   => Dictionary::id('event_status', 'oczekuje_weryfikacji'),
            'id'           => $id,
        ]);
    }

    // Odwołanie — zapisy i historia zostają, event tylko znika z Event::upcoming()
    // (ta filtruje po status.code = 'published') i dostaje badge "Odwołane".
    // Dla PŁATNYCH eventów dodatkowo przenosi zapisy do stanów zwrotu, żeby
    // organizator dostał je od razu w kolejce na /admin/platnosci — bez tego
    // odwołanie całego eventu "gubiło" informację, że komuś należy się zwrot
    // (patrz moveRsvpsToRefundOnCancel() niżej).
    public static function cancel(int $id): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('
            SELECT e.title, e.slug, e.start_date, ep.price_amount, cur.code AS currency_code
            FROM events e
            LEFT JOIN event_pricing ep ON ep.event_id = e.id
            LEFT JOIN dictionary_items cur ON cur.id = ep.currency_item_id
            WHERE e.id = :id
        ');
        $stmt->execute(['id' => $id]);
        $event = $stmt->fetch();
        if (!$event) return;

        $isPaid = $event['price_amount'] !== null;
        // Zbieramy WSZYSTKICH, których zapis odwołanie eventu realnie rusza —
        // nie tylko 'potwierdzony', ale też 'oczekuje_platnosci'/'lista_rezerwowa'
        // (moveRsvpsToRefundOnCancel() poniżej anuluje i ich zapisy, mimo że
        // nic jeszcze nie wpłacili — bez tego dowiadywali się tylko z faktu,
        // że event zniknął). Zbierane PRZED przeniesieniem statusów niżej.
        $affectedParticipants = EventRsvp::participantsWithStatuses(
            $id,
            ['potwierdzony', 'oczekuje_platnosci', 'lista_rezerwowa']
        );

        // Update statusu eventu + przeniesienie zapisów do stanów zwrotu to
        // do czterech zależnych zapisów — bez transakcji awaria w połowie
        // zostawia event odwołany, ale część uczestników nie trafia do
        // kolejki zwrotów na /admin/platnosci. Wysyłka maili celowo POZA
        // transakcją: to już tylko powiadomienie, nie cofa danych, gdyby się
        // nie udało (Mailer::sendTemplate() i tak połyka błąd).
        $cancelledId = Dictionary::id('event_status', 'cancelled');
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE events SET status_item_id = :status_id WHERE id = :id')
                ->execute(['status_id' => $cancelledId, 'id' => $id]);

            if ($isPaid) {
                self::moveRsvpsToRefundOnCancel($id);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        self::sendCancellationNotices($affectedParticipants, $event, $isPaid);
    }

    // potwierdzony (opłacone) -> oczekuje_zwrotu, trafiają do kolejki zwrotów.
    // oczekuje_platnosci/lista_rezerwowa -> anulowany od razu, nic nie wpłacone.
    private static function moveRsvpsToRefundOnCancel(int $eventId): void
    {
        $pdo = Database::connection();

        $pdo->prepare('
            UPDATE event_rsvps
            SET status_item_id = :new_id, refund_requested_at = NOW()
            WHERE event_id = :event_id AND status_item_id = :old_id
        ')->execute([
            'new_id'   => Dictionary::id('rsvp_status', 'oczekuje_zwrotu'),
            'old_id'   => Dictionary::id('rsvp_status', 'potwierdzony'),
            'event_id' => $eventId,
        ]);

        $anulowanyId = Dictionary::id('rsvp_status', 'anulowany');
        foreach (['oczekuje_platnosci', 'lista_rezerwowa'] as $code) {
            $pdo->prepare('
                UPDATE event_rsvps SET status_item_id = :new_id
                WHERE event_id = :event_id AND status_item_id = :old_id
            ')->execute([
                'new_id'   => $anulowanyId,
                'old_id'   => Dictionary::id('rsvp_status', $code),
                'event_id' => $eventId,
            ]);
        }
    }

    private static function sendCancellationNotices(array $participants, array $event, bool $isPaid): void
    {
        $eventLink = View::absoluteUrl('/events/' . $event['slug']);
        $eventDateLabel = Format::dateShort($event['start_date']);
        $paidAmountLabel = $isPaid
            ? Format::price((float) $event['price_amount'], Format::currencySymbol($event['currency_code'] ?? 'PLN'))
            : null;
        foreach ($participants as $participant) {
            // Info o zwrocie tylko dla tych, którzy faktycznie mieli potwierdzony
            // (opłacony) udział — reszta nic nie wpłaciła, więc nie ma czego zwracać.
            // sendTemplate() połyka błąd, więc jeden nieudany mail nie przerywa reszty.
            $amountLabel = $participant['status_code'] === 'potwierdzony' ? $paidAmountLabel : null;
            \Core\Lang::with(\Core\Lang::forEmail((string) ($participant['email'])), static fn() => Mailer::sendTemplate('event-cancelled', $participant['email'], __('Wydarzenie „{tytul}" zostało odwołane', ['tytul' => $event['title']]), [
                'recipientName'  => $participant['name'],
                'eventTitle'     => $event['title'],
                'eventDateLabel' => $eventDateLabel,
                'eventLink'      => $eventLink,
                'amountLabel'    => $amountLabel,
            ]));
        }
    }

    // Nie ma w tej apce żadnego cron/harmonogramu — nic nigdy automatycznie nie
    // przechodzi w 'completed'. Wywoływane oportunistycznie przy wejściu na stronę
    // główną (patrz web/routes.php) i dodatkowo z cron.php dla realnego harmonogramu.
    // Dwuetapowo: (1) tani pre-filtr na już zaindeksowanej start_date, (2) dla
    // kandydatów sprawdzenie MAX(stage_date) — wielodniowy event kończy się dopiero
    // po ostatnim dniu, nie po pierwszym. Zwraca ID eventów oznaczonych w tym przebiegu.
    public static function processCompletions(): array
    {
        $pdo = Database::connection();

        // Wydarzenie z wieloma turnusami "kończy się" dopiero, gdy OSTATNI dzień
        // OSTATNIEGO (najpóźniejszego) turnusu jest już w przeszłości — nie po
        // pierwszym. Dzień N-tego etapu w konkretnym turnusie liczony jako
        // edition.start_date + (max_day_number - 1), bo event_stages.stage_date
        // jest absolutną datą tylko dla domyślnego/pierwszego turnusu (patrz
        // Models\EventEdition). events.start_date (zawsze NAJWCZEŚNIEJSZY turnus)
        // zostaje tanim pre-filtrem: kandydat na "zakończone" musi mieć choć
        // jeden turnus już rozpoczęty, zanim w ogóle sprawdzimy, czy WSZYSTKIE się skończyły.
        $newlyCompleted = $pdo->query("
            SELECT e.id FROM events e
            JOIN dictionary_items st ON st.id = e.status_item_id AND st.code IN ('published', 'full')
            JOIN event_editions ed ON ed.event_id = e.id
            JOIN (SELECT event_id, MAX(day_number) AS max_day FROM event_stages GROUP BY event_id) ds ON ds.event_id = e.id
            WHERE e.start_date < CURDATE()
            GROUP BY e.id
            HAVING MAX(DATE_ADD(ed.start_date, INTERVAL (ds.max_day - 1) DAY)) < CURDATE()
        ")->fetchAll(\PDO::FETCH_COLUMN);

        if (!$newlyCompleted) {
            return [];
        }

        $completedId = Dictionary::id('event_status', 'completed');
        $placeholders = implode(',', array_fill(0, count($newlyCompleted), '?'));
        $pdo->prepare("UPDATE events SET status_item_id = ? WHERE id IN ($placeholders)")
            ->execute(array_merge([$completedId], $newlyCompleted));

        foreach ($newlyCompleted as $eventId) {
            self::sendReviewInvitesIfNeeded($eventId);
        }

        return $newlyCompleted;
    }

    private static function sendReviewInvitesIfNeeded(int $eventId): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT title, slug, review_invites_sent_at FROM events WHERE id = :id');
        $stmt->execute(['id' => $eventId]);
        $event = $stmt->fetch();
        if (!$event || $event['review_invites_sent_at'] !== null) {
            return;
        }

        $participants = EventRsvp::confirmedParticipants($eventId);
        $reviewLink = View::absoluteUrl('/wydarzenia/' . $event['slug'] . '/opinia');
        $recapLink  = View::absoluteUrl('/wydarzenia/' . $event['slug'] . '/relacja');
        foreach ($participants as $participant) {
            // "Byłem" — link na STRONĘ wydarzenia z kotwicą, nie do akcji: samo
            // potwierdzenie idzie POST-em (patrz web/routes.php), bo prefetchery
            // klientów pocztowych klikałyby link GET za użytkownika. ?termin=
            // wskazuje TEN turnus, na który dana osoba była zapisana — obecność
            // potwierdza się per termin, nie per wydarzenie.
            $attendanceLink = View::absoluteUrl('/events/' . $event['slug'])
                . '?termin=' . (int) $participant['edition_id'] . '#bylem';

            // Nie przerywamy dla reszty uczestników przez jeden nieudany e-mail —
            // sendTemplate() połyka błąd, najwyżej ten jeden nie dostanie zaproszenia.
            \Core\Lang::with(\Core\Lang::forEmail((string) ($participant['email'])), static fn() => Mailer::sendTemplate('review-invite', $participant['email'], __('Byłeś na „{tytul}"? Potwierdź i wystaw opinię', ['tytul' => $event['title']]), [
                'recipientName'  => $participant['name'],
                'eventTitle'     => $event['title'],
                'attendanceLink' => $attendanceLink,
                'reviewLink'     => $reviewLink,
                'recapLink'      => $recapLink,
            ]));
        }

        $pdo->prepare('UPDATE events SET review_invites_sent_at = NOW() WHERE id = :id')
            ->execute(['id' => $eventId]);
    }

    // Wstępne (niepełne) rezerwacje płatnych eventów, na które nikt nigdy nie
    // zapłacił — bez tego wisiały w 'oczekuje_platnosci' w nieskończoność,
    // dopóki organizator nie zauważył i nie anulował ręcznie. Termin liczony
    // tak samo jak w web/routes.php (joined_at + payment_deadline_days_before,
    // domyślnie 7 dni) — NIE względem daty eventu, w odróżnieniu od
    // cancellation_deadline_days_before. Oportunistycznie z GET / (jak
    // processCompletions()) i z cron.php. Zwraca ID anulowanych zapisów.
    public static function expireStalePendingPayments(): array
    {
        $pdo = Database::connection();

        $stmt = $pdo->query("
            SELECT r.id AS rsvp_id, r.event_id, u.name, u.email, e.title, e.slug
            FROM event_rsvps r
            JOIN events e ON e.id = r.event_id
            JOIN event_pricing ep ON ep.event_id = e.id
            JOIN users u ON u.id = r.user_id
            JOIN dictionary_items rdi ON rdi.id = r.status_item_id AND rdi.code = 'oczekuje_platnosci'
            WHERE r.joined_at < DATE_SUB(NOW(), INTERVAL COALESCE(ep.payment_deadline_days_before, 7) DAY)
        ");
        $stale = $stmt->fetchAll();
        if (!$stale) {
            return [];
        }

        $waitingId = Dictionary::id('rsvp_status', 'oczekuje_platnosci');
        $cancelledId = Dictionary::id('rsvp_status', 'anulowany');
        $expiredIds = array_map(fn($row) => (int) $row['rsvp_id'], $stale);

        // Jeden zbiorczy UPDATE zamiast osobnego per wygasła rezerwacja —
        // wysyłka maila zostaje w pętli (to I/O per-adresat, nie da się zbić
        // w jedno zapytanie SQL, w odróżnieniu od samego UPDATE-u).
        $placeholders = implode(',', array_fill(0, count($expiredIds), '?'));
        $pdo->prepare("
            UPDATE event_rsvps SET status_item_id = ?
            WHERE status_item_id = ? AND id IN ($placeholders)
        ")->execute(array_merge([$cancelledId, $waitingId], $expiredIds));

        foreach ($stale as $row) {
            \Core\Lang::with(\Core\Lang::forEmail((string) ($row['email'])), static fn() => Mailer::sendTemplate('reservation-expired', $row['email'], __('Rezerwacja wygasła: „{tytul}”', ['tytul' => $row['title']]), [
                'recipientName' => $row['name'],
                'eventTitle'    => $row['title'],
                'eventLink'     => View::absoluteUrl('/events/' . $row['slug']),
            ]));
        }

        return $expiredIds;
    }

    // Nadchodzące opublikowane wydarzenia — lekkie wiersze pod listing/karty,
    // bez ładowania pełnych modeli (etapów, organizatora itd.). $filters — patrz
    // buildFilterClauses(). Zwraca ['items' => [...], 'total' => int] (total =
    // liczba pasujących wierszy bez LIMIT, pod "pokazano X z Y" / load more).
    // $viewerId — gdy podany, dokłada do wyniku 'is_joined' (czy zalogowany user ma
    // potwierdzony zapis na dane wydarzenie), do podświetlenia karty na liście.
    public static function upcoming(array $filters = [], int $limit = 24, int $offset = 0, string $sort = 'date', ?int $viewerId = null): array
    {
        [$joins, $where, $params] = self::buildFilterClauses($filters);
        $joinSql  = $joins ? ' ' . implode(' ', $joins) : '';
        $whereSql = $where ? ' AND ' . implode(' AND ', $where) : '';

        // Dystans w linii prostej (Haversine) do współrzędnych przeglądarki —
        // liczony zawsze, gdy je znamy (nie tylko przy sort=near), bo karty
        // (patrz home-event-card.php) pokazują "X km stąd" niezależnie od
        // wybranego sortowania. Tylko w SELECT (ORDER BY odwołuje się do
        // aliasu) — COUNT ich nie potrzebuje, więc te placeholdery nie
        // trafiają tam wcale.
        $distanceSelect = '';
        $itemParams = $params;
        $hasUserLocation = isset($filters['userLat'], $filters['userLng']);
        if ($hasUserLocation) {
            $distanceSelect = ', (6371 * ACOS(LEAST(1, GREATEST(-1,
                    COS(RADIANS(:user_lat1)) * COS(RADIANS(e.meeting_point_lat)) * COS(RADIANS(e.meeting_point_lng) - RADIANS(:user_lng1))
                    + SIN(RADIANS(:user_lat2)) * SIN(RADIANS(e.meeting_point_lat))
                )))) AS distance_from_user_km';
            $itemParams['user_lat1'] = (float) $filters['userLat'];
            $itemParams['user_lat2'] = (float) $filters['userLat'];
            $itemParams['user_lng1'] = (float) $filters['userLng'];
        }

        $joinedSelect = '';
        if ($viewerId !== null) {
            $joinedSelect = self::joinedSelectSql();
            $itemParams['viewer_id'] = $viewerId;
        }

        $orderSql = match (true) {
            $sort === 'near' && $hasUserLocation => 'ORDER BY distance_from_user_km ASC',
            $sort === 'spots' => "ORDER BY (COALESCE(ed.max_participants, 0) - (
                    SELECT COUNT(*) FROM event_rsvps r3
                    JOIN dictionary_items rdi3 ON rdi3.id = r3.status_item_id
                    WHERE r3.edition_id = ed.id AND rdi3.code = 'potwierdzony'
               )) DESC, ed.start_date ASC",
            // Cztery nowe sortowania pod przebudowany /wydarzenia (patrz
            // szablony/wydarzenia.html) — trasa/cena to realne kolumny,
            // "ostatnio dodane" po dacie utworzenia wydarzenia.
            $sort === 'route_asc'  => 'ORDER BY COALESCE(et.total_distance_km, 0) ASC',
            $sort === 'route_desc' => 'ORDER BY COALESCE(et.total_distance_km, 0) DESC',
            // Darmowe (NULL) traktowane jak 0 zł — najtańsze, trafiają na początek.
            $sort === 'price_asc'  => 'ORDER BY COALESCE(ep.price_amount, 0) ASC',
            $sort === 'newest'     => 'ORDER BY e.created_at DESC',
            default => 'ORDER BY ed.start_date ASC',
        };

        $pdo = Database::connection();
        $confirmedCountSql = self::confirmedCountByEditionSql();
        // Jedna karta na wydarzenie, nawet gdy ma wiele turnusów (patrz
        // Models\EventEdition) — JOIN wybiera NAJBLIŻSZY nadchodzący,
        // nieodwołany termin (start_date = MIN(...) spośród kwalifikujących
        // się turnusów tego eventu). Wydarzenie bez ŻADNEGO nadchodzącego
        // turnusu po prostu nie ma z czym się złączyć i znika z listy —
        // odtwarza to dawne "WHERE e.start_date >= CURDATE()", tylko per turnus.
        $editionJoin = "JOIN event_editions ed ON ed.event_id = e.id AND ed.is_cancelled = 0 AND ed.start_date >= CURDATE()
                AND ed.start_date = (
                    SELECT MIN(ed2.start_date) FROM event_editions ed2
                    WHERE ed2.event_id = e.id AND ed2.is_cancelled = 0 AND ed2.start_date >= CURDATE()
                )";
        $editionCountSelect = "(SELECT COUNT(*) FROM event_editions ed3
                WHERE ed3.event_id = e.id AND ed3.is_cancelled = 0 AND ed3.start_date >= CURDATE()) AS upcoming_edition_count";

        $countStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM events e
            JOIN dictionary_items st ON st.id = e.status_item_id AND st.code = 'published'
            $editionJoin
            LEFT JOIN event_totals et ON et.event_id = e.id
            LEFT JOIN event_pricing ep ON ep.event_id = e.id
            $joinSql
            WHERE 1=1 $whereSql
        ");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT
                e.id AS event_id, e.slug, e.title, ed.start_date, ed.end_date, ed.date_is_flexible, ed.start_time,
                ed.max_participants, e.cover_photo_url, e.meeting_point_address,
                card_type.code AS event_type_code, org.name AS organizer_name, org.avatar_url AS organizer_avatar_url,
                card_region.name AS region_name,
                -- Czy jest jakikolwiek plik GPX (etap ALBO wariant) - pod
                -- znacznik na karcie. EXISTS zamiast JOIN: karcie wystarczy
                -- sama informacja jest/nie ma, a JOIN po dwoch tabelach
                -- zwielokrotnilby wiersze wydarzen z kilkoma etapami.
                (EXISTS(SELECT 1 FROM event_stages sg WHERE sg.event_id = e.id AND sg.gpx_url IS NOT NULL)
                  OR EXISTS(SELECT 1 FROM event_route_variants rv WHERE rv.event_id = e.id AND rv.gpx_url IS NOT NULL)
                ) AS has_gpx,
                card_region.code AS region_code,
                card_difficulty.name AS difficulty_label,
                (org_vs.code = 'verified') AS organizer_verified,
                (SELECT GROUP_CONCAT(bdi.name SEPARATOR ', ') FROM event_bike_types ebt2
                    JOIN dictionary_items bdi ON bdi.id = ebt2.bike_type_item_id
                   WHERE ebt2.event_id = e.id) AS bike_type_names,
                COALESCE(et.duration_days, 1)     AS duration_days,
                COALESCE(et.total_distance_km, 0) AS distance_km,
                ep.price_amount, cur.code AS currency_code,
                $confirmedCountSql AS confirmed_count,
                $editionCountSelect
                $distanceSelect
                $joinedSelect
            FROM events e
            JOIN dictionary_items st ON st.id = e.status_item_id AND st.code = 'published'
            JOIN dictionary_items card_type ON card_type.id = e.event_type_item_id
            JOIN users org ON org.id = e.organizer_id
            LEFT JOIN (
                SELECT er.event_id,
                       GROUP_CONCAT(reg3.name ORDER BY reg3.sort_order SEPARATOR ', ') AS name,
                       SUBSTRING_INDEX(GROUP_CONCAT(reg3.code ORDER BY reg3.sort_order SEPARATOR ','), ',', 1) AS code
                  FROM event_regions er
                  JOIN dictionary_items reg3 ON reg3.id = er.region_item_id
                 GROUP BY er.event_id
            ) card_region ON card_region.event_id = e.id
            LEFT JOIN dictionary_items card_difficulty ON card_difficulty.id = e.difficulty_item_id
            LEFT JOIN organizer_profiles org_prof ON org_prof.user_id = org.id
            LEFT JOIN dictionary_items org_vs ON org_vs.id = org_prof.verification_status_item_id
            $editionJoin
            LEFT JOIN event_totals et ON et.event_id = e.id
            LEFT JOIN event_pricing ep ON ep.event_id = e.id
            LEFT JOIN dictionary_items cur ON cur.id = ep.currency_item_id
            $joinSql
            WHERE 1=1 $whereSql
            $orderSql
            LIMIT $limit OFFSET $offset
        ");
        $stmt->execute($itemParams);

        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }

    // Pełne dane KARTY dla wskazanych turnusów — pod Puls (Etap 5).
    //
    // Puls pokazywał wcześniej sam tytuł i datę („suchy tekst", zgłoszenie
    // usera 2026-08-12), podczas gdy reszta serwisu ma bogatą kartę wyjazdu.
    // Zamiast projektować trzecią odmianę karty, dociągamy TEN SAM kształt
    // wiersza co upcoming()/completed() i renderujemy istniejący partial
    // `home-event-card.php` — dystans, trudność, region, cena, wolne miejsca,
    // organizator i okładka pojawiają się za darmo i wyglądają jak wszędzie.
    //
    // Klucz wyniku to edition_id, bo Puls operuje na turnusach: to samo
    // wydarzenie może wystąpić w feedzie dwa razy z różnymi terminami.
    // Świadomie BEZ filtra statusu — Puls pokazuje też wyjazdy zakończone
    // (przejazd, kronika), których upcoming() z definicji nie zwraca.
    public static function cardsForEditions(array $editionIds): array
    {
        if (!$editionIds) {
            return [];
        }
        $in = implode(',', array_fill(0, count($editionIds), '?'));

        $stmt = Database::connection()->prepare("
            SELECT
                e.id AS event_id, e.slug, e.title, ed.id AS edition_id,
                ed.start_date, ed.end_date, ed.date_is_flexible, ed.start_time,
                ed.max_participants, e.cover_photo_url, e.meeting_point_address,
                card_type.code AS event_type_code,
                org.name AS organizer_name, org.avatar_url AS organizer_avatar_url,
                card_region.name AS region_name,
                -- Czy jest jakikolwiek plik GPX (etap ALBO wariant) - pod
                -- znacznik na karcie. EXISTS zamiast JOIN: karcie wystarczy
                -- sama informacja jest/nie ma, a JOIN po dwoch tabelach
                -- zwielokrotnilby wiersze wydarzen z kilkoma etapami.
                (EXISTS(SELECT 1 FROM event_stages sg WHERE sg.event_id = e.id AND sg.gpx_url IS NOT NULL)
                  OR EXISTS(SELECT 1 FROM event_route_variants rv WHERE rv.event_id = e.id AND rv.gpx_url IS NOT NULL)
                ) AS has_gpx,
                card_region.code AS region_code,
                card_difficulty.name AS difficulty_label,
                (org_vs.code = 'verified') AS organizer_verified,
                (SELECT GROUP_CONCAT(bdi.name SEPARATOR ', ') FROM event_bike_types ebt2
                    JOIN dictionary_items bdi ON bdi.id = ebt2.bike_type_item_id
                   WHERE ebt2.event_id = e.id) AS bike_type_names,
                COALESCE(et.duration_days, 1)     AS duration_days,
                COALESCE(et.total_distance_km, 0) AS distance_km,
                ep.price_amount, cur.code AS currency_code,
                (SELECT COUNT(*) FROM event_rsvps r
                   JOIN dictionary_items rdi ON rdi.id = r.status_item_id AND rdi.code = 'potwierdzony'
                  WHERE r.edition_id = ed.id) AS confirmed_count
            FROM event_editions ed
            JOIN events e ON e.id = ed.event_id
            JOIN dictionary_items card_type ON card_type.id = e.event_type_item_id
            JOIN users org ON org.id = e.organizer_id
            LEFT JOIN (
                SELECT er.event_id,
                       GROUP_CONCAT(reg3.name ORDER BY reg3.sort_order SEPARATOR ', ') AS name,
                       SUBSTRING_INDEX(GROUP_CONCAT(reg3.code ORDER BY reg3.sort_order SEPARATOR ','), ',', 1) AS code
                  FROM event_regions er
                  JOIN dictionary_items reg3 ON reg3.id = er.region_item_id
                 GROUP BY er.event_id
            ) card_region ON card_region.event_id = e.id
            LEFT JOIN dictionary_items card_difficulty ON card_difficulty.id = e.difficulty_item_id
            LEFT JOIN organizer_profiles org_prof ON org_prof.user_id = org.id
            LEFT JOIN dictionary_items org_vs ON org_vs.id = org_prof.verification_status_item_id
            LEFT JOIN event_totals et ON et.event_id = e.id
            LEFT JOIN event_pricing ep ON ep.event_id = e.id
            LEFT JOIN dictionary_items cur ON cur.id = ep.currency_item_id
            WHERE ed.id IN ($in)
        ");
        $stmt->execute($editionIds);

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['edition_id']] = \Resources\EventCardResource::fromRow($row);
        }
        return $out;
    }

    // Liczba nadchodzących opublikowanych wydarzeń per region — pod sekcję
    // "Regiony" na stronie głównej (landing). Te same warunki "nadchodzące
    // opublikowane" co upcoming() (bez filtrów), ale bez limitu/JOIN-ów, których
    // tam nie potrzeba (organizator, bike types, ceny). Zwraca tylko regiony
    // z co najmniej jednym wydarzeniem, malejąco po liczbie.
    public static function upcomingCountsByRegion(): array
    {
        // Turnusy (patrz Models\EventEdition) — wydarzenie z kilkoma nadchodzącymi
        // terminami liczy się tu RAZ, tak samo jak w upcoming(): JOIN wybiera tylko
        // edycję z najbliższym startem, inaczej region z aktywnymi "turnusami"
        // dostałby zawyżoną liczbę.
        $stmt = Database::connection()->query("
            SELECT card_region.code, card_region.name, COUNT(*) AS event_count
            FROM events e
            JOIN dictionary_items st ON st.id = e.status_item_id AND st.code = 'published'
            JOIN event_regions er ON er.event_id = e.id
            JOIN dictionary_items card_region ON card_region.id = er.region_item_id
            JOIN event_editions ed ON ed.event_id = e.id AND ed.is_cancelled = 0 AND ed.start_date >= CURDATE()
                AND ed.start_date = (
                    SELECT MIN(ed2.start_date) FROM event_editions ed2
                    WHERE ed2.event_id = e.id AND ed2.is_cancelled = 0 AND ed2.start_date >= CURDATE()
                )
            GROUP BY card_region.id, card_region.code, card_region.name
            ORDER BY event_count DESC
        ");
        return $stmt->fetchAll();
    }

    // Liczby przy opcjach filtrów w panelu bocznym /wydarzenia (patrz
    // szablony/wydarzenia.html) — GLOBALNE dla każdego wymiaru z osobna, nie
    // przeliczane na krzyż względem innych aktualnie wybranych filtrów
    // (prawdziwe fasetowe liczenie — "ile Gravel PRZY już wybranym Wspólna
    // jazda" — wymagałoby osobnego zapytania na każdą kombinację; świadome
    // uproszczenie, patrz komentarz w EventsListController). $column to
    // zawsze literał z kodu wywołującego (nigdy dane z żądania), bezpieczne
    // do wstawienia wprost w SQL.
    private static function upcomingCountsByDictionaryColumn(string $column): array
    {
        $stmt = Database::connection()->query("
            SELECT dit.code, COUNT(*) AS event_count
            FROM events e
            JOIN dictionary_items st ON st.id = e.status_item_id AND st.code = 'published'
            JOIN dictionary_items dit ON dit.id = e.$column
            JOIN event_editions ed ON ed.event_id = e.id AND ed.is_cancelled = 0 AND ed.start_date >= CURDATE()
                AND ed.start_date = (
                    SELECT MIN(ed2.start_date) FROM event_editions ed2
                    WHERE ed2.event_id = e.id AND ed2.is_cancelled = 0 AND ed2.start_date >= CURDATE()
                )
            GROUP BY dit.id, dit.code
        ");
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[$row['code']] = (int) $row['event_count'];
        }
        return $out;
    }

    public static function upcomingCountsByEventType(): array { return self::upcomingCountsByDictionaryColumn('event_type_item_id'); }
    public static function upcomingCountsByDifficulty(): array { return self::upcomingCountsByDictionaryColumn('difficulty_item_id'); }
    public static function upcomingCountsByPace(): array { return self::upcomingCountsByDictionaryColumn('pace_group_item_id'); }

    // Rower to relacja wiele-do-wielu (event_bike_types) — inny kształt
    // zapytania niż powyższe (COUNT(DISTINCT e.id), bo jeden event może mieć
    // kilka typów roweru naraz i JOIN by go zdublował).
    public static function upcomingCountsByBikeType(): array
    {
        $stmt = Database::connection()->query("
            SELECT bdi.code, COUNT(DISTINCT e.id) AS event_count
            FROM events e
            JOIN dictionary_items st ON st.id = e.status_item_id AND st.code = 'published'
            JOIN event_bike_types ebt ON ebt.event_id = e.id
            JOIN dictionary_items bdi ON bdi.id = ebt.bike_type_item_id
            JOIN event_editions ed ON ed.event_id = e.id AND ed.is_cancelled = 0 AND ed.start_date >= CURDATE()
                AND ed.start_date = (
                    SELECT MIN(ed2.start_date) FROM event_editions ed2
                    WHERE ed2.event_id = e.id AND ed2.is_cancelled = 0 AND ed2.start_date >= CURDATE()
                )
            GROUP BY bdi.id, bdi.code
        ");
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[$row['code']] = (int) $row['event_count'];
        }
        return $out;
    }

    // Kubełkowanie w PHP zamiast trzech osobnych COUNT-ów — zbiór nadchodzących
    // wydarzeń jest mały, jeden przelot po wierszach jest tańszy niż trzy
    // zapytania z CASE WHEN.
    public static function upcomingCountsByDurationBucket(): array
    {
        $stmt = Database::connection()->query("
            SELECT COALESCE(et.duration_days, 1) AS d
            FROM events e
            JOIN dictionary_items st ON st.id = e.status_item_id AND st.code = 'published'
            LEFT JOIN event_totals et ON et.event_id = e.id
            JOIN event_editions ed ON ed.event_id = e.id AND ed.is_cancelled = 0 AND ed.start_date >= CURDATE()
                AND ed.start_date = (
                    SELECT MIN(ed2.start_date) FROM event_editions ed2
                    WHERE ed2.event_id = e.id AND ed2.is_cancelled = 0 AND ed2.start_date >= CURDATE()
                )
        ");
        $buckets = ['1' => 0, '2-3' => 0, '4plus' => 0];
        foreach ($stmt->fetchAll() as $row) {
            $d = (int) $row['d'];
            if ($d <= 1) {
                $buckets['1']++;
            } elseif ($d <= 3) {
                $buckets['2-3']++;
            } else {
                $buckets['4plus']++;
            }
        }
        return $buckets;
    }

    public static function upcomingVerifiedOrganizerCount(): int
    {
        return (int) Database::connection()->query("
            SELECT COUNT(*)
            FROM events e
            JOIN dictionary_items st ON st.id = e.status_item_id AND st.code = 'published'
            JOIN organizer_profiles op ON op.user_id = e.organizer_id
            JOIN dictionary_items vs ON vs.id = op.verification_status_item_id AND vs.code = 'verified'
            JOIN event_editions ed ON ed.event_id = e.id AND ed.is_cancelled = 0 AND ed.start_date >= CURDATE()
                AND ed.start_date = (
                    SELECT MIN(ed2.start_date) FROM event_editions ed2
                    WHERE ed2.event_id = e.id AND ed2.is_cancelled = 0 AND ed2.start_date >= CURDATE()
                )
        ")->fetchColumn();
    }

    public static function upcomingHasSpotsCount(): int
    {
        return (int) Database::connection()->query("
            SELECT COUNT(*)
            FROM events e
            JOIN dictionary_items st ON st.id = e.status_item_id AND st.code = 'published'
            JOIN event_editions ed ON ed.event_id = e.id AND ed.is_cancelled = 0 AND ed.start_date >= CURDATE()
                AND ed.start_date = (
                    SELECT MIN(ed2.start_date) FROM event_editions ed2
                    WHERE ed2.event_id = e.id AND ed2.is_cancelled = 0 AND ed2.start_date >= CURDATE()
                )
            WHERE ed.max_participants IS NULL OR ed.max_participants > (
                SELECT COUNT(*) FROM event_rsvps r
                JOIN dictionary_items rdi ON rdi.id = r.status_item_id
                WHERE r.edition_id = ed.id AND rdi.code = 'potwierdzony'
            )
        ")->fetchColumn();
    }

    // Najbliższe nadchodzące opublikowane wydarzenie, którego pierwszy etap ma
    // zapisany profil elewacji — pod sekcję "Trasa dnia" na stronie głównej
    // (landing). Zwraca null, gdy żadne nadchodzące wydarzenie nie ma jeszcze
    // przetworzonego GPX-a (sekcja się wtedy nie renderuje, patrz HomeController).
    public static function firstUpcomingWithElevationProfile(): ?array
    {
        $stmt = Database::connection()->query("
            SELECT e.id AS event_id, e.slug, e.title, e.description, e.cover_photo_url, ed.start_date,
                   es.gpx_url, es.elevation_gain_m, es.distance_km, es.elevation_profile,
                   COALESCE(et.duration_days, 1) AS duration_days,
                   card_region.name AS region_name,
                -- Czy jest jakikolwiek plik GPX (etap ALBO wariant) - pod
                -- znacznik na karcie. EXISTS zamiast JOIN: karcie wystarczy
                -- sama informacja jest/nie ma, a JOIN po dwoch tabelach
                -- zwielokrotnilby wiersze wydarzen z kilkoma etapami.
                (EXISTS(SELECT 1 FROM event_stages sg WHERE sg.event_id = e.id AND sg.gpx_url IS NOT NULL)
                  OR EXISTS(SELECT 1 FROM event_route_variants rv WHERE rv.event_id = e.id AND rv.gpx_url IS NOT NULL)
                ) AS has_gpx,
                   (SELECT GROUP_CONCAT(bdi.name SEPARATOR ' · ') FROM event_bike_types ebt2
                       JOIN dictionary_items bdi ON bdi.id = ebt2.bike_type_item_id
                      WHERE ebt2.event_id = e.id) AS bike_type_names,
                -- Organizator (2026-09-05, karta Trasa dnia dostaje ikonkę
                -- organizatora): TEN SAM trójkąt pól co home-event-card.php
                -- (organizer_name/organizer_avatar_url/organizer_verified),
                -- żeby widok mógł użyć dokładnie tego samego partiala/CSS.
                   org.name AS organizer_name, org.avatar_url AS organizer_avatar_url,
                   org_prof.slug AS organizer_slug,
                   (org_vs.code = 'verified') AS organizer_verified
            FROM events e
            JOIN dictionary_items st ON st.id = e.status_item_id AND st.code = 'published'
            JOIN event_editions ed ON ed.event_id = e.id AND ed.is_cancelled = 0 AND ed.start_date >= CURDATE()
            JOIN event_stages es ON es.event_id = e.id AND es.day_number = 1 AND es.elevation_profile IS NOT NULL
            JOIN users org ON org.id = e.organizer_id
            LEFT JOIN organizer_profiles org_prof ON org_prof.user_id = org.id
            LEFT JOIN dictionary_items org_vs ON org_vs.id = org_prof.verification_status_item_id
            LEFT JOIN (
                SELECT er.event_id,
                       GROUP_CONCAT(reg3.name ORDER BY reg3.sort_order SEPARATOR ', ') AS name,
                       SUBSTRING_INDEX(GROUP_CONCAT(reg3.code ORDER BY reg3.sort_order SEPARATOR ','), ',', 1) AS code
                  FROM event_regions er
                  JOIN dictionary_items reg3 ON reg3.id = er.region_item_id
                 GROUP BY er.event_id
            ) card_region ON card_region.event_id = e.id
            LEFT JOIN event_totals et ON et.event_id = e.id
            ORDER BY ed.start_date ASC
            LIMIT 1
        ");
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $row['elevation_profile'] = json_decode($row['elevation_profile'], true);
        return $row;
    }

    // Plik GPX pierwszego etapu wydarzenia, po ID zamiast "najbliższe
    // nadchodzące" — pod TileController::routeOfDayMap(), który dostaje z
    // adresu wyłącznie klucz `ev-{id}` i musi sam odtworzyć, z czego rysować
    // (patrz firstUpcomingWithElevationProfile() wyżej — TA SAMA kolumna,
    // ten sam warunek day_number = 1).
    public static function firstStageGpxUrl(int $eventId): ?string
    {
        $stmt = Database::connection()->prepare(
            'SELECT gpx_url FROM event_stages WHERE event_id = :id AND day_number = 1'
        );
        $stmt->execute(['id' => $eventId]);
        $url = $stmt->fetchColumn();
        return $url !== false && $url !== null && $url !== '' ? (string) $url : null;
    }

    // Suma potwierdzonych ze WSZYSTKICH turnusów wydarzenia naraz — pod
    // forDashboard() (jeden wiersz na wydarzenie, bez rozbicia per termin).
    // Bez "AS confirmed_count" — dokleja to wywołujący.
    private static function confirmedCountByEventSql(): string
    {
        return "(SELECT COUNT(*) FROM event_rsvps r
                    JOIN dictionary_items rdi ON rdi.id = r.status_item_id
                   WHERE r.event_id = e.id AND rdi.code = 'potwierdzony')";
    }

    // Potwierdzeni dla JEDNEGO, konkretnego turnusu ($editionAlias.id) — limit
    // miejsc i "X zapisanych" na karcie/stronie eventu liczą się OSOBNO per
    // termin, patrz Models\EventEdition. Używane w upcoming()/completed()/
    // forParticipant(), gdzie $editionAlias to alias JOIN-a do event_editions.
    private static function confirmedCountByEditionSql(string $editionAlias = 'ed'): string
    {
        return "(SELECT COUNT(*) FROM event_rsvps r
                    JOIN dictionary_items rdi ON rdi.id = r.status_item_id
                   WHERE r.edition_id = $editionAlias.id AND rdi.code = 'potwierdzony')";
    }

    private static function joinedSelectSql(): string
    {
        return ", EXISTS (
                SELECT 1 FROM event_rsvps rv
                JOIN dictionary_items rvdi ON rvdi.id = rv.status_item_id AND rvdi.code = 'potwierdzony'
                WHERE rv.event_id = e.id AND rv.user_id = :viewer_id
            ) AS is_joined";
    }


    // Lista zakończonych wydarzeń — odpowiednik upcoming() dla chipa "Zakończone"
    // na stronie głównej. Te same filtry (region/trudność/etc.), sortowanie od
    // najświeższych. $viewerId — jak w upcoming(), do podświetlenia kart.
    public static function completed(array $filters = [], int $limit = 24, int $offset = 0, ?int $viewerId = null): array
    {
        [$joins, $where, $params] = self::buildFilterClauses($filters);
        $joinSql  = $joins ? ' ' . implode(' ', $joins) : '';
        $whereSql = $where ? ' AND ' . implode(' AND ', $where) : '';

        $itemParams = $params;
        $joinedSelect = '';
        if ($viewerId !== null) {
            $joinedSelect = self::joinedSelectSql();
            $itemParams['viewer_id'] = $viewerId;
        }

        $pdo = Database::connection();
        $confirmedCountSql = self::confirmedCountByEditionSql();
        // Odpowiednik JOIN-a z upcoming(), tylko odwrotnie: pokazujemy
        // NAJPÓŹNIEJSZY (najświeższy) turnus, który już się odbył — event z
        // kilkoma zakończonymi terminami ma się pojawić raz, z datą ostatniego z nich.
        $editionJoin = "JOIN event_editions ed ON ed.event_id = e.id AND ed.start_date < CURDATE()
                AND ed.start_date = (
                    SELECT MAX(ed2.start_date) FROM event_editions ed2
                    WHERE ed2.event_id = e.id AND ed2.start_date < CURDATE()
                )";

        $countStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM events e
            JOIN dictionary_items st ON st.id = e.status_item_id AND st.code = 'completed'
            $editionJoin
            LEFT JOIN event_totals et ON et.event_id = e.id
            LEFT JOIN event_pricing ep ON ep.event_id = e.id
            $joinSql
            WHERE 1=1 $whereSql
        ");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT
                e.id AS event_id, e.slug, e.title, ed.start_date, ed.end_date, ed.date_is_flexible, ed.start_time,
                ed.max_participants, e.cover_photo_url, e.meeting_point_address,
                card_type.code AS event_type_code, org.name AS organizer_name, org.avatar_url AS organizer_avatar_url,
                card_region.name AS region_name,
                -- Czy jest jakikolwiek plik GPX (etap ALBO wariant) - pod
                -- znacznik na karcie. EXISTS zamiast JOIN: karcie wystarczy
                -- sama informacja jest/nie ma, a JOIN po dwoch tabelach
                -- zwielokrotnilby wiersze wydarzen z kilkoma etapami.
                (EXISTS(SELECT 1 FROM event_stages sg WHERE sg.event_id = e.id AND sg.gpx_url IS NOT NULL)
                  OR EXISTS(SELECT 1 FROM event_route_variants rv WHERE rv.event_id = e.id AND rv.gpx_url IS NOT NULL)
                ) AS has_gpx,
                (SELECT GROUP_CONCAT(bdi.name SEPARATOR ', ') FROM event_bike_types ebt2
                    JOIN dictionary_items bdi ON bdi.id = ebt2.bike_type_item_id
                   WHERE ebt2.event_id = e.id) AS bike_type_names,
                COALESCE(et.duration_days, 1)     AS duration_days,
                COALESCE(et.total_distance_km, 0) AS distance_km,
                ep.price_amount, cur.code AS currency_code,
                $confirmedCountSql AS confirmed_count
                $joinedSelect
            FROM events e
            JOIN dictionary_items st ON st.id = e.status_item_id AND st.code = 'completed'
            JOIN dictionary_items card_type ON card_type.id = e.event_type_item_id
            JOIN users org ON org.id = e.organizer_id
            LEFT JOIN (
                SELECT er.event_id,
                       GROUP_CONCAT(reg3.name ORDER BY reg3.sort_order SEPARATOR ', ') AS name,
                       SUBSTRING_INDEX(GROUP_CONCAT(reg3.code ORDER BY reg3.sort_order SEPARATOR ','), ',', 1) AS code
                  FROM event_regions er
                  JOIN dictionary_items reg3 ON reg3.id = er.region_item_id
                 GROUP BY er.event_id
            ) card_region ON card_region.event_id = e.id
            $editionJoin
            LEFT JOIN event_totals et ON et.event_id = e.id
            LEFT JOIN event_pricing ep ON ep.event_id = e.id
            LEFT JOIN dictionary_items cur ON cur.id = ep.currency_item_id
            $joinSql
            WHERE 1=1 $whereSql
            ORDER BY ed.start_date DESC
            LIMIT $limit OFFSET $offset
        ");
        $stmt->execute($itemParams);

        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }

    // "Moje wydarzenia" — wydarzenia, na które $userId ma potwierdzony zapis.
    // $statusView: 'all' (domyślnie, dowolny status poza szkicem, przeszłe i
    // przyszłe razem) | 'completed' (tylko zakończone — łączenie chipa
    // "Zakończone" z widokiem "Moje", patrz web/routes.php).
    // is_joined zawsze true (stąd stały 1, bez kosztownego EXISTS jak gdzie indziej).
    // W odróżnieniu od upcoming()/completed() (jedna karta na wydarzenie,
    // nawet przy wielu turnusach) — "Moje wydarzenia" pokazuje jeden wiersz
    // PER ZAPIS: jeśli user dołączył do dwóch różnych terminów tego samego
    // wyjazdu (dozwolone, patrz Models\EventRsvp), oba mają się pokazać
    // osobno, każdy ze swoim statusem/terminem płatności.
    public static function forParticipant(int $userId, array $filters = [], int $limit = 24, int $offset = 0, string $statusView = 'all'): array
    {
        [$joins, $where, $params] = self::buildFilterClauses($filters);
        $joinSql  = $joins ? ' ' . implode(' ', $joins) : '';
        $whereSql = $where ? ' AND ' . implode(' AND ', $where) : '';
        $params['viewer_id'] = $userId;

        // "Wszystkie" na liście "Moje wydarzenia" oznacza wszystkie NADCHODZĄCE
        // (spójnie z chipem "Wszystkie" poza "Moje wydarzenia", patrz Event::upcoming())
        // — nie wszystkie zapisy w historii, stąd dodatkowy warunek daty turnusu.
        $statusClause = $statusView === 'completed' ? "st.code = 'completed'" : "st.code IN ('published', 'full')";
        $extraDateWhere = $statusView === 'completed' ? '' : ' AND ed.start_date >= CURDATE()';
        // Rezerwacje oczekujące na płatność mają się rzucać w oczy jako pierwsze
        // (patrz event-results.php/event-card.php — osobny wyróżniony blok nad
        // resztą listy), stąd bias w sortowaniu przed zwykłym porządkiem dat.
        $orderSql = $statusView === 'completed'
            ? 'ORDER BY ed.start_date DESC'
            : "ORDER BY (rdi_my.code = 'oczekuje_platnosci') DESC, ed.start_date ASC";

        $pdo = Database::connection();
        $confirmedCountSql = self::confirmedCountByEditionSql();

        // "Moje wydarzenia" pokazuje TERAZ też rezerwacje oczekujące na płatność
        // (dawniej widoczne były wyłącznie 'potwierdzony' — user nie miał gdzie
        // dokończyć zapisu po opuszczeniu strony eventu). confirmed_count/pula
        // miejsc nadal liczy tylko 'potwierdzony' (patrz SELECT niżej) — bez zmian.
        $countStmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM event_rsvps r
            JOIN dictionary_items rdi_my ON rdi_my.id = r.status_item_id AND rdi_my.code IN ('potwierdzony', 'oczekuje_platnosci', 'zainteresowany')
            JOIN event_editions ed ON ed.id = r.edition_id
            JOIN events e ON e.id = r.event_id
            JOIN dictionary_items st ON st.id = e.status_item_id AND $statusClause
            LEFT JOIN event_totals et ON et.event_id = e.id
            LEFT JOIN event_pricing ep ON ep.event_id = e.id
            $joinSql
            WHERE r.user_id = :viewer_id $whereSql$extraDateWhere
        ");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT
                e.id AS event_id, e.slug, e.title, ed.start_date, ed.end_date, ed.date_is_flexible, ed.start_time,
                ed.max_participants, e.cover_photo_url, e.meeting_point_address,
                card_type.code AS event_type_code, org.name AS organizer_name, org.avatar_url AS organizer_avatar_url,
                card_region.name AS region_name,
                -- Czy jest jakikolwiek plik GPX (etap ALBO wariant) - pod
                -- znacznik na karcie. EXISTS zamiast JOIN: karcie wystarczy
                -- sama informacja jest/nie ma, a JOIN po dwoch tabelach
                -- zwielokrotnilby wiersze wydarzen z kilkoma etapami.
                (EXISTS(SELECT 1 FROM event_stages sg WHERE sg.event_id = e.id AND sg.gpx_url IS NOT NULL)
                  OR EXISTS(SELECT 1 FROM event_route_variants rv WHERE rv.event_id = e.id AND rv.gpx_url IS NOT NULL)
                ) AS has_gpx,
                (SELECT GROUP_CONCAT(bdi.name SEPARATOR ', ') FROM event_bike_types ebt2
                    JOIN dictionary_items bdi ON bdi.id = ebt2.bike_type_item_id
                   WHERE ebt2.event_id = e.id) AS bike_type_names,
                COALESCE(et.duration_days, 1)     AS duration_days,
                COALESCE(et.total_distance_km, 0) AS distance_km,
                ep.price_amount, cur.code AS currency_code,
                $confirmedCountSql AS confirmed_count,
                1 AS is_joined,
                r.edition_id,
                rdi_my.code AS my_rsvp_status
            FROM event_rsvps r
            JOIN dictionary_items rdi_my ON rdi_my.id = r.status_item_id AND rdi_my.code IN ('potwierdzony', 'oczekuje_platnosci', 'zainteresowany')
            JOIN event_editions ed ON ed.id = r.edition_id
            JOIN events e ON e.id = r.event_id
            JOIN dictionary_items st ON st.id = e.status_item_id AND $statusClause
            JOIN dictionary_items card_type ON card_type.id = e.event_type_item_id
            JOIN users org ON org.id = e.organizer_id
            LEFT JOIN (
                SELECT er.event_id,
                       GROUP_CONCAT(reg3.name ORDER BY reg3.sort_order SEPARATOR ', ') AS name,
                       SUBSTRING_INDEX(GROUP_CONCAT(reg3.code ORDER BY reg3.sort_order SEPARATOR ','), ',', 1) AS code
                  FROM event_regions er
                  JOIN dictionary_items reg3 ON reg3.id = er.region_item_id
                 GROUP BY er.event_id
            ) card_region ON card_region.event_id = e.id
            LEFT JOIN event_totals et ON et.event_id = e.id
            LEFT JOIN event_pricing ep ON ep.event_id = e.id
            LEFT JOIN dictionary_items cur ON cur.id = ep.currency_item_id
            $joinSql
            WHERE r.user_id = :viewer_id $whereSql$extraDateWhere
            $orderSql
            LIMIT $limit OFFSET $offset
        ");
        $stmt->execute($params);

        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }

    // Pinezki pod widok mapy na liście wydarzeń — CELOWO osobna od upcoming()/
    // completed()/forParticipant(): mapa nie jest paginowana jak karty (limit 24
    // ukrywałby większość wyników na mapie), więc to zwraca wszystkie pasujące
    // eventy z ustawionym punktem zbiórki (do 500, ochronny limit). $bounds
    // (opcjonalnie) — ['north','south','east','west'] ze widocznego fragmentu
    // mapy, pod przycisk "Szukaj w tym obszarze" (patrz events-list.php).
    public static function mapPins(array $filters, string $statusView, bool $mine, ?int $mineUserId, ?array $bounds = null): array
    {
        [$joins, $where, $params] = self::buildFilterClauses($filters);
        $joinSql = $joins ? ' ' . implode(' ', $joins) : '';
        $whereSql = $where ? ' AND ' . implode(' AND ', $where) : '';

        $extraWhere = ['e.meeting_point_lat IS NOT NULL', 'e.meeting_point_lng IS NOT NULL'];
        if ($bounds !== null) {
            $extraWhere[] = 'e.meeting_point_lat BETWEEN :bounds_south AND :bounds_north';
            $extraWhere[] = 'e.meeting_point_lng BETWEEN :bounds_west AND :bounds_east';
            $params['bounds_south'] = $bounds['south'];
            $params['bounds_north'] = $bounds['north'];
            $params['bounds_west']  = $bounds['west'];
            $params['bounds_east']  = $bounds['east'];
        }

        // Pinezka pokazuje NAJBLIŻSZY kwalifikujący się turnus (jak w
        // upcoming()/completed() — jedna karta/pinezka na wydarzenie nawet przy
        // wielu terminach). Bez tego JOIN-a event, którego PIERWSZY turnus już
        // minął, a kolejny dopiero nadchodzi, zniknąłby z mapy (e.start_date to
        // teraz zawsze najwcześniejsza data spośród wszystkich turnusów).
        if ($statusView === 'completed') {
            $editionJoin = "JOIN event_editions ed ON ed.event_id = e.id AND ed.start_date < CURDATE()
                AND ed.start_date = (
                    SELECT MAX(ed2.start_date) FROM event_editions ed2
                    WHERE ed2.event_id = e.id AND ed2.start_date < CURDATE()
                )";
        } else {
            $editionJoin = "JOIN event_editions ed ON ed.event_id = e.id AND ed.is_cancelled = 0 AND ed.start_date >= CURDATE()
                AND ed.start_date = (
                    SELECT MIN(ed2.start_date) FROM event_editions ed2
                    WHERE ed2.event_id = e.id AND ed2.is_cancelled = 0 AND ed2.start_date >= CURDATE()
                )";
        }

        if ($mine && $mineUserId !== null) {
            // "Wszystkie" oznacza wszystkie NADCHODZĄCE, spójnie z pozostałymi
            // widokami (patrz Event::forParticipant() — ta sama poprawka).
            $statusClause = $statusView === 'completed' ? "st.code = 'completed'" : "st.code IN ('published', 'full')";
            $extraWhere[] = "EXISTS (
                SELECT 1 FROM event_rsvps rv
                JOIN dictionary_items rvdi ON rvdi.id = rv.status_item_id AND rvdi.code = 'potwierdzony'
                WHERE rv.event_id = e.id AND rv.user_id = :viewer_id
            )";
            $params['viewer_id'] = $mineUserId;
        } elseif ($statusView === 'completed') {
            $statusClause = "st.code = 'completed'";
        } else {
            $statusClause = "st.code = 'published'";
        }

        $whereFull = implode(' AND ', $extraWhere) . $whereSql;

        $stmt = Database::connection()->prepare("
            SELECT e.slug, e.title, ed.start_date, e.meeting_point_lat, e.meeting_point_lng,
                COALESCE(et.total_distance_km, 0) AS distance_km,
                (SELECT GROUP_CONCAT(gpx_url SEPARATOR '|') FROM event_stages
                    WHERE event_id = e.id AND gpx_url IS NOT NULL) AS gpx_urls
            FROM events e
            JOIN dictionary_items st ON st.id = e.status_item_id AND $statusClause
            $editionJoin
            LEFT JOIN event_totals et ON et.event_id = e.id
            LEFT JOIN event_pricing ep ON ep.event_id = e.id
            $joinSql
            WHERE $whereFull
            LIMIT 500
        ");
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    // $filters (wszystkie opcjonalne):
    //   organizerId: int        — tylko eventy jednego organizatora (profil publiczny)
    //   eventTypes: string[]    — kody z dict 'event_type'
    //   difficulties: string[]  — kody z dict 'difficulty_level'
    //   paces: string[]         — kody z dict 'pace_group'
    //   bikeTypes: string[]     — kody z dict 'bike_type' (relacja wiele-do-wielu)
    //   regions: string[]       — kody z dict 'region'
    //   paid: 'free'|'paid'
    //   when: 'weekend'|'month'
    //   maxDistanceKm: float
    //   q: string — szuka w tytule, opisie, adresie zbiórki i nazwie regionu
    //   userLat, userLng: float — pozycja z przeglądarki; wyklucza eventy bez
    //     współrzędnych zbiórki. Sortowanie po dystansie tylko przy sort='near'.
    // Zwraca [string[] $joins, string[] $where, array $params] do wklejenia w zapytanie.
    private static function buildFilterClauses(array $filters): array
    {
        $joins  = [];
        $where  = [];
        $params = [];

        if (($filters['organizerId'] ?? null) !== null) {
            $where[] = 'e.organizer_id = :organizer_id_filter';
            $params['organizer_id_filter'] = (int) $filters['organizerId'];
        }

        // JOIN do dict tylko gdy filtr faktycznie użyty (LEFT JOIN — event bez
        // np. przypisanej trudności nie znika z wyników przy innych filtrach).
        $inClause = function (string $alias, string $onColumn, string $paramPrefix, array $codes) use (&$joins, &$where, &$params): void {
            $codes = array_values(array_filter($codes, fn($c) => $c !== ''));
            if (!$codes) return;

            $joins[] = "LEFT JOIN dictionary_items $alias ON $alias.id = e.$onColumn";

            $placeholders = [];
            foreach ($codes as $i => $code) {
                $key = $paramPrefix . $i;
                $placeholders[] = ":$key";
                $params[$key] = $code;
            }
            $where[] = "$alias.code IN (" . implode(',', $placeholders) . ')';
        };

        $inClause('et_type', 'event_type_item_id', 'evtype', $filters['eventTypes'] ?? []);
        $inClause('dif', 'difficulty_item_id', 'dif', $filters['difficulties'] ?? []);
        $inClause('pace', 'pace_group_item_id', 'pace', $filters['paces'] ?? []);

        // Region NIE jest już skalarną kolumną (migr. 074) — event pasuje do
        // filtra, jeśli KTÓRYKOLWIEK z jego regionów jest na liście wybranych.
        $regionCodes = array_values(array_filter($filters['regions'] ?? [], fn($c) => $c !== ''));
        if ($regionCodes) {
            $placeholders = [];
            foreach ($regionCodes as $i => $code) {
                $key = "region_filter$i";
                $placeholders[] = ":$key";
                $params[$key] = $code;
            }
            $where[] = 'EXISTS (
                SELECT 1 FROM event_regions fer
                JOIN dictionary_items fer_di ON fer_di.id = fer.region_item_id
                WHERE fer.event_id = e.id AND fer_di.code IN (' . implode(',', $placeholders) . ')
            )';
        }

        $bikeCodes = array_values(array_filter($filters['bikeTypes'] ?? [], fn($c) => $c !== ''));
        if ($bikeCodes) {
            $placeholders = [];
            foreach ($bikeCodes as $i => $code) {
                $key = "bike$i";
                $placeholders[] = ":$key";
                $params[$key] = $code;
            }
            $where[] = 'EXISTS (
                SELECT 1 FROM event_bike_types ebt
                JOIN dictionary_items bdi ON bdi.id = ebt.bike_type_item_id
                WHERE ebt.event_id = e.id AND bdi.code IN (' . implode(',', $placeholders) . ')
            )';
        }

        if (($filters['paid'] ?? null) === 'free') {
            $where[] = 'ep.price_amount IS NULL';
        } elseif (($filters['paid'] ?? null) === 'paid') {
            $where[] = 'ep.price_amount IS NOT NULL';
        }

        if (($filters['maxDistanceKm'] ?? null) !== null) {
            $where[] = 'COALESCE(et.total_distance_km, 0) <= :max_distance';
            $params['max_distance'] = (float) $filters['maxDistanceKm'];
        }

        $q = trim($filters['q'] ?? '');
        if ($q !== '') {
            // PDO z natywnymi prepared statements (EMULATE_PREPARES=false) nie
            // pozwala użyć tej samej nazwanej wartości wielokrotnie w zapytaniu —
            // stąd osobny placeholder na każde wystąpienie, ta sama wartość.
            $params['q1'] = '%' . $q . '%';
            $params['q2'] = '%' . $q . '%';
            $params['q3'] = '%' . $q . '%';
            $params['q4'] = '%' . $q . '%';
            // Region NIE jest już skalarną kolumną (migr. 074) — szukamy w
            // NAZWACH KTÓREGOKOLWIEK z regionów eventu.
            $where[] = '(e.title LIKE :q1 OR e.description LIKE :q2 OR e.meeting_point_address LIKE :q3 OR EXISTS (
                SELECT 1 FROM event_regions ser
                JOIN dictionary_items ser_di ON ser_di.id = ser.region_item_id
                WHERE ser.event_id = e.id AND ser_di.name LIKE :q4
            ))';
        }

        // EXISTS na event_editions zamiast dawnego e.start_date BETWEEN — event
        // może mieć wiele turnusów (patrz Models\EventEdition), więc "weekend"/
        // "miesiąc" ma dopasować wydarzenie, jeśli JAKIKOLWIEK jego nieodwołany
        // termin wypada w oknie, niezależnie od tego, który konkretnie turnus
        // trafi potem na kartę (to wybiera $editionJoin w upcoming()/completed()).
        $when = $filters['when'] ?? null;
        if ($when === 'weekend') {
            $today = new \DateTimeImmutable('today');
            $dow   = (int) $today->format('N'); // 1=pon .. 7=niedz
            $saturday = $dow === 7 ? $today->modify('-1 day') : $today->modify('+' . (6 - $dow) . ' days');
            $sunday   = $saturday->modify('+1 day');
            $where[]  = "EXISTS (SELECT 1 FROM event_editions wed WHERE wed.event_id = e.id AND wed.is_cancelled = 0 AND wed.start_date BETWEEN :weekend_from AND :weekend_to)";
            $params['weekend_from'] = $saturday->format('Y-m-d');
            $params['weekend_to']   = $sunday->format('Y-m-d');
        } elseif ($when === 'month') {
            $where[] = "EXISTS (SELECT 1 FROM event_editions med WHERE med.event_id = e.id AND med.is_cancelled = 0 AND med.start_date >= CURDATE() AND med.start_date <= :month_end)";
            $params['month_end'] = (new \DateTimeImmutable('today'))->format('Y-m-t');
        }

        // "Blisko mnie" — bez ustalonej lokalizacji zbiórki nie ma czego porównywać.
        if (isset($filters['userLat'], $filters['userLng'])) {
            $where[] = 'e.meeting_point_lat IS NOT NULL AND e.meeting_point_lng IS NOT NULL';
        }

        // Zakres dat "od/do" (przebudowa /wydarzenia, patrz szablony/wydarzenia.html)
        // — jedno EXISTS z oboma warunkami naraz, nie dwa osobne — inaczej dwie
        // różne edycje tego samego wydarzenia mogłyby niezależnie "uzasadnić"
        // każdy z warunków z osobna, mimo że żadna nie mieści się w całym zakresie.
        $dateFrom = $filters['dateFrom'] ?? null;
        $dateTo   = $filters['dateTo'] ?? null;
        if ($dateFrom !== null || $dateTo !== null) {
            $rangeConds = ['rded.event_id = e.id', 'rded.is_cancelled = 0'];
            if ($dateFrom !== null) {
                $rangeConds[] = 'rded.start_date >= :date_from';
                $params['date_from'] = $dateFrom;
            }
            if ($dateTo !== null) {
                $rangeConds[] = 'rded.start_date <= :date_to';
                $params['date_to'] = $dateTo;
            }
            $where[] = 'EXISTS (SELECT 1 FROM event_editions rded WHERE ' . implode(' AND ', $rangeConds) . ')';
        }

        // Długość wyjazdu w dniach — kubełki zamiast dokładnej liczby, bo user
        // myśli "jeden dzień" / "weekend" / "tydzień", nie w konkretnych dniach.
        $durationBuckets = array_values(array_filter($filters['durationBuckets'] ?? [], fn($b) => $b !== ''));
        if ($durationBuckets) {
            $bucketConds = [];
            foreach ($durationBuckets as $bucket) {
                $bucketConds[] = match ($bucket) {
                    '1'     => 'COALESCE(et.duration_days, 1) = 1',
                    '2-3'   => 'COALESCE(et.duration_days, 1) BETWEEN 2 AND 3',
                    '4plus' => 'COALESCE(et.duration_days, 1) >= 4',
                    default => '1=0',
                };
            }
            $where[] = '(' . implode(' OR ', $bucketConds) . ')';
        }

        // Budżet — darmowe wydarzenia (NULL) zawsze przechodzą, niezależnie od
        // ustawionego limitu (to nie jest filtr "tylko płatne do X", tylko
        // "nie więcej niż X, chyba że i tak nic nie płacisz").
        if (($filters['maxPriceAmount'] ?? null) !== null) {
            $where[] = '(ep.price_amount IS NULL OR ep.price_amount <= :max_price)';
            $params['max_price'] = (float) $filters['maxPriceAmount'];
        }

        // Tylko zweryfikowani organizatorzy — osobne aliasy (nie org_vs z
        // upcoming()), bo buildFilterClauses() jest współdzielone z completed()/
        // forParticipant(), które NIE mają tego JOIN-a we własnym zapytaniu.
        if (!empty($filters['verifiedOnly'])) {
            $joins[] = 'LEFT JOIN organizer_profiles fvorg ON fvorg.user_id = e.organizer_id';
            $joins[] = "LEFT JOIN dictionary_items fvstatus ON fvstatus.id = fvorg.verification_status_item_id";
            $where[] = "fvstatus.code = 'verified'";
        }

        // Tylko wydarzenia z wolnymi miejscami — 'ed' to alias turnusu z
        // $editionJoin w upcoming()/completed()/forParticipant(), zawsze
        // obecny w zapytaniu-wywołującym zanim doklejone zostanie WHERE stąd
        // (ten sam wzorzec co maxDistanceKm/duration_days wyżej, patrz et/ep).
        if (!empty($filters['hasSpotsOnly'])) {
            $where[] = "(ed.max_participants IS NULL OR ed.max_participants > (
                SELECT COUNT(*) FROM event_rsvps hsr
                JOIN dictionary_items hsdi ON hsdi.id = hsr.status_item_id
                WHERE hsr.edition_id = ed.id AND hsdi.code = 'potwierdzony'
            ))";
        }

        // Promień od użytkownika (realna odległość geograficzna, w odróżnieniu
        // od maxDistanceKm wyżej, które filtruje DŁUGOŚĆ TRASY) — tylko gdy
        // znamy współrzędne (patrz "Blisko mnie" wyżej), ten sam wzór Haversine
        // co dystans w SELECT (Event::upcoming()) i sort=near.
        if (($filters['radiusKm'] ?? null) !== null && isset($filters['userLat'], $filters['userLng'])) {
            $where[] = "(6371 * ACOS(LEAST(1, GREATEST(-1,
                    COS(RADIANS(:radius_lat1)) * COS(RADIANS(e.meeting_point_lat)) * COS(RADIANS(e.meeting_point_lng) - RADIANS(:radius_lng1))
                    + SIN(RADIANS(:radius_lat2)) * SIN(RADIANS(e.meeting_point_lat))
                )))) <= :radius_km";
            $params['radius_lat1'] = (float) $filters['userLat'];
            $params['radius_lat2'] = (float) $filters['userLat'];
            $params['radius_lng1'] = (float) $filters['userLng'];
            $params['radius_km']   = (float) $filters['radiusKm'];
        }

        return [$joins, $where, $params];
    }

    // Tworzy albo aktualizuje wydarzenie wraz z całą powiązaną strukturą
    // (rowery, sprzęt, etapy z noclegiem/posiłkami, cennik) — jedna transakcja,
    // ten sam kod dla formularza dodawania i edycji. Zwraca slug (nowy albo
    // przeliczony z tytułu przy edycji).
    //
    // $input — patrz kształt w web/routes.php (parseEventFormInput), skrótowo:
    //   existingId (?int), organizerId (int), type/difficulty/pace/region (kody
    //   dict), title, description, coverPhotoUrl, status ('draft'|'published'),
    //   isPaid (bool), registrationType ('internal'|'external'), externalUrl,
    //   meetingPointAddress/Lat/Lng, bikeTypes (string[]), limitParticipants
    //   (bool), min/maxParticipants, equipment ([['name','mandatory'], ...]),
    //   stages (patrz EventStage::replaceForEvent — pierwszy etap MUSI mieć 'date',
    //   to staje się events.start_date), pricing (patrz EventPricing::replaceForEvent,
    //   null gdy !isPaid).
    // Walidacja specyficzna dla pokrec_z_kims (patrz specyfikacja Etapu 1,
    // Zadanie 2.4) — rzucane wyjątki łapane tak samo jak "brak daty
    // wydarzenia" niżej (patrz EventController::create()/update(), oba
    // pokazują treść wyjątku użytkownikowi, jest bezpieczna/pomocna).
    private static function validatePokrecZKims(array $input): void
    {
        $startDate = $input['stages'][0]['date'] ?? null;
        if (!$startDate) {
            throw new \InvalidArgumentException(__('Podaj datę rozpoczęcia.'));
        }
        if ($startDate < date('Y-m-d')) {
            throw new \InvalidArgumentException(__('Data rozpoczęcia nie może być w przeszłości.'));
        }
        $endDate = ($input['endDate'] ?? null) ?: $startDate;
        if ($endDate < $startDate) {
            throw new \InvalidArgumentException(__('Data zakończenia nie może być wcześniejsza niż data rozpoczęcia.'));
        }
        if (empty($input['region'])) {
            throw new \InvalidArgumentException(__('Wybierz region.'));
        }
        if (trim($input['description'] ?? '') === '') {
            throw new \InvalidArgumentException(__('Podaj opis — to najważniejsza informacja dla osób, które mogłyby dołączyć.'));
        }
    }

    /**
     * Regiony eventu (migr. 074) — zastępuje dawne `region_item_id`.
     *
     * DWA ŹRÓDŁA NARAZ, bo lokalnie tylko 7 z 49 wydarzeń ma w ogóle plik GPX
     * (event_stages/event_route_variants) — czyste wyprowadzenie z geometrii,
     * jak przy known_routes, zostawiłoby resztę bez regionu i wyłączyłoby ją
     * z filtrów/dopasowań/powiadomień. Dlatego suma:
     *   1. ręczna deklaracja z formularza (pole "region", jedna pozycja),
     *   2. wszystko, co wynika z GPX etapów i wariantów (Etap 2 może dodać
     *      region, którego organizator nie zaznaczył, np. pętla przez sąsiednie
     *      województwo — nigdy nie odbiera zaznaczonego ręcznie).
     *
     * Usuwa i wstawia od nowa — regionów na event jest garstka.
     */
    private static function syncRegions(int $eventId, ?string $regionCode): void
    {
        $regionIds = [];
        $manualId = $regionCode !== null ? Dictionary::id('region', $regionCode) : null;
        if ($manualId !== null) {
            $regionIds[$manualId] = true;
        }

        $pdo = Database::connection();
        $gpxUrls = [];
        $stmt = $pdo->prepare('SELECT gpx_url FROM event_stages WHERE event_id = :id AND gpx_url IS NOT NULL');
        $stmt->execute(['id' => $eventId]);
        $gpxUrls = array_merge($gpxUrls, $stmt->fetchAll(\PDO::FETCH_COLUMN));
        $stmt = $pdo->prepare('SELECT gpx_url FROM event_route_variants WHERE event_id = :id AND gpx_url IS NOT NULL');
        $stmt->execute(['id' => $eventId]);
        $gpxUrls = array_merge($gpxUrls, $stmt->fetchAll(\PDO::FETCH_COLUMN));

        $cells = [];
        foreach (array_unique($gpxUrls) as $gpxUrl) {
            foreach (RoutePreview::cellsForGpx(CORE_PATH . '/..' . $gpxUrl) as $cellId) {
                $cells[$cellId] = true;
            }
        }
        if ($cells) {
            $placeholders = implode(',', array_fill(0, count($cells), '?'));
            $stmt = $pdo->prepare("SELECT DISTINCT region_item_id FROM region_cells WHERE cell_id IN ($placeholders)");
            $stmt->execute(array_keys($cells));
            foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $regionId) {
                $regionIds[(int) $regionId] = true;
            }
        }

        $pdo->prepare('DELETE FROM event_regions WHERE event_id = :id')->execute(['id' => $eventId]);
        if ($regionIds) {
            $values = rtrim(str_repeat('(?,?),', count($regionIds)), ',');
            $params = [];
            foreach (array_keys($regionIds) as $regionId) { $params[] = $eventId; $params[] = $regionId; }
            $pdo->prepare("INSERT IGNORE INTO event_regions (event_id, region_item_id) VALUES $values")->execute($params);
        }
    }

    public static function save(array $input): string
    {
        $pdo = Database::connection();
        $eventId = $input['existingId'] ?? null;
        $type = $input['type'] ?? 'ustawka';

        if ($type === 'pokrec_z_kims') {
            self::validatePokrecZKims($input);
        }

        $stages = array_values($input['stages'] ?? []);
        $startDate = $stages[0]['date'] ?? null;
        if (!$startDate) {
            throw new \InvalidArgumentException(__('Brak daty wydarzenia (pierwszy etap musi mieć datę).'));
        }

        // Turnusy (patrz Models\EventEdition) — wydarzenie może mieć więcej niż
        // jeden termin wyjazdu, wszystkie po tej samej trasie (event_stages to
        // wspólny szablon dzień 1..N). $startDate to zawsze jeden z terminów,
        // ale niekoniecznie najwcześniejszy (np. organizator dopisał wcześniejszy
        // dodatkowy termin) — events.start_date (pod listowanie/filtry) ma
        // pokazywać NAJBLIŻSZY termin, stąd sort i wybór pierwszego elementu.
        // pokrec_z_kims nigdy nie ma dodatkowych terminów (brak UI do tego,
        // patrz event-form.php) — $editionDates ma tam zawsze dokładnie jeden element.
        $editionDates = array_values(array_unique(array_merge(
            [$startDate],
            array_values(array_filter($input['additionalEditionDates'] ?? []))
        )));
        sort($editionDates);
        $earliestDate = $editionDates[0];

        // Bramka publikacji: płatny event z zapisami wewnętrznymi wymaga
        // kompletnego profilu rozliczeniowego organizatora, inaczej zostaje szkicem
        // — egzekwowane też tu, nie tylko w UI (formularz mógłby to obejść).
        // Dla pokrec_z_kims warunek nigdy nie trafia (isPaid wymuszone niżej na false).
        $statusCode = $input['status'] ?? 'draft';
        if ($statusCode === 'published' && !empty($input['isPaid'])
            && ($input['registrationType'] ?? 'internal') === 'internal'
            && !OrganizerBillingProfile::isComplete($input['organizerId'])) {
            $statusCode = 'draft';
        }

        // Blokada płatności dla pokrec_z_kims (patrz specyfikacja Etapu 1,
        // Zadanie 2.6) — zawsze zapisy wewnętrzne, nigdy cennik, niezależnie
        // od tego, co ewentualnie przyszło w $_POST (formularz i tak nie
        // pokazuje tych pól dla tego typu, ale wymuszamy też tutaj).
        $isPaid = $type === 'pokrec_z_kims' ? false : !empty($input['isPaid']);
        $registrationTypeCode = $type === 'pokrec_z_kims' ? 'internal' : ($input['registrationType'] ?? 'internal');

        // Slug ustalany raz, przy tworzeniu — kolejne edycje tytułu NIE zmieniają
        // adresu, inaczej każdy udostępniony link do wydarzenia by się psuł.
        if ($eventId !== null) {
            $slugStmt = $pdo->prepare('SELECT slug FROM events WHERE id = :id');
            $slugStmt->execute(['id' => $eventId]);
            $slug = $slugStmt->fetchColumn();
            if ($slug === false) {
                throw new \InvalidArgumentException("Nie znaleziono wydarzenia o id=$eventId");
            }
        } else {
            $slug = self::generateUniqueSlug($input['title'], null);
        }

        // published_at ustawiane raz, przy pierwszej publikacji — kolejne zapisy
        // (nawet jeśli event zostaje 'published') nie przesuwają tej daty.
        $publishedAt = null;
        if ($statusCode === 'published') {
            $publishedAt = date('Y-m-d H:i:s');
            if ($eventId !== null) {
                $existing = $pdo->prepare('SELECT published_at FROM events WHERE id = :id');
                $existing->execute(['id' => $eventId]);
                $existingPublishedAt = $existing->fetchColumn();
                if ($existingPublishedAt) {
                    $publishedAt = $existingPublishedAt;
                }
            }
        }

        $fields = [
            'organizer_id'              => $input['organizerId'],
            // Kontakt do zgłaszającego "w czyimś imieniu" (patrz web/routes.php
            // POST /wydarzenia/nowe) — NULL dla zwykłego, samodzielnego zapisu.
            'submitter_name'            => $input['submitterName'] ?? null,
            'submitter_email'           => $input['submitterEmail'] ?? null,
            'event_type_item_id'        => Dictionary::id('event_type', $input['type']),
            'status_item_id'            => Dictionary::id('event_status', $statusCode),
            'registration_type_item_id' => $registrationTypeCode === 'external' ? Dictionary::id('registration_type', 'external') : null,
            'external_registration_url' => $registrationTypeCode === 'external' ? (($input['externalUrl'] ?? null) ?: null) : null,
            'external_registration_phone' => $registrationTypeCode === 'external' ? (($input['externalPhone'] ?? null) ?: null) : null,
            'external_registration_email' => $registrationTypeCode === 'external' ? (($input['externalEmail'] ?? null) ?: null) : null,
            'title'                     => $input['title'],
            'slug'                      => $slug,
            'description'               => ($input['description'] ?? null) ?: null,
            'cover_photo_url'           => $input['coverPhotoUrl'] ?? null,
            'difficulty_item_id'        => Dictionary::id('difficulty_level', $input['difficulty'] ?? null),
            'pace_group_item_id'        => Dictionary::id('pace_group', $input['pace'] ?? null),
            'min_participants'          => !empty($input['limitParticipants']) ? (($input['minParticipants'] ?? null) ?: null) : null,
            'max_participants'          => !empty($input['limitParticipants']) ? (($input['maxParticipants'] ?? null) ?: null) : null,
            'meeting_point_address'     => ($input['meetingPointAddress'] ?? null) ?: null,
            'meeting_point_lat'         => ($input['meetingPointLat'] ?? null) ?: null,
            'meeting_point_lng'         => ($input['meetingPointLng'] ?? null) ?: null,
            'start_date'                => $earliestDate,
            'published_at'              => $publishedAt,
        ];

        // Prowenancja/„furtka" JSON — zapisywana WYŁĄCZNIE, gdy wywołujący
        // faktycznie przekazał klucz (dziś: importer wydarzeń, patrz
        // Models\EventImport). Formularz dodawania/edycji (EventFormInput) go NIE
        // ustawia, więc zwykła edycja nie zawiera tego pola w $fields i UPDATE
        // NIE nadpisze istniejących custom_attributes NULL-em. array_key_exists,
        // nie isset — jawny null też ma być respektowany, gdyby ktoś chciał
        // wyczyścić atrybuty.
        if (array_key_exists('customAttributes', $input)) {
            $ca = $input['customAttributes'];
            $fields['custom_attributes'] = ($ca === null || $ca === [])
                ? null
                : (is_string($ca) ? $ca : json_encode($ca, JSON_UNESCAPED_UNICODE));
        }

        $pdo->beginTransaction();
        try {
            if ($eventId !== null) {
                $setSql = implode(', ', array_map(fn($col) => "$col = :$col", array_keys($fields)));
                $pdo->prepare("UPDATE events SET $setSql WHERE id = :id")
                    ->execute($fields + ['id' => $eventId]);
            } else {
                $cols = implode(', ', array_keys($fields));
                $placeholders = implode(', ', array_map(fn($c) => ":$c", array_keys($fields)));
                $pdo->prepare("INSERT INTO events ($cols) VALUES ($placeholders)")->execute($fields);
                $eventId = (int) $pdo->lastInsertId();
            }

            $pdo->prepare('DELETE FROM event_bike_types WHERE event_id = :id')->execute(['id' => $eventId]);
            $bikeStmt = $pdo->prepare('INSERT INTO event_bike_types (event_id, bike_type_item_id) VALUES (:event_id, :bike_id)');
            foreach ($input['bikeTypes'] ?? [] as $code) {
                $bikeId = Dictionary::id('bike_type', $code);
                if ($bikeId !== null) {
                    $bikeStmt->execute(['event_id' => $eventId, 'bike_id' => $bikeId]);
                }
            }

            $pdo->prepare('DELETE FROM event_equipment WHERE event_id = :id')->execute(['id' => $eventId]);
            $eqStmt = $pdo->prepare('
                INSERT INTO event_equipment (event_id, equipment_item_id, name, is_mandatory)
                VALUES (:event_id, :item_id, :name, :mandatory)
            ');
            foreach ($input['equipment'] ?? [] as $eq) {
                $name = trim($eq['name'] ?? '');
                if ($name === '') continue;
                // Ten sam wzorzec co kategoria pozycji cennika (patrz
                // EventPricing::replaceForEvent) — wpisana/wybrana nazwa jest
                // dopasowywana do istniejącej pozycji słownika 'equipment_item'
                // albo od razu do niej dokładana, jeśli jeszcze nie istnieje.
                $itemId = Dictionary::resolveOrCreate('equipment_item', $name);
                $eqStmt->execute([
                    'event_id'  => $eventId,
                    'item_id'   => $itemId,
                    'name'      => $name,
                    'mandatory' => !empty($eq['mandatory']) ? 1 : 0,
                ]);
            }

            EventStage::replaceForEvent($eventId, $stages);
            EventEdition::replaceForEvent(
                $eventId,
                $editionDates,
                $fields['max_participants'],
                $type === 'pokrec_z_kims' ? (($input['endDate'] ?? null) ?: $startDate) : null,
                $type === 'pokrec_z_kims' && !empty($input['dateIsFlexible']),
                // GODZINA ZBIÓRKI — dla KAŻDEGO typu (poprawka 2026-08-14).
                //
                // Do tej pory warunek brzmiał `$type === 'pokrec_z_kims' ? ... : null`,
                // czyli dokładnie na odwrót niż formularz: kreator pokazuje pole
                // „Godzina zbiórki" WSZYSTKIM typom OPRÓCZ pokrec_z_kims
                // (step-kiedy.php, `x-if="type!=='pokrec_z_kims'"`), a zapis
                // przyjmował ją wyłącznie od pokrec_z_kims. Efekt: organizator
                // wpisywał godzinę, a ta znikała bez śladu — sekcja „Zbiórka"
                // pokazywała samą datę. Zmierzone: 0 z 52 turnusów w bazie miało
                // ustawioną godzinę.
                //
                // `endDate` i `dateIsFlexible` wyżej ZOSTAJĄ warunkowe i to jest
                // poprawne: okno dostępności ma sens tylko przy „Pokręcę z kimś",
                // gdzie termin jest do uzgodnienia. Godzina zbiórki nie ma z tym
                // nic wspólnego — wyjazd o 7:00 to wyjazd o 7:00 niezależnie od
                // typu. Została tu wciągnięta razem z tamtymi przez pomyłkę.
                ($input['startTime'] ?? null) ?: null
            );
            EventPricing::replaceForEvent($eventId, $isPaid ? ($input['pricing'] ?? null) : null);
            // Warianty trasy (pętle) — opcjonalne; pusta tablica = event bez
            // wariantów (jedna trasa event_stages + jeden event_pricing, jak dotąd).
            EventRouteVariant::replaceForEvent($eventId, $input['variants'] ?? []);

            // Region eventu (migr. 074) — SUMA deklaracji organizatora (formularz
            // dalej ma pole „region", bo większość wydarzeń dziś nie ma GPX i nie
            // miałoby skąd wziąć regionu automatycznie) i tego, co faktycznie
            // wynika z przebiegu etapów/wariantów. Musi paść PO obu replaceForEvent
            // powyżej — czyta świeżo zapisane gpx_url.
            self::syncRegions($eventId, $input['region'] ?? null);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return $slug;
    }

    // Powiadomienie o nowym zgłoszeniu "w czyimś imieniu" oczekującym weryfikacji
    // (patrz web/routes.php POST /wydarzenia/nowe) — zawsze do wszystkich adminów,
    // dodatkowo do docelowego organizatora jeśli już ma aktywne konto (ustawione
    // hasło — inaczej i tak nie mógłby się zalogować, żeby to zobaczyć). Błąd
    // wysyłki nie cofa zapisu eventu — wpis jest już zapisany, brak maila tylko
    // opóźnia to, że ktoś się dowie.
    public static function notifySubmissionForReview(string $slug, int $organizerId, ?string $submitterName, ?string $submitterEmail, ?User $submittedByUser): void
    {
        $pdo = Database::connection();

        $eventStmt = $pdo->prepare('SELECT title FROM events WHERE slug = :slug');
        $eventStmt->execute(['slug' => $slug]);
        $eventTitle = $eventStmt->fetchColumn();
        if ($eventTitle === false) {
            return;
        }

        $organizer = User::find($organizerId);
        $contactName  = $submittedByUser ? $submittedByUser->displayName() : ($submitterName ?: 'nieznany');
        $contactEmail = $submittedByUser ? $submittedByUser->email : $submitterEmail;

        $recipients = [];
        foreach ($pdo->query('SELECT email, name FROM users WHERE is_admin = 1')->fetchAll() as $admin) {
            $recipients[$admin['email']] = $admin['name'] ?: $admin['email'];
        }
        if ($organizer && $organizer->isActive()) {
            $recipients[Organizer::notificationEmail($organizer)] = $organizer->displayName();
        }

        foreach ($recipients as $email => $name) {
            \Core\Lang::with(\Core\Lang::forEmail((string) ($email)), static fn() => Mailer::sendTemplate('event-submitted', $email, __('Nowe wydarzenie do weryfikacji: „{tytul}”', ['tytul' => $eventTitle]), [
                'recipientName' => $name,
                'eventTitle'    => $eventTitle,
                'organizerName' => $organizer ? $organizer->displayName() : $contactName,
                'contactName'   => $contactName,
                'contactEmail'  => $contactEmail,
                'eventLink'     => View::absoluteUrl('/events/' . $slug),
                'panelLink'     => View::absoluteUrl('/admin'),
            ]));
        }
    }

    // Powiadomienie dla organizatora, gdy admin doda wydarzenie w jego imieniu —
    // ta ścieżka publikuje się od razu (bez kolejki weryfikacji, patrz
    // $isSelfOrAdmin w web/routes.php), więc bez tego organizator nie miał ŻADNEJ
    // informacji, że coś pojawiło się pod jego nazwiskiem. Tylko do samego
    // organizatora (nie do innych adminów — to rutynowa czynność, nie wymaga
    // ich uwagi) i tylko jeśli już ma aktywne konto (inaczej i tak nie ma jak
    // się zalogować i zobaczyć — patrz osobny mechanizm "link do przejęcia").
    public static function notifyOrganizerAssignedByAdmin(string $slug, int $organizerId, User $admin): void
    {
        $organizer = User::find($organizerId);
        if (!$organizer || !$organizer->isActive()) {
            return;
        }

        $eventStmt = Database::connection()->prepare('SELECT title FROM events WHERE slug = :slug');
        $eventStmt->execute(['slug' => $slug]);
        $eventTitle = $eventStmt->fetchColumn();
        if ($eventTitle === false) {
            return;
        }

        // Błąd wysyłki nie cofa zapisu eventu — wpis jest już zapisany (sendTemplate() połyka błąd).
        \Core\Lang::with(\Core\Lang::forEmail((string) (Organizer::notificationEmail($organizer))), static fn() => Mailer::sendTemplate('event-assigned', Organizer::notificationEmail($organizer), __('Dodano dla Ciebie nowe wydarzenie: „{tytul}”', ['tytul' => $eventTitle]), [
            'recipientName' => $organizer->displayName(),
            'eventTitle'    => $eventTitle,
            'adminName'     => $admin->displayName(),
            'eventLink'     => View::absoluteUrl('/events/' . $slug),
            'panelLink'     => View::absoluteUrl('/admin'),
        ]));
    }

    private static function generateUniqueSlug(string $title, ?int $excludeEventId): string
    {
        $pdo = Database::connection();
        return Format::uniqueSlug($title, 'wydarzenie', function (string $slug) use ($pdo, $excludeEventId): bool {
            $sql = 'SELECT id FROM events WHERE slug = :slug';
            $params = ['slug' => $slug];
            if ($excludeEventId !== null) {
                $sql .= ' AND id != :exclude_id';
                $params['exclude_id'] = $excludeEventId;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return (bool) $stmt->fetch();
        });
    }

    /**
     * Najdłuższy dystans wśród opublikowanych, nadchodzących wyjazdów — górny
     * kres suwaka filtra.
     *
     * Suwak miał do 2026-08-13 sztywne `max=300`, gdzie 300 znaczyło „bez
     * limitu". Ultramaratony (Wisła 1200 to 1200 km) nie wypadały wtedy
     * z wyników, ale wpadały do jednego worka z każdą trasą powyżej 300 km
     * i nie dało się ich odfiltrować — filtr milczał dokładnie tam, gdzie
     * różnica jest największa.
     *
     * Zaokrąglane w górę do pełnych 50 km, żeby kres nie skakał po każdym
     * dodanym wydarzeniu. Dolna granica 300 km zostaje: przy pustej bazie
     * suwak ma dalej sensowny zakres.
     */
    public static function maxDistanceKm(): int
    {
        $max = (int) Database::connection()->query("
            SELECT COALESCE(MAX(et.total_distance_km), 0)
              FROM events e
              JOIN dictionary_items st ON st.id = e.status_item_id AND st.code IN ('published', 'full')
              JOIN event_editions ed ON ed.event_id = e.id AND ed.is_cancelled = 0
                                    AND COALESCE(ed.end_date, ed.start_date) >= CURDATE()
              JOIN event_totals et ON et.event_id = e.id
        ")->fetchColumn();

        return max(300, (int) (ceil($max / 50) * 50));
    }
}
