<?php

namespace App\UseCase\MediaUseCase;

use App\Dto\MediaItemOutputDto;
use App\Services\ContentAuditService\ContentAuditRecorder;
use App\Services\SharedMedia\SharedMediaLibrary;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * PATCH /api/media/{id} (renommer) et DELETE /api/media/{id} (supprimer un média qui ne sert plus) depuis l'éditeur
 * des landing pages. Seules les images et les vidéos du site sont concernées (404 sinon).
 */
class ManageMediaUseCase
{
    public function __construct(private readonly SharedMediaLibrary $library, private readonly ContentAuditRecorder $audit)
    {
    }

    /** @throws HttpException 400, 404 ou 422 */
    public function rename(int $id, mixed $body, string $host): MediaItemOutputDto
    {
        $media = $this->library->find($id) ?? throw new HttpException(404, 'Média introuvable sur ce site.');
        if (!is_object($body) || array_keys(get_object_vars($body)) !== ['title']) {
            throw new HttpException(400, 'Objet JSON attendu avec le seul champ title.');
        }
        $title = is_string($body->title) ? trim($body->title) : '';
        if ($title === '' || mb_strlen($title) > SharedMediaLibrary::TITLE_MAX_LENGTH || preg_match('/[<>]/', $title)) {
            throw new HttpException(422, sprintf('title : texte de 1 à %d caractères, sans < ni >.', SharedMediaLibrary::TITLE_MAX_LENGTH));
        }
        $before = (string) $media->getTitre();
        $this->library->rename($media, $title);
        $this->audit->record('media', $id, 'rename', ['title' => $before], ['title' => $title], ['title']);

        return $this->library->item($media, $host);
    }

    /**
     * @return list<string> endroits où le média sert encore (vide : supprimé)
     * @throws HttpException 404
     */
    public function delete(int $id): array
    {
        $media = $this->library->find($id) ?? throw new HttpException(404, 'Média introuvable sur ce site.');
        $usages = $this->library->usages($media);
        if ($usages === []) {
            $before = ['title' => $media->getTitre(), 'key' => $media->getAccessKey(), 'type' => $media->getMediaType(), 'filename' => $media->getOriginalFilename()];
            $this->library->delete($media);
            $this->audit->record('media', $id, 'delete', $before, null);
        }

        return $usages;
    }
}
