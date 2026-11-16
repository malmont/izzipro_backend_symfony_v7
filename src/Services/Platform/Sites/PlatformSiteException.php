<?php

namespace App\Services\Platform\Sites;

/** Refus d'un geste sur une demande de création de site par la plateforme, avec un code stable (lot TYPE-1) */
final class PlatformSiteException extends \RuntimeException
{
    public const MESSAGES = [
        'PLATFORM_SITE_CODE_RESERVED' => 'Ce nom de site est réservé.',
        'PLATFORM_SITE_CODE_TAKEN' => 'Ce nom de site est déjà pris.',
        'PLATFORM_SITE_REQUEST_NOT_FOUND' => 'Demande de création introuvable.',
        'PLATFORM_SITE_REQUEST_STATE' => 'Ce geste ne s\'applique pas à l\'état de la demande.',
        'PLATFORM_SITE_ADMIN_ACTIVE' => 'La personne désignée a déjà choisi son mot de passe : elle administre le site. La remplacer se fait dans le site.',
    ];

    /** @param array<string, mixed> $extra */
    public function __construct(public readonly string $errorCode, public readonly int $status, public readonly array $extra = [])
    {
        parent::__construct(self::MESSAGES[$errorCode] ?? $errorCode);
    }

    /** @return array<string, mixed> */
    public function body(): array
    {
        return ['error' => $this->errorCode, 'code' => $this->errorCode, 'message' => $this->getMessage()] + $this->extra;
    }
}
