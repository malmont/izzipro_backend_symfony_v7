<?php

namespace App\Services\LandingContentService;

use App\Entity\Product;
use App\Entity\ProductPicture;
use App\Entity\SharedMedia;
use App\Services\LandingPageSettingsService\RichTextPolicy;
use App\Services\TenantEntityManagerProvider;

/**
 * Modification partielle d'un contenu (présentation, groupe, bannière, vidéo, service ; produit, catégorie,
 * diapositive, carte « explorer » de la boutique) depuis les éditeurs : contrôle de chaque champ envoyé (liste blanche de LandingContentSpec), puis écriture.
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
            if ($value === null || $value === '' || $value === []) {
                if ($required) {
                    $errors[] = ['path' => $name, 'message' => 'champ obligatoire : valeur attendue'];
                } else {
                    $values[$name] = $kind === LandingContentSpec::IMAGES ? [] : null;
                }
                continue;
            }
            if ($kind === LandingContentSpec::IMAGES) {
                [$stored, $error] = $this->mediaList($value, $max);
                $error === null ? $values[$name] = $stored : $errors[] = ['path' => $name, 'message' => $error];
                continue;
            }
            if ($kind === LandingContentSpec::NUMBER) {
                if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
                    $errors[] = ['path' => $name, 'message' => 'montant en cents (entier positif ou nul) attendu'];
                } elseif ((float) $value < 0 || (float) $value > $max || round((float) $value) != (float) $value) {
                    $errors[] = ['path' => $name, 'message' => sprintf('entier de 0 à %d attendu (cents)', $max)];
                } else {
                    $values[$name] = (int) round((float) $value);
                }
                continue;
            }
            if (!is_string($value)) {
                $errors[] = ['path' => $name, 'message' => 'texte ou null attendu'];
                continue;
            }
            $value = trim($value);
            if ($value === '') {
                $required ? $errors[] = ['path' => $name, 'message' => 'champ obligatoire : texte non vide attendu'] : $values[$name] = null;
                continue;
            }
            [$stored, $error] = match ($kind) {
                LandingContentSpec::TEXT => [$value, RichTextPolicy::problems($value)[0] ?? null],
                LandingContentSpec::LINK => [$value, self::linkError($value)],
                LandingContentSpec::COLOR => [$value, preg_match(self::COLOR, $value) ? null : 'couleur attendue (#rrggbb, rgb(…), rgba(…) ou transparent)'],
                LandingContentSpec::IMAGE => $this->media($value, SharedMedia::TYPE_IMAGE),
                LandingContentSpec::VIDEO => $this->media($value, SharedMedia::TYPE_VIDEO),
                LandingContentSpec::DATE => self::date($value),
            };
            if ($error === null && $kind !== LandingContentSpec::DATE && mb_strlen((string) $stored) > $max) {
                $error = sprintf('%d caractères au plus', $max);
            }
            $error === null ? $values[$name] = $stored : $errors[] = ['path' => $name, 'message' => $error];
        }

        return ['values' => $values, 'errors' => $errors];
    }

    /** Écrit les valeurs contrôlées par validate() (ou rétablies depuis le journal : même forme que snapshot()) */
    public function apply(string $resource, object $entity, array $values, string $locale): void
    {
        $spec = LandingContentSpec::RESOURCES[$resource];
        $em = $this->emProvider->getEntityManager();
        $translation = null;
        foreach ($values as $name => $value) {
            [$kind, $translated] = $spec['fields'][$name];
            if ($kind === LandingContentSpec::IMAGES) {
                $this->replacePictures($entity, is_array($value) ? $value : []);
                continue;
            }
            $setter = 'set' . ucfirst(LandingContentSpec::property($resource, $name));
            $value = match ($kind) {
                LandingContentSpec::DATE => $value === null ? null : new \DateTimeImmutable((string) $value),
                LandingContentSpec::NUMBER => $value === null ? null : (float) $value,
                default => $value,
            };
            if (!$translated) {
                $entity->$setter($value);
                continue;
            }
            $translation ??= $this->translation($resource, $entity, $spec['translation'], $locale);
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

    /** Galerie d'un produit : remplacée par la liste donnée (ProductPicture, orphelins supprimés) */
    private function replacePictures(Product $product, array $paths): void
    {
        foreach ($product->getPictures()->toArray() as $picture) {
            $product->removePicture($picture);
        }
        foreach ($paths as $path) {
            $product->addPicture((new ProductPicture())->setImageUrl((string) $path));
        }
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
            if ($spec[$field][0] === LandingContentSpec::IMAGES) {
                $values[$field] = array_values(array_map(fn ($p) => $p->getImageUrl(), $entity->getPictures()->toArray()));
                continue;
            }
            $getter = 'get' . ucfirst(LandingContentSpec::property($resource, $field));
            $value = ($spec[$field][1] && $translation !== null ? $translation->$getter() : null) ?? $entity->$getter();
            $values[$field] = match (true) {
                $value instanceof \DateTimeInterface => $value->format(\DateTimeInterface::ATOM),
                $spec[$field][0] === LandingContentSpec::NUMBER && $value !== null => (int) round((float) $value),
                default => $value,
            };
        }

        return $values;
    }

    /** Traduction exacte de la langue (sans repli sur une autre), créée au besoin */
    private function translation(string $resource, object $entity, string $class, string $locale): object
    {
        $localeGetter = 'get' . ucfirst(LandingContentSpec::localeField($resource));
        foreach ($entity->getTranslations() as $translation) {
            if ($translation->$localeGetter() === $locale) {
                return $translation;
            }
        }
        $title = ucfirst(LandingContentSpec::titleField($resource));
        $translation = new $class();
        $translation->{'set' . ucfirst(LandingContentSpec::localeField($resource))}($locale);
        $translation->{"set$title"}((string) $entity->{"get$title"}());
        $entity->addTranslation($translation);

        return $translation;
    }

    /** @return array{0: ?string, 1: ?string} date ISO 8601 normalisée, erreur */
    private static function date(string $value): array
    {
        try {
            return [(new \DateTimeImmutable($value))->format(\DateTimeInterface::ATOM), null];
        } catch (\Exception) {
            return [null, 'date ISO 8601 attendue (ex. 2026-10-31T23:59:00-04:00)'];
        }
    }

    /** @return array{0: ?list<string>, 1: ?string} chemins à enregistrer, erreur */
    private function mediaList(mixed $value, int $max): array
    {
        if (!is_array($value) || array_keys($value) !== range(0, count($value) - 1)) {
            return [null, 'liste de clés de la médiathèque ou d\'URL https attendue'];
        }
        if (count($value) > $max) {
            return [null, sprintf('%d images au plus', $max)];
        }
        $stored = [];
        foreach ($value as $i => $item) {
            if (!is_string($item)) {
                return [null, sprintf('[%d] : clé de la médiathèque ou URL https attendue', $i)];
            }
            [$path, $error] = $this->media(trim($item), SharedMedia::TYPE_IMAGE);
            if ($error !== null) {
                return [null, "[$i] : $error"];
            }
            $stored[] = $path;
        }

        return [$stored, null];
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
