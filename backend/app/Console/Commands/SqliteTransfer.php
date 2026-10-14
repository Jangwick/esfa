<?php

namespace App\Console\Commands;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use stdClass;
use Throwable;

/** Raw transfers bypass model events, casts, schedulers, and outbound integrations. */
class SqliteTransfer
{
    public function open(string $path): PDO
    {
        if (! is_file($path)) {
            throw new RuntimeException('The SQLite source file does not exist.');
        }

        return new PDO('sqlite:'.$path, null, null, [PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    public function snapshot(string $path): string
    {
        $source = $this->open($path);
        $snapshot = tempnam(sys_get_temp_dir(), 'sfa-transfer-');
        if ($snapshot === false) {
            throw new RuntimeException('A temporary SQLite snapshot could not be created.');
        }
        unlink($snapshot);

        try {
            // VACUUM INTO creates a consistent copy, including committed WAL data, on a read-only connection.
            $source->exec('VACUUM INTO '.$source->quote($snapshot));

            return $snapshot;
        } catch (Throwable $exception) {
            if (is_file($snapshot)) {
                unlink($snapshot);
            }
            throw $exception;
        }
    }

    public function sourceTables(PDO $source): array
    {
        if ($source->query('PRAGMA quick_check')->fetchColumn() !== 'ok') {
            throw new RuntimeException('The SQLite source failed its integrity check.');
        }
        if ($source->query('PRAGMA foreign_key_check')->fetch() !== false) {
            throw new RuntimeException('The SQLite source contains broken foreign-key relationships.');
        }

        return $source->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    }

    public function transfer(PDO $source, PDO $target, bool $dryRun = false, bool $verifyOnly = false, bool $rollback = false): array
    {
        if ($target->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            throw new RuntimeException('The destination must be PostgreSQL.');
        }
        $tables = $this->sourceTables($source);
        if (! in_array('migrations', $tables, true)) {
            throw new RuntimeException('The source has no Laravel migration history.');
        }
        $expected = $tables;
        $actual = $target->query("SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename")->fetchAll(PDO::FETCH_COLUMN);
        if ($expected !== $actual) {
            throw new RuntimeException('The source and destination table lists differ. Run application migrations on a dedicated, empty destination first.');
        }
        if ($target->query("SELECT COUNT(*) FROM pg_class t JOIN pg_namespace n ON n.oid=t.relnamespace WHERE n.nspname='public' AND t.relkind='r' AND NOT t.relrowsecurity")->fetchColumn() > 0) {
            throw new RuntimeException('Every destination application table must enable row-level security before importing.');
        }
        $migrationSql = 'SELECT migration FROM migrations ORDER BY migration';
        if ($source->query($migrationSql)->fetchAll(PDO::FETCH_COLUMN) !== $target->query($migrationSql)->fetchAll(PDO::FETCH_COLUMN)) {
            throw new RuntimeException('Source and destination migration histories differ. Apply the same schema migrations before importing.');
        }
        $tables = array_values(array_diff($tables, ['migrations']));
        $metadata = [];
        foreach ($tables as $table) {
            $statement = $target->prepare("SELECT column_name, data_type, numeric_scale FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ? ORDER BY ordinal_position");
            $statement->execute([$table]);
            $columns = $statement->fetchAll(PDO::FETCH_ASSOC);
            $sourceColumns = array_column($source->query('PRAGMA table_info('.$this->quote($table).')')->fetchAll(PDO::FETCH_ASSOC), 'name');
            $targetColumns = array_column($columns, 'column_name');
            sort($sourceColumns);
            sort($targetColumns);
            if ($sourceColumns !== $targetColumns) {
                throw new RuntimeException('Source and destination columns differ for '.$table.'.');
            }
            $metadata[$table] = array_column($columns, null, 'column_name');
        }

        $target->beginTransaction();
        try {
            $target->exec("SET LOCAL lock_timeout = '10s'");
            $target->exec("SET LOCAL TIME ZONE 'UTC'");
            if (! $verifyOnly) {
                $target->exec('LOCK TABLE '.implode(', ', array_map(fn ($table) => 'public.'.$this->quote($table), $expected)).' IN ACCESS EXCLUSIVE MODE');
                foreach ($tables as $table) {
                    if ($this->canonical($target->query('SELECT EXISTS (SELECT 1 FROM public.'.$this->quote($table).')')->fetchColumn(), ['data_type' => 'boolean']) === 'true') {
                        throw new RuntimeException('Destination '.$table.' already contains data. This command never overwrites existing application records.');
                    }
                }
            } else {
                $target->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }
            $report = ['mode' => $dryRun ? 'Validated' : ($verifyOnly ? 'Verified' : ($rollback ? 'Imported and verified, then rolled back' : 'Imported and verified')), 'rows' => 0, 'tables' => []];
            $constraints = $target->query("SELECT t.relname AS table_name, c.conname, c.condeferrable, c.condeferred FROM pg_constraint c JOIN pg_class t ON t.oid = c.conrelid JOIN pg_namespace n ON n.oid = t.relnamespace WHERE n.nspname = 'public' AND c.contype = 'f' ORDER BY t.relname, c.conname")->fetchAll(PDO::FETCH_ASSOC);
            if (! $dryRun && ! $verifyOnly) {
                foreach ($constraints as $constraint) {
                    $target->exec($this->constraintSql($constraint, 'DEFERRABLE INITIALLY DEFERRED'));
                }
                $target->exec('SET CONSTRAINTS ALL DEFERRED');
            }
            foreach ($tables as $table) {
                $expectedDigest = $this->digest($source, $table, $metadata[$table]);
                if (! $dryRun && ! $verifyOnly) {
                    $this->copy($source, $target, $table, $metadata[$table]);
                }
                if (! $dryRun && $expectedDigest !== $this->digest($target, $table, $metadata[$table])) {
                    $differences = [];
                    foreach ($metadata[$table] as $column => $type) {
                        if ($this->digest($source, $table, [$column => $type]) !== $this->digest($target, $table, [$column => $type])) {
                            $differences[] = $column.' ['.$type['data_type'].']';
                        }
                    }
                    throw new RuntimeException('Record verification failed for '.$table.'; differing fields: '.($differences ? implode(', ', $differences) : 'field relationships').'.');
                }
                $report['tables'][$table] = $expectedDigest;
                $report['rows'] += $expectedDigest['rows'];
            }
            if (! $dryRun && ! $verifyOnly) {
                $target->exec('SET CONSTRAINTS ALL IMMEDIATE');
                foreach ($constraints as $constraint) {
                    $deferrable = $this->canonical($constraint['condeferrable'], ['data_type' => 'boolean']) === 'true';
                    $deferred = $this->canonical($constraint['condeferred'], ['data_type' => 'boolean']) === 'true';
                    $flags = $deferrable ? 'DEFERRABLE INITIALLY '.($deferred ? 'DEFERRED' : 'IMMEDIATE') : 'NOT DEFERRABLE';
                    $target->exec($this->constraintSql($constraint, $flags));
                }
                $this->restoreSequences($source, $target, $tables, $metadata);
            }
            if ($rollback) {
                $target->rollBack();
            } else {
                $target->commit();
            }

            return $report;
        } catch (Throwable $exception) {
            if ($target->inTransaction()) {
                $target->rollBack();
            }
            throw $exception;
        }
    }

    public function digest(PDO $connection, string $table, array $metadata): array
    {
        $hashes = [];
        $rows = $connection->query('SELECT * FROM '.$this->quote($table));
        while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
            $canonical = [];
            foreach ($metadata as $column => $type) {
                $canonical[$column] = $this->canonical($row[$column], $type);
            }
            ksort($canonical);
            $hashes[] = hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
        }
        sort($hashes, SORT_STRING);

        return ['rows' => count($hashes), 'sha256' => hash('sha256', implode('', $hashes))];
    }

    public function canonical(mixed $value, array $type): mixed
    {
        if ($value === null) {
            return null;
        }
        switch ($type['data_type']) {
            case 'boolean':
                if (in_array($value, [true, 1, '1', 't', 'true'], true)) {
                    return 'true';
                }
                if (in_array($value, [false, 0, '0', 'f', 'false'], true)) {
                    return 'false';
                }
                throw new RuntimeException('A source boolean has an invalid value.');
            case 'smallint':
            case 'integer':
            case 'bigint':
                $integer = (string) $value;
                if (! preg_match('/^[+-]?\d+$/D', $integer)) {
                    throw new RuntimeException('A source integer has an invalid value.');
                }
                $digits = ltrim(ltrim($integer, '+-'), '0') ?: '0';

                return str_starts_with($integer, '-') && $digits !== '0' ? '-'.$digits : $digits;
            case 'numeric':
            case 'decimal':
                if (! is_numeric($value)) {
                    throw new RuntimeException('A source decimal has an invalid value.');
                }

                $scale = (int) ($type['numeric_scale'] ?? 0);
                $decimal = is_float($value) ? number_format($value, $scale, '.', '') : (string) $value;

                return bcadd($decimal, '0', $scale);
            case 'json':
            case 'jsonb':
                return json_encode($this->sortJson(json_decode((string) $value, false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING)), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            case 'timestamp with time zone':
            case 'timestamp without time zone':
                return (new DateTimeImmutable((string) $value, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
            case 'date':
                // Laravel's SQLite date cast may serialize midnight; PostgreSQL DATE keeps only the calendar date.
                return (new DateTimeImmutable((string) $value, new DateTimeZone('UTC')))->format('Y-m-d');
            case 'uuid':
                return strtolower((string) $value);
            default:
                return (string) $value;
        }
    }

    private function copy(PDO $source, PDO $target, string $table, array $metadata): void
    {
        $columns = array_keys($metadata);
        $rows = $source->query('SELECT * FROM '.$this->quote($table));
        $batch = [];
        $bytes = 0;
        while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
            $values = [];
            foreach ($columns as $column) {
                // JSON and text remain byte-for-byte opaque on insertion, including encrypted ciphertext.
                $value = $row[$column];
                $type = $metadata[$column];
                $values[] = in_array($type['data_type'], ['json', 'jsonb'], true) ? $value : $this->canonical($value, $type);
            }
            $batch[] = $values;
            $bytes += array_sum(array_map(fn ($value) => strlen((string) $value), $values));
            if (count($batch) >= 100 || $bytes >= 1024 * 1024) {
                $this->insertBatch($target, $table, $columns, $batch);
                $batch = [];
                $bytes = 0;
            }
        }
        if ($batch) {
            $this->insertBatch($target, $table, $columns, $batch);
        }
    }

    private function insertBatch(PDO $target, string $table, array $columns, array $batch): void
    {
        $placeholders = '('.implode(',', array_fill(0, count($columns), '?')).')';
        $sql = 'INSERT INTO public.'.$this->quote($table).' ('.implode(',', array_map($this->quote(...), $columns)).') VALUES '.implode(',', array_fill(0, count($batch), $placeholders));
        $target->prepare($sql)->execute(array_merge(...$batch));
    }

    private function restoreSequences(PDO $source, PDO $target, array $tables, array $metadata): void
    {
        $sequences = $source->query('SELECT name, seq FROM sqlite_sequence')->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($tables as $table) {
            if (! isset($metadata[$table]['id'])) {
                continue;
            }
            $statement = $target->prepare('SELECT pg_get_serial_sequence(?, ?)');
            $statement->execute(['public.'.$this->quote($table), 'id']);
            $sequence = $statement->fetchColumn();
            if (! $sequence) {
                continue;
            }
            $highest = max((int) ($sequences[$table] ?? 0), (int) $source->query('SELECT COALESCE(MAX(id),0) FROM '.$this->quote($table))->fetchColumn());
            if ($highest === PHP_INT_MAX) {
                throw new RuntimeException('The source sequence for '.$table.' has exhausted its integer range.');
            }
            $qualified = implode('.', array_map($this->quote(...), explode('.', $sequence)));
            // ALTER SEQUENCE RESTART participates in rollback, unlike setval().
            $target->exec('ALTER SEQUENCE '.$qualified.' RESTART WITH '.($highest + 1));
        }
    }

    private function sortJson(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $properties = get_object_vars($value);
            ksort($properties);
            $sorted = new stdClass;
            foreach ($properties as $key => $property) {
                $sorted->{$key} = $this->sortJson($property);
            }

            return $sorted;
        }

        return is_array($value) ? array_map($this->sortJson(...), $value) : $value;
    }

    private function constraintSql(array $constraint, string $flags): string
    {
        return 'ALTER TABLE public.'.$this->quote($constraint['table_name']).' ALTER CONSTRAINT '.$this->quote($constraint['conname']).' '.$flags;
    }

    private function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}
