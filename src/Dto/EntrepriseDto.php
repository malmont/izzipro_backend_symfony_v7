<?php
namespace App\Dto;

use App\Entity\Entreprise;

class EntrepriseDto
{
    public ?int $id = null;
    public ?string $name = null;
    public ?string $logo = null;
    public ?string $faviconUrl = null;
    public ?string $email = null;
    public ?string $apropos = null;
    public ?string $tel = null;
    public ?string $website = null;
    public ?string $ein = null;
    public ?string $tvaIntracommunautaire = null;
    public ?string $conditionOfUse = null;
    public ?string $LegalNotice = null;
    public ?string $privacyPolicy = null;
    public ?string $adress = null;

    public static function fromEntity(Entreprise $entreprise, string $host, string $locale): self
    {
        $dto = new self();
        $translation = $entreprise->getTranslation($locale);
        $dto->id = $entreprise->getId();
        $dto->name = $entreprise->getName();
        $dto->email = $entreprise->getEmail();
        $dto->tel = $entreprise->getTel();
        $dto->website = $entreprise->getWebsite();
        $dto->ein = $entreprise->getEin();
        $dto->tvaIntracommunautaire = $entreprise->getTvaIntracommunautaire();
        $dto->adress = $entreprise->getAdress();
        
        $imagePath = $entreprise->getLogo();
        if (str_starts_with((string)$imagePath, 'http')) {
            $dto->logo = $imagePath;
        } else if ($imagePath) {
            $dto->logo = rtrim($host, '/') . '/assets/uploads/email-logos/' . $imagePath;
        }
        $faviconPath = $entreprise->getFaviconFilename();
        if (str_starts_with((string)$faviconPath, 'http')) {
            $dto->faviconUrl = $faviconPath;
        } else if ($faviconPath) {
            $dto->faviconUrl = rtrim($host, '/') . '/assets/uploads/email-logos/' . $faviconPath;
        }

        $dto->conditionOfUse = $translation?->getConditionOfUse() ?? $entreprise->getConditionOfUse();
        $dto->LegalNotice = $translation?->getLegalNotice() ?? $entreprise->getLegalNotice();
        $dto->privacyPolicy = $translation?->getPrivacyPolicy() ?? $entreprise->getPrivacyPolicy();
        $dto->apropos = $translation?->getApropos() ?? $entreprise->getApropos();

        return $dto;
    }
}