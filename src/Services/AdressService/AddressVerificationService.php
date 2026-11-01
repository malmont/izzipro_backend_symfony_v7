<?php
namespace App\Services\AdressService;

use App\Services\ShippingService\EasyPostService;
use RuntimeException;

class AddressVerificationService
{
    public function __construct(private EasyPostService $easyPostService) {}

    /**
     * Vérifie et normalise une adresse via EasyPost.
     *
     * @param array $address  // clefs : street1, street2, city, province, postal_code, country
     * @return array          // adresse normalisée
     * @throws RuntimeException si l’adresse est invalide
     */
    /** Vérification EasyPost possible sur ce site (clé configurée pour l'environnement courant) */
    public function isConfigured(): bool
    {
        return $this->easyPostService->hasConfiguration();
    }

    /**
     * Avec EasyPost : vérifie et normalise (inchangé, RuntimeException si l'adresse est refusée). Sans EasyPost :
     * l'adresse est gardée telle quelle, province ramenée à son code quand le nom est connu (31/10/2026 ; avant, un
     * site sans configuration répondait 500 à toute création d'adresse).
     *
     * @param array $address  // clefs : street1, street2, city, province, postal_code, country
     */
    public function verifyOrKeep(array $address): array
    {
        if ($this->isConfigured()) {
            return $this->verify($address);
        }

        return [
            'street1'     => $address['street1'],
            'street2'     => $address['street2'] ?? null,
            'city'        => $address['city'],
            'province'    => ProvinceCodes::normalize((string) $address['province'], (string) ($address['country'] ?? '')),
            'postal_code' => $address['postal_code'],
            'country'     => $address['country'],
        ];
    }

    public function verify(array $address): array
    {
        $client = $this->easyPostService->getClient();

        $result = $client->address->create([
            'street1' => $address['street1'],
            'street2' => $address['street2'] ?? null,
            'city'    => $address['city'],
            'state'   => $address['province'],
            'zip'     => $address['postal_code'],
            'country' => $address['country'],
            'verify'  => ['delivery'],
        ]);

        $errors = $result->verifications->delivery->errors ?? [];
        if (empty($errors)) {
            return [
                'street1'     => $result->street1,
                'street2'     => $result->street2,
                'city'        => $result->city,
                'province'    => $result->state,
                'postal_code' => $result->zip,
                'country'     => $result->country,
            ];
        }

        $msgs = array_map(fn($e) => $e->message, $errors);
        throw new RuntimeException('Adresse invalide : ' . implode(' ; ', $msgs));
    }
}