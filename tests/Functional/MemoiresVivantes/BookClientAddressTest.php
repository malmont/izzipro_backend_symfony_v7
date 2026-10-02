<?php

namespace App\Tests\Functional\MemoiresVivantes;

use App\MemoiresVivantes\Services\MapsLink;

/**
 * Adresse du client sur le livre (30/09/2026) : modifiable par le propriétaire, renvoyée avec un lien d'itinéraire
 * Google Maps pour le biographe, jamais montrée à un invité venu par un lien de partage.
 */
class BookClientAddressTest extends BookTypeApiTestCase
{
    public function testAddressIsSavedAndReturnedWithADirectionsLink(): void
    {
        [, $book] = $this->api('POST', '/books', ['title' => 'Livre avec adresse', 'type' => 'individuel', 'clientAddress' => " 12 rue des Flamboyants\n97200 Fort-de-France "], 'client');
        $this->assertSame("12 rue des Flamboyants\n97200 Fort-de-France", $book['clientAddress']);
        $this->assertSame('https://www.google.com/maps/dir/?api=1&destination=12%20rue%20des%20Flamboyants%2097200%20Fort-de-France', $book['clientAddressMapsUrl']);

        [$status, $updated] = $this->api('PUT', "/books/{$book['id']}", ['client_address' => '5 avenue Victor Hugo, Le Lamentin'], 'client');
        $this->assertSame(200, $status);
        $this->assertSame('5 avenue Victor Hugo, Le Lamentin', $updated['client_address']);
        $this->assertStringContainsString('Le%20Lamentin', $updated['client_address_maps_url']);

        [, $untouched] = $this->api('PUT', "/books/{$book['id']}", ['title' => 'Titre seul'], 'client');
        $this->assertSame('5 avenue Victor Hugo, Le Lamentin', $untouched['clientAddress'], 'champ absent : adresse inchangée');

        [, $cleared] = $this->api('PUT', "/books/{$book['id']}", ['clientAddress' => ''], 'client');
        $this->assertNull($cleared['clientAddress'], 'chaîne vide : adresse effacée');
        $this->assertNull($cleared['clientAddressMapsUrl']);

        $this->assertNull(MapsLink::directionsUrl('   '));
    }

    public function testTheNumberOfSessionsFollowsTheBook(): void
    {
        // Planificateur de séances : le nombre choisi n'était gardé que dans le navigateur
        [, $book] = $this->api('POST', '/books', ['title' => 'Livre à séances', 'type' => 'individuel', 'sessionCount' => 5], 'client');
        $this->assertSame(5, $book['sessionCount']);

        [, $updated] = $this->api('PUT', "/books/{$book['id']}", ['sessionCount' => 8], 'client');
        $this->assertSame(8, $updated['sessionCount']);
        [, $untouched] = $this->api('PUT', "/books/{$book['id']}", ['title' => 'Titre seul'], 'client');
        $this->assertSame(8, $untouched['sessionCount'], 'champ absent : inchangé');
        [, $invalid] = $this->api('PUT', "/books/{$book['id']}", ['sessionCount' => 40], 'client');
        $this->assertSame(8, $invalid['sessionCount'], 'hors de 1 à 12 : ignoré');

        [, $none] = $this->api('POST', '/books', ['title' => 'Sans choix', 'type' => 'individuel'], 'client');
        $this->assertNull($none['sessionCount']);
    }

    public function testAGuestNeverSeesTheAddress(): void
    {
        [, $book] = $this->api('POST', '/books', ['title' => 'Livre privé', 'type' => 'famille', 'clientAddress' => '12 rue des Flamboyants'], 'client');
        [, $chapter] = $this->api('POST', "/books/{$book['id']}/chapters", ['title' => 'Paroles', 'theme' => 'regards_croises', 'position' => 2, 'answers' => []], 'client');
        [, $contributor] = $this->api('POST', "/books/{$book['id']}/contributors", ['firstName' => 'Léa', 'role' => 'enfant'], 'client');

        $expires = time() + 600;
        $secret = static::getContainer()->getParameter('kernel.secret');
        $query = http_build_query(['chapterId' => $chapter['id'], 'contributorId' => $contributor['id'], 'expires' => $expires,
            'signature' => hash_hmac('sha256', "chapterId={$chapter['id']}&contributorId={$contributor['id']}&expires=$expires", $secret)]);
        [$status, $guestView] = $this->api('GET', "/books/{$book['id']}?$query", null, null);

        $this->assertSame(200, $status);
        $this->assertNull($guestView['clientAddress']);
        $this->assertNull($guestView['clientAddressMapsUrl']);
        $this->assertStringNotContainsString('Flamboyants', json_encode($guestView, JSON_UNESCAPED_UNICODE));
    }
}
