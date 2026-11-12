<?php

namespace App\Services\Platform;

use App\Entity\AppProfile;
use App\Entity\EmailConfiguration;
use App\Entity\Entreprise;
use App\Entity\Tax;
use App\Services\FeatureAccess\FeatureCatalog;
use App\Services\OrderService\TaxCategory;
use App\Services\OrderService\TaxEngine;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;

/**
 * Référentiels automatiques d'un site (07/11/2026) : à la création du site et sur demande (« Compléter automatiquement » de la page
 * Configuration), ce qui se déduit de la fiche Entreprise est posé sans rien demander : la table des taxes d'après le pays et la province
 * de l'adresse (Canada : TPS, TVQ, TVH, TVP ; France : TVA 20, 10, 5,5 et 2,1 % avec leurs catégories, et prix TTC), l'expéditeur des
 * courriels pris sur le courriel de l'entreprise, un profil d'apparence pour chaque application accordée. Idempotent : rien n'est écrasé,
 * seulement ajouté quand il manque. Les textes légaux restent rédigés par l'assistant (outil `set_entreprise_fields`).
 */
class SiteDefaultsService
{
    /** Taxes canadiennes : nom, taux, nature, provinces (mêmes lignes que le modèle `gmasuite`) */
    private const TAXES_CA = [
        ['TPS', 0.05, 'Fédérale', 'QC,BC,AB,SK,MB,YT,NT,NU'], ['TVQ', 0.09975, 'provinciale', 'QC'], ['TVH', 0.13, 'harmonisée', 'ON'], ['TVH', 0.14, 'harmonisée', 'NS'],
        ['TVH', 0.15, 'harmonisée', 'NB,NL,PE'], ['TVP', 0.07, 'provinciale', 'BC,MB'], ['TVP', 0.06, 'provinciale', 'SK'],
    ];
    /** TVA française : nom, taux, catégorie fiscale (null = standard, frappe tout produit non exonéré) */
    private const TAXES_FR = [['TVA 20 %', 0.20, null], ['TVA 10 %', 0.10, TaxCategory::INTERMEDIATE], ['TVA 5,5 %', 0.055, TaxCategory::REDUCED], ['TVA 2,1 %', 0.021, TaxCategory::SUPER_REDUCED]];
    /** application (profil) ← fonctionnalité accordée */
    private const APP_FEATURES = ['pos' => 'module.pos', 'mobile' => 'app.mobile_shop', 'admin' => 'app.management', 'livingmemory' => 'app.living_memory', 'memoires' => 'module.memoires', 'boussole' => 'module.boussole'];

    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly TenantConnectionManager $tenants, private readonly EntitlementService $entitlements, private readonly LoggerInterface $logger, private readonly ?PlatformModeService $platform = null)
    {
    }

    /**
     * Pose ce qui manque ; le site courant doit être sélectionné.
     * @return array{applied: list<string>, skipped: list<string>}
     */
    public function apply(?string $by = null): array
    {
        $em = $this->emProvider->getEntityManager();
        $entreprise = $em->getRepository(Entreprise::class)->findOneBy([], ['id' => 'ASC']);
        if ($entreprise === null) {
            return ['applied' => [], 'skipped' => ['aucune fiche Entreprise']];
        }
        $applied = [];
        $skipped = [];
        $address = $em->getConnection()->fetchAssociative('SELECT country, state FROM address_entreprise ORDER BY id LIMIT 1') ?: null;
        $normalized = $address ? TaxEngine::normalizeAddress(['country' => $address['country'], 'province' => $address['state']]) : null;

        // Taxes
        $taxes = $em->getRepository(Tax::class)->findBy([], ['id' => 'ASC']);
        $country = $normalized['country'] ?? null;
        if ($country === null) {
            $skipped[] = 'taxes : adresse sans pays';
        } elseif ($country === 'CA') {
            $this->seedTaxes($taxes, 'CA', self::TAXES_CA, $applied, $skipped);
        } elseif ($country === 'FR') {
            $this->seedTaxes($taxes, 'FR', self::TAXES_FR, $applied, $skipped);
            if (!$entreprise->isPricesIncludeTax() && (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM "order"') === 0) {
                $entreprise->setPricesIncludeTax(true); // usage français : prix TTC, tant qu'aucune vente n'a figé le réglage
                $applied[] = 'prix affichés taxes comprises (France)';
            }
        } else {
            $skipped[] = sprintf('taxes : pays %s non fourni d\'office (table à saisir ou Stripe Tax)', $country);
        }

        // Expéditeur des courriels
        $email = trim((string) $entreprise->getEmail());
        $sender = $em->getRepository(EmailConfiguration::class)->findOneBy([], ['id' => 'ASC']);
        if ($email === '') {
            $skipped[] = 'expéditeur : la fiche Entreprise n\'a pas de courriel';
        } elseif ($sender === null) {
            $em->persist((new EmailConfiguration())->setFromEmail($email)->setFromName((string) ($entreprise->getName() ?: $email)));
            $applied[] = sprintf('expéditeur des courriels : %s', $email);
        } elseif (trim((string) $sender->getFromEmail()) === '') {
            $sender->setFromEmail($email);
            if (trim((string) $sender->getFromName()) === '') {
                $sender->setFromName((string) ($entreprise->getName() ?: $email));
            }
            $applied[] = sprintf('expéditeur des courriels : %s', $email);
        }

        // Profils des applications accordées
        $code = (string) $this->tenants->getCurrentTenantCode();
        try {
            $effective = $this->entitlements->effective($code);
        } catch (\Throwable) {
            $effective = [];
        }
        $existing = [];
        foreach ($em->getRepository(AppProfile::class)->findAll() as $profile) {
            $existing[$profile->getApp()] = true;
        }
        foreach (self::APP_FEATURES as $app => $feature) {
            if (!($effective[$feature]['enabled'] ?? false) || isset($existing[$app])) {
                continue;
            }
            $profile = (new AppProfile())->setApp($app)->setAppName((string) $entreprise->getName())->setLogo($entreprise->getLogo() ?: null)->setEmail($email ?: null)->setPhone($entreprise->getTel() ?: null)->setWebsite($entreprise->getWebsite() ?: null);
            $em->persist($profile);
            $applied[] = sprintf('profil de l\'application %s', AppProfile::APPS[$app] ?? $app);
        }
        $em->flush();

        // Essai « Aide IA » à la création (IA bornée, 08/11/2026) : une seule fois, jamais si le site a déjà eu un droit IA (essai expiré compris)
        $trialDays = $this->platform?->aiTrialDays() ?? 0;
        if ($code !== '' && $trialDays > 0) {
            try {
                $hadAi = array_filter($this->entitlements->listForTenant($code), fn ($row) => in_array((string) ($row['feature_key'] ?? $row['featureKey'] ?? ''), ['ai.help', 'ai.full'], true)) !== [];
                if ($hadAi) {
                    $skipped[] = 'essai « Aide IA » : le site a déjà eu un droit sur l\'assistant';
                } else {
                    $this->entitlements->trial($code, 'ai.help', $trialDays, $by, 'Essai automatique à la création du site');
                    $applied[] = sprintf('essai « Aide IA » de %d jours', $trialDays);
                }
            } catch (\Throwable $e) {
                $skipped[] = 'essai « Aide IA » : ' . $e->getMessage();
            }
        }
        if ($applied !== []) {
            $this->logger->info(sprintf('[Référentiels] %s : %s (par %s)', $code, implode(' ; ', $applied), $by ?? 'création du site'));
        }

        return ['applied' => $applied, 'skipped' => $skipped];
    }

    /** @param Tax[] $taxes */
    private function seedTaxes(array $taxes, string $country, array $set, array &$applied, array &$skipped): void
    {
        $em = $this->emProvider->getEntityManager();
        $ownCountry = array_filter($taxes, fn (Tax $t) => strtoupper((string) $t->getCountry()) === $country);
        if ($ownCountry !== []) {
            $skipped[] = sprintf('taxes : %d ligne(s) déjà saisie(s) pour %s', count($ownCountry), $country);

            return;
        }
        // Lignes d'un autre pays venues du modèle (le modèle est canadien) : retirées si aucune commande ne s'y rattache
        $foreign = array_filter($taxes, fn (Tax $t) => (string) $t->getCountry() !== '' && strtoupper((string) $t->getCountry()) !== $country);
        if ($foreign !== [] && (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM order_tax') === 0) {
            foreach ($foreign as $t) {
                $em->remove($t);
            }
            $applied[] = sprintf('%d taxe(s) du modèle retirée(s)', count($foreign));
        }
        foreach ($set as $row) {
            $tax = (new Tax())->setName($row[0])->setRate($row[1])->setCountry($country)->setCode(\App\Services\OrderService\TaxCode::fromLabel($row[0])); // code stable (PAYS-1)
            if ($country === 'CA') {
                $tax->setType($row[2])->setProvince($row[3]);
            } else {
                $tax->setType('TVA')->setProvince('Toutes')->setCategory($row[2]);
            }
            $em->persist($tax);
        }
        $applied[] = sprintf('table des taxes %s (%d ligne(s))', $country === 'CA' ? 'du Canada' : 'de France', count($set));
    }
}
