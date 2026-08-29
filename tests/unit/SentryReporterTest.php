<?php

use App\Libraries\SentryReporter;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * SENTRY_DSN 이 없으면 SDK 를 건드리지 않아야 한다(테스트 환경에서 실제 전송 방지).
 * DSN 이 있으면 클라이언트가 바인딩되어야 운영에서 오류가 실제로 전송된다.
 *
 * @internal
 */
final class SentryReporterTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        unset($_ENV['SENTRY_DSN'], $_SERVER['SENTRY_DSN']);
        \Sentry\SentrySdk::init();
    }

    protected function tearDown(): void
    {
        unset($_ENV['SENTRY_DSN'], $_SERVER['SENTRY_DSN']);
        \Sentry\SentrySdk::init();
        parent::tearDown();
    }

    public function testDoesNothingWithoutDsn(): void
    {
        (new SentryReporter())->boot();

        $this->assertNull(\Sentry\SentrySdk::getCurrentHub()->getClient());
    }

    public function testBindsClientWhenDsnIsConfigured(): void
    {
        $_ENV['SENTRY_DSN'] = 'https://public@o0.ingest.sentry.io/1';

        (new SentryReporter())->boot();

        $client = \Sentry\SentrySdk::getCurrentHub()->getClient();
        $this->assertNotNull($client);
        $this->assertSame(ENVIRONMENT, $client->getOptions()->getEnvironment());
    }
}
