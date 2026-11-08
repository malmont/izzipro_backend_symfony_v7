<?php

namespace App\Services\SiteCharter;

use App\Entity\SiteCharter;
use App\Services\BoutiqueSettingsService\BoutiqueConfigurationValidator;
use App\Services\TenantConnectionProvider;
use App\Services\TenantEntityManagerProvider;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Charte et brief du site (08/11/2026) : lecture et écriture de la ligne `site_charter`, document PDF dans le stockage privé
 * (`var/storage/private_media/site-charter/<code>/`), valeurs par défaut données à chaque composition de l'assistant IA.
 */
class SiteCharterService
{
    public const MAX_DESIGN_MD = 40000;
    public const MAX_DOCUMENT_BYTES = 7500000; // 7,5 Mo : 10 Mo au plus une fois en base64 (limite de l'assistant)
    public const STYLE_OPTIONS = ['tone' => ['sobre', 'equilibre', 'expressif'], 'motion' => ['aucun', 'discret', 'vivant'], 'density' => ['aeree', 'normale', 'dense']];
    /** Clés du brief acceptées (texte libre borné) */
    public const BRIEF_TEXT = ['activity' => 400, 'audience' => 300, 'place' => 120, 'sells' => 300, 'extra' => 600];
    public const BRIEF_GOALS = ['found' => 'Être trouvé sur Google', 'sell' => 'Vendre en ligne', 'book' => 'Prendre des rendez-vous ou des réservations', 'present' => 'Présenter mes services', 'recruit' => 'Recruter', 'inform' => 'Informer et publier des actualités'];
    public const PAGE_CHOICES = ['Services', 'À propos', 'Contact', 'Réalisations', 'Tarifs', 'Actualités', 'Équipe', 'FAQ'];
    public const HANDOVER = ['delivery' => 'Livraison', 'pickup' => 'Retrait sur place'];

    public function __construct(
        private readonly TenantEntityManagerProvider $emProvider,
        private readonly TenantConnectionProvider $tenantProvider,
        private readonly BoutiqueConfigurationValidator $charterValidator,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
    }

    public function get(): ?SiteCharter
    {
        return $this->emProvider->getEntityManager()->getRepository(SiteCharter::class)->current();
    }

    public function getOrCreate(): SiteCharter
    {
        $charter = $this->get();
        if ($charter === null) {
            $charter = new SiteCharter();
            $this->emProvider->getEntityManager()->persist($charter);
        }

        return $charter;
    }

    /**
     * Enregistre brief, charte, style et DESIGN.md après contrôle. Une clé absente de $data n'est pas touchée ; null efface.
     *
     * @param array{brief?: ?array, charter?: ?array, style?: ?array, designMd?: ?string} $data
     * @return list<array{path: string, message: string}> erreurs (rien n'est écrit s'il y en a)
     */
    public function save(array $data, ?string $by): array
    {
        $errors = [];
        $brief = array_key_exists('brief', $data) ? $this->cleanBrief($data['brief'], $errors) : false;
        $charter = array_key_exists('charter', $data) ? $this->cleanCharter($data['charter'], $errors) : false;
        $style = array_key_exists('style', $data) ? $this->cleanStyle($data['style'], $errors) : false;
        $designMd = false;
        if (array_key_exists('designMd', $data)) {
            $designMd = $data['designMd'] === null ? null : trim((string) $data['designMd']);
            if ($designMd !== null && mb_strlen($designMd) > self::MAX_DESIGN_MD) {
                $errors[] = ['path' => 'designMd', 'message' => sprintf('%d caractères au plus', self::MAX_DESIGN_MD)];
            }
            $designMd = $designMd === '' ? null : $designMd;
        }
        if ($errors !== []) {
            return $errors;
        }
        $entity = $this->getOrCreate();
        if ($brief !== false) {
            $entity->setBrief($brief);
        }
        if ($charter !== false) {
            $entity->setCharter($charter);
        }
        if ($style !== false) {
            $entity->setStyle($style);
        }
        if ($designMd !== false) {
            $entity->setDesignMd($designMd);
        }
        $entity->touch($by);
        $this->emProvider->getEntityManager()->flush();

        return [];
    }

    /** @param list<array{path: string, message: string}> $errors */
    private function cleanBrief(mixed $brief, array &$errors): ?array
    {
        if ($brief === null) {
            return null;
        }
        if (!is_array($brief)) {
            $errors[] = ['path' => 'brief', 'message' => 'objet attendu'];

            return null;
        }
        $clean = [];
        foreach (self::BRIEF_TEXT as $key => $max) {
            $value = trim((string) ($brief[$key] ?? ''));
            if ($value !== '' && (mb_strlen($value) > $max || preg_match('/[<>]/', $value))) {
                $errors[] = ['path' => "brief.$key", 'message' => sprintf('texte brut de %d caractères au plus', $max)];
            }
            if ($value !== '') {
                $clean[$key] = $value;
            }
        }
        $goals = array_values(array_unique(array_filter(is_array($brief['goals'] ?? null) ? $brief['goals'] : [], fn ($g) => is_string($g) && isset(self::BRIEF_GOALS[$g]))));
        if ($goals !== []) {
            $clean['goals'] = $goals;
        }
        $pages = [];
        foreach (is_array($brief['pages'] ?? null) ? $brief['pages'] : [] as $title) {
            $title = trim((string) $title);
            if ($title === '' || mb_strlen($title) > 60 || preg_match('/[<>]/', $title)) {
                $errors[] = ['path' => 'brief.pages', 'message' => 'titre de page de 1 à 60 caractères, sans < ni >'];
                continue;
            }
            if (!in_array(mb_strtolower($title), array_map('mb_strtolower', $pages), true) && mb_strtolower($title) !== 'accueil') {
                $pages[] = $title;
            }
        }
        if (count($pages) > 8) {
            $errors[] = ['path' => 'brief.pages', 'message' => '8 pages au plus après l\'accueil'];
        }
        $clean['pages'] = $pages;
        $clean['replaceDemo'] = (bool) ($brief['replaceDemo'] ?? false); // lot 3 : effacer le contenu d'exemple à l'application de la composition
        $handover = array_values(array_unique(array_filter(is_array($brief['handover'] ?? null) ? $brief['handover'] : [], fn ($h) => is_string($h) && isset(self::HANDOVER[$h]))));
        if ($handover !== []) {
            $clean['handover'] = $handover;
        }

        return $clean;
    }

    /** @param list<array{path: string, message: string}> $errors */
    private function cleanCharter(mixed $charter, array &$errors): ?array
    {
        if ($charter === null) {
            return null;
        }
        $charter = is_array($charter) ? array_filter($charter, fn ($v) => $v !== null && $v !== '') : $charter;
        if ($charter === []) {
            return null;
        }
        foreach ($this->charterValidator->validateCharter(is_array($charter) ? (object) $charter : $charter) as $e) {
            $errors[] = ['path' => 'charter.' . ltrim(str_replace('charter', '', (string) $e['path']), '.'), 'message' => $e['message']];
        }

        return is_array($charter) ? $charter : null;
    }

    /** @param list<array{path: string, message: string}> $errors */
    private function cleanStyle(mixed $style, array &$errors): ?array
    {
        if ($style === null) {
            return null;
        }
        if (!is_array($style)) {
            $errors[] = ['path' => 'style', 'message' => 'objet attendu'];

            return null;
        }
        $clean = [];
        foreach (self::STYLE_OPTIONS as $key => $allowed) {
            $value = $style[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            if (!in_array($value, $allowed, true)) {
                $errors[] = ['path' => "style.$key", 'message' => 'valeur attendue : ' . implode(', ', $allowed)];
                continue;
            }
            $clean[$key] = $value;
        }

        return $clean === [] ? null : $clean;
    }

    /**
     * Dépose le document PDF (maquette, charte) ; remplace le précédent.
     * @return ?string message d'erreur
     */
    public function storeDocument(string $binary, string $name, ?string $by): ?string
    {
        if (!str_starts_with($binary, '%PDF-')) {
            return 'Le document doit être un PDF.';
        }
        if (strlen($binary) > self::MAX_DOCUMENT_BYTES) {
            return sprintf('PDF de %d Mo au plus.', intdiv(self::MAX_DOCUMENT_BYTES, 1000000));
        }
        $dir = $this->documentDir();
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return 'Stockage indisponible.';
        }
        $file = $dir . '/charte.pdf';
        if (file_put_contents($file, $binary) === false) {
            return 'Écriture impossible.';
        }
        $entity = $this->getOrCreate();
        $entity->setDocumentName(mb_substr(preg_replace('/[^\w .\-()]+/u', '_', $name) ?: 'charte.pdf', 0, 255))->setDocumentPath($this->relative($file))->touch($by);
        $this->emProvider->getEntityManager()->flush();

        return null;
    }

    public function removeDocument(?string $by): void
    {
        $entity = $this->get();
        if ($entity === null || $entity->getDocumentPath() === null) {
            return;
        }
        $file = $this->projectDir . '/' . $entity->getDocumentPath();
        if (is_file($file)) {
            @unlink($file);
        }
        $entity->setDocumentName(null)->setDocumentPath(null)->touch($by);
        $this->emProvider->getEntityManager()->flush();
    }

    /** Document en data URL (pour l'assistant), ou null */
    public function documentDataUri(): ?string
    {
        $entity = $this->get();
        if ($entity?->getDocumentPath() === null) {
            return null;
        }
        $file = $this->projectDir . '/' . $entity->getDocumentPath();
        if (!is_file($file)) {
            return null;
        }
        $binary = (string) file_get_contents($file);

        return str_starts_with($binary, '%PDF-') ? 'data:application/pdf;base64,' . base64_encode($binary) : null;
    }

    public function markPagesSaved(): void
    {
        $entity = $this->getOrCreate();
        $entity->setPagesSavedAt(new \DateTimeImmutable());
        $this->emProvider->getEntityManager()->flush();
    }

    public function markComposed(string $jobId): void
    {
        $entity = $this->getOrCreate();
        $entity->setComposedAt(new \DateTimeImmutable())->setPagesSavedAt(new \DateTimeImmutable())->setLastJobId($jobId);
        $this->emProvider->getEntityManager()->flush();
    }

    public function rememberJob(string $jobId, ?string $by): void
    {
        $entity = $this->getOrCreate();
        $entity->setLastJobId($jobId)->touch($by);
        $this->emProvider->getEntityManager()->flush();
    }

    /** Nouveau site (clone du modèle) : aucune charte ni date héritée */
    public function reset(): void
    {
        $this->emProvider->getEntityManager()->getConnection()->executeStatement('DELETE FROM site_charter');
    }

    /** @return array<string, mixed> forme de l'API et de l'écran */
    public function toArray(?SiteCharter $c): array
    {
        return [
            'brief' => $c?->getBrief(), 'charter' => $c?->getCharter(), 'style' => $c?->getStyle(), 'designMd' => $c?->getDesignMd(),
            'document' => $c?->getDocumentName() !== null ? ['name' => $c->getDocumentName()] : null,
            'pagesSavedAt' => $c?->getPagesSavedAt()?->format(\DateTimeInterface::ATOM), 'composedAt' => $c?->getComposedAt()?->format(\DateTimeInterface::ATOM),
            'lastJobId' => $c?->getLastJobId(), 'updatedAt' => $c?->getUpdatedAt()?->format(\DateTimeInterface::ATOM), 'updatedBy' => $c?->getUpdatedBy(),
            'options' => ['goals' => self::BRIEF_GOALS, 'pages' => self::PAGE_CHOICES, 'style' => self::STYLE_OPTIONS, 'handover' => self::HANDOVER],
        ];
    }

    private function documentDir(): string
    {
        $code = preg_replace('/[^a-z0-9_-]/i', '_', (string) ($this->tenantProvider->getTenantCode() ?? 'site'));

        return $this->projectDir . '/var/storage/private_media/site-charter/' . $code;
    }

    private function relative(string $absolute): string
    {
        return ltrim(substr($absolute, strlen($this->projectDir)), '/');
    }
}
