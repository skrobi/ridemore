<?php
// core/Controllers/Admin/TaxonomyController.php
// Pierwszy w aplikacji ekran list-CRUD: zarządzanie słownikami (dictionaries/
// dictionary_items), generyczny dla wszystkich (region, difficulty_level,
// bike_type...), z obsługą dowolnie głębokiej hierarchii przez parent_id
// (patrz migration_013_dictionary_hierarchy.sql). Wyłącznie dla adminów —
// w odróżnieniu od reszty admin/routes.php (samoobsługowe formularze
// organizatora), to globalne dane referencyjne całego serwisu.
namespace Controllers\Admin;

use Controllers\Support;
use Core\Csrf;
use Models\Dictionary;
use Utils\View;

class TaxonomyController
{
    public static function index(): void
    {
        $dictionaries = Dictionary::dictionaries();
        $codes = array_column($dictionaries, 'code');
        $selected = $_GET['dict'] ?? '';
        if ($selected === '' || !in_array($selected, $codes, true)) {
            $selected = $dictionaries[0]['code'] ?? '';
        }

        $errors = [
            'kod'      => 'Kod może zawierać tylko małe litery, cyfry i podkreślenia (max 64 znaki).',
            'nazwa'    => 'Podaj nazwę (max 128 znaków).',
            'rodzic'   => 'Wybrany element nadrzędny nie należy do tego słownika.',
            'duplikat' => 'Taki kod już istnieje w tym słowniku.',
        ];
        $bladKod = $_GET['blad'] ?? '';

        View::render('web', 'taxonomy-admin', [
            'title'        => 'Taksonomia — ridemore.bike',
            'dictionaries' => $dictionaries,
            'selectedCode' => $selected,
            'tree'         => $selected !== '' ? Dictionary::tree($selected) : [],
            'error'        => $errors[$bladKod] ?? null,
            'info'         => ($_GET['info'] ?? '') === 'zapisane' ? 'Zapisano.' : null,
            'breadcrumbs'  => [Support::homeCrumb(), Support::panelCrumb(), ['label' => 'Taksonomia']],
        ]);
    }

    public static function create(): void
    {
        $dictCode = $_POST['dictionary_code'] ?? '';
        $redirectBase = '/admin/taksonomia?dict=' . urlencode($dictCode);

        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            header('Location: ' . View::url($redirectBase));
            exit;
        }

        $dict = null;
        foreach (Dictionary::dictionaries() as $d) {
            if ($d['code'] === $dictCode) { $dict = $d; break; }
        }
        if ($dict === null) {
            header('Location: ' . View::url('/admin/taksonomia'));
            exit;
        }

        $code = trim($_POST['code'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $sortOrder = ctype_digit((string) ($_POST['sort_order'] ?? '')) ? (int) $_POST['sort_order'] : 0;
        $parentIdInput = trim($_POST['parent_id'] ?? '');
        $parentId = ($parentIdInput !== '' && ctype_digit($parentIdInput)) ? (int) $parentIdInput : null;

        if ($code === '' || mb_strlen($code) > 64 || !preg_match('/^[a-z0-9_]+$/', $code)) {
            header('Location: ' . View::url($redirectBase . '&blad=kod'));
            exit;
        }
        if ($name === '' || mb_strlen($name) > 128) {
            header('Location: ' . View::url($redirectBase . '&blad=nazwa'));
            exit;
        }
        // Element nadrzędny musi należeć do tego samego słownika — blokada przed
        // spreparowanym POST-em podpinającym pozycję pod cudzy słownik.
        if ($parentId !== null) {
            $parentItem = Dictionary::findItem($parentId);
            if ($parentItem === null || $parentItem['dictionaryId'] !== (int) $dict['id']) {
                header('Location: ' . View::url($redirectBase . '&blad=rodzic'));
                exit;
            }
        }

        $newId = Dictionary::createItem((int) $dict['id'], $parentId, $code, $name, $sortOrder);
        header('Location: ' . View::url($redirectBase . ($newId !== null ? '&info=zapisane' : '&blad=duplikat')));
        exit;
    }

    public static function update(string $id): void
    {
        $item = Dictionary::findItem((int) $id);
        if ($item === null) {
            header('Location: ' . View::url('/admin/taksonomia'));
            exit;
        }
        $redirectBase = '/admin/taksonomia?dict=' . urlencode($item['dictionaryCode']);

        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            header('Location: ' . View::url($redirectBase));
            exit;
        }

        $code = trim($_POST['code'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $sortOrder = ctype_digit((string) ($_POST['sort_order'] ?? '')) ? (int) $_POST['sort_order'] : 0;

        if ($code === '' || mb_strlen($code) > 64 || !preg_match('/^[a-z0-9_]+$/', $code)) {
            header('Location: ' . View::url($redirectBase . '&blad=kod'));
            exit;
        }
        if ($name === '' || mb_strlen($name) > 128) {
            header('Location: ' . View::url($redirectBase . '&blad=nazwa'));
            exit;
        }

        $ok = Dictionary::updateItem((int) $id, $code, $name, $sortOrder);
        header('Location: ' . View::url($redirectBase . ($ok ? '&info=zapisane' : '&blad=duplikat')));
        exit;
    }

    public static function toggle(string $id): void
    {
        $item = Dictionary::findItem((int) $id);
        if ($item === null) {
            header('Location: ' . View::url('/admin/taksonomia'));
            exit;
        }
        $redirectBase = '/admin/taksonomia?dict=' . urlencode($item['dictionaryCode']);

        if (!Csrf::check($_POST['csrf_token'] ?? null)) {
            header('Location: ' . View::url($redirectBase));
            exit;
        }

        Dictionary::setActive((int) $id, !$item['isActive']);
        header('Location: ' . View::url($redirectBase . '&info=zapisane'));
        exit;
    }
}
