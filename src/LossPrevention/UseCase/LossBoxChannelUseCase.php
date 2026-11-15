<?php

namespace App\LossPrevention\UseCase;

use App\LossPrevention\Services\LossBoxChannelService;

/**
 * Ce que le boîtier de la boutique demande à la plateforme (lot L3-a, `docs/prevention-pertes.md` § 16.13) : s'inscrire, échanger
 * son jeton, donner signe de vie, lire ses réglages, envoyer ses faits. `$box` est le boîtier que la garde a authentifié.
 */
class LossBoxChannelUseCase
{
    public function __construct(private readonly LossBoxChannelService $channel)
    {
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    public function enrol(array $body): array
    {
        return $this->channel->enrol($body);
    }

    /** @param array<string, mixed> $body @return array<string, mixed> */
    public function renew(mixed $token, array $body): array
    {
        return $this->channel->renew($token, $body);
    }

    /** @param array<string, mixed> $box @param array<string, mixed> $body @return array<string, mixed> */
    public function heartbeat(array $box, array $body): array
    {
        return $this->channel->heartbeat($box, $body);
    }

    /** @param array<string, mixed> $box @return array<string, mixed> */
    public function settings(array $box): array
    {
        return $this->channel->settings($box);
    }

    /** @param array<string, mixed> $box @param array<string, mixed> $body @return array<string, mixed> */
    public function facts(array $box, array $body): array
    {
        return $this->channel->facts($box, $body);
    }
}
