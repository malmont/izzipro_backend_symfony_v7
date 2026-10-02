<?php

namespace App\MemoiresVivantes\BookType;

use App\MemoiresVivantes\Entity\Book;
use App\MemoiresVivantes\Entity\BookType;
use App\MemoiresVivantes\Entity\BookTypeChapter;
use App\MemoiresVivantes\Entity\Chapter;
use App\MemoiresVivantes\Services\ChapterQuestionProvider;
use App\Services\AnthropicService;

/**
 * Moteur de génération des types de livre dont les consignes sont en base (promptSource = database).
 *
 * Le prompt envoyé à l'IA est assemblé ainsi :
 *   1. consigne du type (saisie par l'admin, variables remplacées)
 *   2. consigne du chapitre, si présente
 *   3. contexte du livre (titre, chapitre, interlocuteurs, participation des sujets)
 *   4. matériau : réponses, témoignages des contributeurs, ou tout le livre pour une synthèse
 *   5. cadre technique fixe, partie 1 ou 2 (format attendu par l'affichage et le PDF)
 */
class DatabasePromptEngine
{
    public const VARIABLES = [
        '{prenom1}' => 'Prénom de l\'interlocuteur 1 (ou de la personne honorée)',
        '{prenom2}' => 'Prénom de l\'interlocuteur 2',
        '{lieu_naissance1}' => 'Lieu de naissance de l\'interlocuteur 1',
        '{lieu_naissance2}' => 'Lieu de naissance de l\'interlocuteur 2',
        '{sujets}' => '« Prénom1 et Prénom2 », ou le prénom seul, ou à défaut le titre du livre',
        '{titre_livre}' => 'Titre du livre',
        '{dates}' => 'Années de naissance et de décès, ex. « (1932 - 2021) »',
        '{exergue}' => 'Phrase d\'exergue choisie par la famille (vide si aucune)',
        '{titre_chapitre}' => 'Titre du chapitre en cours',
        '{ton}' => 'Ton choisi à la génération (par défaut « intime et chaleureux »)',
    ];

    private const DEFAULT_TONE = 'intime et chaleureux';

    /** Longueur (caractères) jusqu'à laquelle la première partie est redonnée en entier à l'IA pour écrire la seconde */
    private const FULL_PART1_MAX_LENGTH = 8000;

    /** Réponse convenue de l'IA quand la première partie a tout traité et qu'aucune conclusion n'est attendue */
    public const NOTHING_TO_ADD = '[RIEN À AJOUTER]';

    /** La seconde partie est-elle la réponse convenue « rien à ajouter » (à ne jamais enregistrer dans le chapitre) ? */
    public static function isNothingToAdd(?string $text): bool
    {
        $text = trim((string) $text, " \t\n\r\0\x0B*_\"'.");

        return $text !== '' && mb_strlen($text) < 40 && preg_match('/^\[?\s*rien [àa] ajouter\s*\]?$/iu', $text) === 1;
    }

    public function __construct(
        private readonly AnthropicService $anthropic
    ) {}

    public function generate(Chapter $chapter, BookType $type, int $part, ?string $tone = null, ?string $model = null): string
    {
        return $this->anthropic->complete($this->buildPrompt($chapter, $type, $part, $tone), 32000, null, $model);
    }

    /**
     * Un chapitre de synthèse ne peut être généré qu'une fois des témoignages saisis dans le livre.
     *
     * @return array{canGenerate: bool, reason: ?string}
     */
    public function checkCanGenerate(Chapter $chapter, BookType $type): array
    {
        $template = $type->getChapter((string) $chapter->getTheme());
        if ($template === null || !$template->isSynthesis()) {
            return ['canGenerate' => true, 'reason' => null];
        }

        foreach ($chapter->getBook()->getChapters() as $bookChapter) {
            if (ChapterQuestionProvider::answeredQuestionTexts($bookChapter)) {
                return ['canGenerate' => true, 'reason' => null];
            }
        }

        return ['canGenerate' => false, 'reason' => 'Au moins un témoignage doit être saisi dans le livre avant de pouvoir générer ce chapitre de synthèse.'];
    }

    public function buildPrompt(Chapter $chapter, BookType $type, int $part, ?string $tone = null): string
    {
        $book = $chapter->getBook();
        $template = $type->getChapter((string) $chapter->getTheme());
        $variables = $this->variables($book, $chapter, $tone ?: self::DEFAULT_TONE);

        $sections = [];

        $typePrompt = $this->substitute($type->getEffectivePrompt(), $variables);
        if ($typePrompt !== '') {
            $sections[] = $typePrompt;
        }
        $chapterPrompt = $this->substitute($template?->getEffectivePrompt(), $variables);
        if ($chapterPrompt !== '') {
            $sections[] = "CONSIGNES PROPRES À CE CHAPITRE :\n" . $chapterPrompt;
        }

        $sections[] = "CONTEXTE DU LIVRE :\n" . implode("\n", $this->contextLines($book, $chapter, $type, $template, $variables));
        $sections[] = $this->material($chapter, $type, $template, $variables);

        if ($part === 1) {
            $sections[] = "CONSIGNES TECHNIQUES — PARTIE 1/2 :\n" .
                "- Respecte les consignes ci-dessus pour le fond, le style et le registre.\n" .
                "- Reste strictement fidèle aux informations fournies : n'invente jamais de lieux, de personnes ou d'événements non mentionnés.\n" .
                "- Le volume dépend de la richesse du matériau — ne répète jamais une scène ou une idée pour allonger le texte.\n" .
                "- PAS de titre général — commence directement par le texte.\n" .
                "- Chaque paragraphe séparé par une ligne vide et terminé par un point complet.\n" .
                "- Sous-titres (tous les 4 à 6 paragraphes, sauf consigne contraire) sous la forme exacte : ===Titre===\n" .
                "- Arrête-toi à la fin d'un paragraphe — jamais au milieu.\n" .
                "- Une 2e partie suivra — ne conclus pas encore.";
        } else {
            // Première partie courte : transmise en entier. Avec ses seuls derniers paragraphes, l'IA ne sait pas ce
            // qui a déjà été traité et recommençait un témoignage (chapitre court, modèle économique).
            $part1 = trim((string) $chapter->getContentPart1());
            if (mb_strlen($part1) <= self::FULL_PART1_MAX_LENGTH) {
                $sections[] = "Voici la première partie, EN ENTIER (ne la répète PAS) :\n\"\"\"\n{$part1}\n\"\"\"\n\n" .
                    "IMPORTANT : tout ce qui précède a déjà été écrit. Ne répète, ne reformule, ne réécris AUCUNE scène ni AUCUN témoignage déjà traité.";
            } else {
                $lastParagraphs = $this->anthropic->extractLastParagraphs($part1, 3);
                $sections[] = "Voici la fin de la première partie (ne la répète PAS) :\n\"\"\"\n{$lastParagraphs}\n\"\"\"\n\n" .
                    "IMPORTANT : tout ce qui précède a déjà été écrit. Ne répète, ne reformule, ne réécris AUCUNE scène déjà traitée.";
            }
            $sections[] = "CONSIGNES TECHNIQUES — PARTIE 2/2 :\n" .
                "- Respecte les consignes ci-dessus pour le fond, le style et le registre.\n" .
                "- Continue UNIQUEMENT avec le matériau qui n'a pas encore été traité.\n" .
                "- Si tout a déjà été traité : rédige uniquement la conclusion demandée par les consignes (ou, à défaut, un beau paragraphe de conclusion) et arrête-toi.\n" .
                "- Seulement si les consignes interdisent toute conclusion et que tout a déjà été traité, réponds exactement et uniquement : " . self::NOTHING_TO_ADD . "\n" .
                "- N'invente aucun élément non fourni.\n" .
                "- Même style, sous-titres sous la forme exacte : ===Titre===\n" .
                "- Termine par un paragraphe terminé par un point complet.";
        }

        return implode("\n\n", $sections);
    }

    /**
     * @return array<string, string>
     */
    public function variables(Book $book, Chapter $chapter, string $tone): array
    {
        $prenom1 = AnthropicService::narratorFirstName($book) ?? '';
        $prenom2 = $book->getPerson2FirstName() ?: '';
        $sujets = ($prenom1 && $prenom2) ? "{$prenom1} et {$prenom2}" : ($prenom1 ?: (string) $book->getTitle());
        $dates = ($book->getBirthYear() || $book->getDeathYear()) ? "({$book->getBirthYear()} - {$book->getDeathYear()})" : '';

        return [
            '{prenom1}' => $prenom1 ?: 'la personne',
            '{prenom2}' => $prenom2 ?: 'son conjoint',
            '{lieu_naissance1}' => $book->getPerson1Birthplace() ?: ($book->getBirthplace() ?: 'un lieu cher à son cœur'),
            '{lieu_naissance2}' => $book->getPerson2Birthplace() ?: 'un lieu cher à son cœur',
            '{sujets}' => $sujets,
            '{titre_livre}' => (string) $book->getTitle(),
            '{dates}' => $dates,
            '{exergue}' => $book->getEpigraph() ? "Phrase d'exergue choisie par la famille : « {$book->getEpigraph()} »" : '',
            '{titre_chapitre}' => (string) $chapter->getTitle(),
            '{ton}' => $tone,
        ];
    }

    private function substitute(?string $text, array $variables): string
    {
        if ($text === null || trim($text) === '') {
            return '';
        }
        $text = strtr($text, $variables);
        // Variables vides (ex. {exergue}) : pas de lignes blanches en trop
        $text = preg_replace("/[ \t]+\n/", "\n", $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }

    private function contextLines(Book $book, Chapter $chapter, BookType $type, ?BookTypeChapter $template, array $variables): array
    {
        $lines = [
            "- Titre du livre : {$variables['{titre_livre}']}",
            "- Chapitre en cours : \"{$variables['{titre_chapitre}']}\"",
        ];

        if ($type->isDirect()) {
            $speaker = $template?->getSpeaker() ?? BookTypeChapter::SPEAKER_PERSON1;
            $lines[] = match ($speaker) {
                BookTypeChapter::SPEAKER_PERSON2 => "- Ce chapitre ne concerne que {$variables['{prenom2}']} (né(e) à {$variables['{lieu_naissance2}']}).",
                BookTypeChapter::SPEAKER_BOTH => "- Ce chapitre raconte {$variables['{prenom1}']} (né(e) à {$variables['{lieu_naissance1}']}) et {$variables['{prenom2}']} (né(e) à {$variables['{lieu_naissance2}']}).",
                default => "- Ce chapitre ne concerne que {$variables['{prenom1}']} (né(e) à {$variables['{lieu_naissance1}']}).",
            };
        } else {
            $lines[] = "- Le livre est consacré à : {$variables['{sujets}']} {$variables['{dates}']}";
            if ($type->isSubjectsMayBeAbsent()) {
                $absent = $book->isParentsDeceased() || $book->isParentsNotParticipating();
                $lines[] = '- Les sujets du livre participent eux-mêmes : ' . ($absent ? 'non (décédés ou non participants)' : 'oui');
            }
            if ($variables['{exergue}'] !== '') {
                $lines[] = "- {$variables['{exergue}']}";
            }
        }

        return $lines;
    }

    private function material(Chapter $chapter, BookType $type, ?BookTypeChapter $template, array $variables): string
    {
        $speaker = $template?->getSpeaker() ?? ($type->isDirect() ? BookTypeChapter::SPEAKER_PERSON1 : BookTypeChapter::SPEAKER_CONTRIBUTORS);

        if ($speaker === BookTypeChapter::SPEAKER_SYNTHESIS) {
            $blocks = [];
            $chapters = $chapter->getBook()->getChapters()->toArray();
            usort($chapters, fn (Chapter $a, Chapter $b) => $a->getPosition() <=> $b->getPosition());
            foreach ($chapters as $bookChapter) {
                $content = $this->chapterMaterial($bookChapter);
                if ($content !== '') {
                    $blocks[] = "--- Chapitre « {$bookChapter->getTitle()} » ---\n{$content}";
                }
            }
            return "TÉMOIGNAGES ET SOUVENIRS RECUEILLIS DANS TOUT LE LIVRE :\n\n" . ($blocks ? implode("\n\n", $blocks) : '(aucun témoignage)');
        }

        if ($speaker === BookTypeChapter::SPEAKER_CONTRIBUTORS) {
            return "TÉMOIGNAGES POUR CE CHAPITRE :\n\n" . ($this->chapterMaterial($chapter) ?: '(aucun témoignage)');
        }

        $who = match ($speaker) {
            BookTypeChapter::SPEAKER_PERSON2 => $variables['{prenom2}'],
            BookTypeChapter::SPEAKER_BOTH => "{$variables['{prenom1}']} et {$variables['{prenom2}']}",
            default => $variables['{prenom1}'],
        };
        $answers = $this->anthropic->formatAnswers($chapter->getAnswers());

        return "RÉPONSES DE {$who} POUR CE CHAPITRE (questions et réponses) :\n\n" . ($answers ?: '(aucune réponse)');
    }

    /** Réponses directes du chapitre + témoignages de ses contributeurs */
    private function chapterMaterial(Chapter $chapter): string
    {
        $parts = [];
        $answers = $this->anthropic->formatAnswers($chapter->getAnswers());
        if ($answers !== '') {
            $parts[] = $answers;
        }
        $testimonies = $this->anthropic->formatContributorTestimonies($chapter->getContributorAnswers() ?? []);
        if ($testimonies !== '') {
            $parts[] = $testimonies;
        }

        return implode("\n\n", $parts);
    }
}
