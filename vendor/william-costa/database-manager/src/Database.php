<?php

namespace WilliamCosta\DatabaseManager;

use PDO;
use PDOStatement;
use PDOException;

/**
 * Classe Database
 * 
 * Gerencia conexão e operações PDO de forma segura com prepared statements.
 */
class Database
{
    private static string $host;
    private static string $name;
    private static string $user;
    private static string $pass;
    private static int $port;

    private string $table;
    private PDO $connection;

    /**
     * Configurações globais de conexão
     */
    public static function config(
        string $host,
        string $name,
        string $user,
        string $pass,
        int $port = 3306
    ): void {
        self::$host = $host;
        self::$name = $name;
        self::$user = $user;
        self::$pass = $pass;
        self::$port = $port;
    }

    /**
     * Construtor com tabela opcional
     */
    public function __construct(?string $table = null)
    {
        $this->table = $table ?? '';
        $this->setConnection();
    }

    /**
     * Estabelece a conexão PDO
     */
    private function setConnection(): void
    {
        try {
            $dsn = "mysql:host=" . self::$host . ";dbname=" . self::$name . ";port=" . self::$port . ";charset=utf8mb4";
            $this->connection = new PDO($dsn, self::$user, self::$pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
        } catch (PDOException $e) {
            die('DB Connection Error: ' . $e->getMessage());
        }
    }

    /**
     * Executa query preparada com bind params seguro
     */
    public function execute(string $query, array $params = []): PDOStatement
    {
        try {
            $statement = $this->connection->prepare($query);
            $statement->execute($params);
            return $statement;
        } catch (PDOException $e) {
            die('DB Execute Error: ' . $e->getMessage());
        }
    }

    /**
     * Inserção dinâmica segura
     */
    public function insert(array $values): int
    {
        $fields = array_keys($values);
        $placeholders = ':' . implode(', :', $fields);

        $query = "INSERT INTO {$this->table} (" . implode(',', $fields) . ") VALUES ({$placeholders})";

        $params = [];
        foreach ($values as $field => $value) {
            $params[":{$field}"] = $value;
        }

        $this->execute($query, $params);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * Seleção com suporte a where, order, limit e bind params
     */

    public function select(
        string $where = '',
        array $params = [],
        string|null $order = '',
        string|null $limit = '',
        string|array $fields = '*'
    ): PDOStatement {
        if (is_array($fields)) {
            $fields = implode(', ', $fields);
        }

        $whereClause = !empty($where) ? "WHERE {$where}" : '';
        $orderClause = !empty($order) ? "ORDER BY {$order}" : '';
        $limitClause = !empty($limit) ? "LIMIT {$limit}" : '';

        $query = "SELECT {$fields} FROM {$this->table} {$whereClause} {$orderClause} {$limitClause}";

        return $this->execute($query, $params);
    }


    /**
     * Atualização dinâmica segura
     */
    public function update(string $where, array $values, array $params = []): bool
    {
        $setParts = [];
        foreach ($values as $field => $value) {
            $setParts[] = "{$field} = :set_{$field}";
        }
        $setClause = implode(', ', $setParts);

        $query = "UPDATE {$this->table} SET {$setClause} WHERE {$where}";

        // Params para SET
        $setParams = [];
        foreach ($values as $field => $value) {
            $setParams[":set_{$field}"] = $value;
        }

        $mergedParams = array_merge($setParams, $params);

        $this->execute($query, $mergedParams);
        return true;
    }

    /**
     * Exclusão segura
     */
    public function delete(string $where, array $params = []): bool
    {
        $query = "DELETE FROM {$this->table} WHERE {$where}";
        $this->execute($query, $params);
        return true;
    }
}
