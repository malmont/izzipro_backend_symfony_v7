<?php

namespace App\Tests\Functional\Security;

use App\Controller\Admin\BaseTenantCrudController;
use PHPUnit\Framework\TestCase;

/**
 * Garde-fou du lot EA-FUSEAU (12/11/2026, `docs/architecture.md`, « Dates et heures dans EasyAdmin »), sur le modèle de
 * `ClientErrorGuardTest` : dans l'administration, une date et heure se lit et se saisit à l'heure du magasin par la règle commune
 * (`StoreTimezoneFieldConfigurator`, `StoreTimezoneFilterConfigurator`), qui ne couvre que les écrans de `BaseTenantCrudController`.
 * Ce test échoue si un champ ou un filtre date et heure y échappe :
 * - écran hors de la classe de base ;
 * - fuseau fixé à la main sur un champ ou un formulaire (`setTimezone`, `view_timezone`, `model_timezone`) ;
 * - saisie de date et heure construite à la main (`DateTimeType`, `datetime-local`) dans un écran, un formulaire ou un gabarit de
 *   l'administration.
 *
 * Chaque liste dit ce qui est admis et pourquoi. Un nouvel endroit fait échouer le test : le ramener à la règle commune, ou l'inscrire
 * ici avec sa raison. Un endroit corrigé doit être retiré de la liste (le test le signale aussi).
 */
class AdminDateTimeZoneGuardTest extends TestCase
{
    /** Écran avec un champ ou un filtre date et heure, hors de la classe de base des sites : « fichier » => raison */
    private const ALLOWED_OUTSIDE_BASE = [];

    /** Fuseau fixé à la main : « fichier » => raison */
    private const ALLOWED_OWN_TIMEZONE = [
        'src/Controller/Admin/BookingCrudController.php' => 'réservations : horloge des réservations (BookingClock, lot L7b), fuseau du magasin avec son propre défaut ; affichage, saisie et filtres déjà réglés',
    ];

    /** `setTimezone` qui n'est pas celui d'un champ : « fichier » => raison */
    private const NOT_A_FIELD = [
        'src/Controller/Admin/DashboardController.php' => 'conversion d\'un instant en UTC pour les chiffres du tableau de bord',
    ];

    /** Saisie de date et heure construite à la main : « fichier » => raison */
    private const ALLOWED_RAW_INPUT = [];

    public function testEveryDateAndTimeOfTheAdministrationFollowsTheStoreTimezoneRule(): void
    {
        $outside = $own = $raw = [];
        foreach ($this->files(['src/Controller/Admin', 'src/Form', 'templates/admin']) as $file => $code) {
            if (str_starts_with($file, 'src/Controller/Admin/') && preg_match('/DateTime(Field|Filter)::new\(/', $code) && preg_match('/^namespace\s+([\w\\\\]+);.*?^(?:abstract\s+|final\s+)*class\s+(\w+)/ms', $code, $m)) {
                if (!is_subclass_of($m[1] . '\\' . $m[2], BaseTenantCrudController::class)) {
                    $outside[] = $file;
                }
            }
            if (preg_match('/->setTimezone\(|[\'"](view|model)_timezone[\'"]/', $code) && !isset(self::NOT_A_FIELD[$file])) {
                $own[] = $file;
            }
            if (preg_match('/DateTimeType::class|datetime-local/', $code)) {
                $raw[] = $file;
            }
        }
        $this->assertSame([], array_values(array_filter(array_keys(self::NOT_A_FIELD), fn (string $f) => !is_file(dirname(__DIR__, 3) . '/' . $f))), 'fichier disparu : à retirer de NOT_A_FIELD');
        $this->assertListMatches($outside, self::ALLOWED_OUTSIDE_BASE, 'écran hors de BaseTenantCrudController avec un champ ou un filtre date et heure');
        $this->assertListMatches($own, self::ALLOWED_OWN_TIMEZONE, 'fuseau fixé à la main dans l\'administration');
        $this->assertListMatches($raw, self::ALLOWED_RAW_INPUT, 'saisie de date et heure construite à la main dans l\'administration');
    }

    /** @param list<string> $found @param array<string, string> $allowed */
    private function assertListMatches(array $found, array $allowed, string $what): void
    {
        sort($found);
        $new = array_values(array_diff($found, array_keys($allowed)));
        $gone = array_values(array_diff(array_keys($allowed), $found));
        $this->assertSame([], $new, "Nouveau : $what. Le ramener à la règle commune (champ DateTimeField d'un écran de la classe de base, sans fuseau propre), ou l'inscrire avec sa raison :\n" . implode("\n", $new));
        $this->assertSame([], $gone, "Corrigé ou déplacé : à retirer de la liste ($what) :\n" . implode("\n", $gone));
    }

    /**
     * @param list<string> $folders
     *
     * @return iterable<string, string> chemin relatif => contenu
     */
    private function files(array $folders): iterable
    {
        $root = dirname(__DIR__, 3) . '/';
        $paths = [];
        foreach ($folders as $folder) {
            if (!is_dir($root . $folder)) {
                continue;
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . $folder, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if (in_array($file->getExtension(), ['php', 'twig'], true)) {
                    $paths[] = substr($file->getPathname(), strlen($root));
                }
            }
        }
        sort($paths);
        foreach ($paths as $relative) {
            yield $relative => (string) file_get_contents($root . $relative);
        }
    }
}
