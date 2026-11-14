<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * deploy.sh は `rsync --exclude-from=.exclude-list` でプロジェクトルートを丸ごと送るため、
 * .exclude-list の抜けはそのまま秘密情報の流出になる。リポジトリ本体には触らず、
 * 一時ディレクトリに作ったダミーのツリーに対してローカルの rsync dry-run を行って確認する。
 */
class DeployExcludeListTest extends TestCase
{
    private const MUST_NOT_TRANSFER = [
        '.env',
        '.env.backup',
        '.env.production',
        '.env.local',
        '.env.example',
        'deploy.env',
        'deploy.env.example',
        'auth.json',
        '.DS_Store',
        'app/.DS_Store',
        'docs/spec.md',
        'tests/ExampleTest.php',
        'notes.ini',
        'public/hot',
        '.superpowers/progress.md',
        '.git/HEAD',
        '.github/workflows/ci.yml',
        'storage/logs/laravel.log',
        'storage/framework/sessions/abc',
        'database/database.sqlite',
        'phpunit.xml',
        'deploy.sh',
    ];

    private const MUST_TRANSFER = [
        '.htaccess',
        'app/Http/Kernel.php',
        'public/index.php',
        'public/.htaccess',
        'public/build/manifest.json',
        'vendor/autoload.php',
        'config/app.php',
        'artisan',
    ];

    private ?string $workDir = null;

    protected function tearDown(): void
    {
        if ($this->workDir !== null && is_dir($this->workDir)) {
            File::deleteDirectory($this->workDir);
        }
        $this->workDir = null;

        parent::tearDown();
    }

    public function test_exclude_list_blocks_secrets_and_dev_files(): void
    {
        $rsync = (new ExecutableFinder)->find('rsync');
        if ($rsync === null) {
            $this->markTestSkipped('rsync is not installed');
        }

        $this->workDir = sys_get_temp_dir().'/listmaker-exclude-'.bin2hex(random_bytes(6));
        $src = $this->workDir.'/src';
        $dst = $this->workDir.'/dst';
        File::makeDirectory($dst, 0700, true);

        foreach ([...self::MUST_NOT_TRANSFER, ...self::MUST_TRANSFER] as $path) {
            File::ensureDirectoryExists(dirname("$src/$path"));
            File::put("$src/$path", "decoy\n");
        }
        File::copy(base_path('.exclude-list'), "$src/.exclude-list");

        $process = new Process(
            [$rsync, '-an', '--out-format=%n', '--exclude-from=.exclude-list', './', $dst.'/'],
            $src,
        );
        $process->mustRun();

        $sent = array_map(
            fn (string $line) => rtrim($line, '/'),
            array_filter(array_map('trim', explode("\n", $process->getOutput()))),
        );

        foreach (self::MUST_NOT_TRANSFER as $path) {
            $this->assertNotContains($path, $sent, "$path must not be deployed");
        }
        foreach (self::MUST_TRANSFER as $path) {
            $this->assertContains($path, $sent, "$path must be deployed");
        }
        $this->assertNotContains('.exclude-list', $sent);
        $this->assertSame([], File::allFiles($dst, true), 'dry run must not copy anything');
    }
}
