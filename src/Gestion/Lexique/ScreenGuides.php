<?php

namespace App\Gestion\Lexique;

use App\Gestion\Services\Console\ConsoleScreens;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Les GUIDES des écrans de la gestion (lot W2-APPRENDRE A-4, 13/11/2026, `docs/lexique-gestion.md` § 6) : pour chaque écran de
 * l'application, à quoi il sert, ce que veulent dire ses chiffres principaux, quoi faire ensuite. Écrits par le serveur pour un
 * débutant, sans IA, à partir du relevé de l'application web : `translations/ecrans.{fr,en}.yaml`, une entrée par code d'écran (« . » et
 * « - » remplacés par « _ »).
 *
 * Guide : `{ screen, title, theme: { key, label }, purpose, points: [{ label, text, term }], next }`. Un point dont l'identifiant est une
 * clé du lexique porte `term` : l'application peut ouvrir la définition. Les codes du premier contrat de la console renvoient au guide
 * de l'écran du web qui leur correspond (`ALIASES`).
 */
class ScreenGuides
{
    /** Code du premier contrat de la console => écran de l'application web dont il reprend le guide */
    public const ALIASES = ['accueil' => 'gestion.home', 'journee' => 'gestion.home', 'periode.resultat' => 'periode', 'periode.preparation' => 'preparation', 'commande' => 'commandes',
        'article' => 'articles', 'stock' => 'articles', 'tresorerie.rapprochements' => 'tresorerie', 'documents' => 'documents.scan', 'simulateur' => 'periode.simulation', 'console' => 'gestion.home'];
    /** Points d'un guide au plus (les écrans en ont de 1 à 5) */
    private const MAX_POINTS = 8;

    public function __construct(private readonly TranslatorInterface $translator, private readonly LexiqueTexts $lexique)
    {
    }

    public function has(mixed $code): bool
    {
        return ConsoleScreens::known($code) && $this->text(self::key((string) $code) . '.purpose', 'fr') !== null;
    }

    /** @return array<string, mixed>|null le guide de l'écran, ou null pour un code inconnu ou sans guide */
    public function guide(string $code, string $locale): ?array
    {
        if (!$this->has($code)) {
            return null;
        }
        $key = self::key($code);
        $points = [];
        foreach ($this->pointIds($key) as $id) {
            $points[] = ['label' => (string) $this->text("$key.points.$id.label", $locale), 'text' => (string) $this->text("$key.points.$id.text", $locale), 'term' => LexiqueCatalog::has($id) ? $id : null];
        }
        $theme = ConsoleScreens::theme($code, []);

        return [
            'screen' => $code,
            'title' => $this->translator->trans('console.screens.' . str_replace(['.', '-'], '_', $code), [], 'gestion', $locale === 'en' ? 'en' : 'fr'),
            'theme' => ['key' => $theme, 'label' => $this->translator->trans("console.themes.$theme", [], 'gestion', $locale === 'en' ? 'en' : 'fr')],
            'purpose' => (string) $this->text("$key.purpose", $locale),
            'points' => $points,
            'next' => $this->text("$key.next", $locale),
        ];
    }

    /** @return list<string> codes des écrans qui ont leur propre guide (sans les alias) */
    public function codes(): array
    {
        return array_values(array_filter(array_keys(ConsoleScreens::SCREENS), fn (string $code) => !isset(self::ALIASES[$code]) && $this->has($code)));
    }

    /** Clé des textes d'un écran : celle de l'écran du web pour un code du premier contrat */
    private static function key(string $code): string
    {
        return str_replace(['.', '-'], '_', self::ALIASES[$code] ?? $code);
    }

    /**
     * Les points d'un guide, dans l'ordre de son texte français : les identifiants sont lus dans le catalogue des traductions.
     *
     * @return list<string>
     */
    private function pointIds(string $key): array
    {
        $ids = [];
        if (method_exists($this->translator, 'getCatalogue')) {
            foreach (array_keys($this->translator->getCatalogue('fr')->all('ecrans')) as $id) {
                if (preg_match('/^' . preg_quote($key, '/') . '\.points\.([a-z0-9-]+)\.label$/', (string) $id, $m) === 1) {
                    $ids[] = $m[1];
                }
            }
        }

        return array_slice($ids, 0, self::MAX_POINTS);
    }

    private function text(string $id, string $locale): ?string
    {
        $text = $this->translator->trans($id, [], 'ecrans', $locale === 'en' ? 'en' : 'fr');

        return $text === $id ? null : $text;
    }
}
