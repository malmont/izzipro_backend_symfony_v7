<?php

namespace App\MemoiresVivantes\Services;

use App\MemoiresVivantes\Entity\Book;
use App\MemoiresVivantes\Message\GenerateBookPdfMessage;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use App\Services\Worker\WorkerMonitor;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Mise en page PDF d'un livre en tâche de fond (lot 3, 03/11/2026), sur le modèle de ChapterGenerationService : mise en file
 * (`start`), état lisible par les clients (`status`), détection d'une génération perdue (`failIfLost`), échec noté en base
 * (`markFailed`). Le rendu lui-même reste dans BookPdfGeneratorService, appelé par GenerateBookPdfHandler dans le worker `pdf`.
 * Les droits sur le livre sont vérifiés dans la requête (BookAccessGuard) : ici, seul le travail.
 */
class BookPdfGenerationService
{
    public const IN_PROGRESS = [Book::PDF_STATUS_PENDING, Book::PDF_STATUS_GENERATING];
    public const TRANSPORT = 'pdf';
    /** Délai de grâce avant de conclure, file vide, que la génération est perdue (le worker peut la prendre dans la seconde) */
    private const LOST_SECONDS = 60;
    /** Au-delà, sans progression, la génération est considérée perdue même si la file n'est pas lisible (un PDF prend 10 à 20 s) */
    private const STALE_MINUTES = [Book::PDF_STATUS_PENDING => 15, Book::PDF_STATUS_GENERATING => 10];
    public const LOST_MESSAGE = 'La mise en page PDF a été interrompue. Relancez la génération.';

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TenantConnectionManager $tenantManager,
        private readonly MessageBusInterface $messageBus,
        private readonly WorkerMonitor $workerMonitor,
        private readonly BookPdfGeneratorService $pdfService,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
    }

    public function isInProgress(Book $book): bool
    {
        return in_array($book->getPdfStatus(), self::IN_PROGRESS, true);
    }

    /**
     * Met le livre en attente et expédie le message ; false si une génération est déjà en cours (aucun second message) ou si la
     * file est indisponible (le livre passe alors en échec, avec un message pour l'utilisateur).
     * @return array{queued: bool, alreadyInProgress: bool}
     */
    public function start(Book $book, string $coverStyle, ?string $authorName, ?string $bgColor, ?string $font): array
    {
        $this->failIfLost($book);
        if ($this->isInProgress($book)) {
            return ['queued' => false, 'alreadyInProgress' => true];
        }
        $em = $this->emProvider->getEntityManager();
        $book->setPdfStatus(Book::PDF_STATUS_PENDING)->setPdfRequestedAt(new \DateTimeImmutable())->setPdfError(null);
        $em->flush();
        try {
            $tenant = $this->tenantManager->getCurrentTenantCode();
            if ($tenant === null || $tenant === '') {
                throw new \RuntimeException('site courant inconnu');
            }
            $this->messageBus->dispatch(new GenerateBookPdfMessage($book->getId()->toRfc4122(), $tenant, $coverStyle, $bgColor, $font, $authorName));
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('Livre %s : mise en file de la mise en page PDF impossible : %s', $book->getId(), $e->getMessage()));
            $this->markFailed($book, 'La file de traitement est momentanément indisponible. Réessayez dans un instant.');

            return ['queued' => false, 'alreadyInProgress' => false];
        }

        return ['queued' => true, 'alreadyInProgress' => false];
    }

    /** Génération perdue (file vide après le délai de grâce, ou aucune progression depuis trop longtemps) : passage en échec */
    public function failIfLost(Book $book): bool
    {
        $status = $book->getPdfStatus();
        $since = $book->getPdfRequestedAt();
        if (!in_array($status, self::IN_PROGRESS, true) || $since === null) {
            return false;
        }
        $age = time() - $since->getTimestamp();
        if ($age < self::LOST_SECONDS) {
            return false;
        }
        if ($this->workerMonitor->isQueueEmpty(self::TRANSPORT) === true) {
            $reason = self::LOST_MESSAGE;
        } elseif ($age > (self::STALE_MINUTES[$status] ?? 15) * 60) {
            $reason = sprintf('La mise en page PDF a été interrompue (aucune progression depuis %d minutes). Relancez la génération.', intdiv($age, 60));
        } else {
            return false;
        }
        $this->logger->warning(sprintf('Livre %s : mise en page PDF perdue (statut %s, %d s), passage en échec', $book->getId(), $status, $age));
        $this->markFailed($book, $reason);

        return true;
    }

    public function markFailed(Book $book, string $reason): void
    {
        $book->setPdfStatus(Book::PDF_STATUS_FAILED)->setPdfError(mb_substr($reason, 0, 1000));
        $this->emProvider->getEntityManager()->flush();
    }

    /** Les deux fichiers du livre existent sur le disque */
    public function filesReady(Book $book): bool
    {
        $dir = $this->bookDir($book);

        return is_file($dir . '/interior.pdf') && is_file($dir . '/cover.pdf');
    }

    /**
     * État pour les clients (route pdf/status et réponse 202) : `status` vaut pending | generating | done | failed | idle (jamais
     * demandé). Les URL ne sont données que si les fichiers sont réellement prêts ; page_count et cover_geometry seulement s'ils sont
     * connus (aucune valeur inventée). Un livre généré avant ce lot (fichiers présents, aucun suivi) est `done`.
     * @return array<string, mixed>
     */
    public function status(Book $book, bool $alreadyInProgress = false): array
    {
        $this->failIfLost($book);
        $status = $book->getPdfStatus();
        $filesReady = $this->filesReady($book);
        if ($status === null && $filesReady) {
            $status = Book::PDF_STATUS_DONE;
        }
        $ready = $status === Book::PDF_STATUS_DONE && $filesReady;
        $pageCount = $book->getPdfPageCount();
        $id = $book->getId()->toRfc4122();

        return [
            'book_id' => $id,
            'status' => $status ?? 'idle',
            'pdf_status' => $book->getPdfStatus(),
            'ready' => $ready,
            'alreadyInProgress' => $alreadyInProgress,
            'interior_url' => $ready ? '/uploads/memoires/books/' . $id . '/interior.pdf' : null,
            'cover_url' => $ready ? '/uploads/memoires/books/' . $id . '/cover.pdf' : null,
            'page_count' => $ready && $pageCount !== null ? $pageCount : null,
            'cover_geometry' => $ready && $pageCount !== null ? $this->pdfService->coverGeometry($pageCount) : null,
            'requested_at' => $book->getPdfRequestedAt()?->format(\DateTimeInterface::ATOM),
            'generated_at' => $book->getPdfGeneratedAt()?->format(\DateTimeInterface::ATOM),
            'error' => $status === Book::PDF_STATUS_FAILED ? $book->getPdfError() : null,
        ];
    }

    private function bookDir(Book $book): string
    {
        return $this->projectDir . '/var/storage/public_bucket/uploads/memoires/books/' . $book->getId()->toRfc4122();
    }
}
