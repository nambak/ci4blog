<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddStatusToPosts extends Migration
{
    public function up()
    {
        // ENUM 이 아니라 VARCHAR 인 이유: 개발/운영(MySQL)과 테스트(SQLite)를 같은
        // 마이그레이션으로 덮기 위해서다. 값 제약은 PostModel 의 in_list 검증이 맡는다.
        // ('after' 는 MySQL 전용이라 SQLite 는 무시한다 — AddCategoryIdToPosts 와 동일.)
        //
        // 기존 글은 DEFAULT 'published' 로 자동 백필된다. 별도 UPDATE 가 필요 없고,
        // 이는 의도한 동작이다 — 지금 존재하는 글은 전부 이미 공개된 글이다.
        $this->forge->addColumn('posts', [
            'status' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'null'       => false,
                'default'    => 'published',
                'after'      => 'category_id',
            ],
        ]);

        // 공개 목록(WHERE status='published')과 관리 목록의 탭 필터가
        // 모두 이 컬럼으로 거르므로 풀스캔을 피하도록 인덱스를 건다.
        $this->forge->addKey('status');
        $this->forge->processIndexes('posts');
    }

    public function down()
    {
        // 컬럼을 드롭하면 딸린 인덱스도 함께 사라진다(MySQL·SQLite 공통).
        $this->forge->dropColumn('posts', 'status');
    }
}
