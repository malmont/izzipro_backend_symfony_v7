<?php

namespace App\Services\Admin;

use Doctrine\DBAL\Types\Types;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Filter\FilterConfiguratorInterface;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FieldDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FilterDto;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;

/**
 * Tout filtre de liste sur une date et heure, dans un écran de site, lit l'heure du magasin (lot EA-FUSEAU, 12/11/2026) : « le 12 à
 * 9 h » veut dire 9 h au magasin, et la comparaison se fait en UTC, comme la colonne. Un filtre sur une date pure ou une heure seule
 * n'est pas touché ; un filtre qui fixe lui-même son fuseau garde le sien.
 */
final class StoreTimezoneFilterConfigurator implements FilterConfiguratorInterface
{
    private const DATE_TIME_TYPES = [Types::DATETIME_MUTABLE, Types::DATETIME_IMMUTABLE, Types::DATETIMETZ_MUTABLE, Types::DATETIMETZ_IMMUTABLE];

    public function __construct(private readonly AdminStoreTimezone $timezone)
    {
    }

    public function supports(FilterDto $filterDto, ?FieldDto $fieldDto, EntityDto $entityDto, AdminContext $context): bool
    {
        return DateTimeFilter::class === $filterDto->getFqcn() && AdminStoreTimezone::applies($context->getCrud()?->getControllerFqcn());
    }

    public function configure(FilterDto $filterDto, ?FieldDto $fieldDto, EntityDto $entityDto, AdminContext $context): void
    {
        $mapping = $entityDto->getClassMetadata()->fieldMappings[$filterDto->getProperty()] ?? null;
        $type = is_array($mapping) ? ($mapping['type'] ?? null) : ($mapping->type ?? null);
        if (!in_array($type, self::DATE_TIME_TYPES, true)) {
            return;
        }
        // `value_type_options` remplace le défaut d'EasyAdmin en entier : le champ unique (`single_text`) doit être redit
        $filterDto->setFormTypeOptionIfNotSet('value_type_options.widget', 'single_text');
        $filterDto->setFormTypeOptionIfNotSet('value_type_options.view_timezone', $this->timezone->name());
        $filterDto->setFormTypeOptionIfNotSet('value_type_options.model_timezone', 'UTC');
    }
}
