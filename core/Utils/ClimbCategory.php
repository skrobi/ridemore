<?php
// core/Utils/ClimbCategory.php
namespace Utils;

class ClimbCategory
{
    // Kolejność od najtrudniejszej — konwencja zbliżona do tego, jak kategorie
    // podjazdów koloruje się w większości narzędzi rowerowych (zielony = najlżej,
    // ciemna czerwień = najtrudniej).
    private const COLORS = [
        'HC' => '#7A1E1E',
        '1'  => '#D14E1E',
        '2'  => '#E08A2E',
        '3'  => '#3B82C4',
        '4'  => '#4C9A5B',
    ];

    public static function color(?string $category): string
    {
        return self::COLORS[$category] ?? 'var(--ink-mute)';
    }

    public static function label(?string $category): ?string
    {
        return $category !== null ? 'kat. ' . $category : null;
    }
}
