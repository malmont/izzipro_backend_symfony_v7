<?php

namespace App\Services\LandingAiService;

use App\Dto\BaniereStatiqueOutputDto;
use App\Dto\CategorieMarqueOutputDto;
use App\Dto\EmbedOutputDto;
use App\Dto\EmploiOutputDto;
use App\Dto\MultilienOutputDto;
use App\Dto\PresentationGroupOutputDto;
use App\Dto\PresentationOutputDto;
use App\Dto\RechercheOutputDto;
use App\Dto\VideoOutputDto;
use App\Services\BaniereStatiqueService\BaniereStatiqueService;
use App\Services\CategorieMarqueService\CategorieMarqueService;
use App\Services\EmbedService\EmbedService;
use App\Services\EmploiService\EmploiService;
use App\Services\MultilienService\MultilienService;
use App\Services\PresentationGroupService\PresentationGroupService;
use App\Services\PresentationService\PresentationService;
use App\Services\RechercheService\RechercheService;
use App\Services\VideoService\VideoService;

/**
 * Données du site courant que peut afficher une section (valeurs possibles de dataType), comme le sélecteur
 * « Données à afficher » de l'éditeur : identifiant, titre et aperçu. Lecture seule, tenant courant.
 *
 * Familles sans dataType (Contact, Footer, Navbar, Réservation, Services, Bannières (diaporama), Carrousel,
 * À propos) : leurs données viennent de l'entreprise ou de listes globales (repeat.source).
 */
final class LandingAiDataSources
{
    public const LOCALE = 'fr';
    private const EXCERPT = 160;
    private const MAX_ITEMS = 40;

    public function __construct(
        private readonly PresentationService $presentations,
        private readonly PresentationGroupService $presentationGroups,
        private readonly BaniereStatiqueService $banieresStatiques,
        private readonly VideoService $videos,
        private readonly EmbedService $embeds,
        private readonly MultilienService $multiliens,
        private readonly RechercheService $recherches,
        private readonly EmploiService $emplois,
        private readonly CategorieMarqueService $categoriesMarque
    ) {
    }

    /** La famille choisit-elle une donnée (dataType) ? */
    public function familyUsesData(string $componentKey): bool
    {
        return in_array($componentKey, ['Presentation', 'PresentationGroup', 'BaniereStatique', 'Video', 'Embed', 'MultiLien', 'Recherche', 'Candidature', 'Marque'], true);
    }

    /**
     * @return list<array{id: string, title: string, details: string}> vide si la famille n'utilise pas de données
     */
    public function available(string $componentKey): array
    {
        $items = match ($componentKey) {
            'Presentation' => array_map(fn ($e) => $this->item(new PresentationOutputDto($e, '', self::LOCALE), 'titre', 'texte'), $this->presentations->findAllByLocale(self::LOCALE)),
            'PresentationGroup' => array_map(function ($e) {
                $dto = new PresentationGroupOutputDto($e, '', self::LOCALE);
                $titles = array_filter(array_map(fn ($p) => $p->titre ?? null, $dto->presentations));

                return ['id' => (string) $dto->id, 'title' => (string) ($dto->titre ?? ''), 'details' => $this->excerpt(sprintf('%d présentation(s) : %s', count($dto->presentations), implode(' ; ', $titles)))];
            }, $this->presentationGroups->findAllByLocale(self::LOCALE)),
            'BaniereStatique' => array_map(fn ($e) => $this->item(new BaniereStatiqueOutputDto($e, '', self::LOCALE), 'titre', 'texte'), $this->banieresStatiques->findAllByLocale(self::LOCALE)),
            'Video' => array_map(fn ($e) => $this->item(new VideoOutputDto($e, '', self::LOCALE), 'titre', 'description'), $this->videos->getAllVideosByLocale(self::LOCALE)),
            'Embed' => array_map(fn ($e) => $this->item(new EmbedOutputDto($e), 'titre', 'embedUrl'), $this->embeds->findAllEmbeds()),
            'MultiLien' => array_map(fn ($e) => $this->item(new MultilienOutputDto($e, ''), 'titre', 'lien'), $this->multiliens->getAllMultiliens()),
            'Recherche' => array_map(fn ($e) => $this->item(new RechercheOutputDto($e, '', self::LOCALE), 'titre', 'texte1'), $this->recherches->getAllRecherches()),
            'Candidature' => array_map(fn ($e) => $this->item(new EmploiOutputDto($e, self::LOCALE), 'titre', 'description'), $this->emplois->getAllEmploisByLocale(self::LOCALE)),
            'Marque' => array_map(function ($e) {
                $dto = new CategorieMarqueOutputDto($e, null, self::LOCALE);

                return ['id' => (string) $dto->id, 'title' => (string) ($dto->nom ?? ''), 'details' => sprintf('%d marque(s)', $dto->nombreMarques)];
            }, $this->categoriesMarque->getAllCategoriesMarque(self::LOCALE)),
            default => [],
        };

        return array_slice(array_values($items), 0, self::MAX_ITEMS);
    }

    /** @return list<string> */
    public function availableIds(string $componentKey): array
    {
        return array_column($this->available($componentKey), 'id');
    }

    private function item(object $dto, string $titleField, string $detailsField): array
    {
        return [
            'id' => (string) $dto->id,
            'title' => (string) ($dto->$titleField ?? ''),
            'details' => $this->excerpt((string) ($dto->$detailsField ?? '')),
        ];
    }

    private function excerpt(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)));

        return mb_strlen($text) > self::EXCERPT ? mb_substr($text, 0, self::EXCERPT - 1) . '…' : $text;
    }
}
