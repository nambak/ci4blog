<?php

namespace App\Libraries;

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\Exceptions\BadRequestException;

/**
 * 운영 서버 오류를 Sentry 로 보낸다. SENTRY_DSN 이 비어 있으면 SDK 를 초기화하지
 * 않는다 — 로컬·테스트 환경에서 실수로 전송되는 것을 막기 위해서다.
 */
class SentryReporter
{
    public function boot(): void
    {
        $dsn = env('SENTRY_DSN', '');

        if (! is_string($dsn) || trim($dsn) === '') {
            return;
        }

        \Sentry\init($this->options($dsn));
    }

    /**
     * SDK 에 넘길 옵션. 테스트가 같은 옵션으로 클라이언트를 만들어 필터가 실제로
     * 이벤트를 거르는지 확인하므로 boot() 밖으로 빼 둔다.
     *
     * ignore_exceptions 는 클래스 계층으로 매칭된다(Client::shouldIgnoreException).
     *
     * @return array<string, mixed>
     */
    public function options(string $dsn): array
    {
        return [
            'dsn'         => $dsn,
            'environment' => ENVIRONMENT,
            // 404·400 은 서버 오류가 아니라 정상 응답이다. 워드프레스·자격증명
            // 스캐너가 없는 경로를 하루 수백 번 두드리는데 그게 전부 이슈로
            // 올라오면 진짜 오류가 묻힌다(#174).
            'ignore_exceptions' => [
                PageNotFoundException::class,
                BadRequestException::class,
            ],
        ];
    }
}
