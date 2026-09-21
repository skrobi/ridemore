<?php
// core/Controllers/TranslationController.php
namespace Controllers;

use Core\Auth;
use Core\Csrf;
use Core\Lang;
use Models\ContentTranslation;
use Models\EventPermission;
use Models\KnownRoute;
use Models\Organizer;
use Models\Treasure;
use Utils\View;

/**
 * KOREKTA TŁUMACZENIA TREŚCI (2026-09-16, tasks/active/wielojezycznosc.md).
 *
 * `/tlumaczenie/{typ}/{id}` — w języku strony, z której przyszedł link
 * (`/en/tlumaczenie/event/12` poprawia wersję angielską). Poprawka zapisuje
 * się jako `origin = human` i od tej chwili wygrywa z automatem.
 *
 * UPRAWNIENIA to te same bramki, co przy edycji samej treści:
 *   event     — EventPermission::canEdit (organizator albo admin),
 *   organizer — właściciel profilu albo admin,
 *   route/treasure — admin (katalog prowadzi obsługa).
 * Nie ma tu żadnej nowej reguły, bo tłumaczenie jest częścią tej samej treści.
 *
 * Pusty tekst przy zapisie = „wróć do automatu" (ręczna wersja znika).
 */
final class TranslationController
{
    public static function form(string $typ, string $id): void
    {
        $cel = self::cel($typ, (int) $id);
        $target = Lang::current();

        $pola = [];
        foreach ($cel['fields'] as $klucz => [$etykieta, $zrodlo]) {
            if (!is_string($zrodlo) || trim($zrodlo) === '') {
                continue;
            }
            $kontekst = $cel['context'] . ':' . $klucz;
            // Tłumaczenie automatyczne, jeśli go jeszcze nie ma — formularz ma
            // pokazać punkt wyjścia, a nie pustkę.
            $obecne = ContentTranslation::current($zrodlo, $target);
            if ($obecne === null) {
                ContentTranslation::text($zrodlo, $kontekst);
                $obecne = ContentTranslation::current($zrodlo, $target);
            }
            $pola[$klucz] = [
                'label'    => $etykieta,
                'source'   => $zrodlo,
                'text'     => ($obecne && $obecne['origin'] !== 'same') ? (string) $obecne['translated_text'] : '',
                'origin'   => $obecne['origin'] ?? null,
                'previous' => ContentTranslation::previousHuman($kontekst, $zrodlo, $target),
            ];
        }

        View::render('web', 'translation-form', [
            'title'       => __('Popraw tłumaczenie — ridemore.bike'),
            'noindex'     => true,
            'heading'     => $cel['heading'],
            'backUrl'     => $cel['back'],
            'fields'      => $pola,
            'action'      => View::url('/tlumaczenie/' . $typ . '/' . (int) $id),
            'targetName'  => Lang::name($target),
            'saved'       => !empty($_GET['zapisano']),
        ]);
    }

    public static function save(string $typ, string $id): void
    {
        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            http_response_code(403);
            echo __('Sesja wygasła, odśwież stronę');
            return;
        }
        $cel = self::cel($typ, (int) $id);
        $user = Auth::user();
        $target = Lang::current();
        $wyslane = (array) ($_POST['t'] ?? []);

        foreach ($cel['fields'] as $klucz => [, $zrodlo]) {
            if (!is_string($zrodlo) || trim($zrodlo) === '' || !array_key_exists($klucz, $wyslane)) {
                continue;
            }
            $nowe = str_replace("\r\n", "\n", (string) $wyslane[$klucz]);
            $obecne = ContentTranslation::current($zrodlo, $target);
            $bylo = ($obecne && $obecne['origin'] !== 'same') ? (string) $obecne['translated_text'] : '';
            // Niezmienione pole NIE staje się „ręcznym" — inaczej jeden zapis
            // formularza zamroziłby wszystkie maszynowe tłumaczenia na zawsze.
            if ($nowe === $bylo && ($obecne['origin'] ?? null) !== 'human') {
                continue;
            }
            ContentTranslation::saveHuman($cel['context'] . ':' . $klucz, $zrodlo, $target, $nowe, $user->id);
        }

        header('Location: ' . View::url('/tlumaczenie/' . $typ . '/' . (int) $id) . '?zapisano=1');
        exit;
    }

    /**
     * @return array{context:string,heading:string,back:string,fields:array<string,array{0:string,1:?string}>}
     */
    private static function cel(string $typ, int $id): array
    {
        $user = Auth::requireLogin();

        switch ($typ) {
            case 'event':
                $e = \Models\Event::findRawById($id);
                if (!$e) {
                    self::brak();
                }
                if (!EventPermission::canEdit($user, (int) $e['organizer_id'])) {
                    self::odmowa();
                }
                return [
                    'context' => 'event:' . $id,
                    'heading' => (string) $e['title'],
                    'back'    => View::url('/events/' . $e['slug']),
                    'fields'  => [
                        'title'       => [__('Tytuł'), $e['title']],
                        'description' => [__('Opis'), $e['description']],
                    ],
                ];

            case 'organizer':
                if ($user->id !== $id && !$user->isAdmin) {
                    self::odmowa();
                }
                try {
                    $o = Organizer::findByUserId($id);
                } catch (\Throwable $e) {
                    self::brak();
                }
                return [
                    'context' => 'organizer:' . $id,
                    'heading' => $o->name,
                    'back'    => View::url('/organizatorzy/' . $o->slug),
                    'fields'  => ['t' => [__('Opis organizatora'), $o->bio]],
                ];

            case 'route':
                if (!$user->isAdmin) {
                    self::odmowa();
                }
                $r = KnownRoute::find($id);
                if (!$r) {
                    self::brak();
                }
                return [
                    'context' => 'route:' . $id,
                    'heading' => (string) $r['name'],
                    'back'    => View::url('/trasy/' . $r['slug']),
                    'fields'  => [
                        'name'        => [__('Nazwa'), $r['name']],
                        'description' => [__('Opis'), $r['description'] ?? null],
                    ],
                ];

            case 'treasure':
                if (!$user->isAdmin) {
                    self::odmowa();
                }
                $t = Treasure::find($id);
                if (!$t) {
                    self::brak();
                }
                return [
                    'context' => 'treasure:' . $id,
                    'heading' => (string) $t['name'],
                    'back'    => View::url('/skarb/' . ($t['code'] ?? '')),
                    'fields'  => [
                        'name'        => [__('Nazwa'), $t['name']],
                        'description' => [__('Opis'), $t['description'] ?? null],
                        'hint'        => [__('Podpowiedź'), $t['hint'] ?? null],
                    ],
                ];
        }
        self::brak();
    }

    private static function odmowa(): never
    {
        http_response_code(403);
        echo __('Nie masz uprawnień do poprawiania tego tłumaczenia.');
        exit;
    }

    private static function brak(): never
    {
        http_response_code(404);
        echo __('Nie znaleziono.');
        exit;
    }
}
