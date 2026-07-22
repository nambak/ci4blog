<?php

namespace App\Controllers;

use App\Entities\Comment;
use App\Entities\Post;
use App\Models\CommentLikeModel;
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
            if ($dbException !== null) {
                // 유니크 위반 = 이미 신고돼 있다는 뜻(레이스 포함). 원인은 예외에서 읽는다 —
                // 연결의 error() 는 이 시점에 이미 비어 있다(#107 조사).
                if (is_duplicate_key_error($dbException->getCode(), $dbException->getMessage())) {
                    return redirect()->back()->with('message', '이미 신고한 댓글입니다.');
                }

                // 중복이 아닌 DB 오류는 숨기지 않고 그대로 전파한다.
                throw $dbException;
            }

            // DBDebug=false 면 예외가 없어 원인을 알 수 없다. 이때만 예전처럼 되묻는다.
            if ($reports->hasReported($commentId, $reporterId)) {
                return redirect()->back()->with('message', '이미 신고한 댓글입니다.');
            }

            return redirect()->back()->withInput()->with('errors', $reports->errors());
        }

        return redirect()->back()->with('message', '신고가 접수되었습니다.');
    }

    /**
     * 댓글 좋아요를 토글한다. (세션 필터로 로그인 사용자만 접근)
     *
     * 답글도 대상이다 — 답글은 관리자만 달 수 있으므로(Admin\Comments::reply) 관리자
     * 응답이 도움이 됐다는 신호를 받는 자리가 된다. 신고가 답글을 제외한 것은 관리
     * 신고 탭이 최상위만 보여주기 때문이라 여기엔 해당하지 않는다.
     */
    public function like(int $commentId): RedirectResponse
    {
        $comment = model(CommentModel::class)->find($commentId);

        if ($comment === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        $post = model(PostModel::class)->find((int) $comment->post_id);

        // 글 상세와 같은 가드. 상세가 404 인 글의 댓글에 좋아요만 열려 있으면
        // 응답 차이로 글의 존재가 샌다.
        if ($post === null || ! post_viewable($post)) {
            throw PageNotFoundException::forPageNotFound();
        }

        // 이미 안 보이는 댓글에 좋아요를 남길 의미가 없다(신고와 같은 판단).
        if ($comment->isHidden()) {
            throw PageNotFoundException::forPageNotFound();
        }

        // 답글은 자신이 visible 이어도 부모가 숨겨지면 목록에서 함께 빠진다
        // (CommentModel::visibleForPost 의 orWhere('parent.status', VISIBLE)).
        // 답글 자신의 상태만 보면 화면에 없는 답글을 id 로 직접 누를 수 있다.
        if ($comment->isReply()) {
            $parent = model(CommentModel::class)->find((int) $comment->parent_id);

            if ($parent === null || $parent->isHidden()) {
                throw PageNotFoundException::forPageNotFound();
            }
        }

        $likes  = model(CommentLikeModel::class);
        $userId = (int) auth()->id();

        // 검사-후-삽입은 동시 요청 두 개가 모두 통과하는 레이스가 있어 쓰지 않는다(#88).
        // 먼저 넣어 보고 유니크 키(comment_id, user_id) 위반으로 실패하면 그게 곧
        // "이미 눌렀다"는 뜻이라 취소로 간다. 위반은 DBDebug=true 면 예외로, false 면
        // insert=false 로 온다.
        $dbException = null;

        try {
            $inserted = $likes->insert(['comment_id' => $commentId, 'user_id' => $userId]);
        } catch (DatabaseException $e) {
            $inserted    = false;
            $dbException = $e;
        }

        if (! $inserted) {
            // 실패 원인은 예외에서 읽는다. 연결의 error() 는 이 시점에 이미 비어 있다
            // ({code:0, message:"not an error"}) — #107 조사에서 확인했다.
            $isDuplicate = $dbException !== null
                && is_duplicate_key_error($dbException->getCode(), $dbException->getMessage());

            if ($isDuplicate) {
                // 유니크 위반 = 이미 눌러 뒀다는 뜻이므로 취소로 간다(토글).
                $likes->where('comment_id', $commentId)->where('user_id', $userId)->delete();
            } elseif ($dbException !== null) {
                // 중복이 아닌 DB 오류다. 기존 행은 건드리지 않는다 — 원인을 구분하지 않고
                // "행이 있으면 취소" 하면, 삽입이 커밋된 뒤 타임아웃 같은 실패에서 방금
                // 만든 행을 지워 사용자가 누른 좋아요가 사라진다(#107).
                // 재던지지도 않는다. 동시 취소 레이스 같은 정상 상황에도 500 이 나가므로
                // (76ea329) 로그만 남기고 화면은 정상 응답한다 — 진짜 오류라면 카운트가
                // 그대로여서 사용자에게도 "안 눌렸다"가 보인다.
                log_message('error', '댓글 좋아요 삽입 실패 (comment {comment}, user {user}): {message}', [
                    'comment' => $commentId,
                    'user'    => $userId,
                    'message' => $dbException->getMessage(),
                ]);
            } elseif ($likes->errors() !== []) {
                return redirect()->back()->with('errors', $likes->errors());
            } elseif ($likes->hasLiked($commentId, $userId)) {
                // DBDebug=false 면 예외가 없어 원인을 알 수 없다. 이때만 예전처럼 되묻는다.
                $likes->where('comment_id', $commentId)->where('user_id', $userId)->delete();
            }
        }

        return redirect()->to('posts/' . $post->slug . '#comment-' . $commentId);
    }
}
