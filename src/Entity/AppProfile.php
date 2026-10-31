<?php

namespace App\Entity;

use App\Repository\AppProfileRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Profil d'une application du site (20/10/2026) : nom, logo, coordonnées et textes propres à une application
 * (Living Memory, Mémoires Vivantes web, Boussole ESG, POS…). Une ligne au plus par application, désignée par son
 * code technique (fixe, embarqué dans l'application) ; tous les champs sont facultatifs, un champ vide est pris dans
 * la fiche entreprise (GET /api/app-profile/{app}). Table vide par défaut. Réglé dans EasyAdmin, « Profils des
 * applications ». La landing page et la boutique n'ont pas de code : elles utilisent la fiche entreprise.
 * Apparence (31/10/2026, panneau « Apparence de l'application », lue par GET /api/mobile/app-theme) : couleurs, mode,
 * police, arrondi, style des boutons, carte produit, en-tête de l'accueil ; listes fermées et bornes contrôlées par le
 * validateur (le formulaire refuse et n'enregistre rien), un champ vide est pris dans la charte de la boutique web.
 */
#[ORM\Entity(repositoryClass: AppProfileRepository::class)]
#[ORM\Table(name: 'app_profile')]
#[ORM\UniqueConstraint(name: 'uniq_app_profile_app', columns: ['app'])]
class AppProfile
{
    /** Codes des applications (liste fermée) → libellé de l'administration */
    public const APPS = [
        'livingmemory' => 'Living Memory (application mobile Mémoires Vivantes)',
        'memoires' => 'Mémoires Vivantes (web)',
        'boussole' => 'Boussole ESG',
        'pos' => 'Caisse (POS)',
        'mobile' => 'E-commerce mobile',
        'admin' => 'Gestion (application)',
    ];

    public const THEME_MODES = ['light', 'dark', 'system'];
    public const THEME_FONTS = ['Inter', 'Poppins', 'Montserrat', 'Playfair Display', 'Lora', 'System'];
    public const BUTTON_STYLES = ['solid', 'outline', 'pill'];
    public const PRODUCT_CARDS = ['classic', 'overlay', 'compact'];
    public const HOME_HEROS = ['carousel', 'banner', 'none'];
    public const RADIUS_MAX = 28;
    /** Couleur telle qu'enregistrée : #rrggbb en minuscules (setColor normalise la casse et le « # » manquant) */
    public const COLOR_PATTERN = '/^#[0-9a-f]{6}$/';
    private const COLOR_MESSAGE = 'Couleur au format #RRGGBB attendue.';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 30)]
    private string $app = '';

    #[ORM\Column(name: 'app_name', length: 255, nullable: true)]
    private ?string $appName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $tagline = null;

    /** Nom du fichier (dossier des logos de l'entreprise) ou URL absolue */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $logo = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $icon = null;

    /** Couleur principale de la charte de l'application, #rrggbb */
    #[ORM\Column(name: 'primary_color', length: 7, nullable: true)]
    #[Assert\Regex(pattern: self::COLOR_PATTERN, message: self::COLOR_MESSAGE)]
    private ?string $primaryColor = null;

    /** Apparence de l'application e-commerce mobile (31/10/2026), tous facultatifs : voir AppThemeService */
    #[ORM\Column(name: 'accent_color', length: 7, nullable: true)]
    #[Assert\Regex(pattern: self::COLOR_PATTERN, message: self::COLOR_MESSAGE)]
    private ?string $accentColor = null;

    #[ORM\Column(name: 'theme_mode', length: 10, nullable: true)]
    #[Assert\Choice(choices: self::THEME_MODES, message: 'Mode inconnu : light, dark ou system.')]
    private ?string $themeMode = null;

    #[ORM\Column(name: 'theme_font', length: 30, nullable: true)]
    #[Assert\Choice(choices: self::THEME_FONTS, message: 'Police hors de la liste.')]
    private ?string $themeFont = null;

    #[ORM\Column(name: 'theme_radius', type: Types::SMALLINT, nullable: true)]
    #[Assert\Range(min: 0, max: self::RADIUS_MAX, notInRangeMessage: 'Arrondi de {{ min }} à {{ max }} attendu.')]
    private ?int $themeRadius = null;

    #[ORM\Column(name: 'button_style', length: 10, nullable: true)]
    #[Assert\Choice(choices: self::BUTTON_STYLES, message: 'Style de bouton inconnu : solid, outline ou pill.')]
    private ?string $buttonStyle = null;

    #[ORM\Column(name: 'product_card', length: 10, nullable: true)]
    #[Assert\Choice(choices: self::PRODUCT_CARDS, message: 'Carte produit inconnue : classic, overlay ou compact.')]
    private ?string $productCard = null;

    #[ORM\Column(name: 'home_hero', length: 10, nullable: true)]
    #[Assert\Choice(choices: self::HOME_HEROS, message: 'En-tête d\'accueil inconnu : carousel, banner ou none.')]
    private ?string $homeHero = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $phone = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $website = null;

    #[ORM\Column(name: 'support_url', length: 255, nullable: true)]
    private ?string $supportUrl = null;

    #[ORM\Column(name: 'privacy_policy_url', length: 255, nullable: true)]
    private ?string $privacyPolicyUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $street1 = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $street2 = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $zip = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $province = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $country = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $apropos = null;

    #[ORM\Column(name: 'privacy_policy', type: Types::TEXT, nullable: true)]
    private ?string $privacyPolicy = null;

    #[ORM\Column(name: 'condition_of_use', type: Types::TEXT, nullable: true)]
    private ?string $conditionOfUse = null;

    #[ORM\Column(name: 'legal_notice', type: Types::TEXT, nullable: true)]
    private ?string $legalNotice = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, AppProfileTranslation> traductions (nom, accroche, textes) par langue */
    #[ORM\OneToMany(mappedBy: 'appProfile', targetEntity: AppProfileTranslation::class, cascade: ['persist', 'remove'], orphanRemoval: true, fetch: 'EXTRA_LAZY')]
    private Collection $translations;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
        $this->translations = new ArrayCollection();
    }

    /** @return Collection<int, AppProfileTranslation> */
    public function getTranslations(): Collection
    {
        return $this->translations;
    }

    public function addTranslation(AppProfileTranslation $translation): static
    {
        if (!$this->translations->contains($translation)) {
            $this->translations->add($translation);
            $translation->setAppProfile($this);
            $this->touch();
        }

        return $this;
    }

    public function removeTranslation(AppProfileTranslation $translation): static
    {
        if ($this->translations->removeElement($translation)) {
            if ($translation->getAppProfile() === $this) {
                $translation->setAppProfile(null);
            }
            $this->touch();
        }

        return $this;
    }

    /** Traduction dans la langue demandée, sinon en français (comme Entreprise::getTranslation) */
    public function getTranslation(string $locale): ?AppProfileTranslation
    {
        foreach ([$locale, 'fr'] as $language) {
            foreach ($this->translations as $translation) {
                if ($translation->getLanguage() === $language) {
                    return $translation;
                }
            }
        }

        return null;
    }

    public function __toString(): string
    {
        return self::APPS[$this->app] ?? $this->app;
    }

    public function getId(): ?int { return $this->id; }
    public function getApp(): string { return $this->app; }
    public function setApp(string $app): static { $this->app = strtolower(trim($app)); return $this->touch(); }
    public function getAppName(): ?string { return $this->appName; }
    public function setAppName(?string $v): static { $this->appName = self::clean($v); return $this->touch(); }
    public function getTagline(): ?string { return $this->tagline; }
    public function setTagline(?string $v): static { $this->tagline = self::clean($v); return $this->touch(); }
    public function getLogo(): ?string { return $this->logo; }
    public function setLogo(?string $v): static { $this->logo = self::clean($v); return $this->touch(); }
    public function getIcon(): ?string { return $this->icon; }
    public function setIcon(?string $v): static { $this->icon = self::clean($v); return $this->touch(); }
    public function getPrimaryColor(): ?string { return $this->primaryColor; }
    public function setPrimaryColor(?string $v): static { $this->primaryColor = self::color($v); return $this->touch(); }
    public function getAccentColor(): ?string { return $this->accentColor; }
    public function setAccentColor(?string $v): static { $this->accentColor = self::color($v); return $this->touch(); }
    public function getThemeMode(): ?string { return $this->themeMode; }
    public function setThemeMode(?string $v): static { $this->themeMode = self::clean($v); return $this->touch(); }
    public function getThemeFont(): ?string { return $this->themeFont; }
    public function setThemeFont(?string $v): static { $this->themeFont = self::clean($v); return $this->touch(); }
    public function getThemeRadius(): ?int { return $this->themeRadius; }
    public function setThemeRadius(?int $v): static { $this->themeRadius = $v; return $this->touch(); }
    public function getButtonStyle(): ?string { return $this->buttonStyle; }
    public function setButtonStyle(?string $v): static { $this->buttonStyle = self::clean($v); return $this->touch(); }
    public function getProductCard(): ?string { return $this->productCard; }
    public function setProductCard(?string $v): static { $this->productCard = self::clean($v); return $this->touch(); }
    public function getHomeHero(): ?string { return $this->homeHero; }
    public function setHomeHero(?string $v): static { $this->homeHero = self::clean($v); return $this->touch(); }
    public function getEmail(): ?string { return $this->email; }
    public function setEmail(?string $v): static { $this->email = self::clean($v); return $this->touch(); }
    public function getPhone(): ?string { return $this->phone; }
    public function setPhone(?string $v): static { $this->phone = self::clean($v); return $this->touch(); }
    public function getWebsite(): ?string { return $this->website; }
    public function setWebsite(?string $v): static { $this->website = self::clean($v); return $this->touch(); }
    public function getSupportUrl(): ?string { return $this->supportUrl; }
    public function setSupportUrl(?string $v): static { $this->supportUrl = self::clean($v); return $this->touch(); }
    public function getPrivacyPolicyUrl(): ?string { return $this->privacyPolicyUrl; }
    public function setPrivacyPolicyUrl(?string $v): static { $this->privacyPolicyUrl = self::clean($v); return $this->touch(); }
    public function getStreet1(): ?string { return $this->street1; }
    public function setStreet1(?string $v): static { $this->street1 = self::clean($v); return $this->touch(); }
    public function getStreet2(): ?string { return $this->street2; }
    public function setStreet2(?string $v): static { $this->street2 = self::clean($v); return $this->touch(); }
    public function getCity(): ?string { return $this->city; }
    public function setCity(?string $v): static { $this->city = self::clean($v); return $this->touch(); }
    public function getZip(): ?string { return $this->zip; }
    public function setZip(?string $v): static { $this->zip = self::clean($v); return $this->touch(); }
    public function getProvince(): ?string { return $this->province; }
    public function setProvince(?string $v): static { $this->province = self::clean($v); return $this->touch(); }
    public function getCountry(): ?string { return $this->country; }
    public function setCountry(?string $v): static { $this->country = self::clean($v); return $this->touch(); }
    public function getApropos(): ?string { return $this->apropos; }
    public function setApropos(?string $v): static { $this->apropos = self::clean($v); return $this->touch(); }
    public function getPrivacyPolicy(): ?string { return $this->privacyPolicy; }
    public function setPrivacyPolicy(?string $v): static { $this->privacyPolicy = self::clean($v); return $this->touch(); }
    public function getConditionOfUse(): ?string { return $this->conditionOfUse; }
    public function setConditionOfUse(?string $v): static { $this->conditionOfUse = self::clean($v); return $this->touch(); }
    public function getLegalNotice(): ?string { return $this->legalNotice; }
    public function setLegalNotice(?string $v): static { $this->legalNotice = self::clean($v); return $this->touch(); }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /** Une adresse propre à l'application est saisie (elle remplace alors toute celle de l'entreprise, sans mélange) */
    public function hasAddress(): bool
    {
        return ($this->street1 ?? $this->city ?? $this->zip ?? $this->country) !== null;
    }

    private static function clean(?string $v): ?string
    {
        $v = trim((string) $v);

        return $v === '' ? null : $v;
    }

    /**
     * Couleur normalisée (minuscules, « # » ajouté devant 6 chiffres hexadécimaux nus), vide → null ; une valeur qui
     * n'est pas une couleur est gardée telle quelle pour que le validateur la refuse (avant le 31/10/2026, primaryColor
     * la remplaçait par null en silence : seul le sélecteur de couleur d'EasyAdmin, qui envoie toujours #rrggbb, l'appelait)
     */
    private static function color(?string $v): ?string
    {
        $v = strtolower(trim((string) $v));
        if ($v !== '' && !str_starts_with($v, '#') && ctype_xdigit($v) && strlen($v) === 6) {
            $v = '#' . $v;
        }

        return $v === '' ? null : $v;
    }

    private function touch(): static
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
