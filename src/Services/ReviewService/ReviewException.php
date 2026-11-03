<?php

namespace App\Services\ReviewService;

use App\EventSubscriber\ApiExceptionSubscriber;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Refus d'une opération sur les avis : statut HTTP, message, erreurs par champ (path, message), raison codée (`reason`, en
 * minuscules : disabled, already_reviewed, not_purchased, inchangée). Depuis le 04/11/2026 (B15) : code stable `code()` en
 * majuscules (raison en majuscules, sinon code explicite, sinon code par statut d'ApiExceptionSubscriber) ; chaque élément
 * d'`errors[]` porte `code` et, si le texte cite une valeur, `params` (`error()`). Table : docs/boutique.md, « Avis clients ».
 */
final class ReviewException extends HttpException
{
    /** @param list<array{path: string, message: string, code?: string, params?: array<string, mixed>}> $errors */
    public function __construct(int $status, string $message, public readonly array $errors = [], public readonly ?string $reason = null, private readonly ?string $errorCode = null)
    {
        parent::__construct($status, $message);
    }

    public function code(): string
    {
        return $this->errorCode ?? ($this->reason !== null ? strtoupper($this->reason) : ApiExceptionSubscriber::code($this, $this->getStatusCode()));
    }

    /** @return array{path: string, message: string, code: string, params?: array<string, mixed>} élément d'`errors[]` */
    public static function error(string $path, string $code, string $message, array $params = []): array
    {
        return ['path' => $path, 'message' => $message, 'code' => $code] + ($params !== [] ? ['params' => $params] : []);
    }
}
