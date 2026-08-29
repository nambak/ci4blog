<?php

namespace App\Libraries;

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

        \Sentry\init([
            'dsn'         => $dsn,
            'environment' => ENVIRONMENT,
        ]);
    }
}
