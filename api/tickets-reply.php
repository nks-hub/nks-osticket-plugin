<?php
/**
 * API File: Ticket Reply Endpoint
 *
 * Provides POST endpoint for posting a staff reply to a ticket via API.
 * Unlike tickets-update.php's "note" parameter (internal, staff-only),
 * this posts a customer-facing reply that gets emailed to the ticket's user.
 *
 * This file should be deployed to: /api/tickets-reply.php
 * URL pattern: /api/tickets-reply.php/{ticket_number}.json
 */

declare(strict_types=1);

// Load osTicket bootstrap FIRST (must be relative to THIS file's location)
require_once '../main.inc.php';

// Then load ApiBootstrap helper
require_once INCLUDE_DIR . 'plugins/api-endpoints/lib/ApiBootstrap.php';

$bootstrap = ApiBootstrap::initialize();

// Parse ticket number and format from path info
$params = $bootstrap->parsePathInfo(
    '#^/(?P<number>[^/.]+)\.(?P<format>json|xml)$#',
    'Invalid URL format. Expected: /api/tickets-reply.php/{ticket_number}.json'
);

// Only accept POST method
$bootstrap->requireMethod('POST');

// Parse JSON body: {response: string, format?: string}
$data = $bootstrap->parseJsonBody(required: true);

// Execute with standardized error handling
$bootstrap->execute(function() use ($bootstrap, $params, $data) {
    $controller = $bootstrap->getController();
    return $controller->replyToTicket($params['number'], $data);
});
