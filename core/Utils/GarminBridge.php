<?php
// core/Utils/GarminBridge.php
// Most PHP -> Python dla Garmin Connect (ai-engine/garmin.py).
//
// DLACZEGO W OGÓLE PYTHON. Garmin nie udostępnia API dla kont osobistych —
// Connect Developer Program to program PARTNERSKI dla firm, z ręczną
// akceptacją wniosku. Jedyna działająca droga do własnych aktywności prowadzi
// przez bibliotekę `garminconnect`, która loguje się jak aplikacja mobilna,
// a ta istnieje tylko w Pythonie. Most PHP->Python już był (silnik AI), więc
// to nie jest nowa klasa zależności, tylko drugie jej użycie.
//
// TA FUNKCJA JEST DODATKIEM, NIE FUNDAMENTEM. Biblioteka jest nieoficjalna
// (poprzednia, `garth`, przestała działać w marcu 2026 po zmianie logowania
// po stronie Garmina). Wgrywanie pliku GPX ręcznie zostaje pełnoprawną,
// niezależną drogą i nigdy nie może od tego zależeć.
//
// KONFIGURACJA PER ŚRODOWISKO — klucz `garmin` w core/config.php istnieje
// tam, gdzie hosting ma Pythona i proc_open (typowy współdzielony hosting
// zwykle nie ma ani jednego, ani drugiego). Od 2026-09-04 dotyczy to też
// 'prod' (zweryfikowane żywym logowaniem — na tamtym hostingu inny, starszy
// zestaw pakietów niż na dev, patrz komentarz przy bloku 'prod' w
// core/config.php) — brak klucza w danym środowisku = funkcja po prostu się
// tam nie pokazuje.
namespace Utils;

class GarminBridge
{
    /** Czy w tym środowisku most jest w ogóle dostępny (decyduje o pokazaniu UI). */
    public static function available(): bool
    {
        $config = APP_CONFIG['garmin'] ?? null;
        return is_array($config) && is_file($config['script'] ?? '') && function_exists('proc_open');
    }

    /**
     * Wywołuje garmin.py i zwraca CAŁĄ kopertę {"ok": bool, "data"|"error"|"code"}.
     *
     * Świadomie nie rzucamy wyjątkiem przy ok=false: wywołujący musi rozróżnić
     * „złe hasło" (auth), „potrzebny kod 2FA" (mfa) i „Garmin nas przyblokował"
     * (rate), bo to TRZY różne komunikaty dla człowieka i trzy różne reakcje.
     *
     * @param array<string,mixed> $payload
     * @return array{ok:bool,data?:array,error?:string,code?:string}
     */
    public static function call(string $command, array $payload): array
    {
        $config = APP_CONFIG['garmin'] ?? null;
        if (!is_array($config)) {
            throw new \RuntimeException(__('Integracja z Garmin Connect nie jest skonfigurowana w tym środowisku.'));
        }

        return PythonBridge::run($config, ['command' => $command] + $payload, __('Garmin Connect'));
    }
}
