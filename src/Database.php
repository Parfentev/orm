<?php

namespace ORM;

use Doctrine\DBAL\{Connection, DriverManager, Exception, Statement};

class Database
{
    private Connection $db;
    private Statement  $query;
    public string      $lastError = '';

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

    /**
     * @throws Exception
     */
    public function fetchAssociative(): array
    {
        return $this->query
            ->executeQuery()
            ->fetchAssociative();
    }

    /**
     * @throws Exception
     */
    public function fetchFirstColumn(): array
    {
        return $this->query
            ->executeQuery()
            ->fetchFirstColumn();
    }

    /**
     * @throws Exception
     */
    public function fetchOne(): int|string|false
    {
        return $this->query
            ->executeQuery()
            ->fetchOne();
    }

    public function insert(string $table, array $data): int|false
    {
        try {
            return $this->db->insert($table, $data);
        } catch (Exception) {
            //ошибка
            return false;
        }
    }

    public function update(string $table, array $data, array $criteria): int|false
    {
        try {
            return $this->db->update($table, $data, $criteria);
        } catch (Exception) {
            //ошибка
            return false;
        }
    }

    public function delete(string $table, array $criteria): int|false
    {
        try {
            return $this->db->delete($table, $criteria);
        } catch (Exception) {
            //ошибка
            return false;
        }
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