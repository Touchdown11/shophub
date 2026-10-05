<?php
/**
 * ShopHub — database layer
 * A thin PDO wrapper that works with MySQL (XAMPP) and SQLite (demo mode).
 * Every query in this app goes through prepared statements.
 */
declare(strict_types=1);

final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $driver = cfg('db.driver', 'mysql');
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            if ($driver === 'sqlite') {
                $path = cfg('db.sqlite');
                $dir  = dirname((string)$path);
                if (!is_dir($dir)) {
                    mkdir($dir, 0775, true);
                }
                self::$pdo = new PDO('sqlite:' . $path, null, null, $options);
                self::$pdo->exec('PRAGMA foreign_keys = ON');
                self::$pdo->exec('PRAGMA journal_mode = WAL');
            } else {
                $dsn = sprintf(
                    'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                    cfg('db.host'),
                    cfg('db.port'),
                    cfg('db.name'),
                    cfg('db.charset', 'utf8mb4')
                );
                self::$pdo = new PDO($dsn, (string)cfg('db.user'), (string)cfg('db.pass'), $options);
            }
        } catch (PDOException $e) {
            http_response_code(500);
            $hint = $driver === 'sqlite'
                ? 'Run <code>install.php</code> to create the demo database.'
                : 'Start MySQL in XAMPP, create a database named <code>shophub</code>, '
                  . 'then open <code>install.php</code> once. Check includes/config.php for credentials.';
            echo '<div style="font:16px/1.6 system-ui;background:#0b0b0d;color:#eee;padding:2rem;border-radius:12px;margin:2rem auto;max-width:720px">'
               . '<h2 style="color:#ffc400;margin:0 0 .5rem">Database connection failed</h2>'
               . '<p>' . htmlspecialchars($e->getMessage(), ENT_QUOTES) . '</p><p>' . $hint . '</p></div>';
            exit;
        }

        return self::$pdo;
    }

    /** Run a statement with bound params, returns the PDOStatement. */
    public static function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $name = is_int($key) ? $key + 1 : ':' . ltrim((string)$key, ':');
            $type = match (true) {
                is_int($value)  => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_INT,
                is_null($value) => PDO::PARAM_NULL,
                default         => PDO::PARAM_STR,
            };
            $stmt->bindValue($name, is_bool($value) ? (int)$value : $value, $type);
        }
        $stmt->execute();
        return $stmt;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function row(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $value = self::run($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql  = 'INSERT INTO ' . $table . ' (' . implode(', ', $cols) . ') VALUES (:'
              . implode(', :', $cols) . ')';
        self::run($sql, $data);
        return (int)self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $sets = [];
        foreach (array_keys($data) as $col) {
            $sets[] = $col . ' = :set_' . $col;
        }
        $params = [];
        foreach ($data as $k => $v) {
            $params['set_' . $k] = $v;
        }
        $sql = 'UPDATE ' . $table . ' SET ' . implode(', ', $sets) . ' WHERE ' . $where;
        return self::run($sql, array_merge($params, $whereParams))->rowCount();
    }

    public static function driver(): string
    {
        return (string)self::pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
    }
}
