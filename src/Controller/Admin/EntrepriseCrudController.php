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
        private readonly \Symfony\Component\HttpFoundation\RequestStack $requestStack
    ) {
        parent::__construct($emProvider);
        $this->adminUrlGenerator = $adminUrlGenerator;
    }

    /** Mise à jour habituelle, puis clés d'activation des applications mobiles selon les interrupteurs changés (15/10/2026) */
    public function updateEntity(\Doctrine\ORM\EntityManagerInterface $entityManager, $entityInstance): void
    {
        $tenantEm = $this->emProvider->getEntityManager();
        $tenantEm->getUnitOfWork()->computeChangeSets();
        $changeSet = $tenantEm->getUnitOfWork()->getEntityChangeSet($entityInstance);
        parent::updateEntity($entityManager, $entityInstance);

        $request = $this->requestStack->getCurrentRequest();
        foreach ($this->appActivation->applyChanges($changeSet, $this->tenant->getTenantCode() ?? '', $this->getUser()?->getUserIdentifier() ?? 'admin', $request?->getSchemeAndHttpHost() ?? '') as $app => $message) {
            $this->addFlash(str_contains($message, 'non créée') ? 'danger' : 'success', sprintf('%s : %s', \App\Services\AppKeyService\AppKeyService::LABELS[$app] ?? $app, $message));
        }
    }

    public static function getEntityFqcn(): string
    {
        return Entreprise::class;
    }

    public function configureFields(string $pageName): iterable
    {
        $tenantEm = $this->emProvider->getEntityManager();

        return [
            IdField::new('id')->onlyOnIndex(),
            
            // --- Champs non traduits ---
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
            TextField::new('ein', 'SIRET'),
            TextField::new('tvaIntracommunautaire', 'TVA intracommunautaire'),
            TextField::new('gstNumber', 'Numéro de TPS (Canada)')->setRequired(false)->hideOnIndex()->setHelp('Imprimé sur les tickets de caisse (store.taxNumbers.gst)'),
            TextField::new('qstNumber', 'Numéro de TVQ (Québec)')->setRequired(false)->hideOnIndex()->setHelp('Imprimé sur les tickets de caisse (store.taxNumbers.qst)'),
            FormField::addPanel('Activation des Applications (Multi-Module)'),
            BooleanField::new('isBoutiqueActive', 'Boutique / e-Commerce')
                ->setHelp('Activer la boutique et le catalogue e-commerce pour ce tenant'),
            BooleanField::new('isLandingPageActive', 'Landing Page')
                ->setHelp('Activer le site vitrine / landing page'),
            BooleanField::new('isBoussoleEsgActive', 'Boussole ESG')
                ->setHelp('Activer le module de diagnostic et bilans ESG'),
            BooleanField::new('isMemoireVivanteActive', 'Mémoire Vivante')
                ->setHelp('Activer l\'application Mémoire Vivante'),

            FormField::addPanel('Applications mobiles')
                ->setHelp('Activer génère une clé d\'activation et l\'envoie (clé + code QR) à l\'e-mail de l\'entreprise ; désactiver révoque les clés de l\'application. Détail : menu Caisse → Clés des applications.'),
            BooleanField::new('isPosActive', 'Caisse (POS)'),
            BooleanField::new('isMobileShopActive', 'E-commerce mobile'),
            BooleanField::new('isManagementAppActive', 'Application de gestion'),
            BooleanField::new('isLivingMemoryActive', 'LivingMemory (Mémoires Vivantes)'),

            FormField::addPanel('Caisse (POS)'),
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
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posHeldCartsMax', 'Paniers en attente : nombre maximal')->setHelp('défaut 10')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posHeldCartsAlertHours', 'Paniers en attente : alerte après (heures)')->setHelp('défaut 24')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posCashAlertThreshold', 'Alerte « trop d\'espèces » au-delà de (cents)')->setRequired(false)->setHelp('Vide : aucune alerte ; le POS invite à un retrait')->hideOnIndex(),
            BooleanField::new('posTerminalHeaderRequired', 'Exiger un terminal identifié (X-Pos-Terminal) sur les routes de caisse')->setHelp('Désactivé par défaut ; à activer quand toutes les tablettes envoient l\'en-tête')->hideOnIndex(),
            // Mode hors ligne (C5 partie 2, 25/10/2026) : désactivé par défaut
            FormField::addPanel('Mode hors ligne (caisse)')->setHelp('Ventes en espèces sans réseau, synchronisées ensuite ; désactivé d\'office si les taxes du site viennent de Stripe Tax.'),
            BooleanField::new('posOfflineEnabled', 'Mode hors ligne autorisé')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posOfflineMaxHours', 'Durée maximale hors ligne (heures)')->setHelp('défaut 24')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posOfflineMaxQueuedSales', 'Ventes en file au plus')->setHelp('défaut 100')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posOfflineMaxQueuedAmount', 'Montant en file au plus (cents)')->setHelp('défaut 500000 = 5 000,00')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posOfflinePriceTolerance', 'Écart de prix toléré par vente (cents)')->setHelp('Recalcul au pack déclaré ; au-delà, vente refusée sauf acceptation forcée par un responsable ; défaut 5')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posOfflinePackValidityHours', 'Validité du pack hors ligne (heures)')->setHelp('défaut 24')->hideOnIndex(),

            // Conservation des données de vente (A1, 30/10/2026)
            FormField::addPanel('Conservation des données de vente (caisse)')->setHelp('Durée de conservation : défaut du pays (Canada 6 ans après la fin de l\'année, France et DROM 10 ans), jamais moins que le plancher légal ; exercice supposé = année civile (à confirmer par le comptable). Pendant la durée, la suppression des données de caisse et des commandes payées est refusée ; les modifications sont journalisées (mode observation) ou refusées (mode blocage). Aucune purge automatique à l\'échéance.'),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('dataRetentionYears', 'Durée de conservation (années)')->setRequired(false)->setHelp('Vide = défaut du pays')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField::new('retentionWriteMode', 'Modifications des données de caisse')->setChoices(['Observation : journalisées (RETENTION_WRITE_OBSERVED)' => 'observe', 'Blocage : refusées' => 'block'])->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posGiftCardCodeAttempts', 'Codes de cartes-cadeaux refusés : essais par 10 minutes')->setHelp('Par utilisateur et par terminal ; défaut 10, 0 = sans limite')->hideOnIndex(),

            // Fuseau horaire et comptabilité (F14, 29/10/2026)
            FormField::addPanel('Fuseau horaire et comptabilité (caisse)')->setHelp('Fuseau horaire du magasin pour les journées des statistiques et des exports (vide = déduit du pays et de la province : Québec et Ontario America/Toronto, Manitoba America/Winnipeg, Alberta America/Edmonton, Colombie-Britannique America/Vancouver, Martinique America/Martinique, France Europe/Paris). Plan de comptes : une ligne par clé « cle=numero;Nom » ; vide = plan par défaut québécois (voir docs/caisse.md). Les réservations gardent leur fuseau actuel.'),
            TextField::new('posTimezone', 'Fuseau horaire du magasin (IANA)')->setRequired(false)->setHelp('p. ex. America/Toronto, America/Martinique, Europe/Paris')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField::new('accountingAccounts', 'Plan de comptes (une ligne par clé : cle=numero;Nom)')->setRequired(false)->setHelp('Clés : sales, sales_rate_<taux>, returns, discounts, shipping_revenue, tax_gst, tax_qst, tax_hst, tax_hst_<province>, tax_pst, tax_vat, tax_vat_<taux>, cash, bank, card_clearing, interac_clearing, stripe_clearing, stripe_fees, commission, stripe_payout, gift_card_liability, store_credit_liability, cash_discrepancy, cash_rounding, loyalty_rewards')->hideOnIndex(),

            // Fiche client et fidélité (F8, 28/10/2026)
            FormField::addPanel('Clients et fidélité (caisse)')->setHelp('Fiche client de caisse (nom, téléphone, courriel, consentements) distincte du compte web, rattachable à un compte existant avec le consentement du client ; aucun compte web ni courriel n\'est créé. Fidélité : points gagnés sur le net des articles (hors taxes, hors part payée par carte-cadeau), rachetés en remise de ticket calculée par le serveur. Données personnelles (Loi 25, RGPD) : consentements datés par finalité, marketing séparé et désactivé par défaut, journal des consultations, effacement = anonymisation.'),
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
            FormField::addPanel('Cartes-cadeaux et avoirs (caisse)')->setHelp('Carte-cadeau vendue en caisse (code unique non devinable, ticket Code 128 et QR, ou carte physique pré-imprimée) et avoir émis par un retour, utilisables comme moyen de paiement. La vente d\'une carte est un passif, pas un chiffre d\'affaires : taxes à l\'utilisation. Expiration : la règle dépend du pays. Au Québec, une carte prépayée vendue contre de l\'argent ne peut pas expirer et un solde de 5,00 $ ou moins doit être remboursé en espèces sur demande (vide = jamais d\'expiration, seuil 500). La France ou d\'autres pays ont d\'autres règles : ajustez ces valeurs.'),
            BooleanField::new('posGiftCardEnabled', 'Cartes-cadeaux activées')->hideOnIndex(),
            TextField::new('posGiftCardAmounts', 'Montants proposés (cents, séparés par des virgules)')->setRequired(false)->setHelp('Vide : 2500, 5000, 10000 (25, 50 et 100 $) ; le montant libre reste possible')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posGiftCardExpiryMonths', 'Expiration des cartes (mois)')->setRequired(false)->setHelp('Vide = jamais (Québec)')->hideOnIndex(),
            BooleanField::new('posStoreCreditEnabled', 'Avoirs activés (remboursement d\'un retour en avoir)')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posStoreCreditExpiryMonths', 'Expiration des avoirs (mois)')->setRequired(false)->setHelp('Vide = jamais')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posGiftCardCashRefundMax', 'Remboursement en espèces d\'un solde : seuil (cents)')->setHelp('Jusqu\'à ce solde, le client peut exiger les espèces ; défaut 500 = 5,00 $ (Québec)')->hideOnIndex(),
            BooleanField::new('posGiftCardCashRefundBeyond', 'Remboursement en espèces au-delà du seuil (avec validation d\'un responsable)')->hideOnIndex(),

            // Coupons de fabricant (F17, 01/11/2026) : désactivés par défaut
            FormField::addPanel('Coupons de fabricant (caisse)')->setHelp('Bons de réduction émis par une marque et remboursés au commerçant par le fabricant ou une chambre de compensation. Acceptés comme un moyen de paiement : la TPS et la TVQ restent calculées sur le prix plein (Loi sur la taxe d\'accise, art. 181(2)). Rapport des coupons à réclamer : menu « Coupons de fabricant (caisse) ».'),
            BooleanField::new('posCouponEnabled', 'Coupons de fabricant acceptés')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posCouponMaxWithoutApproval', 'Coupons : montant maximal par vente sans validation (cents)')->setHelp('Total des coupons d\'une vente ; au-delà, validation d\'un responsable (action manufacturer_coupon). 0 = toujours une validation. Défaut 2000 (20 $).')->hideOnIndex(),

            // Paiements de caisse en production (M1) : mode affiché ici, changé seulement depuis la page « Paiements de caisse (Stripe) » (contrôles, journal)
            FormField::addPanel('Paiements de caisse et surveillance (caisse)')->setHelp('Mode Stripe de la caisse (test | réel) : page « Paiements de caisse (Stripe) » du menu Caisse, qui contrôle le compte Stripe réel avant le passage. Alertes : courriel au contact du site quand la surveillance relève un point à vérifier ou critique (réconciliation arrêtée, paiements sans vente, remboursements Interac expirés, sessions ouvertes depuis plus de 24 h, échecs de capture, compte Stripe suspendu) ; même situation renvoyée au plus toutes les 6 heures ; aucune donnée de carte.'),
            TextField::new('posStripeMode', 'Paiements de caisse')->hideOnForm()->hideOnIndex()->formatValue(fn ($v) => $v === 'live' ? 'RÉEL' : 'test'),
            BooleanField::new('posAlertsEnabled', 'Alertes de surveillance par courriel au contact du site')->hideOnIndex(),
            EmailField::new('posAlertsEmail', 'Adresse des alertes (vide : courriel de l\'entreprise)')->setRequired(false)->hideOnIndex(),

            // Formulaires à étapes (28/10/2026) : destinataire et conservation des demandes reçues
            FormField::addPanel('Demandes reçues (formulaires)')->setHelp('Demandes envoyées par les formulaires à étapes du site (menu « Demandes reçues »). Notification à l\'adresse ci-dessous, sinon au courriel de l\'entreprise. Les demandes sont supprimées après la durée de conservation (12 mois par défaut).'),
            EmailField::new('formSubmissionsEmail', 'Adresse qui reçoit les demandes (vide : courriel de l\'entreprise)')->setRequired(false)->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('formSubmissionsRetentionMonths', 'Conservation des demandes (mois, 1 à 120 ; vide : 12)')->setRequired(false)->hideOnIndex(),

            // Grille de favoris (F10) : désactivée par défaut
            FormField::addPanel('Grille de favoris (caisse)')->setHelp('Grille de produits à toucher sur l\'écran de vente (articles sans code-barres, ventes fréquentes) : produits épinglés par le responsable d\'abord, dans l\'ordre choisi (menu « Favoris de la caisse » ; les épinglés d\'un poste remplacent ceux du site pour ce poste), puis les plus vendus en caisse sur la période, nets des retours, pour compléter la grille.'),
            BooleanField::new('posFavoritesEnabled', 'Grille de favoris activée')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField::new('posFavoritesMode', 'Contenu de la grille')->hideOnIndex()
                ->setChoices(['Épinglés puis plus vendus' => 'both', 'Épinglés seulement' => 'pinned', 'Plus vendus seulement' => 'bestSellers']),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posFavoritesDays', 'Plus vendus : nombre de jours')->setHelp('Ventes de caisse des N derniers jours (fuseau du magasin), de 1 à 365. Défaut 30.')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posFavoritesGridSize', 'Taille de la grille')->setHelp('Nombre de cases, de 1 à 100. Défaut 24.')->hideOnIndex(),

            // Article libre et prix manuel (F7, 26/10/2026) : désactivés par défaut
            FormField::addPanel('Article libre et prix manuel (caisse)')->setHelp('Article libre : vente d\'un article absent du catalogue (libellé, prix saisi, taxable ou non), sans mouvement de stock. Prix manuel : prix catalogue remplacé sur une ligne, avec motif. Au-delà du montant maximal, validation d\'un responsable ; vide = jamais, 0 = toujours.'),
            BooleanField::new('posCustomItemEnabled', 'Article libre autorisé')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posCustomItemMaxAmount', 'Article libre : montant maximal par vente sans validation (cents)')->setRequired(false)->setHelp('Total des articles libres de la vente ; vide = jamais de validation')->hideOnIndex(),
            BooleanField::new('posPriceOverrideEnabled', 'Prix manuel autorisé')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posPriceOverrideMaxAmount', 'Prix manuel : baisse maximale par vente sans validation (cents)')->setRequired(false)->setHelp('Somme des baisses par rapport au prix catalogue ; une hausse n\'est jamais soumise à validation ; vide = jamais')->hideOnIndex(),
            BooleanField::new('posPriceOverrideReasonRequired', 'Prix manuel : motif obligatoire')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField::new('posPriceOverrideReasons', 'Motifs de prix manuel (un par ligne)')->setRequired(false)->setHelp('Vide : Article abîmé, Prix concurrent, Prix négocié, Erreur d\'étiquette, Autre')->hideOnIndex(),

            // Remises de caisse (F1, 24/10/2026)
            FormField::addPanel('Remises (caisse)')->setHelp('Remises de ligne et de ticket accordées par le caissier, calculées par le serveur (taxes sur le net). Plafonds par rôle ; au-delà du plafond caissier, validation d\'un responsable ; l\'administrateur n\'a pas de plafond.'),
            BooleanField::new('posDiscountEnabled', 'Remises autorisées')->hideOnIndex(),
            BooleanField::new('posDiscountTicketEnabled', 'Remise sur le ticket entier autorisée')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posDiscountCashierMaxPercentBp', 'Caissier : pourcentage maximal (centièmes de %)')->setHelp('1000 = 10 %')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posDiscountCashierMaxAmount', 'Caissier : montant maximal par vente (cents)')->setHelp('2000 = 20,00')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posDiscountManagerMaxPercentBp', 'Responsable : pourcentage maximal (centièmes de %)')->setHelp('5000 = 50 %')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posDiscountManagerMaxAmount', 'Responsable : montant maximal par vente (cents)')->setHelp('20000 = 200,00')->hideOnIndex(),
            BooleanField::new('posDiscountReasonRequired', 'Motif obligatoire')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField::new('posDiscountReasons', 'Motifs de remise (un par ligne)')->setRequired(false)->setHelp('Vide : Article abîmé, Fidélité, Prix affiché, Geste commercial, Autre')->hideOnIndex(),

            // Retours partiels et échanges (F2, 24/10/2026)
            FormField::addPanel('Retours et échanges (caisse)')->setHelp('Retour d\'un ou plusieurs articles d\'une vente retrouvée par son ticket, et échanges. Défauts = comportement livré.'),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posReturnMaxDays', 'Délai maximal de retour (jours)')->setRequired(false)->setHelp('Vide : aucun délai')->hideOnIndex(),
            BooleanField::new('posReturnWithoutReceipt', 'Retour sans ticket autorisé')->setHelp('Articles repris aux prix actuels, sans vente d\'origine ; désactivé par défaut')->hideOnIndex(),
            BooleanField::new('posReturnRestockDefault', 'Remise en stock par défaut')->hideOnIndex(),
            BooleanField::new('posReturnCashForCardSale', 'Remboursement en espèces d\'une vente payée par carte')->setHelp('Désactivé par défaut (carte volée remboursée en espèces) ; l\'annulation complète n\'est pas concernée')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField::new('posReturnReasons', 'Motifs de retour (un par ligne)')->setRequired(false)->setHelp('Vide : Taille ou modèle, Défectueux, Ne convient pas, Erreur de caisse, Autre')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField::new('posApprovalReturnMode', 'Validation d\'un retour')->setChoices(['Même seuil que le remboursement' => 'refund', 'Seuil propre ci-dessous' => 'custom'])->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posApprovalReturn', 'Validation : retour au-delà de (cents)')->setHelp('Seuil propre : 0 toujours ; vide : jamais')->setRequired(false)->hideOnIndex(),

            // Terminal de paiement Stripe (C4, 23/10/2026) : valeurs par défaut = comportement livré (1 %, Interac selon le pays, 120 s, 30 min, 5 min)
            FormField::addPanel('Terminal de paiement Stripe (caisse)')
                ->setHelp('Lecteur par poste : menu Postes de caisse. Commissions de la plateforme en centièmes de pour cent (100 = 1 %). Les paiements en ligne gardent leur commission actuelle.'),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posTerminalFeeCardBp', 'Commission en magasin, carte de crédit (centièmes de %)')->hideOnIndex(),
            \EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField::new('posTerminalFeeInteracBp', 'Commission en magasin, Interac (centièmes de %)')->hideOnIndex(),
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

            FormField::addPanel('Informations Générales'),
            TextField::new('facebookPixelId', 'Facebook Pixel ID')->hideOnIndex(),
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


            FormField::addPanel('Référencement & SEO'),
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

            CollectionField::new('translations', 'Contenus Traduits')
                ->setEntryType(EntrepriseTranslationType::class)
                ->setFormTypeOption('by_reference', false)
                ->onlyOnForms(),

            AssociationField::new('socialNetworks', 'Réseaux Sociaux')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => fn(SocialNetworkRepository $repo) => $repo->createQueryBuilder('sn')->orderBy('sn.name', 'ASC'),
                ])
                ->setColumns('col-md-6'),
        ];
    }
}