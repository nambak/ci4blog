<?php

namespace Tests\Feature;

use App\Commands\ApiToken;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Services;

/**
 * php spark api:token — 발행 API 토큰 발급·조회·폐기.
 *
 * 이 커맨드는 커버리지 0% 였고, 그 상태에서 리뷰가 결함 두 개를 찾아냈다(PR #146).
 * 하나는 폐기가 조용히 실패하는 것이었다 — 화면에는 "폐기했습니다" 가 찍히는데
 * 토큰은 살아 있었다. 토큰이 유출돼 급히 지우는 자리에서 가장 나쁜 실패다.
 *
 * 커맨드는 command() 가 아니라 run($params) 로 직접 호출한다. CLI::write() 는
 * STDOUT 에 직접 쓰기 때문에 command() 로는 출력을 잡지 못한다 —
 * PostsImportCommandTest·DbPruneCommandTest 와 같은 방식이다.
 *
 * 다만 "값 없는 --revoke" 처럼 CLI 인자 파싱 자체가 대상인 경우에는 $_SERVER['argv']
 * 를 세우고 CLI::init() 으로 진짜 파싱을 돌린다. CLI::getOption() 이 값 없는
 * 플래그에 true 를 돌려주는 동작은 $params 로는 재현되지 않기 때문이다.
 *
 * @internal
 */
final class ApiTokenCommandTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use StreamFilterTrait;

    protected $namespace = null;
    protected $refresh   = true;

    protected function setUp(): void
    {
        parent::setUp();

        $_SESSION = [];
        Services::resetSingle('session');
        Services::resetSingle('auth');
    }

    // ---------------------------------------------------------------- 도우미

    private function makeUser(string $username = 'admin'): User
    {
        $users = auth()->getProvider();
        $users->save(new User([
            'username' => $username,
            'email'    => $username . '@example.com',
            'password' => 'secret-password-123',
        ]));

        return $users->findById($users->getInsertID());
    }

    private function command(): ApiToken
    {
        return new ApiToken(Services::logger(), Services::commands());
    }

    /** DB 에 실제로 남아 있는 액세스 토큰 수. 엔티티 캐시를 거치지 않는다. */
    private function tokenCount(): int
    {
        return db_connect()->table('auth_identities')
            ->where('type', 'access_token')
            ->countAllResults();
    }

    // ---------------------------------------------------------------- 폐기

    /**
     * --revoke <id> 는 토큰을 실제로 지운다.
     *
     * 예전에는 revokeAccessToken($token->secret) 을 불렀다. 그 메서드는 받은 값을
     * sha256 으로 해시해서 찾는데 secret 은 이미 해시라, 이중 해시가 되어 아무것도
     * 지우지 못했다. 삭제 0건도 쿼리는 성공이라 커맨드는 초록 글씨로 성공을 알렸다.
     */
    public function testRevokeActuallyDeletesTheToken(): void
    {
        $user = $this->makeUser();
        $user->generateAccessToken('폐기 대상');
        $id = (int) $user->accessTokens()[0]->id;

        $this->assertSame(1, $this->tokenCount(), '사전 조건: 토큰이 하나 있어야 한다.');

        $result = $this->command()->run(['admin', 'revoke' => (string) $id]);

        $this->assertSame(EXIT_SUCCESS, $result);
        $this->assertSame(0, $this->tokenCount(), '폐기했다고 했는데 토큰이 남아 있다.');
    }

    /**
     * 지목한 토큰만 지운다.
     *
     * 위 테스트만 있으면 revoke() 가 id 를 무시하고 전부 지워도 통과한다.
     * 폐기는 되돌릴 수 없으니 "다른 것은 건드리지 않는다" 를 함께 못 박는다.
     *
     * 지목 대상은 반드시 **두 번째** 토큰이어야 한다. 첫 번째를 고르면 id 비교를
     * `true` 로 바꿔도 순회 첫 항목이 지워져 결과가 같아, 뮤테이션이 죽지 않는다.
     */
    public function testRevokeDeletesOnlyTheNamedToken(): void
    {
        $user = $this->makeUser();
        $user->generateAccessToken('첫째');
        $user->generateAccessToken('둘째');

        $tokens = $user->accessTokens();
        $this->assertCount(2, $tokens, '사전 조건: 토큰이 둘이어야 한다.');
        $this->assertSame('둘째', $tokens[1]->name, '사전 조건: 두 번째가 순회에서도 두 번째여야 한다.');

        $this->command()->run(['admin', 'revoke' => (string) $tokens[1]->id]);

        $this->assertSame(1, $this->tokenCount());
        $this->assertSame('첫째', $this->firstTokenName(), '지목하지 않은 토큰이 지워졌다.');
    }

    /** 없는 id 는 오류로 끝나고 아무것도 지우지 않는다. */
    public function testRevokeWithUnknownIdDeletesNothing(): void
    {
        $user = $this->makeUser();
        $user->generateAccessToken('살아남을 토큰');

        $result = $this->command()->run(['admin', 'revoke' => '9999']);

        $this->assertSame(EXIT_ERROR, $result);
        $this->assertSame(1, $this->tokenCount(), '없는 id 를 줬는데 토큰이 지워졌다.');
    }

    /**
     * 값 없는 --revoke 는 오류로 끝난다. 토큰을 발급하지 않는다.
     *
     * 폐기하려고 친 명령이 토큰을 하나 더 만들어 놓는 것이 원래 동작이었다.
     * 여기서는 command() 헬퍼가 만드는 형태($params 에 키가 있고 값이 null)를 준다.
     */
    public function testValuelessRevokeOptionDoesNotIssueToken(): void
    {
        $this->makeUser();

        $result = $this->command()->run(['admin', 'revoke' => null]);

        $this->assertSame(EXIT_ERROR, $result);
        $this->assertSame(0, $this->tokenCount(), '폐기하려 했는데 토큰이 발급됐다.');
    }

    /**
     * 같은 상황을 진짜 CLI 파싱으로 확인한다.
     *
     * CLI::getOption() 은 값 없는 플래그에 true 를 돌려주는데($options 에 null 로
     * 담긴 뒤 ?? true 를 통과한다), 이 동작은 $params 를 손으로 넘기는 방식으로는
     * 재현되지 않는다. 두 경로가 같은 가드에 걸려야 한다.
     */
    public function testValuelessRevokeFlagFromCliDoesNotIssueToken(): void
    {
        $this->makeUser();

        $result = $this->withArgv(['spark', 'api:token', 'admin', '--revoke'], fn () => $this->command()->run(['admin']));

        $this->assertSame(EXIT_ERROR, $result);
        $this->assertSame(0, $this->tokenCount(), '폐기하려 했는데 토큰이 발급됐다.');
    }

    // ---------------------------------------------------------------- 발급

    /**
     * 값 없는 --name 은 기본 라벨로 떨어진다.
     *
     * --revoke 와 같은 뿌리다. CLI::getOption('name') 이 true 를 돌려주는데
     * (string) true 는 '1' 이라, 목록에 라벨이 '1' 인 토큰이 남는다. 라벨은
     * 나중에 어느 토큰을 폐기할지 고르는 유일한 단서라 이게 뭉개지면 곤란하다.
     */
    public function testValuelessNameFlagFallsBackToDefaultLabel(): void
    {
        $this->makeUser();

        $this->withArgv(
            ['spark', 'api:token', 'admin', '--name'],
            fn () => $this->command()->run(['admin'])
        );

        $this->assertSame('publish-cli', $this->firstTokenName());
    }

    /** 라벨을 주면 그대로 쓴다. 위 테스트가 기본값만 보고 통과하지 않게 하는 짝이다. */
    public function testNameOptionIsUsedAsLabel(): void
    {
        $this->makeUser();

        $this->command()->run(['admin', 'name' => 'deploy']);

        $this->assertSame('deploy', $this->firstTokenName());
    }

    /**
     * 발급하면 토큰 원문을 화면에 보여 준다.
     *
     * DB 에는 해시만 남으므로 이 화면이 원문을 볼 수 있는 유일한 자리다.
     * 출력에서 빠지면 발급은 됐는데 쓸 수 없는 토큰이 된다.
     */
    public function testIssuedTokenIsPrintedOnce(): void
    {
        $this->makeUser();

        $result = $this->command()->run(['admin']);
        $output = $this->getStreamFilterBuffer();

        $this->assertSame(EXIT_SUCCESS, $result);
        $this->assertSame(1, $this->tokenCount());

        // 원문은 DB 에 없다. 화면에 찍힌 값이 저장된 해시와 맞아야 진짜 그 토큰이다.
        $row = db_connect()->table('auth_identities')->where('type', 'access_token')->get()->getRowArray();
        $this->assertMatchesRegularExpression('/[0-9a-f]{40,}/', $output, '토큰 원문이 화면에 없다.');

        preg_match('/[0-9a-f]{40,}/', $output, $matches);
        $this->assertSame($row['secret'], hash('sha256', $matches[0]), '화면에 찍힌 값이 저장된 토큰이 아니다.');
    }

    // ---------------------------------------------------------------- 목록

    /** --list 는 토큰을 보여 주기만 한다. 목록을 보려다 토큰이 생기면 안 된다. */
    public function testListShowsTokensWithoutIssuingOne(): void
    {
        $user = $this->makeUser();
        $user->generateAccessToken('배포용');

        $result = $this->command()->run(['admin', 'list' => null]);

        $this->assertSame(EXIT_SUCCESS, $result);
        $this->assertSame(1, $this->tokenCount(), '목록을 보려는데 토큰이 발급됐다.');
        $this->assertStringContainsString('배포용', $this->getStreamFilterBuffer());
    }

    // ---------------------------------------------------------------- 사용자

    /** 없는 사용자면 아무것도 하지 않고 오류로 끝난다. */
    public function testUnknownUserIsRejected(): void
    {
        $this->makeUser();

        $result = $this->command()->run(['없는사람']);

        $this->assertSame(EXIT_ERROR, $result);
        $this->assertSame(0, $this->tokenCount());
    }

    /** 발급된 첫 토큰의 라벨. */
    private function firstTokenName(): ?string
    {
        $row = db_connect()->table('auth_identities')
            ->where('type', 'access_token')
            ->get()
            ->getRowArray();

        return $row['name'] ?? null;
    }

    /**
     * 실제 커맨드라인을 세우고 CLI 를 다시 파싱시킨다.
     *
     * CLI::init() 은 is_cli() 일 때 segments·options 를 비우고 $_SERVER['argv'] 를
     * 다시 읽는다. 끝나면 원래 argv 로 되돌려 다음 테스트에 새지 않게 한다.
     *
     * @template T
     *
     * @param list<string>  $argv
     * @param callable(): T $fn
     *
     * @return T
     */
    private function withArgv(array $argv, callable $fn)
    {
        $original        = $_SERVER['argv'] ?? [];
        $_SERVER['argv'] = $argv;
        CLI::init();

        try {
            return $fn();
        } finally {
            $_SERVER['argv'] = $original;
            CLI::init();
        }
    }
}
