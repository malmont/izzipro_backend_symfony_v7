<?php

namespace App\Services;

use App\MemoiresVivantes\Entity\Chapter;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;

class AnthropicService
{
    private const MODEL = 'claude-sonnet-4-6';
    private const API_URL = 'https://api.anthropic.com/v1/messages';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $anthropicApiKey,
        private readonly LoggerInterface $logger
    ) {}

    public function generatePart1(Chapter $chapter, string $tone = 'intime et chaleureux'): string
    {
        $book = $chapter->getBook();
        $prenom = $book->getUser()->getFirstname();
        $birthplace = $book->getBirthplace() ?? 'un lieu cher à votre cœur';
        $themeTitle = $chapter->getTitle();
        $answersFormatted = $this->formatAnswers($chapter->getAnswers());

        $prompt = "Tu es un écrivain biographe de talent, spécialisé dans les mémoires de vie. La personne s'appelle {$prenom}, née à {$birthplace}.\n\n" .
                  "Voici ses réponses pour le chapitre \"{$themeTitle}\" :\n{$answersFormatted}\n\n" .
                  "CONSIGNES PARTIE 1/2 :\n" .
                  "- Développe CHAQUE réponse en profondeur : contexte, émotions, souvenirs associés, ambiances\n" .
                  "- Le volume dépend de la richesse des réponses — ne pas répéter pour atteindre un quota\n" .
                  "- Si les réponses sont courtes, concentre-toi sur le ressenti, la réflexion et les émotions pour développer le récit sans inventer de nouveaux faits ou événements non mentionnés\n" .
                  "- Reste strictement fidèle aux informations fournies : n'invente jamais de lieux, de personnes ou d'événements majeurs\n" .
                  "- Minimum 3 paragraphes par réponse\n" .
                  "- N'ÉCRIS JAMAIS deux fois la même scène ou la même idée\n" .
                  "- Style littéraire, première personne (je), {$tone}\n" .
                  "- PAS de titre — commence directement par le récit\n" .
                  "- Chaque paragraphe séparé par une ligne vide\n" .
                  "- Sous-titres poétiques toutes les 5-6 paragraphes : ===Titre===\n" .
                  "- Termine TOUJOURS chaque paragraphe par un point complet\n" .
                  "- Arrête-toi à la fin d'un paragraphe — jamais au milieu\n" .
                  "- Une 2e partie suivra — pas besoin de conclure";

        return $this->callAnthropic($prompt, 32000);
    }

    public function generatePart2(Chapter $chapter, string $tone = 'intime et chaleureux'): string
    {
        $book = $chapter->getBook();
        $prenom = $book->getUser()->getFirstname();
        $birthplace = $book->getBirthplace() ?? 'un lieu cher à votre cœur';
        $themeTitle = $chapter->getTitle();
        $answersFormatted = $this->formatAnswers($chapter->getAnswers());
        
        $contentPart1 = $chapter->getContentPart1() ?? '';
        $lastParagraphs = $this->extractLastParagraphs($contentPart1, 3);

        $prompt = "Tu es un écrivain biographe de talent, spécialisé dans les mémoires de vie. La personne s'appelle {$prenom}, née à {$birthplace}.\n\n" .
                  "Voici ses réponses pour le chapitre \"{$themeTitle}\" :\n{$answersFormatted}\n\n" .
                  "Voici la fin de la première partie (ne la répète PAS) :\n\"\"\"\n{$lastParagraphs}\n\"\"\"\n\n" .
                  "IMPORTANT : tout ce qui précède a déjà été écrit. Ne répète, ne reformule, ne réécris AUCUNE scène déjà traitée. Si le matériel est épuisé, conclus élégamment en quelques paragraphes.\n\n" .
                  "CONSIGNES PARTIE 2/2 :\n" .
                  "- Continue UNIQUEMENT si tu as du matériel nouveau\n" .
                  "- Si toutes les réponses ont déjà été traitées en partie 1, rédige UNIQUEMENT un beau paragraphe de conclusion et arrête-toi\n" .
                  "- Ne jamais reformuler ce qui a déjà été dit\n" .
                  "- Qualité et authenticité avant quantité : reste fidèle aux souvenirs partagés\n" .
                  "- Ne pas inventer de nouveaux éléments de vie non fournis dans les réponses\n" .
                  "- Même style, mêmes sous-titres ===...===\n" .
                  "- Termine par un beau paragraphe de conclusion avec un point";

        return $this->callAnthropic($prompt, 32000);
    }

    public function generatePart1Couple(Chapter $chapter, string $tone = 'intime et chaleureux'): string
    {
        $book = $chapter->getBook();
        $prenom1 = $book->getPerson1FirstName() ?? ($book->getUser() ? $book->getUser()->getFirstname() : 'la première personne');
        $birthplace1 = $book->getPerson1Birthplace() ?? $book->getBirthplace() ?? 'un lieu cher à son cœur';
        $prenom2 = $book->getPerson2FirstName() ?? 'son conjoint';
        $birthplace2 = $book->getPerson2Birthplace() ?? 'un lieu cher à son cœur';
        $themeTitle = $chapter->getTitle();
        $theme = $chapter->getTheme();
        $answersFormatted = $this->formatAnswers($chapter->getAnswers());

        if ($theme === 'avant_nous_1') {
            $prompt = "Tu es un écrivain biographe de talent, spécialisé dans les mémoires de vie. La personne s'appelle {$prenom1}, née à {$birthplace1}.\n" .
                      "Il s'agit d'un chapitre d'introduction décrivant sa vie avant sa rencontre commune avec {$prenom2}.\n\n" .
                      "Voici ses réponses pour le chapitre \"{$themeTitle}\" :\n{$answersFormatted}\n\n" .
                      "CONSIGNES PARTIE 1/2 :\n" .
                      "- Développe CHAQUE réponse en profondeur : contexte, émotions, souvenirs associés, ambiances\n" .
                      "- Le volume dépend de la richesse des réponses fournies — ne pas répéter pour atteindre un quota\n" .
                      "- Si les réponses sont courtes, concentre-toi sur le ressenti, la réflexion et les émotions pour développer le récit sans inventer de nouveaux faits ou événements non mentionnés\n" .
                      "- Reste strictly fidèle aux informations fournies : n'invente jamais de faits non mentionnés\n" .
                      "- Minimum 3 paragraphes par réponse\n" .
                      "- N'ÉCRIS JAMAIS deux fois la même scène ou la même idée\n" .
                      "- Style littéraire, première personne (je), {$tone}\n" .
                      "- PAS de titre — commence directement par le récit\n" .
                      "- Chaque paragraphe séparé par une ligne vide\n" .
                      "- Sous-titres poétiques toutes les 5-6 paragraphes : ===Titre===\n" .
                      "- Termine TOUJOURS chaque paragraphe par un point complet\n" .
                      "- Arrête-toi à la fin d'un paragraphe — jamais au milieu\n" .
                      "- Une 2e partie suivra — pas besoin de conclure";
        } elseif ($theme === 'avant_nous_2') {
            $prompt = "Tu es un écrivain biographe de talent, spécialisé dans les mémoires de vie. La personne s'appelle {$prenom2}, née à {$birthplace2}.\n" .
                      "Il s'agit d'un chapitre d'introduction décrivant sa vie avant sa rencontre commune avec {$prenom1}.\n\n" .
                      "Voici ses réponses pour le chapitre \"{$themeTitle}\" :\n{$answersFormatted}\n\n" .
                      "CONSIGNES PARTIE 1/2 :\n" .
                      "- Développe CHAQUE réponse en profondeur : contexte, émotions, souvenirs associés, ambiances\n" .
                      "- Le volume dépend de la richesse des réponses fournies — ne pas répéter pour atteindre un quota\n" .
                      "- Si les réponses sont courtes, concentre-toi sur le ressenti, la réflexion et les émotions pour développer le récit sans inventer de nouveaux faits ou événements non mentionnés\n" .
                      "- Reste strictement fidèle aux informations fournies : n'invente jamais de faits non mentionnés\n" .
                      "- Minimum 3 paragraphes par réponse\n" .
                      "- N'ÉCRIS JAMAIS deux fois la même scène ou la même idée\n" .
                      "- Style littéraire, première personne (je), {$tone}\n" .
                      "- PAS de titre — commence directement par le récit\n" .
                      "- Chaque paragraphe séparé par une ligne vide\n" .
                      "- Sous-titres poétiques toutes les 5-6 paragraphes : ===Titre===\n" .
                      "- Termine TOUJOURS chaque paragraphe par un point complet\n" .
                      "- Arrête-toi à la fin d'un paragraphe — jamais au milieu\n" .
                      "- Une 2e partie suivra — pas besoin de conclure";
        } else {
            $prompt = "Tu es un écrivain biographe de talent, spécialisé dans les mémoires de vie. Raconte l'histoire du couple formé par {$prenom1} (né(e) à {$birthplace1}) et {$prenom2} (né(e) à {$birthplace2}).\n\n" .
                      "Voici leurs réponses pour le chapitre \"{$themeTitle}\" (formatées sous forme de questions et réponses) :\n{$answersFormatted}\n\n" .
                      "CONSIGNES PARTIE 1/2 :\n" .
                      "- Tisse les réponses des deux personnes ensemble de manière fluide. Écris à la troisième personne du pluriel (ils/elles) pour raconter leur histoire commune, ou alterne les voix à la première personne en fonction de qui s'exprime dans les réponses.\n" .
                      "- Développe chaque réponse en profondeur : contexte, émotions, souvenirs associés, ambiances\n" .
                      "- Le volume dépend de la richesse des réponses fournies — ne pas répéter pour atteindre un quota\n" .
                      "- Si les réponses sont courtes, concentre-toi sur le ressenti, la réflexion commune et les émotions pour développer le récit sans inventer de nouveaux faits ou événements non mentionnés\n" .
                      "- Minimum 3 paragraphes par réponse\n" .
                      "- N'ÉCRIS JAMAIS deux fois la même scène ou la même idée, et ne raconte pas la même scène sous deux angles redondants si les réponses n'apportent aucun élément nouveau\n" .
                      "- Style littéraire, {$tone}\n" .
                      "- PAS de titre — commence directement par le récit\n" .
                      "- Chaque paragraphe séparé par une ligne vide\n" .
                      "- Sous-titres poétiques toutes les 5-6 paragraphes : ===Titre===\n" .
                      "- Termine TOUJOURS chaque paragraphe par un point complet\n" .
                      "- Arrête-toi à la fin d'un paragraphe — jamais au milieu\n" .
                      "- Une 2e partie suivra — pas besoin de conclure";
        }

        return $this->callAnthropic($prompt, 32000);
    }

    public function generatePart2Couple(Chapter $chapter, string $tone = 'intime et chaleureux'): string
    {
        $book = $chapter->getBook();
        $prenom1 = $book->getPerson1FirstName() ?? ($book->getUser() ? $book->getUser()->getFirstname() : 'la première personne');
        $birthplace1 = $book->getPerson1Birthplace() ?? $book->getBirthplace() ?? 'un lieu cher à son cœur';
        $prenom2 = $book->getPerson2FirstName() ?? 'son conjoint';
        $birthplace2 = $book->getPerson2Birthplace() ?? 'un lieu cher à son cœur';
        $themeTitle = $chapter->getTitle();
        $theme = $chapter->getTheme();
        $answersFormatted = $this->formatAnswers($chapter->getAnswers());
        
        $contentPart1 = $chapter->getContentPart1() ?? '';
        $lastParagraphs = $this->extractLastParagraphs($contentPart1, 3);

        if ($theme === 'avant_nous_1') {
            $prompt = "Tu es un écrivain biographe de talent, spécialisé dans les mémoires de vie. La personne s'appelle {$prenom1}, née à {$birthplace1}.\n" .
                      "Il s'agit d'un chapitre d'introduction décrivant sa vie avant sa rencontre commune avec {$prenom2}.\n\n" .
                      "Voici ses réponses pour le chapitre \"{$themeTitle}\" :\n{$answersFormatted}\n\n" .
                      "Voici la fin de la première partie (ne la répète PAS) :\n\"\"\"\n{$lastParagraphs}\n\"\"\"\n\n" .
                      "IMPORTANT : tout ce qui précède a déjà été écrit. Ne répète, ne reformule, ne réécris AUCUNE scène déjà traitée. Si le matériel est épuisé, conclus élégamment en quelques paragraphes.\n\n" .
                      "CONSIGNES PARTIE 2/2 :\n" .
                      "- Continue UNIQUEMENT si tu as du matériel nouveau\n" .
                      "- Si toutes les réponses ont déjà été traitées en partie 1, rédige UNIQUEMENT un beau paragraphe de conclusion et arrête-toi\n" .
                      "- Ne jamais reformuler ce qui a déjà été dit\n" .
                      "- Qualité et authenticité avant quantité : reste fidèle aux souvenirs partagés\n" .
                      "- Ne pas inventer de nouveaux éléments de vie non fournis dans les réponses\n" .
                      "- Même style, mêmes sous-titres ===...===\n" .
                      "- Termine par un beau paragraphe de conclusion avec un point";
        } elseif ($theme === 'avant_nous_2') {
            $prompt = "Tu es un écrivain biographe de talent, spécialisé dans les mémoires de vie. La personne s'appelle {$prenom2}, née à {$birthplace2}.\n" .
                      "Il s'agit d'un chapitre d'introduction décrivant sa vie avant sa rencontre commune avec {$prenom1}.\n\n" .
                      "Voici ses réponses pour le chapitre \"{$themeTitle}\" :\n{$answersFormatted}\n\n" .
                      "Voici la fin de la première partie (ne la répète PAS) :\n\"\"\"\n{$lastParagraphs}\n\"\"\"\n\n" .
                      "IMPORTANT : tout ce qui précède a déjà été écrit. Ne répète, ne reformule, ne réécris AUCUNE scène déjà traitée. Si le matériel est épuisé, conclus élégamment en quelques paragraphes.\n\n" .
                      "CONSIGNES PARTIE 2/2 :\n" .
                      "- Continue UNIQUEMENT si tu as du matériel nouveau\n" .
                      "- Si toutes les réponses ont déjà été traitées en partie 1, rédige UNIQUEMENT un beau paragraphe de conclusion et arrête-toi\n" .
                      "- Ne jamais reformuler ce qui a déjà été dit\n" .
                      "- Qualité et authenticité avant quantité : reste fidèle aux souvenirs partagés\n" .
                      "- Ne pas inventer de nouveaux éléments de vie non fournis dans les réponses\n" .
                      "- Même style, mêmes sous-titres ===...===\n" .
                      "- Termine par un beau paragraphe de conclusion avec un point";
        } else {
            $prompt = "Tu es un écrivain biographe de talent, spécialisé dans les mémoires de vie. Raconte l'histoire du couple formé par {$prenom1} (né(e) à {$birthplace1}) et {$prenom2} (né(e) à {$birthplace2}).\n\n" .
                      "Voici leurs réponses pour le chapitre \"{$themeTitle}\" :\n{$answersFormatted}\n\n" .
                      "Voici la fin de la première partie (ne la répète PAS) :\n\"\"\"\n{$lastParagraphs}\n\"\"\"\n\n" .
                      "IMPORTANT : tout ce qui précède a déjà été écrit. Ne répète, ne reformule, ne réécris AUCUNE scène déjà traitée. Si le matériel est épuisé, conclus élégamment en quelques paragraphes.\n\n" .
                      "CONSIGNES PARTIE 2/2 :\n" .
                      "- Continue UNIQUEMENT si tu as du matériel nouveau\n" .
                      "- Si toutes les réponses ont déjà été traitées en partie 1, rédige UNIQUEMENT un beau paragraphe de conclusion et arrête-toi\n" .
                      "- Ne jamais reformuler ce qui a déjà été dit\n" .
                      "- Qualité et authenticité avant quantité : reste fidèle aux souvenirs partagés\n" .
                      "- Ne pas inventer de nouveaux éléments de vie non fournis dans les réponses\n" .
                      "- Même style, mêmes sous-titres ===...===\n" .
                      "- Termine par un beau paragraphe de conclusion avec un point";
        }

        return $this->callAnthropic($prompt, 32000);
    }

    public function generatePart1Famille(Chapter $chapter, string $tone = 'intime et chaleureux'): string
    {
        $theme = $chapter->getTheme();
        if ($theme === 'histoire_aine') {
            return $this->generatePart1($chapter, $tone);
        }

        $themeTitle = $chapter->getTitle();
        if ($theme === 'regards_croises' || $theme === 'regards_petits_enfants') {
            $contributorAnswers = $chapter->getContributorAnswers() ?? [];
            $formattedTestimonies = "";
            foreach ($contributorAnswers as $contrib) {
                $name = $contrib['contributorName'] ?? $contrib['firstName'] ?? 'Un proche';
                $role = $contrib['role'] ?? 'proche';
                $answers = $contrib['answers'] ?? [];
                $contribAnswersFormatted = $this->formatAnswers($answers);
                
                $formattedTestimonies .= "=== Témoignage de {$name} ({$role}) ===\n{$contribAnswersFormatted}\n\n";
            }

            $prompt = "Tu es un écrivain biographe de talent, spécialisé dans les récits de famille. Voici les témoignages des différents membres de la famille concernant la personne célébrée pour le chapitre \"{$themeTitle}\" :\n\n" .
                      "{$formattedTestimonies}\n" .
                      "CONSIGNES PARTIE 1/2 :\n" .
                      "- Rédige le témoignage de CHAQUE contributeur séparément sous son propre sous-titre poétique (ex: ===Témoignage de [Prénom]===)\n" .
                      "- Écris à la première personne du singulier (je) du point de vue de chaque contributeur\n" .
                      "- Ne mélange jamais les témoignages entre eux, garde-les bien distincts\n" .
                      "- Développe en profondeur les anecdotes et émotions transmises par chaque personne\n" .
                      "- Le volume dépend de la richesse des réponses fournies — ne pas répéter pour atteindre un quota\n" .
                      "- Si les réponses sont courtes, concentre-toi sur le ressenti, l'affection et les émotions pour développer le récit sans inventer de nouveaux faits non mentionnés. C'est particulièrement vrai pour les témoignages des petits-enfants qui sont très courts par design : ne boucle jamais sur le même souvenir ou la même idée pour remplir l'espace.\n" .
                      "- Minimum 3 paragraphes par témoignage/contributeur\n" .
                      "- N'ÉCRIS JAMAIS deux fois la même scène ou la même idée\n" .
                      "- Style chaleureux, vivant et littéraire, {$tone}\n" .
                      "- PAS de titre général — commence directement par le premier témoignage\n" .
                      "- Chaque paragraphe séparé par une ligne vide\n" .
                      "- Termine TOUJOURS chaque paragraphe par un point complet\n" .
                      "- Une 2e partie suivra — pas besoin de conclure";
        } else {
            $answersFormatted = $this->formatAnswers($chapter->getAnswers());

            $prompt = "Tu es un écrivain biographe de talent. Rédige l'épilogue collectif de la famille pour le chapitre \"{$themeTitle}\".\n\n" .
                      "Voici leurs réponses :\n{$answersFormatted}\n\n" .
                      "CONSIGNES PARTIE 1/2 :\n" .
                      "- Rédige une lettre ou un message collectif court, extrêmement chaleureux et émotionnel au nom de toute la famille\n" .
                      "- Le volume dépend de la richesse des réponses fournies — ne pas répéter pour atteindre un quota\n" .
                      "- Si les réponses sont courtes, concentre-toi sur le message d'amour, la gratitude et les émotions collectives sans inventer de nouveaux faits non mentionnés\n" .
                      "- Minimum 3 paragraphes\n" .
                      "- N'ÉCRIS JAMAIS deux fois la même scène ou la même idée\n" .
                      "- Style chaleureux, vivant et poétique, {$tone}\n" .
                      "- PAS de titre — commence directement par le texte\n" .
                      "- Termine par un point complet\n" .
                      "- Une 2e partie suivra — pas besoin de conclure";
        }

        return $this->callAnthropic($prompt, 32000);
    }

    public function generatePart2Famille(Chapter $chapter, string $tone = 'intime et chaleureux'): string
    {
        $theme = $chapter->getTheme();
        if ($theme === 'histoire_aine') {
            return $this->generatePart2($chapter, $tone);
        }

        $themeTitle = $chapter->getTitle();
        $contentPart1 = $chapter->getContentPart1() ?? '';
        $lastParagraphs = $this->extractLastParagraphs($contentPart1, 3);

        if ($theme === 'regards_croises' || $theme === 'regards_petits_enfants') {
            $contributorAnswers = $chapter->getContributorAnswers() ?? [];
            $formattedTestimonies = "";
            foreach ($contributorAnswers as $contrib) {
                $name = $contrib['contributorName'] ?? $contrib['firstName'] ?? 'Un proche';
                $role = $contrib['role'] ?? 'proche';
                $answers = $contrib['answers'] ?? [];
                $contribAnswersFormatted = $this->formatAnswers($answers);
                
                $formattedTestimonies .= "=== Témoignage de {$name} ({$role}) ===\n{$contribAnswersFormatted}\n\n";
            }

            $prompt = "Tu es un écrivain biographe de talent, spécialisé dans les récits de famille. Continue la rédaction des témoignages pour le chapitre \"{$themeTitle}\" :\n\n" .
                      "{$formattedTestimonies}\n\n" .
                      "Voici la fin de la première partie (ne la répète PAS) :\n\"\"\"\n{$lastParagraphs}\n\"\"\"\n\n" .
                      "IMPORTANT : tout ce qui précède a déjà été écrit. Ne répète, ne reformule, ne réécris AUCUNE scène déjà traitée. Si le matériel est épuisé, conclus élégamment en quelques paragraphes.\n\n" .
                      "CONSIGNES PARTIE 2/2 :\n" .
                      "- Continue uniquement s'il reste des éléments non traités ou s'il y a du matériel nouveau\n" .
                      "- Si toutes les réponses ont déjà été traitées en partie 1, rédiges uniquement un ou deux beaux paragraphes de conclusion générale chaleureuse pour l'ensemble du chapitre et arrête-toi (ne crée aucun nouveau sous-titre ===...===)\n" .
                      "- Ne jamais reformuler ce qui a déjà été dit\n" .
                      "- Qualité et authenticité avant quantité : reste fidèle aux souvenirs et émotions des contributeurs sans inventer de nouveaux éléments de vie non fournis\n" .
                      "- Même style (si tu continues un témoignage entamé en partie 1, réutilise le même sous-titre === Témoignage de [Prénom] ===)\n" .
                      "- Si aucun nouveau témoignage n'est nécessaire, ne crée aucun sous-titre et finis directement par la conclusion générale.";
        } else {
            $answersFormatted = $this->formatAnswers($chapter->getAnswers());

            $prompt = "Tu es un écrivain biographe de talent. Continue la rédaction de l'épilogue collectif de la famille pour le chapitre \"{$themeTitle}\" :\n\n" .
                      "Voici leurs réponses :\n{$answersFormatted}\n\n" .
                      "Voici la fin de la première partie (ne la répète PAS) :\n\"\"\"\n{$lastParagraphs}\n\"\"\"\n\n" .
                      "IMPORTANT : tout ce qui précède a déjà été écrit. Ne répète, ne reformule, ne réécris AUCUNE scène déjà traitée. Si le matériel est épuisé, conclus élégamment en quelques paragraphes.\n\n" .
                      "CONSIGNES PARTIE 2/2 :\n" .
                      "- Continue uniquement s'il y a du matériel nouveau, sinon conclus avec un beau paragraphe final\n" .
                      "- Ne jamais reformuler ce qui a déjà été dit\n" .
                      "- Qualité et authenticité avant quantité : reste fidèle aux émotions exprimées par la famille\n" .
                      "- Termine par un beau paragraphe de conclusion avec un point";
        }

        return $this->callAnthropic($prompt, 32000);
    }

    public function checkAndComplete(string $text, bool $isLastPart): string
    {
        $trimmed = trim($text);
        if (empty($trimmed)) {
            return $text;
        }

        $lastChar = substr($trimmed, -1);
        if (!in_array($lastChar, ['.', '!', '?', '"', '\''])) {
            $prompt = "Voici un texte inachevé :\n\"\"\"\n{$text}\n\"\"\"\n\n" .
                      "Continue et termine uniquement la phrase en cours (ou le paragraphe en cours) pour qu'il se termine par un point. " .
                      "Ne réécris pas le texte précédent, donne juste la suite manquante.";
            
            $suite = $this->callAnthropic($prompt, 300);
            return $text . $suite;
        }

        return $text;
    }

    private function extractLastParagraphs(string $text, int $count): string
    {
        $paragraphs = array_filter(array_map('trim', explode("\n\n", str_replace("\r", "", $text))));
        if (empty($paragraphs)) {
            return "";
        }
        $last = array_slice($paragraphs, -$count);
        return implode("\n\n", $last);
    }

    public function improveAnswer(string $question, string $answer): string
    {
        $systemPrompt = "Agis en tant que biographe chaleureux et poétique. Prends la réponse brute de l'utilisateur à la question posée, corrige les fautes d'orthographe, restructure les phrases de manière fluide et vivante tout en conservant scrupuleusement la vérité historique et les faits originaux de l'utilisateur. Retourne uniquement la version améliorée à la première personne (Je).";
        
        $prompt = "Question : " . $question . "\nRéponse brute de l'utilisateur : " . $answer;

        return $this->callAnthropic($prompt, 2000, $systemPrompt);
    }

    private function callAnthropic(string $prompt, int $maxTokens = 32000, ?string $system = null): string
    {
        $apiKey = trim($this->anthropicApiKey, " \t\n\r\0\x0B\"");
        
        try {
            $this->logger->info("Anthropic Request", ['model' => self::MODEL, 'max_tokens' => $maxTokens]);

            $jsonPayload = [
                'model' => self::MODEL,
                'max_tokens' => $maxTokens,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt]
                ],
            ];

            if ($system !== null) {
                $jsonPayload['system'] = $system;
            }

            $response = $this->httpClient->request('POST', self::API_URL, [
                'headers' => [
                    'x-api-key' => $apiKey,
                    'anthropic-version' => '2023-06-01',
                    'Content-Type' => 'application/json',
                ],
                'json' => $jsonPayload,
                'timeout' => 300,
            ]);

            if ($response->getStatusCode() !== 200) {
                $errorContent = $response->getContent(false);
                $this->logger->error("Anthropic Error Output: " . $errorContent);
                return "";
            }

            $data = $response->toArray();
            return $data['content'][0]['text'] ?? '';

        } catch (\Exception $e) {
            $this->logger->error("Anthropic Exception: " . $e->getMessage());
            throw $e;
        }
    }

    private function formatAnswers(array $answers): string
    {
        $formatted = "";
        foreach ($answers as $answer) {
            if (is_array($answer)) {
                if (!empty($answer['skipped'])) {
                    continue;
                }
                
                $ansVal = (isset($answer['improvedAnswer']) && $answer['improvedAnswer'] !== '')
                    ? $answer['improvedAnswer']
                    : ((isset($answer['improved_answer']) && $answer['improved_answer'] !== '')
                        ? $answer['improved_answer']
                        : ($answer['answer'] ?? ''));

                $formatted .= "Q: " . ($answer['question'] ?? '') . "\nR: " . $ansVal . "\n\n";
            } elseif (is_string($answer)) {
                $formatted .= "R: $answer\n\n";
            }
        }
        return trim($formatted);
    }
}
