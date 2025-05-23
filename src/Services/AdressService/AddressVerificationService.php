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