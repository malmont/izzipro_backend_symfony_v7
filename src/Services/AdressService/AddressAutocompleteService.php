<?php
// src/Services/AdressService/AddressAutocompleteService.php

namespace App\Services\AdressService;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use App\Services\AdressService\GooglePlacesKeyProvider;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class AddressAutocompleteService
{
    public function __construct(
        private HttpClientInterface     $client,
        private GooglePlacesKeyProvider $keyProvider
    ) {}

    /**
     * Récupère les suggestions d’adresse (description + place_id).
     *
     * @param string $input
     * @return array<int, array{description:string,place_id:string}>
     * @throws TransportExceptionInterface
     */
    public function suggest(string $input): array
    {
        $key = $this->keyProvider->getKey();
        $response = $this->client->request('GET', 'https://maps.googleapis.com/maps/api/place/autocomplete/json', [
            'query' => [
                'input' => $input,
                'types' => 'address',
                'key'   => $key,
            ],
        ]);

        $data = $response->toArray(false);

        if (empty($data['predictions']) || !is_array($data['predictions'])) {
            return [];
        }

        $out = [];
        foreach ($data['predictions'] as $pred) {
            $out[] = [
                'description' => $pred['description'] ?? '',
                'place_id'    => $pred['place_id']    ?? '',
            ];
        }

        return $out;
    }

    /**
     * Récupère les détails d’une adresse à partir d’un place_id.
     *
     * @param string $placeId
     * @return array{
     *   street1:string,
     *   street2:?string,
     *   city:string,
     *   province:string,
     *   postal_code:string,
     *   country:string
     * }
     * @throws TransportExceptionInterface
     */
    public function getDetails(string $placeId): array
    {
        $key = $this->keyProvider->getKey();
        $response = $this->client->request('GET', 'https://maps.googleapis.com/maps/api/place/details/json', [
            'query' => [
                'place_id' => $placeId,
                'fields'   => 'address_components',
                'key'      => $key,
            ],
        ]);

        $data = $response->toArray(false);
        $components = $data['result']['address_components'] ?? [];

        // map des composants
        $map = [
            'street_number'               => '',
            'route'                       => '',
            'locality'                    => '',
            'administrative_area_level_1' => '',
            'postal_code'                 => '',
            'country'                     => '',
        ];

        foreach ($components as $c) {
            $types = $c['types'] ?? [];
            if (in_array('street_number', $types, true)) {
                $map['street_number'] = $c['long_name'];
            }
            if (in_array('route', $types, true)) {
                $map['route'] = $c['long_name'];
            }
            if (in_array('locality', $types, true)) {
                $map['locality'] = $c['long_name'];
            }
            if (in_array('administrative_area_level_1', $types, true)) {
                $map['administrative_area_level_1'] = $c['short_name'];
            }
            if (in_array('postal_code', $types, true)) {
                $map['postal_code'] = $c['long_name'];
            }
            if (in_array('country', $types, true)) {
                $map['country'] = $c['short_name'];
            }
        }

        return [
            'street1'     => trim($map['street_number'] . ' ' . $map['route']),
            'street2'     => null,
            'city'        => $map['locality'],
            'province'    => $map['administrative_area_level_1'],
            'postal_code' => $map['postal_code'],
            'country'     => $map['country'],
        ];
    }
}
