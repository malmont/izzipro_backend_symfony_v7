<?php

namespace App\Services\LandingContentService;

use App\Entity\SharedMedia;
use App\Services\LandingPageSettingsService\RichTextPolicy;
use App\Services\TenantEntityManagerProvider;

/**
 * Modification partielle d'un contenu de section (présentation, groupe, bannière, vidéo, service) depuis l'éditeur
 * des landing pages : contrôle de chaque champ envoyé (liste blanche de LandingContentSpec), puis écriture.
 *
 * Langue (mêmes règles qu'en lecture) : un champ traduit est écrit dans la traduction de la langue demandée, créée au
 * besoin (son titre part alors du titre de base) ; en français, le champ de base est aussi mis à jour, pour que
 * l'administration et les anciens écrans restent d'accord. Les champs non traduits (images, vidéo, couleur) sont
 * communs à toutes les langues.
 *
 * Médias : une clé de la médiathèque du site (64 caractères hexadécimaux, image pour une image, vidéo pour une vidéo)
 * est enregistrée sous la forme « /media/secure/{clé} » ; une URL https est enregistrée telle quelle.
 */
final class LandingContentEditor
{
    private const MEDIA_KEY = '/^[a-f0-9]{64}$/';
    /** Couleur CSS acceptée dans les contenus et la charte de la boutique (BoutiqueConfigurationValidator) */
    public const COLOR = '/^(transparent|#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})|rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(?:,\s*(?:0|1|0?\.\d+)\s*)?\))$/';

    public function __construct(private readonly TenantEntityManagerProvider $emProvider)
    {
    }

    public function find(string $resource, int $id): ?object
    {
        return $this->emProvider->getEntityManager()->getRepository(LandingContentSpec::RESOURCES[$resource]['entity'])->find($id);
    }

    /**
     * @return array{values: array<string, mixed>, errors: list<array{path: string, message: string}>} valeurs à écrire
     */
    public function validate(string $resource, object $body): array
    {
        $fields = LandingContentSpec::RESOURCES[$resource]['fields'];
        $values = [];
        $errors = [];
        foreach (get_object_vars($body) as $name => $value) {
            if (!isset($fields[$name])) {
                $errors[] = ['path' => (string) $name, 'message' => sprintf('champ non modifiable (champs acceptés : %s)', implode(', ', array_keys($fields)))];
                continue;
            }
            [$kind, , $max, $required] = $fields[$name];
            if ($value !== null && !is_string($value)) {
                $errors[] = ['path' => $name, 'message' => 'texte ou null attendu'];
                continue;
            }
            $value = $value === null ? null : trim($value);
            if ($value === '' || $value === null) {
                if ($required) {
                    $errors[] = ['path' => $name, 'message' => 'champ obligatoire : texte non vide attendu'];
                } else {
                    $values[$name] = null;
                }
                continue;
            }
            [$stored, $error] = match ($kind) {
                LandingContentSpec::TEXT => [$value, RichTextPolicy::problems($value)[0] ?? null],
                LandingContentSpec::LINK => [$value, self::linkError($value)],
                LandingContentSpec::COLOR => [$value, preg_match(self::COLOR, $value) ? null : 'couleur attendue (#rrggbb, rgb(…), rgba(…) ou transparent)'],
                LandingContentSpec::IMAGE => $this->media($value, SharedMedia::TYPE_IMAGE),
                LandingContentSpec::VIDEO => $this->media($value, SharedMedia::TYPE_VIDEO),
            };
            if ($error === null && mb_strlen((string) $stored) > $max) {
                $error = sprintf('%d caractères au plus', $max);
            }
            $error === null ? $values[$name] = $stored : $errors[] = ['path' => $name, 'message' => $error];
        }

        return ['values' => $values, 'errors' => $errors];
    }

    /** Écrit les valeurs contrôlées par validate() */
    public function apply(string $resource, object $entity, array $values, string $locale): void
    {
        $spec = LandingContentSpec::RESOURCES[$resource];
        $em = $this->emProvider->getEntityManager();
        $translation = null;
        foreach ($values as $name => $value) {
            $setter = 'set' . ucfirst($name);
            if (!$spec['fields'][$name][1]) {
                $entity->$setter($value);
                continue;
            }
            $translation ??= $this->translation($entity, $spec['translation'], $locale);
            $translation->$setter($value);
            if ($locale === 'fr') {
                $entity->$setter($value);
            }
        }
        if ($translation !== null && $translation->getId() === null) {
            $em->persist($translation);
        }
        $em->flush();
    }

    /**
     * Valeurs des champs telles que le GET de la ressource les lit dans cette langue (traduction, puis valeur de
     * base) : état avant / après pour le journal des écritures.
     *
     * @param list<string> $fields
     * @return array<string, ?string>
     */
    public function snapshot(string $resource, object $entity, array $fields, string $locale): array
    {
        $spec = LandingContentSpec::RESOURCES[$resource]['fields'];
        $translation = method_exists($entity, 'getTranslation') ? $entity->getTranslation($locale) : null;
        $values = [];
        foreach ($fields as $field) {
            $getter = 'get' . ucfirst($field);
            $values[$field] = ($spec[$field][1] && $translation !== null ? $translation->$getter() : null) ?? $entity->$getter();
        }

        return $values;
    }

    /** Traduction exacte de la langue (sans repli sur une autre), créée au besoin */
    private function translation(object $entity, string $class, string $locale): object
    {
        foreach ($entity->getTranslations() as $translation) {
            if ($translation->getLanguage() === $locale) {
                return $translation;
            }
        }
        $translation = new $class();
        $translation->setLanguage($locale);
        $translation->setTitre((string) $entity->getTitre());
        $entity->addTranslation($translation);

        return $translation;
    }

    /** @return array{0: ?string, 1: ?string} valeur à enregistrer, erreur */
    private function media(string $value, string $type): array
    {
        if (preg_match(self::MEDIA_KEY, $value)) {
            $media = $this->emProvider->getEntityManager()->getRepository(SharedMedia::class)->findOneBy(['accessKey' => $value]);
            if (!$media instanceof SharedMedia || !$media->isPrivate()) {
                return [null, 'clé de média inconnue sur ce site'];
            }
            if ($media->getMediaType() !== $type) {
                return [null, sprintf('ce média n\'est pas une %s', $type === SharedMedia::TYPE_IMAGE ? 'image' : 'vidéo')];
            }

            return ['/media/secure/' . $value, null];
        }
        if (preg_match('#^https://[^\s<>"\']+$#i', $value)) {
            return [$value, null];
        }

        return [null, 'clé de la médiathèque (64 caractères) ou URL https attendue'];
    }

    /** Adresse d'un bouton : https, http, mailto, tel, chemin du site (/…) ou ancre (#…) */
    public static function linkError(string $url): ?string
    {
        // même règle que les liens des textes ; l'adresse d'un bouton est enregistrée telle quelle, sans entités
        return RichTextPolicy::urlProblem($url);
    }
}
