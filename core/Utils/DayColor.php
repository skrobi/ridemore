<?php
// core/Utils/DayColor.php
namespace Utils;

class DayColor
{
    // Paleta "oznaczeń szlakowych" — cykliczna po numerze dnia. Soft = tło pod
    // kropki/plakietki, żeby tekst (accent-dark odpowiednik) miał kontrast.
    private const PALETTE = [
        ['color' => '#D14E1E', 'soft' => '#F7E3D8'], // spalona pomarańcz
        ['color' => '#5F7A45', 'soft' => '#E4EADB'], // mech
        ['color' => '#5B7480', 'soft' => '#DEE6E8'], // kamień
        ['color' => '#8A6D3B', 'soft' => '#EFE6D6'], // kora
        ['color' => '#4A6FA5', 'soft' => '#DDE6F0'], // niebo
        ['color' => '#7A5C99', 'soft' => '#E7E0EE'], // wrzos
    ];

    public static function forDay(int $dayNumber): array
    {
        $idx = ($dayNumber - 1) % count(self::PALETTE);
        return self::PALETTE[$idx];
    }
}
