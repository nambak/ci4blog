<?php

namespace App\Controllers;

/**
 * 관리자 대시보드.
 *
 * 라우트 그룹에 걸린 Shield `group:admin,superadmin` 필터가 접근을 막으므로
 * 이 컨트롤러는 이미 admin/superadmin임이 보장된 요청만 처리한다.
 */
class Admin extends BaseController
{
    public function index(): string
    {
        $posts      = model(\App\Models\PostModel::class);
        $comments   = model(\App\Models\CommentModel::class);
        $categories = model(\App\Models\CategoryModel::class);

        $stats = [
            'posts'          => $posts->countAllResults(false),
            'comments'       => $comments->countAllResults(),
            'categories'     => $categories->countAllResults(),
            'postsThisMonth' => $posts->where('created_at >=', date('Y-m-01 00:00:00'))->countAllResults(),
        ];

        return view('admin/dashboard', [
            'stats' => $stats,
        ]);
    }
}
