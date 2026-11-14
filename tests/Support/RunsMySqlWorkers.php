<?php

namespace Tests\Support;

/**
 * Starts child PHP processes (tests/Support/mysql/*.php) on their own MySQL connections to the same test
 * database, waits for all of them and returns their trimmed stdout. Used by the mysql-group concurrency tests.
 */
trait RunsMySqlWorkers
{
    /**
     * @param  list<list<string>>  $argvs  arguments of each worker (all started before any is waited for)
     * @param  array<string, string>  $extraEnv
     * @return list<string> trimmed stdout of each worker, in the order of $argvs
     */
    protected function runWorkers(string $script, array $argvs, array $extraEnv = []): array
    {
        $db = config('database.connections.mysql');
        $env = array_merge([
            'APP_ENV' => 'testing',
            'APP_KEY' => (string) config('app.key'),
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => (string) $db['host'],
            'DB_PORT' => (string) $db['port'],
            'DB_DATABASE' => (string) $db['database'],
            'DB_USERNAME' => (string) $db['username'],
            'DB_PASSWORD' => (string) $db['password'],
            'DB_URL' => '',
            'QUOTA_WRITE_LIMIT' => '5000',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'LOG_CHANNEL' => 'null',
        ], $extraEnv);
        $path = base_path($script);

        $procs = [];
        foreach ($argvs as $args) {
            $pipes = [];
            // stderr goes to a file: an unread stderr pipe could fill up and block the child.
            $errFile = tempnam(sys_get_temp_dir(), 'mysql-worker');
            $proc = proc_open(
                array_merge([PHP_BINARY, $path], $args),
                [1 => ['pipe', 'w'], 2 => ['file', $errFile, 'w']],
                $pipes,
                base_path(),
                $env,
            );
            $this->assertIsResource($proc);
            $procs[] = [$proc, $pipes, $errFile];
        }

        $outputs = [];
        foreach ($procs as [$proc, $pipes, $errFile]) {
            $out = trim((string) stream_get_contents($pipes[1]));
            fclose($pipes[1]);
            $code = proc_close($proc);
            $err = trim((string) file_get_contents($errFile));
            unlink($errFile);
            $this->assertSame(0, $code, 'worker failed: '.$err.' / '.$out);
            $outputs[] = $out;
        }

        return $outputs;
    }
}
