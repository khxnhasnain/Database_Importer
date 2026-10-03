<?php

namespace App;

use PDO;
use Exception;

class Database {
    private ?PDO $pdo = null;
    private string $dbType;

    public function __construct(array $config) {
        $this->dbType = $config['type'] ?? 'sqlite';
        if ($this->dbType === 'sqlite') {
            $dbPath = $config['path'] ?? __DIR__ . '/../data/database.sqlite';
            // Ensure directory exists
            $dir = dirname($dbPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $this->pdo = new PDO("sqlite:$dbPath");
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $this->pdo->exec("PRAGMA journal_mode = WAL;");
            $this->pdo->exec("PRAGMA synchronous = NORMAL;");
            $this->pdo->exec("PRAGMA temp_store = MEMORY;");
            $this->pdo->exec("PRAGMA cache_size = -64000;");
        } elseif ($this->dbType === 'mysql') {
            $host = $config['host'] ?? '127.0.0.1';
            $port = $config['port'] ?? 3306;
            $dbname = $config['dbname'] ?? '';
            $username = $config['username'] ?? '';
            $password = $config['password'] ?? '';

            // First connect without dbname to create the database if not exists
            $dsn = "mysql:host=$host;port=$port;charset=utf8mb4";
            $tempPdo = new PDO($dsn, $username, $password);
            $tempPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $tempPdo->exec("CREATE DATABASE IF NOT EXISTS `" . str_replace("`", "``", $dbname) . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $tempPdo = null;

            // Connect to actual database
            $this->pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4", $username, $password);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $this->pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
            $this->pdo->exec("SET UNIQUE_CHECKS = 0;");
            $this->pdo->exec("SET SQL_MODE = '';");
        } else {
            throw new Exception("Unsupported database type: " . $this->dbType);
        }
    }

    public function getPdo(): PDO {
        return $this->pdo;
    }

    public function beginTransaction(): bool {
        return $this->pdo->beginTransaction();
    }

    public function commit(): bool {
        return $this->pdo->commit();
    }

    public function rollBack(): bool {
        return $this->pdo->rollBack();
    }

    /**
     * Sanitizes and deduplicates column names to ensure compatibility with SQLite/MySQL.
     * Prevents name collisions (including with the internal primary key).
     */
    public static function sanitizeColumnNames(array $columns): array {
        $sanitized = [];
        $seen = ['import_id' => true]; // Reserve internal auto-increment primary key
        foreach ($columns as $col) {
            $sanitizedCol = preg_replace('/[^a-zA-Z0-9_]/', '', $col);
            if (empty($sanitizedCol)) {
                continue;
            }
            $original = $sanitizedCol;
            $counter = 1;
            while (isset($seen[strtolower($sanitizedCol)])) {
                $sanitizedCol = $original . '_' . $counter;
                $counter++;
            }
            $seen[strtolower($sanitizedCol)] = true;
            $sanitized[$col] = $sanitizedCol;
        }
        return $sanitized;
    }

    /**
     * Dynamically creates a table based on the analyzed columns.
     */
    public function createTable(string $tableName, array $columns): void {
        // Sanitize table name
        $sanitizedTableName = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
        if (empty($sanitizedTableName)) {
            throw new Exception("Invalid table name: $tableName");
        }

        $sql = "CREATE TABLE IF NOT EXISTS `$sanitizedTableName` (\n";
        $sql .= "  `import_id` " . ($this->dbType === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY') . ",\n";

        $sanitizedCols = self::sanitizeColumnNames(array_keys($columns));
        $colDefs = [];
        foreach ($columns as $colName => $type) {
            if (!isset($sanitizedCols[$colName])) {
                continue;
            }
            $dbColName = $sanitizedCols[$colName];
            switch (strtolower($type)) {
                case 'integer':
                case 'int':
                    $dbTypeStr = $this->dbType === 'sqlite' ? 'INTEGER' : 'INT';
                    break;
                case 'float':
                case 'double':
                case 'real':
                    $dbTypeStr = $this->dbType === 'sqlite' ? 'REAL' : 'DOUBLE';
                    break;
                case 'date':
                    $dbTypeStr = 'DATE';
                    break;
                case 'datetime':
                    $dbTypeStr = 'DATETIME';
                    break;
                case 'text':
                case 'longtext':
                case 'mediumtext':
                    $dbTypeStr = $this->dbType === 'sqlite' ? 'TEXT' : 'LONGTEXT';
                    break;
                default:
                    $dbTypeStr = $this->dbType === 'sqlite' ? 'TEXT' : 'VARCHAR(255)';
            }
            $colDefs[] = "  `$dbColName` $dbTypeStr DEFAULT NULL";
        }

        $sql .= implode(",\n", $colDefs);
        $sql .= "\n)";

        if ($this->dbType === 'mysql') {
            $sql .= " ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        }

        $this->pdo->exec("DROP TABLE IF EXISTS `$sanitizedTableName`");
        $this->pdo->exec($sql);
    }

    /**
     * Prepares a batch insert statement.
     */
    public function prepareInsert(string $tableName, array $columns): \PDOStatement {
        $sanitizedTableName = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
        
        $sanitizedCols = self::sanitizeColumnNames($columns);
        $colNames = [];
        $placeholders = [];
        foreach ($columns as $col) {
            if (isset($sanitizedCols[$col])) {
                $colNames[] = "`" . $sanitizedCols[$col] . "`";
                $placeholders[] = "?";
            }
        }

        $sql = "INSERT INTO `$sanitizedTableName` (" . implode(', ', $colNames) . ") VALUES (" . implode(', ', $placeholders) . ")";
        return $this->pdo->prepare($sql);
    }

    /**
     * Executes a high-performance multi-row batch insert.
     */
    public function batchInsert(string $tableName, array $columns, array $rows): int {
        if (empty($rows)) return 0;

        $sanitizedTableName = preg_replace('/[^a-zA-Z0-9_]/', '', $tableName);
        $sanitizedCols = self::sanitizeColumnNames($columns);
        
        $colNames = [];
        foreach ($columns as $col) {
            if (isset($sanitizedCols[$col])) {
                $colNames[] = "`" . $sanitizedCols[$col] . "`";
            }
        }
        $colCount = count($colNames);
        if ($colCount === 0) return 0;

        $rowPlaceholders = "(" . implode(', ', array_fill(0, $colCount, '?')) . ")";

        $flattenedValues = [];
        $valuesSql = [];

        foreach ($rows as $rowValues) {
            $valuesSql[] = $rowPlaceholders;
            foreach ($rowValues as $val) {
                $flattenedValues[] = $val;
            }
        }

        $sql = "INSERT INTO `$sanitizedTableName` (" . implode(', ', $colNames) . ") VALUES " . implode(', ', $valuesSql);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($flattenedValues);
        $rowCount = $stmt->rowCount();
        return $rowCount > 0 ? $rowCount : count($rows);
    }
}
