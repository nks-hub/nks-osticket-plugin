<?php
/**
 * Request kernel: bootstrap guard, routing and error handling.
 *
 * Maps a single flat action string (e.g. "tickets.search") to a handler
 * method, enforces the required permission, and renders the JSON envelope.
 * One public entry point keeps the deploy surface to a single web file.
 */

declare(strict_types=1);

namespace NksOsticket;

require_once __DIR__ . '/Http.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Handlers/TicketsHandler.php';
require_once __DIR__ . '/Handlers/SubticketsHandler.php';
require_once __DIR__ . '/Handlers/AttachmentsHandler.php';

final class Kernel
{
    public const VERSION = '1.4.0';

    /**
     * Route table: action => [permission columns (OR), callable factory].
     * Permission arrays use OR semantics; a broader permission grants access.
     */
    private const ROUTES = [
        'tickets.list'        => ['perms' => ['can_search_tickets', 'can_read_tickets'], 'call' => ['TicketsHandler', 'listTickets']],
        'tickets.search'      => ['perms' => ['can_search_tickets', 'can_read_tickets'], 'call' => ['TicketsHandler', 'search']],
        'tickets.get'         => ['perms' => ['can_read_tickets'],                        'call' => ['TicketsHandler', 'get']],
        'tickets.stats'       => ['perms' => ['can_read_stats', 'can_read_tickets'],      'call' => ['TicketsHandler', 'stats']],
        'tickets.statuses'    => ['perms' => ['can_read_stats', 'can_read_tickets'],      'call' => ['TicketsHandler', 'statuses']],
        'tickets.create'      => ['perms' => ['can_create_tickets'],                      'call' => ['TicketsHandler', 'create']],
        'tickets.update'      => ['perms' => ['can_update_tickets'],                      'call' => ['TicketsHandler', 'update']],
        'tickets.reply'       => ['perms' => ['can_reply_tickets'],                       'call' => ['TicketsHandler', 'reply']],
        'tickets.assign'      => ['perms' => ['can_assign_tickets'],                      'call' => ['TicketsHandler', 'assign']],
        'tickets.delete'      => ['perms' => ['can_delete_tickets'],                      'call' => ['TicketsHandler', 'delete']],
        'subtickets.link'     => ['perms' => ['can_manage_subtickets'],                   'call' => ['SubticketsHandler', 'link']],
        'subtickets.unlink'   => ['perms' => ['can_manage_subtickets'],                   'call' => ['SubticketsHandler', 'unlink']],
        'subtickets.children' => ['perms' => ['can_manage_subtickets', 'can_read_tickets'], 'call' => ['SubticketsHandler', 'children']],
        'subtickets.parent'   => ['perms' => ['can_manage_subtickets', 'can_read_tickets'], 'call' => ['SubticketsHandler', 'parent']],
        'attachments.download' => ['perms' => ['can_read_tickets'],                       'call' => ['AttachmentsHandler', 'download']],
        'meta.ping'           => ['perms' => [],                                          'call' => ['Kernel', 'ping']],
    ];

    public static function run(): void
    {
        try {
            $request = Http::capture();
            $action = $request->action();

            if ($action === '') {
                Http::fail(400, 'Missing "action". Try {"action":"meta.ping"} or see the action list.', [
                    'actions' => array_keys(self::ROUTES),
                ]);
            }

            if (!isset(self::ROUTES[$action])) {
                Http::fail(404, sprintf('Unknown action "%s"', $action), [
                    'actions' => array_keys(self::ROUTES),
                ]);
            }

            $route = self::ROUTES[$action];

            // Auth on every action; ping still requires a valid key but no perms.
            $auth = Auth::require($request);
            if (!empty($route['perms'])) {
                $auth->requireAny(...$route['perms']);
            }

            [$class, $method] = $route['call'];
            $params = $request->params();

            if ($class === 'Kernel') {
                $data = self::{$method}($params, $auth);
            } else {
                $fqcn = __NAMESPACE__ . '\\' . $class;
                $handler = new $fqcn();
                $data = $handler->{$method}($params);
            }

            Http::ok($data);
        } catch (ApiException $e) {
            Http::fail($e->status(), $e->getMessage());
        } catch (\Throwable $e) {
            // Never leak stack traces; log server-side, return a clean 500.
            error_log('[nks-osticket-api] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            Http::fail(500, 'Internal error: ' . $e->getMessage());
        }
    }

    /** Lightweight liveness/auth probe. */
    private static function ping(array $params, Auth $auth): array
    {
        return [
            'pong' => true,
            'version' => self::VERSION,
            'keyId' => $auth->keyId(),
            'osticket' => defined('THIS_VERSION') ? THIS_VERSION : null,
        ];
    }
}

/**
 * Handler-level exception carrying an HTTP status.
 */
final class ApiException extends \Exception
{
    private int $status;

    public function __construct(string $message, int $status = 400)
    {
        parent::__construct($message);
        $this->status = $status;
    }

    public function status(): int
    {
        return $this->status;
    }
}
