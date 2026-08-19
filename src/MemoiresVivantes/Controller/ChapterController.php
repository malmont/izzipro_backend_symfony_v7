<?php

namespace App\MemoiresVivantes\Controller;

use App\MemoiresVivantes\Dto\ChapterInputDto;
use App\MemoiresVivantes\Dto\ChapterOutputDto;
use App\MemoiresVivantes\Entity\Book;
use App\MemoiresVivantes\Entity\Chapter;
use App\MemoiresVivantes\Message\GenerateChapterMessage;
use App\MemoiresVivantes\Services\ChapterService;
use App\MemoiresVivantes\UseCase\CreateChapterUseCase;
use App\MemoiresVivantes\UseCase\UpdateChapterUseCase;
use App\MemoiresVivantes\UseCase\DeleteChapterUseCase;
use App\Services\TenantEntityManagerProvider;
use App\Services\OpenAiService;
use App\Services\AnthropicService;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api/memoires')]
class ChapterController extends AbstractController
{
    #[Route('/books/{id}/chapters', methods: ['GET'])]
    public function listByBook(string $id, Request $request): JsonResponse
    {
        $em = $this->emProvider->getEntityManager();
        $book = $em->getRepository(Book::class)->find(Uuid::fromString($id));
        if (!$book) return $this->json(['error' => 'Book not found'], 404);

        $this->denyAccessUnlessGranted('BOOK_VIEW', $book);

        $chapters = $book->getChapters();
        $host = $request->getSchemeAndHttpHost();
        return $this->json(array_map(fn($c) => new ChapterOutputDto($c, $host), $chapters->toArray()));
    }

    public function __construct(
        private readonly CreateChapterUseCase $createChapterUseCase,
        private readonly UpdateChapterUseCase $updateChapterUseCase,
        private readonly DeleteChapterUseCase $deleteChapterUseCase,
        private readonly ChapterService $chapterService,
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly MessageBusInterface $messageBus,
        private readonly \Psr\Log\LoggerInterface $logger,
        private readonly OpenAiService $openAiService,
        private readonly AnthropicService $anthropicService
    ) {}


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
        
        $host = $request->getSchemeAndHttpHost();
        return $this->json(new ChapterOutputDto($chapter, $host), 201);
    }

    #[Route('/chapters/{id}', methods: ['GET'])]
    public function get(string $id, Request $request): JsonResponse
    {
        $em = $this->emProvider->getEntityManager();
        $chapter = $em->getRepository(Chapter::class)->find(Uuid::fromString($id));
        if (!$chapter) return $this->json(['error' => 'Chapter not found'], 404);

        $res = $this->validateSignatureOrGrant('CHAPTER_VIEW', $chapter, $request);
        if ($res !== null) return $res;

        $host = $request->getSchemeAndHttpHost();
        return $this->json(new ChapterOutputDto($chapter, $host));
    }

    #[Route('/chapters/{id}', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $em = $this->emProvider->getEntityManager();
        $chapter = $em->getRepository(Chapter::class)->find(Uuid::fromString($id));
        if (!$chapter) return $this->json(['error' => 'Chapter not found'], 404);

        $res = $this->validateSignatureOrGrant('CHAPTER_EDIT', $chapter, $request);
        if ($res !== null) return $res;

        $data = json_decode($request->getContent(), true) ?? [];
        error_log("RAW UPDATE BODY: " . $request->getContent());
        $tenantHost = $request->headers->get('X-Tenant-Host') ?? $request->getHost();
        $chapter = $this->updateChapterUseCase->execute($chapter, $data, false, $tenantHost);
        
        $host = $request->getSchemeAndHttpHost();
        return $this->json(new ChapterOutputDto($chapter, $host));
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

        $data = json_decode($request->getContent(), true) ?? $request->request->all();
        $tone = $data['tone'] ?? 'intime et chaleureux';

        $chapter->setGenerationStatus('pending');
        $em->flush();

        $tenantHost = $request->headers->get('X-Tenant-Host') ?? $request->getHost();
        $this->messageBus->dispatch(new GenerateChapterMessage((string) $chapter->getId(), 1, $tenantHost, $tone));

        return $this->json(['status' => 'Generation started']);
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

        $this->chapterService->addPhoto($chapter, $file, $request->request->all());
        
        $host = $request->getSchemeAndHttpHost();
        return $this->json(new ChapterOutputDto($chapter, $host));
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
        $uploadDir = $projectDir . '/public/uploads/audio/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0775, true);
        }

        // Generate unpredictable secure unique filename
        $newFilename = bin2hex(random_bytes(16)) . '.' . ($extension ?: 'webm');
        $file->move($uploadDir, $newFilename);

        $absoluteFilePath = $uploadDir . $newFilename;

        try {
            // Call Whisper API for transcription
            $transcribedText = $this->openAiService->transcribe($absoluteFilePath);

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

            // 2. Check in contributor answers (Famille) if not updated yet
            if (!$updated && is_array($contributorAnswers)) {
                // Try format contribIndex_questionIndex (e.g. 0_0)
                if (preg_match('/^(\d+)_(\d+)$/', $questionIndex, $matches)) {
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

            $host = $request->getSchemeAndHttpHost();
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

        $this->denyAccessUnlessGranted('CHAPTER_EDIT', $chapter);

        $data = json_decode($request->getContent(), true) ?? [];
        $question = $data['question'] ?? '';
        $answer = $data['answer'] ?? '';

        if (empty($question) || empty($answer)) {
            return $this->json(['error' => 'Missing question or answer parameter'], 400);
        }

        try {
            $improvedText = $this->anthropicService->improveAnswer($question, $answer);
            return $this->json([
                'improvedText' => $improvedText,
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

        $dataToSign = "chapterId=" . $chapterId . "&expires=" . $expires;
        $signature = hash_hmac('sha256', $dataToSign, $secret);

        $shareUrl = sprintf(
            'https://memoiresvivantes.arkanoa-media.com/memoires/shared/books/%s/chapters/%s?expires=%d&signature=%s&chapterId=%s',
            $bookId,
            $chapterId,
            $expires,
            $signature,
            $chapterId
        );

        return $this->json([
            'url' => $shareUrl
        ]);
    }

    private function validateSignatureOrGrant(string $attribute, Chapter $chapter, Request $request): ?JsonResponse
    {
        $expires = $request->query->get('expires');
        $signature = $request->query->get('signature');
        $chapterId = $request->query->get('chapterId') ?? $request->query->get('chapter_id');

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
        }

        if ($expires === null || $signature === null || $chapterId === null) {
            $content = $request->getContent();
            if ($content) {
                $data = json_decode($content, true);
                if (is_array($data)) {
                    $expires = $expires ?? $data['expires'] ?? null;
                    $signature = $signature ?? $data['signature'] ?? null;
                    $chapterId = $chapterId ?? $data['chapterId'] ?? $data['chapter_id'] ?? null;
                }
            }
        }

        if ($expires === null || $signature === null || $chapterId === null) {
            $expires = $request->headers->get('X-Expires');
            $signature = $request->headers->get('X-Signature');
            $chapterId = $chapterId ?? $request->headers->get('X-Chapter-Id');
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
                }
            }
        }

        $hasValidSignature = false;
        if ($expires !== null && $signature !== null && $chapterId !== null) {
            if (time() <= (int)$expires) {
                $secret = $this->getParameter('kernel.secret');
                $dataToSign = "chapterId=" . $chapterId . "&expires=" . $expires;
                $expectedSignature = hash_hmac('sha256', $dataToSign, $secret);

                if (hash_equals($expectedSignature, $signature) && (string)$chapter->getId() === $chapterId) {
                    $hasValidSignature = true;
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
            } catch (\Symfony\Component\Security\Core\Exception\AccessDeniedException $e) {
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
}
