<?php

namespace App\Tests\Fake;

use App\Services\SharedMedia\ScrollVideoEncoderInterface;

/** Encodeur simulé : écrit un fichier de sortie reconnaissable, ou échoue sur demande (aucun ffmpeg pendant les tests) */
final class FakeScrollVideoEncoder implements ScrollVideoEncoderInterface
{
    public const OUTPUT = 'video-preparee-pour-le-defilement';

    public static bool $fail = false;
    /** @var list<array{string, string}> */
    public static array $calls = [];

    public static function reset(): void
    {
        self::$fail = false;
        self::$calls = [];
    }

    public function encode(string $sourcePath, string $targetPath): void
    {
        self::$calls[] = [$sourcePath, $targetPath];
        if (self::$fail) {
            throw new \RuntimeException('ffmpeg a échoué : fichier illisible');
        }
        file_put_contents($targetPath, self::OUTPUT);
    }
}
