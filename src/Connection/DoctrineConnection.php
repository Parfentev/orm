<?php

namespace ORM\Connection;

use App\Profiler;
use Doctrine\DBAL\{Connection, Exception, Statement};

class DoctrineConnection implements ConnectionInterface
{
    private Connection $connection;
    private Statement  $query;
    public string      $lastError = '';

    public function __construct(Connection $connection)
    {
        Profiler::startTimer('db doctrine');
        $this->connection = $connection;
        $this->connection->getNativeConnection();
        Profiler::stopTimer();
    }

    public function prepare(string $sql, array $args): self
    {
        $this->query     = $this->connection->prepare($sql);

        foreach ($args as $index => $value) {
            $this->query->bindValue($index + 1, $value);
        }

        return $this;
    }

    public function fetch(): array|false
    {
        return $this->query
            ->executeQuery()
            ->fetchAssociative();
    }

    public function fetchAll(): array
    {
        return $this->query
            ->executeQuery()
            ->fetchAllAssociative();
    }

    public function fetchFirstColumn(): array
    {
        return $this->query
            ->executeQuery()
            ->fetchFirstColumn();
    }

    public function fetchOne(): int|string|false
    {
        return $this->query
            ->executeQuery()
            ->fetchOne();
    }

    public function insert(string $table, array $data): int|false
    {
        try {
            return $this->connection->insert($table, $data);
        } catch (Exception) {
            //ошибка
            return false;
        }
    }

    public function update(string $table, array $data, array $criteria): int|false
    {
        try {
            return $this->connection->update($table, $data, $criteria);
        } catch (Exception) {
            //ошибка
            return false;
        }
    }

    public function delete(string $table, array $criteria): int|false
    {
        try {
            return $this->connection->delete($table, $criteria);
        } catch (Exception) {
            //ошибка
            return false;
        }
    }

    public function lastInsertId(): int|string|null
    {
        try {
            return $this->connection->lastInsertId() ?? null;
        } catch (Exception) {
            return null;
        }
    }
}