<?php
// core/i18n.php
// Globalne skróty do Core\Lang — jedyne funkcje globalne w projekcie, bo
// wołane są w KAŻDYM widoku setki razy i `\Core\Lang::t(...)` zaciemniłoby
// szablony bardziej niż cokolwiek innego. Opis zasad: core/Core/Lang.php.

if (!function_exists('__')) {
    /**
     * Tekst interfejsu w bieżącym języku. Kluczem jest POLSKI tekst.
     * `{pole}` podmieniane z $params bez ucieczki (patrz Lang::t).
     */
    function __(string $text, array $params = []): string
    {
        return \Core\Lang::t($text, $params);
    }
}

if (!function_exists('__n')) {
    /**
     * Liczba mnoga z trzech polskich form; w innym języku formy ze słownika.
     * `{n}` w formie podmienia się liczbą — całe zdanie („{n} osoby przejechały
     * razem") tłumaczy się lepiej niż sklejka liczby z odmienionym słowem.
     */
    function __n(int $n, string $one, string $few, string $many, array $params = []): string
    {
        return \Core\Lang::fill(\Core\Lang::plural($n, $one, $few, $many), ['n' => $n] + $params);
    }
}
