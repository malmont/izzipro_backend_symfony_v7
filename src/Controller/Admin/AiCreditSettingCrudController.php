<?php

namespace App\Controller\Admin;

use App\Entity\AiCreditSetting;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use App\Services\LandingAiService\LandingAiInstructionsService;

/**
 * Crédits mensuels de l'assistant IA du tenant : une seule ligne (création possible uniquement s'il n'y en a pas,
 * pas de suppression). Sans ligne, le site dispose de 100 crédits par mois.
 */
class AiCreditSettingCrudController extends BaseTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return AiCreditSetting::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)
            ->setEntityLabelInSingular('Crédits de l\'assistant IA')
            ->setEntityLabelInPlural('Assistant IA : crédits')
            ->setPageTitle(Crud::PAGE_INDEX, 'Assistant IA : crédits mensuels')
            ->setHelp(Crud::PAGE_INDEX, sprintf(
                'Crédits disponibles chaque mois pour l\'assistant IA de l\'éditeur (retouche : 1, création : 3, page ou images : 10). Remise à zéro le 1er du mois (heure de Montréal). Sans réglage : %d crédits.',
                AiCreditSetting::DEFAULT_MONTHLY_CREDITS
            ));
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::DELETE, Action::BATCH_DELETE)
            ->update(Crud::PAGE_INDEX, Action::NEW, fn (Action $action) => $action->displayIf(fn () => !$this->hasSetting()));
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IntegerField::new('monthlyCredits', 'Crédits par mois')
                ->setHelp('Entre 0 (assistant désactivé) et 100 000.')
                ->setFormTypeOption('attr', ['min' => 0, 'max' => 100000]),
            TextareaField::new('assistantInstructions', 'Consignes de l\'assistant')
                ->setHelp(sprintf('Consignes permanentes redonnées à l\'assistant à chaque demande (ex. « garder nos couleurs, pas d\'animations, ton chaleureux »). Texte brut, %d caractères au plus. La demande du moment prime en cas de contradiction.', LandingAiInstructionsService::MAX_LENGTH))
                ->setFormTypeOption('attr', ['maxlength' => LandingAiInstructionsService::MAX_LENGTH, 'rows' => 6])
                ->hideOnIndex(),
        ];
    }

    /** Une seule ligne par site : une création en trop met à jour la ligne existante */
    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        $existing = $this->emProvider->getEntityManager()->getRepository(AiCreditSetting::class)->findOneBy([], ['id' => 'ASC']);
        if ($existing !== null) {
            $existing->setMonthlyCredits($entityInstance->getMonthlyCredits());
            if ($existing->getAssistantInstructions() !== $entityInstance->getAssistantInstructions()) {
                $existing->setAssistantInstructions($entityInstance->getAssistantInstructions());
            }
            $this->emProvider->getEntityManager()->flush();

            return;
        }
        parent::persistEntity($entityManager, $entityInstance);
    }

    private function hasSetting(): bool
    {
        return $this->emProvider->getEntityManager()->getRepository(AiCreditSetting::class)->count([]) > 0;
    }
}
