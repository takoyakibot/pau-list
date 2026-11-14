<?php

namespace Tests\Feature;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DeployScriptTest extends TestCase
{
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
}
