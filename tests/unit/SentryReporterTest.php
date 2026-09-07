<?php

use App\Libraries\SentryReporter;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\Exceptions\BadRequestException;
use CodeIgniter\Test\CIUnitTestCase;
use Sentry\Client;
use Sentry\Event;
use Sentry\Options;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;

/**
 * SENTRY_DSN 이 없으면 SDK 를 건드리지 않아야 한다(테스트 환경에서 실제 전송 방지).
 * DSN 이 있으면 클라이언트가 바인딩되어야 운영에서 오류가 실제로 전송된다.
 *
 * 404·400 은 오류가 아니라 정상 응답이다. 스캐너가 하루 수백 건씩 만들어내므로
 * Sentry 로 보내면 진짜 오류가 노이즈에 묻힌다(#174).
 *
 * @internal
 */
final class SentryReporterTest extends CIUnitTestCase
{
    private const DSN = 'https://public@o0.ingest.sentry.io/1';

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
        $_ENV['SENTRY_DSN'] = self::DSN;

        (new SentryReporter())->boot();

        $client = \Sentry\SentrySdk::getCurrentHub()->getClient();
        $this->assertNotNull($client);
        $this->assertSame(ENVIRONMENT, $client->getOptions()->getEnvironment());
    }

    public function testDiscardsPageNotFound(): void
    {
        $transport = $this->recordingTransport();

        $eventId = $this->clientWithReporterOptions($transport)
            ->captureException(PageNotFoundException::forPageNotFound());

        $this->assertNull($eventId, '404 는 오류가 아니다 — Sentry 로 보내면 안 된다.');
        $this->assertSame([], $transport->sent, '404 이벤트가 전송돼서는 안 된다.');
    }

    public function testDiscardsBadRequest(): void
    {
        $transport = $this->recordingTransport();

        $eventId = $this->clientWithReporterOptions($transport)
            ->captureException(new BadRequestException('The URI you submitted has disallowed characters: "@fs"'));

        $this->assertNull($eventId, '400 은 클라이언트 잘못이다 — Sentry 로 보내면 안 된다.');
        $this->assertSame([], $transport->sent, '400 이벤트가 전송돼서는 안 된다.');
    }

    /**
     * 대조군. 필터가 전부를 삼키면 위의 두 테스트는 통과하면서 모니터링이 죽는다.
     */
    public function testStillSendsServerErrors(): void
    {
        $transport = $this->recordingTransport();

        $eventId = $this->clientWithReporterOptions($transport)
            ->captureException(new RuntimeException('진짜 오류'));

        $this->assertNotNull($eventId, '서버 오류는 계속 보고돼야 한다.');
        $this->assertCount(1, $transport->sent);
    }

    /**
     * 위 세 테스트는 options() 를 직접 읽는다. boot() 가 그 옵션을 실제로 SDK 에
     * 넘기지 않으면 필터는 운영에서 동작하지 않으므로, 연결 자체를 따로 확인한다.
     */
    public function testBootAppliesTheIgnoreListToTheLiveClient(): void
    {
        $_ENV['SENTRY_DSN'] = self::DSN;

        (new SentryReporter())->boot();

        $live = \Sentry\SentrySdk::getCurrentHub()->getClient()->getOptions()->getIgnoreExceptions();
        $this->assertSame(
            (new SentryReporter())->options(self::DSN)['ignore_exceptions'],
            $live,
            'boot() 가 options() 를 그대로 SDK 에 넘겨야 필터가 운영에서 산다.'
        );
    }

    private function clientWithReporterOptions(TransportInterface $transport): Client
    {
        return new Client(new Options((new SentryReporter())->options(self::DSN)), $transport);
    }

    /**
     * @return TransportInterface&object{sent: list<Event>}
     */
    private function recordingTransport(): TransportInterface
    {
        return new class implements TransportInterface {
            /** @var list<Event> */
            public array $sent = [];

            public function send(Event $event): Result
            {
                $this->sent[] = $event;

                return new Result(ResultStatus::success(), $event);
            }

            public function close(?int $timeout = null): Result
            {
                return new Result(ResultStatus::success());
            }
        };
    }
}
