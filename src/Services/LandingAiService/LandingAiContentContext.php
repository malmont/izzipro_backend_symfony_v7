<?php

namespace App\Services\LandingAiService;

use App\Entity\Presentation;
use App\Entity\PresentationGroup;
use App\Entity\SharedMedia;
use App\Repository\SharedMediaRepository;
use App\Services\LandingContentService\LandingContentEditor;
use App\Services\LandingContentService\LandingContentSpec;
use App\Services\MediaUrlResolver;
use App\Services\TenantEntityManagerProvider;

/**
 * Données du site que l'assistant peut proposer de modifier (lecture seule ici) :
 * - médiathèque : images et vidéos récentes, désignées par leur titre (clé pour un média privé, adresse sinon) ;
 * - contenu de la donnée affichée par la section retouchée (famille + dataType) : champs modifiables par
 *   PATCH /api/{ressource}/{id}, et pour un groupe ses présentations (composables par les routes du groupe).
 * Les propositions de l'assistant ne sont jamais appliquées par le backend : l'éditeur les affiche, l'administrateur
 * les valide, puis les routes de modification (journalisées) les écrivent.
 */
final class LandingAiContentContext
{
    public const MEDIA_LIMIT = 40;
    /** Prestations proposées par un formulaire (optionsSource services) : champs modifiables par l'assistant */
    public const SERVICE_FIELDS = ['familyId', 'position', 'bookable', 'durationMinutes'];
    public const SERVICES_LIMIT = 40;
    /** Famille de section => ressource modifiable de sa donnée */
    public const FAMILY_RESOURCES = [
        'Presentation' => 'presentations',
        'PresentationGroup' => 'presentation-groups',
        'BaniereStatique' => 'baniere-statiques',
        'Video' => 'videos',
    ];

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly LandingContentEditor $editor,
        private readonly MediaUrlResolver $urls
    ) {
    }

    /** @return list<array{media: string, title: string, type: string, width?: int, height?: int}> */
    public function mediaLibrary(): array
    {
        /** @var SharedMediaRepository $repository */
        $repository = $this->emProvider->getEntityManager()->getRepository(SharedMedia::class);
        [$media] = $repository->searchForEditor([SharedMedia::TYPE_IMAGE, SharedMedia::TYPE_VIDEO], null, 1, self::MEDIA_LIMIT);

        return array_map(fn (SharedMedia $m) => [
            'media' => $m->isPrivate() && $m->getAccessKey() ? (string) $m->getAccessKey() : $this->urls->resolveSharedMediaUrl($m, null),
            'title' => mb_substr((string) $m->getTitre(), 0, 80),
            'type' => $m->getMediaType(),
        ], $media);
    }

    /**
     * Contenu modifiable de la donnée affichée : null si la famille n'a pas de contenu modifiable ou sans donnée.
     *
     * @return array{resource: string, id: int, fields: array<string, ?string>, presentations?: list<array{id: int, fields: array<string, ?string>}>}|null
     */
    public function editableContent(string $componentKey, string|int|null $dataType, string $locale): ?array
    {
        $resource = self::FAMILY_RESOURCES[$componentKey] ?? null;
        if ($resource === null || $dataType === null || !ctype_digit((string) $dataType)) {
            return null;
        }
        $entity = $this->editor->find($resource, (int) $dataType);
        if ($entity === null) {
            return null;
        }
        $content = [
            'resource' => $resource,
            'id' => (int) $dataType,
            'fields' => $this->editor->snapshot($resource, $entity, array_keys(LandingContentSpec::RESOURCES[$resource]['fields']), $locale),
        ];
        if ($entity instanceof PresentationGroup) {
            $content['presentations'] = array_map(fn (Presentation $p) => [
                'id' => (int) $p->getId(),
                'fields' => $this->editor->snapshot('presentations', $p, array_keys(LandingContentSpec::RESOURCES['presentations']['fields']), $locale),
            ], $entity->getOrderedPresentations());
        }

        return $content;
    }

    /**
     * Ajoute au contenu modifiable les prestations du site quand la composition a une question qui les propose
     * (optionsSource « services ») : l'assistant peut alors en proposer la famille, l'ordre, la disponibilité et la
     * durée (contentChanges resource « service-offers »). Contenu sans donnée de section : seulement les prestations.
     *
     * @return array|null
     */
    public function withServiceOffers(?array $content, object $composition, string $locale): ?array
    {
        $usesServices = false;
        foreach (is_array($composition->blocks ?? null) ? $composition->blocks : [] as $block) {
            $usesServices = $usesServices || (is_object($block) && ($block->type ?? null) === 'field' && ($block->optionsSource ?? null) === 'services');
        }
        if (!$usesServices) {
            return $content;
        }
        $em = $this->emProvider->getEntityManager();
        $offers = $em->getRepository(\App\Entity\ServiceOffer::class)->findBy([], ['position' => 'ASC', 'id' => 'ASC'], self::SERVICES_LIMIT);
        $families = $em->getRepository(\App\Entity\ServiceFamily::class)->findBy([], ['position' => 'ASC', 'id' => 'ASC']);

        return ($content ?? []) + [
            'services' => array_map(fn (\App\Entity\ServiceOffer $o) => [
                'id' => (int) $o->getId(), 'titre' => (string) $o->getTitre(), 'familyId' => $o->getFamily()?->getId(),
                'position' => $o->getPosition(), 'bookable' => $o->isBookable(), 'durationMinutes' => $o->getDurationMinutes(),
            ], $offers),
            'families' => array_map(fn (\App\Entity\ServiceFamily $f) => ['id' => (int) $f->getId(), 'name' => $f->nameFor($locale)], $families),
        ];
    }

    /** @return list<string> médias de la médiathèque utilisables par les compositions et les contenus */
    public function libraryMedia(): array
    {
        return array_column($this->mediaLibrary(), 'media');
    }
}
