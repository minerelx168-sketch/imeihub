<?php
declare(strict_types=1);

if (!function_exists('db')) {
/**
 * Shared PDO handle for the current process.
 *
 * Fork-safety: a child process must never reuse the parent's MySQL
 * socket — concurrent use corrupts the wire protocol and a child tearing
 * down the inherited handle kills the parent's session ("packets out of
 * order" / "server has gone away"). The handle therefore remembers the
 * PID it was opened under and reconnects automatically after a fork.
 * Forking code should still call db_disconnect() right before fork() so
 * there is no live socket to inherit in the first place.
 */
function db(): PDO
{
    return _db_holder()->connection();
}
}

if (!function_exists('db_disconnect')) {
/**
 * Close the cached connection in the CURRENT process. Call this just
 * before pcntl_fork() so children start with a clean slate.
 */
function db_disconnect(): void
{
    _db_holder()->disconnect();
}
}

if (!function_exists('_db_holder')) {
function _db_holder(): object
{
    static $holder = null;
    if ($holder === null) {
        $holder = new class {
            private ?PDO $pdo = null;
            private ?int $pid = null;

            public function connection(): PDO
            {
                $mypid = function_exists('getmypid') ? (getmypid() ?: 0) : 0;
                if ($this->pdo instanceof PDO && $this->pid === $mypid) {
                    return $this->pdo;
                }

                $cfg = require __DIR__ . '/config.php';
                $dsn = sprintf(
                    'mysql:host=%s;dbname=%s;charset=utf8mb4',
                    $cfg['db']['host'],
                    $cfg['db']['name']
                );

                $this->pdo = new PDO($dsn, $cfg['db']['user'], $cfg['db']['pass'], [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);
                $this->pid = $mypid;
                return $this->pdo;
            }

            public function disconnect(): void
            {
                $this->pdo = null;
                $this->pid = null;
            }
        };
    }
    return $holder;
}
}
