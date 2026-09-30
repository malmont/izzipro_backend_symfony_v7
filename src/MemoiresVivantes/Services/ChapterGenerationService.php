<?php

namespace App\MemoiresVivantes\Services;

use App\MemoiresVivantes\BookType\BookTypeResolver;
use App\MemoiresVivantes\BookType\DatabasePromptEngine;
use App\MemoiresVivantes\Entity\Chapter;
use App\MemoiresVivantes\Message\GenerateChapterMessage;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Services\Worker\WorkerMonitor;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Lancement et suivi de la rédaction d'un chapitre par l'IA (tâche de fond, worker « async »).
 *
 * Trois garanties :
 * - rien n'est envoyé à l'IA sans matériau (réponses ou témoignages) : elle rédigerait un refus ou inventerait ;
 * - une rédaction déjà en cours n'est pas lancée une seconde fois (double clic : deux rédactions payées) ;
 * - une rédaction perdue (message effacé, worker arrêté) est détectée et passe en échec, pour pouvoir la relancer
 *   au lieu d'attendre indéfiniment.
 */
class ChapterGenerationService
{
    public const IN_PROGRESS = ['pending', 'generating_part1', 'part1_done', 'generating_part2'];

    /** Transport Messenger des chapitres (config/packages/messenger.yaml) */
    private const TRANSPORT = 'async';

    /** Délai de grâce avant de conclure, file vide et rien en cours, que la rédaction est perdue */
    private const LOST_SECONDS = 120;

    /**
     * Minutes sans progression quand l'état de la file est inconnu ou qu'elle n'est pas vide. Seuils larges : avec un
     * seul worker, un chapitre peut attendre son tour derrière ceux d'un livre entier.
     */
    private const STALE_MINUTES = [
        'pending' => 60,
        'part1_done' => 60,
        'generating_part1' => 30,
        'generating_part2' => 30,
    ];

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TenantConnectionManager $tenantManager,
        private readonly MessageBusInterface $messageBus,
        private readonly WorkerMonitor $workerMonitor,
        private readonly HommageAggregationService $hommageAggregationService,
        private readonly FamilleAggregationService $familleAggregationService,
        private readonly BookTypeResolver $bookTypeResolver,
        private readonly DatabasePromptEngine $promptEngine,
        private readonly LoggerInterface $logger
    ) {
    }

    /** Le chapitre a-t-il au moins une réponse ou un témoignage rédigé (questions passées exclues) ? */
    public function hasMaterial(Chapter $chapter): bool
    {
        $entries = $chapter->getAnswers();
        foreach ($chapter->getContributorAnswers() ?? [] as $contributor) {
            if (is_array($contributor) && is_array($contributor['answers'] ?? null)) {
                $entries = array_merge($entries, $contributor['answers']);
            }
        }

        foreach ($entries as $entry) {
            if (is_string($entry) && trim($entry) !== '') {
                return true;
            }
            if (is_array($entry) && empty($entry['skipped']) && ChapterQuestionProvider::answerText($entry) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Raison pour laquelle le chapitre ne peut pas être rédigé (message destiné à l'utilisateur), null s'il peut l'être.
     * Un chapitre de synthèse se nourrit des témoignages de tout le livre ; les autres, de leurs propres réponses.
     */
    public function cannotGenerateReason(Chapter $chapter): ?string
    {
        $book = $chapter->getBook();
        $theme = $chapter->getTheme();

        $databaseType = $this->bookTypeResolver->findDatabasePromptType($book);
        if ($databaseType !== null) {
            if ($databaseType->getChapter((string) $theme)?->isSynthesis()) {
                $check = $this->promptEngine->checkCanGenerate($chapter, $databaseType);

                return $check['canGenerate'] ? null : $check['reason'];
            }
        } elseif ($book?->getType() === 'hommage' && in_array($theme, ['portrait_croise', 'une_vie'], true)) {
            $check = $this->hommageAggregationService->checkCanGenerateSynthesis($chapter);

            return $check['canGenerate'] ? null : ($check['reason'] ?? 'Les témoignages préalables sont requis pour ce chapitre de synthèse.');
        } elseif ($book?->getType() === 'famille' && in_array($theme, ['histoire_parents', 'histoire_aine'], true)) {
            $check = $this->familleAggregationService->checkCanGenerateSynthesis($chapter);

            return $check['canGenerate'] ? null : ($check['reason'] ?? 'Les témoignages des proches ou des parents sont requis avant de pouvoir générer ce chapitre.');
        }

        return $this->hasMaterial($chapter)
            ? null
            : 'Répondez à au moins une question de ce chapitre avant de lancer la rédaction.';
    }

    /** Une rédaction est-elle réellement en cours (une rédaction perdue est d'abord passée en échec) ? */
    public function isInProgress(Chapter $chapter): bool
    {
        $this->failIfLost($chapter);

        return in_array($chapter->getGenerationStatus(), self::IN_PROGRESS, true);
    }

    /**
     * Passe en échec une rédaction qui ne peut plus aboutir : file vide et aucun message en cours de traitement, ou
     * aucune progression depuis longtemps. Si le message finit malgré tout par être traité, le handler réécrit le statut.
     */
    public function failIfLost(Chapter $chapter): bool
    {
        $status = $chapter->getGenerationStatus();
        if (!in_array($status, self::IN_PROGRESS, true) || $chapter->getUpdatedAt() === null) {
            return false;
        }

        $age = time() - $chapter->getUpdatedAt()->getTimestamp();
        if ($age < self::LOST_SECONDS) {
            return false;
        }

        if ($this->workerMonitor->isQueueEmpty(self::TRANSPORT) === true) {
            $reason = 'La rédaction a été interrompue (elle n\'est plus dans la file de traitement). Relancez la génération.';
        } elseif ($age > (self::STALE_MINUTES[$status] ?? 60) * 60) {
            $reason = sprintf('La rédaction a été interrompue (aucune progression depuis %d minutes). Relancez la génération.', intdiv($age, 60));
        } else {
            return false;
        }

        $this->logger->warning("Chapter {$chapter->getId()} : rédaction perdue (statut {$status}, {$age} s sans progression), passage en échec");
        $this->markFailed($chapter, $reason);

        return true;
    }

    /**
     * Met le chapitre en file pour rédaction. Ne vérifie ni le matériau ni une rédaction en cours : à l'appelant de le
     * faire (cannotGenerateReason, isInProgress).
     *
     * @return bool false si la file de traitement est indisponible (le chapitre passe alors en échec)
     */
    public function start(Chapter $chapter, string $tenantHost, string $tone = 'intime et chaleureux', ?string $model = null): bool
    {
        $em = $this->emProvider->getEntityManager();
        $chapter->setGenerationStatus('pending');
        $chapter->setGenerationError(null);
        // Statut parfois inchangé (relance) : la date sert de point de départ à la détection des rédactions perdues
        $chapter->updateTimestamps();
        $em->flush();

        try {
            // Le tenant déjà résolu par la requête est plus sûr que le nom d'hôte
            $tenant = $this->tenantManager->getCurrentTenantCode() ?: $tenantHost;
            $this->messageBus->dispatch(new GenerateChapterMessage((string) $chapter->getId(), 1, $tenant, $tone, $model));
        } catch (\Throwable $e) {
            $this->logger->error("Chapter {$chapter->getId()} : mise en file impossible : " . $e->getMessage());
            $this->markFailed($chapter, 'La file de traitement est momentanément indisponible. Réessayez dans un instant.');

            return false;
        }

        return true;
    }

    public function markFailed(Chapter $chapter, string $reason): void
    {
        $chapter->setGenerationStatus('failed');
        $chapter->setGenerationError($reason);
        $this->emProvider->getEntityManager()->flush();
    }
}
