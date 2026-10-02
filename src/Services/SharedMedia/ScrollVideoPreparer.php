<?php

namespace App\Services\SharedMedia;

use App\Entity\SharedMedia;
use App\Message\PrepareScrollVideoMessage;
use App\Services\TenantConnectionProvider;
use App\Services\TenantEntityManagerProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Préparation d'une vidéo de la médiathèque pour une scène au défilement.
 *
 * request() : demande faite depuis l'administration (état « pending », message envoyé au worker « media »).
 * run() : exécutée par le worker : réencodage dans un fichier voisin, puis bascule. Le média garde sa clé et son
 * adresse ; « filename » désigne le fichier préparé et « sourceFilename » le fichier d'origine, conservé.
 * restore() : retour au fichier d'origine.
 */
final class ScrollVideoPreparer
{
    public const SUFFIX = '_scroll';

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TenantConnectionProvider $tenantProvider,
        private readonly ScrollVideoEncoderInterface $encoder,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir
    ) {
    }

    /** Une vidéo déjà enregistrée, pas encore préparée ni en cours de préparation */
    public function canRequest(SharedMedia $media): bool
    {
        return $media->getId() !== null && $media->isVideo() && $media->getFilename() !== null
            && !in_array($media->getScrollStatus(), [SharedMedia::SCROLL_PENDING, SharedMedia::SCROLL_PROCESSING, SharedMedia::SCROLL_DONE, SharedMedia::SCROLL_TOO_LONG], true);
    }

    public function request(SharedMedia $media): bool
    {
        $tenant = (string) ($this->tenantProvider->getTenantCode() ?? '');
        if (!$this->canRequest($media) || $tenant === '') {
            return false;
        }
        $media->setScrollStatus(SharedMedia::SCROLL_PENDING);
        $this->emProvider->getEntityManager()->flush();
        $this->bus->dispatch(new PrepareScrollVideoMessage((int) $media->getId(), $tenant));

        return true;
    }

    /** Traitement par le worker (tenant déjà sélectionné) */
    public function run(int $mediaId): void
    {
        $em = $this->emProvider->getEntityManager();
        $media = $em->getRepository(SharedMedia::class)->find($mediaId);
        if (!$media instanceof SharedMedia || $media->getScrollStatus() !== SharedMedia::SCROLL_PENDING) {
            $this->logger->info('Médiathèque : préparation ignorée (média supprimé ou déjà traité)', ['media' => $mediaId]);

            return;
        }
        $source = basename((string) $media->getFilename());
        $dir = $this->directoryOf($source);
        if ($dir === null) {
            $this->fail($media, 'fichier introuvable');

            return;
        }

        $media->setScrollStatus(SharedMedia::SCROLL_PROCESSING);
        $em->flush();

        $target = pathinfo($source, PATHINFO_FILENAME) . self::SUFFIX . '.mp4';
        try {
            $this->encoder->encode($dir . '/' . $source, $dir . '/' . $target);
        } catch (\Throwable $e) {
            @unlink($dir . '/' . $target);
            $this->fail($media, $e->getMessage(), $e instanceof ScrollVideoTooLongException ? SharedMedia::SCROLL_TOO_LONG : SharedMedia::SCROLL_FAILED);

            return;
        }

        // Le média a pu changer pendant l'encodage (fichier remplacé, visibilité modifiée, suppression)
        $em->refresh($media);
        if ($media->getScrollStatus() !== SharedMedia::SCROLL_PROCESSING || basename((string) $media->getFilename()) !== $source || !is_file($dir . '/' . $source)) {
            @unlink($dir . '/' . $target);
            $this->logger->info('Médiathèque : préparation abandonnée, le média a changé pendant l\'encodage', ['media' => $mediaId]);

            return;
        }

        clearstatcache(true, $dir . '/' . $target);
        $media->setSourceFilename($source)
            ->setFilename($target)
            ->setMimeType('video/mp4')
            ->setFileSize((int) filesize($dir . '/' . $target))
            ->setScrollStatus(SharedMedia::SCROLL_DONE)
            ->setUpdatedAt(new \DateTimeImmutable());
        $em->flush();
        $this->logger->info('Médiathèque : vidéo préparée pour le défilement', ['media' => $mediaId, 'octets' => $media->getFileSize()]);
    }

    /** Retour au fichier d'origine (le fichier préparé est supprimé) */
    public function restore(SharedMedia $media): bool
    {
        $source = basename((string) $media->getSourceFilename());
        $prepared = basename((string) $media->getFilename());
        $dir = $source !== '' ? $this->directoryOf($source) : null;
        if ($media->getScrollStatus() !== SharedMedia::SCROLL_DONE || $dir === null) {
            return false;
        }
        clearstatcache(true, $dir . '/' . $source);
        $media->setFilename($source)
            ->setSourceFilename(null)
            ->setMimeType(SharedMediaTypes::servedMimeType($source))
            ->setFileSize((int) filesize($dir . '/' . $source))
            ->setScrollStatus(null)
            ->setUpdatedAt(new \DateTimeImmutable());
        $this->emProvider->getEntityManager()->flush();
        if ($prepared !== '' && $prepared !== $source) {
            @unlink($dir . '/' . $prepared);
        }

        return true;
    }

    /** @return list<string> dossiers de la médiathèque (public, privé) */
    public function directories(): array
    {
        $base = rtrim($this->projectDir, '/');

        return [$base . '/var/storage/public_bucket/assets/uploads/shared', $base . '/var/storage/private_media'];
    }

    private function directoryOf(string $filename): ?string
    {
        foreach ($this->directories() as $dir) {
            if ($filename !== '' && is_file($dir . '/' . $filename)) {
                return $dir;
            }
        }

        return null;
    }

    private function fail(SharedMedia $media, string $reason, string $status = SharedMedia::SCROLL_FAILED): void
    {
        $media->setScrollStatus($status);
        $this->emProvider->getEntityManager()->flush();
        $this->logger->error('Médiathèque : préparation pour le défilement en échec', ['media' => $media->getId(), 'raison' => mb_substr($reason, 0, 500)]);
    }
}
