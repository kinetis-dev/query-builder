<p align="center">
  <img src="logo.svg" alt="Kinetis" width="420">
</p>

<p align="center">
  <strong>kinetis/query-builder</strong>
  <br>
  <strong>A thin, parameterized SQL query builder for Kinetis</strong>
</p>

<p align="center">
  <a href="https://packagist.org/packages/kinetis/query-builder"><img src="https://img.shields.io/packagist/v/kinetis/query-builder?label=version" alt="Packagist Version"></a>
  <a href="https://packagist.org/packages/kinetis/query-builder"><img src="https://img.shields.io/packagist/dt/kinetis/query-builder" alt="Packagist Downloads"></a>
  <a href="https://packagist.org/packages/kinetis/query-builder"><img src="https://img.shields.io/packagist/php-v/kinetis/query-builder" alt="PHP Version"></a>
  <a href="https://packagist.org/packages/kinetis/query-builder"><img src="https://img.shields.io/packagist/l/kinetis/query-builder" alt="License"></a>
  <a href="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml"><img src="https://github.com/kinetis-dev/kinetis/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
</p>

---

MySQL and Postgres via `amphp/mysql`/`amphp/postgres`, with row-to-DTO
mapping via `Kinetis\Validation\Hydrator` — the same mechanism that
hydrates a `#[Body]` request DTO. Not an ORM: no relationships, no
migrations, no change-tracking, no `save()`-on-a-model.

```php
use Kinetis\QueryBuilder\Query;

$orders = new Query($db)
    ->table('orders')
    ->where('customer_id', '=', $customerId)
    ->where('status', '!=', 'cancelled')
    ->orderBy('created_at', 'desc')
    ->limit(20)
    ->get(OrderRow::class);
```

`Query` works with either backend through the same shared `Amp\Sql\SqlLink`
family both drivers implement, auto-detected from the concrete connection
you pass in — and composes directly inside
`Kinetis\Persistence\TransactionGuard::transaction()`.

## Installation

```sh
composer require kinetis/query-builder
```

Requires PHP 8.4+ and `kinetis/framework`. Full documentation:
[docs.kinetis.dev/query-builder.html](https://docs.kinetis.dev/query-builder.html).

## License

MIT — see [LICENSE](../../LICENSE).
