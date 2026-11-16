<?php

namespace App\LossPrevention\Services;

use App\Gestion\Services\GestionConflictException;
use App\Gestion\Services\GestionValidationException;
use App\LossPrevention\Entity\LossPiece;
use App\LossPrevention\Entity\LossPieceEvent;
use App\LossPrevention\Entity\LossTag;
use App\LossPrevention\Repository\LossPieceRepository;
use App\Services\BarcodeService\BarcodeLabelPrinter;
use App\Services\BarcodeService\CatalogBarcode;
use App\Services\BarcodeService\CatalogBarcodeService;
use App\Services\TenantEntityManagerProvider;

/**
 * Assistant d'installation des étiquettes à puce (lot L2-b, 15/11/2026, `docs/prevention-pertes.md` § 13.10) : une suite d'étapes
 * dont l'avancement se CALCULE à partir des données, jamais d'une case cochée ; rien n'est stocké pour lui.
 *
 * - Lire l'assistant, ou « Vérifier » une étape, n'écrit rien.
 * - Une étape est faite si sa preuve existe, ou si une étape qui la suit est faite (un site qui pose déjà ses puces n'a pas à
 *   refaire les essais). Preuves : l'étiquette d'essai demandée (fait `TEST_LABEL_PRINTED`), une puce d'essai lue (fait
 *   `TEST_TAG_READ`), une pièce qui porte une puce (lue dans `loss_tag` : une puce retirée ne prouve plus rien).
 * - Les deux gestes d'essai n'écrivent leur fait qu'UNE fois (la première preuve suffit) : ni un double appui ni des essais
 *   répétés ne remplissent une table où rien ne s'efface. La lecture d'essai n'enregistre PAS la puce : elle ne le sera qu'en étant
 *   posée sur une pièce, avec la technologie donnée ce jour-là (une lecture d'essai ne fige rien).
 * - `$exercise` : le même assistant sur des puces simulées, ouvert sans le droit du module ; ses faits s'effacent avec les pièces
 *   d'exercice et n'avancent jamais l'installation réelle.
 * - Étapes facultatives (`OPTIONAL`, lot L3-a) : le boîtier. Une boutique peut poser et lire ses puces sans boîtier ; ces étapes
 *   sont donc rendues à part (`optionalSteps`), elles ne comptent ni dans l'avancement ni dans « terminé », et aucune étape ne
 *   dépend d'elles. `box` est faite quand un boîtier est inscrit et donne signe de vie (un boîtier simulé, en exercice) : elle
 *   reprend l'état du boîtier au tableau de santé (`LossHealthService`) et dit quoi essayer quand il n'est pas en ordre.
 * Les étapes du tapis de comptoir (L3-b) et des antennes (L4) s'ajouteront de la même façon.
 */
class LossInstallationService
{
    public const SCREEN = 'pertes.installation';
    /** Étapes, dans l'ordre : code => le fait qui prouve l'étape (null : pas de fait ; voir `proofs()`) */
    public const STEPS = [
        'hardware' => null, // étape d'information : faite dès qu'une étape suivante l'est
        'test_label' => LossPieceEvent::TEST_LABEL_PRINTED,
        'test_tag' => LossPieceEvent::TEST_TAG_READ,
        'first_piece' => null, // prouvée par l'état : une pièce porte une puce
    ];
    /** Étapes facultatives, rendues à part : elles ne comptent pas dans l'avancement */
    public const OPTIONAL = ['box', 'counter_mat'];
    /** Code imprimé sur l'étiquette d'essai : sous le préfixe réservé aux documents de la caisse, il ne peut désigner aucun article */
    public const TEST_BARCODE = 'IZ-ESSAI-ETIQUETTE';

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly LossPieceService $pieces,
        private readonly CatalogBarcodeService $barcodes,
        private readonly BarcodeLabelPrinter $printer,
        private readonly LossTexts $texts,
        private readonly LossHealthService $health,
    ) {
    }

    /**
     * L'assistant : ses étapes et où l'on en est.
     *
     * @return array{steps: list<array<string, mixed>>, optionalSteps: list<array<string, mixed>>, progress: array{done: int, total: int}, complete: bool, exercise: bool, screen: string}
     */
    public function steps(string $locale, bool $exercise): array
    {
        $done = $this->proofs($exercise);
        // une étape est faite aussi quand une étape qui la suit l'est
        $codes = array_keys(self::STEPS);
        for ($i = count($codes) - 2; $i >= 0; --$i) {
            $done[$codes[$i]] = $done[$codes[$i]] || $done[$codes[$i + 1]];
        }
        $steps = [];
        $current = null;
        foreach ($codes as $code) {
            $status = $done[$code] ? 'done' : ($current === null ? 'current' : 'todo');
            if ($status === 'current') {
                $current = $code;
            }
            $steps[] = [
                'code' => $code,
                'title' => $this->texts->t("installation.steps.$code.title", $locale),
                'text' => $this->texts->t("installation.steps.$code.text", $locale),
                'image' => 'pertes/installation/' . $code, // clé d'illustration : un dessin tant que le matériel n'est pas choisi
                'status' => $status,
                // « Vérifier » relit les données ; `none` : étape d'information, rien à vérifier
                'verify' => ['kind' => $code === 'hardware' ? 'none' : 'data', 'label' => $this->texts->t("installation.steps.$code.verify", $locale)],
            ];
        }

        $optional = [];
        foreach ($this->optionalStates($locale, $exercise) as $code => $device) {
            $optional[] = [
                'code' => $code,
                'title' => $this->texts->t("installation.steps.$code.title", $locale),
                'text' => $this->texts->t("installation.steps.$code.text", $locale),
                'image' => 'pertes/installation/' . $code,
                'status' => $device['state'] === 'ok' ? 'done' : 'todo', // jamais « en cours » : rien n'oblige à la faire
                'verify' => ['kind' => 'data', 'label' => $this->texts->t("installation.steps.$code.verify", $locale)],
                // l'état de l'appareil de l'étape, celui du tableau de santé, et quoi essayer s'il n'est pas en ordre
                'state' => $device['state'],
                'hint' => $device['hint'],
            ];
        }

        return ['steps' => $steps, 'optionalSteps' => $optional, 'progress' => ['done' => count(array_filter($done)), 'total' => count($done)], 'complete' => !in_array(false, $done, true),
            'exercise' => $exercise, 'screen' => self::SCREEN];
    }

    /**
     * « Vérifier » : l'étape est-elle faite, d'après les données ? Sinon, quoi essayer. N'écrit rien.
     *
     * @return array{code: string, verified: bool, hint: ?string}
     */
    public function verify(string $code, string $locale, bool $exercise): array
    {
        if (!array_key_exists($code, self::STEPS) && !in_array($code, self::OPTIONAL, true)) {
            throw new GestionConflictException('STEP_NOT_FOUND', $this->texts->t('installation.error.STEP_NOT_FOUND', $locale), 404);
        }
        $installation = $this->steps($locale, $exercise);
        $step = array_values(array_filter([...$installation['steps'], ...$installation['optionalSteps']], static fn (array $s): bool => $s['code'] === $code))[0];
        $verified = $step['status'] === 'done';

        // une étape d'appareil dit quoi essayer d'après l'état de l'appareil (câble, alimentation, silence depuis N minutes)
        return ['code' => $code, 'verified' => $verified, 'hint' => $verified ? null : ($step['hint'] ?? $this->texts->t("installation.steps.$code.hint", $locale))];
    }

    /**
     * Lire une puce d'essai : la lecture a-t-elle la forme d'une puce ? Si oui, l'étape est prouvée (un fait `TEST_TAG_READ`, écrit
     * la première fois seulement) ; la puce n'est pas enregistrée. Sinon rien n'est écrit et on dit quoi essayer. Une lecture qui
     * marche sur une puce déjà posée ou retirée le dit : il en faudra une libre pour la suite.
     *
     * @param array<string, mixed> $body { identifier, technology (uhf | nfc) } ; exercice : `identifier` facultatif, puce simulée
     *
     * @return array<string, mixed> { ok, identifier, technology, refusal, hint, tag, installation }
     */
    public function testRead(array $body, string $locale, int $userId, bool $exercise): array
    {
        $errors = [];
        $technology = $exercise ? LossTagIdentifier::SIMULATED : ($body['technology'] ?? null);
        if (!$exercise && !in_array($technology, LossTagIdentifier::REAL, true)) {
            $errors['technology'] = array_key_exists('technology', $body) ? 'INVALID_VALUE' : 'REQUIRED';
        }
        $raw = $body['identifier'] ?? null;
        if ($raw === null && $exercise) {
            $raw = LossTagIdentifier::simulated();
        }
        if (!is_string($raw) || trim($raw) === '') {
            $errors['identifier'] = array_key_exists('identifier', $body) ? 'MUST_BE_STRING' : 'REQUIRED';
        }
        if ($errors !== []) {
            throw new GestionValidationException($errors);
        }
        $failed = fn (string $refusal, string $hint, array $params = []): array => ['ok' => false, 'identifier' => null, 'technology' => $technology, 'refusal' => $refusal,
            'hint' => $this->texts->t("installation.hint.$hint", $locale, $params), 'tag' => null, 'installation' => $this->steps($locale, $exercise)];
        $identifier = LossTagIdentifier::canonical($raw, $technology);
        if (!$exercise) {
            // Le code-barres d'un article lu à la place de la puce (lecteur en clavier dans le mauvais champ) : on le dit, que la
            // lecture ait ou non la forme d'une puce. Comparée telle qu'un code-barres l'écrirait, et sous sa forme nettoyée
            $asBarcode = CatalogBarcode::normalize($raw);
            $holder = ($asBarcode !== null ? $this->barcodes->holder($asBarcode) : null) ?? ($identifier !== null ? $this->barcodes->holder($identifier) : null);
            if ($holder !== null) {
                return $failed('TAG_IS_BARCODE', 'barcode', ['label' => $holder['label']]);
            }
        }
        if ($identifier === null) {
            // quoi essayer, d'après ce que le lecteur a rendu
            $clean = (string) preg_replace('/[\s:.\-]+/', '', $raw);
            $hint = $exercise ? 'simulated' : (ctype_digit($clean) ? 'decimal' : (preg_match('/\A(0x)?[0-9a-f]+\z/i', $clean) === 1 ? 'length_' . $technology : 'form'));

            return $failed('TAG_INVALID', $hint);
        }
        $this->prove(LossPieceEvent::TEST_TAG_READ, $userId, $exercise, ['technology' => $technology, 'identifier' => $identifier]);
        $tag = $this->repository()->tagByIdentifier($identifier);
        if ($tag !== null && (bool) $tag['exercise'] !== $exercise) {
            $tag = null; // impossible : les deux formes ne se recouvrent pas
        }
        // La lecture marche. Si la puce est déjà posée ou retirée, on le dit : il en faudra une libre pour la suite
        $hint = match ($tag['status'] ?? null) {
            LossTag::ASSOCIATED => $this->texts->t('installation.hint.already_associated', $locale),
            LossTag::RETIRED => $this->texts->t('installation.hint.retired', $locale),
            default => null,
        };

        return ['ok' => true, 'identifier' => $identifier, 'technology' => $technology, 'refusal' => null, 'hint' => $hint,
            'tag' => $tag !== null ? $this->pieces->tagView($tag, $locale) : null, 'installation' => $this->steps($locale, $exercise)];
    }

    /**
     * L'étiquette d'essai : une étiquette au format demandé, avec un code qui ne peut désigner aucun article. Le fait
     * `TEST_LABEL_PRINTED`, écrit la première fois seulement et APRÈS le rendu, prouve l'étape (le serveur ne voit pas
     * l'imprimante : il sait seulement que l'étiquette a été produite).
     *
     * @param array<string, mixed> $body { format?: a4-50x30 | roll-50x30 }
     *
     * @return array{content: string, format: string}
     */
    public function testLabel(array $body, string $locale, int $userId, bool $exercise): array
    {
        $format = $body['format'] ?? BarcodeLabelPrinter::A4;
        if (!is_string($format) || !isset(BarcodeLabelPrinter::FORMATS[$format])) {
            throw new GestionValidationException(['format' => 'INVALID_VALUE']);
        }
        $content = $this->printer->pdf([$this->testLabelRow($locale)], $this->texts->t('installation.test_label.title', $locale), $format);
        $this->prove(LossPieceEvent::TEST_LABEL_PRINTED, $userId, $exercise, ['format' => $format]);

        return ['content' => $content, 'format' => $format];
    }

    /** @return array{title: string, subtitle: string, barcode: string, price: null, copies: int, note: string} ce que porte l'étiquette d'essai */
    public function testLabelRow(string $locale): array
    {
        return ['title' => $this->texts->t('installation.test_label.title', $locale), 'subtitle' => $this->texts->t('installation.test_label.subtitle', $locale),
            'barcode' => self::TEST_BARCODE, 'price' => null, 'copies' => 1, 'note' => $this->texts->t('installation.test_label.note', $locale)];
    }

    /**
     * Ce que les données prouvent, étape par étape (sans la règle « une étape suivante faite »).
     *
     * @return array<string, bool>
     */
    private function proofs(bool $exercise): array
    {
        $repository = $this->repository();
        $counts = $repository->eventCounts(array_values(array_filter(self::STEPS)), $exercise);
        $done = [];
        foreach (self::STEPS as $code => $event) {
            $done[$code] = $event !== null && $counts[$event] > 0;
        }
        $done['first_piece'] = $repository->associatedTagExists($exercise);

        return $done;
    }

    /**
     * L'état des appareils des étapes facultatives, lu au tableau de santé : `box` est faite quand un boîtier de ce genre est
     * inscrit ET donne signe de vie ; `counter_mat` quand un tapis a lu une puce et qu'il est relié à son poste de caisse ; sinon
     * l'étape dit quoi essayer.
     *
     * @return array<string, array{state: string, hint: ?string}>
     */
    private function optionalStates(string $locale, bool $exercise): array
    {
        return ['box' => $this->health->kindState('box', $locale, $exercise), 'counter_mat' => $this->health->kindState('mat', $locale, $exercise)];
    }

    /**
     * Écrit le fait qui prouve une étape, s'il n'existe pas déjà : une preuve suffit. En exercice, sous le verrou des exercices (un
     * effacement en cours ne laisse rien derrière lui).
     *
     * @param array<string, mixed> $data
     */
    private function prove(string $event, int $userId, bool $exercise, array $data): void
    {
        $repository = $this->repository();
        $conn = $this->emProvider->getEntityManager()->getConnection();
        $conn->beginTransaction();
        try {
            if ($exercise) {
                $conn->executeStatement('SELECT pg_advisory_xact_lock(' . LossPieceRepository::EXERCISE_LOCK . ')');
            }
            if ($repository->eventCounts([$event], $exercise)[$event] === 0) {
                $repository->event($event, $userId, null, null, $exercise, $data, (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'));
            }
            $conn->commit();
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            throw $e;
        }
    }

    private function repository(): LossPieceRepository
    {
        return $this->emProvider->getEntityManager()->getRepository(LossPiece::class);
    }
}
