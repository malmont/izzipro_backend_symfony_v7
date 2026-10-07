<?php

namespace App\UseCase\MediaUseCase;

use App\Entity\SharedMedia;
use App\Services\SharedMedia\SharedMediaLibrary;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * GET /api/media : images et vidéos de la médiathèque du site, pour le sélecteur de l'éditeur des landing pages,
 * du plus récent au plus ancien, par pages.
 */
class ListMediaUseCase
{
    public const DEFAULT_LIMIT = 40;
    public const MAX_LIMIT = 100;
    public const MAX_QUERY_LENGTH = 100;

    public function __construct(private readonly SharedMediaLibrary $library)
    {
    }

    /**
     * @throws HttpException 400 si un paramètre est invalide
     */
    public function execute(?string $type, ?string $page, ?string $limit, ?string $query, string $host): array
    {
        if ($type !== null && $type !== '' && !in_array($type, SharedMediaLibrary::TYPES, true)) {
            throw new HttpException(400, 'type invalide : image ou video attendu.');
        }
        $pageNumber = self::integer($page, 1, 1, 100000, 'page');
        $size = self::integer($limit, self::DEFAULT_LIMIT, 1, self::MAX_LIMIT, 'limit');
        $query = $query !== null ? trim($query) : null;
        if ($query !== null && mb_strlen($query) > self::MAX_QUERY_LENGTH) {
            throw new HttpException(400, sprintf('q : %d caractères au plus.', self::MAX_QUERY_LENGTH));
        }
        $types = $type !== null && $type !== '' ? [$type] : [SharedMedia::TYPE_IMAGE, SharedMedia::TYPE_VIDEO];
        $result = $this->library->search($types, $query, $pageNumber, $size, $host);

        return ['items' => $result['items'], 'total' => $result['total'], 'page' => $pageNumber, 'limit' => $size];
    }

    private static function integer(?string $value, int $default, int $min, int $max, string $name): int
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (!ctype_digit($value) || (int) $value < $min || (int) $value > $max) {
            throw new HttpException(400, sprintf('%s invalide : entier de %d à %d attendu.', $name, $min, $max));
        }

        return (int) $value;
    }
}
