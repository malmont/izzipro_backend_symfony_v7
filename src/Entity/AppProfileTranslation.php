<?php

namespace App\Entity;

use App\Repository\AppProfileTranslationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Traduction d'un profil d'application (20/10/2026), sur le modèle de EntrepriseTranslation : nom affiché, accroche,
 * à propos et textes légaux dans une langue. Un champ vide reprend celui du profil (langue par défaut), puis celui de
 * la fiche entreprise traduite. Une traduction au plus par langue et par profil.
 */
#[ORM\Entity(repositoryClass: AppProfileTranslationRepository::class)]
#[ORM\Table(name: 'app_profile_translation')]
#[ORM\UniqueConstraint(name: 'uniq_app_profile_translation_language', columns: ['app_profile_id', 'language'])]
class AppProfileTranslation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 10)]
    private ?string $language = null;

    #[ORM\Column(name: 'app_name', length: 255, nullable: true)]
    private ?string $appName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $tagline = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $apropos = null;

    #[ORM\Column(name: 'privacy_policy', type: Types::TEXT, nullable: true)]
    private ?string $privacyPolicy = null;

    #[ORM\Column(name: 'condition_of_use', type: Types::TEXT, nullable: true)]
    private ?string $conditionOfUse = null;

    #[ORM\Column(name: 'legal_notice', type: Types::TEXT, nullable: true)]
    private ?string $legalNotice = null;

    #[ORM\ManyToOne(inversedBy: 'translations')]
    #[ORM\JoinColumn(name: 'app_profile_id', onDelete: 'CASCADE')]
    private ?AppProfile $appProfile = null;

    public function getId(): ?int { return $this->id; }
    public function getLanguage(): ?string { return $this->language; }
    public function setLanguage(?string $language): static { $this->language = $language === null ? null : strtolower(trim($language)); return $this; }
    public function getAppName(): ?string { return $this->appName; }
    public function setAppName(?string $v): static { $this->appName = self::clean($v); return $this; }
    public function getTagline(): ?string { return $this->tagline; }
    public function setTagline(?string $v): static { $this->tagline = self::clean($v); return $this; }
    public function getApropos(): ?string { return $this->apropos; }
    public function setApropos(?string $v): static { $this->apropos = self::clean($v); return $this; }
    public function getPrivacyPolicy(): ?string { return $this->privacyPolicy; }
    public function setPrivacyPolicy(?string $v): static { $this->privacyPolicy = self::clean($v); return $this; }
    public function getConditionOfUse(): ?string { return $this->conditionOfUse; }
    public function setConditionOfUse(?string $v): static { $this->conditionOfUse = self::clean($v); return $this; }
    public function getLegalNotice(): ?string { return $this->legalNotice; }
    public function setLegalNotice(?string $v): static { $this->legalNotice = self::clean($v); return $this; }
    public function getAppProfile(): ?AppProfile { return $this->appProfile; }
    public function setAppProfile(?AppProfile $appProfile): static { $this->appProfile = $appProfile; return $this; }

    private static function clean(?string $v): ?string
    {
        $v = trim((string) $v);

        return $v === '' ? null : $v;
    }
}
