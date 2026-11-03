<?php

namespace App\Tests\Functional\Support;

use App\Entity\User;
use App\Services\TenantEntityManagerProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Validation du code OTP après un `loginUser` de test (03/11/2026). Un super administrateur doit toujours saisir le code reçu par
 * courriel (TwoFactorPolicy) : sans cette étape, PendingOtpSubscriber renvoie chaque page vers /account/otp. L'aide rejoue le parcours
 * réel : une page bloquée pose le compte en attente, un code connu est inséré en base, puis validé sur la page du code.
 * Sans effet pour un compte qui n'a pas besoin du code.
 */
final class OtpTestHelper
{
    public const CODE = '424242';

    public static function validateIfRequired(KernelBrowser $client, User $user, string $host = MV_TEST_TENANT_HOST, string $db = MV_TEST_TENANT_DB, string $code = MV_TEST_TENANT_CODE): void
    {
        if (!in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true) && !$user->isOtpEnabled()) {
            return;
        }
        $server = ['HTTP_X_TENANT_HOST' => $host];
        $client->request('GET', 'https://' . $host . '/admin', [], [], $server); // bloquée : pose pending_otp_user dans la session
        if ($client->getResponse()->getStatusCode() !== 302) {
            return; // déjà validé (ou compte non concerné)
        }
        $provider = $client->getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant($db, $code);
        $provider->getEntityManager()->getConnection()->executeStatement(
            "INSERT INTO otp_code (id, user_otp_id, code, expiration) VALUES (nextval('otp_code_id_seq'), ?, ?, NOW() + INTERVAL '5 minutes')",
            [$user->getId(), self::CODE]
        );
        $page = $client->request('GET', 'https://' . $host . '/account/otp', [], [], $server);
        $token = $page->filter('input[name="_csrf_token"]')->attr('value');
        $client->request('POST', 'https://' . $host . '/account/otp', ['otp' => self::CODE, '_csrf_token' => $token], [], $server);
    }
}
