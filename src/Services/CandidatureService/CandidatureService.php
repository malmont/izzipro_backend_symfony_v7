<?php
namespace App\Services\CandidatureService;

use App\Dto\CandidatureInputDto;
use App\Entity\Candidature;
use App\Entity\Emploi;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CandidatureService
{
    private TenantEntityManagerProvider $emProvider;

    public function __construct(TenantEntityManagerProvider $emProvider)
    {
        $this->emProvider = $emProvider;
    }

    public function getAllCandidatures(): array
    {
        $tenantEm = $this->emProvider->getEntityManager();
        return $tenantEm->getRepository(Candidature::class)->findAll();
    }

    public function findCandidature(int $id): ?Candidature
    {
        $tenantEm = $this->emProvider->getEntityManager();
        return $tenantEm->getRepository(Candidature::class)->find($id);
    }

    public function createCandidature(CandidatureInputDto $dto): Candidature
    {
        $tenantEm = $this->emProvider->getEntityManager();
        
        $emploi = $tenantEm->getRepository(Emploi::class)->find($dto->emploiId);
        if (!$emploi) {
            throw new NotFoundHttpException('Offre d\'emploi associée non trouvée.');
        }

        $candidature = new Candidature();
        $candidature->setNomComplet($dto->nomComplet);
        $candidature->setCourriel($dto->courriel);
        $candidature->setEmploi($emploi);
        $candidature->setTel($dto->tel);
        $candidature->setAdresse($dto->adresse);
        $candidature->setVille($dto->ville);
        $candidature->setCodePostal($dto->codePostal);
        $candidature->setAnneeExperience($dto->anneeExperience);
        if ($dto->dateDisponibilite) {
            $candidature->setDateDisponibilite(new \DateTime($dto->dateDisponibilite));
        }
        $candidature->setQuestionCommentaire($dto->questionCommentaire);
        $candidature->setNiveauAnglais($dto->niveauAnglais);
        $candidature->setSuccursale($dto->succursale);
        $candidature->setLienCv($dto->lienCv);
        
        $tenantEm->persist($candidature);
        $tenantEm->flush();

        return $candidature;
    }

    // Ajoutez ici les méthodes update et delete si nécessaire
}