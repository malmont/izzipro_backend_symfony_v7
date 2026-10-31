<?php

namespace App\Controller\Admin\Field;

use App\Form\Type\JsonTextType;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface;
use EasyCorp\Bundle\EasyAdminBundle\Field\FieldTrait;

/**
 * Champ EasyAdmin d'une colonne json éditée en texte JSON (31/10/2026).
 * Ni `Field::new` (EasyAdmin devine un ArrayField et pousse les options d'une collection au formulaire texte)
 * ni `TextareaField` (refuse une valeur tableau) ne conviennent : ce champ dédié n'est touché par aucun configurateur.
 */
final class JsonTextField implements FieldInterface
{
    use FieldTrait;

    public static function new(string $propertyName, $label = null): self
    {
        return (new self())
            ->setProperty($propertyName)
            ->setLabel($label)
            ->setTemplateName('crud/field/textarea')
            ->setFormType(JsonTextType::class)
            ->addCssClass('field-textarea')
            ->setFormTypeOption('attr', ['rows' => 8, 'class' => 'font-monospace'])
            ->setDefaultColumns('col-md-9 col-xxl-7')
            ->setCustomOption('renderAsHtml', false)
            ->setCustomOption('stripTags', false)
            ->formatValue(static fn ($value) => \is_array($value)
                ? json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : $value);
    }
}
