<?php

namespace App\Services\ShippingService;

use App\Entity\Carrier;
use App\Entity\EasyPostConfiguration;
use App\Services\BoutiqueSettingsService\BoutiqueSettingsService;
use App\Services\TenantEntityManagerProvider;

/**
 * État de la livraison d'un site et son cheminement (livraison, lot 1, 07/11/2026), pour la page « Livraison » du back-office, le
 * cheminement « Préparer mon site » et l'assistant. Trois niveaux voulus par le propriétaire : sans rien (retrait et prix fixes, le
 * marchand expédie lui-même), estimation (poids des produits, classes, gabarits), étiquettes (clé EasyPost du site, ou plateforme
 * avec solde prépayé au lot 2). Lecture seule, SQL léger.
 */
class ShippingSetupService
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly BoutiqueSettingsService $settings,
        private readonly ?\App\Services\TenantConnectionManager $tenants = null, // étiquettes par la plateforme (lot 2, 07/11/2026)
        private readonly ?\App\Services\Platform\ShippingBalanceRepository $balances = null,
        private readonly ?\App\Services\Platform\PlatformShippingService $platform = null,
        private readonly ?ShippingCarrierGatewayInterface $gateway = null,
    ) {
    }

    /** @return array{configured: bool, carriers: int, balanceCents: int, currency: string, feeBp: int, feeFixedCents: int} état des étiquettes par la plateforme pour ce site */
    public function platform(): array
    {
        $code = (string) ($this->tenants?->getCurrentTenantCode() ?? '');
        $settings = $this->platform?->settings() ?? ['feeBp' => 0, 'feeFixedCents' => 0, 'carriers' => []];
        $balance = $code !== '' && $this->balances !== null ? $this->balances->balance($code) : ['balanceCents' => 0, 'currency' => 'CAD'];
        $configured = false;
        try {
            $configured = $this->gateway instanceof EasyPostService ? $this->gateway->platformConfigured() : ($this->gateway?->hasConfiguration() ?? false);
        } catch (\Throwable) {
        }

        return ['configured' => $configured && $settings['carriers'] !== [], 'carriers' => count($settings['carriers']), 'balanceCents' => (int) $balance['balanceCents'], 'currency' => (string) $balance['currency'], 'feeBp' => $settings['feeBp'], 'feeFixedCents' => $settings['feeFixedCents']];
    }

    /**
     * @return array{settings: array{mode: string, easypostEnvironment: string, defaultWeightKg: float, freeShippingAbove: ?int}, carriers: list<array{id: int, name: string, kind: string, price: int, estimatedDays: ?string}>, counts: array{fixed: int, pickup: int, easypost: int, productsToShip: int, productsWithoutWeight: int, shippingClasses: int, packagingTypes: int}, easypostKeys: array{test: bool, live: bool}, steps: list<array{key: string, title: string, description: string, status: string, screen: ?string}>, level: string}
     */
    public function overview(): array
    {
        $em = $this->emProvider->getEntityManager();
        $connection = $em->getConnection();
        $settings = $this->settings->shippingSettings();
        $carriers = [];
        $counts = ['fixed' => 0, 'pickup' => 0, 'easypost' => 0];
        foreach ($em->getRepository(Carrier::class)->findBy([], ['id' => 'ASC']) as $c) {
            $carriers[] = ['id' => (int) $c->getId(), 'name' => (string) $c->getName(), 'kind' => $c->getKind(), 'price' => (int) round((float) $c->getPrice()), 'estimatedDays' => $c->getEstimatedDays()];
            $counts[$c->getKind()] = ($counts[$c->getKind()] ?? 0) + 1;
        }
        $counts['productsToShip'] = $this->count($connection, 'SELECT COUNT(*) FROM product WHERE requires_shipping = TRUE');
        $counts['productsWithoutWeight'] = $this->count($connection, 'SELECT COUNT(*) FROM product p WHERE p.requires_shipping = TRUE AND NOT EXISTS (SELECT 1 FROM product_shipping ps WHERE ps.product_id = p.id AND ps.weight > 0)');
        $counts['shippingClasses'] = $this->count($connection, 'SELECT COUNT(*) FROM shipping_class');
        $counts['packagingTypes'] = $this->count($connection, 'SELECT COUNT(*) FROM packaging_type');
        $keys = ['test' => false, 'live' => false];
        try {
            $config = $em->getRepository(EasyPostConfiguration::class)->findOneBy([]);
            $keys['test'] = trim((string) $config?->getEasypostApiKeySandbox()) !== '';
            $keys['live'] = trim((string) $config?->getEasypostApiKeyProd()) !== '';
        } catch (\Throwable) {
            // clé de production indéchiffrable : comptée absente
        }

        $platform = $this->platform();
        $senderPhone = trim((string) $this->one($connection, 'SELECT phone FROM address_entreprise ORDER BY id LIMIT 1')) !== '';

        return ['settings' => $settings, 'carriers' => $carriers, 'counts' => $counts, 'easypostKeys' => $keys, 'platform' => $platform, 'senderPhone' => $senderPhone, 'steps' => $this->steps($settings, $counts, $keys, $platform, $senderPhone), 'level' => $this->level($settings, $counts, $keys, $platform)];
    }

    /** `manual` (retrait et prix fixes), `estimation` (poids et gabarits en place), `labels` (clé EasyPost prête), `platform` (étiquettes par la plateforme, lot 2) */
    private function level(array $settings, array $counts, array $keys, array $platform = []): string
    {
        $env = $settings['easypostEnvironment'];
        if ($settings['mode'] === 'platform') {
            return ($platform['configured'] ?? false) && ($platform['balanceCents'] ?? 0) > 0 ? 'labels' : 'platform';
        }
        if ($settings['mode'] === 'own_key' && $keys[$env]) {
            return 'labels';
        }

        return $counts['productsToShip'] > 0 && $counts['productsWithoutWeight'] === 0 && $counts['packagingTypes'] > 0 ? 'estimation' : 'manual';
    }

    /** @return list<array{key: string, title: string, description: string, status: string, screen: ?string}> */
    private function steps(array $settings, array $counts, array $keys, array $platform = [], bool $senderPhone = true): array
    {
        $steps = [];
        $add = function (string $key, string $title, string $description, string $status, ?string $screen) use (&$steps) {
            $steps[] = ['key' => $key, 'title' => $title, 'description' => $description, 'status' => $status, 'screen' => $screen];
        };
        $carriers = $counts['fixed'] + $counts['pickup'] + $counts['easypost'];
        $add('shipping.carriers', 'Un mode de remise au moins', 'Un transporteur à prix fixe (livraison standard, locale…) ou le retrait en magasin : vos clients le choisissent au paiement, et vous expédiez vous-même en saisissant le suivi sur la commande.', $carriers > 0 ? 'done' : 'todo', 'carriers');
        $add('shipping.pickup', 'Retrait en magasin', 'Proposez le retrait sur place (toujours gratuit, sans adresse d\'expédition) si vous avez un comptoir.', $counts['pickup'] > 0 ? 'done' : 'optional', 'carriers');
        $add('shipping.free_above', 'Livraison offerte à partir d\'un montant', 'Un seuil au-dessus duquel la livraison est offerte sur les transporteurs payants ; laissez vide pour ne pas l\'offrir.', $settings['freeShippingAbove'] !== null ? 'done' : 'optional', 'shipping');
        $weightsNeeded = $settings['mode'] !== 'none';
        $add('shipping.weights', 'Poids et dimensions des produits', sprintf('%d produit(s) à expédier, %d sans poids. Grille de saisie rapide et estimation par l\'IA ; sans fiche, un produit hérite les valeurs de sa classe, sinon le poids par défaut (%s kg).', $counts['productsToShip'], $counts['productsWithoutWeight'], rtrim(rtrim(number_format($settings['defaultWeightKg'], 3, ',', ' '), '0'), ',')), $counts['productsToShip'] > 0 && $counts['productsWithoutWeight'] === 0 ? 'done' : ($weightsNeeded ? 'todo' : 'optional'), 'product_weights');
        $add('shipping.packaging', 'Gabarits d\'emballage et classes d\'expédition', 'Les gabarits (enveloppe, petite boîte…) et les classes (standard, fragile…) sont fournis d\'office ; adaptez-les à vos cartons pour des tarifs justes.', $counts['packagingTypes'] > 0 && $counts['shippingClasses'] > 0 ? 'done' : ($weightsNeeded ? 'todo' : 'optional'), 'packaging_types');
        if ($settings['mode'] !== 'none' && !$senderPhone) {
            $add('shipping.sender_phone', 'Téléphone de l\'expéditeur', 'Certains transporteurs refusent une étiquette sans téléphone d\'expéditeur : renseignez-le sur l\'adresse de l\'entreprise (fiche Entreprise).', 'todo', 'entreprise');
        }
        if ($settings['mode'] === 'own_key') {
            $env = $settings['easypostEnvironment'];
            $add('shipping.easypost_key', sprintf('Clé EasyPost du site (%s)', $env === 'live' ? 'réel' : 'test'), 'Votre propre compte EasyPost : tarifs en direct au paiement et étiquettes facturées par EasyPost sur votre compte. Saisissez la clé de l\'environnement choisi.', $keys[$env] ? 'done' : 'todo', 'easypost');
        } elseif ($settings['mode'] === 'platform') {
            $ok = ($platform['configured'] ?? false);
            $balance = (int) ($platform['balanceCents'] ?? 0);
            $add('shipping.platform', 'Étiquettes par la plateforme (solde prépayé)', $ok ? sprintf('Solde d\'expédition : %s %s. Depuis une commande, « Acheter l\'étiquette » débite le coût EasyPost%s ; sans solde, expédiez vous-même.', number_format($balance / 100, 2, ',', ' '), $platform['currency'] ?? 'CAD', ($platform['feeBp'] ?? 0) > 0 || ($platform['feeFixedCents'] ?? 0) > 0 ? ' plus les frais de service' : '') : 'La plateforme n\'a pas encore branché sa clé EasyPost et ses comptes transporteurs : en attendant, expédiez vous-même.', $ok && $balance > 0 ? 'done' : ($ok ? 'todo' : 'pending'), 'shipping');
        } else {
            $add('shipping.mode', 'Passer aux étiquettes (facultatif)', 'Aujourd\'hui : estimation et expédition par vos soins. Pour acheter des étiquettes depuis la commande, choisissez « clé EasyPost du site » ou, bientôt, « par la plateforme ».', 'optional', 'shipping');
        }

        return $steps;
    }

    private function one(\Doctrine\DBAL\Connection $connection, string $sql): mixed
    {
        try {
            return $connection->fetchOne($sql);
        } catch (\Throwable) {
            return null;
        }
    }

    private function count(\Doctrine\DBAL\Connection $connection, string $sql): int
    {
        try {
            return (int) $connection->fetchOne($sql);
        } catch (\Throwable) {
            return 0;
        }
    }
}
