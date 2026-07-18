<?php

/**
 * 관리 통계 헬퍼.
 *
 * 통계 카드의 "지난주 대비 증감"을 계산한다. 스냅샷 테이블 없이 created_at 만으로,
 * 최근 7일에 새로 생긴 수에서 그 이전 7일에 생긴 수를 뺀다(증가면 양수, 감소면 음수).
 */

use CodeIgniter\Model;

if (! function_exists('weekly_delta')) {
    /**
     * 모델의 최근 7일 신규 건수 - 그 이전 7일 신규 건수.
     *
     * 경계: 이번 주는 created_at >= (지금-7일), 지난 주는 (지금-14일) <= created_at < (지금-7일).
     * created_at 이 있는 테이블(글·댓글 등 append-only 카운트)에만 의미가 있다.
     * 호출 시점에 모델 빌더에 걸린 조건이 없어야 한다(cards()·countAllResults 관례와 동일하게,
     * 두 번의 countAllResults 가 서로를 리셋한다).
     */
    function weekly_delta(Model $model): int
    {
        $sevenDaysAgo    = date('Y-m-d H:i:s', strtotime('-7 days'));
        $fourteenDaysAgo = date('Y-m-d H:i:s', strtotime('-14 days'));

        $thisWeek = $model->where('created_at >=', $sevenDaysAgo)->countAllResults();
        $prevWeek = $model
            ->where('created_at >=', $fourteenDaysAgo)
            ->where('created_at <', $sevenDaysAgo)
            ->countAllResults();

        return $thisWeek - $prevWeek;
    }
}
