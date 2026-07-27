<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Content-Security-Policy 설정(#111).
 *
 * 정책을 여기 한곳에 모은다. CI4 내장 CSP($CSPEnabled)는 켜지 않고,
 * App\Filters\SecurityHeaders 가 이 config 를 읽어 헤더를 부착한다.
 */
class Csp extends BaseConfig
{
    /**
     * 전역 스위치.
     *
     * 운영에서 CSP 가 문제를 일으키면 .env 의 `csp.enabled = false` 로
     * 재배포 없이 끌 수 있다.
     */
    public bool $enabled = true;

    /**
     * true 면 Content-Security-Policy-Report-Only(차단 없이 위반만 보고),
     * false 면 Content-Security-Policy(실제 차단).
     *
     * 처음에는 Report-Only 로 관찰하고, 콘솔에 뜨는 위반을 정리한 뒤
     * 이 값을 false 로 바꿔 enforce 로 승격한다.
     */
    public bool $reportOnly = true;

    /**
     * 지시어별 소스 목록. 정책 수치의 유일한 출처.
     *
     * 'unsafe-inline'(script/style)은 동적 인라인 style= 속성과
     * onsubmit="return confirm(...)" 때문에 불가피하다(nonce 로 못 살린다).
     * AdSense 도메인은 넓게 잡되, Report-Only 콘솔 로그로 실제 필요한
     * 범위를 확인한 뒤 enforce 전에 좁힌다.
     *
     * @var array<string, list<string>>
     */
    public array $directives = [
        'default-src' => ["'self'"],
        'script-src'  => [
            "'self'",
            "'unsafe-inline'",
            'https://pagead2.googlesyndication.com',
            'https://*.googlesyndication.com',
            'https://*.googleadservices.com',
            'https://*.google.com',
            'https://*.doubleclick.net',
        ],
        'style-src'  => ["'self'", "'unsafe-inline'", 'https://cdn.jsdelivr.net', 'https://fonts.googleapis.com'],
        'font-src'   => ["'self'", 'https://fonts.gstatic.com', 'https://cdn.jsdelivr.net'],
        'img-src'    => ["'self'", 'data:', 'https:'],
        'frame-src'  => ['https://*.doubleclick.net', 'https://*.google.com', 'https://*.googlesyndication.com'],
        'connect-src' => ["'self'", 'https://*.google.com', 'https://*.doubleclick.net', 'https://*.googlesyndication.com'],
        'object-src'  => ["'none'"],
        'base-uri'    => ["'self'"],
        'form-action' => ["'self'"],
        'frame-ancestors' => ["'none'"],
    ];

    public function headerName(): string
    {
        return $this->reportOnly
            ? 'Content-Security-Policy-Report-Only'
            : 'Content-Security-Policy';
    }

    /**
     * 지시어 배열을 "key src1 src2; key2 src...;" 형태의 정책 문자열로 만든다.
     * 소스가 빈 지시어(예: upgrade-insecure-requests)는 키만 출력한다.
     */
    public function compile(): string
    {
        $parts = [];

        foreach ($this->directives as $name => $sources) {
            $parts[] = $sources === [] ? $name : $name . ' ' . implode(' ', $sources);
        }

        return implode('; ', $parts);
    }
}
