<?php

namespace ORM;

use App\Profiler;
use ORM\Connection\{ConnectionInterface, DoctrineConnection};
use Doctrine\DBAL\Exception;

class Database
{
    private ConnectionInterface $db;
    private string              $query;
    private array               $queryArgs;

    public function __construct(object $connection) {
        $this->db = match (true) {
            $connection instanceof \Doctrine\DBAL\Connection => new DoctrineConnection($connection),
            //$connection instanceof \PDO => new PdoConnection($connection),
            //$connection instanceof \wpdb => new WordPressConnection($connection),
            default => throw new \InvalidArgumentException('Unsupported connection type'),
        };
    }

    public function prepare(string $sql, array $args): self
    {
        $this->query     = $sql;
        $this->queryArgs = $args;
        $this->db->prepare($sql, $args);

        return $this;
    }

    public function fetch(): array|false
    {
        Profiler::startTimer('db fetch', ['sql' => $this->query, 'args' => $this->queryArgs]);
        $result = $this->db->fetch();
        Profiler::stopTimer();
        return $result;
    }

    public function fetchAll(): array
    {
        Profiler::startTimer('db fetch_all', ['sql' => $this->query, 'args' => $this->queryArgs]);
        $result = $this->db->fetchAll();
        Profiler::stopTimer();
        return $result;
    }

    public function fetchFirstColumn(): array
    {
        Profiler::startTimer('db fetch_first_column', ['sql' => $this->query, 'args' => $this->queryArgs]);
        $result = $this->db->fetchFirstColumn();
        Profiler::stopTimer();
        return $result;
    }

    public function fetchOne(): int|string|false
    {
        Profiler::startTimer('db fetch_one', ['sql' => $this->query, 'args' => $this->queryArgs]);
        $result = $this->db->fetchOne();
        Profiler::stopTimer();
        return $result;
    }

    public function insert(string $table, array $data): int|false
    {
        Profiler::startTimer('db insert', ['table' => $table, 'data' => $data]);
        $result = $this->db->insert($table, $data);
        Profiler::stopTimer();
        return $result;
    }

    public function update(string $table, array $data, array $criteria): int|false
    {
        Profiler::startTimer('db update', ['table' => $table, 'data' => $data, 'criteria' => $criteria]);
        $result = $this->db->update($table, $data, $criteria);
        Profiler::stopTimer();
        return $result;
    }

    public function delete(string $table, array $criteria): int|false
    {
        Profiler::startTimer('db delete', ['table' => $table, 'criteria' => $criteria]);
        $result = $this->db->delete($table, $criteria);
        Profiler::stopTimer();
        return $result;
    }

    public function getLastError()
    {
        return $this->db->lastError;
    }

    public function lastInsertId(): int|string|null
    {
        try {
            return $this->db->lastInsertId() ?? null;
        } catch (Exception) {
            return null;
        }
    }
}