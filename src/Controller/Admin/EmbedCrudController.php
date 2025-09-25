<?php

namespace App\Controller\Admin;

use App\Entity\Embed;
use App\Controller\Admin\BaseTenantCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;


class EmbedCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return Embed::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),
            TextField::new('titre', 'Titre (pour identification)'),
            UrlField::new('embedUrl', 'URL à intégrer'),
        ];
    }
}
