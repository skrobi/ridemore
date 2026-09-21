<?php
// core/Utils/RateLimiter.php
namespace Utils;

// Bardzo prosty rate-limiter oparty o pliki w storage/ratelimit/ — bez
// zależności zewnętrznych (Redis/Memcached), spójny z resztą aplikacji
// (Core\Mailer też pisze logi do storage/). To nie jest twarda ochrona (per
// klucz, do obejścia rotacją adresów/skrzynek), ale wystarcza, żeby
// uprzykrzyć automatyczne masowe wywołania endpointów wysyłających maile
// (rejestracja / reset hasła / przejęcie profilu organizatora) — wcześniej
// jedyną "ochroną" był brak jakiegokolwiek limitu.
class RateLimiter
{
    private static function storageDir(): string
    {
        $dir = CORE_PATH . '/../storage/ratelimit';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir;
    }

    // true = przekroczono limit, wywołujący powinien odrzucić żądanie.
    // Zlicza próby w przesuwnym oknie $windowSeconds pod danym kluczem —
    // wywołujący sam decyduje co jest kluczem (per IP, per e-mail, per slug...),
    // zwykle warto sprawdzić obie granice naraz (patrz przykłady w web/routes.php).
    public static function tooMany(string $key, int $maxAttempts, int $windowSeconds): bool
    {
        $file = self::storageDir() . '/' . hash('sha256', $key) . '.json';
        $handle = @fopen($file, 'c+');
        if (!$handle) {
            // Bez dostępu do dysku wolimy przepuścić żądanie niż samym
            // rate-limiterem wywalić funkcję, która bez niego działała.
            return false;
        }

        flock($handle, LOCK_EX);
        $raw = stream_get_contents($handle);
        $data = $raw ? json_decode($raw, true) : null;
        $now = time();
        $attempts = is_array($data['attempts'] ?? null) ? $data['attempts'] : [];
        $attempts = array_values(array_filter($attempts, fn($t) => is_int($t) && $t > $now - $windowSeconds));

        $blocked = count($attempts) >= $maxAttempts;
        if (!$blocked) {
            $attempts[] = $now;
        }

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode(['attempts' => $attempts]));
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        return $blocked;
    }
}
