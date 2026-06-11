<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class PostSeeder extends Seeder
{
    public function run()
    {
        $posts = [
            [
                'title' => 'CodeIgniter 4로 블로그 만들기를 시작하며',
                'slug'  => 'codeigniter4-blog-start',
                'body'  => "이 블로그는 CodeIgniter 4를 한 회차씩 쌓아 올리며 만들어 갑니다.\n\n첫 글에서는 왜 프레임워크를 쓰는지, 그리고 앞으로 어떤 기능을 차례로 붙여 갈지 가볍게 정리해 둡니다. 라우팅과 뷰부터 시작해 모델·인증·댓글까지, 작은 단위로 커밋하며 나아갑니다.",
            ],
            [
                'title' => '라우팅과 컨트롤러, 요청은 어디로 흐르는가',
                'slug'  => 'routing-and-controllers',
                'body'  => "사용자의 요청은 Routes.php에서 컨트롤러로, 다시 뷰로 흘러갑니다.\n\n이 흐름을 명확히 이해하면 어디에 무엇을 둬야 할지 감이 잡힙니다. 라우트는 명시적으로 등록해 두는 편이 나중에 읽기 좋습니다.",
            ],
            [
                'title' => '공통 레이아웃으로 중복 걷어내기',
                'slug'  => 'common-layout',
                'body'  => "머리말과 꼬리말이 모든 페이지에 반복된다면 레이아웃을 분리할 때입니다.\n\nextend()와 section()으로 공통 골격을 한 곳에 모으면, 각 페이지는 본문만 책임지면 됩니다. 화면이 늘어날수록 이 구조의 가치가 커집니다.",
            ],
            [
                'title' => '첫 테스트가 주는 안전망',
                'slug'  => 'first-test-safety-net',
                'body'  => "테스트는 미래의 나를 위한 약속입니다.\n\n페이지가 200으로 응답하는지 확인하는 작은 테스트 하나가, 리팩터링할 때 큰 자신감을 줍니다. 빨강에서 초록으로 가는 리듬에 익숙해지는 것이 핵심입니다.",
            ],
            [
                'title' => '마이그레이션으로 스키마를 코드로 남기기',
                'slug'  => 'migrations-as-code',
                'body'  => "DB 스키마를 손으로 만들면 재현하기 어렵습니다.\n\n마이그레이션은 테이블 구조를 코드로 남겨 누구나 같은 스키마를 재현하게 해 줍니다. Forge로 컬럼을 정의하고, 잘못되면 롤백으로 되돌립니다.",
            ],
            [
                'title' => '시더로 현실적인 더미 데이터 채우기',
                'slug'  => 'seeding-dummy-data',
                'body'  => "화면을 만들려면 보여 줄 데이터가 필요합니다.\n\n시더는 개발 단계에서 그럴듯한 더미 글을 한 번에 채워 줍니다. 덕분에 목록과 상세 화면을 실제 데이터처럼 다듬어 볼 수 있습니다.",
            ],
        ];

        // 시더를 반복 실행해도 슬러그 유니크 제약에 걸리지 않도록,
        // 이번에 넣을 슬러그만 먼저 비운다(실제 글은 건드리지 않음).
        $slugs = array_column($posts, 'slug');
        $this->db->table('posts')->whereIn('slug', $slugs)->delete();

        foreach ($posts as $i => $post) {
            // 최신 글이 위로 오도록 작성 시각을 하루씩 벌려 둔다
            $createdAt = date('Y-m-d H:i:s', strtotime("-{$i} days"));

            $this->db->table('posts')->insert([
                'user_id'    => null,
                'title'      => $post['title'],
                'slug'       => $post['slug'],
                'body'       => $post['body'],
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }
    }
}
