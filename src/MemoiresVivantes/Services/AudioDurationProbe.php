<?php

namespace App\MemoiresVivantes\Services;

/**
 * Durée d'un enregistrement audio lue dans l'en-tête du fichier, sans ffmpeg (16/10/2026) : WAV, MP4/M4A (atome
 * mvhd), OGG (position de granule de la dernière page, Opus ou Vorbis), MP3 (en-tête Xing, sinon estimation au
 * débit constant), WebM (élément Duration quand il existe : un WebM enregistré en direct par un navigateur n'en a
 * souvent pas). null quand la durée ne peut pas être établie : le contrôleur s'en remet alors à la durée déclarée.
 */
final class AudioDurationProbe
{
    private const MAX_READ = 24 * 1024 * 1024;

    public static function seconds(string $path): ?float
    {
        $data = @file_get_contents($path, false, null, 0, self::MAX_READ);
        if ($data === false || strlen($data) < 12) {
            return null;
        }
        $size = filesize($path) ?: strlen($data);
        try {
            if (str_starts_with($data, 'RIFF') && substr($data, 8, 4) === 'WAVE') return self::wav($data, $size);
            if (substr($data, 4, 4) === 'ftyp') return self::mp4($data);
            if (str_starts_with($data, 'OggS')) return self::ogg($data);
            if (str_starts_with($data, "\x1A\x45\xDF\xA3")) return self::webm($data);
            if (str_starts_with($data, 'ID3') || (ord($data[0]) === 0xFF && (ord($data[1]) & 0xE0) === 0xE0)) return self::mp3($data, $size);
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    private static function wav(string $d, int $fileSize): ?float
    {
        $pos = 12;
        $byteRate = null;
        while ($pos + 8 <= strlen($d)) {
            $id = substr($d, $pos, 4);
            $len = unpack('V', substr($d, $pos + 4, 4))[1];
            if ($id === 'fmt ') {
                $byteRate = unpack('V', substr($d, $pos + 16, 4))[1];
            } elseif ($id === 'data') {
                if (!$byteRate) return null;
                $available = max(0, $fileSize - ($pos + 8));

                return min($len, $available) / $byteRate;
            }
            $pos += 8 + $len + ($len % 2);
        }

        return null;
    }

    private static function mp4(string $d): ?float
    {
        $moov = self::atom($d, 0, strlen($d), 'moov');
        if ($moov === null) return null;
        $mvhd = self::atom($d, $moov[0], $moov[1], 'mvhd');
        if ($mvhd === null) return null;
        $version = ord($d[$mvhd[0]]);
        if ($version === 1) {
            $timescale = unpack('N', substr($d, $mvhd[0] + 20, 4))[1];
            $duration = unpack('J', substr($d, $mvhd[0] + 24, 8))[1];
        } else {
            $timescale = unpack('N', substr($d, $mvhd[0] + 12, 4))[1];
            $duration = unpack('N', substr($d, $mvhd[0] + 16, 4))[1];
        }

        return $timescale > 0 ? $duration / $timescale : null;
    }

    /** @return array{0: int, 1: int}|null [début du contenu, fin] du premier atome $type entre $from et $to */
    private static function atom(string $d, int $from, int $to, string $type): ?array
    {
        $pos = $from;
        while ($pos + 8 <= $to) {
            $len = unpack('N', substr($d, $pos, 4))[1];
            $name = substr($d, $pos + 4, 4);
            $header = 8;
            if ($len === 1) {
                $len = unpack('J', substr($d, $pos + 8, 8))[1];
                $header = 16;
            } elseif ($len === 0) {
                $len = $to - $pos;
            }
            if ($len < $header) return null;
            if ($name === $type) return [$pos + $header, min($to, $pos + $len)];
            $pos += $len;
        }

        return null;
    }

    private static function ogg(string $d): ?float
    {
        $rate = null;
        if (($p = strpos($d, 'OpusHead')) !== false) {
            $rate = 48000; // la position de granule Opus est toujours à 48 kHz
        } elseif (($p = strpos($d, "\x01vorbis")) !== false) {
            $rate = unpack('V', substr($d, $p + 12, 4))[1];
        }
        $last = strrpos($d, 'OggS');
        if (!$rate || $last === false || strlen($d) < $last + 14) return null;
        $granule = unpack('P', substr($d, $last + 6, 8))[1];

        return $granule > 0 ? $granule / $rate : null;
    }

    private static function webm(string $d): ?float
    {
        // Élément Duration (0x4489) de Segment/Info, en unités de TimecodeScale (0x2AD7B1, 1 ms par défaut)
        $info = strpos($d, "\x15\x49\xA9\x66");
        if ($info === false) return null;
        $scale = 1000000;
        if (($p = strpos($d, "\x2A\xD7\xB1", $info)) !== false) {
            [$len, $off] = self::vint($d, $p + 3);
            if ($len > 0 && $len <= 8) {
                $scale = 0;
                for ($i = 0; $i < $len; $i++) $scale = $scale * 256 + ord($d[$off + $i]);
            }
        }
        if (($p = strpos($d, "\x44\x89", $info)) === false) return null;
        [$len, $off] = self::vint($d, $p + 2);
        $bytes = substr($d, $off, $len);
        $value = $len === 4 ? unpack('G', $bytes)[1] : ($len === 8 ? unpack('E', $bytes)[1] : null);

        return $value !== null && $scale > 0 ? $value * $scale / 1e9 : null;
    }

    /** @return array{0: int, 1: int} [valeur, position suivante] d'un entier EBML à longueur variable (marqueur retiré) */
    private static function vint(string $d, int $pos): array
    {
        $first = ord($d[$pos]);
        $len = 1;
        while ($len <= 8 && !($first & (0x80 >> ($len - 1)))) $len++;
        $value = $first & (0xFF >> $len);
        for ($i = 1; $i < $len; $i++) $value = $value * 256 + ord($d[$pos + $i]);

        return [$value, $pos + $len];
    }

    private static function mp3(string $d, int $fileSize): ?float
    {
        $pos = 0;
        if (str_starts_with($d, 'ID3')) {
            $pos = 10 + ((ord($d[6]) & 0x7F) << 21 | (ord($d[7]) & 0x7F) << 14 | (ord($d[8]) & 0x7F) << 7 | (ord($d[9]) & 0x7F));
        }
        $limit = min(strlen($d) - 4, $pos + 65536);
        while ($pos < $limit && !(ord($d[$pos]) === 0xFF && (ord($d[$pos + 1]) & 0xE0) === 0xE0)) $pos++;
        if ($pos >= $limit) return null;
        $b1 = ord($d[$pos + 1]); $b2 = ord($d[$pos + 2]);
        $versionBits = ($b1 >> 3) & 0x03; // 3 = MPEG1, 2 = MPEG2, 0 = MPEG2.5
        $layer = 4 - (($b1 >> 1) & 0x03);
        if ($versionBits === 1 || $layer !== 3) return null;
        $bitrates = $versionBits === 3 ? [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320] : [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160];
        $rates = $versionBits === 3 ? [44100, 48000, 32000] : ($versionBits === 2 ? [22050, 24000, 16000] : [11025, 12000, 8000]);
        $bitrate = ($bitrates[$b2 >> 4] ?? 0) * 1000;
        $rate = $rates[($b2 >> 2) & 0x03] ?? 0;
        if (!$bitrate || !$rate) return null;
        $samplesPerFrame = $versionBits === 3 ? 1152 : 576;
        // En-tête Xing/Info (VBR) : nombre de trames exact
        $xing = strpos(substr($d, $pos, 200), 'Xing') ?: strpos(substr($d, $pos, 200), 'Info');
        if ($xing !== false) {
            $flags = unpack('N', substr($d, $pos + $xing + 4, 4))[1];
            if ($flags & 1) {
                $frames = unpack('N', substr($d, $pos + $xing + 8, 4))[1];

                return $frames * $samplesPerFrame / $rate;
            }
        }

        return ($fileSize - $pos) * 8 / $bitrate;
    }
}
