<?php
// core/Controllers/Admin/EmblemController.php
// EMBLEMATY (migr. 087, 2026-09-11) — mały panel CRUD, tylko admin.
//
// DLACZEGO OSOBNY EKRAN, A NIE POLA WPROST W FORMULARZU TRASY. Emblemat
// przypina się DO TRASY, ale nie NALEŻY do niej: ten sam może wisieć na serii
// tras („Korona Beskidów") i na wydarzeniu, a w przyszłości dojdzie mu fizyczny
// order z własnym nakładem i statusem wysyłki (zapowiedź usera). Pola wpisane
// wprost przy trasie zamknęłyby obie te drogi.
//
// Formularz dodawania stoi NA TEJ SAMEJ STRONIE co lista, w odróżnieniu od
// znanych tras (tam podstrony, bo katalog ma dorosnąć do setek pozycji).
// Emblematów będzie kilkanaście — lista mieści się na ekranie i dzielenie jej
// na podstrony byłoby kosztem bez pokrycia.
namespace Controllers\Admin;

use Core\Csrf;
use Models\Emblem;
use Utils\Upload;
use Utils\View;

class EmblemController
{
    public static function index(): void
    {
        View::render('web', 'emblems-admin', [
            'title'    => 'Emblematy — panel',
            'noindex'  => true,
            'emblems'  => Emblem::all(),
            'komunikat' => $_GET['ok'] ?? null,
            'blad'      => $_GET['blad'] ?? null,
        ]);
    }

    public static function save(): void
    {
        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            self::back(null, 'sesja');
        }

        $id = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            self::back(null, 'brak_nazwy');
        }

        $opis = trim((string) ($_POST['description'] ?? ''));
        $fields = [
            'name'        => mb_substr($name, 0, 120),
            'description' => $opis === '' ? null : mb_substr($opis, 0, 400),
            // Checkbox nieobecny w POST = odznaczony. Przy DODAWANIU pole jest
            // domyślnie zaznaczone w formularzu, więc nowy emblemat jest aktywny.
            'is_active'   => isset($_POST['is_active']) ? 1 : 0,
        ];

        // Grafika tą samą drogą co okładka wydarzenia. BRAK NOWEGO PLIKU
        // ZOSTAWIA STARY — input plikowy jest pusty przy każdym otwarciu
        // formularza, więc traktowanie pustki jako „skasuj" kasowałoby grafikę
        // przy każdej poprawce opisu (ta sama pułapka co przy znanej trasie).
        try {
            if (!empty($_FILES['image']['name'])) {
                $url = Upload::saveCoverPhoto($_FILES['image']);
                if ($url !== null) {
                    $fields['image_url'] = $url;
                }
            }
        } catch (\Throwable $e) {
            self::back(null, 'zle_zdjecie');
        }

        Emblem::save($id > 0 ? $id : null, $fields);
        self::back($id > 0 ? 'zapisane' : 'dodane');
    }

    /**
     * Skasowanie DEFINICJI kasuje też zdobyte egzemplarze (ON DELETE CASCADE,
     * patrz migr. 087) — dlatego zwykłe wycofanie emblematu z obiegu robi się
     * odznaczeniem „aktywny", a nie tym przyciskiem. Trasy i wydarzenia
     * przeżywają skasowanie bez szwanku (ON DELETE SET NULL).
     */
    public static function delete(string $id): void
    {
        if (Csrf::check($_POST['csrf_token'] ?? null)) {
            Emblem::delete((int) $id);
        }
        self::back('skasowane');
    }

    /**
     * Ręczne przeliczenie — nadaje emblematy wszystkim, którzy już mają trasę
     * domkniętą. Ten sam kod, który chodzi w cronie; przycisk istnieje, bo
     * PIERWSZE przypięcie emblematu do trasy dotyczy z reguły ludzi, którzy
     * przejechali ją dawno temu, a admin nie ma powodu czekać do nocy.
     */
    public static function recalculate(): void
    {
        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            self::back(null, 'sesja');
        }
        $ile = Emblem::sync();
        self::back('nadano:' . $ile);
    }

    private static function back(?string $ok = null, ?string $blad = null): void
    {
        $query = [];
        if ($ok !== null) { $query['ok'] = $ok; }
        if ($blad !== null) { $query['blad'] = $blad; }

        header('Location: ' . View::url('/admin/emblematy') . ($query ? '?' . http_build_query($query) : ''));
        exit;
    }
}
