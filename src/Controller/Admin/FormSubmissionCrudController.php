<?php

namespace App\Controller\Admin;

use App\Entity\FormSubmission;
use App\Services\FormSubmissionService\FormSubmissionCsvWriter;
use App\Services\FormSubmissionService\FormSubmissionService;
use App\Services\TenantEntityManagerProvider;
use App\UseCase\FormSubmissionUseCase\ExportFormSubmissionsUseCase;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\DateTimeFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\TextFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;

/**
 * Demandes reçues par les formulaires à étapes (28/10/2026) : liste, filtres, détail des réponses, statut (actions
 * rapides), suppression et export CSV. Mêmes règles que l'API d'administration : tout passe par FormSubmissionService
 * (journal limité aux métadonnées). Pas de création ni de modification des réponses.
 */
class FormSubmissionCrudController extends BaseTenantCrudController
{
    use CsrfProtectedActionTrait;

    /** action EasyAdmin => [statut, libellé, icône] */
    private const STATUS_ACTIONS = [
        'markRead' => ['read', 'Marquer lue', 'fa fa-envelope-open'],
        'markDone' => ['done', 'Marquer traitée', 'fa fa-check'],
        'markArchived' => ['archived', 'Archiver', 'fa fa-box-archive'],
        'markNew' => ['new', 'Remettre en nouvelle', 'fa fa-rotate-left'],
    ];

    public function __construct(
        TenantEntityManagerProvider $emProvider,
        private readonly FormSubmissionService $submissions,
        private readonly ExportFormSubmissionsUseCase $exporter
    ) {
        parent::__construct($emProvider);
    }

    public static function getEntityFqcn(): string
    {
        return FormSubmission::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)
            ->setEntityLabelInSingular('Demande reçue')
            ->setEntityLabelInPlural('Demandes reçues')
            ->setDefaultSort(['createdAt' => 'DESC', 'id' => 'DESC'])
            ->setSearchFields(['reference', 'formName'])
            ->setPageTitle(Crud::PAGE_DETAIL, fn (FormSubmission $s) => sprintf('Demande %s', $s->getReference()));
    }

    public function configureFields(string $pageName): iterable
    {
        $statuses = array_flip(FormSubmissionCsvWriter::STATUS_LABELS);

        yield TextField::new('reference', 'Référence');
        yield DateTimeField::new('createdAt', 'Reçue le')->setFormat('dd/MM/yyyy HH:mm');
        yield TextField::new('formName', 'Formulaire');
        yield ChoiceField::new('status', 'Statut')->setChoices($statuses)->renderAsBadges(['new' => 'warning', 'read' => 'info', 'done' => 'success', 'archived' => 'secondary']);
        yield ChoiceField::new('target', 'Cible')->setChoices(['Générique' => 'generic', 'Réservation' => 'reservation', 'Contact' => 'contact']);
        yield TextField::new('locale', 'Langue')->hideOnIndex();
        yield TextField::new('answersText', 'Réponses')->onlyOnDetail()->setTemplatePath('admin/fields/form_submission_answers.html.twig');
        yield ChoiceField::new('app', 'Application')->setChoices(['Landing page' => 'landingpage', 'Boutique' => 'boutique'])->onlyOnDetail();
        yield TextField::new('sectionUid', 'Section (uid)')->onlyOnDetail();
        yield TextField::new('formId', 'Formulaire (id)')->onlyOnDetail();
        yield IntegerField::new('reservationId', 'Réservation n°')->onlyOnDetail();
        yield IntegerField::new('contactId', 'Contact n°')->onlyOnDetail();
        yield DateTimeField::new('notifiedAt', 'Notifiée le')->setFormat('dd/MM/yyyy HH:mm')->onlyOnDetail();
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(ChoiceFilter::new('status', 'Statut')->setChoices(array_flip(FormSubmissionCsvWriter::STATUS_LABELS)))
            ->add(TextFilter::new('formName', 'Formulaire'))
            ->add(DateTimeFilter::new('createdAt', 'Reçue le'));
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions = $actions
            ->disable(Action::NEW, Action::EDIT)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, Action::new('exportCsv', 'Exporter (CSV)', 'fa fa-file-csv')->linkToCrudAction('exportCsv')->createAsGlobalAction());
        foreach (self::STATUS_ACTIONS as $name => [$status, $label, $icon]) {
            $action = Action::new($name, $label, $icon)
                ->linkToUrl(fn (FormSubmission $s) => $this->csrfActionUrl($name, (int) $s->getId()))
                ->displayIf(fn (FormSubmission $s) => $s->getStatus() !== $status);
            $actions = $actions->add(Crud::PAGE_DETAIL, $action)->add(Crud::PAGE_INDEX, $action);
        }

        return $actions;
    }

    public function markRead(AdminContext $context): Response { return $this->changeStatus($context, 'markRead'); }
    public function markDone(AdminContext $context): Response { return $this->changeStatus($context, 'markDone'); }
    public function markArchived(AdminContext $context): Response { return $this->changeStatus($context, 'markArchived'); }
    public function markNew(AdminContext $context): Response { return $this->changeStatus($context, 'markNew'); }

    /** Export CSV de toutes les demandes (ExportFormSubmissionsUseCase::MAX_ROWS au plus, les plus récentes) */
    public function exportCsv(AdminContext $context): Response
    {
        $export = $this->exporter->execute([]);
        if ($export['truncated']) {
            $this->addFlash('warning', sprintf('Export limité aux %d demandes les plus récentes.', ExportFormSubmissionsUseCase::MAX_ROWS));
        }

        return new Response($export['csv'], 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $export['filename']),
            'Cache-Control' => 'no-store',
        ]);
    }

    /** Suppression (bouton et suppression groupée) : par le service, journalisée sans les réponses */
    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $submission = $entityInstance instanceof FormSubmission ? $this->submissions->find((int) $entityInstance->getId()) : null;
        if ($submission !== null) {
            $this->submissions->delete($submission);
        }
    }

    private function changeStatus(AdminContext $context, string $action): Response
    {
        $index = $this->container->get(AdminUrlGenerator::class)->unsetAll()->setController(self::class)->setAction(Action::INDEX)->generateUrl();
        if (!$this->isCsrfActionValid($context, $action)) {
            $this->addFlash('danger', 'Lien invalide ou expiré : action annulée. Utilisez le bouton depuis la liste.');

            return $this->redirect($index);
        }
        $instance = $context->getEntity()->getInstance();
        $submission = $instance instanceof FormSubmission ? $this->submissions->find((int) $instance->getId()) : null;
        if ($submission === null) {
            $this->addFlash('danger', 'Demande introuvable.');

            return $this->redirect($index);
        }
        $status = self::STATUS_ACTIONS[$action][0];
        $this->submissions->updateStatus($submission, $status);
        $this->addFlash('success', sprintf('Demande %s : %s.', $submission->getReference(), mb_strtolower(FormSubmissionCsvWriter::STATUS_LABELS[$status])));

        return $this->redirect($context->getReferrer() ?: $index);
    }
}
