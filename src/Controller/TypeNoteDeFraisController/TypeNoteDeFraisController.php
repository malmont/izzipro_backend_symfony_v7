<?php

namespace App\Controller\TypeNoteDeFraisController;

use App\UseCase\TypeNoteDeFraisUseCase\GetListTypeNoteDeFraisUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;
use App\Cache\CacheLocale;
use App\Cache\CacheTags;
use App\Services\TenantCacheService;

class TypeNoteDeFraisController extends AbstractController
{
    private GetListTypeNoteDeFraisUseCase $useCase;
    private TenantCacheService $cache;

    public function __construct(GetListTypeNoteDeFraisUseCase $useCase, TenantCacheService $cache, private readonly ?TranslatorInterface $translator = null) // noms traduits (lot G3, 09/11/2026)
    {
        $this->useCase = $useCase;
        $this->cache = $cache;
    }

    #[Route('/api/type-note-de-frais', name: 'api_type_note_de_frais', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $locale = CacheLocale::normalize($request->query->get('locale'));
        $cacheKey = 'api_type_note_de_frais_all_' . $locale;
        $data = $this->cache->get(
            $cacheKey,
            function () use ($locale) {
                $dtoList = $this->useCase->execute();
                return array_map(function ($dto) use ($locale) {
                    return [
                        'id'    => $dto->id,
                        'name'  => $this->translated('referentiel.type_note_de_frais.' . $dto->name, $dto->name, $locale),
                        'image' => $dto->image,
                    ];
                }, $dtoList);
            },
            3600,
            CacheTags::listTags(CacheTags::NOTES_DE_FRAIS)
        );
        return new JsonResponse($data, JsonResponse::HTTP_OK);
    }

    /** Libellé traduit du référentiel (translations/gestion.<langue>.yaml) ; clé absente : nom enregistré tel quel */
    private function translated(string $key, string $name, string $locale): string
    {
        $t = $this->translator?->trans($key, [], 'gestion', $locale);

        return $t === null || $t === $key ? $name : $t;
    }
}
