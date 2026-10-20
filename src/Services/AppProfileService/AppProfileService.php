<?php

namespace App\Services\AppProfileService;

use App\Dto\EntrepriseDto;
use App\Entity\AppProfile;
use App\Entity\Entreprise;
use App\Repository\AppProfileRepository;
use App\Services\EntrepriseService\EntrepriseService;
use App\Services\TenantEntityManagerProvider;

/**
 * Profil public d'une application du site (20/10/2026) : la ligne app_profile de son code, fusionnée champ par champ
 * avec la fiche entreprise (un champ vide de l'application prend la valeur de l'entreprise). L'adresse est prise en
 * bloc : celle de l'application si elle en a une, sinon celle de l'entreprise. `sources` dit d'où vient chaque valeur.
 */
class AppProfileService
{
    /** Champs avec un équivalent dans la fiche entreprise : champ du profil → propriété de EntrepriseDto */
    private const FALLBACK = [
        'name' => 'name', 'logo' => 'logo', 'icon' => 'faviconUrl', 'email' => 'email', 'phone' => 'tel',
        'website' => 'website', 'apropos' => 'apropos', 'privacyPolicy' => 'privacyPolicy',
        'conditionOfUse' => 'conditionOfUse', 'legalNotice' => 'LegalNotice',
    ];

    public function __construct(private readonly TenantEntityManagerProvider $emProvider, private readonly EntrepriseService $entreprises)
    {
    }

    public static function isKnown(string $app): bool
    {
        return isset(AppProfile::APPS[$app]);
    }

    /** @return array<string, mixed> profil fusionné (voir docs/architecture.md, « Profils des applications ») */
    public function resolve(string $app, string $host, string $locale = 'fr'): array
    {
        $profile = $this->repository()->findOneByApp($app);
        $company = $this->company($host, $locale);

        $own = [
            'name' => $profile?->getAppName(),
            'logo' => EntrepriseDto::uploadUrl($profile?->getLogo(), $host),
            'icon' => EntrepriseDto::uploadUrl($profile?->getIcon(), $host),
            'email' => $profile?->getEmail(), 'phone' => $profile?->getPhone(), 'website' => $profile?->getWebsite(),
            'apropos' => $profile?->getApropos(), 'privacyPolicy' => $profile?->getPrivacyPolicy(),
            'conditionOfUse' => $profile?->getConditionOfUse(), 'legalNotice' => $profile?->getLegalNotice(),
        ];
        $values = [];
        $sources = [];
        foreach (self::FALLBACK as $field => $companyField) {
            if ($own[$field] !== null) {
                $values[$field] = $own[$field];
                $sources[$field] = 'app';
            } else {
                $values[$field] = $company?->{$companyField};
                $sources[$field] = $values[$field] === null ? null : 'entreprise';
            }
        }
        // Sans équivalent dans la fiche entreprise
        foreach (['tagline' => $profile?->getTagline(), 'primaryColor' => $profile?->getPrimaryColor(), 'supportUrl' => $profile?->getSupportUrl(), 'privacyPolicyUrl' => $profile?->getPrivacyPolicyUrl()] as $field => $value) {
            $values[$field] = $value;
            $sources[$field] = $value === null ? null : 'app';
        }
        if ($profile?->hasAddress()) {
            $address = ['street1' => $profile->getStreet1(), 'street2' => $profile->getStreet2(), 'city' => $profile->getCity(), 'zip' => $profile->getZip(), 'province' => $profile->getProvince(), 'country' => $profile->getCountry()];
            $address['oneLine'] = self::oneLine($address);
            $sources['address'] = 'app';
        } else {
            $address = ['street1' => $company?->street1, 'street2' => $company?->street2, 'city' => $company?->city, 'zip' => $company?->zip, 'province' => $company?->province, 'country' => $company?->country, 'oneLine' => $company?->adress];
            $sources['address'] = ($address['street1'] ?? $address['city'] ?? $address['oneLine']) === null ? null : 'entreprise';
        }

        return [
            'app' => $app,
            'appLabel' => AppProfile::APPS[$app],
            'hasProfile' => $profile !== null,
            'name' => $values['name'], 'tagline' => $values['tagline'], 'logo' => $values['logo'], 'icon' => $values['icon'],
            'primaryColor' => $values['primaryColor'], 'email' => $values['email'], 'phone' => $values['phone'],
            'website' => $values['website'], 'supportUrl' => $values['supportUrl'], 'privacyPolicyUrl' => $values['privacyPolicyUrl'],
            'address' => $address,
            'apropos' => $values['apropos'], 'privacyPolicy' => $values['privacyPolicy'], 'conditionOfUse' => $values['conditionOfUse'], 'legalNotice' => $values['legalNotice'],
            'sources' => $sources,
            'entreprise' => $company ? ['id' => $company->id, 'name' => $company->name] : null,
            'updatedAt' => $profile?->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /** Fiche entreprise du site (la première : une seule par site), dans la langue demandée */
    private function company(string $host, string $locale): ?EntrepriseDto
    {
        $entity = $this->emProvider->getEntityManager()->getRepository(Entreprise::class)->findOneBy([], ['id' => 'ASC']);

        return $entity ? $this->entreprises->getEntrepriseByIdAndLocale((int) $entity->getId(), $host, $locale) : null;
    }

    private static function oneLine(array $a): ?string
    {
        $line = implode(', ', array_filter([$a['street1'], $a['street2'], trim(($a['zip'] ?? '') . ' ' . ($a['city'] ?? '')), $a['province'], $a['country']], fn ($v) => $v !== null && trim((string) $v) !== ''));

        return $line === '' ? null : $line;
    }

    private function repository(): AppProfileRepository
    {
        return $this->emProvider->getEntityManager()->getRepository(AppProfile::class);
    }
}
