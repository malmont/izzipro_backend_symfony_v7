<?php

namespace App\Services\SocialNetworkService;

use App\Entity\Entreprise;
use App\Entity\SocialNetwork;
use App\Services\TenantEntityManagerProvider;

class SocialNetworkService
{
    public function __construct(private TenantEntityManagerProvider $emProvider)
    {
    }

    /**
     * @return SocialNetwork[]
     */
    public function getSocialNetworksByEntreprise(Entreprise $entreprise): array
    {
        $em = $this->emProvider->getEntityManager();
        $repository = $em->getRepository(SocialNetwork::class);
        return $repository->findBy(['entreprise' => $entreprise]);
    }

    public function updateSocialNetworksForEntreprise(Entreprise $entreprise, array $companyData): void
    {
        $em = $this->emProvider->getEntityManager();

        $fields = [
            'facebook' => 'website_facebook',
            'instagram' => 'website_instagram',
            'linkedin' => 'website_linkedin',
            'twitter' => 'website_twitter',
            'youtube' => 'website_youtube',
            'tiktok' => 'website_tiktok',
        ];

        foreach ($fields as $name => $payloadKey) {
            if (!array_key_exists($payloadKey, $companyData)) {
                continue;
            }

            $url = $companyData[$payloadKey];

            // Find if it already exists
            $socialNetwork = null;
            foreach ($entreprise->getSocialNetworks() as $sn) {
                if ($sn->getName() === $name) {
                    $socialNetwork = $sn;
                    break;
                }
            }

            if (!$socialNetwork) {
                $socialNetwork = new SocialNetwork();
                $socialNetwork->setName($name);
                $entreprise->addSocialNetwork($socialNetwork);
            }

            $socialNetwork->setUrl($url);
            $em->persist($socialNetwork);
        }
    }
}
