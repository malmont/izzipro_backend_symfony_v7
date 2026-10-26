<?php

namespace App\Tests\Functional\Doctrine;

use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Cohérence de tous les côtés inverses des relations Doctrine (S8, 26/10/2026) : doctrine:schema:validate ne détecte pas un mappedBy
 * qui désigne une association pointant vers une autre entité (StatusPayment::$orders → Order::$status, un StatusCommande : TypeError
 * à l'hydratation, GET /api/products en 500). Ce contrôle couvre chaque OneToMany / OneToOne inverse de toutes les entités.
 */
class InverseSideMappingTest extends KernelTestCase
{
    public function testEveryInverseSidePointsBackToItsOwnEntity(): void
    {
        self::bootKernel();
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);
        $em = $provider->getEntityManager();
        $checked = 0;
        $errors = [];
        foreach ($em->getMetadataFactory()->getAllMetadata() as $meta) {
            foreach ($meta->getAssociationMappings() as $field => $mapping) {
                if (empty($mapping['mappedBy'])) {
                    continue;
                }
                $checked++;
                $target = $em->getClassMetadata($mapping['targetEntity']);
                if (!$target->hasAssociation($mapping['mappedBy'])) {
                    $errors[] = sprintf('%s::$%s : %s::$%s n\'est pas une association', $meta->name, $field, $mapping['targetEntity'], $mapping['mappedBy']);
                    continue;
                }
                $owner = $target->getAssociationMapping($mapping['mappedBy'])['targetEntity'];
                if (!is_a($meta->name, $owner, true) && !is_a($owner, $meta->name, true)) {
                    $errors[] = sprintf('%s::$%s (mappedBy %s) : %s::$%s pointe vers %s', $meta->name, $field, $mapping['mappedBy'], $mapping['targetEntity'], $mapping['mappedBy'], $owner);
                }
            }
        }
        $this->assertGreaterThan(100, $checked, 'côtés inverses contrôlés');
        $this->assertSame([], $errors);
    }
}
