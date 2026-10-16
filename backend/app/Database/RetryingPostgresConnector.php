<?php

namespace App\Database;

use Illuminate\Database\Connectors\PostgresConnector;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use PDOException;

// Rides out short bursts where the Supabase pooler refuses new connections ("too many connections for role"): the
// connect is tried up to 4 times with a growing pause plus jitter, then the last error is rethrown unchanged. Only the
// connect is retried, never a query; any other connect error fails at once. Bound as 'db.connector.pgsql'.
class RetryingPostgresConnector extends PostgresConnector
{
    public const DELAYS_MS = [150, 400, 900];

    private const TOO_MANY = ['SQLSTATE[53300]', 'too many connections', 'remaining connection slots are reserved', 'max client connections reached', 'max clients reached', 'too many clients'];

    public function createConnection($dsn, array $config, array $options)
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return parent::createConnection($dsn, $config, $options);
            } catch (PDOException $e) {
                if ($attempt > count(self::DELAYS_MS) || ! $this->tooManyConnections($e)) {
                    throw $e;
                }
                $delay = self::DELAYS_MS[$attempt - 1] + random_int(0, 100);
                Log::warning('Database connection limit reached; retrying the connect.', ['attempt' => $attempt, 'delay_ms' => $delay]);
                Sleep::for($delay)->milliseconds();
            }
        }
    }

    private function tooManyConnections(PDOException $e): bool
    {
        return (string) $e->getCode() === '53300' || Str::contains($e->getMessage(), self::TOO_MANY, true);
    }
}
