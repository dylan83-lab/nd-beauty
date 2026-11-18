<?php
declare(strict_types=1);

/** Pembungkus PDO: MySQL/MariaDB (produksi di cPanel) dan SQLite (uji lokal). */
final class DB
{
    public static ?PDO $pdo = null;
    public static string $driver = 'mysql';
    private static int $depth = 0;

    public static function connect(array $c): void
    {
        self::$driver = $c['driver'] ?? 'mysql';
        if (self::$driver === 'sqlite') {
            self::$pdo = new PDO('sqlite:' . $c['sqlite_path']);
            self::$pdo->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=8000;');
        } else {
            $dsn = 'mysql:host=' . ($c['host'] ?? 'localhost') . ';port=' . ($c['port'] ?? 3306)
                . ';dbname=' . $c['name'] . ';charset=utf8mb4';
            self::$pdo = new PDO($dsn, $c['user'], $c['pass']);
        }
        self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        if (self::$driver === 'mysql') {
            self::$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            self::$pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);
        }
    }

    public static function q(string $sql, array $p = []): PDOStatement
    {
        $st = self::$pdo->prepare($sql);
        $st->execute(array_values($p));
        return $st;
    }

    public static function all(string $sql, array $p = []): array { return self::q($sql, $p)->fetchAll(); }

    public static function one(string $sql, array $p = []): ?array
    {
        $r = self::q($sql, $p)->fetch();
        return $r === false ? null : $r;
    }

    public static function val(string $sql, array $p = [])
    {
        $r = self::q($sql, $p)->fetch(PDO::FETCH_NUM);
        return $r === false ? null : $r[0];
    }

    public static function exec(string $sql, array $p = []): int { return self::q($sql, $p)->rowCount(); }

    public static function insert(string $table, array $row): int
    {
        $cols = array_keys($row);
        $sql = 'INSERT INTO ' . $table . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
        self::q($sql, array_values($row));
        return (int)self::$pdo->lastInsertId();
    }

    /** Insert yang boleh gagal karena kunci unik (idempoten). Mengembalikan id baru atau 0 jika sudah ada. */
    public static function tryInsert(string $table, array $row): int
    {
        try {
            return self::insert($table, $row);
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000' || str_contains($e->getMessage(), 'UNIQUE') || str_contains($e->getMessage(), 'Duplicate')) return 0;
            throw $e;
        }
    }

    public static function update(string $table, array $set, string $where, array $wp = []): int
    {
        $sql = 'UPDATE ' . $table . ' SET ' . implode(',', array_map(fn($c) => $c . '=?', array_keys($set))) . ' WHERE ' . $where;
        return self::exec($sql, array_merge(array_values($set), $wp));
    }

    /** Transaksi atomik. SQLite: BEGIN IMMEDIATE (menyerialkan penulis). MySQL: kunci baris lewat lockDay()/forUpdate(). */
    public static function tx(callable $fn)
    {
        if (self::$depth > 0) {
            self::$depth++;
            try { return $fn(); } finally { self::$depth--; }
        }
        self::$pdo->exec(self::$driver === 'sqlite' ? 'BEGIN IMMEDIATE' : 'START TRANSACTION');
        self::$depth = 1;
        try {
            $r = $fn();
            self::$pdo->exec('COMMIT');
            return $r;
        } catch (Throwable $e) {
            try { self::$pdo->exec('ROLLBACK'); } catch (Throwable $x) {}
            throw $e;
        } finally {
            self::$depth = 0;
        }
    }

    public static function forUpdate(): string { return self::$driver === 'mysql' ? ' FOR UPDATE' : ''; }

    /** Kunci per-hari agar dua pemesanan bersamaan untuk tanggal yang sama diproses berurutan. */
    public static function lockDay(string $date): void
    {
        if (self::$driver !== 'mysql') return;
        self::q('INSERT IGNORE INTO day_locks (d) VALUES (?)', [$date]);
        self::q('SELECT d FROM day_locks WHERE d=? FOR UPDATE', [$date])->fetchAll();
    }
}
