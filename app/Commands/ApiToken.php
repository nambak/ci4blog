<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Shield\Models\UserModel;

/**
 * 발행 API 용 액세스 토큰을 만들고 관리한다.
 *
 * Shield 는 토큰 발급 CLI 를 주지 않는다. 그렇다고 토큰 하나 만들자고 임시
 * 라우트를 파거나 tinker 로 코드를 치는 것은 나중에 다시 하려면 매번 기억을
 * 더듬어야 한다 — 그래서 커맨드로 남긴다.
 *
 * 사용:
 *   php spark api:token nambak80                 발급
 *   php spark api:token nambak80 --name deploy   이름 지정
 *   php spark api:token nambak80 --list          목록
 *   php spark api:token nambak80 --revoke 3      폐기
 */
class ApiToken extends BaseCommand
{
    protected $group       = 'Blog';
    protected $name        = 'api:token';
    protected $description = '발행 API 용 액세스 토큰을 발급·조회·폐기한다.';
    protected $usage       = 'api:token <username> [--name 라벨] [--list] [--revoke ID]';
    protected $arguments   = [
        'username' => '토큰을 가질 사용자의 username.',
    ];
    protected $options = [
        '--name'   => '토큰 라벨 (기본: publish-cli).',
        '--list'   => '이 사용자의 토큰 목록을 보여 준다.',
        '--revoke' => '해당 id 의 토큰을 폐기한다.',
    ];

    public function run(array $params)
    {
        $username = $params[0] ?? CLI::prompt('username');

        $users = new UserModel();
        $user  = $users->where('username', $username)->first()
            ?? $users->findByCredentials(['email' => $username]);

        if ($user === null) {
            CLI::error("사용자를 찾을 수 없습니다: {$username}");

            return EXIT_ERROR;
        }

        if (array_key_exists('list', $params) || CLI::getOption('list')) {
            return $this->listTokens($user);
        }

        // --revoke 를 값 없이 주면 CLI::getOption() 은 true 를, command() 헬퍼는
        // null 을 넘긴다. 값이 없다고 그냥 흘려보내면 폐기 분기를 건너뛰고 발급
        // 경로로 내려가, 지우려고 친 명령이 토큰을 하나 더 만들어 놓는다.
        // 플래그가 있었는지와 값이 쓸 만한지를 나눠서 본다.
        if (array_key_exists('revoke', $params) || CLI::getOption('revoke') !== null) {
            $revoke = $params['revoke'] ?? CLI::getOption('revoke');
            $id     = is_string($revoke) || is_int($revoke) ? (string) $revoke : '';

            if (! ctype_digit($id)) {
                CLI::error('--revoke 에는 폐기할 토큰 id 를 함께 주세요. 예: --revoke 3');

                return EXIT_ERROR;
            }

            return $this->revoke($user, (int) $id);
        }

        // --revoke 와 같은 이유로 문자열인지 먼저 본다. 값 없는 --name 은 true 가
        // 되는데 (string) true 는 '1' 이라, 라벨이 '1' 인 토큰이 목록에 남는다.
        $rawLabel = $params['name'] ?? CLI::getOption('name');
        $label    = is_string($rawLabel) && trim($rawLabel) !== '' ? trim($rawLabel) : 'publish-cli';

        $token = $user->generateAccessToken($label);

        CLI::write('토큰을 발급했습니다.', 'green');
        CLI::newLine();
        CLI::write('  라벨 : ' . $label);
        CLI::write('  토큰 : ' . CLI::color($token->raw_token, 'yellow'));
        CLI::newLine();

        // 원문은 여기서만 볼 수 있다. DB 에는 해시만 남는다.
        CLI::write('이 화면을 벗어나면 다시 볼 수 없습니다. 지금 안전한 곳에 옮겨 두세요.', 'red');
        CLI::write('보관 위치 예: 발행 스크립트를 돌릴 컴퓨터의 환경변수 BLOG_API_TOKEN');

        return EXIT_SUCCESS;
    }

    private function listTokens($user): int
    {
        $tokens = $user->accessTokens();

        if ($tokens === []) {
            CLI::write('발급된 토큰이 없습니다.', 'yellow');

            return EXIT_SUCCESS;
        }

        $rows = array_map(static fn ($t) => [
            $t->id,
            $t->name,
            $t->last_used_at === null ? '사용 안 함' : (string) $t->last_used_at,
            (string) $t->created_at,
        ], $tokens);

        CLI::table($rows, ['id', '라벨', '마지막 사용', '발급일']);

        return EXIT_SUCCESS;
    }

    private function revoke($user, int $id): int
    {
        foreach ($user->accessTokens() as $token) {
            if ((int) $token->id === $id) {
                // BySecret 이어야 한다. revokeAccessToken() 은 받은 값을 sha256 으로
                // 해시해서 찾는데 secret 은 이미 해시라, 이중 해시가 되어 아무것도
                // 지우지 못한다. 삭제 0건도 쿼리는 성공이라 조용히 실패한다.
                $user->revokeAccessTokenBySecret($token->secret);
                CLI::write("토큰 {$id} ({$token->name}) 을 폐기했습니다.", 'green');

                return EXIT_SUCCESS;
            }
        }

        CLI::error("이 사용자의 토큰 중 id={$id} 인 것이 없습니다.");

        return EXIT_ERROR;
    }
}
