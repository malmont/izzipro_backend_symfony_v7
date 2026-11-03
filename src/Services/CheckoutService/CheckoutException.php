<?php

namespace App\Services\CheckoutService;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Refus d'une étape du paiement d'un panier : statut HTTP, message, erreurs par champ (path, message, et depuis le
 * 02/11/2026 code + params), code stable de premier niveau (`code()` : celui donné, sinon celui du statut).
 */
final class CheckoutException extends HttpException
{
    /** 400 de /api/order/create et create-guest (01/11/2026) : vrai si l'autorisation Stripe est annulée (pendant cet appel ou déjà) ; null = non déterminé */
    public ?bool $authorizationCanceled = null;

    /** @param list<array{path: string, message: string, code?: string, params?: array<string, mixed>}> $errors */
    public function __construct(int $status, string $message, public readonly array $errors = [], private readonly ?string $errorCode = null)
    {
        parent::__construct($status, $message);
    }

    /** Code stable (MAJUSCULES_SOULIGNÉES) : celui du refus, sinon celui du statut (BAD_REQUEST, PAYMENT_REQUIRED, FORBIDDEN, NOT_FOUND, CONFLICT…) */
    public function code(): string
    {
        return $this->errorCode ?? \App\EventSubscriber\ApiExceptionSubscriber::code($this, $this->getStatusCode());
    }
}
