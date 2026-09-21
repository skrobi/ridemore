<?php
// core/Models/GarminBridgeError.php
// Błąd zgłoszony przez most do Garmina (ai-engine/garmin.py) Z KODEM PRZYCZYNY.
//
// Osobna klasa i osobny plik, bo „hasło nie pasuje", „konto chce kod 2FA"
// i „Garmin nas chwilowo przyblokował" to trzy różne komunikaty dla człowieka
// i trzy różne reakcje (popraw hasło / wpisz kod / poczekaj) — jedno „coś
// poszło nie tak" nie mówi żadnej z tych rzeczy. Tekst dla użytkownika składa
// kontroler, tu jedzie wyłącznie surowy kod przyczyny.
namespace Models;

class GarminBridgeError extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Garmin Connect: ' . $reason);
    }
}
