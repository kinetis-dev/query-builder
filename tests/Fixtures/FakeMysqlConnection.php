<?php

declare(strict_types=1);

namespace Kinetis\QueryBuilder\Tests\Fixtures;

use Amp\Mysql\MysqlConfig;
use Amp\Mysql\MysqlConnection;
use Amp\Mysql\MysqlResult;
use Amp\Mysql\MysqlStatement;
use Amp\Mysql\MysqlTransaction;
use Amp\Sql\SqlTransactionIsolation;
use LogicException;

/**
 * Unlike FakeMysqlLink, this implements MysqlConnection specifically so
 * MySqlDialect::literalFor() can reach a real getConfig() — the whole
 * point of this fixture is letting a test control the reported charset.
 */
final class FakeMysqlConnection implements MysqlConnection
{
    private readonly MysqlConfig $config;

    public function __construct(string $charset = 'utf8mb4')
    {
        $this->config = MysqlConfig::fromString("host=localhost charset={$charset}");
    }

    public function getConfig(): MysqlConfig
    {
        return $this->config;
    }

    public function getTransactionIsolation(): SqlTransactionIsolation
    {
        throw new LogicException('FakeMysqlConnection does not support transactions.');
    }

    public function setTransactionIsolation(SqlTransactionIsolation $isolation): void
    {
        throw new LogicException('FakeMysqlConnection does not support transactions.');
    }

    public function query(string $sql): MysqlResult
    {
        throw new LogicException('FakeMysqlConnection does not execute queries.');
    }

    public function prepare(string $sql): MysqlStatement
    {
        throw new LogicException('FakeMysqlConnection does not execute queries.');
    }

    public function execute(string $sql, array $params = []): MysqlResult
    {
        throw new LogicException('FakeMysqlConnection does not execute queries.');
    }

    public function beginTransaction(): MysqlTransaction
    {
        throw new LogicException('FakeMysqlConnection does not support transactions.');
    }

    public function close(): void
    {
    }

    public function isClosed(): bool
    {
        return false;
    }

    public function onClose(\Closure $onClose): void
    {
    }

    public function getLastUsedAt(): int
    {
        return 0;
    }
}
