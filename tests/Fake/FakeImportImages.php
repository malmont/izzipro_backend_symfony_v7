<?php

namespace App\Tests\Fake;

use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Serveur d'images simulé pour les imports (lot IMP-1) : aucun appel réel pendant les tests. Chaque test règle les réponses par
 * adresse ; une adresse inconnue répond 404. Les adresses appelées sont gardées, pour prouver qu'une adresse refusée n'est jamais appelée.
 */
final class FakeImportImages
{
    /** @var array<string, array{status: int, headers: array<string, string>, body: string}> */
    public static array $responses = [];
    /** @var list<string> */
    public static array $calls = [];

    public static function reset(): void
    {
        self::$responses = [];
        self::$calls = [];
    }

    /** Un PNG d'un pixel, valide */
    public static function png(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    }

    public static function respond(string $method, string $url, array $options = []): MockResponse
    {
        self::$calls[] = $url;
        $r = self::$responses[$url] ?? ['status' => 404, 'headers' => [], 'body' => 'absent'];

        return new MockResponse($r['body'], ['http_code' => $r['status'], 'response_headers' => $r['headers']]);
    }
}
