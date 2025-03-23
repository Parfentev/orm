<?php

namespace ORM;

use ORM\Connection\{ConnectionInterface, DoctrineConnection};

class Database
{
    private ConnectionInterface $db;

    public function __construct(object $connection) {
        $this->db = $this->resolveConnection($connection);
    }

    private function resolveConnection(object $connection): DoctrineConnection
    {
        return match (true) {
            $connection instanceof \Doctrine\DBAL\Connection => new DoctrineConnection($connection),
            //$connection instanceof \PDO => new PdoConnection($connection),
            //$connection instanceof \wpdb => new WordPressConnection($connection),
            default => throw new \InvalidArgumentException('Unsupported connection type'),
        };
    }

    public function prepare(string $sql, array $args): self
    {
        $this->db->prepare($sql, $args);

        return $this;
    }

    public function fetch(): array|false
    {
        return $this->db->fetch();
    }

    public function fetchAll(): array
    {
        return $this->db->fetchAll();
    }

    public function fetchFirstColumn(): array
    {
        return $this->db->fetchFirstColumn();
    }

    public function fetchOne(): int|string|false
    {
        return $this->db->fetchOne();
    }

    public function insert(string $table, array $data): int|false
    {
        return $this->db->insert($table, $data);
    }

    public function update(string $table, array $data, array $criteria): int|false
    {
        return $this->db->update($table, $data, $criteria);
    }

    public function delete(string $table, array $criteria): int|false
    {
        return $this->db->delete($table, $criteria);
    }

    public function lastInsertId(): int|string|null
    {
        return $this->db->lastInsertId();
    }
}