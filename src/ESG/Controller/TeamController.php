<?php

namespace App\ESG\Controller;

use App\Entity\User;
use App\ESG\Security\Voter\BoussoleTeamVoter;
use App\ESG\UseCase\Team\DownloadTeamDocumentUseCase;
use App\ESG\UseCase\Team\GetTeamCompanyUseCase;
use App\ESG\UseCase\Team\ListTeamCompaniesUseCase;
use App\ESG\UseCase\Team\ListTeamMembersUseCase;
use App\ESG\UseCase\Team\SetAdvisorUseCase;
use App\Security\RoleAssignmentPolicy;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Équipe de la Boussole ESG (lot BOUSSOLE-CONSEILLER, 13/11/2026) : le conseiller (`ROLE_BOUSSOLE_CONSEILLER`), l'administrateur du site
 * et le super administrateur lisent toutes les entreprises du site, sans assignation. Lecture seule : l'équipe ne crée, ne modifie et ne
 * soumet rien. Le diagnostic et le rapport se lisent par les routes existantes `sessions/{uuid}…`, que la règle des sessions ouvre à
 * l'équipe en lecture. Contrat : docs/boussole-esg.md, « Équipe ».
 */
#[Route('/api/boussole/team')]
#[IsGranted(BoussoleTeamVoter::ATTRIBUTE)]
final class TeamController extends AbstractController
{
    /** → [{id, fullName, email, teamRoles}] : les conseillers, par nom */
    #[Route('/members', name: 'esg_team_members', methods: ['GET'])]
    public function members(ListTeamMembersUseCase $useCase): JsonResponse
    {
        return $this->json($useCase->execute());
    }

    /**
     * Pose ou retire le rôle de conseiller sur un compte du site ; corps {member: true|false} ; idempotent ; journal content_audit_log
     * (user_team, grant | revoke). → {id, isAdvisor, teamRoles, roles, changed}. 400 INVALID_BODY, 403 OWNER_PROTECTED, 404 USER_NOT_FOUND.
     */
    #[Route('/members/{userId}', name: 'esg_team_member_set', methods: ['PUT'], requirements: ['userId' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function setMember(int $userId, Request $request, SetAdvisorUseCase $useCase, TenantEntityManagerProvider $emProvider): JsonResponse
    {
        $target = $emProvider->getEntityManager()->getRepository(User::class)->find($userId);
        if (!$target instanceof User) {
            return $this->json(['error' => 'Utilisateur introuvable.', 'code' => 'USER_NOT_FOUND'], Response::HTTP_NOT_FOUND);
        }
        if (in_array(RoleAssignmentPolicy::SUPER_ADMIN, $target->getRoles(), true) && !$this->isGranted(RoleAssignmentPolicy::SUPER_ADMIN)) {
            return $this->json(['error' => 'Compte du propriétaire de la plateforme : modification réservée.', 'code' => 'OWNER_PROTECTED'], Response::HTTP_FORBIDDEN);
        }
        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !array_key_exists('member', $data) || !is_bool($data['member'])) {
            return $this->json(['error' => 'Corps attendu : {"member": true} ou {"member": false}.', 'code' => 'INVALID_BODY'], Response::HTTP_BAD_REQUEST);
        }

        return $this->json($useCase->execute($target, $data['member']));
    }

    /** → {total, page, limit, companies: [fiche + isActive, createdAt, usersCount, sessionsCount, documentsCount, lastSession]} ; ?q=&page=&limit= (50 par défaut, 200 au plus) */
    #[Route('/companies', name: 'esg_team_companies', methods: ['GET'])]
    public function companies(Request $request, ListTeamCompaniesUseCase $useCase): JsonResponse
    {
        return $this->json($useCase->execute((string) $request->query->get('q', ''), $request->query->getInt('page', 1), $request->query->getInt('limit', ListTeamCompaniesUseCase::DEFAULT_LIMIT)));
    }

    /** → {company, users, sessions, documents} ; 404 COMPANY_NOT_FOUND ; lecture inscrite au journal des consultations */
    #[Route('/companies/{id}', name: 'esg_team_company', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function company(int $id, GetTeamCompanyUseCase $useCase): JsonResponse
    {
        $detail = $useCase->execute($id);
        if ($detail === null) {
            return $this->json(['error' => 'Entreprise introuvable.', 'code' => 'COMPANY_NOT_FOUND'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($detail);
    }

    /** Le fichier d'un document d'une entreprise ; 404 DOCUMENT_NOT_FOUND ; téléchargement inscrit au journal des consultations */
    #[Route('/documents/{id}/download', name: 'esg_team_document_download', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function downloadDocument(int $id, DownloadTeamDocumentUseCase $useCase): Response
    {
        try {
            return $useCase->execute($id);
        } catch (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e) {
            return $this->json(['error' => $e->getMessage(), 'code' => 'DOCUMENT_NOT_FOUND'], Response::HTTP_NOT_FOUND);
        } catch (\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException $e) {
            return $this->json(['error' => $e->getMessage(), 'code' => 'FORBIDDEN'], Response::HTTP_FORBIDDEN);
        }
    }
}
