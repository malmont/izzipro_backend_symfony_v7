<?php

namespace App\Services\LandingConfigService;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Fichiers de configuration des landing pages publiés par le frontend (schéma, catalogue de l'assistant, jeu d'essai),
 * versionnés sur disque :
 *   <dir>/versions/<id>/   une version installée (les 3 fichiers et manifest.json) ;
 *   <dir>/state.json       { "active": "<id>", "previous": "<id>" | null }, remplacé atomiquement (rename).
 * Sans version installée, la version « bundled » sert : les copies de config/landingpage/ (dépôt).
 * Chaque lecture passe par path() : un processus long (worker) voit la nouvelle version sans redémarrer.
 */
final class LandingConfigStore
{
    public const SCHEMA = 'landingpage-reglable.schema.json';
    public const CATALOGUE = 'landingpage-ia-catalogue.json';
    public const TEST_PLAN = 'ia-assistant-jeu-essai.md';
    public const FILES = [self::SCHEMA, self::CATALOGUE, self::TEST_PLAN];
    public const BUNDLED = 'bundled';

    public function __construct(
        #[Autowire('%landing_config.dir%')]
        private readonly string $dir,
        #[Autowire('%kernel.project_dir%/config/landingpage')]
        private readonly string $bundledDir
    ) {
    }

    /** Chemin du fichier $file dans la version active */
    public function path(string $file): string
    {
        return $this->versionPath($this->activeId(), $file);
    }

    public function versionPath(string $id, string $file): string
    {
        if (!in_array($file, self::FILES, true)) {
            throw new \InvalidArgumentException("Fichier de configuration inconnu : $file");
        }

        return ($id === self::BUNDLED ? $this->bundledDir : $this->dir . '/versions/' . $id) . '/' . $file;
    }

    public function activeId(): string
    {
        return $this->state()['active'];
    }

    public function previousId(): ?string
    {
        return $this->state()['previous'];
    }

    /** @return array{id: string, version: string, files: array<string, string>} version et empreintes sha256 */
    public function describe(string $id): array
    {
        if ($id !== self::BUNDLED && is_file($manifest = $this->dir . '/versions/' . $id . '/manifest.json')) {
            $data = json_decode((string) file_get_contents($manifest), true);

            return ['id' => $id, 'version' => (string) ($data['version'] ?? $id), 'files' => (array) ($data['files'] ?? [])];
        }
        $files = [];
        foreach (self::FILES as $file) {
            $path = $this->versionPath($id, $file);
            $files[$file] = is_file($path) ? hash_file('sha256', $path) : null;
        }

        return ['id' => $id, 'version' => $id, 'files' => $files];
    }

    /**
     * Installe une version (sans l'activer). Écrite dans un dossier temporaire puis renommée : jamais de version
     * partielle.
     *
     * @param array<string, string> $contents nom => contenu, les 3 fichiers
     * @return string identifiant de la version installée
     */
    public function install(string $version, array $contents): string
    {
        $hashes = [];
        foreach (self::FILES as $file) {
            $hashes[$file] = hash('sha256', $contents[$file] ?? throw new \InvalidArgumentException("Fichier manquant : $file"));
        }
        $id = preg_replace('/[^A-Za-z0-9._-]/', '_', $version) . '-' . substr(hash('sha256', implode('', $hashes)), 0, 12);
        $target = $this->dir . '/versions/' . $id;
        if (is_dir($target)) {
            return $id;
        }

        $tmp = $this->dir . '/versions/.tmp-' . bin2hex(random_bytes(6));
        $this->mkdir($tmp);
        foreach (self::FILES as $file) {
            file_put_contents($tmp . '/' . $file, $contents[$file]);
        }
        file_put_contents($tmp . '/manifest.json', json_encode(['version' => $version, 'files' => $hashes, 'installedAt' => date(DATE_ATOM)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        if (!rename($tmp, $target)) {
            $this->remove($tmp);
            throw new \RuntimeException("Installation de la version $id impossible");
        }

        return $id;
    }

    /** Supprime une version installée qui n'est ni active ni précédente */
    public function uninstall(string $id): void
    {
        if ($id !== self::BUNDLED && !in_array($id, [$this->activeId(), $this->previousId()], true)) {
            $this->remove($this->dir . '/versions/' . $id);
        }
    }

    /** Active une version installée ; l'ancienne version active devient la précédente */
    public function activate(string $id): void
    {
        if ($id !== self::BUNDLED && !is_dir($this->dir . '/versions/' . $id)) {
            throw new \RuntimeException("Version $id non installée");
        }
        $this->writeState(['active' => $id, 'previous' => $this->activeId()]);
    }

    /** Exécute $action sous verrou exclusif (une seule synchronisation ou restauration à la fois) */
    public function locked(callable $action): mixed
    {
        $this->mkdir($this->dir);
        $lock = fopen($this->dir . '/.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            return $action();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array{active: string, previous: ?string} */
    private function state(): array
    {
        $file = $this->dir . '/state.json';
        $state = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return [
            'active' => is_string($state['active'] ?? null) ? $state['active'] : self::BUNDLED,
            'previous' => is_string($state['previous'] ?? null) ? $state['previous'] : null,
        ];
    }

    private function writeState(array $state): void
    {
        $this->mkdir($this->dir);
        $tmp = $this->dir . '/.state-' . bin2hex(random_bytes(6));
        file_put_contents($tmp, json_encode($state, JSON_PRETTY_PRINT));
        rename($tmp, $this->dir . '/state.json');
    }

    private function mkdir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Dossier $dir impossible à créer");
        }
    }

    private function remove(string $dir): void
    {
        foreach (is_dir($dir) ? scandir($dir) : [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                unlink($dir . '/' . $entry);
            }
        }
        is_dir($dir) && rmdir($dir);
    }
}
