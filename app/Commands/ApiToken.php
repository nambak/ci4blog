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

        $revoke = $params['revoke'] ?? CLI::getOption('revoke');

        if ($revoke !== null && $revoke !== '' && $revoke !== true) {
            return $this->revoke($user, (int) $revoke);
        }

        $label = (string) ($params['name'] ?? CLI::getOption('name') ?: 'publish-cli');
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
                $user->revokeAccessToken($token->secret);
                CLI::write("토큰 {$id} ({$token->name}) 을 폐기했습니다.", 'green');

                return EXIT_SUCCESS;
            }
        }

        CLI::error("이 사용자의 토큰 중 id={$id} 인 것이 없습니다.");

        return EXIT_ERROR;
    }
}
