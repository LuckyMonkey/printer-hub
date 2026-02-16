<?php
declare(strict_types=1);

namespace PrinterHub;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private ?PDO $pdo = null;

    public function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = getenv('DB_PORT') ?: '5432';
        $name = getenv('DB_NAME') ?: 'printerhub';
        $user = getenv('DB_USER') ?: 'printerhub';
        $password = getenv('DB_PASSWORD') ?: 'printerhub';

        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $name);

        try {
            $this->pdo = new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('Database connection failed: ' . $e->getMessage());
        }

        return $this->pdo;
    }
}
