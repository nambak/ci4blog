<?php

namespace App\Controllers;

use App\Models\CommentModel;
use App\Models\PostModel;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;

class Comments extends BaseController
{
    /**
     * 글 한 건에 댓글을 저장한다. (세션 필터로 로그인 사용자만 접근)
     */
    public function store(int $postId): RedirectResponse
    {
        $post = model(PostModel::class)->find($postId);

        // 없는 글에는 댓글을 달 수 없다.
        if ($post === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        $model = model(CommentModel::class);

        $data = [
            'post_id' => $postId,
            'user_id' => auth()->id(),
            'body'    => $this->request->getPost('body'),
        ];

        // 검증 실패 시: 입력값을 들고 글 상세로 되돌아간다.
        if (! $model->insert($data)) {
            return redirect()->back()
                ->withInput()
                ->with('errors', $model->errors());
        }

        return redirect()->to('posts/' . $post->slug)
            ->with('message', '댓글이 등록되었습니다.');
    }
}
