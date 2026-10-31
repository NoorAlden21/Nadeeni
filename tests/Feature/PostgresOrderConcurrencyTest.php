<?php

namespace Tests\Feature;

use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

#[Group('postgres')]
class PostgresOrderConcurrencyTest extends TestCase
{
    private const TESTING_DATABASE = 'nadeeni_testing';

    public function test_postgres_maps_a_duplicate_active_number_to_validation(): void
    {
        $this->prepareDatabase();
        $setup = $this->workerJson(['setup-duplicate']);

        $this->assertSame('created', $this->worker(['create', (string) $setup['user_id'], $setup['order_number']]));
        $this->assertSame('validation', $this->worker(['create', (string) $setup['user_id'], $setup['order_number']]));

        $orders = $this->workerJson(['counts', (string) $setup['restaurant_id'], $setup['order_number']]);
        $this->assertSame([
            ['status' => 'waiting'],
        ], array_map(fn (array $order): array => ['status' => $order['status']], $orders));
    }

    public function test_two_concurrent_creates_leave_one_active_order(): void
    {
        $this->prepareDatabase();
        $setup = $this->workerJson(['setup-duplicate']);

        $results = $this->concurrentCreates((string) $setup['user_id'], $setup['order_number']);

        $this->assertSame(['created', 'validation'], $results);
        $this->assertSame(
            ['waiting' => 1],
            $this->statusCounts((string) $setup['restaurant_id'], $setup['order_number']),
        );
    }

    public function test_two_concurrent_reuses_of_a_due_number_leave_one_active_order(): void
    {
        $this->prepareDatabase();
        $setup = $this->workerJson(['setup-expire']);

        $results = $this->concurrentCreates((string) $setup['user_id'], $setup['order_number']);

        $this->assertSame(['created', 'validation'], $results);

        $orders = $this->workerJson(['counts', (string) $setup['restaurant_id'], $setup['order_number']]);
        $this->assertCount(2, $orders);
        $this->assertSame('expired', collect($orders)->firstWhere('id', $setup['order_id'])['status']);
        $this->assertSame(
            ['expired' => 1, 'waiting' => 1],
            $this->statusCounts((string) $setup['restaurant_id'], $setup['order_number']),
        );
    }

    private function prepareDatabase(): void
    {
        if (! extension_loaded('pdo_pgsql')) {
            $this->fail('pdo_pgsql is required to verify the order number race on PostgreSQL.');
        }

        $file = $this->readEnvFile();

        try {
            $pdo = new PDO(
                sprintf('pgsql:host=%s;port=%s;dbname=postgres', $file['DB_HOST'], $file['DB_PORT']),
                $file['DB_USERNAME'],
                $file['DB_PASSWORD'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
        } catch (PDOException $exception) {
            $this->fail('Could not connect to PostgreSQL for the concurrency test.');
        }

        $exists = $pdo->query("SELECT 1 FROM pg_database WHERE datname = 'nadeeni_testing'")->fetchColumn();

        if (! $exists) {
            $pdo->exec('CREATE DATABASE nadeeni_testing');
        }

        $this->worker(['migrate']);
    }

    /**
     * @return list<string>
     */
    private function concurrentCreates(string $userId, string $orderNumber): array
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nadeeni-'.bin2hex(random_bytes(4));
        mkdir($directory);
        $go = $directory.DIRECTORY_SEPARATOR.'go';
        $ready = [
            $directory.DIRECTORY_SEPARATOR.'ready-a',
            $directory.DIRECTORY_SEPARATOR.'ready-b',
        ];

        $processes = [
            $this->process(['create', $userId, $orderNumber, $ready[0], $go]),
            $this->process(['create', $userId, $orderNumber, $ready[1], $go]),
        ];

        try {
            $processes[0]->start();
            $processes[1]->start();

            $deadline = microtime(true) + 20;
            while (! is_file($ready[0]) || ! is_file($ready[1])) {
                foreach ($processes as $process) {
                    if (! $process->isRunning() && $process->getExitCode() !== null && $process->getExitCode() !== 0) {
                        $this->fail($process->getErrorOutput()."\n".$process->getOutput());
                    }
                }

                if (microtime(true) > $deadline) {
                    $this->fail($this->processOutput($processes));
                }

                usleep(20000);
            }

            file_put_contents($go, 'go');

            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput()."\n".$process->getOutput());
                $results[] = trim($process->getOutput());
            }

            sort($results);

            return $results;
        } finally {
            @unlink($go);
            @unlink($ready[0]);
            @unlink($ready[1]);
            @rmdir($directory);
        }
    }

    /**
     * @param  list<array<string, string>>  $orders
     * @return array<string, int>
     */
    private function statusCounts(string $restaurantId, string $orderNumber): array
    {
        $counts = [];

        foreach ($this->workerJson(['counts', $restaurantId, $orderNumber]) as $order) {
            $counts[$order['status']] = ($counts[$order['status']] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    /**
     * @param  list<string>  $arguments
     * @return array<string, mixed>
     */
    private function workerJson(array $arguments): array
    {
        $decoded = json_decode($this->worker($arguments), true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Worker returned invalid JSON.');
        }

        return $decoded;
    }

    /**
     * @param  list<string>  $arguments
     */
    private function worker(array $arguments): string
    {
        $process = $this->process($arguments);
        $process->run();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput()."\n".$process->getOutput());

        return trim($process->getOutput());
    }

    /**
     * @param  list<string>  $arguments
     */
    private function process(array $arguments): Process
    {
        return new Process(
            array_merge([PHP_BINARY, base_path('tests/Support/postgres_testing.php')], $arguments),
            base_path(),
            $this->postgresProcessEnvironment(),
            null,
            60,
        );
    }

    /**
     * @param  list<Process>  $processes
     */
    private function processOutput(array $processes): string
    {
        return implode("\n", array_map(
            fn (Process $process): string => $process->getErrorOutput()."\n".$process->getOutput(),
            $processes,
        ));
    }

    /**
     * @return array<string, string>
     */
    private function postgresProcessEnvironment(): array
    {
        $file = $this->readEnvFile();
        $environment = [];

        foreach (getenv() as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $environment[$key] = $value;
            }
        }

        foreach (['APP_KEY', 'APP_NAME', 'APP_URL'] as $key) {
            if (($environment[$key] ?? '') === '' && isset($file[$key])) {
                $environment[$key] = $file[$key];
            }
        }

        $environment['APP_ENV'] = 'local';
        $environment['DB_CONNECTION'] = 'pgsql';
        $environment['DB_HOST'] = $file['DB_HOST'];
        $environment['DB_PORT'] = $file['DB_PORT'];
        $environment['DB_DATABASE'] = self::TESTING_DATABASE;
        $environment['DB_USERNAME'] = $file['DB_USERNAME'];
        $environment['DB_PASSWORD'] = $file['DB_PASSWORD'];

        return $environment;
    }

    /**
     * @return array<string, string>
     */
    private function readEnvFile(): array
    {
        $values = [];

        foreach (file(base_path('.env'), FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $values[trim($key)] = trim($value, " \t\"'");
        }

        foreach (['DB_HOST', 'DB_PORT', 'DB_USERNAME', 'DB_PASSWORD'] as $key) {
            if (($values[$key] ?? '') === '') {
                $this->fail('The concurrency test needs '.$key.' in .env.');
            }
        }

        return $values;
    }
}
