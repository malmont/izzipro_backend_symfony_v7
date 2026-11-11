<?php

namespace App\Gestion\UseCase;

use App\Entity\MarketingCampaign;
use App\Repository\MarketingCampaignRepository;
use App\Gestion\Services\CampaignProfitabilityCalculator;
use App\Gestion\Services\GestionValidationException;
use App\Gestion\Services\MarketingCampaignService;
use App\Services\TenantEntityManagerProvider;

/** Campagnes de publicité (lot A1, 10/11/2026) : liste filtrée, création, lecture, modification, suppression, rentabilité perçue */
class MarketingCampaignUseCase
{
    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly MarketingCampaignService $campaigns, private readonly CampaignProfitabilityCalculator $profitability)
    {
    }

    /** @return array{0: list<array<string, mixed>>, 1: int} */
    public function list(?string $from, ?string $to, ?string $channel, int $page, int $limit): array
    {
        $errors = [];
        $parse = function (?string $v, string $field) use (&$errors): ?\DateTimeImmutable {
            if ($v === null || $v === '') {
                return null;
            }
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
            if ($d === false) {
                $errors[$field] = 'INVALID_DATE';

                return null;
            }

            return $d;
        };
        $fromDate = $parse($from, 'from');
        $toDate = $parse($to, 'to');
        if ($channel !== null && $channel !== '' && !in_array($channel, MarketingCampaign::CHANNELS, true)) {
            $errors['channel'] = 'INVALID_CHANNEL';
        }
        if ($errors !== []) {
            throw new GestionValidationException($errors, 'Filtres invalides');
        }
        /** @var MarketingCampaignRepository $repo */
        $repo = $this->emProvider->getEntityManager()->getRepository(MarketingCampaign::class);
        [$rows, $total] = $repo->search($fromDate, $toDate, $channel ?: null, $page, $limit);

        return [array_map(fn (MarketingCampaign $c) => $this->campaigns->toArray($c), $rows), $total];
    }

    /** 404 CAMPAIGN_NOT_FOUND */
    public function find(int $id): MarketingCampaign
    {
        $campaign = $this->emProvider->getEntityManager()->find(MarketingCampaign::class, $id);
        if ($campaign === null) {
            throw new \App\Gestion\Services\GestionConflictException('CAMPAIGN_NOT_FOUND', 'Campagne introuvable.', 404, ['campaignId' => $id]);
        }

        return $campaign;
    }

    public function create(array $data, ?string $by): array
    {
        return $this->campaigns->toArray($this->campaigns->create($data, null, $by));
    }

    public function get(MarketingCampaign $c): array
    {
        return $this->campaigns->toArray($c);
    }

    public function update(MarketingCampaign $c, array $data): array
    {
        return $this->campaigns->toArray($this->campaigns->update($c, $data));
    }

    public function delete(MarketingCampaign $c): void
    {
        $this->campaigns->delete($c);
    }

    public function profitability(MarketingCampaign $c): array
    {
        return $this->profitability->compute($c);
    }
}
