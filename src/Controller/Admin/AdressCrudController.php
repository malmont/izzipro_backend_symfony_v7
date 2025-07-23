<?php
namespace App\Controller\Admin;

use App\Entity\Adress;
use App\Repository\UserRepository;
use App\Controller\Admin\BaseTenantCrudController;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;

class AdressCrudController extends BaseTenantCrudController
{

    public static function getEntityFqcn(): string
    {
        return Adress::class;
    }

    public function configureFields(string $pageName): iterable
    {
        $tenantEm = $this->emProvider->getEntityManager();

        return [
            IdField::new('id')->hideOnForm(),
            AssociationField::new('userAdress', 'Utilisateur associé')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => function (UserRepository $repo) {
                        return $repo->createQueryBuilder('u')->orderBy('u.email', 'ASC');
                    },
                    'choice_label' => 'email',
                ]),
            TextField::new('firstname', 'Prénom'),
            TextField::new('lastname', 'Nom'),
            TextField::new('company', 'Entreprise')->hideOnIndex(),
            TextareaField::new('address', 'Adresse'),
            TextareaField::new('complement', 'Complément d\'adresse')->hideOnIndex(),
            TextField::new('phone', 'Téléphone'),
            TextField::new('city', 'Ville'),
            TextField::new('codepostal', 'Code postal'),
            TextField::new('country', 'Pays'),
            BooleanField::new('isPrimary', 'Définir comme adresse principale')
                ->setFormTypeOption('mapped', false)
                ->onlyOnForms()
        ];
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        parent::persistEntity($entityManager, $entityInstance);
        $isPrimary = $this->getContext()->getRequest()->get('Adress')['isPrimary'] ?? false;
        
        if ($isPrimary && $entityInstance instanceof Adress) {
            $user = $entityInstance->getUserAdress();
            if ($user) {
                $user->setPrimaryAddress($entityInstance);
                $entityManager->persist($user);
                $entityManager->flush();
            }
        }
    }
    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        parent::updateEntity($entityManager, $entityInstance);
        $isPrimary = $this->getContext()->getRequest()->get('Adress')['isPrimary'] ?? false;

        if ($isPrimary && $entityInstance instanceof Adress) {
            $user = $entityInstance->getUserAdress();
            if ($user) {
                $user->setPrimaryAddress($entityInstance);
                $entityManager->persist($user);
                $entityManager->flush();
            }
        }
    }
}
