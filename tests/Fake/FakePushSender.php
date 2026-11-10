<?php

namespace App\Tests\Fake;

use App\Services\NotificationService\PushSenderInterface;

/** Envoi de notifications simulé (lot N1) : enregistre les envois ; un jeton listé dans `$invalid` est refusé comme par Firebase. */
class FakePushSender implements PushSenderInterface
{
    /** @var list<array{token: string, title: string, body: string, data: array<string, string>}> */
    public static array $sent = [];
    /** @var list<string> */
    public static array $invalid = [];
    public static string $status = self::SENT;

    public static function reset(): void
    {
        self::$sent = [];
        self::$invalid = [];
        self::$status = self::SENT;
    }

    public function send(string $token, string $title, string $body, array $data): array
    {
        if (in_array($token, self::$invalid, true)) {
            return ['status' => self::INVALID_TOKEN, 'error' => 'UNREGISTERED'];
        }
        self::$sent[] = ['token' => $token, 'title' => $title, 'body' => $body, 'data' => $data];

        return ['status' => self::$status, 'error' => null];
    }
}
