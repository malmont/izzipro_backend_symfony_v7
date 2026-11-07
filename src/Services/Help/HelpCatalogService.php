<?php

namespace App\Services\Help;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Aide intégrée (07/11/2026) : une page par clé stable dans `docs/aide/<clé>.md` (en-tête : key, title, summary, screen, steps, settings),
 * lue localement et convertie en HTML simple (titres, listes, paragraphes, gras, liens). Chaque étape du cheminement, chaque clé du
 * registre et chaque écran retrouve sa page (`forStep`, `forSetting`, `forScreen`). Pas de bibliothèque Markdown dans le projet :
 * convertisseur minimal, suffisant pour des pages d'aide.
 */
class HelpCatalogService
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $pages = null;

    public function __construct(#[Autowire('%kernel.project_dir%')] private readonly string $projectDir)
    {
    }

    /** @return list<array{key: string, title: string, summary: string, screen: ?string, steps: list<string>, settings: list<string>}> */
    public function all(): array
    {
        return array_values(array_map(fn ($p) => array_diff_key($p, ['markdown' => 1]), $this->load()));
    }

    /** @return array{key: string, title: string, summary: string, screen: ?string, steps: list<string>, settings: list<string>, html: string}|null */
    public function get(string $key): ?array
    {
        $page = $this->load()[$key] ?? null;
        if ($page === null) {
            return null;
        }

        return array_diff_key($page, ['markdown' => 1]) + ['html' => $this->toHtml($page['markdown'])];
    }

    /** @return list<array<string, mixed>> pages dont le titre, le résumé ou le texte contient les mots demandés */
    public function search(string $query): array
    {
        $words = array_filter(preg_split('/\s+/', mb_strtolower(trim($query))) ?: []);
        if ($words === []) {
            return $this->all();
        }
        $out = [];
        foreach ($this->load() as $page) {
            $haystack = mb_strtolower($page['title'] . ' ' . $page['summary'] . ' ' . $page['markdown']);
            $ok = true;
            foreach ($words as $w) {
                $ok = $ok && str_contains($haystack, $w);
            }
            if ($ok) {
                $out[] = array_diff_key($page, ['markdown' => 1]);
            }
        }

        return $out;
    }

    public function forStep(string $stepKey): ?string
    {
        foreach ($this->load() as $key => $page) {
            if (in_array($stepKey, $page['steps'], true)) {
                return $key;
            }
        }

        return null;
    }

    public function forSetting(string $settingKey): ?string
    {
        foreach ($this->load() as $key => $page) {
            if (in_array($settingKey, $page['settings'], true)) {
                return $key;
            }
        }

        return null;
    }

    public function forScreen(?string $screen): ?string
    {
        if ($screen === null) {
            return null;
        }
        foreach ($this->load() as $key => $page) {
            if ($page['screen'] === $screen) {
                return $key;
            }
        }

        return null;
    }

    /** @return array<string, array<string, mixed>> */
    private function load(): array
    {
        if ($this->pages !== null) {
            return $this->pages;
        }
        $pages = [];
        foreach (glob($this->projectDir . '/docs/aide/*.md') ?: [] as $file) {
            $raw = (string) file_get_contents($file);
            $meta = ['key' => basename($file, '.md'), 'title' => basename($file, '.md'), 'summary' => '', 'screen' => null, 'steps' => [], 'settings' => []];
            $body = $raw;
            if (preg_match('/^---\s*\n(.*?)\n---\s*\n(.*)$/s', $raw, $m)) {
                $body = $m[2];
                foreach (preg_split('/\R/', $m[1]) ?: [] as $line) {
                    if (!preg_match('/^([a-z_]+):\s*(.*)$/', trim($line), $kv)) {
                        continue;
                    }
                    $value = trim($kv[2]);
                    if (in_array($kv[1], ['steps', 'settings'], true)) {
                        $meta[$kv[1]] = array_values(array_filter(array_map('trim', explode(',', trim($value, '[] ')))));
                    } elseif ($kv[1] === 'screen') {
                        $meta['screen'] = $value === '' || $value === 'null' ? null : $value;
                    } else {
                        $meta[$kv[1]] = $value;
                    }
                }
            }
            $meta['key'] = preg_replace('/[^a-z0-9_-]/', '', (string) $meta['key']) ?: basename($file, '.md');
            $pages[$meta['key']] = $meta + ['markdown' => $body];
        }
        ksort($pages);

        return $this->pages = $pages;
    }

    /** Markdown minimal → HTML (titres, listes à puces et numérotées, citations, paragraphes, gras, italique, code, liens) */
    public function toHtml(string $markdown): string
    {
        $inline = function (string $text): string {
            $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text) ?? $text;
            $text = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $text) ?? $text;
            $text = preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/s', '<em>$1</em>', $text) ?? $text;

            return preg_replace_callback('/\[([^\]]+)\]\((https?:\/\/[^)\s]+|\/[^)\s]*)\)/', fn ($m) => sprintf('<a href="%s">%s</a>', $m[2], $m[1]), $text) ?? $text;
        };
        $html = '';
        $list = null;
        $paragraph = [];
        $flush = function () use (&$html, &$paragraph, $inline) {
            if ($paragraph !== []) {
                $html .= '<p>' . $inline(implode(' ', $paragraph)) . "</p>\n";
                $paragraph = [];
            }
        };
        $closeList = function () use (&$html, &$list) {
            if ($list !== null) {
                $html .= "</$list>\n";
                $list = null;
            }
        };
        foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
            $trim = trim($line);
            if ($trim === '') {
                $flush();
                $closeList();
                continue;
            }
            if (preg_match('/^(#{1,6})\s+(.*)$/', $trim, $m)) {
                $flush();
                $closeList();
                $level = min(4, strlen($m[1]) + 1);
                $html .= sprintf("<h%d>%s</h%d>\n", $level, $inline($m[2]), $level);
                continue;
            }
            if (preg_match('/^[-*]\s+(.*)$/', $trim, $m) || preg_match('/^\d+[.)]\s+(.*)$/', $trim, $m)) {
                $flush();
                $type = preg_match('/^\d/', $trim) ? 'ol' : 'ul';
                if ($list !== $type) {
                    $closeList();
                    $html .= "<$type>\n";
                    $list = $type;
                }
                $html .= '<li>' . $inline($m[1]) . "</li>\n";
                continue;
            }
            if (str_starts_with($trim, '>')) {
                $flush();
                $closeList();
                $html .= '<blockquote>' . $inline(ltrim(substr($trim, 1))) . "</blockquote>\n";
                continue;
            }
            $closeList();
            $paragraph[] = $trim;
        }
        $flush();
        $closeList();

        return $html;
    }
}
