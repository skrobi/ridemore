<?php
// core/Utils/MailTemplate.php
namespace Utils;

// Odpowiednik View::render() dla treści HTML e-maili — jedno miejsce na
// wspólną otoczkę/stopkę (views/emails/layout.php) i kolory/przyciski
// (self::button()) zamiast HTML-a duplikowanego w każdym miejscu wysyłki.
class MailTemplate
{
    public static function render(string $page, array $data = []): string
    {
        $pagePath = CORE_PATH . '/../views/emails/pages/' . $page . '.php';
        if (!file_exists($pagePath)) {
            throw new \RuntimeException("Szablon e-maila nie istnieje: $page");
        }

        extract($data);

        ob_start();
        require $pagePath;
        $content = ob_get_clean();

        ob_start();
        require CORE_PATH . '/../views/emails/layout.php';
        return ob_get_clean();
    }

    public static function greeting(?string $name): string
    {
        return $name ? __('Cześć, {imie}!', ['imie' => htmlspecialchars($name)]) : __('Cześć!');
    }

    public static function button(string $url, string $label, bool $primary = true): string
    {
        $style = $primary
            ? 'display:inline-block;background:#D14E1E;color:#FBEEE6;text-decoration:none;padding:12px 22px;border-radius:8px;font-weight:bold;font-size:14px;'
            : 'display:inline-block;background:#FFFFFF;color:#A23913;text-decoration:none;padding:12px 22px;border-radius:8px;font-weight:bold;font-size:14px;border:1px solid #A23913;';
        return '<a href="' . htmlspecialchars($url) . '" style="' . $style . '">' . htmlspecialchars($label) . '</a>';
    }
}
