<?php

namespace App\Tests\Functional\Security;

use PHPUnit\Framework\TestCase;

/**
 * Garde-fou du lot ERREURS-1 (12/11/2026, `docs/erreurs-client.md`), sur le modèle de `AnonymousWriteAccessTest` : il parcourt `src/` et
 * échoue si un « catch » large (`Exception`, `Throwable`, `RuntimeException`, `LogicException`, `Error`, ou toute erreur de l'API Stripe)
 * rend `getMessage()` vers une sortie (réponse JSON, message d'écran, tableau, valeur de retour) sans passer par `ClientSafeError`, ou si une exception est construite avec le message
 * d'une autre sans la garder en « previous » (la faille du critère par origine : le texte technique passerait pour le nôtre).
 *
 * Les deux listes ci-dessous disent ce qui est admis et pourquoi. Un nouvel endroit fait échouer le test : le corriger, ou l'inscrire ici
 * avec sa raison. Un endroit corrigé doit être retiré de la liste (le test le signale aussi).
 */
class ClientErrorGuardTest extends TestCase
{
    private const LARGE = ['Exception', 'Throwable', 'RuntimeException', 'LogicException', 'Error', 'ApiErrorException']; // ApiErrorException : toute erreur de l'API Stripe

    /** Dossiers des lots à venir : ERREURS-3 (Boussole ESG, Mémoires Vivantes), confié à leurs sessions */
    private const PENDING_FOLDERS = ['src/ESG/']; // Mémoires Vivantes balayé depuis ERREURS-3 (13/11/2026)

    /** « fichier::méthode » => raison. JOURNAL : le message part dans un journal ou une console, pas au client. ERREURS-2 : à traiter. */
    private const ALLOWED_CATCHES = [
        'src/Cache/CacheInvalidationQueue.php::invalidate' => 'JOURNAL : contexte d\'un avertissement',
        'src/Command/PosTerminalReconcileCommand.php::execute' => 'JOURNAL : console et fichier d\'état de la surveillance, lus par le super administrateur',
        'src/Gestion/Services/GestionModuleTransitionService.php::run' => 'JOURNAL : résultat d\'une commande de console',
        'src/Gestion/Services/GestionNotificationService.php::notify' => 'JOURNAL : colonne error du journal des notifications, que l\'API ne rend pas',
        'src/Services/AccountService/EmailChangeGuard.php::notifyChanged' => 'JOURNAL de sécurité',
        'src/UseCase/AccountUseCase/AdminEraseCustomerAccountUseCase.php::execute' => 'JOURNAL de sécurité',
        'src/UseCase/AccountUseCase/DeleteAccountUseCase.php::execute' => 'JOURNAL de sécurité',
        'src/UseCase/SubscriptionUseCase/HandleSubscriptionWebhookUseCase.php::keepPaymentMethod' => 'JOURNAL : contexte d\'un avertissement',
        'src/Services/AppKeyService/AppActivationService.php::activate' => 'ERREURS-2',
        'src/Controller/Admin/ConfigurationConsoleAdminController.php::defaults' => 'ERREURS-2',
        'src/Controller/TenantSetupController/TenantSetupController.php::setup' => 'ERREURS-2',
        'src/Services/LandingAiService/LandingAiComposer.php::call' => 'ERREURS-2',
        'src/Services/SiteCharter/SiteCompositionApplier.php::applyShop' => 'ERREURS-2',
        'src/UseCase/LandingAiUseCase/RunLandingAiJobUseCase.php::execute' => 'ERREURS-2',
        'src/UseCase/LandingConfigUseCase/SyncLandingConfigUseCase.php::checkContents' => 'ERREURS-2',
        'src/UseCase/SiteAssistantUseCase/ConfirmSiteAssistantActionUseCase.php::batch' => 'ERREURS-2',
        'src/MemoiresVivantes/Controller/BookController.php::update' => 'ERREURS-3 : App\\MemoiresVivantes\\Exception\\ApiErrorException (la nôtre, homonyme de celle de Stripe), refus rédigés BIOGRAPHER_INVALID et FORBIDDEN du biographe affecté',
        'src/MemoiresVivantes/EventListener/MemoireQuestionAnswerSyncListener.php::postFlush' => 'JOURNAL : échec de synchronisation des réponses',
        'src/MemoiresVivantes/Services/PrintPaymentService.php::completeFromWebhook' => 'ERREURS-3 : notre ApiErrorException rédigée, rendue au webhook Stripe et au journal, jamais au client',
    ];

    /** « fichier::méthode » => raison : exception construite avec le message d'une autre, sans « previous » */
    private const ALLOWED_REWRAPS = [
        'src/Controller/Admin/TaxCrudController.php::deleteEntity' => 'notre propre exception, construite pour son message (TaxInUseException)',
        'src/Services/ContentAuditService/ContentAuditRestorer.php::restore' => 'reprend un refus rédigé (LandingContentException)',
        'src/Services/LandingContentService/BoutiqueCatalogEditor.php::addCombination' => 'message d\'une contrainte de validation, rédigé',
        'src/Services/PosCouponService/PosCouponService.php::normalize' => 'reprend un refus rédigé (CouponBarcodeException)',
        'src/Services/PosCouponService/PosCouponService.php::parse' => 'reprend un refus rédigé (CouponBarcodeException)',
        'src/Services/PosDiscountService/PosDiscountService.php::check' => 'reprend un refus rédigé (CartQuoteException)',
        'src/Services/PosDiscountService/PosDiscountService.php::quote' => 'reprend un refus rédigé (CartQuoteException)',
        'src/Services/PosReturnService/PosReturnService.php::linesFromCatalog' => 'reprend un refus rédigé (CartQuoteException)',
        'src/Services/PosTerminalService/PosTerminalPaymentService.php::captureForSale' => 'reprend PosTerminalGatewayException, dont le texte est rédigé ou neutralisé à la passerelle',
        'src/Services/PosTerminalService/PosTerminalPaymentService.php::refundOnline' => 'reprend PosTerminalGatewayException, dont le texte est rédigé ou neutralisé à la passerelle',
        'src/Services/PosTerminalService/PosTerminalPaymentService.php::start' => 'reprend un refus rédigé (CartQuoteException)',
        'src/Services/PosTerminalService/PosTerminalPaymentService.php::translate' => 'reprend PosTerminalGatewayException, dont le texte est rédigé ou neutralisé à la passerelle',
        'src/UseCase/CheckoutUseCase/PrepareCheckoutUseCase.php::execute' => 'reprend un refus rédigé (CartQuoteException)',
        'src/UseCase/OrderUseCase/CreateOrderUseCase.php::quoteTotal' => 'reprend un refus rédigé (CartQuoteException)',
        'src/Form/DataTransformer/JsonToStringTransformer.php::reverseTransform' => 'ERREURS-2 : message de PHP (JSON invalide) dans un formulaire EasyAdmin',
        'src/Services/LandingConfigService/FrontendConfigFetcher.php::download' => 'ERREURS-2',
        'src/UseCase/FormSubmissionUseCase/SubmitFormUseCase.php::execute' => 'ERREURS-2 : reprend une DomainException de notre code (créneau pris)',
        'src/UseCase/MediaUseCase/UploadMediaUseCase.php::execute' => 'ERREURS-2 : reprend une InvalidArgumentException de notre stockage',
    ];

    public function testNoBroadCatchReturnsAnExceptionMessageToTheClient(): void
    {
        $found = [];
        foreach ($this->sources() as $file => $code) {
            preg_match_all('/catch\s*\(\s*([^)$]+?)\s+\$(\w+)\s*\)\s*\{/', $code, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
            foreach ($matches as $m) {
                $types = array_map(fn (string $t) => ltrim(substr(strrchr('\\' . trim($t), '\\'), 1), '\\'), explode('|', $m[1][0]));
                if (array_intersect($types, self::LARGE) === []) {
                    continue;
                }
                $body = $this->block($code, $m[0][1] + strlen($m[0][0]));
                $var = preg_quote($m[2][0], '/');
                if (!preg_match('/\$' . $var . '->getMessage\(\)/', $body) || str_contains($body, 'safeError') || str_contains($body, 'ClientSafeError')) {
                    continue;
                }
                if (preg_match('/(->json\(|JsonResponse\(|new Response\(|addFlash\(|\breturn\b|=>)[^;]*\$' . $var . '->getMessage\(\)/s', $body)) {
                    $found[$file . '::' . $this->enclosingFunction($code, $m[0][1])] = true;
                }
            }
        }
        $this->assertListMatches(array_keys($found), self::ALLOWED_CATCHES, '« catch » large qui rend le message d\'une exception');
    }

    public function testNoExceptionIsRebuiltFromAnotherOnesMessageWithoutKeepingItAsPrevious(): void
    {
        $found = [];
        foreach ($this->sources() as $file => $code) {
            preg_match_all('/new\s+\\\\?[\w\\\\]*Exception\s*\(([^;]*?->getMessage\(\)[^;]*)\)\s*;/s', $code, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
            foreach ($matches as $m) {
                // la cause est gardée quand la variable d'exception est aussi passée seule en argument : « , $e) » ou « previous: $e »
                if (preg_match('/,\s*\$\w+\s*$/', rtrim($m[1][0])) || str_contains($m[1][0], 'previous:')) {
                    continue;
                }
                $found[$file . '::' . $this->enclosingFunction($code, $m[0][1])] = true;
            }
        }
        $this->assertListMatches(array_keys($found), self::ALLOWED_REWRAPS, 'exception reconstruite avec le message d\'une autre, sans « previous »');
    }

    /** @param list<string> $found @param array<string, string> $allowed */
    private function assertListMatches(array $found, array $allowed, string $what): void
    {
        sort($found);
        $new = array_values(array_diff($found, array_keys($allowed)));
        $gone = array_values(array_diff(array_keys($allowed), $found));
        $this->assertSame([], $new, "Nouveau : $what. Passer par ClientSafeError (ou garder la cause en « previous »), ou l'inscrire avec sa raison :\n" . implode("\n", $new));
        $this->assertSame([], $gone, "Corrigé ou déplacé : à retirer de la liste ($what) :\n" . implode("\n", $gone));
    }

    /** @return iterable<string, string> chemin relatif => code, hors des dossiers des lots à venir */
    private function sources(): iterable
    {
        $root = dirname(__DIR__, 3) . '/';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . 'src', \FilesystemIterator::SKIP_DOTS));
        $paths = [];
        foreach ($files as $file) {
            $relative = substr($file->getPathname(), strlen($root));
            if ($file->getExtension() === 'php' && array_filter(self::PENDING_FOLDERS, fn (string $p) => str_starts_with($relative, $p)) === []) {
                $paths[] = $relative;
            }
        }
        sort($paths);
        foreach ($paths as $relative) {
            yield $relative => (string) file_get_contents($root . $relative);
        }
    }

    private function block(string $code, int $start): string
    {
        $depth = 1;
        $length = strlen($code);
        for ($i = $start; $i < $length && $depth > 0; ++$i) {
            $depth += ($code[$i] === '{') - ($code[$i] === '}');
        }

        return substr($code, $start, $i - $start - 1);
    }

    private function enclosingFunction(string $code, int $offset): string
    {
        preg_match_all('/function\s+(\w+)\s*\(/', substr($code, 0, $offset), $m);

        return $m[1] !== [] ? end($m[1]) : '?';
    }
}
