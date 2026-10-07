<?php

namespace App\Services\LandingSiteModelService;

use App\Entity\LandingSiteModel;
use App\Repository\LandingSiteModelRepository;
use App\Services\LandingPageSettingsService\ReglableCompositionValidator;
use App\Services\TenantEntityManagerProvider;

/**
 * Bibliothèque de modèles de site des landing pages, propre au site courant (base du tenant) : contrôle des champs,
 * de la configuration (mêmes règles que PUT /api/landingpage-settings), des limites, puis enregistrement. Ne lit ni
 * n'écrit jamais les réglages publiés du site.
 */
final class LandingSiteModelService
{
    public const MAX_MODELS = 30;
    public const NAME_MAX_LENGTH = 80;
    public const DESCRIPTION_MAX_LENGTH = 300;
    /** Octets de la configuration encodée (une page de démonstration pèse environ 120 Ko) */
    public const MAX_CONFIGURATION_BYTES = 2097152;
    public const FIELDS = ['name', 'description', 'configuration'];

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly ReglableCompositionValidator $validator
    ) {
    }

    /** @return LandingSiteModel[] */
    public function all(): array
    {
        return $this->models()->findLatestFirst();
    }

    /** @throws LandingSiteModelException 404 */
    public function get(int $id): LandingSiteModel
    {
        return $this->models()->find($id) ?? throw new LandingSiteModelException(404, 'Modèle introuvable sur ce site.');
    }

    /** @throws LandingSiteModelException */
    public function create(mixed $body, ?string $user): LandingSiteModel
    {
        $body = $this->body($body);
        foreach (self::FIELDS as $field) {
            if ($field !== 'description' && !property_exists($body, $field)) {
                throw new LandingSiteModelException(422, 'Champ obligatoire manquant : ' . $field, [['path' => $field, 'message' => 'champ obligatoire']]);
            }
        }
        if ($this->models()->countAll() >= self::MAX_MODELS) {
            throw new LandingSiteModelException(422, sprintf('%d modèles au plus par site : supprimez-en un avant d\'en enregistrer un autre.', self::MAX_MODELS),
                [['path' => '', 'message' => sprintf('%d modèles au plus par site', self::MAX_MODELS)]]);
        }
        $model = (new LandingSiteModel())->setCreatedBy($user);
        $this->apply($model, $body);
        $em = $this->emProvider->getEntityManager();
        $em->persist($model);
        $em->flush();

        return $model;
    }

    /** @throws LandingSiteModelException */
    public function update(int $id, mixed $body): LandingSiteModel
    {
        $model = $this->get($id);
        $body = $this->body($body);
        if (get_object_vars($body) === []) {
            throw new LandingSiteModelException(422, 'Aucun champ à modifier (name, description, configuration).', [['path' => '', 'message' => 'au moins un champ attendu']]);
        }
        $this->apply($model, $body);
        $model->touch();
        $this->emProvider->getEntityManager()->flush();

        return $model;
    }

    /** @throws LandingSiteModelException 404 */
    public function delete(int $id): void
    {
        $em = $this->emProvider->getEntityManager();
        $em->remove($this->get($id));
        $em->flush();
    }

    /** Contrôle tous les champs envoyés, puis les écrit (rien n'est écrit si un champ est refusé) */
    private function apply(LandingSiteModel $model, object $body): void
    {
        $errors = [];
        foreach (array_keys(get_object_vars($body)) as $field) {
            if (!in_array($field, self::FIELDS, true)) {
                $errors[] = ['path' => (string) $field, 'message' => 'champ inconnu (acceptés : name, description, configuration)'];
            }
        }
        $name = $description = $json = null;
        if (property_exists($body, 'name')) {
            $name = is_string($body->name) ? trim($body->name) : null;
            if ($name === null || $name === '' || mb_strlen($name) > self::NAME_MAX_LENGTH) {
                $errors[] = ['path' => 'name', 'message' => sprintf('texte de 1 à %d caractères attendu', self::NAME_MAX_LENGTH)];
            } elseif (preg_match('/[<>]/', $name)) {
                $errors[] = ['path' => 'name', 'message' => 'balises non autorisées (ni « < » ni « > »)'];
            }
        }
        if (property_exists($body, 'description')) {
            $description = $body->description;
            if ($description !== null && (!is_string($description) || mb_strlen($description) > self::DESCRIPTION_MAX_LENGTH)) {
                $errors[] = ['path' => 'description', 'message' => sprintf('texte de %d caractères au plus, ou null', self::DESCRIPTION_MAX_LENGTH)];
            }
            $description = is_string($description) && trim($description) !== '' ? trim($description) : null;
        }
        if (property_exists($body, 'configuration')) {
            $configuration = $body->configuration;
            if (!is_object($configuration)) {
                $errors[] = ['path' => 'configuration', 'message' => 'objet attendu (même forme que les réglages du site)'];
            } else {
                $json = json_encode($configuration, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
                if (strlen($json) > self::MAX_CONFIGURATION_BYTES) {
                    throw new LandingSiteModelException(413, sprintf('Configuration trop volumineuse : %d Mo au plus.', self::MAX_CONFIGURATION_BYTES / 1048576));
                }
                foreach ($this->validator->validateConfiguration($configuration) as $error) {
                    $errors[] = ['path' => 'configuration.' . $error['path'], 'message' => $error['message']];
                }
            }
        }
        if ($errors) {
            throw new LandingSiteModelException(422, 'Modèle refusé : ' . $errors[0]['path'] . ' : ' . $errors[0]['message'], array_slice($errors, 0, 100));
        }

        if ($name !== null) {
            $model->setName($name);
        }
        if (property_exists($body, 'description')) {
            $model->setDescription($description);
        }
        if ($json !== null) {
            [$tabs, $sections] = self::counts($body->configuration);
            $model->setConfiguration($json)->setTabsCount($tabs)->setSectionsCount($sections);
        }
    }

    /** @return array{0: int, 1: int} onglets, sections de tous les onglets */
    public static function counts(object $configuration): array
    {
        $tabs = is_array($configuration->tabs ?? null) ? $configuration->tabs : [];
        $sections = array_sum(array_map(fn ($tab) => is_object($tab) && is_array($tab->sections ?? null) ? count($tab->sections) : 0, $tabs));

        return [count($tabs), $sections];
    }

    private function body(mixed $body): object
    {
        if (!is_object($body)) {
            throw new LandingSiteModelException(400, 'Objet JSON attendu.');
        }

        return $body;
    }

    private function models(): LandingSiteModelRepository
    {
        return $this->emProvider->getEntityManager()->getRepository(LandingSiteModel::class);
    }
}
