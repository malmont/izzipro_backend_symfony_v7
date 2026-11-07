<?php

namespace App\Controller\Admin;

use App\Entity\GooglePlacesConfig;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;

class GooglePlacesConfigCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return GooglePlacesConfig::class;
    }

    public function configureActions(Actions $actions): Actions
    {
        return parent::configureActions($actions)
            ->disable(Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->setPermission(Action::NEW, 'ROLE_SUPER_ADMIN')
            ->setPermission(Action::EDIT, 'ROLE_SUPER_ADMIN')
            ->setPermission(Action::DELETE, 'ROLE_SUPER_ADMIN');
    }

    public function configureCrud(\EasyCorp\Bundle\EasyAdminBundle\Config\Crud $crud): \EasyCorp\Bundle\EasyAdminBundle\Config\Crud
    {
        return parent::configureCrud($crud)->setEntityLabelInSingular('Clés Google Places')->setEntityLabelInPlural('Adresses (Google Places)')
            ->setHelp('index', 'Clé d\'un projet Google Cloud avec l\'API Places : complète les adresses saisies par vos clients et affiche vos avis Google. Sans clé, l\'adresse se saisit à la main. Le texte d\'adresse tapé par le client est envoyé à Google : à mentionner dans votre politique de confidentialité.');
    }

    public function configureFields(string $pageName): iterable
    {

        return [
            IdField::new('id')->hideOnForm(),
            TextField::new('googleApiKeyTest', 'Clé Google Places (test)')
                ->setFormType(PasswordType::class)
                ->setRequired(false)
                ->setFormTypeOption('empty_data', '')
                ->setTemplatePath('admin/field/masked_secret.html.twig') // index et détail : jamais la valeur, même dans title
                ->setHelp('Entrez une nouvelle clé pour la mettre à jour. Laissez vide pour ne pas la changer.'),
            TextField::new('googleApiKeyProd', 'Clé Google Places (prod)')
                ->setFormType(PasswordType::class)
                ->setRequired(false)
                ->setFormTypeOption('empty_data', '')
                ->setTemplatePath('admin/field/masked_secret.html.twig') // index et détail : jamais la valeur, même dans title
                ->setHelp('Entrez une nouvelle clé pour la mettre à jour. Laissez vide pour ne pas la changer.'),
        ];
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN', null, 'Configuration Google Places réservée au super administrateur.');
        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN', null, 'Configuration Google Places réservée au super administrateur.');
        if ($entityInstance instanceof GooglePlacesConfig) {
            $id = (int) $entityInstance->getId();
            if ($id <= 0) {
                throw $this->createAccessDeniedException('Configuration Google Places introuvable.');
            }
            $row = $this->emProvider->getEntityManager()->getConnection()->fetchAssociative(
                'SELECT google_api_key_test, google_api_key_prod_encrypted FROM google_places_config WHERE id = ?',
                [$id]
            );
            if (!is_array($row)) {
                throw $this->createAccessDeniedException('Configuration Google Places introuvable.');
            }
            if (trim((string) $entityInstance->getGoogleApiKeyTest()) === '' && $row['google_api_key_test'] !== null) {
                $entityInstance->setGoogleApiKeyTest((string) $row['google_api_key_test']);
            }
            if (trim((string) $entityInstance->getGoogleApiKeyProd()) === '' && $row['google_api_key_prod_encrypted'] !== null) {
                (new \ReflectionProperty(GooglePlacesConfig::class, 'googleApiKeyProdEncrypted'))
                    ->setValue($entityInstance, (string) $row['google_api_key_prod_encrypted']);
            }
        }
        parent::updateEntity($entityManager, $entityInstance);
    }

    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $this->denyAccessUnlessGranted('ROLE_SUPER_ADMIN', null, 'Configuration Google Places réservée au super administrateur.');
        parent::deleteEntity($entityManager, $entityInstance);
    }
}
