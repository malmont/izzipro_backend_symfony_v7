<?php

namespace App\MemoiresVivantes\UseCase;

use App\MemoiresVivantes\Dto\ChapterInputDto;
use App\MemoiresVivantes\Entity\Chapter;
use App\MemoiresVivantes\Message\GenerateChapterMessage;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\Messenger\MessageBusInterface;

class UpdateChapterUseCase
{
    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly MessageBusInterface $messageBus
    ) {}

    public function execute(Chapter $chapter, array $data, bool $triggerGenerate = false, string $tenantHost = ''): Chapter
    {
        $em = $this->emProvider->getEntityManager();

        if (isset($data['title'])) $chapter->setTitle($data['title']);
        if (isset($data['theme'])) $chapter->setTheme($data['theme']);
        if (isset($data['position'])) $chapter->setPosition((int)$data['position']);
        if (isset($data['answers'])) {
            $existingAnswers = $chapter->getAnswers();
            $newAnswers = $data['answers'];
            
            if (is_array($existingAnswers) && is_array($newAnswers)) {
                $existingMap = [];
                foreach ($existingAnswers as $extAns) {
                    if (is_array($extAns) && isset($extAns['index'])) {
                        $existingMap[(string)$extAns['index']] = $extAns;
                    }
                }
                
                foreach ($newAnswers as &$newAns) {
                    if (is_array($newAns)) {
                        // Normalize improved_answer to improvedAnswer
                        if (isset($newAns['improved_answer'])) {
                            $newAns['improvedAnswer'] = $newAns['improved_answer'];
                            unset($newAns['improved_answer']);
                        }
                        
                        if (isset($newAns['index'])) {
                            $idx = (string)$newAns['index'];
                            if (isset($existingMap[$idx])) {
                                $extAns = $existingMap[$idx];
                                foreach (['audioUrl', 'audioUrl1', 'audioUrl2', 'improvedAnswer'] as $key) {
                                    $newVal = $newAns[$key] ?? '';
                                    $extVal = $extAns[$key] ?? '';
                                    if (($newVal === null || $newVal === '') && $extVal !== null && $extVal !== '') {
                                        $newAns[$key] = $extVal;
                                    }
                                }
                            }
                        }
                    }
                }
                unset($newAns);
            }
            
            $chapter->setAnswers($newAnswers);
        }
        if (isset($data['contributorAnswers'])) {
            $existingContributors = $chapter->getContributorAnswers();
            $newContributors = $data['contributorAnswers'];
            
            if (is_array($existingContributors) && is_array($newContributors)) {
                $existingMap = [];
                foreach ($existingContributors as $extContrib) {
                    if (is_array($extContrib)) {
                        $key = $extContrib['id'] ?? $extContrib['contributorName'] ?? $extContrib['firstName'] ?? null;
                        if ($key !== null) {
                            $existingMap[(string)$key] = $extContrib;
                        }
                    }
                }
                
                foreach ($newContributors as &$newContrib) {
                    if (is_array($newContrib)) {
                        $key = $newContrib['id'] ?? $newContrib['contributorName'] ?? $newContrib['firstName'] ?? null;
                        if ($key !== null && isset($existingMap[(string)$key])) {
                            $extContrib = $existingMap[(string)$key];

                            foreach (['improvedQuestionIndices', 'improvedQuestionKeys', 'improvedCount'] as $trackKey) {
                                if (isset($extContrib[$trackKey]) && !isset($newContrib[$trackKey])) {
                                    $newContrib[$trackKey] = $extContrib[$trackKey];
                                }
                            }
                            
                            if (isset($newContrib['answers']) && is_array($newContrib['answers']) &&
                                isset($extContrib['answers']) && is_array($extContrib['answers'])) {
                                
                                $extAnswersMap = [];
                                foreach ($extContrib['answers'] as $extAns) {
                                    if (is_array($extAns) && isset($extAns['index'])) {
                                        $extAnswersMap[(string)$extAns['index']] = $extAns;
                                    }
                                }
                                
                                foreach ($newContrib['answers'] as &$newAns) {
                                    if (is_array($newAns)) {
                                        // Normalize improved_answer to improvedAnswer
                                        if (isset($newAns['improved_answer'])) {
                                            $newAns['improvedAnswer'] = $newAns['improved_answer'];
                                            unset($newAns['improved_answer']);
                                        }
                                        
                                        if (isset($newAns['index'])) {
                                            $idx = (string)$newAns['index'];
                                            if (isset($extAnswersMap[$idx])) {
                                                $extAns = $extAnswersMap[$idx];
                                                foreach (['audioUrl', 'audioUrl1', 'audioUrl2', 'improvedAnswer'] as $audioKey) {
                                                    $newVal = $newAns[$audioKey] ?? '';
                                                    $extVal = $extAns[$audioKey] ?? '';
                                                    if (($newVal === null || $newVal === '') && $extVal !== null && $extVal !== '') {
                                                        $newAns[$audioKey] = $extVal;
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                                unset($newAns);
                            }

                            // Réponses déjà enregistrées que l'envoi ne mentionne pas : conservées (voir keepUnmentionedAnswers)
                            if (is_array($extContrib['answers'] ?? null)) {
                                $newContrib['answers'] = self::keepUnmentionedAnswers($newContrib['answers'] ?? null, $extContrib['answers']);
                            }
                        }
                    }
                }
                unset($newContrib);
            }

            if (is_array($newContributors)) {
                $newContributors = $this->keepMissingContributors($chapter, $newContributors);
            }

            $chapter->setContributorAnswers($newContributors);
        }
        if (isset($data['contentFinal'])) $chapter->setContentFinal($data['contentFinal']);

        $em->flush();

        if ($triggerGenerate) {
            $chapter->setGenerationStatus('pending');
            $em->flush();
            $this->messageBus->dispatch(new GenerateChapterMessage((string) $chapter->getId(), 1, $tenantHost));
        }

        return $chapter;
    }

    /**
     * Les réponses d'un contributeur renvoyées par l'API sont filtrées sur les questions du rôle demandé : la page
     * du propriétaire ne reçoit pas les réponses aux questions des autres rôles, et les effaçait en enregistrant.
     * Une réponse existante que l'envoi ne mentionne pas (question absente) est donc conservée ; pour effacer une
     * réponse, il faut l'envoyer avec un texte vide.
     */
    private static function keepUnmentionedAnswers(mixed $submitted, mixed $existing): mixed
    {
        if (!is_array($existing) || $existing === []) {
            return $submitted;
        }
        if (!is_array($submitted)) {
            return $existing;
        }

        $questions = [];
        $indexes = [];
        foreach ($submitted as $answer) {
            if (!is_array($answer)) {
                continue;
            }
            if (is_string($answer['question'] ?? null) && trim($answer['question']) !== '') {
                $questions[trim($answer['question'])] = true;
            } elseif (isset($answer['index']) && is_scalar($answer['index'])) {
                $indexes[(string) $answer['index']] = true;
            }
        }

        foreach ($existing as $answer) {
            if (!is_array($answer)) {
                continue;
            }
            $question = is_string($answer['question'] ?? null) ? trim($answer['question']) : '';
            $mentioned = $question !== ''
                ? isset($questions[$question])
                : (isset($answer['index']) && is_scalar($answer['index']) && isset($indexes[(string) $answer['index']]));
            if (!$mentioned) {
                $submitted[] = $answer;
            }
        }

        return $submitted;
    }

    /**
     * Un enregistrement envoie toute la liste des témoignages telle que la page l'a chargée : si un contributeur a
     * répondu entre-temps, sa réponse n'y figure pas et serait effacée. Les témoignages des contributeurs du livre
     * absents de l'envoi sont donc conservés ; pour retirer un témoignage, on retire le contributeur du livre.
     */
    private function keepMissingContributors(Chapter $chapter, array $submitted): array
    {
        $existing = $chapter->getContributorAnswers();
        if (!is_array($existing) || $existing === []) {
            return $submitted;
        }

        $bookContributorIds = [];
        foreach ($chapter->getBook()?->getContributors() ?? [] as $contributor) {
            $bookContributorIds[strtolower((string) $contributor->getId())] = true;
        }

        $submittedIds = [];
        $submittedNames = [];
        foreach ($submitted as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (isset($entry['id']) && is_scalar($entry['id'])) {
                $submittedIds[strtolower((string) $entry['id'])] = true;
            }
            $name = $entry['contributorName'] ?? $entry['firstName'] ?? null;
            if (is_string($name) && $name !== '') {
                $submittedNames[mb_strtolower($name)] = true;
            }
        }

        foreach ($existing as $entry) {
            $id = is_array($entry) && isset($entry['id']) && is_scalar($entry['id']) ? strtolower((string) $entry['id']) : null;
            if ($id === null || !isset($bookContributorIds[$id]) || isset($submittedIds[$id])) {
                continue;
            }
            $name = $entry['contributorName'] ?? $entry['firstName'] ?? null;
            if (is_string($name) && isset($submittedNames[mb_strtolower($name)])) {
                continue;
            }
            $submitted[] = $entry;
        }

        return $submitted;
    }
}
