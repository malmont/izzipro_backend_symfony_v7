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

    public function generatePart1Famille(Chapter $chapter, string $tone = 'intime et chaleureux', ?array $aggregatedContext = null): string
    {
        $book = $chapter->getBook();
        $theme = $chapter->getTheme();
        $themeTitle = $chapter->getTitle();
        $parent1 = $book->getPerson1FirstName() ?: 'notre père';
        $parent2 = $book->getPerson2FirstName() ?: 'notre mère';
        $parentsLabel = ($book->getPerson1FirstName() && $book->getPerson2FirstName())
            ? "{$parent1} et {$parent2}"
            : ($book->getPerson1FirstName() ?: $book->getTitle());

        $isParentsDeceased = ($book && ($book->isParentsDeceased() || $book->isParentsNotParticipating()))
            || (empty($chapter->getAnswers()) && !empty($chapter->getContributorAnswers()));

        // Chapitre 1 : Histoire des parents & Nos racines (Synthèse 3e personne)
        if ($theme === 'histoire_parents' || $theme === 'histoire_aine') {
            $contextText = $aggregatedContext['formattedContext'] ?? '';
            if (empty(trim($contextText))) {
                $contextText = $this->formatAnswers($chapter->getAnswers());
                if (empty(trim($contextText)) && !empty($chapter->getContributorAnswers())) {
                    $contextText = $this->formatContributorTestimonies($chapter->getContributorAnswers());
                }
            }

            $editorialDirectives = $isParentsDeceased
                ? "- ANGLE NARRATIF D'HOMMAGE ET TRANSMISSION (PARENTS DÉCÉDÉS OU NON-PARTICIPANTS) :\n" .
                  "  * Ce chapitre est un hommage filial rédigé à partir des récits, anecdotes et souvenirs transmis à leurs enfants et descendants.\n" .
                  "  * Registre de SYNTHÈSE STRICTEMENT À LA 3ÈME PERSONNE (« il/elle » ou « {$parent1} et {$parent2} »).\n" .
                  "  * RÈGLE FORMELLE : Ne JAMAIS rédiger à la 1ère personne du couple (« Nous nous sommes rencontrés... »).\n" .
                  "  * Adopte des formules élégantes de mémoire familiale et de transmission (« Dans les souvenirs transmis au foyer... », « Leurs enfants se rappellent avec émotion de... », « Selon la mémoire familiale... »).\n" .
                  "  * Retrace la jeunesse de chacun, la légende de leur rencontre telle que transmise avec amour au sein de la famille, et l'installation de leur premier chez-soi.\n"
                : "- Registre de SYNTHÈSE à la 3ème personne (il/elle ou {$parent1} et {$parent2}).\n" .
                  "- Retrace le début de cette histoire d'amour et la fondation de leur famille : les origines de chacun, leur jeunesse, les récits de leur rencontre (tels que racontés par eux-mêmes ou transmis avec affection par leurs enfants), leurs premiers temps ensemble et l'installation de leur premier chez-soi.\n";

            $prompt = "Tu es un écrivain biographe de premier ordre, spécialisé dans les sagas familiales et les mémoires de famille.\n" .
                      "Tu rédiges le premier chapitre \"{$themeTitle}\" consacré à l'histoire des parents et aux racines du foyer de {$parentsLabel}.\n\n" .
                      "Voici les témoignages et souvenirs recueillis auprès de la famille :\n\n" .
                      "{$contextText}\n\n" .
                      "CONSIGNES ÉDITORIALES PARTIE 1/2 :\n" .
                      $editorialDirectives .
                      "- RÈGLE D'OR : Si les enfants rapportent des détails ou versions légèrement différentes de la rencontre, ne tranche JAMAIS : tisse les récits comme la légende chaleureuse de la famille (« Pour l'un, c'était... tandis que pour l'autre, demeure le souvenir de... »).\n" .
                      "- Volume proportionnel aux souvenirs réels fournis — ne boucle jamais sur la même idée.\n" .
                      "- Sous-titres poétiques toutes les 4 à 6 paragraphes sous la forme : ===Titre poétique===\n" .
                      "- Style littéraire, émouvant, chaleureux et respectueux, {$tone}.\n" .
                      "- PAS de titre général — commence directement par le premier paragraphe du récit.\n" .
                      "- Chaque paragraphe séparé par une ligne vide et terminé par un point complet.\n" .
                      "- Une 2e partie suivra — pas besoin de conclure pour l'instant.";

            return $this->callAnthropic($prompt, 32000);
        }

        // Chapitre 2 & 3 : Regards croisés (Paroles d'enfants) et Petits-enfants (100% Verbatim)
        if ($theme === 'regards_croises' || $theme === 'regards_petits_enfants') {
            $contributorAnswers = $chapter->getContributorAnswers() ?? [];
            $formattedTestimonies = $this->formatContributorTestimonies($contributorAnswers);
            if (empty(trim($formattedTestimonies))) {
                $formattedTestimonies = $this->formatAnswers($chapter->getAnswers());
            }

            $isGrandchildren = ($theme === 'regards_petits_enfants');
            $roleLabel = $isGrandchildren ? "les petits-enfants" : "les enfants";

            $prompt = "Tu es un écrivain biographe de talent, spécialisé dans les récits de famille. Tu rédiges le recueil de témoignages \"{$themeTitle}\" réunissant les souvenirs de {$roleLabel} envers {$parentsLabel} :\n\n" .
                      "{$formattedTestimonies}\n\n" .
                      "CONSIGNES ÉDITORIALES PARTIE 1/2 :\n" .
                      "- Registre 100% VERBATIM à la 1ère personne du singulier (je) pour chaque contributeur.\n" .
                      "- Chaque participant a son propre espace bien distinct sous son sous-titre poétique et nominatif (ex: === Témoignage de [Prénom] ([Rôle]) ===).\n" .
                      "- Ne mélange JAMAIS les témoignages entre eux : garde la singularité, l'âge et la sensibilité de chacun.\n" .
                      "- Développe en profondeur les anecdotes concrètes, les souvenirs d'enfance et les émotions partagées.\n" .
                      ($isGrandchildren
                          ? "- Pour les petits-enfants, les souvenirs sont souvent courts et tendres : ne boucle jamais sur la même idée pour allonger artificiellement le texte. Privilégie la fraîcheur et la vérité du cœur.\n"
                          : "- Développe les souvenirs avec le père et avec la mère, l'ambiance du foyer et les valeurs transmises.\n") .
                      "- Style chaleureux, vivant et littéraire, {$tone}.\n" .
                      "- PAS de titre général — commence directement par le premier témoignage.\n" .
                      "- Une 2e partie suivra — pas besoin de conclure.";

            return $this->callAnthropic($prompt, 32000);
        }

        // Chapitre 4 : Rituels et valeurs (Hybride / Mixte)
        if ($theme === 'rituels_et_valeurs') {
            $contributorAnswers = $chapter->getContributorAnswers() ?? [];
            $formattedTestimonies = $this->formatContributorTestimonies($contributorAnswers);
            if (empty(trim($formattedTestimonies))) {
                $formattedTestimonies = $this->formatAnswers($chapter->getAnswers());
            }

            $prompt = "Tu es un écrivain biographe de grand talent. Tu rédiges le chapitre \"{$themeTitle}\" célébrant les traditions, rituels et valeurs qui font l'âme de cette famille :\n\n" .
                      "{$formattedTestimonies}\n\n" .
                      "CONSIGNES ÉDITORIALES PARTIE 1/2 :\n" .
                      "- Registre HYBRIDE mêlant fragments de témoignages directs des membres de la famille et récit narratif chaleureux.\n" .
                      "- Fais revivre les grands rituels du foyer : les repas du dimanche, les recettes fétiches, les vacances inoubliables, les répliques cultes et expressions de la maison, ainsi que les valeurs fondamentales transmises par les parents.\n" .
                      "- Insère des sous-titres poétiques tous les 4 à 5 paragraphes (ex: === Autour de la table ===, === Les vacances qui nous unissent ===, === Ce qui nous a été transmis ===).\n" .
                      "- Style vivant, plein de saveur, réconfortant et joyeux, {$tone}.\n" .
                      "- Une 2e partie suivra.";

            return $this->callAnthropic($prompt, 32000);
        }

        // Chapitre 5 : Épilogue collectif / Lettre d'amour
        $contributorAnswers = $chapter->getContributorAnswers() ?? [];
        $formattedTestimonies = $this->formatContributorTestimonies($contributorAnswers);
        if (empty(trim($formattedTestimonies))) {
            $formattedTestimonies = $this->formatAnswers($chapter->getAnswers());
        }

        $epilogueDirectives = $isParentsDeceased
            ? "- Rédige une lettre collective ou des hommages successifs au nom des enfants et petits-enfants, célébrant la mémoire de {$parentsLabel}, leur reconnaissance infinie pour leur amour et leur exemple, et affirmant la fidélité de la famille à leurs valeurs.\n"
            : "- Rédige une lettre collective ou des messages successifs de chaque enfant/proche sous forme de déclaration d'amour, de gratitude et de fierté envers les parents.\n";

        $prompt = "Tu es un écrivain biographe de talent. Tu rédiges l'épilogue intime et vibrant \"{$themeTitle}\" adressé à {$parentsLabel} au nom de toute la famille :\n\n" .
                  "{$formattedTestimonies}\n\n" .
                  "CONSIGNES ÉDITORIALES PARTIE 1/2 :\n" .
                  $epilogueDirectives .
                  "- Style profondément émouvant, chaleureux et noble, célébrant le bonheur d'avoir grandi auprès d'eux et formulant des vœux pour la pérennité du clan familial.\n" .
                  "- Une 2e partie suivra.";

        return $this->callAnthropic($prompt, 32000);
    }

    public function generatePart2Famille(Chapter $chapter, string $tone = 'intime et chaleureux', ?array $aggregatedContext = null): string
    {
        $book = $chapter->getBook();
        $theme = $chapter->getTheme();
        $themeTitle = $chapter->getTitle();
        $parent1 = $book->getPerson1FirstName() ?: 'notre père';
        $parent2 = $book->getPerson2FirstName() ?: 'notre mère';
        $parentsLabel = ($book->getPerson1FirstName() && $book->getPerson2FirstName())
            ? "{$parent1} et {$parent2}"
            : ($book->getPerson1FirstName() ?: $book->getTitle());

        $isParentsDeceased = ($book && ($book->isParentsDeceased() || $book->isParentsNotParticipating()))
            || (empty($chapter->getAnswers()) && !empty($chapter->getContributorAnswers()));

        $contentPart1 = $chapter->getContentPart1() ?? '';
        $lastParagraphs = $this->extractLastParagraphs($contentPart1, 3);

        // Chapitre 1 : Histoire des parents
        if ($theme === 'histoire_parents' || $theme === 'histoire_aine') {
            $contextText = $aggregatedContext['formattedContext'] ?? '';
            if (empty(trim($contextText))) {
                $contextText = $this->formatAnswers($chapter->getAnswers());
            }

            $part2Directives = $isParentsDeceased
                ? "- ANGLE NARRATIF D'HOMMAGE ET TRANSMISSION :\n" .
                  "  * Poursuis l'histoire de la famille, l'arrivée des enfants, les traditions et valeurs de la maison, et les souvenirs marquants de leur vie de famille.\n" .
                  "  * Conclus par un vibrant et magnifique hommage à la mémoire de {$parentsLabel}, à la force de leur union et à l'héritage d'amour indestructible qu'ils ont légué à leurs descendants.\n"
                : "- Poursuis la fondation du foyer et la vie avec les enfants, les étapes marquantes traversées ensemble, et le regard admiratif porté sur leur histoire.\n" .
                  "- Conclus par un magnifique paragraphe d'hommage à l'amour et au foyer qu'ils ont su bâtir.\n";

            $prompt = "Tu es un écrivain biographe d'exception. Continue la rédaction du récit \"{$themeTitle}\" sur l'histoire de {$parentsLabel} :\n\n" .
                      "{$contextText}\n\n" .
                      "Voici la fin de la première partie (ne la répète PAS) :\n\"\"\"\n{$lastParagraphs}\n\"\"\"\n\n" .
                      "IMPORTANT : tout ce qui précède a déjà été écrit. Ne répète, ne reformule, ne réécris AUCUNE période déjà abordée.\n\n" .
                      "CONSIGNES PARTIE 2/2 :\n" .
                      $part2Directives .
                      "- Termine par un point complet.";

            return $this->callAnthropic($prompt, 32000);
        }

        // Chapitres 2 & 3 : Regards croisés & Petits-enfants
        if ($theme === 'regards_croises' || $theme === 'regards_petits_enfants') {
            $contributorAnswers = $chapter->getContributorAnswers() ?? [];
            $formattedTestimonies = $this->formatContributorTestimonies($contributorAnswers);
            if (empty(trim($formattedTestimonies))) {
                $formattedTestimonies = $this->formatAnswers($chapter->getAnswers());
            }

            $prompt = "Tu es un écrivain biographe de talent, spécialisé dans les récits de famille. Continue la rédaction des témoignages pour le chapitre \"{$themeTitle}\" :\n\n" .
                      "{$formattedTestimonies}\n\n" .
                      "Voici la fin de la première partie (ne la répète PAS) :\n\"\"\"\n{$lastParagraphs}\n\"\"\"\n\n" .
                      "IMPORTANT : tout ce qui précède a déjà été écrit. Ne répète, ne reformule, ne réécris AUCUN témoignage déjà traité.\n\n" .
                      "CONSIGNES PARTIE 2/2 :\n" .
                      "- Continue uniquement s'il reste des témoignages de contributeurs non traités en partie 1.\n" .
                      "- Si toutes les personnes ont déjà été traitées, rédige uniquement un beau paragraphe de conclusion générale chaleureuse pour clore le chapitre (sans nouveau sous-titre).\n" .
                      "- Même style, termine par un point complet.";

            return $this->callAnthropic($prompt, 32000);
        }

        // Chapitres 4 & 5 : Rituels / Épilogue
        $answersFormatted = $this->formatContributorTestimonies($chapter->getContributorAnswers() ?? []);
        if (empty(trim($answersFormatted))) {
            $answersFormatted = $this->formatAnswers($chapter->getAnswers());
        }

        $prompt = "Tu es un écrivain biographe de talent. Continue la rédaction pour le chapitre \"{$themeTitle}\" :\n\n" .
                  "{$answersFormatted}\n\n" .
                  "Voici la fin de la première partie (ne la répète PAS) :\n\"\"\"\n{$lastParagraphs}\n\"\"\"\n\n" .
                  "IMPORTANT : tout ce qui précède a déjà été écrit. Ne répète, ne reformule, ne réécris AUCUNE scène déjà traitée.\n\n" .
                  "CONSIGNES PARTIE 2/2 :\n" .
                  "- Continue uniquement s'il reste du matériel nouveau, puis termine par une émouvante conclusion au nom de toute la famille.\n" .
                  "- Termine par un beau paragraphe de conclusion avec un point complet.";

            return $this->callAnthropic($prompt, 32000);
        }

    public function generatePart1Hommage(Chapter $chapter, string $tone = 'intime et chaleureux', ?array $aggregatedContext = null): string
    {
        $book = $chapter->getBook();
        $theme = $chapter->getTheme();
        $themeTitle = $chapter->getTitle();
        $deceasedName = $book->getPerson1FirstName() ?: $book->getTitle();
        $birthYear = $book->getBirthYear();
        $deathYear = $book->getDeathYear();
        $datesInfo = ($birthYear || $deathYear) ? "({$birthYear} - {$deathYear})" : "";
        $epigraph = $book->getEpigraph() ? "Phrase d'exergue choisie par la famille : « {$book->getEpigraph()} »\n" : "";

        // Chapitres de SYNTHÈSE (3e personne) : portrait_croise (Ch 1) & une_vie (Ch 3)
        if ($theme === 'portrait_croise' || $theme === 'une_vie') {
            $contextText = $aggregatedContext['formattedContext'] ?? '';
            if (empty(trim($contextText))) {
                $contextText = $this->formatAnswers($chapter->getAnswers());
            }

            if ($theme === 'portrait_croise') {
                $prompt = "Tu es un écrivain biographe d'exception, spécialisé dans les livres d'hommage et récits de mémoire familiale.\n" .
                          "Tu rédiges le premier chapitre \"{$themeTitle}\" dédié à {$deceasedName} {$datesInfo}.\n" .
                          ($epigraph ? "{$epigraph}\n" : "\n") .
                          "Voici l'ensemble des témoignages recueillis auprès de ses proches :\n\n" .
                          "{$contextText}\n\n" .
                          "CONSIGNES ÉDITORIALES IMPÉRATIVES (PARTIE 1/2) :\n" .
                          "- Registre de SYNTHÈSE TRANSVERSALE à la 3ème personne (il/elle ou {$deceasedName}).\n" .
                          "- Rédige un portrait d'ensemble polyphonique, sensible et vivant de {$deceasedName}.\n" .
                          "- Tisse avec finesse et bienveillance les regards croisés de ses proches (conjoint, enfants, petits-enfants, amis, collègues).\n" .
                          "- Mets en valeur les convergences (ce qui fait l'unanimité : tempérament, voix, rire, manies attachantes, générosité) tout en accueillant les nuances selon les époques et le lien propre à chacun.\n" .
                          "- RÈGLE D'OR : Ne tranche JAMAIS entre des mémoires divergentes ou contradictoires (ex: deux enfants se souvenant différemment d'un même été). Tisse délicatement les versions (« Pour l'un, c'était l'été des cabanes... tandis que pour l'autre, demeure le souvenir du silence des sous-bois... »).\n" .
                          "- Respecte la vérité des relations complexes : ne pas édulcorer ou aseptiser artificiellement les liens parfois difficiles, mais leur conférer une tonalité digne, respectueuse et réparatrice.\n" .
                          "- Le volume doit être strictement proportionnel à la richesse du matériau fourni : ne tourne jamais en rond, ne boucle pas sur la même idée ou le même adjectif pour remplir de l'espace.\n" .
                          "- Insère des sous-titres poétiques tous les 4 à 6 paragraphes sous la forme : ===Titre poétique===\n" .
                          "- Style littéraire, noble, sensible et chaleureux, sans pathos excessif ni emphase larmoyante, {$tone}.\n" .
                          "- PAS de titre général du chapitre — commence directement par le premier paragraphe du récit.\n" .
                          "- Chaque paragraphe séparé par une ligne vide et terminé par un point complet.\n" .
                          "- Une 2e partie suivra — pas besoin de conclure pour l'instant.";
            } else {
                // 'une_vie'
                $prompt = "Tu es un écrivain biographe d'exception, spécialisé dans les récits de vie et mémoires d'hommage.\n" .
                          "Tu rédiges le grand récit biographique \"{$themeTitle}\" retraçant l'existence de {$deceasedName} {$datesInfo}.\n" .
                          ($epigraph ? "{$epigraph}\n" : "\n") .
                          "Voici l'ensemble des repères et souvenirs biographiques transmis par ses proches :\n\n" .
                          "{$contextText}\n\n" .
                          "CONSIGNES ÉDITORIALES IMPÉRATIVES (PARTIE 1/2) :\n" .
                          "- Registre de SYNTHÈSE CHRONOLOGIQUE à la 3ème personne (il/elle ou {$deceasedName}).\n" .
                          "- Reconstitue la trajectoire de sa vie comme un roman vrai et sensible, fondé rigoureusement sur les faits transmis par les proches.\n" .
                          "- Traite dans cette première partie les origines, la jeunesse, l'entrée dans l'âge adulte, les racines familiales et les premières grandes étapes de sa vie.\n" .
                          "- N'invente pas d'événements majeurs non mentionnés : si une période a moins de souvenirs, concentre-toi sur l'atmosphère et les anecdotes réelles transmises sans délayer.\n" .
                          "- Tisse les mémoires des proches sans trancher en cas de divergences de perception.\n" .
                          "- Insère des sous-titres poétiques marquant les époques sous la forme : ===Titre poétique===\n" .
                          "- Style biographique de haute tenue littéraire, chaleureux et respectueux, {$tone}.\n" .
                          "- PAS de titre général du chapitre — commence directement par le premier paragraphe.\n" .
                          "- Chaque paragraphe séparé par une ligne vide et terminé par un point complet.\n" .
                          "- Une 2e partie suivra pour couvrir les décennies suivantes jusqu'à l'apaisement.";
            }

            return $this->callAnthropic($prompt, 32000);
        }

        // Chapitres VERBATIM ou HYBRIDES : les_voix (Ch 2), ce_quil_nous_laisse (Ch 4), ce_quon_aurait_voulu_dire (Ch 5)
        $contributorAnswers = $chapter->getContributorAnswers() ?? [];

        if ($theme === 'les_voix') {
            $formattedTestimonies = $this->formatContributorTestimonies($contributorAnswers);

            $prompt = "Tu es un écrivain biographe de grand talent. Tu rédiges le recueil de témoignages \"{$themeTitle}\" consacré à {$deceasedName} {$datesInfo}.\n\n" .
                      "Voici les témoignages des différents proches :\n\n" .
                      "{$formattedTestimonies}\n\n" .
                      "CONSIGNES ÉDITORIALES IMPÉRATIVES (PARTIE 1/2) :\n" .
                      "- Registre 100% VERBATIM à la 1ère personne du singulier (je) pour chaque contributeur.\n" .
                      "- Rédige le témoignage de CHAQUE contributeur séparément sous son sous-titre nominatif : === Témoignage de [Prénom] ([Rôle]) ===\n" .
                      "- Ne mélange JAMAIS les témoignages entre eux : préserve la voix, le lien et la sensibilité propre à chaque proche.\n" .
                      "- Développe en profondeur les anecdotes concrètes et les émotions partagées.\n" .
                      "- Volume proportionnel à la richesse des réponses fournies : ne tourne jamais en rond, ne boucle pas sur le même souvenir.\n" .
                      "- Pour les petits-enfants ou réponses plus courtes, privilégie l'émotion pure, la tendresse d'un geste ou d'un regard plutôt que de broder artificiellement.\n" .
                      "- Style vivant, intime et chaleureux, {$tone}.\n" .
                      "- PAS de titre général — commence directement par le premier témoignage.\n" .
                      "- Termine chaque paragraphe par un point complet.\n" .
                      "- Une 2e partie suivra — pas besoin de conclure.";

            return $this->callAnthropic($prompt, 32000);
        }

        if ($theme === 'ce_quon_aurait_voulu_dire') {
            $formattedTestimonies = $this->formatContributorTestimonies($contributorAnswers);

            $prompt = "Tu es un écrivain biographe de grand talent. Tu rédiges l'épilogue intime \"{$themeTitle}\" dédié à {$deceasedName} {$datesInfo}.\n\n" .
                      "Voici les messages et confidences confiés par les proches :\n\n" .
                      "{$formattedTestimonies}\n\n" .
                      "CONSIGNES ÉDITORIALES IMPÉRATIVES (PARTIE 1/2) :\n" .
                      "- Registre de MESSAGES DIRECTS adressés à {$deceasedName} (tutoiement ou vouvoiement selon la relation).\n" .
                      "- Présente le mot de chaque proche avec son sous-titre : === Pour toi, {$deceasedName} — De [Prénom] ([Rôle]) ===\n" .
                      "- Rédige des messages vibrants, sincères, émouvants et pudiques : ce qu'on n'a pas eu le temps de lui dire, la gratitude éternelle, une promesse, un souvenir indélébile.\n" .
                      "- Ne délaie pas : privilégie l'intensité, la sincérité et la justesse de chaque message.\n" .
                      "- Style littéraire, poétique, apaisé et profondément touchant, {$tone}.\n" .
                      "- PAS de titre général — commence directement par le premier message.\n" .
                      "- Une 2e partie suivra.";

            return $this->callAnthropic($prompt, 32000);
        }

        // 'ce_quil_nous_laisse' (héritage vivant, transmissions, gestes, expressions, valeurs)
        $formattedTestimonies = $this->formatContributorTestimonies($contributorAnswers);
        if (empty(trim($formattedTestimonies))) {
            $formattedTestimonies = $this->formatAnswers($chapter->getAnswers());
        }

        $prompt = "Tu es un écrivain biographe de grand talent. Tu rédiges le chapitre \"{$themeTitle}\" sur l'héritage vivant et les transmissions de {$deceasedName} {$datesInfo}.\n\n" .
                  "Voici les souvenirs et éléments transmis par les proches :\n\n" .
                  "{$formattedTestimonies}\n\n" .
                  "CONSIGNES ÉDITORIALES IMPÉRATIVES (PARTIE 1/2) :\n" .
                  "- Registre HYBRIDE mêlant fragments de témoignages directs et tissu narratif délicat.\n" .
                  "- Mets en lumière ce que {$deceasedName} a légué aux siens : ses expressions fétiches, ses habitudes et petits rituels, les gestes transmis, les passions partagées, ses valeurs fondamentales, des objets symboliques.\n" .
                  "- Célèbre ce qui continue de vivre à travers les proches, avec bienveillance et tendresse.\n" .
                  "- Insère des sous-titres poétiques tous les 4-5 paragraphes (ex: === Les mots qui demeurent ===, === Les gestes partagés ===...).\n" .
                  "- Style chaleureux, vivant, lumineux et réconfortant, {$tone}.\n" .
                  "- PAS de titre général — commence directement par le texte.\n" .
                  "- Une 2e partie suivra.";

        return $this->callAnthropic($prompt, 32000);
    }

    public function generatePart2Hommage(Chapter $chapter, string $tone = 'intime et chaleureux', ?array $aggregatedContext = null): string
    {
        $book = $chapter->getBook();
        $theme = $chapter->getTheme();
        $themeTitle = $chapter->getTitle();
        $deceasedName = $book->getPerson1FirstName() ?: $book->getTitle();
        $birthYear = $book->getBirthYear();
        $deathYear = $book->getDeathYear();
        $datesInfo = ($birthYear || $deathYear) ? "({$birthYear} - {$deathYear})" : "";
        $contentPart1 = $chapter->getContentPart1() ?? '';
        $lastParagraphs = $this->extractLastParagraphs($contentPart1, 3);

        // Chapitres de SYNTHÈSE (3e personne) : portrait_croise & une_vie
        if ($theme === 'portrait_croise' || $theme === 'une_vie') {
            $contextText = $aggregatedContext['formattedContext'] ?? '';
            if (empty(trim($contextText))) {
                $contextText = $this->formatAnswers($chapter->getAnswers());
            }

            if ($theme === 'portrait_croise') {
                $prompt = "Tu es un écrivain biographe d'exception. Tu continues la rédaction du chapitre de synthèse \"{$themeTitle}\" consacré à {$deceasedName} {$datesInfo}.\n\n" .
                          "Voici l'ensemble des témoignages recueillis :\n\n" .
                          "{$contextText}\n\n" .
                          "Voici la fin de la première partie (ne la répète PAS) :\n\"\"\"\n{$lastParagraphs}\n\"\"\"\n\n" .
                          "IMPORTANT : tout ce qui précède a déjà été écrit. Ne répète, ne reformule, ne réécris AUCUNE idée déjà abordée.\n\n" .
                          "CONSIGNES PARTIE 2/2 :\n" .
                          "- Continue UNIQUEMENT si tu as du matériel ou des facettes de sa personnalité non encore explorées.\n" .
                          "- Reste fidèle au registre de SYNTHÈSE à la 3ème personne, tissant les regards sans jamais trancher en cas de divergences.\n" .
                          "- Respecte la justesse des relations sans emphase excessive.\n" .
                          "- Même style, mêmes sous-titres poétiques ===Titre poétique=== si nécessaire.\n" .
                          "- Rédige une conclusion poignante, apaisée et digne pour clore ce portrait d'ensemble.\n" .
                          "- Termine par un point complet.";
            } else {
                // 'une_vie'
                $prompt = "Tu es un écrivain biographe d'exception. Tu poursuis le grand récit biographique \"{$themeTitle}\" de la vie de {$deceasedName} {$datesInfo}.\n\n" .
                          "Voici l'ensemble des repères et souvenirs biographiques :\n\n" .
                          "{$contextText}\n\n" .
                          "Voici la fin de la première partie (ne la répète PAS) :\n\"\"\"\n{$lastParagraphs}\n\"\"\"\n\n" .
                          "IMPORTANT : tout ce qui précède a déjà été écrit. Ne répète, ne reformule, ne réécris AUCUNE période déjà traitée.\n\n" .
                          "CONSIGNES PARTIE 2/2 :\n" .
                          "- Poursuis la chronologie : la maturité, les accomplissements, les liens familiaux consolidés, les passions de la seconde partie de vie, jusqu'aux dernières années dans la dignité et la paix.\n" .
                          "- Reste strictement fidèle aux souvenirs transmis par les siens sans rien inventer d'artificiel.\n" .
                          "- Termine par un magnifique passage d'hommage et de transmission concluant son parcours terrestre.\n" .
                          "- Même style romanesque et sensible, sous-titres poétiques ===Titre poétique===.\n" .
                          "- Termine par un point complet.";
            }

            return $this->callAnthropic($prompt, 32000);
        }

        // Chapitres VERBATIM ou HYBRIDES : les_voix, ce_quon_aurait_voulu_dire, ce_quil_nous_laisse
        $contributorAnswers = $chapter->getContributorAnswers() ?? [];

        if ($theme === 'les_voix') {
            $formattedTestimonies = $this->formatContributorTestimonies($contributorAnswers);

            $prompt = "Tu es un écrivain biographe de grand talent. Continue la rédaction des témoignages pour le recueil \"{$themeTitle}\" en hommage à {$deceasedName} {$datesInfo} :\n\n" .
                      "{$formattedTestimonies}\n\n" .
                      "Voici la fin de la première partie (ne la répète PAS) :\n\"\"\"\n{$lastParagraphs}\n\"\"\"\n\n" .
                      "IMPORTANT : tout ce qui précède a déjà été écrit. Ne répète, ne reformule, ne réécris AUCUN témoignage déjà traité.\n\n" .
                      "CONSIGNES PARTIE 2/2 :\n" .
                      "- Continue UNIQUEMENT s'il reste des témoignages de contributeurs non traités en partie 1.\n" .
                      "- Si tous les proches ont déjà été traités, rédige UNIQUEMENT un ou deux beaux paragraphes de conclusion chorale pleine de reconnaissance et arrête-toi (ne crée aucun sous-titre de témoignage supplémentaire).\n" .
                      "- Reste à la 1ère personne (je) pour les nouveaux témoignages sous === Témoignage de [Prénom] ([Rôle]) ===.\n" .
                      "- Termine par un point complet.";

            return $this->callAnthropic($prompt, 32000);
        }

        if ($theme === 'ce_quon_aurait_voulu_dire') {
            $formattedTestimonies = $this->formatContributorTestimonies($contributorAnswers);

            $prompt = "Tu es un écrivain biographe de grand talent. Continue la rédaction de l'épilogue intime \"{$themeTitle}\" dédié à {$deceasedName} {$datesInfo} :\n\n" .
                      "{$formattedTestimonies}\n\n" .
                      "Voici la fin de la première partie (ne la répète PAS) :\n\"\"\"\n{$lastParagraphs}\n\"\"\"\n\n" .
                      "IMPORTANT : tout ce qui précède a déjà été écrit. Ne répète aucun message déjà rédigé.\n\n" .
                      "CONSIGNES PARTIE 2/2 :\n" .
                      "- Rédige les messages des proches qui n'ont pas encore été traités en partie 1.\n" .
                      "- Si tous les messages ont été traités, rédige une phrase finale ou un court paragraphe d'adieu apaisé et termine.\n" .
                      "- Maintiens l'adresse directe avec le sous-titre : === Pour toi, {$deceasedName} — De [Prénom] ([Rôle]) ===\n" .
                      "- Termine par un point complet.";

            return $this->callAnthropic($prompt, 32000);
        }

        // 'ce_quil_nous_laisse'
        $formattedTestimonies = $this->formatContributorTestimonies($contributorAnswers);
        if (empty(trim($formattedTestimonies))) {
            $formattedTestimonies = $this->formatAnswers($chapter->getAnswers());
        }

        $prompt = "Tu es un écrivain biographe de premier ordre. Continue la rédaction du chapitre d'héritage \"{$themeTitle}\" pour {$deceasedName} {$datesInfo} :\n\n" .
                  "{$formattedTestimonies}\n\n" .
                  "Voici la fin de la première partie (ne la répète PAS) :\n\"\"\"\n{$lastParagraphs}\n\"\"\"\n\n" .
                  "IMPORTANT : tout ce qui précède a déjà été écrit. Ne répète aucune transmission déjà rédigée.\n\n" .
                  "CONSIGNES PARTIE 2/2 :\n" .
                  "- Continue uniquement s'il reste des dimensions d'héritage (objets, leçons, expressions, rituels) non traitées.\n" .
                  "- Conclus par un magnifique passage sur la pérennité de sa mémoire au sein des générations futures.\n" .
                  "- Style chaleureux, digne et vivant, {$tone}.\n" .
                  "- Termine par un point complet.";

        return $this->callAnthropic($prompt, 32000);
    }

    private function formatContributorTestimonies(array $contributorAnswers): string
    {
        $formatted = "";
        foreach ($contributorAnswers as $contrib) {
            $name = $contrib['contributorName'] ?? $contrib['firstName'] ?? 'Un proche';
            $role = $contrib['role'] ?? 'proche';
            $answers = $contrib['answers'] ?? [];
            $contribAnswersFormatted = $this->formatAnswers($answers);
            if (trim($contribAnswersFormatted) === '') continue;

            $formatted .= "=== Témoignage de {$name} ({$role}) ===\n{$contribAnswersFormatted}\n\n";
        }
        return trim($formatted);
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
