<?php

namespace App\Tests\Fake;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Google Places simulé pour l'autocomplétion d'adresses (aucun appel réseau pendant les tests) : une suggestion,
 * un seul place_id connu, tout autre identifiant répond NOT_FOUND comme l'API réelle.
 */
final class FakeGooglePlacesHttpClient extends MockHttpClient
{
    public const KNOWN_PLACE_ID = 'ChIJ-test-connu';

    public function __construct()
    {
        parent::__construct(function (string $method, string $url): MockResponse {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $headers = ['response_headers' => ['content-type' => 'application/json']];
            if (str_contains($url, '/place/autocomplete/')) {
                return new MockResponse(json_encode(['status' => 'OK', 'predictions' => [
                    ['description' => '1 rue du Test, Montréal, QC, Canada', 'place_id' => self::KNOWN_PLACE_ID],
                ]]), $headers);
            }
            if (($query['place_id'] ?? '') === self::KNOWN_PLACE_ID) {
                return new MockResponse(json_encode(['status' => 'OK', 'result' => ['address_components' => [
                    ['long_name' => '1', 'short_name' => '1', 'types' => ['street_number']],
                    ['long_name' => 'rue du Test', 'short_name' => 'rue du Test', 'types' => ['route']],
                    ['long_name' => 'Montréal', 'short_name' => 'Montréal', 'types' => ['locality']],
                    ['long_name' => 'Québec', 'short_name' => 'QC', 'types' => ['administrative_area_level_1']],
                    ['long_name' => 'Canada', 'short_name' => 'CA', 'types' => ['country']],
                    ['long_name' => 'H1A 1A1', 'short_name' => 'H1A 1A1', 'types' => ['postal_code']],
                ]]]), $headers);
            }

            return new MockResponse(json_encode(['status' => 'NOT_FOUND', 'error_message' => 'place_id inconnu']), $headers);
        });
    }
}
