<?php

namespace App\Models;

use App\Entities\Post;
use CodeIgniter\Model;

class PostModel extends Model
{
    protected $table      = 'posts';
    protected $primaryKey = 'id';

    // 조회 결과를 배열이 아니라 Post 엔티티로 돌려받는다.
    protected $returnType = Post::class;

    // created_at / updated_at 을 모델이 자동으로 채운다.
    protected $useTimestamps = true;

    // 대량 할당을 허용할 필드. id 와 타임스탬프는 제외한다.
    protected $allowedFields = [
        'user_id',
        'title',
        'slug',
        'body',
    ];
}
