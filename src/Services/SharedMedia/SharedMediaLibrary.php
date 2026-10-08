<?php

namespace App\Services\SharedMedia;

use App\Dto\MediaItemOutputDto;
use App\Entity\BoutiqueSetting;
use App\Entity\LandingPageSetting;
use App\Entity\LandingSiteModel;
use App\Entity\SharedMedia;
use App\Repository\SharedMediaRepository;
use App\Services\MediaUrlResolver;
use App\Services\TenantEntityManagerProvider;

/**
 * Médiathèque vue depuis l'éditeur des landing pages : recherche, renommage, et suppression d'un média qui ne sert
 * plus. Avant de supprimer, cherche où le média sert encore : réglages publiés, modèles de site et contenus de
 * section (images, vidéo), par sa clé (privé) ou son nom de fichier (public).
 */
final class SharedMediaLibrary
{
    public const TYPES = [SharedMedia::TYPE_IMAGE, SharedMedia::TYPE_VIDEO];
    public const MAX_USAGES = 20;
    public const TITLE_MAX_LENGTH = 255;

    /** Colonnes des contenus de section qui peuvent désigner un média : table => [libellé, colonnes] */
    private const CONTENT_COLUMNS = [
        'presentation' => ['Présentation', ['image']],
        'baniere_statique' => ['Bannière statique', ['image_de_fond']],
        'banniere' => ['Bannière', ['image_de_fond']],
        'video' => ['Vidéo', ['image_de_fond', 'lien_video']],
        'service_offer' => ['Service', ['logo', 'photo_service']],
    ];

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly MediaUrlResolver $urls,
        private readonly SharedMediaStorage $storage
    ) {
    }

    /**
     * @param list<string> $types
     * @return array{items: list<MediaItemOutputDto>, total: int}
     */
    public function search(array $types, ?string $query, int $page, int $limit, string $host): array
    {
        [$media, $total] = $this->media()->searchForEditor($types, $query, $page, $limit);

        return ['items' => array_map(fn (SharedMedia $m) => $this->item($m, $host), $media), 'total' => $total];
    }

    public function find(int $id): ?SharedMedia
    {
        $media = $this->media()->find($id);

        return $media instanceof SharedMedia && in_array($media->getMediaType(), self::TYPES, true) ? $media : null;
    }

    public function item(SharedMedia $media, string $host): MediaItemOutputDto
    {
        return MediaItemOutputDto::fromEntity($media, $this->urls->resolveSharedMediaUrl($media, $host), $this->dimensions($media));
    }

    public function rename(SharedMedia $media, string $title): void
    {
        $media->setTitre($title)->setUpdatedAt(new \DateTimeImmutable());
        $this->emProvider->getEntityManager()->flush();
    }

    /** @return list<string> endroits où le média sert encore (MAX_USAGES au plus) */
    public function usages(SharedMedia $media): array
    {
        $needle = $media->isPrivate() && $media->getAccessKey() ? $media->getAccessKey() : basename((string) $media->getFilename());
        if (strlen($needle) < 12) {
            return [];
        }
        $connection = $this->emProvider->getEntityManager()->getConnection();
        $usages = [];

        foreach ([LandingPageSetting::class => 'Réglages publiés : ', BoutiqueSetting::class => 'Réglages publiés de la boutique : '] as $class => $label) {
            $settings = $this->emProvider->getEntityManager()->getClassMetadata($class)->getTableName();
            foreach ($connection->fetchFirstColumn("SELECT configuration::text FROM $settings") as $json) {
                foreach ($this->paths(json_decode((string) $json, false), $needle) as $path) {
                    $usages[] = $label . $path;
                }
            }
        }
        $models = $this->emProvider->getEntityManager()->getClassMetadata(LandingSiteModel::class)->getTableName();
        foreach ($connection->fetchAllAssociative("SELECT id, app, name, configuration FROM $models WHERE configuration LIKE ?", ['%' . $needle . '%']) as $row) {
            foreach ($this->paths(json_decode($row['configuration'], false), $needle) as $path) {
                $usages[] = sprintf('Modèle de site%s « %s » : %s', $row['app'] === LandingSiteModel::APP_BOUTIQUE ? ' de la boutique' : '', $row['name'], $path);
            }
        }
        foreach (self::CONTENT_COLUMNS as $table => [$label, $columns]) {
            foreach ($columns as $column) {
                foreach ($connection->fetchAllAssociative("SELECT id, titre FROM $table WHERE $column LIKE ?", ['%' . $needle . '%']) as $row) {
                    $usages[] = sprintf('%s n° %d « %s » : %s', $label, $row['id'], mb_substr(strip_tags((string) $row['titre']), 0, 60), lcfirst(str_replace('_', '', ucwords($column, '_'))));
                }
            }
        }

        return array_slice(array_values(array_unique($usages)), 0, self::MAX_USAGES);
    }

    public function delete(SharedMedia $media): void
    {
        $this->storage->remove($media);
        $em = $this->emProvider->getEntityManager();
        $em->remove($media);
        $em->flush();
    }

    /**
     * Chemins des chaînes qui contiennent $needle, avec le nom de l'onglet pour se repérer
     *
     * @return list<string>
     */
    private function paths(mixed $node, string $needle, string $path = '', string $tab = ''): array
    {
        if (is_string($node)) {
            return str_contains($node, $needle) ? [ltrim($path, '.') . ($tab !== '' ? " (onglet « $tab »)" : '')] : [];
        }
        $found = [];
        if (is_object($node) || is_array($node)) {
            foreach ($node as $key => $value) {
                $childTab = $tab;
                if (preg_match('/^\.tabs\[\d+\]$/', $path . (is_int($key) ? "[$key]" : ".$key")) && is_object($value) && is_string($value->name ?? null)) {
                    $childTab = $value->name;
                }
                array_push($found, ...$this->paths($value, $needle, $path . (is_int($key) ? "[$key]" : ".$key"), $childTab));
            }
        }

        return $found;
    }

    /** @return array{0: ?int, 1: ?int} largeur et hauteur d'une image (lecture de l'en-tête du fichier seulement) */
    private function dimensions(SharedMedia $media): array
    {
        if (!$media->isImage()) {
            return [null, null];
        }
        foreach ($this->storage->directories() as $dir) {
            $file = $dir . '/' . basename((string) $media->getFilename());
            if (is_file($file) && ($size = @getimagesize($file)) !== false) {
                return [(int) $size[0], (int) $size[1]];
            }
        }

        return [null, null];
    }

    private function media(): SharedMediaRepository
    {
        return $this->emProvider->getEntityManager()->getRepository(SharedMedia::class);
    }
}
