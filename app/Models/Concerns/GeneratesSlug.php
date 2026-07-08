<?php

namespace App\Models\Concerns;

/**
 * name/title 같은 텍스트에서 URL 안전한 slug 를 만드는 공용 로직.
 * PostModel·CategoryModel 이 함께 쓴다. slug 컬럼이 있는 Model 에서 use 한다.
 */
trait GeneratesSlug
{
    /**
     * 텍스트를 URL 안전한 slug 로 바꾼다.
     * 한국어는 url_title()이 빈 문자열이 되므로 글자/숫자(한글 포함)는 살리고
     * 공백은 하이픈으로 바꾼다. 결과가 비면 $fallback 으로 대체한다.
     */
    protected function slugify(string $text, string $fallback = 'post'): string
    {
        $slug = mb_strtolower(trim($text));
        $slug = preg_replace('/\s+/u', '-', $slug);             // 공백 → 하이픈
        $slug = preg_replace('/[^a-z0-9가-힣\-]+/u', '', $slug); // 허용 문자만
        $slug = preg_replace('/-+/', '-', $slug);               // 연속 하이픈 축약
        $slug = trim($slug, '-');

        return $slug !== '' ? $slug : $fallback;
    }

    /**
     * slug 가 이미 있으면 -2, -3 … 을 붙여 유일하게 만든다.
     */
    protected function uniqueSlug(string $base, ?int $excludeId = null): string
    {
        $slug   = $base;
        $suffix = 2;

        while (true) {
            $builder = $this->where('slug', $slug);
            if ($excludeId !== null) {
                $builder->where('id !=', $excludeId);
            }

            // countAllResults() 는 기본적으로 쿼리 빌더를 초기화하므로
            // 다음 루프의 조건이 누적되지 않는다.
            if ($builder->countAllResults() === 0) {
                return $slug;
            }

            $slug = $base . '-' . $suffix++;
        }
    }
}
