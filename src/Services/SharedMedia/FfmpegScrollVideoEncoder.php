<?php

namespace App\Services\SharedMedia;

use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

/**
 * Encodage par ffmpeg : H.264, une image complète toutes les 5 images, sans images B, sans son, définition et cadence
 * d'origine (ramenée à 1080 px de haut au-delà), lecture possible dès le début du téléchargement. Réglages mesurés
 * par le frontend le 02/10/2026 sur une vidéo de 10 s en 1080p : 3,8 Mo → 6,4 Mo, calage de 156–228 ms → 19–22 ms ;
 * une image complète à chaque image double le poids sans gain mesurable. Vidéos de 30 secondes au plus. ffmpeg n'est installé que dans le conteneur du worker « media »
 * (docker/worker-media/Dockerfile), jamais dans celui du site.
 */
final class FfmpegScrollVideoEncoder implements ScrollVideoEncoderInterface
{
    public const KEYFRAME_INTERVAL = 5;
    public const MAX_HEIGHT = 1080;
    /** Secondes : au-delà, le fichier préparé devient trop lourd pour une page */
    public const MAX_DURATION = 30;
    /** Secondes : une vidéo de 100 Mo en 1080p reste bien en dessous */
    public const TIMEOUT = 1800;

    public function encode(string $sourcePath, string $targetPath): void
    {
        $duration = $this->duration($sourcePath);
        if ($duration > self::MAX_DURATION + 0.5) {
            throw new ScrollVideoTooLongException(sprintf('vidéo de %d secondes : %d au plus', round($duration), self::MAX_DURATION));
        }

        $process = new Process([
            'ffmpeg', '-nostdin', '-y', '-loglevel', 'error',
            '-i', $sourcePath,
            '-an',
            '-c:v', 'libx264', '-preset', 'slow', '-crf', '20', '-pix_fmt', 'yuv420p', '-bf', '0',
            '-g', (string) self::KEYFRAME_INTERVAL, '-keyint_min', (string) self::KEYFRAME_INTERVAL, '-sc_threshold', '0',
            '-vf', sprintf("scale=-2:'min(%d,ih)'", self::MAX_HEIGHT),
            '-movflags', '+faststart',
            '-f', 'mp4', $targetPath,
        ]);
        $process->setTimeout(self::TIMEOUT);

        try {
            $process->run();
        } catch (ExceptionInterface $e) {
            throw new \RuntimeException('ffmpeg indisponible ou interrompu : ' . $e->getMessage(), 0, $e);
        }
        if (!$process->isSuccessful() || !is_file($targetPath) || filesize($targetPath) === 0) {
            throw new \RuntimeException('ffmpeg a échoué : ' . mb_substr(trim($process->getErrorOutput()), 0, 500));
        }
    }

    /** Durée en secondes, lue par ffprobe */
    private function duration(string $path): float
    {
        $process = new Process(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'default=noprint_wrappers=1:nokey=1', $path]);
        $process->setTimeout(60);
        try {
            $process->run();
        } catch (ExceptionInterface $e) {
            throw new \RuntimeException('ffprobe indisponible : ' . $e->getMessage(), 0, $e);
        }
        $duration = trim($process->getOutput());
        if (!$process->isSuccessful() || !is_numeric($duration)) {
            throw new \RuntimeException('durée de la vidéo illisible : ' . mb_substr(trim($process->getErrorOutput()), 0, 300));
        }

        return (float) $duration;
    }
}
