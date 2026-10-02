<?php

namespace App\Services\SharedMedia;

use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

/**
 * Encodage par ffmpeg pour une scène au défilement : H.264, sans images B, sans son, 1080 px de haut au plus,
 * lecture possible dès le début du téléchargement.
 *
 * - Calage rapide : une image complète toutes les 0,21 s environ (5 images à 24 i/s, 10 à 48 i/s). Mesuré par le
 *   frontend le 02/10/2026 sur 10 s en 1080p : calage de 156–228 ms → 19–24 ms ; une image complète à chaque image
 *   double le poids sans gain mesurable.
 * - Fluidité au défilement lent : la cadence est doublée par interpolation (minterpolate, images intermédiaires
 *   calculées) quand la source est à 30 i/s ou moins (24 → 48, 25 → 50, 30 → 60) ; au-delà, la cadence d'origine
 *   est gardée. Une vidéo à 24 i/s paraissait saccadée. Limite : sur un mouvement très rapide ou un changement de
 *   plan, une image calculée peut être déformée.
 * - L'interpolation est lente (de l'ordre de 10 fois la durée de la vidéo, davantage sur ce serveur) : la réduction à
 *   1080p se fait avant elle, et les vidéos sont limitées à 30 secondes.
 *
 * Mesure sur ce serveur (02/10/2026) : 3 minutes pour 10 s de vidéo en 1080p à 24 i/s (477 images, 7,5 Mo).
 *
 * ffmpeg n'est installé que dans le conteneur du worker « media » (docker/worker-media/Dockerfile).
 */
final class FfmpegScrollVideoEncoder implements ScrollVideoEncoderInterface
{
    /** Secondes entre deux images complètes : 5 images à 24 i/s */
    public const KEYFRAME_SECONDS = 5 / 24;
    /** Cadence (i/s) jusqu'à laquelle la source est doublée par interpolation */
    public const MAX_FPS_TO_DOUBLE = 30;
    public const MAX_HEIGHT = 1080;
    /** Secondes : au-delà, le fichier préparé devient trop lourd pour une page, et l'interpolation trop longue */
    public const MAX_DURATION = 30;
    /** Secondes : l'interpolation de 30 s de vidéo peut prendre plus d'un quart d'heure */
    public const TIMEOUT = 3600;

    public function encode(string $sourcePath, string $targetPath): void
    {
        ['duration' => $duration, 'fps' => $fps] = $this->probe($sourcePath);
        if ($duration > self::MAX_DURATION + 0.5) {
            throw new ScrollVideoTooLongException(sprintf('vidéo de %d secondes : %d au plus', round($duration), self::MAX_DURATION));
        }
        $plan = self::plan($fps);

        // Priorité basse (nice) : l'encodage occupe un cœur pendant plusieurs minutes, le site passe avant lui
        $process = new Process([
            'nice', '-n', '10', 'ffmpeg', '-nostdin', '-y', '-loglevel', 'error',
            '-i', $sourcePath,
            '-an',
            '-vf', implode(',', array_filter([
                sprintf("scale=-2:'min(%d,ih)'", self::MAX_HEIGHT),
                $plan['fps'] !== null ? sprintf('minterpolate=fps=%s:mi_mode=mci:mc_mode=aobmc:me_mode=bidir:vsbmc=1', $plan['fps']) : null,
            ])),
            '-c:v', 'libx264', '-preset', 'slow', '-crf', '20', '-pix_fmt', 'yuv420p', '-bf', '0',
            '-g', (string) $plan['gop'], '-keyint_min', (string) $plan['gop'], '-sc_threshold', '0',
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

    /**
     * Cadence cible et écart entre images complètes, d'après la cadence de la source (« 24/1 », « 30000/1001 »…).
     *
     * @return array{fps: ?string, gop: int} fps : cadence à obtenir par interpolation, ou null pour garder celle de la source
     */
    public static function plan(?string $sourceFps): array
    {
        $rate = null;
        if ($sourceFps !== null && preg_match('#^(\d+)(?:/(\d+))?$#', trim($sourceFps), $m) && (int) ($m[2] ?? 1) > 0 && (int) $m[1] > 0) {
            [$num, $den] = [(int) $m[1], (int) ($m[2] ?? 1)];
            $rate = $num / $den;
        }
        if ($rate === null) {
            // cadence illisible : pas d'interpolation, images complètes comme pour une vidéo à 24 i/s
            return ['fps' => null, 'gop' => 5];
        }
        $double = $rate <= self::MAX_FPS_TO_DOUBLE + 0.01;
        $target = $double ? $rate * 2 : $rate;

        return [
            'fps' => $double ? ($den === 1 ? (string) ($num * 2) : sprintf('%d/%d', $num * 2, $den)) : null,
            'gop' => max(1, (int) round($target * self::KEYFRAME_SECONDS)),
        ];
    }

    /**
     * Durée (secondes) et cadence de la première piste vidéo, lues par ffprobe.
     *
     * @return array{duration: float, fps: ?string}
     */
    private function probe(string $path): array
    {
        $process = new Process(['ffprobe', '-v', 'error', '-select_streams', 'v:0', '-show_entries', 'stream=r_frame_rate:format=duration', '-of', 'json', $path]);
        $process->setTimeout(60);
        try {
            $process->run();
        } catch (ExceptionInterface $e) {
            throw new \RuntimeException('ffprobe indisponible : ' . $e->getMessage(), 0, $e);
        }
        $data = json_decode($process->getOutput(), true);
        $duration = $data['format']['duration'] ?? null;
        if (!$process->isSuccessful() || !is_numeric($duration)) {
            throw new \RuntimeException('durée de la vidéo illisible : ' . mb_substr(trim($process->getErrorOutput()), 0, 300));
        }
        $fps = $data['streams'][0]['r_frame_rate'] ?? null;

        return ['duration' => (float) $duration, 'fps' => is_string($fps) ? $fps : null];
    }
}
