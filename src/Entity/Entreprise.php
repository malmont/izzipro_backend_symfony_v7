<?php

namespace App\Entity;

use App\Repository\EntrepriseRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;


#[ORM\Entity(repositoryClass: EntrepriseRepository::class)]
class Entreprise implements TranslatableInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $logo = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $tel = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $website = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $ein = null;

    #[ORM\Column(length: 255, nullable: true)] 
    private ?string $tvaIntracommunautaire = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $conditionOfUse = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $LegalNotice = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $privacyPolicy = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $adress = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $Apropos = null;

    #[ORM\OneToOne(mappedBy: 'entreprise', targetEntity: AddressEntreprise::class, cascade: ['persist','remove'])]
    private ?AddressEntreprise $addressEntreprise = null;

    /**
     * @var Collection<int, EntrepriseTranslation>
     */
    #[ORM\OneToMany(
    mappedBy: 'entreprise', 
    targetEntity: EntrepriseTranslation::class, 
    cascade: ['persist', 'remove'], 
    orphanRemoval: true,
    fetch: 'EXTRA_LAZY'
    )]
    private Collection $translations;

    /**
     * @var Collection<int, SocialNetwork>
     */
    #[ORM\OneToMany(
        mappedBy: 'entreprise',
        targetEntity: SocialNetwork::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
        fetch: 'EXTRA_LAZY'
    )]
    private Collection $socialNetworks;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $faviconFilename = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $facebookPixelId = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private ?bool $isBoutiqueActive = true;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private ?bool $isBoussoleEsgActive = false;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private ?bool $isMemoireVivanteActive = false;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private ?bool $isLandingPageActive = true;

    // Applications mobiles (15/10/2026) : activer génère une clé d'activation envoyée au contact du site, désactiver
    // révoque les clés de l'application (AppActivationService). Colonnes : scripts/migrate_all_v2_app_activation.sh.
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private ?bool $isPosActive = false;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private ?bool $isMobileShopActive = false;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private ?bool $isManagementAppActive = false;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private ?bool $isLivingMemoryActive = false;

    // Caisse (19/10/2026) : écart de comptage toléré sans motif à la fermeture (cents, 0 = tout écart demande un motif),
    // et pas d'arrondi des paiements en espèces (cents ; null = selon la devise : 5 pour le CAD, aucun sinon)
    #[ORM\Column(options: ['default' => 0])]
    private int $posDiscrepancyThreshold = 0;

    #[ORM\Column(nullable: true)]
    private ?int $posCashRoundingStep = null;

    // Validation d'un responsable (C3, 20/10/2026) : seuil en cents (0 = toujours, N = au-delà de N cents, null = jamais)
    // ou oui / non ; délai de verrouillage du POS en minutes (0 = jamais)
    #[ORM\Column(nullable: true, options: ['default' => 0])]
    private ?int $posApprovalRefund = 0;

    #[ORM\Column(nullable: true, options: ['default' => 0])]
    private ?int $posApprovalWithdrawal = 0;

    #[ORM\Column(nullable: true, options: ['default' => 0])]
    private ?int $posApprovalFundWithdrawal = 0;

    #[ORM\Column(options: ['default' => true])]
    private bool $posApprovalDrawerOpen = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $posApprovalCloseWithoutCount = true;

    #[ORM\Column(options: ['default' => 5])]
    private int $posLockMinutes = 5;

    // Terminal de paiement Stripe (C4, 23/10/2026) : commissions plateforme en magasin en centièmes de % (100 = 1 %),
    // Interac accepté (null = selon le pays du site : oui pour CA), délais en secondes / minutes, options du lecteur
    #[ORM\Column(options: ['default' => 100])]
    private int $posTerminalFeeCardBp = 100;

    #[ORM\Column(options: ['default' => 100])]
    private int $posTerminalFeeInteracBp = 100;

    #[ORM\Column(nullable: true)]
    private ?bool $posTerminalInterac = null;

    #[ORM\Column(options: ['default' => 120])]
    private int $posTerminalCardWaitSeconds = 120;

    #[ORM\Column(options: ['default' => 30])]
    private int $posTerminalAuthCancelMinutes = 30;

    #[ORM\Column(options: ['default' => 5])]
    private int $posTerminalOrphanMinutes = 5;

    #[ORM\Column(options: ['default' => true])]
    private bool $posTerminalCustomerCancel = true;

    #[ORM\Column(options: ['default' => false])]
    private bool $posTerminalCartDisplay = false;

    // Réglages de caisse R1 (23/10/2026) : défauts = comportement livré. PIN (C3), fond suggéré, moyens de paiement du POS,
    // ticket (en-tête, pied, numéros de taxes), paniers en attente, alerte espèces, terminal exigé
    #[ORM\Column(options: ['default' => 4])]
    private int $posPinMinLength = 4;

    #[ORM\Column(options: ['default' => 6])]
    private int $posPinMaxLength = 6;

    #[ORM\Column(options: ['default' => 5])]
    private int $posPinMaxAttempts = 5;

    #[ORM\Column(options: ['default' => 15])]
    private int $posPinLockMinutes = 15;

    #[ORM\Column(nullable: true)]
    private ?int $posOpeningFundSuggested = null;

    #[ORM\Column(nullable: true)]
    private ?int $posClosingKeptFundSuggested = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $posPayCash = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $posPayDeclaredCard = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $posPayPaypal = true;

    #[ORM\Column(options: ['default' => true])]
    private bool $posPayMulti = true;

    #[ORM\Column(options: ['default' => false])]
    private bool $posDeclaredCardBlockedWithTerminal = false;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $posReceiptHeader = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $posReceiptFooter = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $posReceiptShowTaxNumbers = true;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $gstNumber = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $qstNumber = null;

    #[ORM\Column(options: ['default' => 10])]
    private int $posHeldCartsMax = 10;

    #[ORM\Column(options: ['default' => 24])]
    private int $posHeldCartsAlertHours = 24;

    #[ORM\Column(nullable: true)]
    private ?int $posCashAlertThreshold = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $posTerminalHeaderRequired = false;

    // Retours partiels et échanges (F2, 24/10/2026) : délai (jours, null = aucun), retour sans ticket, remise en stock par défaut,
    // remboursement en espèces d'une vente carte, motifs (un par ligne, null = liste par défaut), validation (mode refund = seuil
    // du remboursement, custom = seuil propre : 0 toujours, N au-delà, null jamais)
    #[ORM\Column(nullable: true)]
    private ?int $posReturnMaxDays = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $posReturnWithoutReceipt = false;

    #[ORM\Column(options: ['default' => true])]
    private bool $posReturnRestockDefault = true;

    #[ORM\Column(options: ['default' => false])]
    private bool $posReturnCashForCardSale = false;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $posReturnReasons = null;

    #[ORM\Column(length: 10, options: ['default' => 'refund'])]
    private string $posApprovalReturnMode = 'refund';

    #[ORM\Column(nullable: true)]
    private ?int $posApprovalReturn = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $metaTitle = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $metaDescription = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $seoKeywords = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $ogImage = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $googleSiteVerification = null;

    /** Devise du site (ISO 4217, une seule par site) : tous les montants de la boutique (08/10/2026) */
    #[ORM\Column(type: 'string', length: 3, options: ['default' => 'CAD'])]
    private string $currency = 'CAD';

    public function __construct()
    {
        $this->translations = new ArrayCollection();
        $this->socialNetworks = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getLogo(): ?string
    {
        return $this->logo;
    }

    public function setLogo(?string $logo): static
    {
        $this->logo = $logo;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getTel(): ?string
    {
        return $this->tel;
    }

    public function setTel(?string $tel): static
    {
        $this->tel = $tel;

        return $this;
    }

    public function getWebsite(): ?string
    {
        return $this->website;
    }

    public function setWebsite(?string $website): static
    {
        $this->website = $website;

        return $this;
    }

    public function getEin(): ?string
    {
        return $this->ein;
    }

    public function setEin(?string $ein): static
    {
        $this->ein = $ein;

        return $this;
    }

    public function getTvaIntracommunautaire(): ?string
    {
        return $this->tvaIntracommunautaire;
    }

    public function setTvaIntracommunautaire(?string $tvaIntracommunautaire): static
    {
        $this->tvaIntracommunautaire = $tvaIntracommunautaire;

        return $this;
    }

    public function getConditionOfUse(): ?string
    {
        return $this->conditionOfUse;
    }

    public function setConditionOfUse(?string $conditionOfUse): static
    {
        $this->conditionOfUse = $conditionOfUse;

        return $this;
    }

    public function getLegalNotice(): ?string
    {
        return $this->LegalNotice;
    }

    public function setLegalNotice(?string $LegalNotice): static
    {
        $this->LegalNotice = $LegalNotice;

        return $this;
    }

    public function getPrivacyPolicy(): ?string
    {
        return $this->privacyPolicy;
    }

    public function setPrivacyPolicy(?string $privacyPolicy): static
    {
        $this->privacyPolicy = $privacyPolicy;

        return $this;
    }

    public function getAdress(): ?string
    {
        return $this->adress;
    }

    public function setAdress(?string $adress): static
    {
        $this->adress = $adress;

        return $this;
    }

    public function getApropos(): ?string
    {
        return $this->Apropos;
    }

    public function setApropos(?string $Apropos): static
    {
        $this->Apropos = $Apropos;

        return $this;
    }

    public function getAddressEntreprise(): ?AddressEntreprise
    {
        return $this->addressEntreprise;
    }

    public function setAddressEntreprise(?AddressEntreprise $addressEntreprise): static
    {
        // unset the owning side of the relation if necessary
        if ($addressEntreprise === null && $this->addressEntreprise !== null) {
            $this->addressEntreprise->setEntreprise(null);
        }

        // set the owning side of the relation if necessary
        if ($addressEntreprise !== null && $addressEntreprise->getEntreprise() !== $this) {
            $addressEntreprise->setEntreprise($this);
        }

        $this->addressEntreprise = $addressEntreprise;

        return $this;
    }

    public function __toString(): string
    {
        return $this->name;
    }



    /**
     * @return Collection<int, EntrepriseTranslation>
     */
    public function getTranslations(): Collection
    {
        return $this->translations;
    }

    public function addTranslation(object $translation): void
    {
        if (!$this->translations->contains($translation)) {
            $this->translations->add($translation);
            $translation->setEntreprise($this);
        }
    }

    public function removeTranslation(EntrepriseTranslation $translation): static
    {
        if ($this->translations->removeElement($translation)) {
            // set the owning side to null (unless already changed)
            if ($translation->getEntreprise() === $this) {
                $translation->setEntreprise(null);
            }
        }

        return $this;
    }

    public function getTranslation(string $locale): ?EntrepriseTranslation
    {
        foreach ($this->translations as $translation) {
            if ($translation->getLanguage() === $locale) {
                return $translation;
            }
        }

        foreach ($this->translations as $translation) {
            if ($translation->getLanguage() === 'fr') {
                return $translation;
            }
        }
        
        return $this->translations->first() ?: null;
    }
    public function getTranslatableFields(): array
    {
        return ['conditionOfUse', 'LegalNotice', 'privacyPolicy', 'Apropos'];
    }

    public function getTranslationEntityClass(): string
    {
        return EntrepriseTranslation::class;
    }

    public function findTranslationByLocale(string $locale): ?object
    {
        foreach ($this->translations as $translation) {
            if ($translation->getLanguage() === $locale) {
                return $translation;
            }
        }
        return null;
    }

    public function getFaviconFilename(): ?string
    {
        return $this->faviconFilename;
    }

    public function setFaviconFilename(?string $faviconFilename): static
    {
        $this->faviconFilename = $faviconFilename;

        return $this;
    }



    public function getFacebookPixelId(): ?string
    {
        return $this->facebookPixelId;
    }

    public function setFacebookPixelId(?string $facebookPixelId): static
    {
        $this->facebookPixelId = $facebookPixelId;

        return $this;
    }

    public function isBoutiqueActive(): ?bool
    {
        return $this->isBoutiqueActive;
    }

    public function setIsBoutiqueActive(bool $isBoutiqueActive): static
    {
        $this->isBoutiqueActive = $isBoutiqueActive;

        return $this;
    }

    public function isBoussoleEsgActive(): ?bool
    {
        return $this->isBoussoleEsgActive;
    }

    public function setIsBoussoleEsgActive(bool $isBoussoleEsgActive): static
    {
        $this->isBoussoleEsgActive = $isBoussoleEsgActive;

        return $this;
    }

    public function isMemoireVivanteActive(): ?bool
    {
        return $this->isMemoireVivanteActive;
    }

    public function setIsMemoireVivanteActive(bool $isMemoireVivanteActive): static
    {
        $this->isMemoireVivanteActive = $isMemoireVivanteActive;

        return $this;
    }

    public function isLandingPageActive(): ?bool
    {
        return $this->isLandingPageActive;
    }

    public function setIsLandingPageActive(bool $isLandingPageActive): static
    {
        $this->isLandingPageActive = $isLandingPageActive;

        return $this;
    }

    public function isPosActive(): bool
    {
        return (bool) $this->isPosActive;
    }

    public function setIsPosActive(bool $active): static
    {
        $this->isPosActive = $active;

        return $this;
    }

    public function isMobileShopActive(): bool
    {
        return (bool) $this->isMobileShopActive;
    }

    public function setIsMobileShopActive(bool $active): static
    {
        $this->isMobileShopActive = $active;

        return $this;
    }

    public function isManagementAppActive(): bool
    {
        return (bool) $this->isManagementAppActive;
    }

    public function setIsManagementAppActive(bool $active): static
    {
        $this->isManagementAppActive = $active;

        return $this;
    }

    public function getPosDiscrepancyThreshold(): int
    {
        return $this->posDiscrepancyThreshold;
    }

    public function setPosDiscrepancyThreshold(?int $cents): static
    {
        $this->posDiscrepancyThreshold = max(0, (int) $cents);

        return $this;
    }

    public function getPosCashRoundingStep(): ?int
    {
        return $this->posCashRoundingStep;
    }

    public function setPosCashRoundingStep(?int $cents): static
    {
        $this->posCashRoundingStep = $cents !== null && $cents > 0 ? $cents : null;

        return $this;
    }

    public function getPosApprovalRefund(): ?int { return $this->posApprovalRefund; }
    public function setPosApprovalRefund(?int $cents): static { $this->posApprovalRefund = $cents !== null ? max(0, $cents) : null; return $this; }
    public function getPosApprovalWithdrawal(): ?int { return $this->posApprovalWithdrawal; }
    public function setPosApprovalWithdrawal(?int $cents): static { $this->posApprovalWithdrawal = $cents !== null ? max(0, $cents) : null; return $this; }
    public function getPosApprovalFundWithdrawal(): ?int { return $this->posApprovalFundWithdrawal; }
    public function setPosApprovalFundWithdrawal(?int $cents): static { $this->posApprovalFundWithdrawal = $cents !== null ? max(0, $cents) : null; return $this; }
    public function isPosApprovalDrawerOpen(): bool { return $this->posApprovalDrawerOpen; }
    public function setPosApprovalDrawerOpen(bool $required): static { $this->posApprovalDrawerOpen = $required; return $this; }
    public function isPosApprovalCloseWithoutCount(): bool { return $this->posApprovalCloseWithoutCount; }
    public function setPosApprovalCloseWithoutCount(bool $required): static { $this->posApprovalCloseWithoutCount = $required; return $this; }
    public function getPosLockMinutes(): int { return $this->posLockMinutes; }
    public function setPosLockMinutes(?int $minutes): static { $this->posLockMinutes = max(0, (int) $minutes); return $this; }

    public function getPosTerminalFeeCardBp(): int { return $this->posTerminalFeeCardBp; }
    public function setPosTerminalFeeCardBp(?int $bp): static { $this->posTerminalFeeCardBp = min(10000, max(0, (int) $bp)); return $this; }
    public function getPosTerminalFeeInteracBp(): int { return $this->posTerminalFeeInteracBp; }
    public function setPosTerminalFeeInteracBp(?int $bp): static { $this->posTerminalFeeInteracBp = min(10000, max(0, (int) $bp)); return $this; }
    public function getPosTerminalInterac(): ?bool { return $this->posTerminalInterac; }
    public function setPosTerminalInterac(?bool $interac): static { $this->posTerminalInterac = $interac; return $this; }
    public function getPosTerminalCardWaitSeconds(): int { return $this->posTerminalCardWaitSeconds; }
    public function setPosTerminalCardWaitSeconds(?int $seconds): static { $this->posTerminalCardWaitSeconds = min(600, max(15, (int) $seconds)); return $this; }
    public function getPosTerminalAuthCancelMinutes(): int { return $this->posTerminalAuthCancelMinutes; }
    public function setPosTerminalAuthCancelMinutes(?int $minutes): static { $this->posTerminalAuthCancelMinutes = min(2 * 24 * 60, max(1, (int) $minutes)); return $this; }
    public function getPosTerminalOrphanMinutes(): int { return $this->posTerminalOrphanMinutes; }
    public function setPosTerminalOrphanMinutes(?int $minutes): static { $this->posTerminalOrphanMinutes = min(24 * 60, max(1, (int) $minutes)); return $this; }
    public function isPosTerminalCustomerCancel(): bool { return $this->posTerminalCustomerCancel; }
    public function setPosTerminalCustomerCancel(bool $enabled): static { $this->posTerminalCustomerCancel = $enabled; return $this; }
    public function isPosTerminalCartDisplay(): bool { return $this->posTerminalCartDisplay; }
    public function setPosTerminalCartDisplay(bool $enabled): static { $this->posTerminalCartDisplay = $enabled; return $this; }

    public function getPosPinMinLength(): int { return $this->posPinMinLength; }
    public function setPosPinMinLength(?int $n): static { $this->posPinMinLength = min(8, max(4, (int) $n)); $this->posPinMaxLength = max($this->posPinMaxLength, $this->posPinMinLength); return $this; }
    public function getPosPinMaxLength(): int { return $this->posPinMaxLength; }
    public function setPosPinMaxLength(?int $n): static { $this->posPinMaxLength = min(12, max($this->posPinMinLength, (int) $n)); return $this; }
    public function getPosPinMaxAttempts(): int { return $this->posPinMaxAttempts; }
    public function setPosPinMaxAttempts(?int $n): static { $this->posPinMaxAttempts = min(10, max(3, (int) $n)); return $this; }
    public function getPosPinLockMinutes(): int { return $this->posPinLockMinutes; }
    public function setPosPinLockMinutes(?int $n): static { $this->posPinLockMinutes = min(1440, max(1, (int) $n)); return $this; }
    public function getPosOpeningFundSuggested(): ?int { return $this->posOpeningFundSuggested; }
    public function setPosOpeningFundSuggested(?int $cents): static { $this->posOpeningFundSuggested = $cents !== null && $cents >= 0 ? $cents : null; return $this; }
    public function getPosClosingKeptFundSuggested(): ?int { return $this->posClosingKeptFundSuggested; }
    public function setPosClosingKeptFundSuggested(?int $cents): static { $this->posClosingKeptFundSuggested = $cents !== null && $cents >= 0 ? $cents : null; return $this; }
    public function isPosPayCash(): bool { return $this->posPayCash; }
    public function setPosPayCash(bool $v): static { $this->posPayCash = $v; return $this; }
    public function isPosPayDeclaredCard(): bool { return $this->posPayDeclaredCard; }
    public function setPosPayDeclaredCard(bool $v): static { $this->posPayDeclaredCard = $v; return $this; }
    public function isPosPayPaypal(): bool { return $this->posPayPaypal; }
    public function setPosPayPaypal(bool $v): static { $this->posPayPaypal = $v; return $this; }
    public function isPosPayMulti(): bool { return $this->posPayMulti; }
    public function setPosPayMulti(bool $v): static { $this->posPayMulti = $v; return $this; }
    public function isPosDeclaredCardBlockedWithTerminal(): bool { return $this->posDeclaredCardBlockedWithTerminal; }
    public function setPosDeclaredCardBlockedWithTerminal(bool $v): static { $this->posDeclaredCardBlockedWithTerminal = $v; return $this; }
    public function getPosReceiptHeader(): ?string { return $this->posReceiptHeader; }
    public function setPosReceiptHeader(?string $text): static { $this->posReceiptHeader = self::plainText($text); return $this; }
    public function getPosReceiptFooter(): ?string { return $this->posReceiptFooter; }
    public function setPosReceiptFooter(?string $text): static { $this->posReceiptFooter = self::plainText($text); return $this; }
    public function isPosReceiptShowTaxNumbers(): bool { return $this->posReceiptShowTaxNumbers; }
    public function setPosReceiptShowTaxNumbers(bool $v): static { $this->posReceiptShowTaxNumbers = $v; return $this; }
    public function getGstNumber(): ?string { return $this->gstNumber; }
    public function setGstNumber(?string $n): static { $this->gstNumber = $n !== null && trim($n) !== '' ? mb_substr(trim($n), 0, 30) : null; return $this; }
    public function getQstNumber(): ?string { return $this->qstNumber; }
    public function setQstNumber(?string $n): static { $this->qstNumber = $n !== null && trim($n) !== '' ? mb_substr(trim($n), 0, 30) : null; return $this; }
    public function getPosHeldCartsMax(): int { return $this->posHeldCartsMax; }
    public function setPosHeldCartsMax(?int $n): static { $this->posHeldCartsMax = min(200, max(1, (int) $n)); return $this; }
    public function getPosHeldCartsAlertHours(): int { return $this->posHeldCartsAlertHours; }
    public function setPosHeldCartsAlertHours(?int $n): static { $this->posHeldCartsAlertHours = min(24 * 30, max(1, (int) $n)); return $this; }
    public function getPosCashAlertThreshold(): ?int { return $this->posCashAlertThreshold; }
    public function setPosCashAlertThreshold(?int $cents): static { $this->posCashAlertThreshold = $cents !== null && $cents > 0 ? $cents : null; return $this; }
    public function isPosTerminalHeaderRequired(): bool { return $this->posTerminalHeaderRequired; }

    public const RETURN_REASONS_DEFAULT = ['Taille ou modèle', 'Défectueux', 'Ne convient pas', 'Erreur de caisse', 'Autre'];
    public function getPosReturnMaxDays(): ?int { return $this->posReturnMaxDays; }
    public function setPosReturnMaxDays(?int $days): static { $this->posReturnMaxDays = $days !== null && $days > 0 ? min(3650, $days) : null; return $this; }
    public function isPosReturnWithoutReceipt(): bool { return $this->posReturnWithoutReceipt; }
    public function setPosReturnWithoutReceipt(bool $v): static { $this->posReturnWithoutReceipt = $v; return $this; }
    public function isPosReturnRestockDefault(): bool { return $this->posReturnRestockDefault; }
    public function setPosReturnRestockDefault(bool $v): static { $this->posReturnRestockDefault = $v; return $this; }
    public function isPosReturnCashForCardSale(): bool { return $this->posReturnCashForCardSale; }
    public function setPosReturnCashForCardSale(bool $v): static { $this->posReturnCashForCardSale = $v; return $this; }
    public function getPosReturnReasons(): ?string { return $this->posReturnReasons; }
    public function setPosReturnReasons(?string $text): static { $t = $text !== null ? trim(strip_tags($text)) : ''; $this->posReturnReasons = $t === '' ? null : mb_substr($t, 0, 2000); return $this; }
    /** @return list<array{code: string, label: string}> motifs du site (liste par défaut si vide) */
    public function returnReasons(): array
    {
        $labels = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string) $this->posReturnReasons) ?: [])));
        $out = [];
        foreach ($labels ?: self::RETURN_REASONS_DEFAULT as $label) {
            $ascii = class_exists(\Normalizer::class) ? preg_replace('/\p{Mn}/u', '', (string) \Normalizer::normalize($label, \Normalizer::FORM_D)) : strtr($label, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'à' => 'a', 'â' => 'a', 'ç' => 'c', 'ô' => 'o', 'î' => 'i', 'ï' => 'i', 'ù' => 'u', 'û' => 'u', 'É' => 'E', 'È' => 'E', 'À' => 'A', 'Ç' => 'C']);
            $code = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($ascii ?: $label)), '_');
            $out[] = ['code' => $code !== '' ? $code : 'motif_' . (count($out) + 1), 'label' => mb_substr($label, 0, 80)];
        }

        return $out;
    }
    public function getPosApprovalReturnMode(): string { return $this->posApprovalReturnMode; }
    public function setPosApprovalReturnMode(?string $mode): static { $this->posApprovalReturnMode = $mode === 'custom' ? 'custom' : 'refund'; return $this; }
    public function getPosApprovalReturn(): ?int { return $this->posApprovalReturn; }
    public function setPosApprovalReturn(?int $cents): static { $this->posApprovalReturn = $cents !== null ? max(0, $cents) : null; return $this; }
    /** Seuil effectif de validation d'un retour : celui du remboursement, ou le seuil propre */
    public function effectivePosApprovalReturn(): ?int { return $this->posApprovalReturnMode === 'custom' ? $this->posApprovalReturn : $this->posApprovalRefund; }
    public function setPosTerminalHeaderRequired(bool $v): static { $this->posTerminalHeaderRequired = $v; return $this; }

    /** Texte simple des tickets : balises retirées, retours à la ligne conservés, 500 caractères au plus */
    private static function plainText(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }
        $clean = trim(preg_replace("/[ \t]+/", ' ', strip_tags(str_replace(["\r\n", "\r"], "\n", $text))));

        return $clean === '' ? null : mb_substr($clean, 0, 500);
    }

    public function isLivingMemoryActive(): bool
    {
        return (bool) $this->isLivingMemoryActive;
    }

    public function setIsLivingMemoryActive(bool $active): static
    {
        $this->isLivingMemoryActive = $active;

        return $this;
    }


    /**
     * @return Collection<int, SocialNetwork>
     */
    public function getSocialNetworks(): Collection
    {
        return $this->socialNetworks;
    }

    public function addSocialNetwork(SocialNetwork $socialNetwork): static
    {
        if (!$this->socialNetworks->contains($socialNetwork)) {
            $this->socialNetworks->add($socialNetwork);
            $socialNetwork->setEntreprise($this);
        }

        return $this;
    }

    public function removeSocialNetwork(SocialNetwork $socialNetwork): static
    {
        if ($this->socialNetworks->removeElement($socialNetwork)) {
            if ($socialNetwork->getEntreprise() === $this) {
                $socialNetwork->setEntreprise(null);
            }
        }

        return $this;
    }

    public function getMetaTitle(): ?string
    {
        return $this->metaTitle;
    }

    public function setMetaTitle(?string $metaTitle): static
    {
        $this->metaTitle = $metaTitle;

        return $this;
    }

    public function getMetaDescription(): ?string
    {
        return $this->metaDescription;
    }

    public function setMetaDescription(?string $metaDescription): static
    {
        $this->metaDescription = $metaDescription;

        return $this;
    }

    public function getSeoKeywords(): ?string
    {
        return $this->seoKeywords;
    }

    public function setSeoKeywords(?string $seoKeywords): static
    {
        $this->seoKeywords = $seoKeywords;

        return $this;
    }

    public function getOgImage(): ?string
    {
        return $this->ogImage;
    }

    public function setOgImage(?string $ogImage): static
    {
        $this->ogImage = $ogImage;

        return $this;
    }

    public function getGoogleSiteVerification(): ?string
    {
        return $this->googleSiteVerification;
    }

    public function setGoogleSiteVerification(?string $googleSiteVerification): static
    {
        $this->googleSiteVerification = $googleSiteVerification;

        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): static
    {
        $this->currency = $currency;

        return $this;
    }
}
