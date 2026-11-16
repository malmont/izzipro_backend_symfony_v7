<?php

namespace App\Services\Storefront;

/** Refus d'un geste sur l'état public d'un site (lot CONSTRUCTION), avec un code stable que les applications traduisent */
final class StorefrontException extends \RuntimeException
{
    public const MESSAGES = [
        'STOREFRONT_UNDER_CONSTRUCTION' => 'Cette boutique n\'est pas encore ouverte.',
        'STOREFRONT_NOT_READY' => 'La boutique ne peut pas ouvrir sans moyen de paiement.',
        'STOREFRONT_NO_SHEET' => 'Ce site n\'a pas encore de fiche d\'entreprise.',
        'PREVIEW_LINK_INVALID' => 'Lien d\'aperçu invalide ou expiré : demandez-en un nouveau.',
    ];

    /** @param array<string, mixed> $extra */
    public function __construct(public readonly string $errorCode, public readonly int $status, public readonly array $extra = [])
    {
        parent::__construct(self::MESSAGES[$errorCode] ?? $errorCode);
    }

    /** @return array<string, mixed> corps de la réponse d'erreur */
    public function body(): array
    {
        return ['error' => $this->errorCode, 'code' => $this->errorCode, 'message' => $this->getMessage()] + $this->extra;
    }
}
