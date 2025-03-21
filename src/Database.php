<?php

namespace ORM;

use Doctrine\DBAL\{Connection, DriverManager, Exception, Statement};

class Database
{
    private Connection $db;
    private Statement  $query;
    public string $lastError = '';

    /**
     * @throws Exception
     */
    public function __construct(array $params)
    {
        $this->db = DriverManager::getConnection($params);
    }

    /**
     * @throws Exception
     */
    public function prepare(string $sql, array $args): self
    {
        $this->query = $this->db->prepare($sql);

        foreach ($args as $index => $value) {
            $this->query->bindValue($index + 1, $value);
        }

        return $this;
    }

    /**
     * @throws Exception
     */
    public function fetch(): array|false
    {
        return $this->query
            ->executeQuery()
            ->fetchAssociative();
    }

    /**
     * @throws Exception
     */
    public function fetchAll(): array
    {
        return $this->query
            ->executeQuery()
            ->fetchAllAssociative();
    }

    public function insert(string $table, array $data): int
    {
        return $this->db->insert($table, $data);
    }

    public function update(string $table, array $data, array $criteria): int
    {
        return $this->db->update($table, $data, $criteria);
    }

    public function delete(string $table, array $criteria): int
    {
        return $this->db->delete($table, $criteria);
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