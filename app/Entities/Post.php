<?php

namespace App\Entities;

use CodeIgniter\Entity\Entity;
use League\CommonMark\CommonMarkConverter;

/**
 * 글 한 건을 나타내는 도메인 객체.
 *
 * Model 의 $returnType 으로 지정되어, 조회 결과가 이 엔티티로 감싸진다.
 * 화면 표시용 가공은 컨트롤러/뷰가 아니라 여기 접근자(getXxx)에 모아 둔다.
 */
class Post extends Entity
{
    /** 글 상태. DB 에는 이 문자열 그대로 저장된다. */
    public const STATUS_DRAFT     = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_PRIVATE   = 'private';

    /** 검증(in_list)과 컨트롤러 정규화가 함께 쓰는 허용 값 목록. */
    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_PUBLISHED, self::STATUS_PRIVATE];

    /** 배지에 노출할 한국어 라벨. */
    private const STATUS_LABELS = [
        self::STATUS_DRAFT     => '임시저장',
        self::STATUS_PUBLISHED => '발행됨',
        self::STATUS_PRIVATE   => '비공개',
    ];

    // created_at / updated_at 을 Time 객체로 다룬다.
    protected $dates = ['created_at', 'updated_at'];

    /**
     * 본문 마크다운 원문을 HTML 로 변환해 돌려준다. 뷰에서 $post->body_html.
     *
     * 저장은 원문(body), 표시만 변환한다. 본문은 사용자가 쓴 것이므로
     * 원시 HTML 은 이스케이프하고(html_input=escape) 위험한 링크(javascript: 등)는
     * 막아서(allow_unsafe_links=false) XSS 를 차단한다.
     */
    public function getBodyHtml(): string
    {
        $converter = new CommonMarkConverter([
            'html_input'         => 'escape',
            'allow_unsafe_links' => false,
        ]);

        return $converter->convert((string) ($this->attributes['body'] ?? ''))->getContent();
    }

    /**
     * 목록에서 보여 줄 짧은 미리보기.
     *
     * 원문을 그대로 자르면 #, **, [](…) 같은 마크다운 기호가 노출되므로,
     * 본문을 HTML 로 변환한 뒤 태그를 걷어내 순수 텍스트만 남긴다.
     * 그 뒤 줄바꿈을 공백으로 합치고 앞부분만 잘라 준다.
     * 뷰에서 $post->excerpt 로 접근한다.
     */
    public function getExcerpt(int $limit = 80): string
    {
        $text    = html_entity_decode(strip_tags($this->getBodyHtml()), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $excerpt = preg_replace('/\s+/u', ' ', trim($text));

        if (mb_strlen($excerpt) > $limit) {
            $excerpt = mb_substr($excerpt, 0, $limit) . '…';
        }

        return $excerpt;
    }

    /**
     * 본문 길이로 추정한 읽기 시간(분). 한국어 기준 분당 약 500자.
     * 뷰에서 $post->read_time 으로 접근한다(최소 1분).
     */
    public function getReadTime(): int
    {
        $chars = mb_strlen(preg_replace('/\s+/u', '', (string) ($this->attributes['body'] ?? '')));

        return max(1, (int) ceil($chars / 500));
    }

    /**
     * 대표 이미지가 없을 때 커버에 깔 그라데이션. 글 id 로 팔레트에서 고정 선택해
     * 같은 글은 항상 같은 색을 갖도록 한다(Nord Aurora/Frost 계열).
     * 뷰에서 background:<?= $post->cover_gradient ?> 로 쓴다.
     */
    public function getCoverGradient(): string
    {
        $palette = [
            'linear-gradient(135deg,#88C0D0 0%,#5E81AC 60%,#2E3440 100%)',
            'linear-gradient(135deg,#EBCB8B 0%,#D08770 100%)',
            'linear-gradient(135deg,#8FBCBB 0%,#5E81AC 100%)',
            'linear-gradient(135deg,#D8DEE9 0%,#2E3440 100%)',
            'linear-gradient(135deg,#D08770 0%,#BF616A 100%)',
            'linear-gradient(135deg,#A3BE8C 0%,#5E81AC 100%)',
            'linear-gradient(135deg,#B48EAD 0%,#5E81AC 100%)',
        ];

        return $palette[((int) ($this->attributes['id'] ?? 0)) % count($palette)];
    }

    /**
     * 커버에 얹을 글자(제목 첫 글자, 대문자). 뷰에서 $post->cover_initial.
     */
    public function getCoverInitial(): string
    {
        $title = trim((string) ($this->attributes['title'] ?? ''));

        return $title === '' ? '·' : mb_strtoupper(mb_substr($title, 0, 1));
    }

    /**
     * 이 글이 공개 화면에 노출되는 상태인지. 상세 가드·미리보기 배너가 쓴다.
     */
    public function isPublished(): bool
    {
        return ($this->attributes['status'] ?? null) === self::STATUS_PUBLISHED;
    }

    /**
     * 상태 배지에 찍을 한국어 라벨. 알 수 없는 값이면 그대로 노출하지 않고 뭉갠다.
     */
    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->attributes['status'] ?? self::STATUS_PUBLISHED] ?? '알 수 없음';
    }
}
