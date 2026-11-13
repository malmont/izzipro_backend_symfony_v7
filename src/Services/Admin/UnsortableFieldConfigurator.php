<?php

namespace App\Services\Admin;

use Doctrine\DBAL\Types\Types;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldConfiguratorInterface;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FieldDto;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Colonnes qui ne se trient pas, sur les écrans de site (lot EA-LISTES, 13/11/2026, `docs/architecture.md`, « Listes d'EasyAdmin ») :
 * - une colonne JSON ou tableau (rôles d'un utilisateur, configuration d'une page) : la base n'a pas d'ordre pour ce type ;
 * - une association vue du côté inverse (l'adresse depuis l'entreprise) : la colonne est dans l'autre table.
 * Depuis que les listes appliquent le tri d'EasyAdmin, un clic sur un tel en-tête faisait une erreur. L'en-tête n'est plus cliquable,
 * et un tri demandé à la main dans l'adresse est écarté par `BaseTenantCrudController::createIndexQueryBuilder`. Vaut pour tout écran, présent ou futur.
 */
#[AsTaggedItem(priority: 10)]
final class UnsortableFieldConfigurator implements FieldConfiguratorInterface
{
    private const UNSORTABLE_TYPES = [Types::JSON, Types::ARRAY, Types::SIMPLE_ARRAY, Types::OBJECT, Types::BLOB];

    public function supports(FieldDto $field, EntityDto $entityDto): bool
    {
        return self::isUnsortable($entityDto, $field->getProperty());
    }

    /** Vrai si la propriété ne peut pas servir à trier la liste (voir l'en-tête de la classe) */
    public static function isUnsortable(EntityDto $entityDto, string $property): bool
    {
        $metadata = $entityDto->getClassMetadata();
        if (isset($metadata->associationMappings[$property])) {
            $mapping = $metadata->associationMappings[$property];

            return !(is_array($mapping) ? ($mapping['isOwningSide'] ?? true) : $mapping->isOwningSide());
        }
        $mapping = $metadata->fieldMappings[$property] ?? null;
        $type = is_array($mapping) ? ($mapping['type'] ?? null) : ($mapping->type ?? null);

        return in_array($type, self::UNSORTABLE_TYPES, true);
    }

    public function configure(FieldDto $field, EntityDto $entityDto, AdminContext $context): void
    {
        if (AdminStoreTimezone::applies($context->getCrud()?->getControllerFqcn())) {
            $field->setSortable(false);
        }
    }
}
