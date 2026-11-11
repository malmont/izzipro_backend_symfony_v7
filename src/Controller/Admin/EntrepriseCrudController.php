<?php
namespace App\Controller\Admin;

use App\Entity\Entreprise;
use App\Form\EntrepriseTranslationType;
use App\Form\SocialNetworkType; 
use App\Controller\Admin\BaseTenantCrudController; 
use App\Services\TenantEntityManagerProvider;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;
use App\Repository\SocialNetworkRepository;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;

class EntrepriseCrudController extends BaseTenantCrudController
{
    private AdminUrlGenerator $adminUrlGenerator;

    public function __construct(
        AdminUrlGenerator $adminUrlGenerator,
        TenantEntityManagerProvider $emProvider,
        private readonly \App\Services\AppKeyService\AppActivationService $appActivation,
        private readonly \App\Services\TenantConnectionProvider $tenant,
        private readonly \Symfony\Component\HttpFoundation\RequestStack $requestStack,
        private readonly \App\Services\TenantConnectionManager $tenantManager,
        private readonly ?\App\Services\BoutiqueSettingsService\SiteCurrencyService $siteCurrency = null, // devise du site (F18 partie 1, 01/11/2026)
        private readonly ?\App\Services\OrderService\TaxInclusiveGuard $taxInclusive = null, // prix taxes comprises (F18 partie 2b)
        private readonly ?\App\Services\Platform\EntitlementFlagSync $flagSync = null // droits d'usage alignés sur les activations (console, lot 3, 04/11/2026)
    ) {
        parent::__construct($emProvider);
        $this->adminUrlGenerator = $adminUrlGenerator;
    }

    public function persistEntity(\Doctrine\ORM\EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof Entreprise) {
            $this->enforceSuperAdminFields($entityInstance);
        }
        parent::persistEntity($entityManager, $entityInstance);
    }

    /** Mise à jour habituelle, puis clés d'activation des applications mobiles selon les interrupteurs changés (15/10/2026) */
    public function updateEntity(\Doctrine\ORM\EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof Entreprise) {
            $this->enforceSuperAdminFields($entityInstance);
        }
        $tenantEm = $this->emProvider->getEntityManager();
        $tenantEm->getUnitOfWork()->computeChangeSets();
        $changeSet = $tenantEm->getUnitOfWork()->getEntityChangeSet($entityInstance);
        // Devise du site (F18 partie 1) : refusée si le site a déjà des montants enregistrés, même par requête forgée (champ désactivé dans le formulaire)
        $currencyChange = isset($changeSet['currency']) && $entityInstance instanceof Entreprise ? array_map(fn ($v) => strtoupper((string) $v), $changeSet['currency']) : null;
        if ($currencyChange !== null && $this->siteCurrency !== null) {
            try {
                $this->siteCurrency->assertChangeAllowed($currencyChange[0], $currencyChange[1]);
                $entityInstance->setCurrency($currencyChange[1]);
            } catch (\DomainException $e) {
                $entityInstance->setCurrency($currencyChange[0]);
                $currencyChange = null;
                $this->addFlash('danger', $e->getMessage());
            }
        }
        // Prix taxes comprises (F18 partie 2b) : Stripe Tax, taux non multiple de 0,01 % ou montants déjà enregistrés → refus, même par requête forgée
        $ttcChange = isset($changeSet['pricesIncludeTax']) && $entityInstance instanceof Entreprise ? $changeSet['pricesIncludeTax'] : null;
        if ($ttcChange !== null && $this->taxInclusive !== null) {
            try {
                $this->taxInclusive->assertCanChange((bool) $ttcChange[1]);
            } catch (\DomainException $e) {
                $entityInstance->setPricesIncludeTax((bool) $ttcChange[0]);
                $ttcChange = null;
                $this->addFlash('danger', $e->getMessage());
            }
        }
        parent::updateEntity($entityManager, $entityInstance);
        if ($ttcChange !== null) {
            $this->addFlash('success', $ttcChange[1] ? 'Prix taxes comprises activés : les prix du catalogue sont désormais TTC, la TVA est extraite par taux.' : 'Prix taxes comprises désactivés : les prix du catalogue sont hors taxes.');
        }
        if ($currencyChange !== null && $this->siteCurrency !== null) {
            $outcome = $this->siteCurrency->afterChange($entityInstance, $currencyChange[0], 'entreprise');
            $this->addFlash('success', $outcome['created'] > 0
                ? sprintf('Devise du site : %s. %d coupures par défaut créées pour la caisse (menu Caisse → Coupures).', $outcome['currency'], $outcome['created'])
                : sprintf('Devise du site : %s.', $outcome['currency']));
        }

        $request = $this->requestStack->getCurrentRequest();
        foreach ($this->appActivation->applyChanges($changeSet, $this->tenant->getTenantCode() ?? '', $this->getUser()?->getUserIdentifier() ?? 'admin', $request?->getSchemeAndHttpHost() ?? '') as $app => $message) {
            $this->addFlash(str_contains($message, 'non créée') ? 'danger' : 'success', sprintf('%s : %s', \App\Services\AppKeyService\AppKeyService::LABELS[$app] ?? $app, $message));
        }
        if ($entityInstance instanceof Entreprise) {
            $this->flagSync?->afterWrite($entityInstance); // droits d'usage : vrai sans droit → droit manuel, faux avec droit → annulé
        }
    }

    public static function getEntityFqcn(): string
    {
        return Entreprise::class;
    }

    /**
     * Devise du site (F18 partie 1, 01/11/2026) : CAD, EUR ou USD ; modifiable tant que le site n'a ni commande, ni carte-cadeau ou avoir,
     * ni session de caisse, puis en lecture seule avec le motif. Tous les montants du site (boutique, caisse) sont dans cette devise.
     */
    /** Prix taxes comprises (F18 partie 2b) : verrouillé comme la devise dès le premier montant enregistré ; refusé avec Stripe Tax ou un taux non multiple de 0,01 % */
    private function pricesIncludeTaxField(): BooleanField
    {
        $lock = $this->siteCurrency?->lockMessage();
        $field = BooleanField::new('pricesIncludeTax', 'Prix affichés taxes comprises')->renderAsSwitch(false)->hideOnIndex()
            ->setHelp(($lock !== null ? '🔒 ' . str_replace('Devise verrouillée', 'Prix taxes comprises verrouillés', $lock) . ' ' : '')
                . 'Obligatoire en France pour la vente aux particuliers : les prix du catalogue sont TTC, la TVA est extraite par taux (base = round(TTC / (1 + taux))). '
                . 'À activer seulement quand les applications (caisse, e-commerce) qui savent l\'afficher sont publiées : une application plus ancienne afficherait « TVA 20 % » comme si la taxe s\'ajoutait. '
                . 'Impossible avec Stripe Tax ou un taux non multiple de 0,01 % (ex. 9,975 %). Verrouillé après la première vente, carte-cadeau ou session de caisse.');
        if ($lock !== null) {
            $field->setFormTypeOption('disabled', true);
        }

        return $field;
    }

    private function currencyField(): \EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField
    {
        $lock = $this->siteCurrency?->lockMessage();
        $field = \EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField::new('currency', 'Devise du site')
            ->setChoices(['Dollar canadien (CAD)' => 'CAD', 'Euro (EUR)' => 'EUR', 'Dollar américain (USD)' => 'USD'])
            ->setRequired(true)->hideOnIndex()->setFormTypeOption('placeholder', false)
            ->setHelp($lock ?? 'Devise de tous les montants du site (boutique et caisse), à choisir avant la première vente : ensuite elle est verrouillée. La caisse la lit dans GET /api/pos/settings et GET /api/app-profile/pos ; les coupures par défaut de la devise sont créées si le site n\'en a aucune.');
        if ($lock !== null) {
            $field->setFormTypeOption('disabled', true);
        }

        return $field;
    }

    /** Code court du site (31/10/2026), lecture seule : régénération par le propriétaire de la plateforme (page « Code court de la boutique ») */
    private function shortCodeHelp(): string
    {
        try {
            $code = \App\Services\TenantShortCode::format($this->tenantManager->shortCodeOf($this->tenant->getTenantCode() ?? ''));
        } catch (\Throwable) {
            $code = null;
        }
        if ($code === null) {
            return 'Aucun code court attribué à ce site pour le moment (commande app:tenant:short-codes).';
        }

        return sprintf('<span style="font-size:1.6em;font-weight:700;letter-spacing:.15em">%s</span><br>À imprimer sur vos reçus et vos affiches : vos clients le tapent dans l\'application. Lecture seule ; régénération par le propriétaire de la plateforme (menu Entreprise, « Code court de la boutique »).', htmlspecialchars($code));
    }

    public function configureFields(string $pageName): iterable
    {
        $tenantEm = $this->emProvider->getEntityManager();

        return [
            // Onglets (31/10/2026) : Entreprise, Applications, Caisse, Site web et SEO, Traductions ; panneaux repliables
            FormField::addTab('Entreprise', 'fa fa-building'),
            FormField::addPanel('Identité et coordonnées')->collapsible(),
            IdField::new('id')->onlyOnIndex(),
            TextField::new('name', 'Nom de l\'entreprise'),
            ImageField::new('logo', 'Logo')
                ->setBasePath('/bucket-simulator/assets/uploads/email-logos/')
                ->setUploadDir('var/storage/public_bucket/assets/uploads/email-logos/')
                ->setUploadedFileNamePattern('[randomhash].[extension]')
                ->setRequired(false),
            ImageField::new('faviconFilename', 'Favicon')
                ->setBasePath('/bucket-simulator/assets/uploads/email-logos/')
                ->setUploadDir('var/storage/public_bucket/assets/uploads/email-logos/')
                ->setUploadedFileNamePattern('[randomhash].[extension]')
                ->setRequired(false),        
            TextField::new('adress', 'Adresse'),
            EmailField::new('email', 'E-mail'),
            TextField::new('tel', 'Téléphone'),
            UrlField::new('website', 'Site web'),
            TextField::new('ein', 'EIN (États-Unis)')->setRequired(false)->setHelp('Numéro d\'employeur américain. Un SIRET saisi ici avant le 01/11/2026 reste imprimé sur les tickets d\'un magasin français tant que le champ SIRET est vide.'),
            TextField::new('siret', 'SIRET (France)')->setRequired(false)->hideOnIndex()->setHelp('Imprimé sur les tickets de caisse d\'un magasin en France (store.taxIds), avec le n° de TVA intracommunautaire.'),
            TextField::new('tvaIntracommunautaire', 'TVA intracommunautaire'),
            $this->currencyField(),
            $this->pricesIncludeTaxField(),
            TextField::new('gstNumber', 'Numéro de TPS (Canada)')->setRequired(false)->hideOnIndex()->setHelp('Imprimé sur les tickets de caisse (store.taxNumbers.gst)'),
            TextField::new('qstNumber', 'Numéro de TVQ (Québec)')->setRequired(false)->hideOnIndex()->setHelp('Imprimé sur les tickets de caisse (store.taxNumbers.qst)'),
            FormField::addPanel('Réseaux sociaux')->collapsible(),
            AssociationField::new('socialNetworks', 'Réseaux Sociaux')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => fn(SocialNetworkRepository $repo) => $repo->createQueryBuilder('sn')->orderBy('sn.name', 'ASC'),
                ])
                ->setColumns('col-md-6'),

            FormField::addTab('Applications', 'fa fa-th-large'),
            FormField::addPanel('Activation des Applications (Multi-Module)')->collapsible(),
            $this->superAdminBooleanField('isBoutiqueActive', 'Boutique / e-Commerce', 'Activer la boutique et le catalogue e-commerce pour ce tenant.'),
            $this->superAdminBooleanField('isLandingPageActive', 'Landing Page', 'Activer le site vitrine / landing page.'),
            $this->superAdminBooleanField('isBoussoleEsgActive', 'Boussole ESG', 'Activer le module de diagnostic et bilans ESG.'),
            $this->superAdminBooleanField('isMemoireVivanteActive', 'Mémoire Vivante', 'Activer l\'application Mémoire Vivante.'),

            FormField::addPanel('Code court de la boutique')->collapsible()->setHelp($this->shortCodeHelp()),
            FormField::addPanel('Applications mobiles')->collapsible()
                ->setHelp('Activer génère une clé d\'activation et l\'envoie (clé + code QR) à l\'e-mail de l\'entreprise ; désactiver révoque les clés de l\'application. Détail : menu Caisse → Clés des applications.'),
            $this->superAdminBooleanField('isPosActive', 'Caisse (POS)'),
            $this->superAdminBooleanField('isMobileShopActive', 'E-commerce mobile'),
            $this->superAdminBooleanField('isManagementAppActive', 'Application de gestion'),
            $this->superAdminBooleanField('isLivingMemoryActive', 'LivingMemory (Mémoires Vivantes)'),
            $this->superAdminBooleanField('variantPromotionsEnabled', 'Promotions par variante', 'Lot VAR : à allumer seulement quand la caisse, la boutique Next et l\'app mobile savent appliquer une promotion de variante (pack hors ligne v4).', true),

            FormField::addPanel('Assistant IA')->collapsible()
                ->setHelp('Paliers de l\'assistant IA (console IA, lot 6) : Aide IA explique et guide ; IA complète propose des réglages et des opérations sur les commandes, confirmées par l\'administrateur. Projetés depuis les droits d\'usage de la Plateforme ; en mode démo de la plateforme, tout est ouvert.'),
            $this->superAdminBooleanField('isAiHelpActive', 'Aide IA'),
            $this->superAdminBooleanField('isAiFullActive', 'IA complète'),

            FormField::addTab('Caisse', 'fa fa-cash-register'),
            FormField::addPanel('Réglages généraux de la caisse')->collapsible()
                ->setHelp('Écarts, validations, PIN, paiements acceptés, ticket. Les autres sections sont repliées : cliquer sur un titre pour l\'ouvrir.'),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posDiscrepancyThreshold', 'Écart de caisse toléré sans motif (cents)')
                ->setHelp('À la fermeture, un écart entre espèces comptées et attendues au-delà de ce montant exige un motif. 0 : tout écart demande un motif.')
                ->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posCashRoundingStep', 'Arrondi des paiements en espèces (cents)')
                ->setHelp('Vide : selon la devise (5 ¢ pour le dollar canadien, aucun sinon). 1 : aucun arrondi.')
                ->setRequired(false)->hideOnIndex(),
            // Validation d'un responsable pour un caissier (C3) : seuil en cents, 0 = toujours, vide = jamais
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posApprovalRefund', 'Validation : remboursement au-delà de (cents)')
                ->setHelp('0 : toujours ; vide : jamais')->setRequired(false)->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posApprovalWithdrawal', 'Validation : retrait d\'espèces au-delà de (cents)')
                ->setHelp('0 : toujours ; vide : jamais')->setRequired(false)->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posApprovalFundWithdrawal', 'Validation : retrait de fond au-delà de (cents)')
                ->setHelp('0 : toujours ; vide : jamais')->setRequired(false)->hideOnIndex(),
            BooleanField::new('posApprovalDrawerOpen', 'Validation : ouverture du tiroir sans vente')->hideOnIndex(),
            BooleanField::new('posApprovalCloseWithoutCount', 'Validation : fermeture sans comptage')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posLockMinutes', 'Verrouillage du POS après (minutes)')
                ->setHelp('Inactivité avant verrouillage de l\'écran ; 0 : jamais')->hideOnIndex(),
            // Lot R1 (23/10/2026) : réglages de caisse par site, défauts = comportement livré
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posPinMinLength', 'PIN : longueur minimale')->setHelp('4 à 8 chiffres ; défaut 4')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posPinMaxLength', 'PIN : longueur maximale')->setHelp('jusqu\'à 12 chiffres ; défaut 6')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posPinMaxAttempts', 'PIN : essais avant blocage')->setHelp('3 à 10 ; défaut 5')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posPinLockMinutes', 'PIN : durée du blocage (minutes)')->setHelp('défaut 15')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posOpeningFundSuggested', 'Fond de caisse suggéré à l\'ouverture (cents)')->setRequired(false)->setHelp('Vide : aucune suggestion')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posClosingKeptFundSuggested', 'Fond laissé suggéré à la fermeture (cents)')->setRequired(false)->setHelp('Vide : le fond courant, comme aujourd\'hui')->hideOnIndex(),
            BooleanField::new('posPayCash', 'POS : espèces')->hideOnIndex(),
            BooleanField::new('posPayDeclaredCard', 'POS : carte déclarée (sans terminal)')->hideOnIndex(),
            BooleanField::new('posPayPaypal', 'POS : PayPal')->hideOnIndex(),
            BooleanField::new('posPayMulti', 'POS : paiement en plusieurs parts')->hideOnIndex(),
            BooleanField::new('posDeclaredCardBlockedWithTerminal', 'Refuser la carte déclarée sur un poste à terminal actif')->setHelp('Anti-fraude ; désactivé par défaut')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField::new('posReceiptHeader', 'Ticket : texte d\'en-tête')->setRequired(false)->setHelp('Texte simple, retours à la ligne conservés, 500 caractères')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField::new('posReceiptFooter', 'Ticket : texte de pied')->setRequired(false)->setHelp('Par exemple « Merci » ou la politique de retour')->hideOnIndex(),
            BooleanField::new('posReceiptShowTaxNumbers', 'Ticket : afficher les numéros de taxes')->hideOnIndex(),
            // Ticket numérique (F3, 24/10/2026)
            BooleanField::new('posReceiptEmailEnabled', 'Ticket : reçu par courriel')->setHelp('Envoi par le SMTP du site ; sans configuration SMTP, le POS reçoit 503 EMAIL_NOT_CONFIGURED')->hideOnIndex(),
            BooleanField::new('posReceiptQrEnabled', 'Ticket : reçu par QR code (lien public signé)')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posReceiptLinkDays', 'Ticket : validité du lien du reçu (jours)')->setHelp('défaut 90')->hideOnIndex(),
            BooleanField::new('posReceiptQrPrintDefault', 'Ticket : QR imprimé par défaut')->hideOnIndex(),
            // Lot France (02/11/2026) : ticket imprimé à chaque vente ou remis sur demande (loi AGEC depuis le 01/08/2023)
            \EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField::new('posReceiptPrintMode', 'Ticket : impression')->setChoices(['Selon le pays du magasin (défaut)' => '', 'Automatique à chaque vente' => 'auto', 'Sur demande du client' => 'on_demand'])
                ->setRequired(false)->setFormTypeOption('placeholder', false)->hideOnIndex()
                ->setHelp('Vide : « sur demande » si le magasin est en France ou dans un DROM (Martinique, Guadeloupe, Guyane, Réunion, Mayotte ; loi AGEC : ticket remis seulement sur demande depuis le 01/08/2023), « automatique » ailleurs. La caisse lit settings.receipt.printMode.'),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posHeldCartsMax', 'Paniers en attente : nombre maximal')->setHelp('défaut 10')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posHeldCartsAlertHours', 'Paniers en attente : alerte après (heures)')->setHelp('défaut 24')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posCashAlertThreshold', 'Alerte « trop d\'espèces » au-delà de (cents)')->setRequired(false)->setHelp('Vide : aucune alerte ; le POS invite à un retrait')->hideOnIndex(),
            BooleanField::new('posTerminalHeaderRequired', 'Exiger un terminal identifié (X-Pos-Terminal) sur les routes de caisse')->setHelp('Désactivé par défaut ; à activer quand toutes les tablettes envoient l\'en-tête')->hideOnIndex(),
            // Mode hors ligne (C5 partie 2, 25/10/2026) : désactivé par défaut
            FormField::addPanel('Mode hors ligne (caisse)')->collapsible()->renderCollapsed()->setHelp('Ventes en espèces sans réseau, synchronisées ensuite ; désactivé d\'office si les taxes du site viennent de Stripe Tax.'),
            BooleanField::new('posOfflineEnabled', 'Mode hors ligne autorisé')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posOfflineMaxHours', 'Durée maximale hors ligne (heures)')->setHelp('défaut 24')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posOfflineMaxQueuedSales', 'Ventes en file au plus')->setHelp('défaut 100')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posOfflineMaxQueuedAmount', 'Montant en file au plus (cents)')->setHelp('défaut 500000 = 5 000,00')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posOfflinePriceTolerance', 'Écart de prix toléré par vente (cents)')->setHelp('Recalcul au pack déclaré ; au-delà, vente refusée sauf acceptation forcée par un responsable ; défaut 5')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posOfflinePackValidityHours', 'Validité du pack hors ligne (heures)')->setHelp('défaut 24')->hideOnIndex(),

            // Conservation des données de vente (A1, 30/10/2026)
            FormField::addPanel('Conservation des données de vente (caisse)')->collapsible()->renderCollapsed()->setHelp('Durée de conservation : défaut du pays (Canada 6 ans après la fin de l\'année, France et DROM 10 ans), jamais moins que le plancher légal ; exercice supposé = année civile (à confirmer par le comptable). Pendant la durée, la suppression des données de caisse et des commandes payées est refusée ; les modifications sont journalisées (mode observation) ou refusées (mode blocage). Aucune purge automatique à l\'échéance.'),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('dataRetentionYears', 'Durée de conservation (années)')->setRequired(false)->setHelp('Vide = défaut du pays')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField::new('retentionWriteMode', 'Modifications des données de caisse')->setChoices(['Observation : journalisées (RETENTION_WRITE_OBSERVED)' => 'observe', 'Blocage : refusées' => 'block'])->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posGiftCardCodeAttempts', 'Codes de cartes-cadeaux refusés : essais par 10 minutes')->setHelp('Par utilisateur et par terminal ; défaut 10, 0 = sans limite')->hideOnIndex(),

            // Fuseau horaire et comptabilité (F14, 29/10/2026)
            FormField::addPanel('Fuseau horaire et comptabilité (caisse)')->collapsible()->renderCollapsed()->setHelp('Fuseau horaire du magasin pour les journées des statistiques et des exports (vide = déduit du pays et de la province : Québec et Ontario America/Toronto, Manitoba America/Winnipeg, Alberta America/Edmonton, Colombie-Britannique America/Vancouver, Martinique America/Martinique, France Europe/Paris). Plan de comptes : une ligne par clé « cle=numero;Nom » ; vide = plan par défaut québécois (voir docs/caisse.md). Les réservations de la boutique suivent ce fuseau (America/Toronto à défaut).'),
            TextField::new('posTimezone', 'Fuseau horaire du magasin (IANA)')->setRequired(false)->setHelp('p. ex. America/Toronto, America/Martinique, Europe/Paris')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField::new('purchasePeriodFrequency', 'Périodes de gestion')->setChoices(['Trimestres saisonniers (défaut)' => 'quarterly', 'Semestres' => 'semester', 'Mois' => 'monthly'])->setRequired(false)->hideOnIndex()
                ->setHelp('Découpage des périodes de l\'application de gestion (printemps, été, automne, hiver par défaut) ; un changement vaut pour les périodes à venir. Clôture automatique 15 jours après la fin (docs/periodes-gestion.md).'),
            \EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField::new('accountingAccounts', 'Plan de comptes (une ligne par clé : cle=numero;Nom)')->setRequired(false)->setHelp('Clés : sales, sales_rate_<taux>, returns, discounts, shipping_revenue, tax_gst, tax_qst, tax_hst, tax_hst_<province>, tax_pst, tax_vat, tax_vat_<taux>, cash, bank, card_clearing, interac_clearing, stripe_clearing, stripe_fees, commission, stripe_payout, gift_card_liability, store_credit_liability, cash_discrepancy, cash_rounding, loyalty_rewards')->hideOnIndex(),

            // Fiche client et fidélité (F8, 28/10/2026)
            FormField::addPanel('Clients et fidélité (caisse)')->collapsible()->renderCollapsed()->setHelp('Fiche client de caisse (nom, téléphone, courriel, consentements) distincte du compte web, rattachable à un compte existant avec le consentement du client ; aucun compte web ni courriel n\'est créé. Fidélité : points gagnés sur le net des articles (hors taxes, hors part payée par carte-cadeau), rachetés en remise de ticket calculée par le serveur. Données personnelles (Loi 25, RGPD) : consentements datés par finalité, marketing séparé et désactivé par défaut, journal des consultations, effacement = anonymisation.'),
            BooleanField::new('posCustomersEnabled', 'Fiche client activée')->hideOnIndex(),
            BooleanField::new('posCustomerLinkEnabled', 'Rattachement d\'un compte web autorisé (avec consentement)')->hideOnIndex(),
            BooleanField::new('posLoyaltyEnabled', 'Programme de fidélité activé')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posLoyaltyPointsPerDollar', 'Points gagnés par dollar dépensé')->setHelp('défaut 1')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posLoyaltyValuePer100', 'Valeur de 100 points (cents)')->setHelp('défaut 100 = 1,00 $')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posLoyaltyMinRedeem', 'Minimum de points pour un rachat')->setHelp('défaut 500')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posLoyaltyRedeemStep', 'Pas de rachat (points)')->setHelp('défaut 100')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posLoyaltyExpiryMonths', 'Expiration des points (mois sans activité)')->setRequired(false)->setHelp('Vide = jamais')->hideOnIndex(),
            TextField::new('posConsentTextVersion', 'Version du texte des consentements')->setRequired(false)->setHelp('Référence enregistrée avec chaque consentement, p. ex. 2026-10')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posCustomerSearchPerMinute', 'Recherches de clients par utilisateur et par minute')->setHelp('Empêche de tester des courriels un par un pour savoir qui a un compte web ; défaut 30, 0 = sans limite')->hideOnIndex(),

            // Cartes-cadeaux et avoirs (F9, 27/10/2026) : désactivés par défaut
            FormField::addPanel('Cartes-cadeaux et avoirs (caisse)')->collapsible()->renderCollapsed()->setHelp('Carte-cadeau vendue en caisse (code unique non devinable, ticket Code 128 et QR, ou carte physique pré-imprimée) et avoir émis par un retour, utilisables comme moyen de paiement. La vente d\'une carte est un passif, pas un chiffre d\'affaires : taxes à l\'utilisation. Expiration : la règle dépend du pays. Au Québec, une carte prépayée vendue contre de l\'argent ne peut pas expirer et un solde de 5,00 $ ou moins doit être remboursé en espèces sur demande (vide = jamais d\'expiration, seuil 500). La France ou d\'autres pays ont d\'autres règles : ajustez ces valeurs.'),
            BooleanField::new('posGiftCardEnabled', 'Cartes-cadeaux activées')->hideOnIndex(),
            TextField::new('posGiftCardAmounts', 'Montants proposés (cents, séparés par des virgules)')->setRequired(false)->setHelp('Vide : 2500, 5000, 10000 (25, 50 et 100 $) ; le montant libre reste possible')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posGiftCardExpiryMonths', 'Expiration des cartes (mois)')->setRequired(false)->setHelp('Vide = jamais (Québec)')->hideOnIndex(),
            BooleanField::new('posStoreCreditEnabled', 'Avoirs activés (remboursement d\'un retour en avoir)')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posStoreCreditExpiryMonths', 'Expiration des avoirs (mois)')->setRequired(false)->setHelp('Vide = jamais')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posGiftCardCashRefundMax', 'Remboursement en espèces d\'un solde : seuil (cents)')->setHelp('Jusqu\'à ce solde, le client peut exiger les espèces ; défaut 500 = 5,00 $ (Québec)')->hideOnIndex(),
            BooleanField::new('posGiftCardCashRefundBeyond', 'Remboursement en espèces au-delà du seuil (avec validation d\'un responsable)')->hideOnIndex(),

            // Coupons de fabricant (F17, 01/11/2026) : désactivés par défaut
            FormField::addPanel('Coupons de fabricant (caisse)')->collapsible()->renderCollapsed()->setHelp('Bons de réduction émis par une marque et remboursés au commerçant par le fabricant ou une chambre de compensation. Acceptés comme un moyen de paiement : la TPS et la TVQ restent calculées sur le prix plein (Loi sur la taxe d\'accise, art. 181(2)). Rapport des coupons à réclamer : menu « Coupons de fabricant (caisse) ».'),
            BooleanField::new('posCouponEnabled', 'Coupons de fabricant acceptés')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posCouponMaxWithoutApproval', 'Coupons : montant maximal par vente sans validation (cents)')->setHelp('Total des coupons d\'une vente ; au-delà, validation d\'un responsable (action manufacturer_coupon). 0 = toujours une validation. Défaut 2000 (20 $).')->hideOnIndex(),

            // Paiements de caisse en production (M1) : mode affiché ici, changé seulement depuis la page « Paiements de caisse (Stripe) » (contrôles, journal)
            FormField::addPanel('Paiements de caisse et surveillance (caisse)')->collapsible()->renderCollapsed()->setHelp('Mode Stripe de la caisse (test | réel) : page « Paiements de caisse (Stripe) » du menu Caisse, qui contrôle le compte Stripe réel avant le passage. Alertes : courriel au contact du site quand la surveillance relève un point à vérifier ou critique (réconciliation arrêtée, paiements sans vente, remboursements Interac expirés, sessions ouvertes depuis plus de 24 h, échecs de capture, compte Stripe suspendu) ; même situation renvoyée au plus toutes les 6 heures ; aucune donnée de carte.'),
            TextField::new('posStripeMode', 'Paiements de caisse')->hideOnForm()->hideOnIndex()->formatValue(fn ($v) => $v === 'live' ? 'RÉEL' : 'test'),
            BooleanField::new('posAlertsEnabled', 'Alertes de surveillance par courriel au contact du site')->hideOnIndex(),
            EmailField::new('posAlertsEmail', 'Adresse des alertes (vide : courriel de l\'entreprise)')->setRequired(false)->hideOnIndex(),

            // Grille de favoris (F10) : désactivée par défaut
            FormField::addPanel('Grille de favoris (caisse)')->collapsible()->renderCollapsed()->setHelp('Grille de produits à toucher sur l\'écran de vente (articles sans code-barres, ventes fréquentes) : produits épinglés par le responsable d\'abord, dans l\'ordre choisi (menu « Favoris de la caisse » ; les épinglés d\'un poste remplacent ceux du site pour ce poste), puis les plus vendus en caisse sur la période, nets des retours, pour compléter la grille.'),
            BooleanField::new('posFavoritesEnabled', 'Grille de favoris activée')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField::new('posFavoritesMode', 'Contenu de la grille')->hideOnIndex()
                ->setChoices(['Épinglés puis plus vendus' => 'both', 'Épinglés seulement' => 'pinned', 'Plus vendus seulement' => 'bestSellers']),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posFavoritesDays', 'Plus vendus : nombre de jours')->setHelp('Ventes de caisse des N derniers jours (fuseau du magasin), de 1 à 365. Défaut 30.')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posFavoritesGridSize', 'Taille de la grille')->setHelp('Nombre de cases, de 1 à 100. Défaut 24.')->hideOnIndex(),

            // Article libre et prix manuel (F7, 26/10/2026) : désactivés par défaut
            FormField::addPanel('Article libre et prix manuel (caisse)')->collapsible()->renderCollapsed()->setHelp('Article libre : vente d\'un article absent du catalogue (libellé, prix saisi, taxable ou non), sans mouvement de stock. Prix manuel : prix catalogue remplacé sur une ligne, avec motif. Au-delà du montant maximal, validation d\'un responsable ; vide = jamais, 0 = toujours.'),
            BooleanField::new('posCustomItemEnabled', 'Article libre autorisé')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posCustomItemMaxAmount', 'Article libre : montant maximal par vente sans validation (cents)')->setRequired(false)->setHelp('Total des articles libres de la vente ; vide = jamais de validation')->hideOnIndex(),
            BooleanField::new('posPriceOverrideEnabled', 'Prix manuel autorisé')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posPriceOverrideMaxAmount', 'Prix manuel : baisse maximale par vente sans validation (cents)')->setRequired(false)->setHelp('Somme des baisses par rapport au prix catalogue ; une hausse n\'est jamais soumise à validation ; vide = jamais')->hideOnIndex(),
            BooleanField::new('posPriceOverrideReasonRequired', 'Prix manuel : motif obligatoire')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField::new('posPriceOverrideReasons', 'Motifs de prix manuel (un par ligne)')->setRequired(false)->setHelp('Vide : Article abîmé, Prix concurrent, Prix négocié, Erreur d\'étiquette, Autre')->hideOnIndex(),

            // Remises de caisse (F1, 24/10/2026)
            FormField::addPanel('Remises (caisse)')->collapsible()->renderCollapsed()->setHelp('Remises de ligne et de ticket accordées par le caissier, calculées par le serveur (taxes sur le net). Plafonds par rôle ; au-delà du plafond caissier, validation d\'un responsable ; l\'administrateur n\'a pas de plafond.'),
            BooleanField::new('posDiscountEnabled', 'Remises autorisées')->hideOnIndex(),
            BooleanField::new('posDiscountTicketEnabled', 'Remise sur le ticket entier autorisée')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posDiscountCashierMaxPercentBp', 'Caissier : pourcentage maximal (centièmes de %)')->setHelp('1000 = 10 %')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posDiscountCashierMaxAmount', 'Caissier : montant maximal par vente (cents)')->setHelp('2000 = 20,00')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posDiscountManagerMaxPercentBp', 'Responsable : pourcentage maximal (centièmes de %)')->setHelp('5000 = 50 %')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posDiscountManagerMaxAmount', 'Responsable : montant maximal par vente (cents)')->setHelp('20000 = 200,00')->hideOnIndex(),
            BooleanField::new('posDiscountReasonRequired', 'Motif obligatoire')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField::new('posDiscountReasons', 'Motifs de remise (un par ligne)')->setRequired(false)->setHelp('Vide : Article abîmé, Fidélité, Prix affiché, Geste commercial, Autre')->hideOnIndex(),

            // Retours partiels et échanges (F2, 24/10/2026)
            FormField::addPanel('Retours et échanges (caisse)')->collapsible()->renderCollapsed()->setHelp('Retour d\'un ou plusieurs articles d\'une vente retrouvée par son ticket, et échanges. Défauts = comportement livré.'),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posReturnMaxDays', 'Délai maximal de retour (jours)')->setRequired(false)->setHelp('Vide : aucun délai')->hideOnIndex(),
            BooleanField::new('posReturnWithoutReceipt', 'Retour sans ticket autorisé')->setHelp('Articles repris aux prix actuels, sans vente d\'origine ; désactivé par défaut')->hideOnIndex(),
            BooleanField::new('posReturnRestockDefault', 'Remise en stock par défaut')->hideOnIndex(),
            BooleanField::new('posReturnCashForCardSale', 'Remboursement en espèces d\'une vente payée par carte')->setHelp('Désactivé par défaut (carte volée remboursée en espèces) ; l\'annulation complète n\'est pas concernée')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField::new('posReturnReasons', 'Motifs de retour (un par ligne)')->setRequired(false)->setHelp('Vide : Taille ou modèle, Défectueux, Ne convient pas, Erreur de caisse, Autre')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField::new('posApprovalReturnMode', 'Validation d\'un retour')->setChoices(['Même seuil que le remboursement' => 'refund', 'Seuil propre ci-dessous' => 'custom'])->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posApprovalReturn', 'Validation : retour au-delà de (cents)')->setHelp('Seuil propre : 0 toujours ; vide : jamais')->setRequired(false)->hideOnIndex(),

            // Terminal de paiement Stripe (C4, 23/10/2026) : valeurs par défaut = comportement livré (1 %, Interac selon le pays, 120 s, 30 min, 5 min)
            FormField::addPanel('Terminal de paiement Stripe (caisse)')->collapsible()->renderCollapsed()
                ->setHelp('Lecteur par poste : menu Postes de caisse. Commissions de la plateforme en centièmes de pour cent (100 = 1 %). Les paiements en ligne gardent leur commission actuelle.'),
            $this->superAdminIntegerField('posTerminalFeeCardBp', 'Commission en magasin, carte de crédit (centièmes de %)'),
            $this->superAdminIntegerField('posTerminalFeeInteracBp', 'Commission en magasin, Interac (centièmes de %)'),
            \EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField::new('posTerminalInterac', 'Interac accepté sur le terminal')
                ->setChoices(['Oui' => true, 'Non' => false])->setFormTypeOption('placeholder', 'Selon le pays du site (oui au Canada)')->setRequired(false)->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posTerminalCardWaitSeconds', 'Attente de la carte sur le terminal (secondes)')
                ->setHelp('Au-delà, le paiement est annulé (15 à 600)')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posTerminalAuthCancelMinutes', 'Annulation d\'une autorisation carte sans vente après (minutes)')
                ->setHelp('Carte de crédit autorisée mais vente jamais enregistrée ; Stripe libère de toute façon sous 2 jours')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posTerminalOrphanMinutes', 'Paiement considéré sans vente après (minutes)')
                ->setHelp('Liste des paiements réussis sans vente pour le responsable')->hideOnIndex(),
            BooleanField::new('posTerminalCustomerCancel', 'Annulation par le client sur l\'écran du lecteur')->hideOnIndex(),
            BooleanField::new('posTerminalCartDisplay', 'Afficher le panier sur le lecteur')->hideOnIndex(),
            // Kit Stripe Terminal dans l'application (F13/F16, 31/10/2026) : défaut non
            BooleanField::new('posTapToPayAllowed', 'Tap to Pay autorisé (téléphone ou tablette lit la carte)')->hideOnIndex()
                ->setHelp('Kit Stripe Terminal dans l\'application : le serveur fournit les jetons et crée le paiement, l\'application lit la carte. Le mode se choisit par poste (menu Postes de caisse).'),
            BooleanField::new('posBluetoothReaderAllowed', 'Lecteur Bluetooth autorisé (BBPOS WisePad 3)')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posTerminalRefundPendingMinutes', 'Remboursement Interac en attente sur le lecteur : expiration (minutes)')
                ->setHelp('Kit seulement : au-delà, le serveur relit Stripe (remboursement fait → enregistré ; sinon demande expirée, paiement de nouveau remboursable)')->hideOnIndex(),

            FormField::addTab('Site web et SEO', 'fa fa-globe'),
            FormField::addPanel('Suivi')->collapsible(),
            TextField::new('facebookPixelId', 'Facebook Pixel ID')->hideOnIndex(),
            // Formulaires à étapes (28/10/2026) : destinataire et conservation des demandes reçues
            FormField::addPanel('Demandes reçues (formulaires)')->collapsible()->setHelp('Demandes envoyées par les formulaires à étapes du site (menu « Demandes reçues »). Notification à l\'adresse ci-dessous, sinon au courriel de l\'entreprise. Les demandes sont supprimées après la durée de conservation (12 mois par défaut).'),
            EmailField::new('formSubmissionsEmail', 'Adresse qui reçoit les demandes (vide : courriel de l\'entreprise)')->setRequired(false)->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('formSubmissionsRetentionMonths', 'Conservation des demandes (mois, 1 à 120 ; vide : 12)')->setRequired(false)->hideOnIndex(),

            FormField::addPanel('Textes légaux et à propos')->collapsible(),
            TextareaField::new('conditionOfUse', 'Conditions d\'utilisation'),
            TextareaField::new('LegalNotice', 'Mentions légales'),
            TextareaField::new('privacyPolicy', 'Politique de confidentialité'),
            TextareaField::new('Apropos', 'A propos'),
            AssociationField::new('addressEntreprise', 'Adresse Entreprise')
                ->formatValue(function ($value, $entity) {
                    $address = $entity->getAddressEntreprise();
                    if (!$address) {
                        return 'Aucune adresse';
                    }
                    $url = $this->adminUrlGenerator
                        ->setController(AddressEntrepriseCrudController::class)
                        ->setAction(Action::DETAIL)
                        ->setEntityId($address->getId())
                        ->generateUrl();

                    return sprintf('<a href="%s" target="_blank">Voir l\'adresse</a>', $url);
                })
                ->renderAsHtml()
                ->onlyOnIndex(),


            FormField::addPanel('Référencement & SEO')->collapsible(),
            TextField::new('metaTitle', 'Meta Title')
                ->setHelp('Titre pour les moteurs de recherche (60-70 caractères)')
                ->hideOnIndex(),
            TextareaField::new('metaDescription', 'Meta Description')
                ->setHelp('Description affichée dans Google (150-160 caractères)')
                ->hideOnIndex(),
            TextField::new('seoKeywords', 'Mots-clés SEO')
                ->setHelp('Mots-clés séparés par des virgules (ex: conciergerie, intendance, montreal)')
                ->hideOnIndex(),
            ImageField::new('ogImage', 'Image Open Graph (og:image)')
                ->setBasePath('/bucket-simulator/assets/uploads/email-logos/')
                ->setUploadDir('var/storage/public_bucket/assets/uploads/email-logos/')
                ->setUploadedFileNamePattern('[randomhash].[extension]')
                ->setRequired(false)
                ->setHelp('Image affichée lors du partage sur les réseaux sociaux (1200x630px recommandé)')
                ->hideOnIndex(),
            TextField::new('googleSiteVerification', 'Google Site Verification')
                ->setHelp('Code de vérification Google Search Console')
                ->hideOnIndex(),

            FormField::addTab('Traductions', 'fa fa-language'),
            CollectionField::new('translations', 'Contenus Traduits')
                ->setEntryType(EntrepriseTranslationType::class)
                ->setFormTypeOption('by_reference', false)
                ->onlyOnForms(),

        ];
    }

    private function superAdminBooleanField(string $property, string $label, ?string $help = null, bool $hideOnIndex = false): BooleanField
    {
        $isSuperAdmin = $this->isGranted('ROLE_SUPER_ADMIN');
        $field = BooleanField::new($property, $label);
        if ($hideOnIndex) {
            $field->hideOnIndex();
        }
        $helpText = trim(($help !== null ? $help . ' ' : '') . (!$isSuperAdmin ? 'Modifiable uniquement par le super administrateur.' : ''));
        if ($helpText !== '') {
            $field->setHelp($helpText);
        }
        if (!$isSuperAdmin) {
            $field->renderAsSwitch(false)->setFormTypeOption('attr', ['disabled' => 'disabled']);
        }

        return $field;
    }

    private function superAdminIntegerField(string $property, string $label, int $default = 100): \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField
    {
        $isSuperAdmin = $this->isGranted('ROLE_SUPER_ADMIN');
        $field = \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new($property, $label)->hideOnIndex();
        if (!$isSuperAdmin) {
            $field
                ->setHelp('Modifiable uniquement par le super administrateur.')
                ->setRequired(false)
                ->setFormTypeOption('empty_data', (string) $default)
                ->setFormTypeOption('attr', ['disabled' => 'disabled', 'readonly' => 'readonly']);
        }

        return $field;
    }

    /** Réimpose les valeurs en base des champs réservés au super administrateur (commissions de la plateforme, activations des modules et des applications ; Tap to Pay et lecteur Bluetooth restent à l'administrateur du site : réglages de sa caisse, 03/11/2026) */
    private function enforceSuperAdminFields(Entreprise $entity): void
    {
        if ($this->isGranted('ROLE_SUPER_ADMIN')) {
            return;
        }

        $id = (int) $entity->getId();
        if ($id <= 0) {
            // Création par un administrateur de site : les valeurs par défaut de l'entité, aucun chiffre en dur ici
            $defaults = new Entreprise();
            $entity
                ->setPosTerminalFeeCardBp($defaults->getPosTerminalFeeCardBp())
                ->setPosTerminalFeeInteracBp($defaults->getPosTerminalFeeInteracBp())
                ->setIsBoutiqueActive((bool) $defaults->isBoutiqueActive())
                ->setIsLandingPageActive((bool) $defaults->isLandingPageActive())
                ->setIsBoussoleEsgActive((bool) $defaults->isBoussoleEsgActive())
                ->setIsMemoireVivanteActive((bool) $defaults->isMemoireVivanteActive())
                ->setIsPosActive($defaults->isPosActive())
                ->setIsMobileShopActive($defaults->isMobileShopActive())
                ->setIsManagementAppActive($defaults->isManagementAppActive())
                ->setIsLivingMemoryActive($defaults->isLivingMemoryActive())
                ->setIsAiHelpActive($defaults->isAiHelpActive())
                ->setIsAiFullActive($defaults->isAiFullActive())
                ->setVariantPromotionsEnabled($defaults->isVariantPromotionsEnabled());

            return;
        }

        try {
            $row = $this->emProvider->getEntityManager()->getConnection()->fetchAssociative(
                'SELECT pos_terminal_fee_card_bp, pos_terminal_fee_interac_bp,
                        is_boutique_active, is_landing_page_active, is_boussole_esg_active, is_memoire_vivante_active,
                        is_pos_active, is_mobile_shop_active, is_management_app_active, is_living_memory_active, variant_promotions_enabled
                 FROM entreprise WHERE id = ?',
                [$id]
            );
        } catch (\Throwable) {
            throw $this->createAccessDeniedException('Réglages réservés au super administrateur.');
        }
        if (!is_array($row)) {
            throw $this->createAccessDeniedException('Réglages réservés au super administrateur.');
        }

        $entity
            ->setPosTerminalFeeCardBp((int) $row['pos_terminal_fee_card_bp'])
            ->setPosTerminalFeeInteracBp((int) $row['pos_terminal_fee_interac_bp'])
            ->setIsBoutiqueActive((bool) $row['is_boutique_active'])
            ->setIsLandingPageActive((bool) $row['is_landing_page_active'])
            ->setIsBoussoleEsgActive((bool) $row['is_boussole_esg_active'])
            ->setIsMemoireVivanteActive((bool) $row['is_memoire_vivante_active'])
            ->setIsPosActive((bool) $row['is_pos_active'])
            ->setIsMobileShopActive((bool) $row['is_mobile_shop_active'])
            ->setIsManagementAppActive((bool) $row['is_management_app_active'])
            ->setIsLivingMemoryActive((bool) $row['is_living_memory_active'])
            ->setVariantPromotionsEnabled((bool) $row['variant_promotions_enabled'])
;
    }
}