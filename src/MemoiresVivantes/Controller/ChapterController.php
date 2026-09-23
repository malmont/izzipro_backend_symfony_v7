<?php

namespace App\MemoiresVivantes\Controller;

use App\MemoiresVivantes\Dto\ChapterInputDto;
use App\MemoiresVivantes\Dto\ChapterOutputDto;
use App\MemoiresVivantes\Entity\Book;
use App\MemoiresVivantes\Entity\Chapter;
use App\MemoiresVivantes\Entity\Contributor;
use App\MemoiresVivantes\Entity\MemoireQuestion;
use App\MemoiresVivantes\Message\GenerateChapterMessage;
use App\MemoiresVivantes\UseCase\AddChapterPhotoUseCase;
use App\MemoiresVivantes\UseCase\CreateChapterUseCase;
use App\MemoiresVivantes\UseCase\DeleteChapterUseCase;
use App\MemoiresVivantes\UseCase\GetChapterUseCase;
use App\MemoiresVivantes\UseCase\GetChaptersByBookUseCase;
use App\MemoiresVivantes\UseCase\ImproveAnswerUseCase;
use App\MemoiresVivantes\UseCase\TranscribeAudioUseCase;
use App\MemoiresVivantes\UseCase\UpdateChapterUseCase;
use App\MemoiresVivantes\Services\HommageAggregationService;
use App\MemoiresVivantes\Services\FamilleAggregationService;
use App\Services\AnthropicService;
use App\Services\MediaUrlResolver;
use App\Services\TenantEntityManagerProvider;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api/memoires')]
class ChapterController extends AbstractController
{
    public function __construct(
        private readonly CreateChapterUseCase $createChapterUseCase,
        private readonly UpdateChapterUseCase $updateChapterUseCase,
        private readonly DeleteChapterUseCase $deleteChapterUseCase,
        private readonly GetChapterUseCase $getChapterUseCase,
        private readonly GetChaptersByBookUseCase $getChaptersByBookUseCase,
        private readonly AddChapterPhotoUseCase $addChapterPhotoUseCase,
        private readonly TranscribeAudioUseCase $transcribeAudioUseCase,
        private readonly ImproveAnswerUseCase $improveAnswerUseCase,
        private readonly HommageAggregationService $hommageAggregationService,
        private readonly FamilleAggregationService $familleAggregationService,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly MessageBusInterface $messageBus,
        private readonly \Psr\Log\LoggerInterface $logger,
        private readonly ?MediaUrlResolver $mediaUrlResolver = null
    ) {}

    private function resolveHost(Request $request): string
    {
        return $this->mediaUrlResolver?->getPublicHost($request->getSchemeAndHttpHost())
            ?? $request->getSchemeAndHttpHost();
    }

    #[Route('/books/{id}/chapters', methods: ['GET'])]
    public function listByBook(string $id, Request $request): JsonResponse
    {
        $em = $this->emProvider->getEntityManager();
        $book = $em->getRepository(Book::class)->find(Uuid::fromString($id));
        if (!$book) return $this->json(['error' => 'Book not found'], 404);

        $res = $this->validateBookSignatureOrGrant('BOOK_VIEW', $book, $request);
        if ($res !== null) return $res;

        $validatedContributorId = $request->attributes->get('validatedContributorId');
        $contributorIdParam = $request->query->get('contributorId') ?? $request->query->get('contributor_id');
        $targetContribId = $validatedContributorId ?: $contributorIdParam;
        $currentContributor = null;
        $role = $request->query->get('role');

        if ($targetContribId) {
            try {
                $contribRepo = $em->getRepository(Contributor::class);
                $contrib = $contribRepo->find(Uuid::fromString($targetContribId));
                if ($contrib) {
                    $role = $role ?: $contrib->getRole();
                    $currentContributor = [
                        'id' => (string) $contrib->getId(),
                        'firstName' => $contrib->getFirstName(),
                        'role' => $contrib->getRole(),
                        'isApproved' => $contrib->isApproved(),
                        'approvedAt' => $contrib->getApprovedAt()?->format(\DateTimeInterface::ATOM),
                    ];
                }
            } catch (\Throwable) {}
        }

        if ($role === null && $book->getType() === 'famille') {
            $role = ($book->isParentsDeceased() || $book->isParentsNotParticipating()) ? 'enfant' : 'parent';
        }

        $questionsByTheme = [];
        if ($book->getType()) {
            $qb = $em->getRepository(MemoireQuestion::class)->createQueryBuilder('q')
                ->where('q.isActive = true')
                ->andWhere('q.bookType = :bookType')
                ->setParameter('bookType', $book->getType());

            if ($role !== null) {
                $qb->andWhere('(q.role IS NULL OR q.role = :role)')
                   ->setParameter('role', $role);
            }

            $qb->orderBy('q.displayOrder', 'ASC');
            foreach ($qb->getQuery()->getResult() as $q) {
                $questionsByTheme[$q->getTheme()][] = $q->toFrontArray();
            }
        }

        $chapters = $this->getChaptersByBookUseCase->execute($book);
        $host = $this->resolveHost($request);
        return $this->json(array_map(function($c) use ($host, $questionsByTheme, $targetContribId, $currentContributor) {
            $themeQuestions = $questionsByTheme[$c->getTheme()] ?? [];
            return new ChapterOutputDto($c, $host, $themeQuestions, $targetContribId, $currentContributor);
        }, $chapters));
    }


    #[Route('/books/{id}/chapters', methods: ['POST'])]
    public function create(string $id, Request $request): JsonResponse
    {
        $em = $this->emProvider->getEntityManager();
        $book = $em->getRepository(Book::class)->find(Uuid::fromString($id));
        if (!$book) return $this->json(['error' => 'Book not found'], 404);

        $this->denyAccessUnlessGranted('BOOK_EDIT', $book);

        $data = json_decode($request->getContent(), true) ?? [];
        $this->logger->info("Raw Chapter Create Data: " . $request->getContent());
        $tenantHost = $request->headers->get('X-Tenant-Host') ?? $request->getHost();
        $chapter = $this->createChapterUseCase->execute($book, new ChapterInputDto($data), $tenantHost);
        
        $host = $this->resolveHost($request);
        return $this->json(new ChapterOutputDto($chapter, $host), 201);
    }

    #[Route('/chapters/{id}', methods: ['GET'])]
    public function get(string $id, Request $request): JsonResponse
    {
        $chapter = $this->getChapterUseCase->execute($id);
        if (!$chapter) return $this->json(['error' => 'Chapter not found'], 404);

        $em = $this->emProvider->getEntityManager();

        $res = $this->validateSignatureOrGrant('CHAPTER_VIEW', $chapter, $request);
        if ($res !== null) return $res;

        $host = $this->resolveHost($request);

        $validatedContributorId = $request->attributes->get('validatedContributorId');
        $contributorIdParam = $request->query->get('contributorId') ?? $request->query->get('contributor_id');
        $targetContribId = $validatedContributorId ?: $contributorIdParam;
        $currentContributor = null;
        $role = $request->query->get('role');

        if ($targetContribId) {
            try {
                $contribRepo = $em->getRepository(Contributor::class);
                $contrib = $contribRepo->find(Uuid::fromString($targetContribId));
                if ($contrib) {
                    $role = $role ?: $contrib->getRole();
                    $currentContributor = [
                        'id' => (string) $contrib->getId(),
                        'firstName' => $contrib->getFirstName(),
                        'role' => $contrib->getRole(),
                        'isApproved' => $contrib->isApproved(),
                        'approvedAt' => $contrib->getApprovedAt()?->format(\DateTimeInterface::ATOM),
                    ];
                }
            } catch (\Throwable) {
                // Ignore parsing non-uuid
            }
        }

        if ($role === null && $chapter->getBook() && $chapter->getBook()->getType() === 'famille') {
            $role = ($chapter->getBook()->isParentsDeceased() || $chapter->getBook()->isParentsNotParticipating()) ? 'enfant' : 'parent';
        }

        $questions = [];
        if ($chapter->getTheme()) {
            $qb = $em->getRepository(MemoireQuestion::class)->createQueryBuilder('q')
                ->where('q.theme = :theme')
                ->andWhere('q.isActive = true')
                ->setParameter('theme', $chapter->getTheme());

            if ($chapter->getBook() && $chapter->getBook()->getType()) {
                $qb->andWhere('q.bookType = :bookType')
                   ->setParameter('bookType', $chapter->getBook()->getType());
            }

            if ($role !== null) {
                $qb->andWhere('(q.role IS NULL OR q.role = :role)')
                   ->setParameter('role', $role);
            }

            $qb->orderBy('q.displayOrder', 'ASC');
            $questionEntities = $qb->getQuery()->getResult();
            $questions = array_map(fn(MemoireQuestion $q) => $q->toFrontArray(), $questionEntities);
        }

        return $this->json(new ChapterOutputDto($chapter, $host, $questions, $validatedContributorId, $currentContributor));
    }

    #[Route('/chapters/{id}', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $em = $this->emProvider->getEntityManager();
        $chapter = $em->getRepository(Chapter::class)->find(Uuid::fromString($id));
        if (!$chapter) return $this->json(['error' => 'Chapter not found'], 404);

        $res = $this->validateSignatureOrGrant('CHAPTER_EDIT', $chapter, $request);
        if ($res !== null) return $res;

        $validatedContributorId = $request->attributes->get('validatedContributorId');
        $data = json_decode($request->getContent(), true) ?? [];
        error_log("RAW UPDATE BODY: " . $request->getContent());

        // Si la requête provient d'un lien contributeur individuel, restreindre la modification à ce contributeur
        if ($validatedContributorId && isset($data['contributorAnswers']) && is_array($data['contributorAnswers'])) {
            $existingAll = $chapter->getContributorAnswers() ?? [];
            $filteredSubmitted = [];

            $targetContrib = null;
            try {
                $targetContrib = $em->getRepository(Contributor::class)->find(Uuid::fromString($validatedContributorId));
            } catch (\Throwable) {}
            $targetFirstName = $targetContrib ? strtolower($targetContrib->getFirstName()) : null;

            foreach ($data['contributorAnswers'] as $submitted) {
                if (!is_array($submitted)) continue;
                $sId = $submitted['id'] ?? null;
                $sName = strtolower($submitted['contributorName'] ?? $submitted['firstName'] ?? '');
                if (($sId && (string)$sId === (string)$validatedContributorId) ||
                    ($targetFirstName && $sName === $targetFirstName)) {
                    // Injecter l'ID officiel pour garantir la cohérence
                    $submitted['id'] = (string)$validatedContributorId;
                    if ($targetContrib) {
                        $submitted['firstName'] = $targetContrib->getFirstName();
                        $submitted['contributorName'] = $targetContrib->getFirstName();
                        $submitted['role'] = $targetContrib->getRole();
                    }
                    $filteredSubmitted[] = $submitted;
                }
            }

            // Fusionner pour préserver les réponses des autres participants sans les écraser
            $merged = [];
            foreach ($existingAll as $ext) {
                if (!is_array($ext)) continue;
                $extId = $ext['id'] ?? null;
                $extName = strtolower($ext['contributorName'] ?? $ext['firstName'] ?? '');
                if (($extId && (string)$extId === (string)$validatedContributorId) ||
                    ($targetFirstName && $extName === $targetFirstName)) {
                    continue;
                }
                $merged[] = $ext;
            }
            foreach ($filteredSubmitted as $sub) {
                $merged[] = $sub;
            }
            $data['contributorAnswers'] = $merged;
        }

        $tenantHost = $request->headers->get('X-Tenant-Host') ?? $request->getHost();
        $chapter = $this->updateChapterUseCase->execute($chapter, $data, false, $tenantHost);

        $host = $this->resolveHost($request);

        $targetContribId = $validatedContributorId ?: ($request->query->get('contributorId') ?? $request->query->get('contributor_id'));
        $currentContributor = null;
        $role = $request->query->get('role');

        if ($targetContribId) {
            try {
                $contribRepo = $em->getRepository(Contributor::class);
                $contrib = $contribRepo->find(Uuid::fromString($targetContribId));
                if ($contrib) {
                    $role = $role ?: $contrib->getRole();
                    $currentContributor = [
                        'id' => (string) $contrib->getId(),
                        'firstName' => $contrib->getFirstName(),
                        'role' => $contrib->getRole(),
                        'isApproved' => $contrib->isApproved(),
                        'approvedAt' => $contrib->getApprovedAt()?->format(\DateTimeInterface::ATOM),
                    ];
                }
            } catch (\Throwable) {}
        }

        if ($role === null && $chapter->getBook() && $chapter->getBook()->getType() === 'famille') {
            $role = ($chapter->getBook()->isParentsDeceased() || $chapter->getBook()->isParentsNotParticipating()) ? 'enfant' : 'parent';
        }

        $questions = [];
        if ($chapter->getTheme()) {
            $qb = $em->getRepository(MemoireQuestion::class)->createQueryBuilder('q')
                ->where('q.theme = :theme')
                ->andWhere('q.isActive = true')
                ->setParameter('theme', $chapter->getTheme());

            if ($chapter->getBook() && $chapter->getBook()->getType()) {
                $qb->andWhere('q.bookType = :bookType')
                   ->setParameter('bookType', $chapter->getBook()->getType());
            }

            if ($role !== null) {
                $qb->andWhere('(q.role IS NULL OR q.role = :role)')
                   ->setParameter('role', $role);
            }

            $qb->orderBy('q.displayOrder', 'ASC');
            $questionEntities = $qb->getQuery()->getResult();
            $questions = array_map(fn(MemoireQuestion $q) => $q->toFrontArray(), $questionEntities);
        }

        return $this->json(new ChapterOutputDto($chapter, $host, $questions, $validatedContributorId, $currentContributor));
    }

    #[Route('/chapters/{id}', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        $em = $this->emProvider->getEntityManager();
        $chapter = $em->getRepository(Chapter::class)->find(Uuid::fromString($id));
        if (!$chapter) return $this->json(['error' => 'Chapter not found'], 404);

        $this->denyAccessUnlessGranted('CHAPTER_DELETE', $chapter);

        $this->deleteChapterUseCase->execute($chapter);
        return $this->json(['status' => 'Chapter deleted']);
    }

    #[Route('/chapters/{id}/status', methods: ['GET'])]
    public function status(string $id): JsonResponse
    {
        $em = $this->emProvider->getEntityManager();
        $chapter = $em->getRepository(Chapter::class)->find(Uuid::fromString($id));
        if (!$chapter) return $this->json(['error' => 'Chapter not found'], 404);

        $this->logger->info("Generating Chapter: " . $id . " | Answers Count: " . count($chapter->getAnswers()));

        $this->denyAccessUnlessGranted('CHAPTER_VIEW', $chapter);

        return $this->json([
            'status'  => $chapter->getGenerationStatus(),
            'content' => $chapter->getGenerationStatus() === 'completed' ? $chapter->getContentFinal() : null,
            'error'   => $chapter->getGenerationError(),
        ]);
    }

    #[Route('/chapters/{id}/generate', methods: ['POST'])]
    public function generate(string $id, Request $request): JsonResponse
    {
        $em = $this->emProvider->getEntityManager();
        $chapter = $em->getRepository(Chapter::class)->find(Uuid::fromString($id));
        if (!$chapter) return $this->json(['error' => 'Chapter not found'], 404);

        $this->denyAccessUnlessGranted('CHAPTER_EDIT', $chapter);

        // Guardrail pour Hommage : vérifier les prérequis de synthèse transversale
        if ($chapter->getBook() && $chapter->getBook()->getType() === 'hommage') {
            $theme = $chapter->getTheme();
            if ($theme === 'portrait_croise' || $theme === 'une_vie') {
                $check = $this->hommageAggregationService->checkCanGenerateSynthesis($chapter);
                if (!$check['canGenerate']) {
                    return $this->json([
                        'error' => $check['reason'] ?? 'Les témoignages préalables sont requis pour ce chapitre de synthèse.'
                    ], 422);
                }
            }
        }

        // Guardrail pour Famille : vérifier les prérequis de synthèse pour l'histoire des parents
        if ($chapter->getBook() && $chapter->getBook()->getType() === 'famille') {
            $theme = $chapter->getTheme();
            if ($theme === 'histoire_parents' || $theme === 'histoire_aine') {
                $check = $this->familleAggregationService->checkCanGenerateSynthesis($chapter);
                if (!$check['canGenerate']) {
                    return $this->json([
                        'error' => $check['reason'] ?? 'Les témoignages des proches ou des parents sont requis avant de pouvoir générer ce chapitre.'
                    ], 422);
                }
            }
        }

        $data = json_decode($request->getContent(), true) ?? $request->request->all();
        $tone = $data['tone'] ?? 'intime et chaleureux';
        $model = isset($data['model']) && is_string($data['model']) && trim($data['model']) !== '' ? trim($data['model']) : null;

        $chapter->setGenerationStatus('pending');
        $em->flush();

        $tenantHost = $request->headers->get('X-Tenant-Host') ?? $request->getHost();
        $this->messageBus->dispatch(new GenerateChapterMessage((string) $chapter->getId(), 1, $tenantHost, $tone, $model));

        return $this->json(['status' => 'Generation started']);
    }

    #[Route('/ai-models', methods: ['GET'])]
    public function getAiModels(AnthropicService $anthropicService): JsonResponse
    {
        return $this->json($anthropicService->getAvailableModels());
    }

    #[Route('/chapters/{id}/photos', methods: ['POST'])]
    public function addPhoto(string $id, Request $request): JsonResponse
    {
        $em = $this->emProvider->getEntityManager();
        $chapter = $em->getRepository(Chapter::class)->find(Uuid::fromString($id));
        if (!$chapter) return $this->json(['error' => 'Chapter not found'], 404);

        $this->denyAccessUnlessGranted('CHAPTER_EDIT', $chapter);

        $file = $request->files->get('photo')
            ?? $request->files->get('file')
            ?? $request->files->get('image')
            ?? ($request->files->count() > 0 ? $request->files->getIterator()->current() : null);
        if (!$file) return $this->json(['error' => 'No file uploaded — expected field: photo, file, or image'], 400);

        $this->addChapterPhotoUseCase->execute($chapter, $file, $request->request->all());
        
        $host = $this->resolveHost($request);
        return $this->json(new ChapterOutputDto($chapter, $host));
    }

    #[Route('/chapters/{id}/photos/layout', methods: ['POST'])]
    #[Route('/chapters/{chapterId}/photos/layout', methods: ['POST'])]
    public function updatePhotosLayout(?string $id = null, ?string $chapterId = null, Request $request = null): JsonResponse
    {
        $targetId = $id ?: $chapterId;
        $em = $this->emProvider->getEntityManager();
        try {
            $chapter = $em->getRepository(Chapter::class)->find(Uuid::fromString($targetId));
        } catch (\Throwable) {
            return $this->json(['error' => 'Invalid chapter UUID'], 400);
        }

        if (!$chapter) return $this->json(['error' => 'Chapter not found'], 404);

        $res = $this->validateSignatureOrGrant('CHAPTER_EDIT', $chapter, $request);
        if ($res !== null) return $res;

        $data = json_decode($request->getContent(), true) ?: [];
        $photoPages = $data['photo_pages'] ?? $data['pages'] ?? [];

        $chapter->setPhotoLayout($photoPages);
        $em->flush();

        $resolvedHost = $request ? $this->resolveHost($request) : '';
        return $this->json([
            'status' => 'Photo layout updated',
            'chapter_id' => $chapter->getId()->toRfc4122(),
            'photo_pages' => $photoPages,
            'photoPages' => $photoPages,
            'photo_layout' => $photoPages,
            'photoLayout' => $photoPages,
            'chapter' => new ChapterOutputDto($chapter, $resolvedHost),
        ]);
    }

    #[Route('/chapters/{id}/reset', methods: ['POST'])]
    public function reset(string $id): JsonResponse
    {
        $em = $this->emProvider->getEntityManager();
        $chapter = $em->getRepository(Chapter::class)->find(Uuid::fromString($id));
        if (!$chapter) return $this->json(['error' => 'Chapter not found'], 404);

        $this->denyAccessUnlessGranted('CHAPTER_EDIT', $chapter);

        $chapter->setContentFinal($chapter->getContentGenerated());
        $em->flush();

        return $this->json(['status' => 'Content reset to generated version']);
    }

    #[Route('/chapters/{id}/audio', methods: ['POST'])]
    public function uploadAudio(string $id, Request $request): JsonResponse
    {
        $em = $this->emProvider->getEntityManager();
        $chapter = $em->getRepository(Chapter::class)->find(Uuid::fromString($id));
        if (!$chapter) return $this->json(['error' => 'Chapter not found'], 404);

        $res = $this->validateSignatureOrGrant('CHAPTER_EDIT', $chapter, $request);
        if ($res !== null) return $res;

        $file = $request->files->get('file');
        $questionIndex = $request->request->get('questionIndex');

        if (!$file) {
            return $this->json(['error' => 'No audio file uploaded'], 400);
        }

        if ($questionIndex === null || $questionIndex === '') {
            return $this->json(['error' => 'Missing questionIndex parameter'], 400);
        }

        // Validate file size (max 20MB)
        $maxSizeBytes = 20 * 1024 * 1024;
        if ($file->getSize() > $maxSizeBytes) {
            return $this->json(['error' => 'The audio file is too large (maximum size is 20MB)'], 400);
        }

        // Validate mime-type / extension
        $allowedExtensions = ['webm', 'ogg', 'wav', 'mp3', 'm4a'];
        $allowedMimeTypes = ['audio/webm', 'audio/ogg', 'audio/wav', 'audio/mpeg', 'audio/mp4', 'audio/x-m4a'];
        
        $extension = strtolower($file->getClientOriginalExtension());
        if (empty($extension)) {
            $extension = $file->guessExtension();
        }

        $mimeType = $file->getMimeType();

        if (!in_array($extension, $allowedExtensions) && !in_array($mimeType, $allowedMimeTypes)) {
            return $this->json(['error' => 'Invalid audio format. Allowed formats: webm, ogg, wav, mp3, m4a'], 400);
        }

        // Ensure upload directory exists
        $projectDir = $this->getParameter('kernel.project_dir');
        $uploadDir = $projectDir . '/var/storage/public_bucket/uploads/audio/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0775, true);
        }

        // Generate unpredictable secure unique filename
        $newFilename = bin2hex(random_bytes(16)) . '.' . ($extension ?: 'webm');
        $file->move($uploadDir, $newFilename);

        $absoluteFilePath = $uploadDir . $newFilename;

        try {
            // Call Whisper API for transcription
            $transcribedText = $this->transcribeAudioUseCase->execute($absoluteFilePath);

            // Update database JSON
            $answers = $chapter->getAnswers();
            $contributorAnswers = $chapter->getContributorAnswers();
            $updated = false;

            // 1. Check in normal answers (Solo / Couple)
            foreach ($answers as &$ans) {
                if (is_array($ans) && isset($ans['index'])) {
                    // Exact match (Solo)
                    if ((string)$ans['index'] === (string)$questionIndex) {
                        $ans['answer'] = $transcribedText;
                        $ans['audioUrl'] = '/uploads/audio/' . $newFilename;
                        $updated = true;
                        break;
                    }
                    
                    // Partner match (Couple: ex index_partner1 or index_partner2)
                    if (str_contains($questionIndex, '_partner')) {
                        $baseIndex = str_replace(['_partner1', '_partner2'], '', $questionIndex);
                        if ((string)$ans['index'] === (string)$baseIndex) {
                            if (str_contains($questionIndex, 'partner1')) {
                                $ans['audioUrl1'] = '/uploads/audio/' . $newFilename;
                            } else {
                                $ans['audioUrl2'] = '/uploads/audio/' . $newFilename;
                            }
                            $ans['answer'] = $transcribedText;
                            $updated = true;
                            break;
                        }
                    }
                }
            }

            if ($updated) {
                $chapter->setAnswers($answers);
            }

            // 2. Check in contributor answers (Famille / Hommage) if not updated yet
            if (!$updated && is_array($contributorAnswers)) {
                $validatedContributorId = $request->attributes->get('validatedContributorId');
                if ($validatedContributorId) {
                    foreach ($contributorAnswers as &$contrib) {
                        $cId = $contrib['id'] ?? null;
                        if ($cId && (string)$cId === (string)$validatedContributorId) {
                            preg_match('/\d+/', (string)$questionIndex, $matches);
                            $targetIndex = $matches[0] ?? (string)$questionIndex;
                            if (isset($contrib['answers']) && is_array($contrib['answers'])) {
                                foreach ($contrib['answers'] as &$ans) {
                                    if (isset($ans['index']) && (string)$ans['index'] === (string)$targetIndex) {
                                        $ans['audioUrl'] = '/uploads/audio/' . $newFilename;
                                        $ans['answer'] = $transcribedText;
                                        $updated = true;
                                        break 2;
                                    }
                                }
                            }
                        }
                    }
                }

                // Try format contribIndex_questionIndex (e.g. 0_0)
                if (!$updated && preg_match('/^(\d+)_(\d+)$/', $questionIndex, $matches)) {
                    $contribIdx = (int)$matches[1];
                    $questionIdx = (int)$matches[2];
                    if (isset($contributorAnswers[$contribIdx])) {
                        foreach ($contributorAnswers[$contribIdx]['answers'] as &$ans) {
                            if (isset($ans['index']) && (int)$ans['index'] === $questionIdx) {
                                $ans['audioUrl'] = '/uploads/audio/' . $newFilename;
                                $ans['answer'] = $transcribedText;
                                $updated = true;
                                break;
                            }
                        }
                    }
                }


                // Try name/id matching (e.g. Jean_0)
                if (!$updated) {
                    foreach ($contributorAnswers as &$contrib) {
                        $cName = strtolower($contrib['contributorName'] ?? $contrib['firstName'] ?? '');
                        $cId = strtolower($contrib['id'] ?? '');
                        if (($cName !== '' && str_contains(strtolower($questionIndex), $cName)) ||
                            ($cId !== '' && str_contains(strtolower($questionIndex), $cId))) {
                            
                            preg_match('/\d+/', $questionIndex, $matches);
                            $targetIndex = $matches[0] ?? null;
                            
                            if ($targetIndex !== null) {
                                foreach ($contrib['answers'] as &$ans) {
                                    if (isset($ans['index']) && (string)$ans['index'] === (string)$targetIndex) {
                                        $ans['audioUrl'] = '/uploads/audio/' . $newFilename;
                                        $ans['answer'] = $transcribedText;
                                        $updated = true;
                                        break 2;
                                    }
                                }
                            }
                        }
                    }
                }

                if ($updated) {
                    $chapter->setContributorAnswers($contributorAnswers);
                }
            }

            if ($updated) {
                $em->flush();
            } else {
                $this->logger->warning("ChapterController audio upload: questionIndex '{$questionIndex}' not found in chapter answers or contributor answers.");
            }

            $host = $this->resolveHost($request);
            $absoluteAudioUrl = rtrim($host, '/') . '/uploads/audio/' . $newFilename;

            $responseData = [
                'text' => $transcribedText,
                'audioUrl' => $absoluteAudioUrl,
            ];

            if (str_contains($questionIndex, 'partner1')) {
                $responseData['audioUrl1'] = $absoluteAudioUrl;
            } elseif (str_contains($questionIndex, 'partner2')) {
                $responseData['audioUrl2'] = $absoluteAudioUrl;
            }

            return $this->json($responseData);
        } catch (\Exception $e) {
            $this->logger->error("ChapterController audio upload exception: " . $e->getMessage());
            return $this->json(['error' => 'An error occurred during transcription: ' . $e->getMessage()], 500);
        }
    }

    #[Route('/chapters/{id}/improve-answer', methods: ['POST'])]
    public function improveAnswer(string $id, Request $request): JsonResponse
    {
        $em = $this->emProvider->getEntityManager();
        $chapter = $em->getRepository(Chapter::class)->find(Uuid::fromString($id));
        if (!$chapter) return $this->json(['error' => 'Chapter not found'], 404);

        $res = $this->validateSignatureOrGrant('CHAPTER_EDIT', $chapter, $request);
        if ($res !== null) return $res;

        $user = $this->getUser();
        $validatedContributorId = $request->attributes->get('validatedContributorId');
        $contributorIdParam = $request->query->get('contributorId') ?? $request->query->get('contributor_id');
        $targetContribId = $validatedContributorId ?: $contributorIdParam;
        $isGuest = ($user === null);

        $data = json_decode($request->getContent(), true) ?? [];
        $question = trim((string)($data['question'] ?? ''));
        $answer = trim((string)($data['answer'] ?? ''));
        $index = isset($data['index']) && is_numeric($data['index']) ? (int)$data['index'] : null;
        $model = isset($data['model']) && is_string($data['model']) && trim($data['model']) !== '' ? trim($data['model']) : null;

        if (empty($question) || empty($answer)) {
            return $this->json(['error' => 'Missing question or answer parameter'], 400);
        }

        // Tenter de retrouver l'index de la question si non fourni
        if ($index === null && $question !== '' && $chapter->getTheme()) {
            try {
                $qb = $em->getRepository(MemoireQuestion::class)->createQueryBuilder('q')
                    ->where('q.theme = :theme')
                    ->andWhere('q.isActive = true')
                    ->setParameter('theme', $chapter->getTheme());
                if ($chapter->getBook() && $chapter->getBook()->getType()) {
                    $qb->andWhere('q.bookType = :bookType')
                       ->setParameter('bookType', $chapter->getBook()->getType());
                }
                $qb->orderBy('q.displayOrder', 'ASC');
                $qList = $qb->getQuery()->getResult();
                foreach ($qList as $idx => $qEnt) {
                    if (trim($qEnt->getQuestion()) === $question) {
                        $index = $idx;
                        break;
                    }
                }
            } catch (\Throwable) {}
        }

        $questionKey = ($index !== null) ? 'idx_' . $index : 'q_' . substr(md5($question), 0, 12);

        $contributorAnswers = $chapter->getContributorAnswers() ?? [];
        $targetContribEntry = null;
        $targetContribIdx = null;

        if (is_array($contributorAnswers) && $targetContribId) {
            foreach ($contributorAnswers as $idx => $cEntry) {
                if (!is_array($cEntry)) continue;
                $cId = $cEntry['id'] ?? null;
                if ($cId && (string)$cId === (string)$targetContribId) {
                    $targetContribEntry = $cEntry;
                    $targetContribIdx = $idx;
                    break;
                }
            }
        }

        // En mode invité (lien contributeur) : vérifier la limite de 1 amélioration par question
        if ($isGuest && $targetContribEntry !== null) {
            $improvedIndices = $targetContribEntry['improvedQuestionIndices'] ?? [];
            $improvedKeys = $targetContribEntry['improvedQuestionKeys'] ?? [];
            $alreadyImproved = false;

            if ($index !== null && in_array($index, $improvedIndices, true)) {
                $alreadyImproved = true;
            } elseif (in_array($questionKey, $improvedKeys, true)) {
                $alreadyImproved = true;
            } else {
                if (isset($targetContribEntry['answers']) && is_array($targetContribEntry['answers'])) {
                    foreach ($targetContribEntry['answers'] as $ans) {
                        if (!is_array($ans)) continue;
                        $match = false;
                        if ($index !== null && isset($ans['index']) && (int)$ans['index'] === $index) {
                            $match = true;
                        } elseif (isset($ans['question']) && trim($ans['question']) === $question) {
                            $match = true;
                        }
                        if ($match) {
                            $imp = trim($ans['improvedAnswer'] ?? $ans['improved_answer'] ?? '');
                            if ($imp !== '') {
                                $alreadyImproved = true;
                                break;
                            }
                        }
                    }
                }
            }

            if ($alreadyImproved) {
                return $this->json([
                    'error' => "Cette réponse a déjà été améliorée par l'IA (limitée à une seule fois par question en mode invité).",
                    'alreadyImproved' => true,
                    'canImprove' => false,
                ], 403);
            }
        }

        try {
            $improvedText = $this->improveAnswerUseCase->execute($question, $answer, $model);

            if ($isGuest && $targetContribId) {
                if ($targetContribEntry === null) {
                    $contribRepo = $em->getRepository(Contributor::class);
                    $contribEntity = null;
                    try {
                        $contribEntity = $contribRepo->find(Uuid::fromString($targetContribId));
                    } catch (\Throwable) {}

                    $targetContribEntry = [
                        'id' => (string)$targetContribId,
                        'contributorName' => $contribEntity ? $contribEntity->getFirstName() : '',
                        'firstName' => $contribEntity ? $contribEntity->getFirstName() : '',
                        'role' => $contribEntity ? $contribEntity->getRole() : 'proche',
                        'improvedQuestionIndices' => [],
                        'improvedQuestionKeys' => [],
                        'answers' => [],
                    ];
                    $targetContribIdx = count($contributorAnswers);
                }

                if (!isset($targetContribEntry['improvedQuestionIndices']) || !is_array($targetContribEntry['improvedQuestionIndices'])) {
                    $targetContribEntry['improvedQuestionIndices'] = [];
                }
                if (!isset($targetContribEntry['improvedQuestionKeys']) || !is_array($targetContribEntry['improvedQuestionKeys'])) {
                    $targetContribEntry['improvedQuestionKeys'] = [];
                }

                if ($index !== null && !in_array($index, $targetContribEntry['improvedQuestionIndices'], true)) {
                    $targetContribEntry['improvedQuestionIndices'][] = $index;
                }
                if (!in_array($questionKey, $targetContribEntry['improvedQuestionKeys'], true)) {
                    $targetContribEntry['improvedQuestionKeys'][] = $questionKey;
                }

                // Enregistrer immédiatement la réponse améliorée pour le contributeur
                $ansFound = false;
                if (!isset($targetContribEntry['answers']) || !is_array($targetContribEntry['answers'])) {
                    $targetContribEntry['answers'] = [];
                }
                foreach ($targetContribEntry['answers'] as &$ans) {
                    if (!is_array($ans)) continue;
                    if (($index !== null && isset($ans['index']) && (int)$ans['index'] === $index) ||
                        (isset($ans['question']) && trim($ans['question']) === $question)) {
                        $ans['improvedAnswer'] = $improvedText;
                        $ans['useImproved'] = true;
                        if (!empty($answer)) {
                            $ans['answer'] = $answer;
                        }
                        $ansFound = true;
                        break;
                    }
                }
                unset($ans);

                if (!$ansFound) {
                    $targetContribEntry['answers'][] = [
                        'index' => $index ?? count($targetContribEntry['answers']),
                        'question' => $question,
                        'answer' => $answer,
                        'improvedAnswer' => $improvedText,
                        'useImproved' => true,
                    ];
                }

                $contributorAnswers[$targetContribIdx] = $targetContribEntry;
                $chapter->setContributorAnswers($contributorAnswers);
                $em->flush();
            }

            return $this->json([
                'improvedText' => $improvedText,
                'alreadyImproved' => false,
                'canImprove' => false,
            ]);
        } catch (\Exception $e) {
            $this->logger->error("ChapterController improveAnswer exception: " . $e->getMessage());
            return $this->json(['error' => 'An error occurred during text improvement: ' . $e->getMessage()], 500);
        }
    }

    #[Route('/chapters/{id}/share-link', methods: ['GET'])]
    public function getShareLink(string $id, Request $request): JsonResponse
    {
        $em = $this->emProvider->getEntityManager();
        $chapter = $em->getRepository(Chapter::class)->find(Uuid::fromString($id));
        if (!$chapter) return $this->json(['error' => 'Chapter not found'], 404);

        $this->denyAccessUnlessGranted('CHAPTER_EDIT', $chapter);

        // Duration defaults to 7 days (604800 seconds)
        $duration = $request->query->getInt('duration', 604800);
        $expires = time() + $duration;

        $secret = $this->getParameter('kernel.secret');
        $chapterId = (string)$chapter->getId();
        $bookId = (string)$chapter->getBook()->getId();

        $frontendHost = rtrim(
            $_ENV['MEMOIRES_FRONTEND_URL'] ?? ('https://memoiresvivantes.' . ($_ENV['FRONTEND_BASE_DOMAIN'] ?? 'arkanoa-media.com')),
            '/'
        );

        $contributorId = $request->query->get('contributorId') ?? $request->query->get('contributor_id');
        if ($contributorId) {
            $dataToSign = "chapterId=" . $chapterId . "&contributorId=" . $contributorId . "&expires=" . $expires;
            $signature = hash_hmac('sha256', $dataToSign, $secret);
            $shareUrl = sprintf(
                '%s/memoires/shared/books/%s/chapters/%s?contributorId=%s&expires=%d&signature=%s&chapterId=%s',
                $frontendHost,
                $bookId,
                $chapterId,
                $contributorId,
                $expires,
                $signature,
                $chapterId
            );
        } else {
            $dataToSign = "chapterId=" . $chapterId . "&expires=" . $expires;
            $signature = hash_hmac('sha256', $dataToSign, $secret);
            $shareUrl = sprintf(
                '%s/memoires/shared/books/%s/chapters/%s?expires=%d&signature=%s&chapterId=%s',
                $frontendHost,
                $bookId,
                $chapterId,
                $expires,
                $signature,
                $chapterId
            );
        }

        return $this->json([
            'url' => $shareUrl
        ]);
    }

    #[Route('/chapters/{id}/contributor-links', methods: ['GET'])]
    public function getContributorLinks(string $id, Request $request): JsonResponse
    {
        $em = $this->emProvider->getEntityManager();
        $chapter = $em->getRepository(Chapter::class)->find(Uuid::fromString($id));
        if (!$chapter) return $this->json(['error' => 'Chapter not found'], 404);

        $this->denyAccessUnlessGranted('CHAPTER_EDIT', $chapter);

        $duration = $request->query->getInt('duration', 604800);
        $expires = time() + $duration;

        $secret = $this->getParameter('kernel.secret');
        $chapterId = (string)$chapter->getId();
        $bookId = (string)$chapter->getBook()->getId();

        $frontendHost = rtrim(
            $_ENV['MEMOIRES_FRONTEND_URL'] ?? ('https://memoiresvivantes.' . ($_ENV['FRONTEND_BASE_DOMAIN'] ?? 'arkanoa-media.com')),
            '/'
        );

        $contributors = $chapter->getBook()->getContributors();
        $links = [];

        foreach ($contributors as $contrib) {
            $contribId = (string)$contrib->getId();
            $dataToSign = "chapterId=" . $chapterId . "&contributorId=" . $contribId . "&expires=" . $expires;
            $signature = hash_hmac('sha256', $dataToSign, $secret);

            $shareUrl = sprintf(
                '%s/memoires/shared/books/%s/chapters/%s?contributorId=%s&expires=%d&signature=%s&chapterId=%s',
                $frontendHost,
                $bookId,
                $chapterId,
                $contribId,
                $expires,
                $signature,
                $chapterId
            );

            $links[] = [
                'contributorId' => $contribId,
                'firstName' => $contrib->getFirstName(),
                'role' => $contrib->getRole(),
                'isApproved' => $contrib->isApproved(),
                'approvedAt' => $contrib->getApprovedAt()?->format(\DateTimeInterface::ATOM),
                'shareUrl' => $shareUrl,
            ];
        }

        return $this->json($links);
    }

    private function validateSignatureOrGrant(string $attribute, Chapter $chapter, Request $request): ?JsonResponse
    {
        $expires = $request->query->get('expires');
        $signature = $request->query->get('signature');
        $chapterId = $request->query->get('chapterId') ?? $request->query->get('chapter_id') ?? (string)$chapter->getId();
        $contributorId = $request->query->get('contributorId') ?? $request->query->get('contributor_id');

        if ($expires === null || $signature === null || $chapterId === null) {
            if ($request->request->has('expires')) {
                $expires = $request->request->get('expires');
            }
            if ($request->request->has('signature')) {
                $signature = $request->request->get('signature');
            }
            if ($request->request->has('chapterId')) {
                $chapterId = $request->request->get('chapterId');
            } elseif ($request->request->has('chapter_id')) {
                $chapterId = $request->request->get('chapter_id');
            }
            if ($contributorId === null) {
                if ($request->request->has('contributorId')) {
                    $contributorId = $request->request->get('contributorId');
                } elseif ($request->request->has('contributor_id')) {
                    $contributorId = $request->request->get('contributor_id');
                }
            }
        }

        if ($expires === null || $signature === null || $chapterId === null) {
            $content = $request->getContent();
            if ($content) {
                $data = json_decode($content, true);
                if (is_array($data)) {
                    $expires = $expires ?? $data['expires'] ?? null;
                    $signature = $signature ?? $data['signature'] ?? null;
                    $chapterId = $chapterId ?? $data['chapterId'] ?? $data['chapter_id'] ?? null;
                    $contributorId = $contributorId ?? $data['contributorId'] ?? $data['contributor_id'] ?? null;
                }
            }
        }

        if ($expires === null || $signature === null || $chapterId === null) {
            $expires = $request->headers->get('X-Expires');
            $signature = $request->headers->get('X-Signature');
            $chapterId = $chapterId ?? $request->headers->get('X-Chapter-Id');
            $contributorId = $contributorId ?? $request->headers->get('X-Contributor-Id');
        }

        if ($expires === null || $signature === null || $chapterId === null) {
            $referer = $request->headers->get('Referer');
            if ($referer) {
                $query = parse_url($referer, PHP_URL_QUERY);
                if ($query) {
                    parse_str($query, $params);
                    $expires = $expires ?? $params['expires'] ?? null;
                    $signature = $signature ?? $params['signature'] ?? null;
                    $chapterId = $chapterId ?? $params['chapterId'] ?? $params['chapter_id'] ?? null;
                    $contributorId = $contributorId ?? $params['contributorId'] ?? $params['contributor_id'] ?? null;
                }
            }
        }

        $hasValidSignature = false;
        if ($expires !== null && $signature !== null && $chapterId !== null) {
            if (time() <= (int)$expires) {
                $secret = $this->getParameter('kernel.secret');

                // 1. Signature spécifique au contributeur
                if ($contributorId !== null) {
                    $dataToSignWithContrib = "chapterId=" . $chapterId . "&contributorId=" . $contributorId . "&expires=" . $expires;
                    $expectedWithContrib = hash_hmac('sha256', $dataToSignWithContrib, $secret);
                    if (hash_equals($expectedWithContrib, $signature) && (string)$chapter->getId() === $chapterId) {
                        $hasValidSignature = true;
                        $request->attributes->set('validatedContributorId', $contributorId);
                    }
                }

                // 2. Signature classique globale (rétrocompatibilité pour solo, couple, famille)
                if (!$hasValidSignature) {
                    $dataToSign = "chapterId=" . $chapterId . "&expires=" . $expires;
                    $expectedSignature = hash_hmac('sha256', $dataToSign, $secret);

                    if (hash_equals($expectedSignature, $signature) && (string)$chapter->getId() === $chapterId) {
                        $hasValidSignature = true;
                        if ($contributorId !== null) {
                            $request->attributes->set('validatedContributorId', $contributorId);
                        }
                    }
                }
            }
        }

        if ($hasValidSignature) {
            return null;
        }

        $user = $this->getUser();
        if ($user !== null) {
            try {
                $this->denyAccessUnlessGranted($attribute, $chapter);
                return null;
            } catch (\Symfony\Component\Security\Core\Exception\AccessDeniedException) {
                // proceed to return JsonResponse below
            }
        }

        if ($expires !== null && $signature !== null && $chapterId !== null) {
            if (time() > (int)$expires) {
                return $this->json(['error' => 'This sharing link has expired.'], \Symfony\Component\HttpFoundation\Response::HTTP_UNAUTHORIZED);
            }
            return $this->json(['error' => 'Invalid signature or resource mismatch.'], \Symfony\Component\HttpFoundation\Response::HTTP_UNAUTHORIZED);
        }

        return $this->json(['error' => 'Access denied. Missing or invalid signature.'], \Symfony\Component\HttpFoundation\Response::HTTP_UNAUTHORIZED);
    }

    private function validateBookSignatureOrGrant(string $attribute, Book $book, Request $request): ?JsonResponse
    {
        $expires = $request->query->get('expires');
        $signature = $request->query->get('signature');
        $chapterId = $request->query->get('chapterId') ?? $request->query->get('chapter_id');
        $contributorId = $request->query->get('contributorId') ?? $request->query->get('contributor_id');

        if ($expires === null || $signature === null || $chapterId === null) {
            if ($request->request->has('expires')) {
                $expires = $request->request->get('expires');
            }
            if ($request->request->has('signature')) {
                $signature = $request->request->get('signature');
            }
            if ($request->request->has('chapterId')) {
                $chapterId = $request->request->get('chapterId');
            } elseif ($request->request->has('chapter_id')) {
                $chapterId = $request->request->get('chapter_id');
            }
            if ($contributorId === null) {
                if ($request->request->has('contributorId')) {
                    $contributorId = $request->request->get('contributorId');
                } elseif ($request->request->has('contributor_id')) {
                    $contributorId = $request->request->get('contributor_id');
                }
            }
        }

        if ($expires === null || $signature === null || $chapterId === null) {
            $content = $request->getContent();
            if ($content) {
                $data = json_decode($content, true);
                if (is_array($data)) {
                    $expires = $expires ?? $data['expires'] ?? null;
                    $signature = $signature ?? $data['signature'] ?? null;
                    $chapterId = $chapterId ?? $data['chapterId'] ?? $data['chapter_id'] ?? null;
                    $contributorId = $contributorId ?? $data['contributorId'] ?? $data['contributor_id'] ?? null;
                }
            }
        }

        if ($expires === null || $signature === null || $chapterId === null) {
            $expires = $request->headers->get('X-Expires');
            $signature = $request->headers->get('X-Signature');
            $chapterId = $chapterId ?? $request->headers->get('X-Chapter-Id');
            $contributorId = $contributorId ?? $request->headers->get('X-Contributor-Id');
        }

        if ($expires === null || $signature === null || $chapterId === null) {
            $referer = $request->headers->get('Referer');
            if ($referer) {
                $query = parse_url($referer, PHP_URL_QUERY);
                if ($query) {
                    parse_str($query, $params);
                    $expires = $expires ?? $params['expires'] ?? null;
                    $signature = $signature ?? $params['signature'] ?? null;
                    $chapterId = $chapterId ?? $params['chapterId'] ?? $params['chapter_id'] ?? null;
                    $contributorId = $contributorId ?? $params['contributorId'] ?? $params['contributor_id'] ?? null;
                }
            }
        }

        $hasValidSignature = false;
        if ($expires !== null && $signature !== null && $chapterId !== null) {
            if (time() <= (int)$expires) {
                $secret = $this->getParameter('kernel.secret');

                // 1. Signature spécifique au contributeur
                if ($contributorId !== null) {
                    $dataToSignWithContrib = "chapterId=" . $chapterId . "&contributorId=" . $contributorId . "&expires=" . $expires;
                    $expectedWithContrib = hash_hmac('sha256', $dataToSignWithContrib, $secret);
                    if (hash_equals($expectedWithContrib, $signature)) {
                        $em = $this->emProvider->getEntityManager();
                        $chapter = $em->getRepository(Chapter::class)->find(Uuid::fromString($chapterId));
                        if ($chapter && (string)$chapter->getBook()->getId() === (string)$book->getId()) {
                            $hasValidSignature = true;
                            $request->attributes->set('validatedContributorId', $contributorId);
                        }
                    }
                }

                // 2. Signature classique globale
                if (!$hasValidSignature) {
                    $dataToSign = "chapterId=" . $chapterId . "&expires=" . $expires;
                    $expectedSignature = hash_hmac('sha256', $dataToSign, $secret);

                    if (hash_equals($expectedSignature, $signature)) {
                        $em = $this->emProvider->getEntityManager();
                        $chapter = $em->getRepository(Chapter::class)->find(Uuid::fromString($chapterId));
                        if ($chapter && (string)$chapter->getBook()->getId() === (string)$book->getId()) {
                            $hasValidSignature = true;
                            if ($contributorId !== null) {
                                $request->attributes->set('validatedContributorId', $contributorId);
                            }
                        }
                    }
                }
            }
        }

        if ($hasValidSignature) {
            return null;
        }

        $user = $this->getUser();
        if ($user !== null) {
            try {
                $this->denyAccessUnlessGranted($attribute, $book);
                return null;
            } catch (\Symfony\Component\Security\Core\Exception\AccessDeniedException) {}
        }

        if ($expires !== null && $signature !== null && $chapterId !== null) {
            if (time() > (int)$expires) {
                return $this->json(['error' => 'This sharing link has expired.'], \Symfony\Component\HttpFoundation\Response::HTTP_UNAUTHORIZED);
            }
            return $this->json(['error' => 'Invalid signature or resource mismatch.'], \Symfony\Component\HttpFoundation\Response::HTTP_UNAUTHORIZED);
        }

        return $this->json(['error' => 'Access denied. Missing or invalid signature.'], \Symfony\Component\HttpFoundation\Response::HTTP_UNAUTHORIZED);
    }
}
