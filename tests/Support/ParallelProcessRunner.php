<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;
use Throwable;

final class ParallelProcessRunner
{
    /**
     * @template TJob
     *
     * @param  list<TJob>  $jobs
     * @param  Closure(TJob): string  $execute
     * @return list<string>
     *
     * @throws JsonException
     */
    public function run(array $jobs, int $workerCount, Closure $execute): array
    {
        if ($workerCount < 1 || $jobs === []) {
            throw new RuntimeException('Parallel execution requires jobs and at least one worker.');
        }

        $workerCount = min($workerCount, count($jobs));
        $batches = array_fill(0, $workerCount, []);

        foreach ($jobs as $index => $job) {
            $batches[$index % $workerCount][] = $job;
        }

        $directory = sys_get_temp_dir().'/wallet-concurrency-'.bin2hex(random_bytes(8));

        if (! mkdir($directory, 0700) && ! is_dir($directory)) {
            throw new RuntimeException('Could not create the concurrency result directory.');
        }

        $startAt = microtime(true) + 0.5;
        $children = [];

        foreach ($batches as $worker => $batch) {
            $processId = pcntl_fork();

            if ($processId === -1) {
                throw new RuntimeException('Could not fork a concurrency worker.');
            }

            if ($processId === 0) {
                $this->runChild($directory, $worker, $batch, $startAt, $execute);
            }

            $children[] = $processId;
        }

        foreach ($children as $processId) {
            $status = 0;
            pcntl_waitpid($processId, $status);

            if (! is_int($status)) {
                throw new RuntimeException("Concurrency worker {$processId} returned an invalid status.");
            }

            if (! pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
                throw new RuntimeException("Concurrency worker {$processId} failed.");
            }
        }

        DB::purge('pgsql');
        $results = [];

        for ($worker = 0; $worker < $workerCount; $worker++) {
            $contents = file_get_contents("{$directory}/{$worker}.json");

            if (! is_string($contents)) {
                throw new RuntimeException("Concurrency worker {$worker} returned no result.");
            }

            /** @var list<string> $workerResults */
            $workerResults = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
            array_push($results, ...$workerResults);
            unlink("{$directory}/{$worker}.json");
        }

        rmdir($directory);

        return $results;
    }

    /**
     * @template TJob
     *
     * @param  list<TJob>  $jobs
     * @param  Closure(TJob): string  $execute
     */
    private function runChild(
        string $directory,
        int $worker,
        array $jobs,
        float $startAt,
        Closure $execute,
    ): never {
        DB::purge('pgsql');

        while (microtime(true) < $startAt) {
            usleep(1_000);
        }

        $results = [];

        try {
            foreach ($jobs as $job) {
                $results[] = $execute($job);
            }
        } catch (Throwable $exception) {
            $results[] = 'worker_error:'.$exception::class.':'.$exception->getMessage();
        }

        try {
            file_put_contents(
                "{$directory}/{$worker}.json",
                json_encode($results, JSON_THROW_ON_ERROR),
                LOCK_EX,
            );
            DB::disconnect('pgsql');
            exit(0);
        } catch (Throwable) {
            exit(1);
        }
    }
}
