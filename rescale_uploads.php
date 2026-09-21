<?php
// rescale_uploads.php
// Jednorazowe przeskalowanie ZASTANYCH zdjęć w assets/uploads/.
//
// POWÓD: Utils\Upload skaluje przy wgrywaniu (okładka 1600 px, galeria 1200 px,
// avatar 400 px), ale pliki wgrane ZANIM ten limit powstał — oraz wszystko,
// co trafiło do repozytorium jako dane seedowe — leżą w oryginale. Zmierzone
// na dev: 3 z 4 okładek organizatorów miały 6000×3375 i po ~2 MB, a wyświetlają
// się w kafelku o wysokości 64 px. Przy liście kilkudziesięciu organizatorów
// to kilkadziesiąt megabajtów na jedno wejście.
//
// Skrypt jest IDEMPOTENTNY: resizeExistingFile() nie rusza pliku, który już
// mieści się w limicie, więc ponowne uruchomienie nic nie kosztuje i niczego
// nie psuje. Nie zmienia nazw plików ani ścieżek — adresy w bazie zostają
// ważne.
//
// UŻYCIE:
//   php rescale_uploads.php            — pokazuje, co by zrobił (bez zapisu)
//   php rescale_uploads.php --apply    — skaluje naprawdę

require __DIR__ . '/core/bootstrap.php';

use Utils\Upload;

$apply = in_array('--apply', $argv, true);

// Limity MUSZĄ być te same co w Utils\Upload — inaczej skrypt zrobiłby zdjęcia
// innego rozmiaru niż kolejne wgrywane i galeria wyglądałaby niespójnie.
$dirs = [
    'avatars'           => 400,
    'covers'            => 1600,
    'gallery'           => 1200,
    'organizer-covers'  => 1200,
];

$totalBefore = 0;
$totalAfter = 0;
$changed = 0;
$seen = 0;

foreach ($dirs as $dir => $maxDim) {
    $path = __DIR__ . '/assets/uploads/' . $dir;
    if (!is_dir($path)) {
        continue;
    }

    foreach (glob($path . '/*') as $file) {
        if (!is_file($file)) {
            continue;
        }
        $size = @getimagesize($file);
        if (!$size) {
            continue; // nie obrazek albo uszkodzony — zostawiamy w spokoju
        }
        $seen++;
        $bytesBefore = filesize($file);
        $totalBefore += $bytesBefore;

        if (max($size[0], $size[1]) <= $maxDim) {
            $totalAfter += $bytesBefore;
            continue;
        }

        printf(
            "%-18s %-36s %5dx%-5d %6.1f KB",
            $dir,
            basename($file),
            $size[0],
            $size[1],
            $bytesBefore / 1024
        );

        if ($apply && Upload::resizeExistingFile($file, $maxDim)) {
            clearstatcache(true, $file);
            $new = @getimagesize($file);
            $bytesAfter = filesize($file);
            $totalAfter += $bytesAfter;
            $changed++;
            printf("  ->  %4dx%-5d %6.1f KB\n", $new[0], $new[1], $bytesAfter / 1024);
        } else {
            $totalAfter += $bytesBefore;
            echo "  (podgląd — uruchom z --apply)\n";
        }
    }
}

printf(
    "\nplików: %d, przeskalowanych: %d, rozmiar: %.1f MB -> %.1f MB%s\n",
    $seen,
    $changed,
    $totalBefore / 1048576,
    $totalAfter / 1048576,
    $apply ? '' : ' (podgląd)'
);
