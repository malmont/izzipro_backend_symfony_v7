<?php

namespace App\Gestion\Services;

use App\Entity\Commande;
use App\Gestion\Entity\GestionDocument;
use App\Entity\MarketingCampaign;
use App\Entity\Product;
use App\Services\BoutiqueSettingsService\SiteCurrencyService;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\TenantEntityManagerProvider;

/**
 * Campagnes de publicité (lot A1 de la gestion, 10/11/2026, `docs/publicite-gestion.md`) : validation, devise de la régie et taux (comme les
 * commandes, lot G6), garde de période (classée à sa date de début, 409 PERIOD_CLOSED), cibles facultatives, justificatif, journal.
 */
class MarketingCampaignService
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly PeriodGuard $guard,
        private readonly PeriodCalendar $calendar,
        private readonly ContentAuditRecorder $audit,
        private readonly ?TenantCurrencyProvider $currency = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, ?GestionDocument $document, ?string $by): MarketingCampaign
    {
        $fields = $this->validate($data, true);
        $this->guardDate($fields['startDate']);
        $now = new \DateTimeImmutable();
        $campaign = (new MarketingCampaign())->setCreatedBy($by)->setCreatedAt($now)->setDocument($document);
        $this->apply($campaign, $fields);
        $em = $this->emProvider->getEntityManager();
        $em->persist($campaign);
        $em->flush();
        $this->audit->record('gestion-campagnes', $campaign->getId(), 'create', null, $this->toArray($campaign));

        return $campaign;
    }

    /** @param array<string, mixed> $data */
    public function update(MarketingCampaign $campaign, array $data): MarketingCampaign
    {
        $before = $this->toArray($campaign);
        $this->guardDate($campaign->getStartDate()); // la période d'origine doit être ouverte
        $merged = [
            'name' => $campaign->getName(), 'channel' => $campaign->getChannel(), 'startDate' => $campaign->getStartDate()->format('Y-m-d'), 'endDate' => $campaign->getEndDate()->format('Y-m-d'),
            'amount' => $campaign->getAmountCents(), 'currency' => $campaign->getCurrency(), 'exchangeRate' => $campaign->getExchangeRate(), 'notes' => $campaign->getNotes(),
            'productIds' => array_map(fn (Product $p) => $p->getId(), $campaign->getProducts()->toArray()), 'commandeIds' => array_map(fn (Commande $c) => $c->getId(), $campaign->getCommandes()->toArray()),
        ];
        $fields = $this->validate(array_replace($merged, $data), true);
        $this->guardDate($fields['startDate']); // et la nouvelle
        $this->apply($campaign, $fields);
        $this->emProvider->getEntityManager()->flush();
        $this->audit->record('gestion-campagnes', $campaign->getId(), 'update', $before, $this->toArray($campaign));

        return $campaign;
    }

    public function delete(MarketingCampaign $campaign): void
    {
        $this->guardDate($campaign->getStartDate());
        $em = $this->emProvider->getEntityManager();
        $before = $this->toArray($campaign);
        $id = $campaign->getId();
        $em->getConnection()->executeStatement('UPDATE gestion_document SET campaign_id = NULL WHERE campaign_id = ?', [$id]); // justificatif libéré (purgé ensuite)
        $em->remove($campaign);
        $em->flush();
        $this->audit->record('gestion-campagnes', $id, 'delete', $before, null);
    }

    /** Forme rendue par l'API */
    public function toArray(MarketingCampaign $c): array
    {
        $site = $this->currency?->code() ?? TenantCurrencyProvider::DEFAULT;
        $period = $c->getStartDate() !== null ? $this->calendar->findByDate($c->getStartDate()) : null;

        return [
            'id' => $c->getId(), 'name' => $c->getName(), 'channel' => $c->getChannel(),
            'startDate' => $c->getStartDate()?->format('Y-m-d'), 'endDate' => $c->getEndDate()?->format('Y-m-d'),
            'amount' => $c->getAmountCents(), 'currency' => $c->getCurrency() ?? $site, 'exchangeRate' => $c->getCurrency() === null ? null : $c->getExchangeRate(),
            'amountSite' => $c->amountSite(), 'siteCurrency' => $site,
            'productIds' => array_values(array_map(fn (Product $p) => $p->getId(), $c->getProducts()->toArray())),
            'commandeIds' => array_values(array_map(fn (Commande $x) => $x->getId(), $c->getCommandes()->toArray())),
            'notes' => $c->getNotes(), 'document' => $c->getDocument()?->invoiceSummary(),
            'period' => $period === null ? null : ['id' => $period->getId(), 'periodKey' => $period->getPeriodKey(), 'name' => $period->getNomCollection()],
            'createdAt' => $c->getCreatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    // ----- interne -----

    /** @return array<string, mixed> */
    private function validate(array $data, bool $required): array
    {
        $input = new GestionInput($data);
        $name = $input->text('name', $required);
        $channel = isset($data['channel']) && is_string($data['channel']) ? strtolower(trim($data['channel'])) : null;
        $start = $input->date('startDate', $required);
        $end = $input->date('endDate', $required);
        $amount = $input->cents('amount', $required);
        $notes = $input->text('notes', false, 2000);
        $errors = $input->errors();
        if ($channel === null || !in_array($channel, MarketingCampaign::CHANNELS, true)) {
            $errors['channel'] = 'INVALID_CHANNEL';
        }
        if ($start !== null && $end !== null && $end < $start) {
            $errors['endDate'] = 'BEFORE_START';
        }
        $site = strtoupper($this->currency?->code() ?? TenantCurrencyProvider::DEFAULT);
        $currency = isset($data['currency']) && is_string($data['currency']) && trim($data['currency']) !== '' ? strtoupper(trim($data['currency'])) : null;
        if ($currency !== null && !in_array($currency, SiteCurrencyService::SUPPORTED, true)) {
            $errors['currency'] = 'INVALID_CURRENCY';
        }
        if ($currency === $site) {
            $currency = null; // devise du site : pas de taux
        }
        $rate = null;
        if ($currency !== null) {
            $raw = $data['exchangeRate'] ?? null;
            if ($raw === null || $raw === '') {
                $errors['exchangeRate'] = 'EXCHANGE_RATE_REQUIRED';
            } elseif (!is_numeric($raw) || (float) $raw <= 0) {
                $errors['exchangeRate'] = 'MUST_BE_POSITIVE';
            } else {
                $rate = (float) $raw;
            }
        }
        $em = $this->emProvider->getEntityManager();
        $products = [];
        foreach (array_values(is_array($data['productIds'] ?? null) ? $data['productIds'] : []) as $i => $id) {
            $product = is_numeric($id) ? $em->find(Product::class, (int) $id) : null;
            $product === null ? $errors["productIds[$i]"] = 'PRODUCT_NOT_FOUND' : $products[$product->getId()] = $product;
        }
        $commandes = [];
        foreach (array_values(is_array($data['commandeIds'] ?? null) ? $data['commandeIds'] : []) as $i => $id) {
            $commande = is_numeric($id) ? $em->find(Commande::class, (int) $id) : null;
            $commande === null ? $errors["commandeIds[$i]"] = 'COMMANDE_NOT_FOUND' : $commandes[$commande->getId()] = $commande;
        }
        if ($errors !== []) {
            throw new GestionValidationException($errors, 'Campagne invalide : ' . implode(', ', array_keys($errors)));
        }

        return ['name' => $name, 'channel' => $channel, 'startDate' => \DateTimeImmutable::createFromInterface($start), 'endDate' => \DateTimeImmutable::createFromInterface($end),
            'amount' => $amount, 'currency' => $currency, 'exchangeRate' => $rate, 'notes' => $notes, 'products' => array_values($products), 'commandes' => array_values($commandes)];
    }

    private function apply(MarketingCampaign $c, array $f): void
    {
        $c->setName((string) $f['name'])->setChannel($f['channel'])->setStartDate($f['startDate']->setTime(0, 0))->setEndDate($f['endDate']->setTime(0, 0))
            ->setAmountCents((int) $f['amount'])->setCurrency($f['currency'])->setExchangeRate($f['exchangeRate'])->setNotes($f['notes'])->setUpdatedAt(new \DateTimeImmutable());
        $c->getProducts()->clear();
        foreach ($f['products'] as $p) {
            $c->getProducts()->add($p);
        }
        $c->getCommandes()->clear();
        foreach ($f['commandes'] as $x) {
            $c->getCommandes()->add($x);
        }
    }

    /** Période de la date de début : ouverte (409 PERIOD_CLOSED), dans le calendrier (422 OUT_OF_CALENDAR) */
    private function guardDate(\DateTimeInterface $start): void
    {
        if ($this->guard->assertDateWritable($start) === null) {
            throw new GestionValidationException(['startDate' => 'OUT_OF_CALENDAR'], 'Aucune période du calendrier ne couvre cette date');
        }
    }
}
