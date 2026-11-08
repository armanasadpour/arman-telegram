<?php
declare(strict_types=1);

namespace PM\Infra;

use PDO;

final class Database
{
    private int $txDepth = 0;

    public function __construct(public readonly PDO $pdo)
    {
    }

    public static function connect(string $host, int $port, string $name, string $user, string $pass): self
    {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name),
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
                PDO::ATTR_TIMEOUT => 5,
            ]
        );
        $pdo->exec("SET time_zone = '+00:00', sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
        return new self($pdo);
    }

    public function run(string $sql, array $params = []): \PDOStatement
    {
        $st = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $key = is_int($k) ? $k + 1 : (str_starts_with($k, ':') ? $k : ':' . $k);
            $type = match (true) {
                is_int($v) => PDO::PARAM_INT,
                is_bool($v) => PDO::PARAM_BOOL,
                $v === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $st->bindValue($key, $v, $type);
        }
        $st->execute();
        return $st;
    }

    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    public function scalar(string $sql, array $params = []): mixed
    {
        $v = $this->run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public function insert(string $sql, array $params = []): int
    {
        $this->run($sql, $params);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * @template T
     * @param callable(self):T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        if ($this->txDepth > 0) {
            $this->txDepth++;
            try {
                return $fn($this);
            } finally {
                $this->txDepth--;
            }
        }
        $this->pdo->beginTransaction();
        $this->txDepth = 1;
        try {
            $result = $fn($this);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        } finally {
            $this->txDepth = 0;
        }
    }
}
