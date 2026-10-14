<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDOException;
use RuntimeException;
use Throwable;

class ImportSqlite extends Command
{
    protected $signature = 'sfa:import-sqlite {source? : Local SQLite database or a saved snapshot}
        {--database=pgsql : Configured PostgreSQL destination connection}
        {--dry-run : Validate the source, target schema, and empty destination without copying}
        {--verify-only : Compare every destination record with the source without copying}
        {--rollback : Exercise the complete import and verification, then roll back every destination change}
        {--report= : Save a JSON report containing only counts and SHA-256 digests}';

    protected $description = 'Copy all SQLite application data to an empty PostgreSQL schema, preserving IDs and verifying every field';

    public function handle(): int
    {
        $snapshot = null;

        try {
            if (count(array_filter([$this->option('dry-run'), $this->option('verify-only'), $this->option('rollback')])) > 1) {
                throw new RuntimeException('Choose only one of --dry-run, --verify-only, or --rollback.');
            }
            if ($this->option('report') && file_exists((string) $this->option('report'))) {
                throw new RuntimeException('The report path already exists; choose a new path.');
            }

            $destination = DB::connection((string) $this->option('database'));
            if ($destination->getDriverName() !== 'pgsql') {
                throw new RuntimeException('The destination must be PostgreSQL. The source is never changed.');
            }

            $transfer = new SqliteTransfer;
            $snapshot = $transfer->snapshot((string) ($this->argument('source') ?: database_path('database.sqlite')));
            $source = $transfer->open($snapshot);
            $report = $transfer->transfer($source, $destination->getPdo(), (bool) $this->option('dry-run'), (bool) $this->option('verify-only'), (bool) $this->option('rollback'));
            $source = null;

            if ($this->option('report')) {
                $path = (string) $this->option('report');
                if (file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL, LOCK_EX) === false) {
                    throw new RuntimeException('The data operation succeeded, but the report could not be saved.');
                }
            }

            foreach ($report['tables'] as $table => $result) {
                $this->line($table.': '.$result['rows'].' rows; sha256='.$result['sha256']);
            }
            $this->info($report['mode'].': '.$report['rows'].' rows across '.count($report['tables']).' application tables. Migration history matched; PostgreSQL keeps its own migration batches.');

            return self::SUCCESS;
        } catch (PDOException $exception) {
            $this->error('Data transfer failed ('.class_basename($exception).'). Any active import transaction was rolled back; no record values are printed.');

            return self::FAILURE;
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            // Database exceptions can contain bound passwords, encrypted secrets, or personal data.
            $this->error('Data transfer failed ('.class_basename($exception).'). Any active import transaction was rolled back; no record values are printed.');

            return self::FAILURE;
        } finally {
            $source = null;
            // Caught exceptions retain their argument objects in the trace, including the SQLite PDO.
            $exception = null;
            gc_collect_cycles();
            if ($snapshot && is_file($snapshot) && ! @unlink($snapshot)) {
                $this->warn('The temporary snapshot could not be removed. The data operation result above is unchanged.');
            }
        }
    }
}
