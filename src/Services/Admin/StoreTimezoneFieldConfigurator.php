<?php

namespace App\Services\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldConfiguratorInterface;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\FieldDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Tout champ date et heure d'un écran de site se lit et se saisit à l'heure du magasin (lot EA-FUSEAU, 12/11/2026).
 *
 * EasyAdmin n'applique le fuseau d'un champ qu'à l'affichage (liste, fiche) : le formulaire, lui, lisait la saisie comme de l'UTC. Ici :
 * - affichage : le champ reçoit le fuseau du magasin, sauf s'il fixe le sien ;
 * - saisie : le formulaire montre et lit l'heure dans ce même fuseau (`view_timezone`) et rend une date en UTC (`model_timezone`), celle
 *   qui est en base ;
 * - libellé : « (heure du magasin) », ou « (UTC) » pour un site sans fuseau, sur le formulaire et la fiche (pas dans l'en-tête de liste).
 *
 * Les champs de date pure (`DateField`) et d'heure seule (`TimeField`) ne sont pas touchés : un fuseau les décalerait d'un jour ou d'heures.
 * Priorité 10 : avant le configurateur des dates d'EasyAdmin (priorité 0), qui lit le fuseau du champ pour formater la valeur.
 */
#[AsTaggedItem(priority: 10)]
final class StoreTimezoneFieldConfigurator implements FieldConfiguratorInterface
{
    public function __construct(private readonly AdminStoreTimezone $timezone)
    {
    }

    public function supports(FieldDto $field, EntityDto $entityDto): bool
    {
        return DateTimeField::class === $field->getFieldFqcn();
    }

    public function configure(FieldDto $field, EntityDto $entityDto, AdminContext $context): void
    {
        $crud = $context->getCrud();
        if (!AdminStoreTimezone::applies($crud?->getControllerFqcn())) {
            return;
        }
        $own = $field->getCustomOption(DateTimeField::OPTION_TIMEZONE);
        $timezone = is_string($own) && $own !== '' ? $own : $this->timezone->name();
        $field->setCustomOption(DateTimeField::OPTION_TIMEZONE, $timezone);
        $field->setFormTypeOptionIfNotSet('view_timezone', $timezone);
        $field->setFormTypeOptionIfNotSet('model_timezone', 'UTC');

        // Libellé : EasyAdmin l'a déjà emballé pour la traduction (texte, paramètres, domaine) ; un libellé absent ou masqué reste tel quel
        if (!in_array($crud?->getCurrentPage(), [Crud::PAGE_EDIT, Crud::PAGE_NEW, Crud::PAGE_DETAIL], true)) {
            return;
        }
        $label = $field->getLabel();
        $text = $label instanceof TranslatableMessage ? $label->getMessage() : (is_string($label) ? $label : '');
        if ($text === '' || str_contains($text, AdminStoreTimezone::STORE_MENTION) || str_contains($text, AdminStoreTimezone::UTC_MENTION)) {
            return;
        }
        $text .= ' ' . ($timezone === $this->timezone->name() ? $this->timezone->mention() : AdminStoreTimezone::STORE_MENTION);
        $mentioned = $label instanceof TranslatableMessage ? new TranslatableMessage($text, $label->getParameters(), $label->getDomain()) : $text;
        $field->setLabel($mentioned);
        if ($field->getFormTypeOption('label') === $label) { // libellé du formulaire : copie du libellé du champ, sauf si l'écran en a fixé un autre
            $field->setFormTypeOption('label', $mentioned);
        }
    }
}
