<?php
// core/Core/Csrf.php
namespace Core;

class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function check(?string $token): bool
    {
        return is_string($token) && !empty($_SESSION['csrf_token'])
            && hash_equals($_SESSION['csrf_token'], $token);
    }

    // Gotowe ukryte pole do wklejenia w każdym <form method="post">.
    // Jedyne miejsce, które zna nazwę pola 'csrf_token'.
    public static function field(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(self::token()) . '">';
    }
}
