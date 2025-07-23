<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Repository\AdressRepository; // <-- Importer le repository
use App\Services\TenantEntityManagerProvider;
use App\Controller\Admin\BaseTenantCrudController;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder; 
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField; 
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;


class UserCrudController extends BaseTenantCrudController
{
    private UserPasswordHasherInterface $userPasswordHasher;

    public function __construct(
        UserPasswordHasherInterface $userPasswordHasher,
        TenantEntityManagerProvider $emProvider
    ) {
        parent::__construct($emProvider);
        $this->userPasswordHasher = $userPasswordHasher;
    }

    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureFields(string $pageName): iterable
    {
        $tenantEm = $this->emProvider->getEntityManager();

        return [
            IdField::new('id')->hideOnForm(),
            EmailField::new('email'),
            TextField::new('username'),
            TextField::new('firstname'),
            TextField::new('lastname'),
            AssociationField::new('primaryAddress', 'Adresse Principale')
                ->setFormTypeOptions([
                    'em' => $tenantEm,
                    'query_builder' => function (AdressRepository $repo) {
                        $currentUser = $this->getContext()->getEntity()->getInstance();
                        return $repo->createQueryBuilder('a')
                            ->where('a.userAdress = :user')
                            ->setParameter('user', $currentUser)
                            ->orderBy('a.fullname', 'ASC');
                    },
                    'choice_label' => '__toString', 
                ])
                ->setRequired(false), 
            AssociationField::new('adresses', 'Toutes les adresses')
                ->hideOnForm(), 

            ArrayField::new('roles'),
            BooleanField::new('isVerified', 'Verified'),
            BooleanField::new('otpEnabled', 'OTP Enabled'),
            TextField::new('plainPassword', 'Password')
                ->setFormType(PasswordType::class)
                ->onlyOnForms(),
            NumberField::new('gemsuiteClientId', 'gemsuiteClientId'),    
        ];
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->hashPassword($entityInstance);
        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->hashPassword($entityInstance);
        parent::updateEntity($entityManager, $entityInstance);
    }
    
    private function hashPassword($entityInstance): void
    {
        if ($entityInstance instanceof User && $entityInstance->getPlainPassword()) {
            $hashedPassword = $this->userPasswordHasher->hashPassword(
                $entityInstance,
                $entityInstance->getPlainPassword()
            );
            $entityInstance->setPassword($hashedPassword);
            $entityInstance->setPlainPassword(null);
        }
    }
}
