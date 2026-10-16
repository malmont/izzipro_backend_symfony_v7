<?php

namespace App\MemoiresVivantes\Controller;

use App\MemoiresVivantes\BookType\BookTypeResolver;
use App\MemoiresVivantes\Dto\ChapterOutputDto;
use App\MemoiresVivantes\Entity\Chapter;
use App\MemoiresVivantes\Exception\ApiErrorException;
use App\MemoiresVivantes\Services\ChapterQuestionProvider;
use App\MemoiresVivantes\Services\PricingService;
use App\MemoiresVivantes\UseCase\Payment\ListUnassignedPurchasesUseCase;
use App\MemoiresVivantes\UseCase\Payment\UnlockChapterUseCase;
use App\Services\MediaUrlResolver;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Achats de l'application mobile Living Memory (16/10/2026) : tarifs, déblocage d'un chapitre par une transaction
 * de store (RevenueCat), achats non encore affectés. Connexion obligatoire (access_control `^/api/memoires/mobile/`).
 * Contrat : docs/memoires-vivantes.md, « Rédaction payante et livre imprimé ».
 */
#[Route('/api/memoires/mobile')]
class MobilePurchaseController extends AbstractController
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly PricingService $pricing,
        private readonly UnlockChapterUseCase $unlock,
        private readonly ListUnassignedPurchasesUseCase $unassigned,
        private readonly ChapterQuestionProvider $questionProvider,
        private readonly BookTypeResolver $bookTypeResolver,
        private readonly ?MediaUrlResolver $mediaUrlResolver = null
    ) {}

    /** {currencies, defaultCurrency, print: {<devise>: {firstCopy, nextCopies}}, chapterProduct} */
    #[Route('/pricing', methods: ['GET'])]
    public function pricing(): JsonResponse
    {
        if (!$this->getUser()) return $this->json(['error' => 'Unauthorized'], 401);
        $settings = $this->pricing->settings();

        return $this->json(['currencies' => array_keys($settings->getPrintGrid()), 'defaultCurrency' => $settings->defaultCurrency(), 'print' => $settings->getPrintGrid(), 'chapterProduct' => $settings->getChapterProduct()]);
    }

    /** {transactionId, store} → le chapitre complet (paid renseigné) ; 404 PURCHASE_NOT_FOUND, 409 PURCHASE_ALREADY_USED | CHAPTER_ALREADY_PAID | PURCHASE_REFUNDED */
    #[Route('/chapters/{id}/unlock', methods: ['POST'])]
    public function unlock(string $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) return $this->json(['error' => 'Unauthorized'], 401);
        $chapter = $this->emProvider->getEntityManager()->getRepository(Chapter::class)->find(Uuid::fromString($id));
        if (!$chapter) return $this->json(['error' => 'Chapter not found'], 404);
        $this->denyAccessUnlessGranted('CHAPTER_EDIT', $chapter);
        $data = json_decode($request->getContent(), true);
        try {
            $chapter = $this->unlock->execute($chapter, $user, is_array($data) ? $data : []);
        } catch (ApiErrorException $e) {
            return $this->json($e->toBody(), $e->status);
        }
        $book = $chapter->getBook();
        $host = $this->mediaUrlResolver?->getPublicHost($request->getSchemeAndHttpHost()) ?? $request->getSchemeAndHttpHost();
        $questions = $this->questionProvider->forBook($book, $this->bookTypeResolver->defaultRole($book))[$chapter->getTheme()] ?? [];

        return $this->json(new ChapterOutputDto($chapter, $host, $questions, null, null, $this->bookTypeResolver->speakersByTheme($book)[$chapter->getTheme()] ?? null));
    }

    /** {purchases: [{transactionId, store, product, amount, currency, purchasedAt}]} */
    #[Route('/purchases/unassigned', methods: ['GET'])]
    public function unassignedPurchases(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) return $this->json(['error' => 'Unauthorized'], 401);

        return $this->json($this->unassigned->execute($user));
    }
}
