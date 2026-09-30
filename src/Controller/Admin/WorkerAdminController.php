<?php

namespace App\Controller\Admin;

use App\MemoiresVivantes\Entity\Chapter;
use App\MemoiresVivantes\Services\ChapterGenerationService;
use App\Security\RoleAssignmentPolicy;
use App\Services\TenantEntityManagerProvider;
use App\Services\Worker\WorkerMonitor;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Écran « Workers » : état des tâches de fond (rédaction des chapitres, rapports ESG, assistant IA), redémarrage des
 * workers et relance d'une rédaction de chapitre. Réservé au propriétaire de la plateforme (ROLE_SUPER_ADMIN) : les
 * workers sont communs à tous les sites. Un administrateur de site ne voit ni l'écran ni son entrée de menu.
 */
class WorkerAdminController extends AbstractController
{
    private const CSRF_ID = 'admin_workers';

    public function __construct(
        private readonly WorkerMonitor $monitor,
        private readonly ChapterGenerationService $generationService,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly AdminUrlGenerator $adminUrlGenerator,
        private readonly LoggerInterface $logger
    ) {
    }

    #[Route('/admin/workers', name: 'admin_workers', methods: ['GET'])]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted(RoleAssignmentPolicy::SUPER_ADMIN);

        $chapters = [];
        try {
            $rows = $this->emProvider->getEntityManager()->getRepository(Chapter::class)->createQueryBuilder('c')
                ->join('c.book', 'b')->addSelect('b')
                ->where('c.generationStatus IN (:inProgress)')
                ->orWhere('c.generationStatus = :failed AND c.updatedAt > :since')
                ->setParameter('inProgress', ChapterGenerationService::IN_PROGRESS)
                ->setParameter('failed', 'failed')
                ->setParameter('since', new \DateTime('-48 hours'))
                ->orderBy('c.updatedAt', 'DESC')
                ->setMaxResults(50)
                ->getQuery()->getResult();

            foreach ($rows as $chapter) {
                // Une rédaction qui n'est plus dans la file est signalée tout de suite, pas après une heure
                $this->generationService->failIfLost($chapter);
                $chapters[] = [
                    'id' => (string) $chapter->getId(),
                    'book' => $chapter->getBook()?->getTitle(),
                    'title' => $chapter->getTitle(),
                    'status' => $chapter->getGenerationStatus(),
                    'inProgress' => in_array($chapter->getGenerationStatus(), ChapterGenerationService::IN_PROGRESS, true),
                    'error' => $chapter->getGenerationError(),
                    'updatedAt' => $chapter->getUpdatedAt(),
                    'age' => $chapter->getUpdatedAt() ? WorkerMonitor::duration(time() - $chapter->getUpdatedAt()->getTimestamp()) : null,
                    'canGenerate' => $this->generationService->cannotGenerateReason($chapter) === null,
                ];
            }
        } catch (\Throwable $e) {
            // Site sans module Mémoires Vivantes (tables absentes) : l'état des workers reste consultable
            $this->logger->info('Écran Workers : chapitres non listés : ' . $e->getMessage());
        }

        return $this->render('admin/workers/index.html.twig', [
            'workers' => $this->monitor->status(),
            'failed_count' => $this->monitor->failedCount(),
            'chapters' => $chapters,
            'csrf_id' => self::CSRF_ID,
            'refreshed_at' => new \DateTimeImmutable(),
        ]);
    }

    #[Route('/admin/workers/restart', name: 'admin_workers_restart', methods: ['POST'])]
    public function restart(Request $request): Response
    {
        $this->denyAccessUnlessGranted(RoleAssignmentPolicy::SUPER_ADMIN);
        if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton de sécurité invalide. Action annulée.');

            return $this->backToList();
        }

        $this->monitor->requestRestart();
        $this->logger->info('Redémarrage des workers demandé depuis l\'administration.');
        $this->addFlash('success', 'Redémarrage demandé. Chaque worker termine son traitement en cours puis redémarre (10 à 30 secondes) ; actualisez la page pour suivre.');

        return $this->backToList();
    }

    #[Route('/admin/workers/chapters/{id}/relaunch', name: 'admin_workers_chapter_relaunch', methods: ['POST'])]
    public function relaunchChapter(string $id, Request $request): Response
    {
        $this->denyAccessUnlessGranted(RoleAssignmentPolicy::SUPER_ADMIN);
        $chapter = $this->chapterFor($id, $request);
        if ($chapter === null) {
            return $this->backToList();
        }

        $reason = $this->generationService->cannotGenerateReason($chapter);
        if ($reason !== null) {
            $this->addFlash('warning', 'Rédaction non lancée : ' . $reason);
        } elseif ($this->generationService->start($chapter, $request->getHost())) {
            $this->addFlash('success', sprintf('Rédaction du chapitre « %s » relancée.', $chapter->getTitle()));
        } else {
            $this->addFlash('danger', (string) $chapter->getGenerationError());
        }

        return $this->backToList();
    }

    #[Route('/admin/workers/chapters/{id}/fail', name: 'admin_workers_chapter_fail', methods: ['POST'])]
    public function failChapter(string $id, Request $request): Response
    {
        $this->denyAccessUnlessGranted(RoleAssignmentPolicy::SUPER_ADMIN);
        $chapter = $this->chapterFor($id, $request);
        if ($chapter === null) {
            return $this->backToList();
        }

        if (in_array($chapter->getGenerationStatus(), ChapterGenerationService::IN_PROGRESS, true)) {
            $this->generationService->markFailed($chapter, 'Rédaction arrêtée depuis l\'administration. Relancez la génération.');
            $this->addFlash('success', sprintf('Le chapitre « %s » est libéré : il peut être relancé.', $chapter->getTitle()));
        }

        return $this->backToList();
    }

    private function chapterFor(string $id, Request $request): ?Chapter
    {
        if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Jeton de sécurité invalide. Action annulée.');

            return null;
        }

        try {
            $chapter = $this->emProvider->getEntityManager()->getRepository(Chapter::class)->find(Uuid::fromString($id));
        } catch (\Throwable) {
            $chapter = null;
        }
        if ($chapter === null) {
            $this->addFlash('danger', 'Chapitre introuvable.');
        }

        return $chapter;
    }

    /** Retour à l'écran dans l'habillage EasyAdmin */
    private function backToList(): Response
    {
        return $this->redirect($this->adminUrlGenerator->unsetAll()->setRoute('admin_workers')->generateUrl());
    }
}
