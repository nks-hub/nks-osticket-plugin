<?php
/**
 * API key authentication and per-action authorization.
 *
 * Reuses osTicket's own `ost_api_key` table (key + bound IP + granular
 * `can_*` permission columns) so keys are managed the same way as core API
 * keys, but the permission model is our own.
 */

declare(strict_types=1);

namespace NksOsticket;

final class Auth
{
    /**
     * Permission columns this API adds to `ost_api_key`. Stock osTicket ships
     * only `can_create_tickets` and `can_exec_cron`, so the rest are created
     * on first use (default 0 = not granted).
     */
    public const PERMISSION_COLUMNS = [
        'can_read_tickets', 'can_search_tickets', 'can_create_tickets',
        'can_update_tickets', 'can_reply_tickets', 'can_delete_tickets',
        'can_read_stats', 'can_manage_subtickets', 'can_assign_tickets',
    ];

    /** @var array<string,mixed> Raw api key row. */
    private array $row;

    private function __construct(array $row)
    {
        $this->row = $row;
    }

    /**
     * Resolve and validate the caller from the X-Api-Key header + client IP.
     * Terminates with 401 on failure.
     */
    public static function require(Http $request): self
    {
        $key = $request->header('X-Api-Key');
        if ($key === null || $key === '') {
            Http::fail(401, 'Missing X-Api-Key header');
        }

        // Basic shape guard before touching the DB.
        if (!preg_match('/^[A-Za-z0-9]{16,255}$/', $key)) {
            Http::fail(401, 'Malformed API key');
        }

        $ip = Http::clientIp();
        $table = defined('API_KEY_TABLE') ? API_KEY_TABLE : 'ost_api_key';

        $sql = sprintf(
            "SELECT * FROM %s WHERE apikey = '%s' AND isactive = 1 LIMIT 1",
            $table,
            \db_real_escape($key)
        );
        $res = \db_query($sql);
        $row = $res ? \db_fetch_array($res) : null;

        if (!$row) {
            Http::fail(401, 'Invalid or inactive API key');
        }

        // SELECT * returns every column, so a missing key means the column
        // does not exist yet; adding it costs nothing once the schema is done.
        $missing = array_diff(self::PERMISSION_COLUMNS, array_keys($row));
        if ($missing) {
            self::addPermissionColumns($table, $missing);
        }

        // IP binding: osTicket keys are pinned to a source IP. Surface the seen
        // IP on mismatch so operators can configure the key without guessing.
        if (!self::ipAllowed((string)($row['ipaddr'] ?? ''), $ip)) {
            Http::fail(401, 'API key not authorized for this source IP', ['client_ip' => $ip]);
        }

        return new self($row);
    }

    /**
     * Whether the key's bound address admits the caller. Empty and "*" admit
     * any address (wildcard keys); "*" inside an address ("172.16.4.*"),
     * IPv4 CIDR ("172.16.0.0/16") and "regex:/.../" admit a range.
     */
    public static function ipAllowed(string $allowed, string $ip): bool
    {
        $allowed = trim($allowed);
        if ($allowed === '' || $allowed === '*' || $allowed === $ip) {
            return true;
        }
        if (strncmp($allowed, 'regex:', 6) === 0) {
            return @preg_match(substr($allowed, 6), $ip) === 1;
        }
        if (strpos($allowed, '*') !== false) {
            $re = '/^' . str_replace('\*', '[0-9A-Fa-f:.]*', preg_quote($allowed, '/')) . '$/';
            return (bool)preg_match($re, $ip);
        }
        if (strpos($allowed, '/') !== false) {
            [$net, $bits] = explode('/', $allowed, 2);
            $n = ip2long($net);
            $i = ip2long($ip);
            $b = (int)$bits;
            if ($n === false || $i === false || $b < 0 || $b > 32) {
                return false;
            }
            $mask = $b === 0 ? 0 : ((-1 << (32 - $b)) & 0xFFFFFFFF);
            return ($n & $mask) === ($i & $mask);
        }
        return false;
    }

    public function keyId(): int
    {
        return (int)($this->row['id'] ?? 0);
    }

    /** Whether the key has a given `can_*` permission column set. */
    public function can(string $permissionColumn): bool
    {
        return !empty($this->row[$permissionColumn]);
    }

    /**
     * Require one of the given permission columns (OR semantics).
     * Terminates with 403 if none are granted.
     */
    public function requireAny(string ...$permissionColumns): void
    {
        foreach ($permissionColumns as $col) {
            if ($this->can($col)) {
                return;
            }
        }
        Http::fail(403, 'API key lacks permission: ' . implode(' | ', $permissionColumns), [
            'hint' => sprintf(
                'Grant it in the database: UPDATE %s SET %s = 1 WHERE id = %d',
                defined('API_KEY_TABLE') ? API_KEY_TABLE : 'ost_api_key',
                $permissionColumns[0],
                $this->keyId()
            ),
        ]);
    }

    /**
     * Add the permission columns stock osTicket lacks. Fails with the exact
     * SQL when the database user may not alter the table.
     *
     * @param string[] $columns
     */
    private static function addPermissionColumns(string $table, array $columns): void
    {
        foreach ($columns as $col) {
            $sql = sprintf(
                'ALTER TABLE %s ADD COLUMN `%s` tinyint(1) unsigned NOT NULL DEFAULT 0',
                $table,
                $col
            );
            if (!\db_query($sql)) {
                Http::fail(500, 'Missing permission column ' . $col . ' and the database user cannot add it', [
                    'sql' => $sql,
                ]);
            }
        }
    }
}
