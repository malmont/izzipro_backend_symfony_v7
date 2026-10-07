<?php

namespace App\Dto;

use App\Entity\LandingSiteModel;

/** Résumé d'un modèle de site (liste, création, modification) : sans la configuration */
final class LandingSiteModelSummaryDto
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly ?string $description,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        public readonly int $tabs,
        public readonly int $sections
    ) {
    }

    public static function fromEntity(LandingSiteModel $model): self
    {
        return new self(
            (int) $model->getId(),
            $model->getName(),
            $model->getDescription(),
            $model->getCreatedAt()->format(\DateTimeInterface::ATOM),
            $model->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            $model->getTabsCount(),
            $model->getSectionsCount()
        );
    }

    /** Modèle complet en JSON, configuration restituée telle qu'enregistrée */
    public static function fullJson(LandingSiteModel $model): string
    {
        $summary = (array) self::fromEntity($model);
        unset($summary['tabs'], $summary['sections']);
        $head = json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return substr($head, 0, -1) . ',"configuration":' . $model->getConfiguration() . '}';
    }
}
