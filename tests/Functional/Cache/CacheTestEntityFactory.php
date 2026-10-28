<?php

namespace App\Tests\Functional\Cache;

use App\Entity\TranslatableInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * Entités de test construites depuis les métadonnées Doctrine (tests d'invalidation ciblée, 28/10/2026) : chaque champ
 * obligatoire reçoit une valeur neutre, une traduction `fr` est ajoutée aux entités traduisibles, et une association
 * obligatoire fait échouer le test (à créer à la main). La classe hôte fournit em().
 */
trait CacheTestEntityFactory
{
    abstract protected function em(): EntityManagerInterface;

    /**
     * @param class-string $class
     * @param array<string, mixed> $values champs imposés (nom de champ Doctrine => valeur)
     * @param ?callable(object, EntityManagerInterface): void $configure réglages par accesseurs avant l'enregistrement
     */
    protected function create(string $class, array $values = [], ?callable $configure = null): object
    {
        $em = $this->em();
        $entity = new $class();
        $metadata = $em->getClassMetadata($class);
        foreach ($values as $field => $value) {
            if (is_object($value) && !$em->contains($value)) {
                // em() bascule le site et vide l'EntityManager : une entité trouvée avant est rattachée
                $value = $em->find($em->getClassMetadata(get_class($value))->getName(), $value->getId());
            }
            $metadata->setFieldValue($entity, $field, $value);
        }
        if ($configure !== null) {
            $configure($entity, $em);
        }
        $this->fill($metadata, $entity);
        if ($metadata->generatorType === ClassMetadata::GENERATOR_TYPE_NONE && $metadata->getIdentifierValues($entity) === []) {
            // identifiant assigné par l'application (référentiels) : une valeur hors de la plage des données
            $metadata->setIdentifierValues($entity, [$metadata->getSingleIdentifierFieldName() => random_int(900000, 999999)]);
        }
        foreach ($metadata->associationMappings as $mapping) {
            if (($mapping['type'] & ClassMetadata::TO_ONE) && !($mapping['joinColumns'][0]['nullable'] ?? true)
                && $metadata->getFieldValue($entity, $mapping['fieldName']) === null) {
                $this->fail($class . '::' . $mapping['fieldName'] . ' : association obligatoire, à créer à la main dans ce test');
            }
        }
        if ($entity instanceof TranslatableInterface) {
            $translationClass = $entity->getTranslationEntityClass();
            $translation = new $translationClass();
            $translationMetadata = $em->getClassMetadata($translationClass);
            $this->fill($translationMetadata, $translation);
            foreach ($translationMetadata->associationMappings as $field => $mapping) {
                if (($mapping['type'] & ClassMetadata::TO_ONE) && is_a($class, $mapping['targetEntity'], true)) {
                    $translationMetadata->setFieldValue($translation, $field, $entity);
                }
            }
            $entity->addTranslation($translation);
            $em->persist($translation);
        }
        $em->persist($entity);
        $em->flush();

        return $entity;
    }

    private function fill(ClassMetadata $metadata, object $entity): void
    {
        $suffix = bin2hex(random_bytes(3));
        foreach ($metadata->fieldMappings as $field => $mapping) {
            if (!empty($mapping['id']) || ($mapping['nullable'] ?? false) || $metadata->getFieldValue($entity, $field) !== null) {
                continue;
            }
            $value = match ($mapping['type']) {
                'string' => $field === 'language' || $field === 'locale' ? 'fr' : substr('Cache test ' . $suffix, 0, (int) ($mapping['length'] ?? 255)),
                'text' => 'Cache test ' . $suffix,
                'integer', 'smallint', 'bigint' => 1,
                'float' => 1.0,
                'decimal' => '1.00',
                'boolean' => false,
                'datetime', 'date', 'time' => new \DateTime(),
                'datetime_immutable', 'date_immutable', 'time_immutable' => new \DateTimeImmutable(),
                'json', 'array', 'simple_array' => [],
                default => null,
            };
            if ($value !== null) {
                $metadata->setFieldValue($entity, $field, $value);
            }
        }
    }
}
