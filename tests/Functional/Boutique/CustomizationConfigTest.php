<?php

namespace App\Tests\Functional\Boutique;

use App\Entity\ProductCustomizationImage;
use App\Entity\ProductVariant;
use App\Services\BoutiqueDemoService\BoutiqueDemoSeeder;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Configuration de personnalisation (09/10/2026) : adresses vers le dossier qui contient vraiment le fichier, null si le
 * fichier n'existe plus, un seul résultat par ensemble d'options, doublon refusé à l'enregistrement.
 */
class CustomizationConfigTest extends WebTestCase
{
    public function testUrlsPointToRealFilesDuplicatesAreHiddenAndRefused(): void
    {
        $client = static::createClient();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        static::getContainer()->get(BoutiqueDemoSeeder::class)->seed('client-perso2@example.invalid', false, false);
        $em = $provider->getEntityManager();
        $db = $em->getConnection();
        $variantId = (int) $db->fetchOne("SELECT v.id FROM product_variant v JOIN product p ON p.id = v.product_id WHERE p.code = 'DEMO-GOURDE' ORDER BY v.id LIMIT 1");
        $variant = $em->getRepository(ProductVariant::class)->find($variantId);
        $original = $variant->getProductCustomizationImages()->first();

        // Doublon enregistré sans contrôle (comme les données existantes), avec un fichier disparu
        $duplicate = (new ProductCustomizationImage())->setImagePath('disparu-' . bin2hex(random_bytes(3)) . '.webp')->setNumberOfPieces(5);
        foreach ($original->getOptionValues() as $value) {
            $duplicate->addOptionValue($value);
        }
        $variant->addProductCustomizationImage($duplicate);
        $em->persist($duplicate);
        $em->flush();

        try {
            $client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/api/customization/config/' . $variantId, [], [], ['HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST]);
            $this->assertSame(200, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
            $config = json_decode((string) $client->getResponse()->getContent(), true);
            $keys = array_map(fn ($c) => implode(',', $c['optionIds']), $config['combinations']);
            $this->assertSame(count($keys), count(array_unique($keys)), 'un seul résultat par ensemble d\'options');
            $this->assertNotContains($duplicate->getId(), array_column($config['combinations'], 'id'), 'le doublon sans fichier est écarté');
            foreach ($config['combinations'] as $combination) {
                $this->assertTrue($combination['imageUrl'] === null || preg_match('#/assets/uploads/(customization|products)/[^/]+$#', $combination['imageUrl']) === 1, (string) $combination['imageUrl']);
            }

            // Enregistrement d'un nouveau doublon : refusé par la validation
            $again = (new ProductCustomizationImage())->setImagePath('x.webp')->setNumberOfPieces(1);
            foreach ($original->getOptionValues() as $value) {
                $again->addOptionValue($value);
            }
            $variant->addProductCustomizationImage($again);
            $violations = static::getContainer()->get(ValidatorInterface::class)->validate($again);
            $this->assertCount(1, $violations);
            $this->assertStringContainsString('déjà une combinaison', (string) $violations[0]->getMessage());
            $variant->removeProductCustomizationImage($again);
        } finally {
            $db->executeStatement('DELETE FROM product_customization_image_product_option_value WHERE product_customization_image_id = ?', [$duplicate->getId()]);
            $db->executeStatement('DELETE FROM product_customization_image WHERE id = ?', [$duplicate->getId()]);
        }
    }
}
