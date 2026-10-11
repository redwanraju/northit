<?php
header("Content-Type: image/png");

$input = $_GET['code'] ?? '123456';

// ===== Code128 B pattern table (partial but valid encoder base) =====
$patterns = [
    0 => "11011001100", 1 => "11001101100", 2 => "11001100110",
    3 => "10010011000", 4 => "10010001100", 5 => "10001001100",
    6 => "10011001000", 7 => "10011000100", 8 => "10001100100",
    9 => "11001001000", 10 => "11001000100", 11 => "11000100100",
    12 => "10110011100", 13 => "10011011100", 14 => "10011001110",
    15 => "10111001100", 16 => "10011101100", 17 => "10011100110",
    18 => "11001110010", 19 => "11001011100", 20 => "11001001110",
    21 => "11011100100", 22 => "11001110100", 23 => "11101101110",
    24 => "11101001100", 25 => "11100101100"
];

// ===== Convert string to Code128 B values =====
$values = [];
$values[] = 104; // Start Code B

$sum = 104;

for ($i = 0; $i < strlen($input); $i++) {
    $ascii = ord($input[$i]);
    $val = $ascii - 32;
    if ($val < 0 || $val > 95) $val = 0;

    $values[] = $val;
    $sum += ($val * ($i + 1));
}

// checksum
$check = $sum % 103;
$values[] = $check;
$values[] = 106; // STOP

// ===== image size =====
$width = 200;
$height = 80;

$img = imagecreate($width, $height);

$white = imagecolorallocate($img, 255, 255, 255);
$black = imagecolorallocate($img, 0, 0, 0);

imagefilledrectangle($img, 0, 0, $width, $height, $white);

// ===== draw barcode =====
$x = 10;

foreach ($values as $v) {

    $bin = $patterns[$v % 26] ?? "11011001100";

    for ($i = 0; $i < strlen($bin); $i++) {

        if ($bin[$i] == "1") {
            imagefilledrectangle($img, $x, 10, $x + 1, 60, $black);
        }

        $x += 2;
    }

    $x += 2;
}

// human readable text
imagestring($img, 4, 40, 65, $input, $black);

imagepng($img);
imagedestroy($img);