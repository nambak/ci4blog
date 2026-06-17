<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddCategoryIdToPosts extends Migration
{
    public function up()
    {
        // 이미 만들어진 posts 테이블에 컬럼을 더하는 "테이블 변경" 마이그레이션.
        // category 가 지워져도 글은 남겨야 하므로 null 을 허용한다.
        // (DB 레벨 외래키는 SQLite·MySQL 간 addColumn 이식성을 위해 걸지 않고,
        //  관계는 모델·시더에서 관리한다. 'after' 는 MySQL 전용이라 SQLite 는 무시한다.)
        $this->forge->addColumn('posts', [
            'category_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'null'       => true,
                'after'      => 'user_id',
            ],
        ]);

        // 다음 회차의 카테고리 필터가 `WHERE category_id = ?` 로 자주 조회하므로
        // 풀스캔을 피하도록 인덱스를 함께 건다. (이미 있는 테이블에 인덱스 추가)
        $this->forge->addKey('category_id');
        $this->forge->processIndexes('posts');
    }

    public function down()
    {
        // 컬럼을 드롭하면 딸린 인덱스(category_id)도 함께 사라진다(MySQL·SQLite 공통).
        $this->forge->dropColumn('posts', 'category_id');
    }
}
