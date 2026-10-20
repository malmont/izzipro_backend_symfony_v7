<?php

namespace App\Entity;

use App\Repository\AppProfileRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Profil d'une application du site (20/10/2026) : nom, logo, coordonnées et textes propres à une application
 * (Living Memory, Mémoires Vivantes web, Boussole ESG, POS…). Une ligne au plus par application, désignée par son
 * code technique (fixe, embarqué dans l'application) ; tous les champs sont facultatifs, un champ vide est pris dans
 * la fiche entreprise (GET /api/app-profile/{app}). Table vide par défaut. Réglé dans EasyAdmin, « Profils des
 * applications ». La landing page et la boutique n'ont pas de code : elles utilisent la fiche entreprise.
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
    private ?string $primaryColor = null;

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

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
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
    public function setPrimaryColor(?string $v): static
    {
        $v = strtolower(trim((string) $v));
        if ($v !== '' && !str_starts_with($v, '#') && ctype_xdigit($v)) {
            $v = '#' . $v;
        }
        $this->primaryColor = preg_match('/^#[0-9a-f]{6}$/', $v) ? $v : null;

        return $this->touch();
    }
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

    private function touch(): static
    {
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }
}
