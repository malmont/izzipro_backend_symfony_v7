<?php

namespace App\Services\Admin;

use App\Services\AppMembershipService\AppMembershipDirectory;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Filter\FilterInterface;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FieldDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FilterDataDto;
use EasyCorp\Bundle\EasyAdminBundle\Filter\FilterTrait;
use EasyCorp\Bundle\EasyAdminBundle\Form\Filter\Type\ChoiceFilterType;
use EasyCorp\Bundle\EasyAdminBundle\Form\Type\ComparisonType;

/**
 * Filtre « Application » de l'écran Utilisateurs (APPS-MEMBRES, lot M4) : une famille d'applications, « Aucune » ou « Équipe ». Une
 * appartenance n'est pas une colonne du compte : le filtre demande à `AppMembershipDirectory` les comptes dont la colonne
 * « Applications » porte la valeur choisie, puis restreint la liste à ces identifiants (ou les en retire, avec « n'est pas »).
 */
final class ApplicationFilter implements FilterInterface
{
    use FilterTrait;

    private ?AppMembershipDirectory $directory = null;

    public static function new(AppMembershipDirectory $directory, string $propertyName = 'applications', string $label = 'Application'): self
    {
        $filter = (new self())
            ->setFilterFqcn(__CLASS__)
            ->setProperty($propertyName)
            ->setLabel($label)
            ->setFormType(ChoiceFilterType::class)
            ->setFormTypeOption('translation_domain', 'EasyAdminBundle')
            ->setFormTypeOption('value_type_options.choices', array_flip(AppMembershipDirectory::LABELS))
            ->setFormTypeOption('value_type_options.choice_translation_domain', false);
        $filter->directory = $directory;

        return $filter;
    }

    public function apply(QueryBuilder $queryBuilder, FilterDataDto $filterDataDto, ?FieldDto $fieldDto, EntityDto $entityDto): void
    {
        $value = $filterDataDto->getValue();
        if (!is_string($value) || $this->directory === null) {
            return; // rien de choisi : la liste entière
        }
        $ids = $this->directory->userIds($value);
        $excluded = in_array($filterDataDto->getComparison(), [ComparisonType::NEQ, 'NOT IN'], true);
        if ($ids === []) {
            $excluded ?: $queryBuilder->andWhere('1 = 0');

            return;
        }
        $queryBuilder->andWhere(sprintf('%s.id %s (:%s)', $filterDataDto->getEntityAlias(), $excluded ? 'NOT IN' : 'IN', $filterDataDto->getParameterName()))
            ->setParameter($filterDataDto->getParameterName(), $ids);
    }
}
