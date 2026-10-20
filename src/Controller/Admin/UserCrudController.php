<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Repository\AdressRepository; // <-- Importer le repository
use App\Services\TenantEntityManagerProvider;
use App\Controller\Admin\BaseTenantCrudController;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder; 
use App\Security\RoleAssignmentPolicy;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
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
    use CsrfProtectedActionTrait;
    private UserPasswordHasherInterface $userPasswordHasher;

    public function __construct(
        UserPasswordHasherInterface $userPasswordHasher,
        TenantEntityManagerProvider $emProvider,
        private readonly RoleAssignmentPolicy $rolePolicy,
        private readonly ?\App\Services\PosAuditService\PosPinService $posPins = null,
        private readonly ?\App\Services\PosAuditService\PosAuditService $posAudit = null
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
                    'choice_label' => 'formattedForChoice'
                ])
                ->setRequired(false), 
            AssociationField::new('adresses', 'Toutes les adresses')
                ->hideOnForm(), 

            // Liste fermée : ROLE_SUPER_ADMIN n'est proposé qu'à un super administrateur (voir RoleAssignmentPolicy)
            ChoiceField::new('roles', 'Rôles')
                ->setChoices($this->rolePolicy->choices($this->isGranted(RoleAssignmentPolicy::SUPER_ADMIN)))
                ->allowMultipleChoices()
                ->renderExpanded(),
            BooleanField::new('isVerified', 'Verified'),
            BooleanField::new('otpEnabled', 'OTP Enabled'),
            TextField::new('plainPassword', 'Password')
                ->setFormType(PasswordType::class)
                ->onlyOnForms(),
        ];
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->hashPassword($entityInstance);
        $this->applyRolePolicy($entityInstance, []);
        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->hashPassword($entityInstance);
        $original = $this->emProvider->getEntityManager()->getUnitOfWork()->getOriginalEntityData($entityInstance);
        $this->applyRolePolicy($entityInstance, (array) ($original['roles'] ?? []));
        parent::updateEntity($entityManager, $entityInstance);
    }

    /** Règle appliquée côté serveur, même si le formulaire est forgé */
    private function applyRolePolicy($entityInstance, array $previousRoles): void
    {
        if ($entityInstance instanceof User) {
            $entityInstance->setRoles($this->rolePolicy->apply(
                $entityInstance->getRoles(),
                $previousRoles,
                $this->isGranted(RoleAssignmentPolicy::SUPER_ADMIN)
            ));
        }
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

    /** Action « Réinitialiser le PIN de caisse » (C3) : efface le PIN et lève le blocage ; le caissier en recrée un avec son mot de passe */
    public function configureActions(\EasyCorp\Bundle\EasyAdminBundle\Config\Actions $actions): \EasyCorp\Bundle\EasyAdminBundle\Config\Actions
    {
        $reset = \EasyCorp\Bundle\EasyAdminBundle\Config\Action::new('resetPosPin', 'Réinitialiser le PIN de caisse', 'fas fa-key')
            ->linkToUrl(fn (User $user) => $this->csrfActionUrl('resetPosPin', (string) $user->getId()))
            ->displayIf(fn ($user) => $user instanceof User && array_intersect(['ROLE_USER_POS', 'ROLE_POS_MANAGER', 'ROLE_ADMIN'], $user->getRoles()) !== [])
            ->setHtmlAttributes(['onclick' => 'return confirm(' . json_encode('Effacer le PIN de caisse de cet utilisateur ? Il devra en recréer un.', JSON_UNESCAPED_UNICODE) . ');']);

        return parent::configureActions($actions)
            ->add(\EasyCorp\Bundle\EasyAdminBundle\Config\Crud::PAGE_DETAIL, $reset)
            ->add(\EasyCorp\Bundle\EasyAdminBundle\Config\Crud::PAGE_INDEX, $reset);
    }

    public function resetPosPin(\EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext $context): \Symfony\Component\HttpFoundation\Response
    {
        $user = $context->getEntity()->getInstance();
        if (!$user instanceof User || !$this->isCsrfActionValid($context, 'resetPosPin') || !$this->posPins) {
            $this->addFlash('danger', 'Lien invalide ou expiré : action annulée.');
        } else {
            $hadPin = $this->posPins->reset($user);
            $this->posAudit?->record(\App\Services\PosAuditService\PosAuditService::PIN_RESET, ['userId' => $user->getId(), 'email' => $user->getEmail(), 'hadPin' => $hadPin], subjectType: 'user', subjectId: $user->getId());
            $this->addFlash('success', $hadPin ? 'PIN de caisse réinitialisé : l\'utilisateur en recréera un avec son mot de passe.' : 'Cet utilisateur n\'avait pas de PIN de caisse.');
        }
        $url = $this->container->get(\EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator::class)->unsetAll()->setController(self::class)->setAction(\EasyCorp\Bundle\EasyAdminBundle\Config\Action::INDEX);

        return $this->redirect($url->generateUrl());
    }
}
