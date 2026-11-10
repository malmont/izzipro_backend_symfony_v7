<?php

namespace App\Services\GestionDocumentService;

use App\Entity\AiUsage;
use App\Entity\GestionDocument;
use App\Message\ReadGestionDocumentMessage;
use App\Repository\GestionDocumentRepository;
use App\Services\BoutiqueSettingsService\TenantCurrencyProvider;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\GestionService\GestionConflictException;
use App\Services\LandingAiService\LandingAiException;
use App\Services\LandingAiService\LandingAiQuotaService;
use App\Services\TenantConnectionManager;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Documents de la gestion lus par IA (lot L1, 10/11/2026) : réception et validation des fichiers (6 images de 8 Mo ou un PDF de 20 Mo
 * et 20 pages), réservation d'un crédit IA, lecture asynchrone (worker `gestion_ai`), relecture, brouillon de vérification, photo par
 * ligne, purge paresseuse (30 jours sans confirmation ; confirmés dont la cible a disparu), lecture et suppression. La confirmation
 * est dans `UseCase/GestionUseCase/ConfirmGestionDocumentUseCase`.
 */
class GestionDocumentService
{
    public const COST = 1;
    public const MAX_IMAGES = 6;
    public const MAX_IMAGE_BYTES = 8_000_000;
    public const MAX_PDF_BYTES = 20_000_000;
    public const MAX_PDF_PAGES = 20;
    public const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
    public const RETENTION_DAYS = 30;
    public const MAX_DRAFT_BYTES = 262_144;
    public const LINE_PHOTO_MIN_N = 1;

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TenantConnectionManager $tenants,
        private readonly GestionDocumentStorage $storage,
        private readonly LandingAiQuotaService $quota,
        private readonly MessageBusInterface $bus,
        private readonly ContentAuditRecorder $audit,
        private readonly ?TenantCurrencyProvider $currency = null,
        private readonly ?\App\Services\Platform\EntitlementService $entitlements = null,
    ) {
    }

    /**
     * Palier de la lecture (règle de la plateforme, 08/11/2026) : « Aide IA » suffit (l'IA lit et propose, le gérant confirme), « IA complète »
     * l'inclut ; le propriétaire de la plateforme n'est pas soumis aux droits ; droits illisibles = refus, comme l'assistant.
     *
     * @throws LandingAiException 403 (rendu AI_NOT_INCLUDED)
     */
    public function assertAiIncluded(bool $platformOwner): void
    {
        if ($platformOwner || $this->entitlements === null) {
            return;
        }
        $code = (string) $this->tenants->getCurrentTenantCode();
        try {
            $allowed = $this->entitlements->isEnabled($code, 'ai.full') || $this->entitlements->isEnabled($code, 'ai.help');
        } catch (\Throwable) {
            $allowed = false;
        }
        if (!$allowed) {
            throw new LandingAiException(403, 'IA non incluse', 'La lecture de documents demande l\'option « Aide IA » pour ce site.');
        }
    }

    /**
     * Lectures perdues (worker arrêté, message disparu) : une lecture dont la réservation de crédit est échue passe en `failed`
     * (`AI_UNAVAILABLE`, crédit libéré) ; sans cela le site resterait bloqué sur DOCUMENT_IN_PROGRESS jusqu'à l'expiration du document.
     */
    public function releaseStaleReadings(): void
    {
        $em = $this->emProvider->getEntityManager();
        $now = new \DateTimeImmutable();
        $changed = false;
        foreach ($em->getRepository(GestionDocument::class)->findBy(['status' => GestionDocument::STATUS_READING]) as $document) {
            $usage = $document->getAiUsageId() !== null ? $em->find(AiUsage::class, $document->getAiUsageId()) : null;
            $reserved = $usage !== null && $usage->getStatus() === AiUsage::STATUS_RESERVED;
            if ($reserved && $usage->getReservedUntil() !== null && $usage->getReservedUntil() > $now) {
                continue;
            }
            if ($reserved) {
                $this->quota->release($usage, null);
            }
            $document->setStatus(GestionDocument::STATUS_FAILED)->setErrorCode('AI_UNAVAILABLE')->setReadAt($now);
            $changed = true;
        }
        if ($changed) {
            $em->flush();
        }
    }

    /**
     * @param list<UploadedFile> $files
     * @throws GestionConflictException 422 DOCUMENT_INVALID {fields}
     * @throws LandingAiException 402 crédits
     * @throws GestionConflictException 409 DOCUMENT_IN_PROGRESS
     */
    public function create(string $type, array $files, string $locale, ?string $user): GestionDocument
    {
        $this->purge();
        $errors = [];
        if (!in_array($type, GestionDocument::TYPES, true)) {
            $errors['type'] = 'INVALID_TYPE';
        }
        $pdf = [];
        $images = [];
        foreach (array_values($files) as $i => $file) {
            $mime = (string) ($file->getMimeType() ?: $file->getClientMimeType());
            if ($mime === 'application/pdf') {
                $pdf[] = $file;
            } elseif (in_array($mime, self::IMAGE_TYPES, true)) {
                $images[] = $file;
            } else {
                $errors["files[$i]"] = 'FORMAT';
            }
        }
        if ($files === []) {
            $errors['files'] = 'REQUIRED';
        } elseif ($pdf !== [] && $images !== []) {
            $errors['files'] = 'MIXED';
        } elseif (count($pdf) > 1) {
            $errors['files'] = 'TOO_MANY';
        } elseif (count($images) > self::MAX_IMAGES) {
            $errors['files'] = 'TOO_MANY';
        }
        foreach (array_values($files) as $i => $file) {
            $size = (int) $file->getSize();
            $mime = (string) ($file->getMimeType() ?: $file->getClientMimeType());
            if ($mime === 'application/pdf' && $size > self::MAX_PDF_BYTES) {
                $errors["files[$i]"] = 'TOO_LARGE';
            } elseif ($mime !== 'application/pdf' && $size > self::MAX_IMAGE_BYTES) {
                $errors["files[$i]"] = 'TOO_LARGE';
            }
        }
        $pdfPages = null;
        if ($errors === [] && $pdf !== []) {
            $pdfPages = $this->pdfPages((string) file_get_contents($pdf[0]->getPathname()));
            if ($pdfPages > self::MAX_PDF_PAGES) {
                $errors['files[0]'] = 'PDF_TOO_MANY_PAGES';
            }
        }
        if ($errors !== []) {
            throw new GestionConflictException('DOCUMENT_INVALID', 'Document invalide : ' . implode(', ', array_map(fn ($k, $v) => "$k $v", array_keys($errors), $errors)), 422, ['fields' => $errors]);
        }
        $em = $this->emProvider->getEntityManager();
        /** @var GestionDocumentRepository $repo */
        $repo = $em->getRepository(GestionDocument::class);
        if ($repo->findReading() !== null) {
            throw new GestionConflictException('DOCUMENT_IN_PROGRESS', 'Une lecture est déjà en cours sur ce site : attendez sa fin.');
        }
        $usage = $this->quota->reserve('assistant', 'gestion_document', self::COST, $user, sprintf('Lecture de document (%s, %d fichier(s))', $type, count($files)), 1800);
        $now = new \DateTimeImmutable();
        $document = (new GestionDocument())->setType($type)->setStatus(GestionDocument::STATUS_READING)->setLocale($locale)->setAiUsageId($usage->getId())
            ->setCreatedBy($user)->setCreatedAt($now)->setExpiresAt($now->modify('+' . self::RETENTION_DAYS . ' days'));
        $em->persist($document);
        $em->flush();
        $pages = [];
        foreach (array_values($files) as $i => $file) {
            $mime = (string) ($file->getMimeType() ?: $file->getClientMimeType());
            $name = mb_substr((string) $file->getClientOriginalName(), 0, 150);
            $size = (int) $file->getSize();
            $stored = $this->storage->store($document, $file, 'page-' . ($i + 1), $mime);
            $page = ['n' => $i + 1, 'name' => $name, 'mime' => $mime, 'size' => $size, 'file' => $stored];
            if ($mime === 'application/pdf') {
                $page['pages'] = $pdfPages;
            }
            $pages[] = $page;
        }
        $document->setFiles(['pages' => $pages, 'linePhotos' => []]);
        $em->flush();
        $this->audit->record('gestion-documents', $document->getId(), 'upload', null, ['type' => $type, 'files' => count($pages)]);
        $this->bus->dispatch(new ReadGestionDocumentMessage((int) $document->getId(), (string) $this->tenants->getCurrentTenantCode()));

        return $document;
    }

    /** Relecture sans renvoyer les fichiers (nouveau crédit) */
    public function reread(GestionDocument $document, ?string $user): GestionDocument
    {
        $this->releaseStaleReadings();
        $this->assertOpen($document);
        if ($document->getStatus() === GestionDocument::STATUS_READING) {
            throw new GestionConflictException('DOCUMENT_IN_PROGRESS', 'La lecture est déjà en cours.');
        }
        /** @var GestionDocumentRepository $repo */
        $repo = $this->emProvider->getEntityManager()->getRepository(GestionDocument::class);
        if ($repo->findReading() !== null) {
            throw new GestionConflictException('DOCUMENT_IN_PROGRESS', 'Une lecture est déjà en cours sur ce site : attendez sa fin.');
        }
        $usage = $this->quota->reserve('assistant', 'gestion_document', self::COST, $user, sprintf('Relecture du document %d', $document->getId()), 1800);
        $document->setStatus(GestionDocument::STATUS_READING)->setErrorCode(null)->setAiUsageId($usage->getId());
        $this->emProvider->getEntityManager()->flush();
        $this->bus->dispatch(new ReadGestionDocumentMessage((int) $document->getId(), (string) $this->tenants->getCurrentTenantCode()));

        return $document;
    }

    /** @param array<string, mixed> $draft brouillon libre de l'application, enregistré tel quel (idempotent) */
    public function saveDraft(GestionDocument $document, array $draft): GestionDocument
    {
        $this->assertOpen($document);
        if (strlen((string) json_encode($draft)) > self::MAX_DRAFT_BYTES) {
            throw new GestionConflictException('DRAFT_TOO_LARGE', 'Brouillon trop volumineux (256 Ko au plus)', 422);
        }
        if ($document->getDraft() !== $draft) {
            $document->setDraft($draft)->setDraftUpdatedAt(new \DateTimeImmutable());
            $this->emProvider->getEntityManager()->flush();
        }

        return $document;
    }

    public function storeLinePhoto(GestionDocument $document, int $n, UploadedFile $file): array
    {
        $this->assertOpen($document);
        $mime = (string) ($file->getMimeType() ?: $file->getClientMimeType());
        if (!in_array($mime, self::IMAGE_TYPES, true)) {
            throw new GestionConflictException('DOCUMENT_INVALID', 'Photo : JPEG, PNG ou WebP attendu', 422, ['fields' => ['file' => 'FORMAT']]);
        }
        if ((int) $file->getSize() > self::MAX_IMAGE_BYTES) {
            throw new GestionConflictException('DOCUMENT_INVALID', 'Photo de 8 Mo au plus', 422, ['fields' => ['file' => 'TOO_LARGE']]);
        }
        $files = $document->getFiles();
        if (isset($files['linePhotos'][(string) $n]['file'])) {
            $this->storage->removeFile($document, $files['linePhotos'][(string) $n]['file']);
        }
        $name = mb_substr((string) $file->getClientOriginalName(), 0, 150);
        $size = (int) $file->getSize(); // avant le déplacement du fichier
        $stored = $this->storage->store($document, $file, 'line-' . $n, $mime);
        $photo = ['name' => $name, 'mime' => $mime, 'size' => $size, 'file' => $stored];
        $files['linePhotos'][(string) $n] = $photo;
        $document->setFiles($files);
        $this->emProvider->getEntityManager()->flush();

        return ['n' => $n, 'photo' => ['name' => $photo['name'], 'mime' => $mime, 'size' => $photo['size']]];
    }

    public function removeLinePhoto(GestionDocument $document, int $n): void
    {
        $this->assertOpen($document);
        $files = $document->getFiles();
        if (!isset($files['linePhotos'][(string) $n])) {
            throw new GestionConflictException('PHOTO_NOT_FOUND', 'Aucune photo sur cette ligne.', 404);
        }
        $this->storage->removeFile($document, $files['linePhotos'][(string) $n]['file']);
        unset($files['linePhotos'][(string) $n]);
        $document->setFiles($files);
        $this->emProvider->getEntityManager()->flush();
    }

    /** @return array{0: string, 1: string}|null chemin et type MIME d'une page (n ≥ 1) ou d'une photo de ligne */
    public function file(GestionDocument $document, int $n, bool $linePhoto = false): ?array
    {
        $files = $document->getFiles();
        $entry = $linePhoto ? ($files['linePhotos'][(string) $n] ?? null) : null;
        if (!$linePhoto) {
            foreach ($files['pages'] ?? [] as $page) {
                if ((int) $page['n'] === $n) {
                    $entry = $page;
                }
            }
        }
        if ($entry === null) {
            return null;
        }
        $path = $this->storage->path($document, $entry['file']);

        return is_file($path) ? [$path, $entry['mime']] : null;
    }

    public function delete(GestionDocument $document): void
    {
        if ($document->isConfirmed()) {
            throw new GestionConflictException('DOCUMENT_CONFIRMED', 'Document confirmé : le justificatif suit la commande, la note ou le frais de port.');
        }
        $em = $this->emProvider->getEntityManager();
        $this->storage->removeAll($document);
        $this->audit->record('gestion-documents', $document->getId(), 'delete', ['type' => $document->getType(), 'status' => $document->getStatus()], null);
        $em->remove($document);
        $em->flush();
    }

    /** Purge paresseuse : non confirmés expirés (statut, puis suppression), confirmés sans cible ; nombre supprimé */
    public function purge(): int
    {
        $this->releaseStaleReadings();
        $em = $this->emProvider->getEntityManager();
        /** @var GestionDocumentRepository $repo */
        $repo = $em->getRepository(GestionDocument::class);
        $count = 0;
        foreach ($repo->findPurgeable(new \DateTimeImmutable()) as $document) {
            $this->storage->removeAll($document);
            $em->remove($document);
            $count++;
        }
        if ($count > 0) {
            $em->flush();
        }

        return $count;
    }

    /** @return array{0: list<GestionDocument>, 1: int} */
    public function search(?string $type, ?string $status, int $page, int $limit): array
    {
        $this->purge();
        /** @var GestionDocumentRepository $repo */
        $repo = $this->emProvider->getEntityManager()->getRepository(GestionDocument::class);

        return $repo->search($type, $status, $page, $limit);
    }

    /** Forme rendue par l'API */
    public function toArray(GestionDocument $document, bool $withDetails = true): array
    {
        $files = $document->getFiles();
        $out = [
            'id' => $document->getId(), 'type' => $document->getType(), 'status' => $document->getStatus(), 'locale' => $document->getLocale(),
            'currency' => $this->currency?->code() ?? TenantCurrencyProvider::DEFAULT,
            'createdAt' => $document->getCreatedAt()?->format(\DateTimeInterface::ATOM), 'readAt' => $document->getReadAt()?->format(\DateTimeInterface::ATOM),
            'expiresAt' => $document->isConfirmed() ? null : $document->getExpiresAt()?->format(\DateTimeInterface::ATOM),
            'confirmedAt' => $document->getConfirmedAt()?->format(\DateTimeInterface::ATOM), 'draftUpdatedAt' => $document->getDraftUpdatedAt()?->format(\DateTimeInterface::ATOM),
            'error' => $document->getErrorCode(),
            'files' => array_map(fn ($p) => ['n' => $p['n'], 'name' => $p['name'], 'mime' => $p['mime'], 'size' => $p['size'], 'pages' => $p['pages'] ?? 1], $files['pages'] ?? []),
            'commandeId' => $document->getCommandeId(), 'noteDeFraisId' => $document->getNoteDeFraisId(), 'fraisDePortId' => $document->getFraisDePortId(), 'campaignId' => $document->getCampaignId(),
        ];
        if ($withDetails) {
            $out['extraction'] = $document->getExtraction();
            $out['draft'] = $document->getDraft();
            $out['confirmed'] = $document->getConfirmed();
            $out['linePhotos'] = array_map(fn ($p) => ['name' => $p['name'], 'mime' => $p['mime'], 'size' => $p['size']], $files['linePhotos'] ?? []) ?: new \stdClass();
        }

        return $out;
    }

    /** 409 DOCUMENT_CONFIRMED / 410 DOCUMENT_EXPIRED */
    public function assertOpen(GestionDocument $document): void
    {
        if ($document->isConfirmed()) {
            throw new GestionConflictException('DOCUMENT_CONFIRMED', 'Document déjà confirmé.');
        }
        if ($document->getStatus() === GestionDocument::STATUS_EXPIRED || ($document->getExpiresAt() !== null && $document->getExpiresAt() < new \DateTimeImmutable())) {
            throw new GestionConflictException('DOCUMENT_EXPIRED', 'Document expiré (30 jours sans confirmation).', 410);
        }
    }

    /** Nombre de pages d'un PDF (comptage des objets /Type /Page ; 1 au moins) */
    public static function pdfPages(string $content): int
    {
        $count = preg_match_all('/\/Type\s*\/Page(?![s\w])/', $content);

        return max(1, (int) $count);
    }
}
