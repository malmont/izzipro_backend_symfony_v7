<?php

namespace App\UseCase\SiteFeaturesUseCase;

/** Demande refusée : code stable (ALREADY_ENABLED | REQUEST_PENDING), 409 */
final class SiteFeatureRequestException extends \RuntimeException
{
    public function __construct(public readonly string $code409, string $message)
    {
        parent::__construct($message);
    }
}
