<?php

namespace App\UseCase\MediaUseCase;

use App\Dto\MediaUploadOutputDto;
use App\Entity\SharedMedia;
use App\Services\SharedMedia\ScrollVideoPreparer;
use App\Services\SharedMedia\SharedMediaStorage;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * POST /api/media : téléversement d'une image ou d'une vidéo depuis l'éditeur des landing pages. Le média est rangé
 * dans la médiathèque du site, en privé (servi par sa clé, comme les médias de l'administration). Option : vidéo
 * préparée pour une scène au défilement (tâche de fond, même clé).
 */
class UploadMediaUseCase
{
    public const TYPES = [SharedMedia::TYPE_IMAGE, SharedMedia::TYPE_VIDEO];
    public const MAX_TITLE_LENGTH = 255;

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly SharedMediaStorage $storage,
        private readonly ScrollVideoPreparer $scrollVideo
    ) {
    }

    /**
     * @throws HttpException 400 (fichier absent ou invalide), 413 (trop gros), 415 (format refusé)
     */
    public function execute(mixed $file, ?string $title, bool $prepareForScroll, string $host): MediaUploadOutputDto
    {
        if (!$file instanceof UploadedFile) {
            throw new HttpException(400, 'Fichier attendu dans le champ « file » (multipart/form-data).');
        }
        if (!$file->isValid()) {
            throw new HttpException(in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 413 : 400, 'Téléversement incomplet ou refusé : ' . $file->getErrorMessage());
        }
        if ($file->getSize() > SharedMediaStorage::MAX_SIZE) {
            throw new HttpException(413, sprintf('Fichier trop volumineux : %d Mo au plus.', SharedMediaStorage::MAX_SIZE / 1048576));
        }
        $extension = strtolower($file->getClientOriginalExtension() ?: (string) $file->guessExtension());
        if (!in_array(SharedMediaStorage::mediaType($extension, $file->getMimeType()), self::TYPES, true)) {
            throw new HttpException(415, 'Seules les images et les vidéos sont acceptées ici.');
        }

        $media = (new SharedMedia())
            ->setTitre(mb_substr(trim((string) $title) !== '' ? trim((string) $title) : pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME), 0, self::MAX_TITLE_LENGTH))
            ->setVisibility(SharedMedia::VISIBILITY_PRIVATE)
            ->setDescription('Téléversé depuis l\'éditeur des landing pages');
        try {
            $this->storage->store($media, $file);
        } catch (\InvalidArgumentException $e) {
            throw new HttpException(415, $e->getMessage());
        }

        $em = $this->emProvider->getEntityManager();
        $em->persist($media);
        $em->flush();
        if ($prepareForScroll && $media->isVideo()) {
            $this->scrollVideo->request($media);
        }

        return MediaUploadOutputDto::fromEntity($media, $host);
    }
}
