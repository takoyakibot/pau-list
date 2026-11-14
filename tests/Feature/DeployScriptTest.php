<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DeployScriptTest extends TestCase
{
    private ?string $workDir = null;

    protected function tearDown(): void
    {
        if ($this->workDir !== null && is_dir($this->workDir)) {
            File::deleteDirectory($this->workDir);
        }
        $this->workDir = null;

        parent::tearDown();
    }

    public function test_deploy_script_has_valid_bash_syntax(): void
    {
        $bash = (new ExecutableFinder)->find('bash');
        if ($bash === null) {
            $this->markTestSkipped('bash is not installed');
        }

        $process = new Process([$bash, '-n', base_path('deploy.sh')]);
        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
    }

    public function test_migrate_runs_before_cache_clear(): void
    {
        // CACHE_STORE=database のため、初回デプロイでは cache テーブル作成（migrate）が cache:clear より先に必要
        $script = file_get_contents(base_path('deploy.sh'));

        $migrate = strpos($script, 'artisan migrate --force');
        $cacheClear = strpos($script, 'artisan cache:clear');

        $this->assertNotFalse($migrate);
        $this->assertNotFalse($cacheClear);
        $this->assertLessThan($cacheClear, $migrate);
    }

    public function test_rsync_uses_delete_after(): void
    {
        $script = file_get_contents(base_path('deploy.sh'));

        $this->assertStringContainsString('--delete-after', $script);
        $this->assertStringNotContainsString(' --delete ', $script);
        $this->assertDoesNotMatchRegularExpression('/--delete\s*\\\\?$/m', $script);
    }

    public static function unsafeRemotePaths(): array
    {
        return [['relative/x'], ['/'], ['/a b'], ['/srv/app;rm -rf ~'], ['/..'], ['/home/u/..'], ['/.'], ['/home/u/../v'], ['/home/./u']];
    }

    #[DataProvider('unsafeRemotePaths')]
    public function test_remote_path_must_be_an_absolute_safe_path(string $remotePath): void
    {
        $bash = (new ExecutableFinder)->find('bash');
        if ($bash === null) {
            $this->markTestSkipped('bash is not installed');
        }

        $this->workDir = sys_get_temp_dir().'/listmaker-deploy-'.bin2hex(random_bytes(6));
        $bin = $this->workDir.'/bin';
        $marker = $this->workDir.'/reached';
        File::makeDirectory($bin, 0700, true);
        File::copy(base_path('deploy.sh'), $this->workDir.'/deploy.sh');
        File::put($this->workDir.'/deploy.env', implode("\n", [
            'SERVER=user@example.invalid',
            'SSH_KEY=/nonexistent/key',
            "REMOTE_PATH='".$remotePath."'",
            'ALLOW_NON_MAIN_DEPLOY=0',
            '',
        ]));
        // 形式チェックより先に外部コマンドへ進んだら検出できるよう、失敗するダミーを PATH の先頭に置く
        foreach (['git', 'rsync', 'ssh', 'php', 'npm', 'composer'] as $command) {
            File::put("$bin/$command", "#!/bin/sh\necho \"$command\" >> '$marker'\nexit 1\n");
            chmod("$bin/$command", 0700);
        }

        $process = new Process([$bash, $this->workDir.'/deploy.sh'], $this->workDir, [
            'PATH' => $bin.':'.getenv('PATH'),
            'ALLOW_NON_MAIN_DEPLOY' => '0',
        ]);
        $process->run();

        $this->assertSame(1, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        $this->assertStringContainsString('REMOTE_PATH', $process->getOutput().$process->getErrorOutput());
        $this->assertFileDoesNotExist($marker, 'deploy.sh must stop before running git/rsync');
    }

    public function test_failure_after_rsync_says_files_were_sent(): void
    {
        $script = file_get_contents(base_path('deploy.sh'));

        $this->assertMatchesRegularExpression('/^RSYNC_DONE=0$/m', $script);
        $rsync = strpos($script, 'rsync -avz');
        $done = strpos($script, "\nRSYNC_DONE=1\n");
        $remote = strpos($script, '▶ 3.');
        $this->assertNotFalse($rsync);
        $this->assertNotFalse($done);
        $this->assertTrue($rsync < $done && $done < $remote, 'RSYNC_DONE=1 must be set right after rsync');
        $this->assertStringContainsString('ファイルは本番に転送済みです。サーバー側の手順（migrate など）が終わっていません', $script);
        $onExit = substr($script, strpos($script, 'on_exit() {'), strpos($script, 'trap on_exit EXIT') - strpos($script, 'on_exit() {'));
        $this->assertStringContainsString('RSYNC_DONE', $onExit);
        $this->assertStringContainsString('ファイルは本番に転送済みです', $onExit);
    }
}
