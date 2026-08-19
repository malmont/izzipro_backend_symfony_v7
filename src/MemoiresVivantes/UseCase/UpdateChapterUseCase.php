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
            error_log("DEBUG ANSWERS: " . json_encode($data['answers']));
            
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
                        }
                    }
                }
                unset($newContrib);
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
}
