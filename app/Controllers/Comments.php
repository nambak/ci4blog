<?php

namespace App\Controllers;

use App\Entities\Comment;
use App\Entities\Post;
use App\Models\CommentModel;
use App\Models\PostModel;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

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

    /**
     * 댓글을 삭제한다. 댓글 작성자 본인·글 작성자·관리자만 가능하다.
     */
    public function delete(int $commentId): ResponseInterface|RedirectResponse
    {
        $model   = model(CommentModel::class);
        $comment = $model->find($commentId);

        if ($comment === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        $post = model(PostModel::class)->find((int) $comment->post_id);

        // 컨트롤러 가드: 권한이 없으면 403 으로 막는다.
        if (! $this->canDelete($comment, $post)) {
            return $this->response->setStatusCode(403, '삭제 권한이 없습니다.');
        }

        $model->delete($commentId);

        $to = $post !== null ? 'posts/' . $post->slug : 'posts';

        return redirect()->to($to)->with('message', '댓글이 삭제되었습니다.');
    }

    /**
     * 댓글 작성자 본인이거나, 글 작성자이거나, 관리자면 삭제할 수 있다.
     */
    private function canDelete(Comment $comment, ?Post $post): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        // 댓글 작성자 본인
        if ((int) $comment->user_id === (int) $user->id) {
            return true;
        }

        // 글 작성자(자기 글의 댓글을 정리할 수 있다)
        if ($post !== null && (int) $post->user_id === (int) $user->id) {
            return true;
        }

        return $user->inGroup('admin');
    }
}
