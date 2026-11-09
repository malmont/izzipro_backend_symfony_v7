<?php

namespace App\Controller\TypeFournisseurController;

use App\UseCase\TypeFournisseurUseCase\GetListTypeFournisseurUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;
use App\Cache\CacheLocale;
use App\Cache\CacheTags;
use App\Services\TenantCacheService;

class TypeFournisseurController extends AbstractController
{
    private GetListTypeFournisseurUseCase $useCase;
    private TenantCacheService $cache;

    public function __construct(GetListTypeFournisseurUseCase $useCase, TenantCacheService $cache, private readonly ?TranslatorInterface $translator = null) // noms traduits (lot G3, 09/11/2026)
    {
        $this->useCase = $useCase;
        $this->cache = $cache;
    }

    #[Route('/api/type-fournisseurs', name: 'api_type_fournisseurs', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $locale = CacheLocale::normalize($request->query->get('locale'));
        $cacheKey = 'api_type_fournisseurs_all_' . $locale;

        $data = $this->cache->get(
            $cacheKey,
            function () use ($locale) {
                $dtoList = $this->useCase->execute();
                return array_map(function ($dto) use ($locale) {
                    return [
                        'id'    => $dto->id,
                        'name'  => $this->translated('referentiel.type_fournisseur.' . $dto->name, $dto->name, $locale),
                        'photo' => $dto->photo,
                    ];
                }, $dtoList);
            },
            3600,
            CacheTags::listTags(CacheTags::FOURNISSEURS)
        );

        return $this->json($data, JsonResponse::HTTP_OK);
    }

    /** Libellé traduit du référentiel (translations/gestion.<langue>.yaml) ; clé absente : nom enregistré tel quel */
    private function translated(string $key, string $name, string $locale): string
    {
        $t = $this->translator?->trans($key, [], 'gestion', $locale);

        return $t === null || $t === $key ? $name : $t;
    }
}
