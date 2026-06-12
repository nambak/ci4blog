<?php

namespace App\Controllers;

use App\Models\PostModel;

class Posts extends BaseController
{
    // 한 페이지에 보여 줄 글 수
    private const PER_PAGE = 5;

    public function index(): string
    {
        $model = model(PostModel::class);

        $posts = $model
            ->orderBy('created_at', 'DESC')
            ->paginate(self::PER_PAGE);

        return view('posts/index', [
            'posts' => $posts,
            'pager' => $model->pager,
        ]);
    }
}
