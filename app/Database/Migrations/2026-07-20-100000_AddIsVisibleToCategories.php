<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddIsVisibleToCategories extends Migration
{
    public function up()
    {
        // 카테고리 단위 공개/숨김(#67). 숨기면 그 카테고리의 글도 공개 화면에서 빠진다.
        //
        // 기본값 1(공개)이라 기존 행은 전부 공개로 남는다 — 배포 시점에 동작이 바뀌지 않는다.
        // TINYINT(1) 은 MySQL 의 bool 관례이고 SQLite 에서도 정수로 잘 동작한다.
        // ('after' 는 MySQL 전용이라 SQLite 는 무시한다.)
        $this->forge->addColumn('categories', [
            'is_visible' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'null'       => false,
                'default'    => 1,
                'after'      => 'slug',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('categories', 'is_visible');
    }
}
