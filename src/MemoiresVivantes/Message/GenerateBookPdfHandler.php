<?php

namespace App\MemoiresVivantes\Message;

use App\MemoiresVivantes\Entity\Book;
use App\MemoiresVivantes\Services\BookPdfGeneratorService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Worker `pdf` (lot 3, 03/11/2026) : bascule sur le site du message, rend l'intérieur et la couverture du livre avec
 * BookPdfGeneratorService (le même code que la route synchrone), puis note le résultat sur le livre : done avec le nombre de pages
 * et la date, ou failed avec un message pour l'utilisateur. Journaux sans donnée personnelle (site, identifiant du livre, pages).
 * Jamais relancé automatiquement (max_retries 0) : l'utilisateur relance depuis l'état failed.
 */
#[AsMessageHandler]
final class GenerateBookPdfHandler
{
    public function __construct(
        private readonly TenantConnectionManager $tenantManager,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly BookPdfGeneratorService $pdfService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(GenerateBookPdfMessage $message): void
    {
        $tenant = $this->tenantManager->findTenantByCode($message->tenantCode);
        if (!is_array($tenant) || empty($tenant['dbname'])) {
            $this->logger->error(sprintf('GenerateBookPdfHandler : site %s introuvable, livre %s ignoré', $message->tenantCode, $message->bookId));

            return;
        }
        $this->emProvider->switchTenant((string) $tenant['dbname'], (string) ($tenant['code'] ?? $message->tenantCode));
        $em = $this->emProvider->getEntityManager();
        $book = $em->getRepository(Book::class)->find(Uuid::fromString($message->bookId));
        if (!$book) {
            $this->logger->error(sprintf('GenerateBookPdfHandler : livre %s introuvable sur le site %s', $message->bookId, $message->tenantCode));

            return;
        }
        if ($book->getPdfStatus() === Book::PDF_STATUS_GENERATING) {
            $this->logger->warning(sprintf('GenerateBookPdfHandler : livre %s déjà en cours de mise en page, message ignoré', $message->bookId));

            return;
        }
        $book->setPdfStatus(Book::PDF_STATUS_GENERATING)->setPdfError(null);
        $em->flush();
        try {
            $result = $this->pdfService->generateAndSaveBookPdfs($book, $message->coverStyle, $message->getAuthorName(), $message->bgColor, $message->font);
            $book->setPdfStatus(Book::PDF_STATUS_DONE)
                ->setPdfPageCount((int) $result['page_count'])
                ->setPdfGeneratedAt(new \DateTimeImmutable())
                ->setPdfError(null);
            $em->flush();
            $this->logger->info(sprintf('GenerateBookPdfHandler : livre %s (site %s) mis en page, %d pages', $message->bookId, $message->tenantCode, (int) $result['page_count']));
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('GenerateBookPdfHandler : livre %s (site %s) : %s', $message->bookId, $message->tenantCode, $e->getMessage()));
            $this->markFailed($book, 'La mise en page PDF a échoué : ' . mb_substr($e->getMessage(), 0, 300));
        }
    }

    /** Échec noté par Doctrine, sinon en SQL direct (EntityManager fermé après une exception) : l'utilisateur voit toujours l'état */
    private function markFailed(Book $book, string $reason): void
    {
        try {
            $book->setPdfStatus(Book::PDF_STATUS_FAILED)->setPdfError(mb_substr($reason, 0, 1000));
            $this->emProvider->getEntityManager()->flush();
        } catch (\Throwable $flushError) {
            $this->logger->error('GenerateBookPdfHandler : échec du flush, statut écrit en SQL : ' . $flushError->getMessage());
            try {
                $this->emProvider->getEntityManager()->getConnection()->executeStatement(
                    'UPDATE mv_book SET pdf_status = ?, pdf_error = ? WHERE id = ?',
                    [Book::PDF_STATUS_FAILED, mb_substr($reason, 0, 1000), $book->getId()->toRfc4122()]
                );
            } catch (\Throwable $sqlError) {
                $this->logger->critical('GenerateBookPdfHandler : impossible de noter l\'échec : ' . $sqlError->getMessage());
            }
        }
    }
}
