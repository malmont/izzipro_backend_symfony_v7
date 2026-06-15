<?php

namespace App\Controller\Admin;

use App\Entity\SocialNetwork;
use App\Controller\Admin\BaseTenantCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;

class SocialNetworkCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return SocialNetwork::class;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            ChoiceField::new('name', 'Réseau Social')
                ->setChoices([
                    'Facebook' => 'facebook',
                    'Instagram' => 'instagram',
                    'LinkedIn' => 'linkedin',
                    'Twitter' => 'twitter',
                    'YouTube' => 'youtube',
                    'TikTok' => 'tiktok',
                ])
                ->setRequired(true),
            UrlField::new('url', 'Lien / URL')
                ->setRequired(false),
        ];
    }
}
