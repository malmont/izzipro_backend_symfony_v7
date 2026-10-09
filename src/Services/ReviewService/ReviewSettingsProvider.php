<?php

namespace App\Services\ReviewService;

use App\Entity\ReviewSetting;
use App\Services\TenantEntityManagerProvider;

/** Réglages des avis du site courant ; sans ligne en base, les valeurs par défaut de ReviewSetting (non enregistrées) */
final class ReviewSettingsProvider
{
    public function __construct(private readonly TenantEntityManagerProvider $emProvider)
    {
    }

    public function get(): ReviewSetting
    {
        return $this->emProvider->getEntityManager()->getRepository(ReviewSetting::class)->current() ?? new ReviewSetting();
    }
}
