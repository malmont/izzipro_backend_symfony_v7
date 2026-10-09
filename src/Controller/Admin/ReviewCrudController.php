<?php

namespace App\Controller\Admin;

use App\Entity\ReviewsProduct;
use App\Services\ReviewService\ReviewModerationService;
use App\Services\TenantEntityManagerProvider;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;

/**
 * Avis clients (09/10/2026) : les avis en attente d'abord ; Publier / Refuser depuis la liste, réponse publique et motif
 * de refus dans le formulaire. On modère le contenu (insulte, données personnelles, hors sujet), jamais la note.
 * Les avis viennent des clients : pas de création ici. Filtre par statut : ?status=pending|approved|rejected (menu).
 */
class ReviewCrudController extends BaseTenantCrudController
{
    use CsrfProtectedActionTrait;

    private const STATUS_LABELS = ['En attente' => ReviewsProduct::STATUS_PENDING, 'Publié' => ReviewsProduct::STATUS_APPROVED, 'Refusé' => ReviewsProduct::STATUS_REJECTED];

    public function __construct(TenantEntityManagerProvider $emProvider, private readonly ReviewModerationService $moderation)
    {
        parent::__construct($emProvider);
    }

    public static function getEntityFqcn(): string
    {
        return ReviewsProduct::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setEntityLabelInSingular('Avis client')->setEntityLabelInPlural('Avis clients')
            ->setPageTitle('index', 'Avis clients')
            ->setHelp('index', 'Les avis en attente apparaissent en premier. Publiez ou refusez selon le contenu (insulte, données personnelles, hors sujet), jamais selon la note : la loi interdit d\'écarter les avis négatifs. Réglages (achat vérifié, modération, politique affichée) : menu « Réglages des avis ».')
            ->setHelp('edit', 'La réponse est publique, affichée sous l\'avis. Le motif de refus n\'est montré qu\'à l\'auteur, dans « Mes avis ».');
    }

    public function configureActions(Actions $actions): Actions
    {
        $approve = Action::new('approveReview', 'Publier', 'fas fa-check')
            ->linkToUrl(fn (ReviewsProduct $r) => $this->csrfActionUrl('approveReview', (string) $r->getId()))
            ->displayIf(fn (ReviewsProduct $r) => !$r->isApproved())->addCssClass('text-success');
        $reject = Action::new('rejectReview', 'Refuser', 'fas fa-ban')
            ->linkToUrl(fn (ReviewsProduct $r) => $this->csrfActionUrl('rejectReview', (string) $r->getId()))
            ->displayIf(fn (ReviewsProduct $r) => $r->getStatus() !== ReviewsProduct::STATUS_REJECTED)->addCssClass('text-danger');

        return $actions->disable(Action::NEW)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $approve)->add(Crud::PAGE_INDEX, $reject)
            ->add(Crud::PAGE_DETAIL, $approve)->add(Crud::PAGE_DETAIL, $reject);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield ChoiceField::new('status', 'Statut')->setChoices(self::STATUS_LABELS)
            ->renderAsBadges([ReviewsProduct::STATUS_PENDING => 'warning', ReviewsProduct::STATUS_APPROVED => 'success', ReviewsProduct::STATUS_REJECTED => 'danger']);
        yield AssociationField::new('productReviews', 'Produit')->hideOnForm();
        yield IntegerField::new('rating', 'Note')->hideOnForm()
            ->formatValue(fn ($value) => str_repeat('★', (int) $value) . str_repeat('☆', 5 - (int) $value));
        yield TextField::new('title', 'Titre')->hideOnForm();
        yield TextareaField::new('body', 'Avis')->hideOnForm()->setMaxLength(160);
        yield TextField::new('authorName', 'Auteur')->hideOnForm();
        yield AssociationField::new('userReview', 'Compte client')->onlyOnDetail();
        yield BooleanField::new('verifiedPurchase', 'Achat vérifié')->renderAsSwitch(false)->hideOnForm();
        yield AssociationField::new('order', 'Commande')->onlyOnDetail();
        yield TextareaField::new('reply', 'Réponse publique')->hideOnIndex()->setHelp('Visible sous l\'avis, en clair (pas de mise en forme). Vide = pas de réponse.');
        yield TextField::new('rejectionReason', 'Motif de refus')->hideOnIndex()->setHelp('Montré au seul auteur.');
        yield DateTimeField::new('createdAt', 'Déposé le')->hideOnForm();
        yield DateTimeField::new('publishedAt', 'Publié le')->onlyOnDetail();
        yield DateTimeField::new('repliedAt', 'Réponse le')->onlyOnDetail();
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $qb = $this->emProvider->getEntityManager()->getRepository(ReviewsProduct::class)->createQueryBuilder('entity')
            ->addSelect("CASE WHEN entity.status = 'pending' THEN 0 ELSE 1 END AS HIDDEN pendingFirst")
            ->orderBy('pendingFirst', 'ASC')->addOrderBy('entity.id', 'DESC');
        $status = $this->getContext()?->getRequest()->query->get('status');
        if (in_array($status, ReviewsProduct::STATUSES, true)) {
            $qb->andWhere('entity.status = :status')->setParameter('status', $status);
        }

        return $qb;
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        /** @var ReviewsProduct $entityInstance */
        $original = $this->emProvider->getEntityManager()->getUnitOfWork()->getOriginalEntityData($entityInstance);
        $this->moderation->afterAdminEdit($entityInstance, $original['reply'] ?? null, $original['repliedAt'] ?? null);
    }

    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $product = $entityInstance->getProductReviews();
        parent::deleteEntity($entityManager, $entityInstance);
        if ($product !== null) {
            $this->moderation->refreshProduct($product);
        }
    }

    public function approveReview(AdminContext $context): Response
    {
        return $this->moderate($context, 'approveReview', fn (ReviewsProduct $r) => $this->moderation->approve($r), 'Avis publié.');
    }

    public function rejectReview(AdminContext $context): Response
    {
        // Après le refus, le formulaire de l'avis s'ouvre : le motif (montré au seul auteur) se saisit tout de suite
        return $this->moderate($context, 'rejectReview', fn (ReviewsProduct $r) => $this->moderation->reject($r), 'Avis refusé. Indiquez le motif ci-dessous : l\'auteur le verra dans « Mes avis ».', Action::EDIT);
    }

    private function moderate(AdminContext $context, string $action, callable $apply, string $message, string $then = Action::INDEX): Response
    {
        $index = $this->container->get(AdminUrlGenerator::class)->unsetAll()->setController(self::class)->setAction(Action::INDEX)->generateUrl();
        if (!$this->isCsrfActionValid($context, $action)) {
            $this->addFlash('danger', 'Lien invalide ou expiré : action annulée. Utilisez le bouton depuis la liste.');

            return $this->redirect($index);
        }
        $review = $context->getEntity()->getInstance();
        if ($review instanceof ReviewsProduct) {
            $apply($review);
            $this->addFlash('success', $message);
            if ($then === Action::EDIT) {
                return $this->redirect($this->container->get(AdminUrlGenerator::class)->unsetAll()->setController(self::class)->setAction(Action::EDIT)->setEntityId($review->getId())->generateUrl());
            }
        }

        return $this->redirect($index);
    }
}
