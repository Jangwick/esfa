<?php

namespace Tests\Feature;

use App\Console\Commands\SqliteTransfer;
use PDO;
use PDOException;
use RuntimeException;
use Tests\TestCase;

class SqliteTransferTest extends TestCase
{
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            foreach ([$path, $path.'-wal', $path.'-shm'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
        parent::tearDown();
    }

    public function test_read_only_snapshot_includes_committed_wal_and_deleted_id_high_water_mark(): void
    {
        $path = $this->temporaryDatabase();
        $fixture = new PDO('sqlite:'.$path);
        $fixture->exec('PRAGMA journal_mode=WAL');
        $fixture->exec('CREATE TABLE records (id INTEGER PRIMARY KEY AUTOINCREMENT, payload TEXT)');
        $fixture->prepare('INSERT INTO records (id, payload) VALUES (?, ?)')->execute([1, 'opaque encrypted bytes']);
        $fixture->exec("INSERT INTO records VALUES (40, 'deleted')");
        $fixture->exec('DELETE FROM records WHERE id=40');
        $before = [hash_file('sha256', $path), hash_file('sha256', $path.'-wal')];

        $transfer = new SqliteTransfer;
        $snapshot = $transfer->snapshot($path);
        $this->temporaryFiles[] = $snapshot;
        $copy = $transfer->open($snapshot);
        $this->assertSame('opaque encrypted bytes', $copy->query('SELECT payload FROM records')->fetchColumn());
        $this->assertSame(40, (int) $copy->query("SELECT seq FROM sqlite_sequence WHERE name='records'")->fetchColumn());
        $this->assertSame($before, [hash_file('sha256', $path), hash_file('sha256', $path.'-wal')]);
        $copy = null;
        $fixture = null;
    }

    public function test_source_connection_cannot_write(): void
    {
        $path = $this->temporaryDatabase();
        $fixture = new PDO('sqlite:'.$path);
        $fixture->exec('CREATE TABLE records (id INTEGER PRIMARY KEY)');
        $fixture = null;
        $source = (new SqliteTransfer)->open($path);
        $this->expectException(PDOException::class);
        $source->exec('INSERT INTO records VALUES (1)');
    }

    public function test_cross_driver_values_normalize_without_losing_money_or_json_structure(): void
    {
        $transfer = new SqliteTransfer;
        $boolean = ['data_type' => 'boolean'];
        $this->assertSame($transfer->canonical(0, $boolean), $transfer->canonical(false, $boolean));
        $this->assertSame($transfer->canonical(1, $boolean), $transfer->canonical('t', $boolean));
        $this->assertSame('9223372036854775806', $transfer->canonical('9223372036854775806', ['data_type' => 'bigint']));
        $this->assertSame('9007199254740993.1200', $transfer->canonical('9007199254740993.12', ['data_type' => 'numeric', 'numeric_scale' => 4]));
        $this->assertSame('14.1234567', $transfer->canonical(14.1234567, ['data_type' => 'numeric', 'numeric_scale' => 7]));
        $timestamp = ['data_type' => 'timestamp with time zone'];
        $this->assertSame($transfer->canonical('2026-10-11 00:00:00', $timestamp), $transfer->canonical('2026-10-11 08:00:00+08', $timestamp));
        $date = ['data_type' => 'date'];
        $this->assertSame('2026-10-11', $transfer->canonical('2026-10-11 00:00:00', $date));
        $this->assertSame($transfer->canonical('2026-10-11', $date), $transfer->canonical('2026-10-11 00:00:00', $date));
        $json = ['data_type' => 'jsonb'];
        $this->assertSame($transfer->canonical('{"b":[],"a":{"z":1,"y":2}}', $json), $transfer->canonical('{"a":{"y":2,"z":1},"b":[]}', $json));
        $this->assertNotSame($transfer->canonical('{}', $json), $transfer->canonical('[]', $json));
    }

    public function test_fractional_money_is_rejected_without_showing_the_value(): void
    {
        try {
            (new SqliteTransfer)->canonical('314159.123', ['data_type' => 'bigint']);
            $this->fail('Fractional integer data was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString('314159', $exception->getMessage());
        }
    }

    public function test_digests_detect_every_opaque_field_and_preserve_duplicate_rows_without_disclosing_values(): void
    {
        $connection = new PDO('sqlite::memory:');
        $connection->exec('CREATE TABLE records (id INTEGER, secret TEXT, content TEXT)');
        $insert = $connection->prepare('INSERT INTO records VALUES (?, ?, ?)');
        $insert->execute([1, 'secret-one', base64_encode('attachment file bytes')]);
        $insert->execute([2, 'secret-two', base64_encode('other bytes')]);
        $types = ['id' => ['data_type' => 'bigint'], 'secret' => ['data_type' => 'text'], 'content' => ['data_type' => 'text']];
        $transfer = new SqliteTransfer;
        $before = $transfer->digest($connection, 'records', $types);
        $this->assertSame(2, $before['rows']);
        $this->assertStringNotContainsString('secret-one', json_encode($before));
        $connection->exec("UPDATE records SET secret='secret-three' WHERE id=1");
        $this->assertNotSame($before['sha256'], $transfer->digest($connection, 'records', $types)['sha256']);
        $connection->exec("UPDATE records SET secret='secret-one' WHERE id=1");
        $this->assertSame($before, $transfer->digest($connection, 'records', array_reverse($types, true)));
        $connection->exec('INSERT INTO records SELECT * FROM records WHERE id=1');
        $this->assertSame(3, $transfer->digest($connection, 'records', $types)['rows']);
        $this->assertNotSame($before['sha256'], $transfer->digest($connection, 'records', $types)['sha256']);
    }

    public function test_source_foreign_key_violations_are_rejected(): void
    {
        $connection = new PDO('sqlite::memory:');
        $connection->exec('CREATE TABLE parents (id INTEGER PRIMARY KEY)');
        $connection->exec('CREATE TABLE children (parent_id INTEGER REFERENCES parents(id))');
        $connection->exec('INSERT INTO children VALUES (1)');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('broken foreign-key relationships');
        (new SqliteTransfer)->sourceTables($connection);
    }

    public function test_command_refuses_sqlite_destination_before_touching_source(): void
    {
        $path = $this->temporaryDatabase();
        $before = hash_file('sha256', $path);
        $this->artisan('sfa:import-sqlite', ['source' => $path, '--database' => 'sqlite'])
            ->expectsOutputToContain('The destination must be PostgreSQL')
            ->assertFailed();
        $this->assertSame($before, hash_file('sha256', $path));
    }

    private function temporaryDatabase(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sfa-transfer-test-');
        $this->temporaryFiles[] = $path;

        return $path;
    }
}
