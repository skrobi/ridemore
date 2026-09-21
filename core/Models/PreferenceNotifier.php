<?php
// core/Models/PreferenceNotifier.php
// Etap 3 (preferencje) §6 — dwa NIEZALEŻNE strumienie podpowiedzi (zwykłe
// i aspiracyjne), oba gatowane przez user_preferences.notify_matches
// (domyślnie wyłączone — zgoda, nie domyślne wciągnięcie).
//
// ETAP 1c PROGRAMU ZACHĘT (2026-09-11) — WŁASNE LIMITY CZĘSTOŚCI ZNIKŁY.
// Do tej daty ten plik pilnował się sam: 7 dni odstępu dla zwykłych, 30 dla
// aspiracyjnych, liczone po `recommendation_log`. Problem nie był w tych
// liczbach, tylko w tym, że NIKT ICH NIE WIDZIAŁ — bramka powiadomień nie
// wiedziała o tych mailach, więc jej limit „2 zachęty tygodniowo" był
// deklaracją, a nie faktem: człowiek mógł dostać dwa powiadomienia z bramki
// i mail stąd, i wszystko formalnie się zgadzało. Od tej zmiany dopasowania
// przechodzą przez `Models\Notifier` jak każda inna zachęta, a odstęp między
// nimi wynika z BUDŻETU, nie z osobnego licznika dni.
//
// `recommendation_log` zostaje nietknięty — to rejestr uczenia dopasowań
// (impresje, wygaszanie aspiracji), a nie licznik powiadomień. Mieszanie tych
// dwóch ról było źródłem całego problemu.
namespace Models;

use Core\Database;
use PDO;
use Utils\View;

class PreferenceNotifier
{
    // Impresja aspiracyjna bez rozstrzygnięcia po tylu dniach liczy się jako
    // zignorowana (docs/etap3 §5 "Wygaszanie aspiracji") — margines na "user
    // zobaczył, ale się waha", zanim uznamy brak reakcji za brak zainteresowania.
    private const IGNORED_IMPRESSION_GRACE_DAYS = 14;
    private const IGNORED_STOP_THRESHOLD = 10;

    // --- Kanał zwykły (docs/etap3 §6, "Powiadomienia zwykłe") -----------

    public static function runRegular(): array
    {
        $pdo = Database::connection();
        $userIds = $pdo->query('SELECT user_id FROM user_preferences WHERE notify_matches = 1')->fetchAll(PDO::FETCH_COLUMN);
        // "Nowo opublikowane" — okno dopasowane do cyklu zadania nocnego
        // (raz dziennie), nie do cooldownu 7-dniowego samego powiadomienia.
        $publishedSince = date('Y-m-d H:i:s', strtotime('-1 day'));

        $sent = [];
        foreach ($userIds as $userId) {
            $userId = (int) $userId;

            $matches = MatchEngine::profileMatchesForUser($userId, $publishedSince, 1);
            if (empty($matches)) {
                continue;
            }
            $match = $matches[0];

            $contact = self::contactFor($pdo, $userId);
            if ($contact === null) {
                continue;
            }

            // Klucz deduplikacji to WYJAZD, nie data — ta sama podpowiedź nie
            // wróci nigdy, nawet gdy zadanie nocne zobaczy ją ponownie.
            // Wcześniej odstęp liczył dni, więc po tygodniu ten sam wyjazd
            // mógł przyjść drugi raz, o ile wciąż był najlepszym dopasowaniem.
            $wyszlo = \Core\Lang::with(\Models\User::langOf((int) ($userId)), static fn() => Notifier::wyslij($userId, NotificationGate::DOPASOWANIE, 'ev:' . $match['eventId'], [], [
                'to'       => $contact['email'],
                'subject'  => NotificationTexts::render('match.mail.subject', [], [], true),
                'template' => 'custom',
                'data'     => [
                    'bodyHtml' => NotificationTexts::render('match.mail.body', [], [
                        'powitanie' => \Utils\MailTemplate::greeting($contact['name']),
                        // POWÓD SKŁADA SILNIK DOPASOWAŃ, nie panel: to zdanie
                        // o KONKRETNYM wyjeździe, a nie szablon. Admin decyduje
                        // tylko, gdzie ma stanąć i co jest wokół niego.
                        'powod'     => NotificationTexts::blokPowod((string) $match['reason']),
                        // KARTA Z FAKTAMI (2026-09-11, zgłoszenie usera: „powinno
                        // być więcej informacji o tym evencie"). Sam powód kazał
                        // klikać, żeby poznać termin i dystans — czyli rzeczy,
                        // które przesądzają, czy w ogóle warto klikać.
                        'karta'     => self::kartaWyjazdu($match),
                        'przycisk'  => \Utils\MailTemplate::button(View::absoluteUrl('/events/' . $match['slug']), __('Zobacz wydarzenie →')),
                    ]),
                ],
            ]));
            if (!$wyszlo['mail']) {
                continue;
            }

            RecommendationLog::record($userId, $match['eventId'], 'notification', $match['score'], 1, false, false, 0.0);
            $sent[] = ['userId' => $userId, 'eventId' => $match['eventId']];
        }
        return $sent;
    }

    // --- Kanał aspiracyjny (docs/etap3 §6, limit niezależny od zwykłego) --

    public static function runAspirational(): array
    {
        self::convertRealizedAspirations();
        self::sweepIgnoredAspirations();

        $pdo = Database::connection();
        $userIds = $pdo->query('SELECT user_id FROM user_preferences WHERE notify_matches = 1')->fetchAll(PDO::FETCH_COLUMN);

        $sent = [];
        foreach ($userIds as $userId) {
            $userId = (int) $userId;

            $match = self::bestActiveAspirationalMatch($pdo, $userId);
            if ($match === null) {
                continue;
            }

            $contact = self::contactFor($pdo, $userId);
            if ($contact === null) {
                continue;
            }

            // Ten sam typ i ten sam wzorzec klucza co w `runRegular()` — dzięki
            // temu wyjazd zaproponowany jako zwykłe dopasowanie nie wróci
            // chwilę później jako aspiracyjne. Wcześniej były to dwa
            // niezależne liczniki dni i taka powtórka była możliwa.
            $wyszlo = \Core\Lang::with(\Models\User::langOf((int) ($userId)), static fn() => Notifier::wyslij($userId, NotificationGate::DOPASOWANIE, 'ev:' . $match['eventId'], [], [
                'to'       => $contact['email'],
                'subject'  => NotificationTexts::render('match_aspirational.mail.subject', [], [], true),
                'template' => 'custom',
                'data'     => [
                    'bodyHtml' => NotificationTexts::render('match_aspirational.mail.body', [], [
                        'powitanie' => \Utils\MailTemplate::greeting($contact['name']),
                        // POWÓD SKŁADA SILNIK DOPASOWAŃ, nie panel: to zdanie
                        // o KONKRETNYM wyjeździe, a nie szablon. Admin decyduje
                        // tylko, gdzie ma stanąć i co jest wokół niego.
                        'powod'     => NotificationTexts::blokPowod((string) $match['reason']),
                        // KARTA Z FAKTAMI (2026-09-11, zgłoszenie usera: „powinno
                        // być więcej informacji o tym evencie"). Sam powód kazał
                        // klikać, żeby poznać termin i dystans — czyli rzeczy,
                        // które przesądzają, czy w ogóle warto klikać.
                        'karta'     => self::kartaWyjazdu($match),
                        'przycisk'  => \Utils\MailTemplate::button(View::absoluteUrl('/events/' . $match['slug']), __('Zobacz wydarzenie →')),
                    ]),
                ],
            ]));
            if (!$wyszlo['mail']) {
                continue;
            }

            RecommendationLog::record($userId, $match['eventId'], 'notification', 0.0, 1, false, true, 0.0);
            $sent[] = ['userId' => $userId, 'eventId' => $match['eventId']];
        }
        return $sent;
    }

    // Realizacja aspiracji (docs/etap3 §5): gdy user ma choćkolwiek
    // potwierdzony udział w wydarzeniu zgodnym z deklaracją aspiracyjną,
    // oś PRZESTAJE być aspiracyjna i zaczyna podlegać zwykłej rampie —
    // zamiar został zrealizowany, dalej rozstrzyga zachowanie.
    public static function convertRealizedAspirations(): int
    {
        $pdo = Database::connection();
        $items = $pdo->query("
            SELECT upi.user_id, upi.dictionary_item_id, d.code AS dict_code
            FROM user_preference_items upi
            JOIN dictionary_items di ON di.id = upi.dictionary_item_id
            JOIN dictionaries d ON d.id = di.dictionary_id
            WHERE upi.kind = 'aspirational'
        ")->fetchAll();

        $converted = 0;
        foreach ($items as $row) {
            $userId = (int) $row['user_id'];
            $itemId = (int) $row['dictionary_item_id'];
            if (!in_array($row['dict_code'], ['region', 'event_type'], true)) {
                continue;
            }

            // Region NIE jest już skalarną kolumną (migr. 074) — sprawdzamy
            // EXISTS na event_regions zamiast równości event_type_item_id.
            $condition = $row['dict_code'] === 'region'
                ? 'EXISTS (SELECT 1 FROM event_regions er WHERE er.event_id = e.id AND er.region_item_id = :item_id)'
                : 'e.event_type_item_id = :item_id';

            $stmt = $pdo->prepare("
                SELECT 1 FROM event_rsvps r
                  JOIN event_editions ed ON ed.id = r.edition_id
                  JOIN events e ON e.id = ed.event_id
                  JOIN dictionary_items rdi ON rdi.id = r.status_item_id AND rdi.code = 'potwierdzony'
                WHERE r.user_id = :user_id AND $condition
                LIMIT 1
            ");
            $stmt->execute(['user_id' => $userId, 'item_id' => $itemId]);
            if (!$stmt->fetchColumn()) {
                continue;
            }

            $pdo->prepare("DELETE FROM user_preference_items WHERE user_id = :u AND dictionary_item_id = :i AND kind = 'aspirational'")
                ->execute(['u' => $userId, 'i' => $itemId]);
            $pdo->prepare("INSERT IGNORE INTO user_preference_items (user_id, dictionary_item_id, kind) VALUES (:u, :i, 'operational')")
                ->execute(['u' => $userId, 'i' => $itemId]);
            $converted++;
        }
        return $converted;
    }

    // Impresje aspiracyjne pokazane, ale nierozstrzygnięte przez
    // IGNORED_IMPRESSION_GRACE_DAYS liczą się jako zignorowane — inkrementuje
    // ignored_count na WŁAŚCIWEJ pozycji deklaracji (region i/lub typ
    // wydarzenia tego konkretnego wydarzenia).
    public static function sweepIgnoredAspirations(): int
    {
        $pdo = Database::connection();
        $stale = $pdo->query('
            SELECT rl.id, rl.user_id, rl.event_id, e.event_type_item_id,
                   (SELECT GROUP_CONCAT(er.region_item_id) FROM event_regions er WHERE er.event_id = e.id) AS region_item_ids
            FROM recommendation_log rl
            JOIN events e ON e.id = rl.event_id
            WHERE rl.is_aspirational = 1 AND rl.outcome IS NULL
              AND rl.shown_at < DATE_SUB(NOW(), INTERVAL ' . self::IGNORED_IMPRESSION_GRACE_DAYS . ' DAY)
        ')->fetchAll();

        foreach ($stale as $row) {
            $pdo->prepare("UPDATE recommendation_log SET outcome = 'ignored', outcome_at = NOW() WHERE id = :id")
                ->execute(['id' => $row['id']]);

            // Region może być kilkoma pozycjami naraz (migr. 074) — kara za
            // zignorowanie trafia w każdą z nich.
            $itemIds = !empty($row['region_item_ids']) ? explode(',', $row['region_item_ids']) : [];
            if ($row['event_type_item_id'] !== null) {
                $itemIds[] = $row['event_type_item_id'];
            }
            foreach ($itemIds as $itemId) {
                $pdo->prepare("
                    UPDATE user_preference_items SET ignored_count = ignored_count + 1
                    WHERE user_id = :u AND dictionary_item_id = :i AND kind = 'aspirational'
                ")->execute(['u' => $row['user_id'], 'i' => $itemId]);
            }
        }
        return count($stale);
    }

    private static function bestActiveAspirationalMatch(PDO $pdo, int $userId): ?array
    {
        $items = $pdo->prepare("
            SELECT upi.dictionary_item_id, upi.ignored_count, d.code AS dict_code
            FROM user_preference_items upi
            JOIN dictionary_items di ON di.id = upi.dictionary_item_id
            JOIN dictionaries d ON d.id = di.dictionary_id
            WHERE upi.user_id = :u AND upi.kind = 'aspirational' AND upi.ignored_count < :stop_threshold
            ORDER BY upi.ignored_count ASC
        ");
        $items->execute(['u' => $userId, 'stop_threshold' => self::IGNORED_STOP_THRESHOLD]);
        $rows = $items->fetchAll();

        foreach ($rows as $row) {
            // Region NIE jest już skalarną kolumną (migr. 074) — EXISTS na
            // event_regions zamiast równości event_type_item_id.
            $condition = $row['dict_code'] === 'region'
                ? 'EXISTS (SELECT 1 FROM event_regions er WHERE er.event_id = e.id AND er.region_item_id = :item_id)'
                : 'e.event_type_item_id = :item_id';
            $stmt = $pdo->prepare("
                SELECT e.id AS event_id, e.slug, e.title
                FROM events e
                JOIN event_editions ed ON ed.event_id = e.id
                JOIN dictionary_items st ON st.id = e.status_item_id AND st.code = 'published'
                WHERE ed.is_cancelled = 0 AND ed.start_date >= CURDATE()
                  AND $condition
                  AND e.organizer_id != :user_id
                  AND NOT EXISTS (SELECT 1 FROM event_rsvps r WHERE r.edition_id = ed.id AND r.user_id = :user_id2)
                  AND NOT EXISTS (SELECT 1 FROM recommendation_dismissals rd WHERE rd.user_id = :user_id3 AND rd.event_id = e.id)
                ORDER BY e.published_at DESC
                LIMIT 1
            ");
            $stmt->execute([
                'item_id' => $row['dictionary_item_id'],
                'user_id' => $userId, 'user_id2' => $userId, 'user_id3' => $userId,
            ]);
            $event = $stmt->fetch();
            if ($event) {
                $label = $row['dict_code'] === 'region' ? __('region, o którym marzysz') : __('typ wyjazdu, który Cię kusi');
                return [
                    'eventId' => (int) $event['event_id'],
                    'slug'    => $event['slug'],
                    'reason'  => __('Pojawił się wyjazd „{tytul}" — zaznaczyłeś/aś, że kusi Cię ten {co}.', ['tytul' => $event['title'], 'co' => $label]),
                ];
            }
        }
        return null;
    }

    // `withinCooldown()` USUNIĘTE w Etapie 1c (2026-09-11). Odstęp między
    // podpowiedziami wynika teraz z budżetu bramki (2 zachęty mailowe na
    // tydzień, 1 na dobę), a to, że ten sam wyjazd nie wróci, gwarantuje klucz
    // deduplikacji `ev:{id}` — czyli RZECZ, a nie upływ dni. Stara metoda
    // czytała `recommendation_log`, mieszając rejestr uczenia dopasowań
    // z licznikiem wysyłek; te dwie role rozjechały się teraz na dobre.

    /**
     * Karta wyjazdu do maila — składana z pól, które `MatchEngine` i tak liczy
     * do rankingu, więc nie kosztuje ani jednego zapytania więcej.
     *
     * Region tłumaczony przez `Dictionary`, bo w wyniku dopasowania są
     * identyfikatory pozycji słownika, a nie nazwy; przy kilku regionach
     * bierzemy PIERWSZY — mail ma być zdaniem, nie listą.
     */
    private static function kartaWyjazdu(array $match): string
    {
        $region = null;
        foreach ($match['regionItemIds'] ?? [] as $itemId) {
            $pozycja = Dictionary::findItem((int) $itemId);
            if ($pozycja !== null && !empty($pozycja['name'])) {
                $region = (string) $pozycja['name'];
                break;
            }
        }

        return NotificationTexts::blokKarta(
            (string) $match['title'],
            self::terminLabel($match['startDate'] ?? null, $match['endDate'] ?? null),
            $region,
            isset($match['distanceKm']) ? (float) $match['distanceKm'] : null,
            (int) ($match['confirmedCount'] ?? 0)
        );
    }

    /** „18 października" albo „18–19 października" — bez roku, bo to zawsze wyjazd przed nami. */
    private static function terminLabel(?string $od, ?string $do): ?string
    {
        if ($od === null || $od === '') {
            return null;
        }
        $start = \Utils\Format::dateP($od);
        if ($do === null || $do === '' || $do === $od) {
            return $start;
        }
        return $start . ' – ' . \Utils\Format::dateP($do);
    }

    private static function contactFor(PDO $pdo, int $userId): ?array
    {
        $stmt = $pdo->prepare('SELECT name, email FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();
        return ($row && !empty($row['email'])) ? $row : null;
    }
}
