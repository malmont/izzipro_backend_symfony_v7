<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Services\TenantEntityManagerProvider;
use App\Controller\Admin\BaseTenantCrudController; // <-- 1. On importe notre base
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

// 2. On étend notre contrôleur de base
class UserCrudController extends BaseTenantCrudController
{
    private UserPasswordHasherInterface $userPasswordHasher;

    // 3. Le constructeur reçoit maintenant SES dépendances ET celles du parent
    public function __construct(
        UserPasswordHasherInterface $userPasswordHasher,
        TenantEntityManagerProvider $emProvider
    ) {
        // On passe le provider au constructeur du parent
        parent::__construct($emProvider);
        // On garde le hasher pour ce contrôleur
        $this->userPasswordHasher = $userPasswordHasher;
    }

    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureFields(string $pageName): iterable
    {
        // La configuration des champs ne change pas
        return [
            IdField::new('id')->hideOnForm(),
            EmailField::new('email'),
            TextField::new('username'),
            TextField::new('firstname'),
            TextField::new('lastname'),
            ArrayField::new('roles'),
            BooleanField::new('isVerified', 'Verified'),
            BooleanField::new('otpEnabled', 'OTP Enabled'),
            TextField::new('plainPassword', 'Password')
                ->setFormType(PasswordType::class)
                ->onlyOnForms(),
        ];
    }

    // La méthode createIndexQueryBuilder a été SUPPRIMÉE. Le parent s'en charge.

    // 4. Les méthodes d'écriture sont SIMPLIFIÉES.
    // Elles ajoutent leur logique spécifique PUIS appellent le parent.
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
    
    // La logique de hachage reste ici car elle est spécifique à l'entité User.
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