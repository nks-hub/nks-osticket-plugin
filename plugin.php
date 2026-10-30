<?php
/**
 * NKS osTicket API — plugin manifest.
 *
 * The JSON API is served by the standalone web entry (deployed to
 * osTicket's api/ directory); this manifest registers the project as a proper
 * osTicket plugin so it is visible and manageable in the admin panel.
 */

return array(
    'id'          => 'nks:osticket-api',
    'version'     => '1.4.0',
    'name'        => 'NKS osTicket API',
    'author'      => 'NKS',
    'description' => 'Clean JSON API over osTicket — tickets (list/search/get/create/update/reply/delete), stats, statuses, subtickets and attachments — designed for integrations and AI agents.',
    'url'         => 'https://github.com/nks-hub/nks-osticket-plugin',
    'plugin'      => 'class.NksOsTicketApiPlugin.php:NksOsTicketApiPlugin',
);
