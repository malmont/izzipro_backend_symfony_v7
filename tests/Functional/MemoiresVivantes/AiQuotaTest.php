<?php

namespace App\Tests\Functional\MemoiresVivantes;

use App\MemoiresVivantes\Services\AudioDurationProbe;
use App\Tests\Fake\FakeAnthropicService;
use App\Tests\Fake\FakeOpenAiService;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * Quotas des appels IA (16/10/2026) : transcriptions et améliorations par utilisateur et par jour, durée maximale
 * d'un enregistrement, réglables par site. Livres mobile limités, livres web seulement sur option, administrateurs
 * jamais ; seuls les appels aboutis comptent.
 */
class AiQuotaTest extends BookTypeApiTestCase
{
    private const TZ = 'America/Martinique';

    protected function setUp(): void
    {
        parent::setUp();
        FakeOpenAiService::reset();
        self::db()->exec('DELETE FROM mv_ai_usage');
        [$status, $settings] = $this->api('PUT', '/admin/ai-quotas', ['transcriptionsPerDay' => 40, 'improvementsPerDay' => 60, 'maxAudioSeconds' => 300, 'applyToWebBooks' => false, 'timezone' => self::TZ]);
        $this->assertSame(200, $status, json_encode($settings));
    }

    public function testAMobileBookOwnerIsLimitedAndOnlySuccessfulCallsCount(): void
    {
        [$status, $settings] = $this->api('PUT', '/admin/ai-quotas', ['transcriptionsPerDay' => 2, 'improvementsPerDay' => 1]);
        $this->assertSame(200, $status);
        $this->assertSame([2, 1, 300, false, self::TZ, false], [$settings['transcriptionsPerDay'], $settings['improvementsPerDay'], $settings['maxAudioSeconds'], $settings['applyToWebBooks'], $settings['timezone'], $settings['isDefault']]);
        $chapter = $this->chapterOf('/mobile/books');

        // Rien d'audible : 422, non décompté
        FakeOpenAiService::$transcription = '';
        $r = $this->upload($chapter, 2);
        $this->assertSame(422, $r->getStatusCode(), $r->getContent());
        $this->assertTrue(json_decode($r->getContent(), true)['noSpeech']);
        [, $quotas] = $this->api('GET', '/mobile/quotas', null, 'client');
        $this->assertSame(['limit' => 2, 'used' => 0, 'remaining' => 2], array_intersect_key($quotas['transcription'], ['limit' => 1, 'used' => 1, 'remaining' => 1]));
        FakeOpenAiService::reset();

        foreach ([1, 2] as $i) {
            $r = $this->upload($chapter, 2);
            $this->assertSame(200, $r->getStatusCode(), "transcription $i : " . $r->getContent());
            $this->assertSame('Texte transcrit par la fausse IA de test.', json_decode($r->getContent(), true)['text']);
        }
        $r = $this->upload($chapter, 2);
        $this->assertSame(429, $r->getStatusCode(), $r->getContent());
        $body = json_decode($r->getContent(), true);
        $this->assertSame('QUOTA_EXCEEDED', $body['error']);
        $this->assertStringContainsString('2 transcriptions par jour', $body['message']);
        $this->assertSame(['kind' => 'transcription', 'limit' => 2, 'used' => 2, 'resetsAt' => $this->expectedReset()], $body['quota']);
        $retry = (int) $r->headers->get('Retry-After');
        $this->assertGreaterThan(0, $retry);
        $this->assertLessThanOrEqual(86400, $retry);
        $this->assertCount(2, FakeOpenAiService::$files, 'Whisper n\'est pas appelé au-delà du quota');

        // Amélioration : un 503 ne compte pas, un texte rendu compte
        FakeAnthropicService::$improvedAnswer = '';
        $this->assertSame(503, $this->improve($chapter)->getStatusCode());
        FakeAnthropicService::reset();
        $this->assertSame(200, $this->improve($chapter)->getStatusCode());
        $r = $this->improve($chapter);
        $this->assertSame(429, $r->getStatusCode(), $r->getContent());
        $this->assertSame(['kind' => 'improvement', 'limit' => 1, 'used' => 1], array_intersect_key(json_decode($r->getContent(), true)['quota'], ['kind' => 1, 'limit' => 1, 'used' => 1]));

        [, $quotas] = $this->api('GET', '/mobile/quotas', null, 'client');
        $this->assertSame(['limit' => 2, 'used' => 2, 'remaining' => 0, 'resetsAt' => $this->expectedReset()], $quotas['transcription']);
        $this->assertSame(['limit' => 1, 'used' => 1, 'remaining' => 0, 'resetsAt' => $this->expectedReset()], $quotas['improvement']);
        $this->assertSame(300, $quotas['maxAudioSeconds']);

        // Consommation du jour vue par l'administrateur
        [$status, $usage] = $this->api('GET', '/admin/ai-quotas/usage?date=' . (new \DateTimeImmutable('now', new \DateTimeZone(self::TZ)))->format('Y-m-d'));
        $this->assertSame(200, $status, json_encode($usage));
        $mine = array_values(array_filter($usage['users'], fn ($u) => $u['email'] === self::CLIENT_EMAIL));
        $this->assertSame([2, 1], [$mine[0]['transcriptions'], $mine[0]['improvements']]);
        $this->assertSame(['transcriptionsPerDay' => 2, 'improvementsPerDay' => 1], $usage['limits']);
        $this->assertSame(self::TZ, $usage['timezone']);
        [, $today] = $this->api('GET', '/admin/ai-quotas/usage');
        $this->assertSame($usage['date'], $today['date'], 'sans date : aujourd\'hui');
    }

    public function testTheBookSaysWhetherItsAiCallsAreLimitedForTheCurrentUser(): void
    {
        [, $mobile] = $this->api('POST', '/mobile/books', ['title' => 'Livre mobile', 'type' => 'individuel'], 'client');
        [, $web] = $this->api('POST', '/books', ['title' => 'Livre web', 'type' => 'individuel'], 'client');
        $this->assertSame([true, false], [$mobile['aiQuotaLimited'], $web['aiQuotaLimited']], 'dès la création');
        [, $one] = $this->api('GET', "/books/{$mobile['id']}", null, 'client');
        $this->assertTrue($one['aiQuotaLimited']);
        $byId = array_column($this->api('GET', '/books?scope=my', null, 'client')[1], null, 'id');
        $this->assertSame([true, false], [$byId[$mobile['id']]['aiQuotaLimited'], $byId[$web['id']]['aiQuotaLimited']], 'dans la liste');
        $this->assertFalse($this->api('GET', "/books/{$mobile['id']}", null, 'admin')[1]['aiQuotaLimited'], 'administrateur : jamais limité');
        $this->api('PUT', '/admin/ai-quotas', ['applyToWebBooks' => true]);
        $this->assertTrue($this->api('GET', "/books/{$web['id']}", null, 'client')[1]['aiQuotaLimited'], 'option activée : livre web limité');
        [, $updated] = $this->api('PUT', "/books/{$web['id']}", ['title' => 'Livre web renommé'], 'client');
        $this->assertTrue($updated['aiQuotaLimited']);
    }

    public function testWebBooksAreFreeUnlessEnabledZeroIsUnlimitedAndAdminsAreNeverLimited(): void
    {
        $this->api('PUT', '/admin/ai-quotas', ['transcriptionsPerDay' => 1]);
        $web = $this->chapterOf('/books');
        $this->assertSame(200, $this->upload($web, 2)->getStatusCode());
        $this->assertSame(200, $this->upload($web, 2)->getStatusCode(), 'livre web : pas de quota par défaut');
        $this->assertSame(0, (int) self::db()->query('SELECT COUNT(*) FROM mv_ai_usage')->fetchColumn(), 'rien n\'est compté quand le quota ne s\'applique pas');

        $this->api('PUT', '/admin/ai-quotas', ['applyToWebBooks' => true]);
        $this->assertSame(200, $this->upload($web, 2)->getStatusCode());
        $this->assertSame(429, $this->upload($web, 2)->getStatusCode(), 'option activée : le livre web est limité');

        $this->api('PUT', '/admin/ai-quotas', ['transcriptionsPerDay' => 0]);
        $this->assertSame(200, $this->upload($web, 2)->getStatusCode(), '0 = illimité');
        [, $quotas] = $this->api('GET', '/mobile/quotas', null, 'client');
        $this->assertSame(['limit' => null, 'remaining' => null], array_intersect_key($quotas['transcription'], ['limit' => 1, 'remaining' => 1]));

        $this->api('PUT', '/admin/ai-quotas', ['transcriptionsPerDay' => 1, 'improvementsPerDay' => 1]);
        $mobile = $this->chapterOf('/mobile/books');
        $this->assertSame(200, $this->upload($mobile, 2, 'admin')->getStatusCode());
        $this->assertSame(200, $this->upload($mobile, 2, 'admin')->getStatusCode(), 'administrateur sur le livre d\'un autre : jamais limité');
        $this->assertSame(200, $this->improve($mobile, 'admin')->getStatusCode());
        $this->assertSame(200, $this->improve($mobile, 'admin')->getStatusCode());
    }

    public function testARecordingLongerThanTheLimitIsRefusedBeforeWhisper(): void
    {
        $this->api('PUT', '/admin/ai-quotas', ['maxAudioSeconds' => 30]);
        $chapter = $this->chapterOf('/mobile/books');

        $r = $this->upload($chapter, 2, 'client', ['durationSeconds' => '400']);
        $this->assertSame(400, $r->getStatusCode(), $r->getContent());
        $this->assertSame(['AUDIO_TOO_LONG', 30], [json_decode($r->getContent(), true)['error'], json_decode($r->getContent(), true)['maxSeconds']]);
        $r = $this->upload($chapter, 31);
        $this->assertSame(400, $r->getStatusCode(), 'durée lue dans l\'en-tête WAV');
        $this->assertSame([], FakeOpenAiService::$files, 'Whisper n\'est pas appelé');
        $this->assertSame(200, $this->upload($chapter, 29)->getStatusCode());
        $this->assertEqualsWithDelta(31.0, AudioDurationProbe::seconds($this->wav(31)), 0.01);
        $this->assertNull(AudioDurationProbe::seconds(__FILE__), 'fichier inconnu : durée indéterminée');
    }

    public function testAdminApiValidationAndRoles(): void
    {
        [$status] = $this->api('PUT', '/admin/ai-quotas', ['transcriptionsPerDay' => 5], 'client');
        $this->assertSame(403, $status, 'réglage réservé aux administrateurs');
        $this->assertSame(403, $this->api('GET', '/admin/ai-quotas', null, 'client')[0]);
        $this->assertSame(403, $this->api('GET', '/admin/ai-quotas/usage', null, 'client')[0]);
        $this->assertSame(401, $this->api('GET', '/mobile/quotas', null, null)[0]);

        [$status, $r] = $this->api('PUT', '/admin/ai-quotas', ['transcriptionsPerDay' => -1, 'improvementsPerDay' => 'dix', 'maxAudioSeconds' => 10, 'timezone' => 'Mars/Olympus', 'applyToWebBooks' => 'peut-être']);
        $this->assertSame(422, $status);
        $this->assertSame('VALIDATION_FAILED', $r['error']);
        $this->assertSame(['transcriptionsPerDay', 'improvementsPerDay', 'maxAudioSeconds', 'applyToWebBooks', 'timezone'], array_column($r['errors'], 'path'));
        [$status, $r] = $this->api('PUT', '/admin/ai-quotas', ['maxAudioSeconds' => 1801]);
        $this->assertSame(422, $status);
        [$status, $r] = $this->api('GET', '/admin/ai-quotas/usage?date=2026-13-45');
        $this->assertSame(422, $status);
        [$status, $r] = $this->api('GET', '/admin/ai-quotas');
        $this->assertSame(200, $status);
        $this->assertSame([40, 60, 300, false, self::TZ], [$r['transcriptionsPerDay'], $r['improvementsPerDay'], $r['maxAudioSeconds'], $r['applyToWebBooks'], $r['timezone']], 'réglages inchangés après des envois refusés');
        [$status, $r] = $this->api('PUT', '/admin/ai-quotas', ['maxAudioSeconds' => 1800, 'timezone' => 'Europe/Paris']);
        $this->assertSame([200, 1800, 'Europe/Paris', 40], [$status, $r['maxAudioSeconds'], $r['timezone'], $r['transcriptionsPerDay']], 'champs absents : inchangés');
    }

    /** Livre (famille) et chapitre créés par le client ; rend l'identifiant du chapitre */
    private function chapterOf(string $route): string
    {
        [$status, $book] = $this->api('POST', $route, ['title' => 'Livre à quotas', 'type' => 'famille'], 'client');
        $this->assertSame(201, $status, json_encode($book));
        [$status, $chapter] = $this->api('POST', "/books/{$book['id']}/chapters", ['title' => 'Paroles', 'theme' => 'regards_croises', 'position' => 2, 'answers' => [['index' => 0, 'question' => 'Un souvenir ?', 'answer' => 'Oui']]], 'client');
        $this->assertSame(201, $status, json_encode($chapter));

        return $chapter['id'];
    }

    private function upload(string $chapterId, int $seconds, string $as = 'client', array $fields = []): Response
    {
        $file = new UploadedFile($this->wav($seconds), 'enregistrement.wav', 'audio/wav', null, true);
        $this->client->request('POST', "/api/memoires/chapters/$chapterId/audio?locale=fr", ['questionIndex' => '0'] + $fields, ['file' => $file], [
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_XSRF_TOKEN' => 'jeton-xsrf-de-test',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenFor($as),
        ]);

        return $this->client->getResponse();
    }

    private function improve(string $chapterId, string $as = 'client'): Response
    {
        $this->client->request('POST', "/api/memoires/chapters/$chapterId/improve-answer?locale=fr", [], [], [
            'HTTP_X_TENANT_HOST' => MV_TEST_TENANT_HOST, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_X_XSRF_TOKEN' => 'jeton-xsrf-de-test',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenFor($as),
        ], json_encode(['question' => 'Un souvenir ?', 'answer' => 'Oui, un très beau.', 'index' => 0]));

        return $this->client->getResponse();
    }

    /** WAV PCM 8 kHz, 8 bits, mono, de la durée demandée (8 000 octets par seconde) */
    private function wav(int $seconds): string
    {
        $dataSize = 8000 * $seconds;
        $path = tempnam(sys_get_temp_dir(), 'mvwav') . '.wav';
        $header = 'RIFF' . pack('V', 36 + $dataSize) . 'WAVE' . 'fmt ' . pack('VvvVVvv', 16, 1, 1, 8000, 8000, 1, 8) . 'data' . pack('V', $dataSize);
        file_put_contents($path, $header . str_repeat("\x80", $dataSize));

        return $path;
    }

    private function expectedReset(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone(self::TZ)))->setTime(0, 0)->modify('+1 day')->format(\DateTimeInterface::ATOM);
    }
}
