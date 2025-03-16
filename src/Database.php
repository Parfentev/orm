<?php

namespace ORM;

use Doctrine\DBAL\{Connection, DriverManager, Exception, Statement};
use PDO;

class Database
{
    private Connection $db;
    private Statement  $query;

    public string  $lastError = '';

    /**
     * @throws Exception
     */
    public function __construct()
    {
        $db       = getenv('MYSQL_DB');
        $user     = getenv('MYSQL_USER');
        $password = getenv('MYSQL_PASSWORD');

        $this->db = DriverManager::getConnection([
            //'url' => "postgresql://$user:$password@database:5432/$db?serverVersion=11&charset=utf8",
            //'driver' => "pdo_mysql",
            //'url'    => "mysql://money:w33FaQpL&e*h@brepinorof.beget.app:3306/money",
            //'url' => "mysql://$user:$password@brepinorof.beget.app:3306/$db?charset=utf8",
            'dbname' => 'money',
            'user' => 'money',
            'password' => 'w33FaQpL&e*h',
            'host' => 'brepinorof.beget.app',
            'port' => 3306,
            'charset' => 'utf8mb4',
            'driver' => 'pdo_mysql',
            'driverOptions' => [
                PDO::ATTR_TIMEOUT => 0.1,
                PDO::ATTR_PERSISTENT => true// Таймаут подключения 3 секунды
            ]
        ]);
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