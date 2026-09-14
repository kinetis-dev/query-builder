<p align="center">
  <img src="logo.svg" alt="Kinetis" width="420">
</p>

<p align="center">
  <strong>kinetis/query-builder</strong>
  <br>
  <strong>A thin, parameterized SQL query builder for MySQL and PostgreSQL</strong>
</p>

<p align="center">
  <a href="https://packagist.org/packages/kinetis/query-builder"><img src="https://img.shields.io/packagist/v/kinetis/query-builder?label=version" alt="Packagist Version"></a>
  <a href="https://packagist.org/packages/kinetis/query-builder"><img src="https://img.shields.io/packagist/dt/kinetis/query-builder" alt="Packagist Downloads"></a>
  <a href="https://packagist.org/packages/kinetis/query-builder"><img src="https://img.shields.io/packagist/php-v/kinetis/query-builder" alt="PHP Version"></a>
  <a href="https://packagist.org/packages/kinetis/query-builder"><img src="https://img.shields.io/packagist/l/kinetis/query-builder" alt="License"></a>
  <a href="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml"><img src="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
</p>

---

Part of [Kinetis](https://kinetis.dev/), a non-blocking PHP framework for
API-first applications, developed in the
[kinetis-dev/kinetis](https://github.com/kinetis-dev/kinetis) monorepo.
Usable standalone: in production it depends only on
[`kinetis/persistence`](https://github.com/kinetis-dev/persistence).

Parameterized selects, joins, subqueries, set operations, CTEs, row
locks, inserts, upserts, updates and deletes over the SQL shared by
MySQL 8.4, MariaDB 11.4 and PostgreSQL 16, run through
`kinetis/persistence`'s drivers, with its own row-to-DTO mapping and
pagination envelopes. Not an ORM: no relationships, no change-tracking,
no `save()`-on-a-model.

```php
use Kinetis\Persistence\ConnectionDefinition;
use Kinetis\Persistence\SqlConnectionFactory;
use Kinetis\QueryBuilder\Query;

// Build the client once and reuse it across units of work.
$db = SqlConnectionFactory::create(new ConnectionDefinition(
    dialect: 'mysql',
    host: 'db.internal',
    database: 'shop',
    user: 'shop',
    password: $password,
));

$orders = new Query($db)
    ->table('orders')
    ->where('customer_id', '=', $customerId)
    ->where('status', '!=', 'cancelled')
    ->orderBy('created_at', 'desc')
    ->limit(20)
    ->get(OrderRow::class); // OrderRow is your own row DTO
```

The connection's type selects the SQL dialect, and a `Query` built on a
transaction runs inside it. What the host owns — client lifetime and a
`TransactionGuard` per unit of work — is described in
[`kinetis/persistence`](https://github.com/kinetis-dev/persistence).

## With Kinetis

```sh
composer require kinetis/query-builder kinetis/database-bridge
```

Install both: [`kinetis/database-bridge`](https://github.com/kinetis-dev/database-bridge)
binds the connection from `DB_*` configuration but does not install the
query builder. An application then injects `MysqlLink` or `PostgresLink`
and constructs `new Query($link)` per statement with no wiring of its
own.

## Installation

```sh
composer require kinetis/query-builder
```

Requires PHP 8.4+ and [`kinetis/persistence`](https://github.com/kinetis-dev/persistence),
plus the PHP extension for the driver you use. Full documentation:
[kinetis.dev/docs/query-builder.html](https://kinetis.dev/docs/query-builder.html).

## License

MIT — see [LICENSE](LICENSE).
