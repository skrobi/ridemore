<?php

namespace Models;

use Core\Database;
use Utils\View;

/**
 * POWIADOMIENIE „NOWY SKARB W OKOLICY" (Etap 8 przebudowy apki, 2026-08-28) — wzorzec
 * `Models\PreferenceNotifier`: metoda wołana z `cron.php`, opportunistycznie,
 * bez cursora/tabeli stanu. Okno „od wczoraj" (`created_at >= NOW() -
 * INTERVAL 1 DAY`) zamiast trwałego znacznika czasu — ten sam duch co reszta
 * `cron.php` (`Event::processCompletions()` itd.): nie gwarantuje złapania
 * KAŻDEGO uruchomienia day-by-day, tylko normalnego rytmu codziennego crona.
 *
 * „OKOLICA" — ŚWIADOMIE UPROSZCZONA DEFINICJA: region skarbu dopasowany do
 * regionów, w których user ma choć jedno odkryte pole
 * (`Discovery::regionIdsForUser()`). To region administracyjny (województwo),
 * nie promień geograficzny — grubsze niż „50 km od Ciebie", ale reużywa
 * gotowego, już testowanego podziału zamiast liczyć nową matematykę
 * odległości dla powiadomień.
 *
 * ETAP 1c (2026-09-11) — TO JUŻ NIE JEST WYŁĄCZNIE PUSH. Nazwa klasy została,
 * bo zmiana nazwy dotknęłaby `cron.php`, dokumentacji i testów bez żadnego
 * zysku dla zadania; treść się zmieniła: powiadomienie idzie OBYDWOMA kanałami
 * (`Models\Notifier`), a o tym, który wyjdzie, decyduje zgoda per kanał.
 *
 * NAJWAŻNIEJSZA ZMIANA JEST W ZAPYTANIU, NIE W WYSYŁCE: do tej daty lista
 * odbiorców zaczynała się od `FROM push_devices ... WHERE is_active = 1`,
 * więc zachęta nie miała fizycznie jak dotrzeć do kogokolwiek bez apki.
 * Teraz odbiorcą jest ten, kto ma odkryte pola w regionie skarbu, a kanał
 * rozstrzyga się dopiero per człowiek.
 */
class PushNotifier
{
    public static function runTreasuresNearby(): array
    {
        $db = Database::connection();

        // Okno świeżości z panelu (migr. 083, domyślnie 1 dzień = jak dotąd).
        // Podniesienie ma sens, gdy cron chodzi rzadziej niż codziennie —
        // inaczej nowość „starzeje się" między przebiegami i nie powiadomi
        // nikogo. Wartość jest liczbą z białej listy `NotificationSettings`,
        // przyciętą do 1–30 przy zapisie, więc wchodzi do SQL-a bezpiecznie
        // (`INTERVAL ?` nie działa z parametrem w MySQL).
        $dni = NotificationSettings::getInt('okno.swiezosc_dni');

        $treasures = $db->query("
            SELECT t.id, t.name, t.reveal_level, t.region_item_id, r.name AS region_name
              FROM treasures t
              LEFT JOIN dictionary_items r ON r.id = t.region_item_id
             WHERE t.is_active = 1 AND t.status = 'ACTIVE'
               AND t.region_item_id IS NOT NULL
               AND t.created_at >= NOW() - INTERVAL {$dni} DAY
        ")->fetchAll();

        $notified = [];
        foreach ($treasures as $treasure) {
            // TREŚĆ SZANUJE POZIOM UJAWNIENIA — dokładnie ta sama zasada co
            // wszędzie indziej w tym module (Treasure::reveal()): powiadomienie
            // systemowe nie może zdradzić więcej, niż zdradziłaby mapa temu,
            // kto jeszcze nie odkrył pola. Region ≠ konkretne pole, więc nawet
            // przy poziomie 2 nie podajemy współrzędnych — tylko nazwę.
            //
            // MAIL JEST POD TYM WZGLĘDEM GORSZYM KANAŁEM NIŻ PUSH: zostaje
            // w skrzynce i da się go przeszukać, więc nazwa nieodkrytego skarbu
            // nie może w nim wylądować „na wszelki wypadek". Szablon dostaje
            // `treasureName` na tej samej zasadzie co push swoje `body`.
            // TREŚCI Z PANELU (migr. 084). Wariant tekstu wybiera KOD na
            // podstawie poziomu ujawnienia, a nie szablon — i to jest cała
            // ochrona: wariant „ukryty" nie ma na swojej białej liście
            // znacznika `{nazwa}`, więc nazwa nieodkrytego skarbu nie ma jak
            // się w nim znaleźć, choćby ktoś wpisał ją do pola w panelu.
            $ujawniony = (int) $treasure['reveal_level'] >= 2;
            $wariant   = $ujawniony ? 'jawny' : 'ukryty';
            $znaczniki = [
                'nazwa'  => $ujawniony ? (string) $treasure['name'] : '',
                'region' => (string) ($treasure['region_name'] ?? ''),
            ];
            $body    = NotificationTexts::render('treasure_nearby.push.body.' . $wariant, $znaczniki, [], true);

            // KADR NA SKARBIE TYLKO DLA JAWNEGO (zgłoszenie usera 2026-09-11:
            // „do skarbu nie kieruje, tylko do odkryć"). Skarb nie ma własnej
            // strony — mapa odkryć JEST jego miejscem — ale może ją otworzyć
            // wycentrowaną na okolicy (`DiscoveryController::kadrNaSkarbie`).
            // Przy skarbie ukrytym zostaje goła mapa: adres z identyfikatorem
            // byłby wskazówką, gdzie szukać czegoś, czego mapa nie pokazuje.
            $sciezkaMapy = '/odkrycia' . ($ujawniony ? '?skarb=' . (int) $treasure['id'] : '');
            $mapLink = View::url($sciezkaMapy);

            // ODBIORCY: ten, kto ma odkryte pola w regionie skarbu — BEZ
            // warunku o urządzeniu. Do Etapu 1c zapytanie startowało od
            // `push_devices`, przez co zachęta omijała każdego, kto nie ma
            // apki; kanał rozstrzyga teraz bramka, per człowiek.
            $stmt = $db->prepare('
                SELECT DISTINCT u.id, u.name, u.email
                  FROM users u
                  JOIN discovery_cells dc ON dc.user_id = u.id
                  JOIN region_cells rc ON rc.cell_id = dc.cell_id AND rc.region_item_id = :region_id
                 WHERE NOT EXISTS (
                       SELECT 1 FROM treasure_finds tf
                        WHERE tf.treasure_id = :treasure_id AND tf.user_id = u.id
                   )
            ');
            $stmt->execute([
                'region_id'   => $treasure['region_item_id'],
                'treasure_id' => $treasure['id'],
            ]);

            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $odbiorca) {
                // BRAMKA (2026-09-11, migr. 081 i 082). To jest ZACHĘTA, nie
                // rzecz transakcyjna — podlega więc zgodzie „okolica" (osobnej
                // dla pusha i dla maila), budżetowi liczonemu per kanał
                // i — w przypadku pusha — ciszy nocnej. Klucz to id skarbu,
                // więc ten sam skarb nie wróci, choćby zapytanie wyżej złapało
                // go ponownie przy kolejnym przebiegu.
                //
                // KONSEKWENCJA DLA HARMONOGRAMU: przy nocnym crontabie cisza
                // nocna wycina PUSHOWĄ połowę tego zadania (mailowa wyjdzie —
                // patrz NotificationGate::wolnoZachecac). Zachęty i tak
                // powinny mieć własny, dzienny przebieg — nota w cron.php.
                $userId = (int) $odbiorca['id'];
                $wynik  = \Core\Lang::with(\Models\User::langOf((int) ($userId)), static fn() => Notifier::wyslij(
                    $userId,
                    NotificationGate::SKARB_W_OKOLICY,
                    'tre:' . (int) $treasure['id'],
                    [
                        'title' => NotificationTexts::render('treasure_nearby.push.title.' . $wariant, $znaczniki, [], true),
                        // Treść i adres składane W ŚRODKU wysyłki, nie przed pętlą —
                        // każdy odbiorca dostaje je w swoim języku (Core\Lang::with).
                        'body'  => NotificationTexts::render('treasure_nearby.push.body.' . $wariant, $znaczniki, [], true),
                        'data'  => ['url' => View::url($sciezkaMapy)],
                    ],
                    [
                        'to'       => (string) $odbiorca['email'],
                        'subject'  => NotificationTexts::render('treasure_nearby.mail.subject.' . $wariant, $znaczniki, [], true),
                        // Szablon jest pusty z definicji — całą treść składa
                        // NotificationTexts, łącznie z blokami. Wariant
                        // ujawnienia wybiera TU kod, nie widok.
                        'template' => 'custom',
                        'data'     => [
                            'bodyHtml' => NotificationTexts::render(
                                'treasure_nearby.mail.body.' . $wariant,
                                $znaczniki,
                                [
                                    'powitanie' => \Utils\MailTemplate::greeting($odbiorca['name'] ?: null),
                                    'przycisk'  => \Utils\MailTemplate::button(View::absoluteUrl($sciezkaMapy), __('Zobacz na mapie →')),
                                    'mapa'      => '',
                                ]
                            ),
                        ],
                    ]
                ));

                if ($wynik['push'] || $wynik['mail']) {
                    $notified[] = [
                        'treasure_id' => (int) $treasure['id'],
                        'user_id'     => $userId,
                        'kanaly'      => array_keys(array_filter($wynik)),
                    ];
                }
            }
        }

        return $notified;
    }

    /**
     * ETAP 1b — „NOWOŚĆ W TWOJEJ OKOLICY" (2026-09-11).
     *
     * Nowa znana trasa albo nowo opublikowany wyjazd w regionie, w którym masz
     * odkryte pola. Ten sam wzorzec co `runTreasuresNearby()` i ta sama
     * definicja okolicy — REGION ADMINISTRACYJNY, nie promień od pozycji GPS.
     *
     * DLACZEGO REGION, A NIE PROMIEŃ (pytanie zostawione otwarte w kontrakcie):
     * region liczy się z danych, które już mamy i które user sam wytworzył
     * jeżdżąc (`discovery_cells`). Promień wymagałby przechowywania ostatniej
     * pozycji — nowej kategorii danych osobowych i zmiany w polityce
     * prywatności — żeby powiadomienie było o kilkanaście kilometrów
     * dokładniejsze. To zła wymiana przy zachęcie, którą i tak wolno wysłać
     * dwa razy w tygodniu.
     *
     * ZDARZENIEM JEST POJAWIENIE SIĘ OBIEKTU, nie jego istnienie — klucz
     * deduplikacji to `kr:{id}` / `ev:{id}`, więc ta sama trasa nie wróci
     * nigdy, choćby okno świeżości złapało ją ponownie.
     *
     * @return array<int,array{kind:string,id:int,user_id:int,kanaly:string[]}>
     */
    public static function runNewInRegion(): array
    {
        $db  = Database::connection();
        $dni = NotificationSettings::getInt('okno.swiezosc_dni');

        // DWA ŹRÓDŁA, JEDNO ZAPYTANIE PO ODBIORCÓW. Trasa i wyjazd różnią się
        // tym, co mają do powiedzenia (wyjazd ma termin i skład), ale pytanie
        // „kto jeździ w tym regionie" jest dla obu identyczne.
        $nowosci = [];

        foreach ($db->query("
            SELECT kr.id, kr.name, kr.slug, kr.distance_km, krr.region_item_id, r.name AS region_name
              FROM known_routes kr
              JOIN known_route_regions krr ON krr.route_id = kr.id
              LEFT JOIN dictionary_items r ON r.id = krr.region_item_id
             WHERE kr.is_active = 1
               AND kr.created_at >= NOW() - INTERVAL {$dni} DAY
        ")->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $nowosci[] = ['kind' => 'route'] + $row;
        }

        foreach ($db->query("
            SELECT e.id, e.title AS name, e.slug, er.region_item_id, r.name AS region_name,
                   (SELECT MIN(ed.start_date) FROM event_editions ed WHERE ed.event_id = e.id) AS start_date
              FROM events e
              JOIN dictionary_items st ON st.id = e.status_item_id AND st.code = 'published'
              JOIN event_regions er ON er.event_id = e.id
              LEFT JOIN dictionary_items r ON r.id = er.region_item_id
             WHERE COALESCE(e.published_at, e.created_at) >= NOW() - INTERVAL {$dni} DAY
        ")->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $nowosci[] = ['kind' => 'event'] + $row;
        }

        $notified = [];
        foreach ($nowosci as $nowosc) {
            $kind = $nowosc['kind'];
            $id   = (int) $nowosc['id'];

            // ODBIORCY: ci, którzy mają odkryte pola w regionie nowości —
            // bez warunku o urządzeniu, bo kanał rozstrzyga bramka per człowiek
            // (ta sama poprawka co w Etapie 1c przy skarbach).
            $stmt = $db->prepare('
                SELECT DISTINCT u.id, u.name, u.email
                  FROM users u
                  JOIN discovery_cells dc ON dc.user_id = u.id
                  JOIN region_cells rc ON rc.cell_id = dc.cell_id AND rc.region_item_id = :region_id
            ');
            $stmt->execute(['region_id' => $nowosc['region_item_id']]);

            $sciezka   = $kind === 'route' ? '/trasy/' . $nowosc['slug'] : '/events/' . $nowosc['slug'];
            $powiadom  = $kind === 'route' ? 'nearby_route' : 'nearby_event';
            $znaczniki = [
                'nazwa'  => (string) $nowosc['name'],
                'region' => (string) ($nowosc['region_name'] ?? ''),
            ];

            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $odbiorca) {
                $userId = (int) $odbiorca['id'];
                $wynik  = \Core\Lang::with(\Models\User::langOf((int) ($userId)), static fn() => Notifier::wyslij(
                    $userId,
                    NotificationGate::NOWOSC_W_OKOLICY,
                    ($kind === 'route' ? 'kr:' : 'ev:') . $id,
                    [
                        'title' => NotificationTexts::render($powiadom . '.push.title', $znaczniki, [], true),
                        'body'  => NotificationTexts::render($powiadom . '.push.body', $znaczniki, [], true),
                        'data'  => ['url' => View::url($sciezka)],
                    ],
                    [
                        'to'       => (string) $odbiorca['email'],
                        'subject'  => NotificationTexts::render($powiadom . '.mail.subject', $znaczniki, [], true),
                        'template' => 'custom',
                        'data'     => [
                            'bodyHtml' => NotificationTexts::render($powiadom . '.mail.body', $znaczniki, [
                                'powitanie' => \Utils\MailTemplate::greeting($odbiorca['name'] ?: null),
                                'przycisk'  => \Utils\MailTemplate::button(
                                    View::absoluteUrl($sciezka),
                                    $kind === 'route' ? __('Zobacz trasę →') : __('Zobacz wydarzenie →')
                                ),
                                'karta'     => NotificationTexts::blokKarta(
                                    (string) $nowosc['name'],
                                    $kind === 'event' ? \Utils\Format::dateP($nowosc['start_date'] ?? null) : null,
                                    $nowosc['region_name'] ?: null,
                                    isset($nowosc['distance_km']) ? (float) $nowosc['distance_km'] : null,
                                    0
                                ),
                            ]),
                        ],
                    ]
                ));

                if ($wynik['push'] || $wynik['mail']) {
                    $notified[] = [
                        'kind'    => $kind,
                        'id'      => $id,
                        'user_id' => $userId,
                        'kanaly'  => array_keys(array_filter($wynik)),
                    ];
                }
            }
        }

        return $notified;
    }

}
