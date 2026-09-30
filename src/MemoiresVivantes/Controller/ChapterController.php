<?php

namespace App\MemoiresVivantes\Controller;

use App\MemoiresVivantes\Dto\ChapterInputDto;
use App\MemoiresVivantes\Dto\ChapterOutputDto;
use App\MemoiresVivantes\Entity\Book;
use App\MemoiresVivantes\Entity\Chapter;
use App\MemoiresVivantes\Entity\Contributor;
use App\MemoiresVivantes\Entity\MemoireQuestion;
use App\MemoiresVivantes\UseCase\AddChapterPhotoUseCase;
use App\MemoiresVivantes\UseCase\CreateChapterUseCase;
use App\MemoiresVivantes\UseCase\DeleteChapterUseCase;
use App\MemoiresVivantes\UseCase\GetChapterUseCase;
use App\MemoiresVivantes\UseCase\GetChaptersByBookUseCase;
use App\MemoiresVivantes\UseCase\ImproveAnswerUseCase;
use App\MemoiresVivantes\UseCase\TranscribeAudioUseCase;
use App\MemoiresVivantes\UseCase\UpdateChapterUseCase;
use App\MemoiresVivantes\Security\BookAccessGuard;
use App\MemoiresVivantes\Services\ChapterGenerationService;
use App\MemoiresVivantes\Services\FrontendUrlResolver;
use App\MemoiresVivantes\UseCase\NoSpeechDetectedException;
use App\MemoiresVivantes\Services\ChapterQuestionProvider;
use App\MemoiresVivantes\BookType\BookTypeResolver;
use App\Services\AnthropicService;
use App\Services\MediaUrlResolver;
use App\Services\TenantEntityManagerProvider;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
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
        private readonly ChapterGenerationService $generationService,
        private readonly BookAccessGuard $accessGuard,
        private readonly FrontendUrlResolver $frontendUrl,
        private readonly ChapterQuestionProvider $questionProvider,
        private readonly BookTypeResolver $bookTypeResolver,
        private readonly TenantEntityManagerProvider $emProvider,
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

        if ($role === null) {
            $role = $this->bookTypeResolver->defaultRole($book);
        }

        $questionsByTheme = $this->questionProvider->forBook($book, $role);

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

        $data = json_decode($request->getContent(), true);
        $data = is_array($data) ? $data : [];
        foreach (['title', 'theme'] as $field) {
            if (!is_string($data[$field] ?? null) || trim($data[$field]) === '') {
                return $this->json(['error' => "Le champ « $field » est obligatoire."], 422);
            }
        }
        if (!is_numeric($data['position'] ?? null)) {
            return $this->json(['error' => 'Le champ « position » est obligatoire.'], 422);
        }
        $data['position'] = (int) $data['position'];
        foreach (['answers', 'contributorAnswers'] as $field) {
            if (isset($data[$field]) && !is_array($data[$field])) {
                return $this->json(['error' => "Le champ « $field » doit être une liste."], 422);
            }
        }
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

        $this->generationService->failIfLost($chapter);

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

        if ($role === null) {
            $role = $this->bookTypeResolver->defaultRole($chapter->getBook());
        }

        $questions = $this->questionProvider->forChapter($chapter, $role);

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
        $data = json_decode($request->getContent(), true);
        $data = is_array($data) ? $data : [];

        // Accès par lien signé : les réponses sont modifiables ; le texte final aussi, mais seulement par le lien de
        // partage du chapitre (relecture, « Partager l'édition »), pas par le lien personnel d'un contributeur.
        // Titre, thème et position restent au propriétaire du livre.
        if ($this->getUser() === null || !$this->isGranted('CHAPTER_EDIT', $chapter)) {
            $allowed = ['answers' => true, 'contributorAnswers' => true];
            if (!$validatedContributorId) {
                $allowed['contentFinal'] = true;
            }
            $data = array_intersect_key($data, $allowed);
        }

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

        if ($role === null) {
            $role = $this->bookTypeResolver->defaultRole($chapter->getBook());
        }

        $questions = $this->questionProvider->forChapter($chapter, $role);

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

        $this->denyAccessUnlessGranted('CHAPTER_VIEW', $chapter);

        $this->generationService->failIfLost($chapter);

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

        // Rédaction déjà en file ou en cours (double clic, relance du frontend) : pas de seconde rédaction payée
        if ($this->generationService->isInProgress($chapter)) {
            return $this->json(['status' => 'Generation already in progress', 'alreadyInProgress' => true]);
        }

        // Sans réponse ni témoignage, l'IA rédigerait un refus ou inventerait
        $reason = $this->generationService->cannotGenerateReason($chapter);
        if ($reason !== null) {
            return $this->json(['error' => $reason], 422);
        }

        $data = json_decode($request->getContent(), true) ?? $request->request->all();
        $tone = is_string($data['tone'] ?? null) && trim($data['tone']) !== '' ? trim($data['tone']) : 'intime et chaleureux';
        $model = isset($data['model']) && is_string($data['model']) && trim($data['model']) !== '' ? trim($data['model']) : null;

        $tenantHost = $request->headers->get('X-Tenant-Host') ?? $request->getHost();
        if (!$this->generationService->start($chapter, $tenantHost, $tone, $model)) {
            return $this->json(['error' => $chapter->getGenerationError()], 503);
        }

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

        try {
            $this->addChapterPhotoUseCase->execute($chapter, $file, $request->request->all());
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], 400);
        }
        
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
        // Safari (iPhone, iPad) enregistre en MP4/AAC, Chrome et Firefox en WebM/Ogg : les deux familles sont acceptées,
        // y compris quand le type détecté est celui du conteneur vidéo (un MP4 audio est souvent vu comme « video/mp4 »).
        $allowedExtensions = ['webm', 'ogg', 'oga', 'wav', 'mp3', 'm4a', 'mp4', 'aac'];
        $mimeExtensions = [
            'audio/webm' => 'webm', 'video/webm' => 'webm',
            'audio/ogg' => 'ogg', 'application/ogg' => 'ogg',
            'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/wave' => 'wav',
            'audio/mpeg' => 'mp3', 'audio/mp3' => 'mp3',
            'audio/mp4' => 'mp4', 'video/mp4' => 'mp4', 'audio/x-m4a' => 'm4a', 'audio/m4a' => 'm4a',
            'audio/aac' => 'aac', 'audio/x-hx-aac-adts' => 'aac',
        ];

        $extension = strtolower($file->getClientOriginalExtension());
        $mimeType = (string) $file->getMimeType();

        if (!in_array($extension, $allowedExtensions, true) && !isset($mimeExtensions[$mimeType])) {
            return $this->json(['error' => 'Invalid audio format. Allowed formats: webm, ogg, wav, mp3, m4a, mp4'], 400);
        }
        // L'extension enregistrée suit le contenu réel quand il est reconnu : la transcription s'appuie sur elle
        // (un enregistrement MP4 d'iPhone nommé « .webm » par le navigateur serait refusé par Whisper)
        $extension = $mimeExtensions[$mimeType] ?? (in_array($extension, $allowedExtensions, true) ? $extension : 'webm');

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
            try {
                $transcribedText = $this->transcribeAudioUseCase->execute($absoluteFilePath);
            } catch (NoSpeechDetectedException $e) {
                // Rien d'audible : ne pas enregistrer une phrase inventée par la transcription
                @unlink($absoluteFilePath);

                return $this->json(['error' => $e->getMessage(), 'noSpeech' => true], 422);
            }

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
                    if (is_array($contributorAnswers[$contribIdx]['answers'] ?? null)) {
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
                            
                            if ($targetIndex !== null && is_array($contrib['answers'] ?? null)) {
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

            // IA en erreur ou surchargée : ni texte vide présenté comme une amélioration, ni essai consommé
            if (trim($improvedText) === '') {
                return $this->json([
                    'error' => "L'amélioration par l'IA est momentanément indisponible. Votre réponse n'a pas été modifiée : réessayez dans un instant.",
                    'alreadyImproved' => false,
                    'canImprove' => true,
                ], 503);
            }

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

        $frontendHost = $this->frontendUrl->baseUrl($request);

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

        $frontendHost = $this->frontendUrl->baseUrl($request);

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
                    // Lien personnel d'un contributeur : refusé dès que le contributeur a été retiré du livre
                    if (hash_equals($expectedWithContrib, $signature) && (string)$chapter->getId() === $chapterId
                        && $this->accessGuard->contributorBelongsTo((string) $contributorId, $chapter->getBook())) {
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
                        if ($contributorId !== null && $this->accessGuard->contributorBelongsTo((string) $contributorId, $chapter->getBook())) {
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

    /** Lien de chapitre signé ou voter : voir BookAccessGuard::canView */
    private function validateBookSignatureOrGrant(string $attribute, Book $book, Request $request): ?JsonResponse
    {
        $denied = $this->accessGuard->canView($book, $request, $attribute);

        return $denied === null ? null : $this->json(['error' => $denied[1]], $denied[0]);
    }
}
