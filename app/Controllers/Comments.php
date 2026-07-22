<?php

namespace App\Controllers;

use App\Entities\Comment;
use App\Entities\Post;
use App\Models\CommentModel;
use App\Models\CommentReportModel;
use App\Models\PostModel;
use CodeIgniter\Database\Exceptions\DatabaseException;
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

        // 상세와 같은 규칙으로 막는다(비발행 + 숨김 카테고리). 이 가드가 없으면
        // 숫자 id 만 아는 사용자가 못 보는 글에 댓글을 달 수 있고, 성공 리다이렉트의
        // Location 헤더로 그 글의 슬러그가 새어 나간다.
        if (! post_viewable($post)) {
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
        // 댓글 작성자 본인, 또는 글 작성자(자기 글의 댓글 정리), 또는 관리자.
        // "본인 또는 관리자" 판정은 acl 헬퍼로 모았다(관리자는 어느 쪽이든 true).
        return is_owner_or_admin($comment->user_id)
            || ($post !== null && is_owner_or_admin($post->user_id));
    }

    /**
     * 댓글을 신고한다. (세션 필터로 로그인 사용자만 접근)
     *
     * 최상위 visible 댓글만 신고할 수 있다. 답글(관리자 작성)·숨김 댓글·자기 댓글은 막는다.
     * reporter_user_id 는 auth()->id() 에서 얻는다(요청에서 받으면 위조 가능).
     */
    public function report(int $commentId): RedirectResponse
    {
        $comment = model(CommentModel::class)->find($commentId);

        if ($comment === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        $post = model(PostModel::class)->find((int) $comment->post_id);

        // 글이 삭제되었거나 못 보는 글이면 store() 와 같은 규칙으로 막는다. 이 가드가
        // 없으면 댓글 자체는 visible 이어도 댓글 id 를 직접 요청해 비공개 글의 댓글을
        // 신고할 수 있다.
        if ($post === null || ! post_viewable($post)) {
            throw PageNotFoundException::forPageNotFound();
        }

        // 답글은 관리자가 단 것이고 관리 신고 탭이 최상위만 보여주므로 신고 대상에서 제외한다.
        if ($comment->isReply()) {
            throw PageNotFoundException::forPageNotFound();
        }

        // 이미 안 보이는 댓글은 신고할 의미가 없다.
        if ($comment->isHidden()) {
            throw PageNotFoundException::forPageNotFound();
        }

        // 자기 댓글은 신고할 수 없다.
        if ((int) $comment->user_id === (int) auth()->id()) {
            return redirect()->back()->with('errors', ['자기 댓글은 신고할 수 없습니다.']);
        }

        $reports    = model(CommentReportModel::class);
        $reporterId = (int) auth()->id();

        // 검사-후-삽입은 동시 요청 두 개가 모두 통과하는 레이스가 있다(TOCTOU).
        // 사전 검사 없이 바로 삽입하고, 유니크 키(comment_id, reporter_user_id) 위반을
        // 여기서 "이미 신고" 로 바꾼다. 위반은 DBDebug=true 면 예외로, false 면 insert=false 로 온다.
        $dbException = null;

        try {
            $inserted = $reports->insert([
                'comment_id'       => $commentId,
                'reporter_user_id' => $reporterId,
                'reason'           => $this->request->getPost('reason'),
                'status'           => CommentReportModel::STATUS_PENDING,
            ]);
        } catch (DatabaseException $e) {
            $inserted    = false;
            $dbException = $e;
        }

        if (! $inserted) {
            // 이미 신고돼 있으면(레이스 포함) 친화적으로 처리한다.
            if ($reports->hasReported($commentId, $reporterId)) {
                return redirect()->back()->with('message', '이미 신고한 댓글입니다.');
            }

            // 중복이 아닌 DB 오류는 숨기지 않고 그대로 전파한다.
            if ($dbException !== null) {
                throw $dbException;
            }

            return redirect()->back()->withInput()->with('errors', $reports->errors());
        }

        return redirect()->back()->with('message', '신고가 접수되었습니다.');
    }
}
