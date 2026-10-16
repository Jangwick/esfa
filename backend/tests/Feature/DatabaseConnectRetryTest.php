<?php

namespace Tests\Feature;

use App\Database\RetryingPostgresConnector;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use PDO;
use PDOException;
use Tests\TestCase;

class DatabaseConnectRetryTest extends TestCase
{
    private const TOO_MANY = 'SQLSTATE[08006] [7] connection to server at "aws-0-ap-northeast-1.pooler.supabase.com" (35.79.125.133), port 5432 failed: FATAL:  too many connections for role "sfa_app"';

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        Log::spy();
    }

    // A connector whose raw PDO connect throws the given errors in turn, then succeeds (no real PostgreSQL needed).
    private function connector(array $failures): RetryingPostgresConnector
    {
        return new class($failures) extends RetryingPostgresConnector
        {
            public int $attempts = 0;

            public function __construct(private array $failures) {}

            protected function createPdoConnection($dsn, $username, #[\SensitiveParameter] $password, $options)
            {
                $this->attempts++;
                if ($failure = array_shift($this->failures)) {
                    throw $failure;
                }

                return new PDO('sqlite::memory:');
            }
        };
    }

    private function connect(RetryingPostgresConnector $connector): PDO
    {
        return $connector->createConnection('pgsql:host=pooler;dbname=postgres', ['username' => 'sfa_app', 'password' => 'top-secret'], []);
    }

    public function test_it_retries_a_full_pooler_and_connects_on_the_third_attempt(): void
    {
        $connector = $this->connector([new PDOException(self::TOO_MANY), new PDOException(self::TOO_MANY)]);

        $this->assertInstanceOf(PDO::class, $this->connect($connector));
        $this->assertSame(3, $connector->attempts);
        Sleep::assertSleptTimes(2);
        Log::shouldHaveReceived('warning')->twice()->withArgs(fn ($message, $context) => ! str_contains(json_encode($context), 'top-secret'));
    }

    public function test_it_gives_up_after_four_attempts_and_rethrows_the_original_error(): void
    {
        $errors = [new PDOException(self::TOO_MANY), new PDOException('SQLSTATE[53300] remaining connection slots are reserved'), new PDOException('Max client connections reached'), $last = new PDOException('SQLSTATE[08006] [7] connection to server failed: FATAL:  sorry, too many clients already'), new PDOException(self::TOO_MANY)];
        $connector = $this->connector($errors);

        try {
            $this->connect($connector);
            $this->fail('The connect should have failed.');
        } catch (PDOException $e) {
            $this->assertSame($last, $e);
        }
        $this->assertSame(4, $connector->attempts);
        Sleep::assertSleptTimes(3);
        Sleep::assertSlept(fn ($duration) => $duration->totalMilliseconds >= 150 && $duration->totalMilliseconds <= 1000, 3);
    }

    public function test_it_does_not_retry_other_connect_errors(): void
    {
        $error = new PDOException('SQLSTATE[08006] [7] connection to server failed: FATAL:  password authentication failed for user "sfa_app"');
        $connector = $this->connector([$error]);

        try {
            $this->connect($connector);
            $this->fail('The connect should have failed.');
        } catch (PDOException $e) {
            $this->assertSame($error, $e);
        }
        $this->assertSame(1, $connector->attempts);
        Sleep::assertNeverSlept();
        Log::shouldNotHaveReceived('warning');
    }

    public function test_the_pgsql_driver_uses_the_retrying_connector(): void
    {
        $this->assertInstanceOf(RetryingPostgresConnector::class, app('db.connector.pgsql'));
        $this->assertInstanceOf(RetryingPostgresConnector::class, app('db.factory')->createConnector(['driver' => 'pgsql']));
    }
}
