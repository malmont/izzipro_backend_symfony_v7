<?php

namespace App\MemoiresVivantes\BookType;

use App\MemoiresVivantes\Entity\Book;
use App\MemoiresVivantes\Entity\BookType;
use App\MemoiresVivantes\Entity\BookTypeChapter;
use App\MemoiresVivantes\Entity\Chapter;
use App\MemoiresVivantes\Entity\MemoireQuestion;
use App\Services\AnthropicService;
use App\Services\TenantEntityManagerProvider;

/**
 * Aide à l'écriture des consignes des types de livre : amélioration par l'IA et test sur un livre fictif.
 * Rien n'est enregistré : l'admin valide lui-même la consigne optimisée.
 */
class BookTypePromptAssistant
{
    /** Le test est synchrone : il doit tenir sous le délai de 60 s de nginx */
    private const TEST_MAX_TOKENS = 2000;

    public function __construct(
        private readonly AnthropicService $anthropic,
        private readonly DatabasePromptEngine $engine,
        private readonly TenantEntityManagerProvider $emProvider
    ) {}

    /**
     * Propose une version améliorée d'une consigne (type ou chapitre).
     */
    public function optimize(BookType $type, ?BookTypeChapter $chapter, string $rawPrompt, ?string $model = null): string
    {
        if (trim($rawPrompt) === '') {
            throw BookTypeAdminException::invalid('La consigne à améliorer est vide.');
        }

        $variables = implode("\n", array_map(
            fn ($name, $desc) => "  {$name} : {$desc}",
            array_keys(DatabasePromptEngine::VARIABLES),
            DatabasePromptEngine::VARIABLES
        ));

        $family = $type->isDirect()
            ? "récit direct : {$type->getSpeakerCount()} interlocuteur(s) répondent eux-mêmes aux questions"
            : 'récit collectif : plusieurs contributeurs (' . implode(', ', array_map(fn ($r) => $r->getLabel(), $type->getRoles()->toArray())) . ') témoignent';

        $target = $chapter
            ? "la consigne PROPRE AU CHAPITRE « {$chapter->getTitle()} » (interlocuteur : {$this->speakerLabel($chapter->getSpeaker())}). Elle s'ajoute à la consigne générale du type, rappelée ci-dessous pour contexte ; ne la répète pas."
            : 'la consigne GÉNÉRALE du type, appliquée à tous ses chapitres.';

        $context = '';
        if ($chapter && $type->getEffectivePrompt()) {
            $context = "\n\nConsigne générale du type (pour contexte, à ne pas réécrire ni contredire) :\n\"\"\"\n{$type->getEffectivePrompt()}\n\"\"\"";
        } elseif (!$chapter) {
            $chapterPrompts = [];
            foreach ($type->getChapters() as $c) {
                if ($c->getEffectivePrompt()) {
                    $chapterPrompts[] = "- « {$c->getTitle()} » : " . str_replace("\n", ' ', $c->getEffectivePrompt());
                }
            }
            if ($chapterPrompts) {
                $context = "\n\nConsignes propres aux chapitres (elles priment pour leur chapitre : la consigne générale ne doit pas les contredire) :\n" . implode("\n", $chapterPrompts);
            }
        }

        $system = "Tu es un expert en rédaction de consignes (prompts) pour une IA écrivain biographe francophone, spécialisée dans les livres de mémoires. " .
            "Tu améliores les consignes rédigées par un administrateur sans en changer l'intention.";

        $prompt = "Type de livre : « {$type->getLabel()} » — {$family}.\n" .
            "Tu dois améliorer {$target}{$context}\n\n" .
            "Fonctionnement du moteur (à connaître, ne pas réécrire dans la consigne) :\n" .
            "- Le moteur ajoute automatiquement le contexte du livre, les réponses ou témoignages recueillis, et le cadre technique " .
            "(génération en 2 parties, format des paragraphes, sous-titres ===Titre===, interdiction d'inventer, pas de titre général).\n" .
            "- La consigne peut utiliser ces variables, remplacées automatiquement :\n{$variables}\n\n" .
            "Objectifs :\n" .
            "- Clarifier et structurer ce que l'administrateur demande : rôle de l'IA, ton, contenu à couvrir, points d'attention.\n" .
            "- Ne fixe PAS de narrateur, de voix (je / nous / il-elle), de registre (verbatim, synthèse, hybride) ou de structure que l'administrateur n'a pas demandés : ils relèvent des consignes de chapitre.\n" .
            "- Structurer en consignes courtes et actionnables (listes à tirets), préciser ce qui doit conclure le chapitre.\n" .
            "- Conserver toutes les intentions, exemples et règles de l'administrateur ; ne rien ajouter de contradictoire.\n" .
            "- Utiliser les variables pertinentes plutôt que des noms en dur.\n" .
            "- Ne pas répéter le cadre technique ajouté par le moteur.\n\n" .
            "Consigne de l'administrateur :\n\"\"\"\n{$rawPrompt}\n\"\"\"\n\n" .
            "Réponds UNIQUEMENT avec la consigne améliorée, en français, sans introduction ni commentaire.";

        $result = trim($this->anthropic->complete($prompt, 3000, $system, $model));
        if ($result === '') {
            throw BookTypeAdminException::conflict('L\'IA n\'a pas pu améliorer la consigne, réessayez.');
        }

        return $result;
    }

    /**
     * Génère un extrait de chapitre sur un livre fictif, avec la consigne en base du type (quelle que soit sa source active).
     *
     * @param array{chapterCode?: string, answers?: array, tone?: string, model?: string, dryRun?: bool, subjectsAbsent?: bool} $options
     */
    public function test(BookType $type, array $options): array
    {
        $template = $type->getChapter((string) ($options['chapterCode'] ?? ''));
        if ($template === null) {
            throw BookTypeAdminException::invalid('Choisissez un chapitre existant du type à tester (chapterCode).');
        }

        $dryRun = (bool) ($options['dryRun'] ?? false);
        $tone = isset($options['tone']) && is_string($options['tone']) && trim($options['tone']) !== '' ? trim($options['tone']) : null;
        $model = isset($options['model']) && is_string($options['model']) && trim($options['model']) !== '' ? trim($options['model']) : null;

        // Livre et chapitre fictifs, jamais persistés
        $book = (new Book())
            ->setType($type->getCode())
            ->setTitle('Livre de test')
            ->setPerson1FirstName('Marie')
            ->setPerson1Birthplace('Lyon')
            ->setBirthplace('Lyon')
            ->setParentsDeceased((bool) ($options['subjectsAbsent'] ?? false));
        if ($type->isCollective() || $type->getSpeakerCount() === 2) {
            $book->setPerson2FirstName('Jean')->setPerson2Birthplace('Brest');
        }
        $chapter = (new Chapter())
            ->setTheme($template->getCode())
            ->setTitle($template->getTitle())
            ->setPosition($template->getPosition());
        $book->addChapter($chapter);

        $sample = isset($options['answers']) && is_array($options['answers']) && $options['answers'] !== []
            ? $this->normalizeProvidedAnswers($type, $template, $options['answers'])
            : $this->sampleAnswers($type, $template, $dryRun);
        $chapter->setAnswers($sample['answers']);
        $chapter->setContributorAnswers($sample['contributorAnswers'] ?: null);

        $prompt = $this->engine->buildPrompt($chapter, $type, 1, $tone) .
            "\n\n(MODE TEST : rédige uniquement un extrait représentatif d'environ 500 mots, en respectant toutes les consignes ci-dessus, et termine sur un paragraphe complet.)";

        $text = $dryRun ? null : trim($this->anthropic->complete($prompt, self::TEST_MAX_TOKENS, null, $model));

        return [
            'chapterCode' => $template->getCode(),
            'model' => $dryRun ? null : $this->anthropic->resolveModel($model),
            'sample' => $sample,
            'prompt' => $prompt,
            'text' => $text,
        ];
    }

    /**
     * Réponses fictives aux questions actives du chapitre (générées par le modèle rapide, ou génériques en dryRun).
     */
    private function sampleAnswers(BookType $type, BookTypeChapter $template, bool $dryRun): array
    {
        $questions = $this->emProvider->getEntityManager()->getRepository(MemoireQuestion::class)->findBy(
            ['bookType' => $type->getCode(), 'theme' => $template->getCode(), 'isActive' => true],
            ['displayOrder' => 'ASC', 'id' => 'ASC']
        );
        if ($questions === []) {
            throw BookTypeAdminException::invalid("Le chapitre « {$template->getTitle()} » n'a aucune question active : ajoutez-en avant de le tester.");
        }

        if (!$type->isCollective() || in_array($template->getSpeaker(), BookTypeChapter::SPEAKERS_DIRECT, true)) {
            $list = array_values(array_filter($questions, fn (MemoireQuestion $q) => $q->getRole() === null || $q->getRole() === $type->getDefaultRole()));
            return ['answers' => $this->fillAnswers($list ?: $questions, 'Marie', null, $dryRun), 'contributorAnswers' => []];
        }

        // Un contributeur fictif par rôle ayant des questions (3 au plus)
        $names = ['Sophie', 'Lucas', 'Camille'];
        $contributors = [];
        foreach ($type->getRoles() as $role) {
            if (count($contributors) >= 3) {
                break;
            }
            $roleQuestions = array_values(array_filter($questions, fn (MemoireQuestion $q) => $q->getRole() === null || $q->getRole() === $role->getCode()));
            $specific = array_filter($roleQuestions, fn (MemoireQuestion $q) => $q->getRole() === $role->getCode());
            if ($specific === [] && $contributors !== []) {
                continue;
            }
            $name = $names[count($contributors)];
            $contributors[] = [
                'id' => 'test-' . $role->getCode(),
                'firstName' => $name,
                'contributorName' => $name,
                'role' => $role->getCode(),
                'answers' => $this->fillAnswers(array_slice($roleQuestions, 0, 4), $name, $role->getLabel(), $dryRun),
            ];
        }
        if ($contributors === []) {
            $contributors[] = ['id' => 'test-proche', 'firstName' => 'Sophie', 'contributorName' => 'Sophie', 'role' => 'proche', 'answers' => $this->fillAnswers(array_slice($questions, 0, 4), 'Sophie', 'Proche', $dryRun)];
        }

        return ['answers' => [], 'contributorAnswers' => $contributors];
    }

    /**
     * @param MemoireQuestion[] $questions
     */
    private function fillAnswers(array $questions, string $name, ?string $roleLabel, bool $dryRun): array
    {
        $texts = array_map(fn (MemoireQuestion $q) => $q->getQuestionText(), $questions);
        $generated = $dryRun ? [] : $this->generateFictitiousAnswers($texts, $name, $roleLabel);

        $answers = [];
        foreach ($questions as $i => $q) {
            $answers[] = [
                'index' => $q->getDisplayOrder(),
                'question' => $q->getQuestionText(),
                'answer' => $generated[$i] ?? "(Réponse d'exemple de {$name})",
                'skipped' => false,
            ];
        }
        return $answers;
    }

    /**
     * @return string[] une réponse par question, dans le même ordre
     */
    private function generateFictitiousAnswers(array $questions, string $name, ?string $roleLabel): array
    {
        $who = $roleLabel
            ? "{$name} ({$roleLabel}), à propos de Marie, la femme à qui le livre est consacré (parle d'elle au féminin)"
            : "{$name}, née à Lyon, à propos de sa propre vie";
        $prompt = "Invente des réponses réalistes, concrètes et variées (3 à 5 phrases chacune, à la première personne) " .
            "que pourrait donner {$who}, pour tester un livre de mémoires. " .
            "Réponds UNIQUEMENT avec un tableau JSON de chaînes, une réponse par question, dans l'ordre.\n\nQuestions :\n" .
            implode("\n", array_map(fn ($i, $q) => ($i + 1) . ". {$q}", array_keys($questions), $questions));

        try {
            $raw = $this->anthropic->complete($prompt, 2500, null, AnthropicService::DEFAULT_FAST_MODEL);
        } catch (\Throwable) {
            return [];
        }

        // Le modèle renvoie parfois un objet ({"reponse_1": ...}) ou entoure le JSON de ```json
        $clean = trim(preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $raw));
        $decoded = json_decode($clean, true);
        if (!is_array($decoded) && preg_match('/[\[{].*[\]}]/s', $clean, $m)) {
            $decoded = json_decode($m[0], true);
        }
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_map(
            fn ($v) => is_array($v) ? (string) ($v['answer'] ?? $v['reponse'] ?? $v['réponse'] ?? reset($v)) : (string) $v,
            $decoded
        ));
    }

    private function normalizeProvidedAnswers(BookType $type, BookTypeChapter $template, array $answers): array
    {
        $list = [];
        foreach (array_values($answers) as $i => $a) {
            if (is_array($a) && trim((string) ($a['answer'] ?? '')) !== '') {
                $list[] = ['index' => $i, 'question' => (string) ($a['question'] ?? ''), 'answer' => (string) $a['answer'], 'skipped' => false];
            }
        }
        if ($list === []) {
            throw BookTypeAdminException::invalid('Les réponses de test fournies sont vides.');
        }

        if ($type->isCollective() && !in_array($template->getSpeaker(), BookTypeChapter::SPEAKERS_DIRECT, true)) {
            return ['answers' => [], 'contributorAnswers' => [[
                'id' => 'test-proche', 'firstName' => 'Sophie', 'contributorName' => 'Sophie',
                'role' => $type->getDefaultRole() ?? 'proche', 'answers' => $list,
            ]]];
        }

        return ['answers' => $list, 'contributorAnswers' => []];
    }

    private function speakerLabel(string $speaker): string
    {
        return match ($speaker) {
            BookTypeChapter::SPEAKER_PERSON1 => 'interlocuteur 1',
            BookTypeChapter::SPEAKER_PERSON2 => 'interlocuteur 2',
            BookTypeChapter::SPEAKER_BOTH => 'les deux interlocuteurs',
            BookTypeChapter::SPEAKER_CONTRIBUTORS => 'témoignages des contributeurs',
            BookTypeChapter::SPEAKER_SYNTHESIS => 'synthèse de tous les témoignages du livre',
            default => $speaker,
        };
    }
}
