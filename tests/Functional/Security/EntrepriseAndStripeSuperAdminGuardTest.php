<?php

namespace App\Tests\Functional\Security;

use App\Controller\Admin\EntrepriseCrudController;
use App\Controller\Admin\StripeConfigCrudController;
use App\Entity\User;
use App\Security\RoleAssignmentPolicy;
use App\Services\TenantEntityManagerProvider;
use App\Tests\Functional\Support\OtpTestHelper;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Sous-lot 2a — Réglages réservés au super administrateur dans la fiche Entreprise et les écrans Stripe :
 * 1. EntrepriseCrudController : commissions terminal (posTerminalFeeCardBp, posTerminalFeeInteracBp), activations de modules
 *    (isBoutiqueActive, isLandingPageActive, isBoussoleEsgActive, isMemoireVivanteActive), activations d'applications mobiles
 *    (isPosActive, isMobileShopActive, isManagementAppActive, isLivingMemoryActive) et options du kit Terminal
 *    (Tap to Pay et lecteur Bluetooth restent à l'administrateur du site : réglages de sa caisse) ;
 * 2. StripeConfigCrudController : accountId et onlineFeeBp réservés au super admin, isActive laissé à l'administrateur ;
 * 3. PosStripeAdminController : bascule mode test ↔ mode réel réservée au super admin (bouton masqué + 403 en POST).
 */
class EntrepriseAndStripeSuperAdminGuardTest extends WebTestCase
{
    private const ENTREPRISE_LOCKED_FIELDS = [
        'posTerminalFeeCardBp',
        'posTerminalFeeInteracBp',
        'isBoutiqueActive',
        'isLandingPageActive',
        'isBoussoleEsgActive',
        'isMemoireVivanteActive',
        'isPosActive',
        'isMobileShopActive',
        'isManagementAppActive',
        'isLivingMemoryActive',
    ];

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->setServerParameter('HTTP_X_TENANT_HOST', MV_TEST_TENANT_HOST);
    }

    public function testEntrepriseLockedFieldsAreProtectedAgainstForgedPostWhileAllowedFieldsAreSaved(): void
    {
        $db = $this->db();
        $id = (int) $db->fetchOne('SELECT MIN(id) FROM entreprise');
        $this->assertGreaterThan(0, $id);
        $snapshot = $db->fetchAssociative('SELECT * FROM entreprise WHERE id = ?', [$id]);
        $this->assertIsArray($snapshot);

        try {
            $db->executeStatement(
                'UPDATE entreprise SET
                    pos_terminal_fee_card_bp = 120,
                    pos_terminal_fee_interac_bp = 110,
                    is_boutique_active = true,
                    is_landing_page_active = true,
                    is_boussole_esg_active = false,
                    is_memoire_vivante_active = false,
                    is_pos_active = true,
                    is_mobile_shop_active = false,
                    is_management_app_active = false,
                    is_living_memory_active = false,
                    pos_tap_to_pay_allowed = false,
                    pos_bluetooth_reader_allowed = false
                 WHERE id = ?',
                [$id]
            );
            $this->em()->clear();

            // 1. Administrateur de site : champs réservés verrouillés à l'écran, champs du site modifiables
            $this->loginAs(['ROLE_ADMIN']);
            $crawler = $this->crud(EntrepriseCrudController::class, 'edit', $id);

            foreach (self::ENTREPRISE_LOCKED_FIELDS as $field) {
                $input = $crawler->filter(sprintf('input[name="Entreprise[%s]"]', $field));
                $this->assertCount(1, $input, "champ $field présent dans le formulaire");
                $this->assertSame('disabled', $input->attr('disabled'), "champ $field verrouillé pour ROLE_ADMIN");
            }
            foreach (['name', 'posTimezone', 'dataRetentionYears', 'metaTitle'] as $allowedField) {
                $input = $crawler->filter(sprintf('input[name="Entreprise[%s]"]', $allowedField));
                $this->assertCount(1, $input, "champ $allowedField présent");
                $this->assertNull($input->attr('disabled'), "champ $allowedField modifiable par ROLE_ADMIN");
            }

            // Envoi forgé par l'administrateur de site : tente de modifier les 12 champs réservés + 4 champs autorisés
            $form = $crawler->filter('form[name="edit-Entreprise-form"], form[name="Entreprise"]')->form();
            $values = $form->getPhpValues();
            $values['Entreprise']['name'] = 'Entreprise Admin Site';
            $values['Entreprise']['posTimezone'] = 'America/Toronto';
            $values['Entreprise']['dataRetentionYears'] = '12';
            $values['Entreprise']['metaTitle'] = 'Meta Admin Site';

            $values['Entreprise']['posTerminalFeeCardBp'] = '450';
            $values['Entreprise']['posTerminalFeeInteracBp'] = '350';
            unset(
                $values['Entreprise']['isBoutiqueActive'],
                $values['Entreprise']['isLandingPageActive'],
                $values['Entreprise']['isPosActive']
            );
            $values['Entreprise']['isBoussoleEsgActive'] = '1';
            $values['Entreprise']['isMemoireVivanteActive'] = '1';
            $values['Entreprise']['isMobileShopActive'] = '1';
            $values['Entreprise']['isManagementAppActive'] = '1';
            $values['Entreprise']['isLivingMemoryActive'] = '1';
            $values['Entreprise']['posTapToPayAllowed'] = '1';
            $values['Entreprise']['posBluetoothReaderAllowed'] = '1';

            $this->client->request('POST', $form->getUri(), $values);
            $this->assertTrue($this->client->getResponse()->isRedirect(), (string) $this->client->getResponse()->getContent());

            $row = $db->fetchAssociative('SELECT * FROM entreprise WHERE id = ?', [$id]);
            $this->assertIsArray($row);
            // Champs autorisés enregistrés
            $this->assertSame('Entreprise Admin Site', $row['name']);
            $this->assertSame('America/Toronto', $row['pos_timezone']);
            $this->assertSame(12, (int) $row['data_retention_years']);
            $this->assertSame('Meta Admin Site', $row['meta_title']);
            // Les 12 champs réservés sont restés intacts en base
            $this->assertSame(120, (int) $row['pos_terminal_fee_card_bp']);
            $this->assertSame(110, (int) $row['pos_terminal_fee_interac_bp']);
            $this->assertTrue((bool) $row['is_boutique_active']);
            $this->assertTrue((bool) $row['is_landing_page_active']);
            $this->assertFalse((bool) $row['is_boussole_esg_active']);
            $this->assertFalse((bool) $row['is_memoire_vivante_active']);
            $this->assertTrue((bool) $row['is_pos_active']);
            $this->assertFalse((bool) $row['is_mobile_shop_active']);
            $this->assertFalse((bool) $row['is_management_app_active']);
            $this->assertFalse((bool) $row['is_living_memory_active']);
            $this->assertTrue((bool) $row['pos_tap_to_pay_allowed'], 'Tap to Pay : réglage de la caisse, modifiable par l\'administrateur du site');
            $this->assertTrue((bool) $row['pos_bluetooth_reader_allowed'], 'lecteur Bluetooth : idem');

            // 2. Super administrateur : champs déverrouillés et modifiables
            $this->loginAs(['ROLE_ADMIN', RoleAssignmentPolicy::SUPER_ADMIN]);
            $crawler = $this->crud(EntrepriseCrudController::class, 'edit', $id);
            foreach (self::ENTREPRISE_LOCKED_FIELDS as $field) {
                $input = $crawler->filter(sprintf('input[name="Entreprise[%s]"]', $field));
                $this->assertCount(1, $input);
                $this->assertNull($input->attr('disabled'), "champ $field déverrouillé pour ROLE_SUPER_ADMIN");
            }

            $form = $crawler->filter('form[name="edit-Entreprise-form"], form[name="Entreprise"]')->form();
            $values = $form->getPhpValues();
            $values['Entreprise']['posTerminalFeeCardBp'] = '240';
            $values['Entreprise']['posTerminalFeeInteracBp'] = '190';
            $values['Entreprise']['isBoussoleEsgActive'] = '1';
            $values['Entreprise']['isMemoireVivanteActive'] = '1';
            $values['Entreprise']['posTapToPayAllowed'] = '1';
            $values['Entreprise']['posBluetoothReaderAllowed'] = '1';

            $this->client->request('POST', $form->getUri(), $values);
            $this->assertTrue($this->client->getResponse()->isRedirect(), (string) $this->client->getResponse()->getContent());

            $row = $db->fetchAssociative('SELECT * FROM entreprise WHERE id = ?', [$id]);
            $this->assertIsArray($row);
            $this->assertSame(240, (int) $row['pos_terminal_fee_card_bp']);
            $this->assertSame(190, (int) $row['pos_terminal_fee_interac_bp']);
            $this->assertTrue((bool) $row['is_boussole_esg_active']);
            $this->assertTrue((bool) $row['is_memoire_vivante_active']);
            $this->assertTrue((bool) $row['pos_tap_to_pay_allowed']);
            $this->assertTrue((bool) $row['pos_bluetooth_reader_allowed']);
        } finally {
            $db->update('entreprise', $snapshot, ['id' => $id], self::types($snapshot)); // booléens typés : PDO pgsql lie false à '' sinon
            $this->em()->clear();
        }
    }

    public function testStripeConfigLocksAccountIdAndOnlineFeeBpForSiteAdminWhileAllowingIsActive(): void
    {
        $db = $this->db();
        $id = (int) $db->fetchOne('SELECT MIN(id) FROM stripe_config');
        $this->assertGreaterThan(0, $id);
        $snapshot = $db->fetchAssociative('SELECT * FROM stripe_config WHERE id = ?', [$id]);
        $this->assertIsArray($snapshot);

        try {
            $db->executeStatement(
                'UPDATE stripe_config SET account_id = ?, online_fee_bp = 150, is_active = true WHERE id = ?',
                ['acct_initial_guard', $id]
            );
            $this->em()->clear();

            // 1. Administrateur de site : accountId et onlineFeeBp verrouillés, isActive modifiable
            $this->loginAs(['ROLE_ADMIN']);
            $crawler = $this->crud(StripeConfigCrudController::class, 'edit', $id);

            $this->assertSame('disabled', $crawler->filter('input[name="StripeConfig[accountId]"]')->attr('disabled'));
            $this->assertSame('disabled', $crawler->filter('input[name="StripeConfig[onlineFeeBp]"]')->attr('disabled'));
            $this->assertNull($crawler->filter('input[name="StripeConfig[isActive]"]')->attr('disabled'));

            // Envoi forgé par l'administrateur de site
            $form = $crawler->filter('form[name="edit-StripeConfig-form"], form[name="StripeConfig"]')->form();
            $values = $form->getPhpValues();
            $values['StripeConfig']['accountId'] = 'acct_pirate_999';
            $values['StripeConfig']['onlineFeeBp'] = '0';
            unset($values['StripeConfig']['isActive']);

            $this->client->request('POST', $form->getUri(), $values);
            $this->assertTrue($this->client->getResponse()->isRedirect(), (string) $this->client->getResponse()->getContent());

            $row = $db->fetchAssociative('SELECT account_id, online_fee_bp, is_active FROM stripe_config WHERE id = ?', [$id]);
            $this->assertIsArray($row);
            $this->assertFalse((bool) $row['is_active'], 'isActive modifié par l\'administrateur de site');
            $this->assertSame('acct_initial_guard', $row['account_id'], 'accountId forgé ignoré');
            $this->assertSame(150, (int) $row['online_fee_bp'], 'onlineFeeBp forgé ignoré');

            // 2. Super administrateur : accountId et onlineFeeBp modifiables
            $this->loginAs(['ROLE_ADMIN', RoleAssignmentPolicy::SUPER_ADMIN]);
            $crawler = $this->crud(StripeConfigCrudController::class, 'edit', $id);
            $this->assertNull($crawler->filter('input[name="StripeConfig[accountId]"]')->attr('disabled'));
            $this->assertNull($crawler->filter('input[name="StripeConfig[onlineFeeBp]"]')->attr('disabled'));

            $form = $crawler->filter('form[name="edit-StripeConfig-form"], form[name="StripeConfig"]')->form();
            $values = $form->getPhpValues();
            $values['StripeConfig']['accountId'] = 'acct_super_ok';
            $values['StripeConfig']['onlineFeeBp'] = '275';
            $values['StripeConfig']['isActive'] = '1';

            $this->client->request('POST', $form->getUri(), $values);
            $this->assertTrue($this->client->getResponse()->isRedirect(), (string) $this->client->getResponse()->getContent());

            $row = $db->fetchAssociative('SELECT account_id, online_fee_bp, is_active FROM stripe_config WHERE id = ?', [$id]);
            $this->assertIsArray($row);
            $this->assertSame('acct_super_ok', $row['account_id']);
            $this->assertSame(275, (int) $row['online_fee_bp']);
            $this->assertTrue((bool) $row['is_active']);
        } finally {
            $db->update('stripe_config', $snapshot, ['id' => $id], self::types($snapshot));
            $this->em()->clear();
        }
    }

    public function testPosStripeModeSwitchIsReservedToSuperAdmin(): void
    {
        $db = $this->db();
        $id = (int) $db->fetchOne('SELECT MIN(id) FROM entreprise');
        $initialMode = (string) $db->fetchOne('SELECT pos_stripe_mode FROM entreprise WHERE id = ?', [$id]);

        try {
            $db->executeStatement("UPDATE entreprise SET pos_stripe_mode = 'test' WHERE id = ?", [$id]);
            $this->em()->clear();

            // 1. Administrateur de site : page visible, bouton de bascule masqué, POST forgé -> 403
            $this->loginAs(['ROLE_ADMIN']);
            $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/pos/stripe');
            $this->assertSame(200, $this->client->getResponse()->getStatusCode());
            $this->assertCount(1, $crawler->filter('button[name="action"][value="location"]'), 'action location accessible à ROLE_ADMIN');
            $this->assertCount(0, $crawler->filter('button[name="action"][value="mode_live"], button[name="action"][value="mode_test"]'), 'bouton de bascule masqué pour ROLE_ADMIN');

            $csrfToken = (string) $crawler->filter('form input[name="_token"]')->attr('value');
            $this->assertNotEmpty($csrfToken);

            $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/admin/pos/stripe', [
                'action' => 'mode_live',
                '_token' => $csrfToken,
            ]);
            $this->assertSame(403, $this->client->getResponse()->getStatusCode(), 'bascule mode_live forgée refusée en 403');
            $this->assertSame('test', $db->fetchOne('SELECT pos_stripe_mode FROM entreprise WHERE id = ?', [$id]));

            $db->executeStatement("UPDATE entreprise SET pos_stripe_mode = 'live' WHERE id = ?", [$id]);
            $this->em()->clear();

            $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/admin/pos/stripe', [
                'action' => 'mode_test',
                '_token' => $csrfToken,
            ]);
            $this->assertSame(403, $this->client->getResponse()->getStatusCode(), 'bascule mode_test forgée refusée en 403');
            $this->assertSame('live', $db->fetchOne('SELECT pos_stripe_mode FROM entreprise WHERE id = ?', [$id]));

            // 2. Super administrateur : bouton visible et bascule effective. La bascule refuse tant qu'un paiement par terminal est en
            // cours : les paiements laissés en attente par d'autres tests sont mis de côté le temps de ce test, puis restaurés.
            $pendingStatuses = implode(', ', array_map(fn ($st) => $db->quote($st), \App\Entity\PosTerminalPayment::PENDING));
            $pending = $db->fetchAllAssociative("SELECT id, status, refund_status FROM pos_terminal_payment WHERE status IN ($pendingStatuses) OR refund_status = ?", [\App\Entity\PosTerminalPayment::REFUND_WAITING]);
            foreach ($pending as $payment) {
                $db->executeStatement('UPDATE pos_terminal_payment SET status = ?, refund_status = NULL WHERE id = ?', [\App\Entity\PosTerminalPayment::CANCELED, $payment['id']]);
            }
            $this->em()->clear();
            $this->loginAs(['ROLE_ADMIN', RoleAssignmentPolicy::SUPER_ADMIN]);
            $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin/pos/stripe');
            $this->assertSame(200, $this->client->getResponse()->getStatusCode());
            $this->assertCount(1, $crawler->filter('button[name="action"][value="mode_test"]'), 'bouton mode_test visible pour ROLE_SUPER_ADMIN');

            $csrfToken = (string) $crawler->filter('form input[name="_token"]')->attr('value');
            $this->client->request('POST', 'https://' . MV_TEST_TENANT_HOST . '/admin/pos/stripe', [
                'action' => 'mode_test',
                '_token' => $csrfToken,
            ]);
            $this->assertSame(200, $this->client->getResponse()->getStatusCode());
            $this->assertSame('test', $db->fetchOne('SELECT pos_stripe_mode FROM entreprise WHERE id = ?', [$id]), 'bascule effective par le super admin : ' . strip_tags((string) $this->client->getCrawler()->filter('.alert')->text('')));
        } finally {
            foreach ($pending ?? [] as $payment) {
                $db->executeStatement('UPDATE pos_terminal_payment SET status = ?, refund_status = ? WHERE id = ?', [$payment['status'], $payment['refund_status'], $payment['id']]);
            }
            $db->executeStatement('UPDATE entreprise SET pos_stripe_mode = ? WHERE id = ?', [$initialMode ?: 'test', $id]);
            $this->em()->clear();
        }
    }

    private function crud(string $controller, string $action, int $entityId): Crawler
    {
        $crawler = $this->client->request('GET', 'https://' . MV_TEST_TENANT_HOST . '/admin?' . http_build_query([
            'crudAction' => $action,
            'crudControllerFqcn' => $controller,
            'entityId' => $entityId,
        ]));
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());

        return $crawler;
    }

    private function loginAs(array $roles): User
    {
        $email = 'guard-2a-' . bin2hex(random_bytes(3)) . '@example.invalid';
        $user = (new User())
            ->setEmail($email)
            ->setUsername($email)
            ->setFirstname('Test')
            ->setLastname('Lot2a')
            ->setRoles($roles)
            ->setIsVerified(true)
            ->setPassword('x');
        $em = $this->em();
        $em->persist($user);
        $em->flush();

        $this->client->loginUser($user, 'main');
        OtpTestHelper::validateIfRequired($this->client, $user);

        return $user;
    }

    private function em()
    {
        $provider = static::getContainer()->get(TenantEntityManagerProvider::class);
        $provider->switchTenant(MV_TEST_TENANT_DB, MV_TEST_TENANT_CODE);

        return $provider->getEntityManager();
    }

    private function db(): Connection
    {
        return $this->em()->getConnection();
    }

    /** Types DBAL d'une ligne lue par fetchAssociative : les booléens doivent être liés comme tels (PDO pgsql lie false à '') */
    private static function types(array $row): array
    {
        return array_map(fn ($v) => is_bool($v) ? \Doctrine\DBAL\ParameterType::BOOLEAN : (is_int($v) ? \Doctrine\DBAL\ParameterType::INTEGER : \Doctrine\DBAL\ParameterType::STRING), $row);
    }
}
