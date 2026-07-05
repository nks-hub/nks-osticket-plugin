<?php
/**
 * Ticket Service
 *
 * Centralizes ticket CRUD operations and business logic.
 * Extracted from ExtendedTicketApiController to follow Single Responsibility Principle.
 *
 * Handles:
 * - Get single ticket with full details
 * - Delete ticket
 * - Get ticket statistics
 * - Search tickets with filters
 */

declare(strict_types=1);

class TicketService
{
    /**
     * Get ticket by number or ID with full details including thread/messages
     *
     * @param string|int $ticketIdentifier Ticket number (e.g. ABC-123-456) or ID
     * @return array Ticket data with all messages
     * @throws Exception if ticket not found (404)
     */
    public function getTicket(string|int $ticketIdentifier): array
    {
        // Load ticket - try by number first (user provides ticket NUMBER like "781258")
        $ticket = Ticket::lookupByNumber((string)$ticketIdentifier);

        // If not found by number, try by ID as fallback
        if (!$ticket) {
            $ticket = Ticket::lookup($ticketIdentifier);
        }

        if (!$ticket) {
            throw new Exception('Ticket not found', 404);
        }

        return $this->buildTicketResponse($ticket);
    }

    /**
     * Build full ticket response array
     */
    private function buildTicketResponse(Ticket $ticket): array
    {
        $response = [
            'id' => $ticket->getId(),
            'number' => $ticket->getNumber(),
            'subject' => $ticket->getSubject(),
            'statusId' => $ticket->getStatusId(),
            'status' => (string)$ticket->getStatus(),
            'priorityId' => $ticket->getPriorityId(),
            'priority' => (string)$ticket->getPriority(),
            'departmentId' => $ticket->getDeptId(),
            'department' => $ticket->getDept()?->getName(),
            'topicId' => $ticket->getTopicId(),
            'topic' => $ticket->getTopic()?->getName(),
            'userId' => $ticket->getUserId(),
            'user' => [
                'name' => $ticket->getName(),
                'email' => $ticket->getEmail()
            ],
            'staffId' => $ticket->getStaffId(),
            'staff' => $ticket->getStaff()?->getName(),
            'teamId' => $ticket->getTeamId(),
            'team' => $ticket->getTeam()?->getName(),
            'slaId' => $ticket->getSLAId(),
            'sla' => $ticket->getSLA()?->getName(),
            'created' => $ticket->getCreateDate(),
            'updated' => $ticket->getUpdateDate(),
            'duedate' => $ticket->getDueDate(),
            'closed' => $ticket->isClosed() ? $ticket->getCloseDate() : null,
            'isOverdue' => $ticket->isOverdue(),
            'isAnswered' => $ticket->isAnswered(),
            'source' => $ticket->getSource(),
            'ip' => $ticket->getIP(),
            'children' => $this->getChildTicketIds($ticket),
            'thread' => $this->getThreadEntries($ticket)
        ];

        return $response;
    }

    /**
     * Get child ticket IDs for a parent ticket
     */
    private function getChildTicketIds(Ticket $ticket): array
    {
        $children = [];
        $ticketId = $ticket->getId();

        // Only query database if we're in production environment
        if (defined('TICKET_TABLE')) {
            $sql = sprintf(
                "SELECT ticket_id FROM %s WHERE ticket_pid = %d",
                TICKET_TABLE,
                (int)$ticketId
            );
            $result = db_query($sql);
            if ($result) {
                while ($row = db_fetch_array($result)) {
                    $children[] = (int)$row['ticket_id'];
                }
            }
        } else {
            // In test environment, get children from ticket object if method exists
            if (method_exists($ticket, 'getChildren')) {
                foreach ($ticket->getChildren() as $childId) {
                    $children[] = (int)$childId;
                }
            }
        }

        return $children;
    }

    /**
     * Get thread entries for a ticket
     */
    private function getThreadEntries(Ticket $ticket): array
    {
        $entries = [];
        $thread = $ticket->getThread();

        if (!$thread) {
            return $entries;
        }

        foreach ($thread->getEntries() as $entry) {
            $threadEntry = [
                'id' => $entry->getId(),
                'type' => $entry->getType(),
                'poster' => $entry->getPoster(),
                'timestamp' => $entry->getCreateDate(),
                'body' => $entry->getBody()
            ];

            // Add staff info if it's an internal note or response
            if ($entry->getStaffId()) {
                $threadEntry['staffId'] = $entry->getStaffId();
                $threadEntry['staff'] = $entry->getStaff()?->getName();
            }

            // Add user info if it's a user message
            if ($entry->getUserId()) {
                $threadEntry['userId'] = $entry->getUserId();
            }

            // Add attachment metadata if thread entry has attachments
            if (method_exists($entry, 'getAttachments')) {
                $attachments = $entry->getAttachments();
                if ($attachments) {
                    $attachmentMeta = [];
                    // ThreadEntry->attachments is an ORM InstrumentedList,
                    // not GenericAttachments — iterate directly and filter
                    foreach ($attachments as $attachment) {
                        $file = $attachment->getFile();
                        if ($file) {
                            $attachmentMeta[] = [
                                'id' => (int)$attachment->getId(),
                                'file_id' => (int)$file->getId(),
                                'filename' => $file->getName(),
                                'size' => (int)$file->getSize(),
                                'mime_type' => $file->getMimeType(),
                                // Attachment model has no isInline() — use ORM property
                                'inline' => (bool)$attachment->inline,
                            ];
                        }
                    }
                    if (!empty($attachmentMeta)) {
                        $threadEntry['attachments'] = $attachmentMeta;
                        $threadEntry['attachment_count'] = count($attachmentMeta);
                    }
                }
            }

            $entries[] = $threadEntry;
        }

        return $entries;
    }

    /**
     * Delete a ticket and all associated data
     *
     * @param string|int $ticketIdentifier Ticket number or internal ticket ID
     * @return string Deleted ticket number
     * @throws Exception 404 if ticket not found, 500 if deletion fails
     */
    public function deleteTicket(string|int $ticketIdentifier): string
    {
        // Lookup ticket by number first
        $ticket = Ticket::lookupByNumber((string)$ticketIdentifier);

        // Fallback to ID lookup
        if (!$ticket) {
            $ticket = Ticket::lookup($ticketIdentifier);
        }

        if (!$ticket) {
            throw new Exception('Ticket not found', 404);
        }

        // Store ticket data BEFORE deletion (object will be destroyed)
        $ticketNumberToReturn = $ticket->getNumber();

        // Delete ticket using osTicket's delete() method
        $ticket->delete();

        return $ticketNumberToReturn;
    }

    /**
     * Post a staff reply, visible to the ticket's user and sent by email.
     *
     * @param string|int $ticketIdentifier Ticket number or internal ticket ID
     * @param string $response Reply body
     * @param string $format Message format: markdown|html|text
     * @return array Posted reply summary
     * @throws Exception 404 if ticket not found, 400 if posting fails
     */
    public function replyToTicket(string|int $ticketIdentifier, string $response, string $format = 'markdown'): array
    {
        $ticket = Ticket::lookupByNumber((string)$ticketIdentifier);
        if (!$ticket) {
            $ticket = Ticket::lookup($ticketIdentifier);
        }
        if (!$ticket) {
            throw new Exception('Ticket not found', 404);
        }

        // Bridge for Markdown Support Plugin (mirrors postNote() handling above)
        $_POST['format'] = $format;
        $_POST['note'] = $response;

        // Post as the account owner (single-operator instance) so replies show
        // a real staff name to the customer instead of a generic "API" sender.
        $errors = [];
        $entry = $ticket->postReply([
            'response' => $response,
            'staffId'  => 1,
        ], $errors, false);

        unset($_POST['format'], $_POST['note']);

        if (!$entry) {
            throw new Exception('Failed to post reply: ' . implode(', ', $errors), 400);
        }

        return [
            'ticket_id' => $ticket->getId(),
            'number'    => $ticket->getNumber(),
            'entry_id'  => $entry->getId(),
            'status'    => (string)$ticket->getStatus(),
        ];
    }

    /**
     * Get comprehensive ticket statistics
     *
     * @return array Statistics data structure
     */
    public function getTicketStats(): array
    {
        // Iterating Ticket::objects() would load all ~48k tickets plus an N+1
        // department/staff lookup each — minutes of work that dropped the DB
        // connection. Aggregate in SQL instead. State lives in ost_ticket_status
        // ('open' vs 'closed'), so join it and count by CASE.
        $open = db_input('open');
        $closed = db_input('closed');

        $stats = [
            'total' => 0,
            'open' => 0,
            'closed' => 0,
            'overdue' => 0,
            'by_department' => [],
            'by_staff' => [],
        ];

        // Global counts
        $sql = sprintf(
            'SELECT COUNT(*) AS total,'
            . ' SUM(CASE WHEN s.state = %1$s THEN 1 ELSE 0 END) AS open_cnt,'
            . ' SUM(CASE WHEN s.state = %2$s THEN 1 ELSE 0 END) AS closed_cnt,'
            . ' SUM(CASE WHEN t.isoverdue = 1 THEN 1 ELSE 0 END) AS overdue_cnt'
            . ' FROM %3$s t JOIN %4$s s ON t.status_id = s.id',
            $open, $closed, TICKET_TABLE, TICKET_STATUS_TABLE
        );
        if (($res = db_query($sql)) && ($row = db_fetch_array($res))) {
            $stats['total'] = (int)$row['total'];
            $stats['open'] = (int)$row['open_cnt'];
            $stats['closed'] = (int)$row['closed_cnt'];
            $stats['overdue'] = (int)$row['overdue_cnt'];
        }

        // Per-department breakdown
        $sql = sprintf(
            'SELECT d.name AS dept,'
            . ' COUNT(*) AS total,'
            . ' SUM(CASE WHEN s.state = %1$s THEN 1 ELSE 0 END) AS open_cnt,'
            . ' SUM(CASE WHEN s.state = %2$s THEN 1 ELSE 0 END) AS closed_cnt,'
            . ' SUM(CASE WHEN t.isoverdue = 1 THEN 1 ELSE 0 END) AS overdue_cnt'
            . ' FROM %3$s t'
            . ' JOIN %4$s s ON t.status_id = s.id'
            . ' JOIN %5$s d ON t.dept_id = d.id'
            . ' GROUP BY d.id, d.name ORDER BY d.name',
            $open, $closed, TICKET_TABLE, TICKET_STATUS_TABLE, DEPT_TABLE
        );
        if ($res = db_query($sql)) {
            while ($row = db_fetch_array($res)) {
                $stats['by_department'][$row['dept']] = [
                    'total' => (int)$row['total'],
                    'open' => (int)$row['open_cnt'],
                    'closed' => (int)$row['closed_cnt'],
                    'overdue' => (int)$row['overdue_cnt'],
                ];
            }
        }

        // Per-staff breakdown (assigned tickets only)
        $sql = sprintf(
            'SELECT t.staff_id AS staff_id,'
            . ' CONCAT_WS(%1$s, st.firstname, st.lastname) AS staff_name,'
            . ' COUNT(*) AS total,'
            . ' SUM(CASE WHEN s.state = %2$s THEN 1 ELSE 0 END) AS open_cnt,'
            . ' SUM(CASE WHEN s.state = %3$s THEN 1 ELSE 0 END) AS closed_cnt'
            . ' FROM %4$s t'
            . ' JOIN %5$s s ON t.status_id = s.id'
            . ' JOIN %6$s st ON t.staff_id = st.staff_id'
            . ' WHERE t.staff_id > 0'
            . ' GROUP BY t.staff_id, staff_name ORDER BY staff_name',
            db_input(' '), $open, $closed, TICKET_TABLE, TICKET_STATUS_TABLE, STAFF_TABLE
        );
        if ($res = db_query($sql)) {
            while ($row = db_fetch_array($res)) {
                $stats['by_staff'][] = [
                    'staff_id' => (int)$row['staff_id'],
                    'staff_name' => trim((string)$row['staff_name']),
                    'total' => (int)$row['total'],
                    'open' => (int)$row['open_cnt'],
                    'closed' => (int)$row['closed_cnt'],
                ];
            }
        }

        return $stats;
    }

    /**
     * Search tickets with filters, pagination and sorting
     *
     * @param array $params Search parameters
     * @return array Array of tickets (without thread entries for performance)
     */
    public function searchTickets(array $params): array
    {
        $query = isset($params['query']) ? trim($params['query']) : null;

        // Status filter
        $statusFilter = $this->resolveStatusFilter($params['status'] ?? null);

        // Department filter
        $deptFilter = $this->resolveDepartmentFilter($params['department'] ?? null);

        // Pagination
        $limit = $this->resolvePaginationLimit($params['limit'] ?? null);
        $offset = max(0, (int)($params['offset'] ?? 0));

        // Sorting
        $sort = $this->resolveSortField($params['sort'] ?? null);

        // Build database query.
        //
        // IMPORTANT: use ->values() (flat rows) instead of hydrating full Ticket
        // models. Iterating hydrated Ticket objects here is pathological on this
        // osTicket build: materializing even a single row through the auto-joined
        // QuerySet triggers tens of thousands of INFORMATION_SCHEMA/related lookups
        // and takes 45s+ per request, which blows past the MCP client's 10s timeout.
        // values() pulls exactly the columns we need in one query (~1ms) and we map
        // them to the same response shape in buildSearchResultItemFromRow().
        $fields = [
            'ticket_id', 'number', 'status_id', 'dept_id', 'staff_id',
            'topic_id', 'team_id', 'sla_id',
            'created', 'updated', 'duedate', 'isoverdue', 'isanswered',
            'cdata__subject', 'cdata__priority',
            'status__name', 'dept__name', 'topic__topic',
            'staff__firstname', 'staff__lastname', 'team__name', 'sla__name',
        ];

        $tickets = Ticket::objects();

        // Filter by query (search in subject)
        if ($query !== null && $query !== '') {
            $tickets = $tickets->filter(['cdata__subject__contains' => $query]);
        }

        // Filter by status
        if ($statusFilter !== null) {
            $tickets = $tickets->filter(['status_id__in' => $statusFilter]);
        }

        // Filter by department
        if ($deptFilter !== null) {
            $tickets = $tickets->filter(['dept_id' => $deptFilter]);
        }

        // Project to flat rows (no model hydration)
        $tickets = $tickets->values(...$fields);

        // Apply sorting
        $tickets = match ($sort) {
            'updated' => $tickets->order_by('-updated'),
            'number' => $tickets->order_by('number'),
            default => $tickets->order_by('-created'),
        };

        // Apply pagination
        $tickets = $tickets->limit($limit)->offset($offset);

        // Build response array from flat rows
        $priorityMap = $this->loadPriorityMap();
        $results = [];
        foreach ($tickets as $row) {
            $results[] = $this->buildSearchResultItemFromRow($row, $priorityMap);
        }

        return $results;
    }

    /**
     * Resolve status filter from parameter
     */
    private function resolveStatusFilter(mixed $status): ?array
    {
        if ($status === null) {
            return null;
        }

        $statusParam = is_string($status) ? trim($status) : $status;

        if ($statusParam === '') {
            return null;
        }

        if (is_numeric($statusParam)) {
            return [(int)$statusParam];
        }

        if (!defined('TICKET_STATUS_TABLE')) {
            return null;
        }

        // Callers pass canonical English states (open/closed/resolved/archived).
        // osTicket localizes `name` (e.g. "Otevřený") but keeps the canonical
        // value in the `state` column, so match on `state` to stay locale-proof.
        // A state can span several rows (closed => Vyřešený, Uzavřený, ...), so
        // return every matching id and let the caller use status_id__in.
        $needle = strtolower((string)$statusParam);
        $needle = ['resolved' => 'closed'][$needle] ?? $needle;
        $esc = db_real_escape($needle);

        $sql = sprintf(
            "SELECT id FROM %s WHERE LOWER(state) = '%s' OR LOWER(name) = '%s'",
            TICKET_STATUS_TABLE,
            $esc,
            $esc
        );

        $ids = [];
        if ($result = db_query($sql)) {
            while ($row = db_fetch_array($result)) {
                $ids[] = (int)$row['id'];
            }
        }

        return $ids !== [] ? $ids : null;
    }

    /**
     * Resolve department filter from parameter
     */
    private function resolveDepartmentFilter(mixed $department): ?int
    {
        if ($department === null) {
            return null;
        }

        $deptParam = is_string($department) ? trim($department) : $department;

        if (is_numeric($deptParam)) {
            return (int)$deptParam;
        }

        if (!is_string($deptParam)) {
            return null;
        }

        // Path format: "Development / osTicket"
        if (strpos($deptParam, '/') !== false) {
            return $this->resolveDepartmentPath($deptParam);
        }

        // Simple name lookup
        return $this->resolveDepartmentByName($deptParam);
    }

    /**
     * Resolve department from path format
     */
    private function resolveDepartmentPath(string $path): ?int
    {
        if (!defined('DEPT_TABLE')) {
            return null;
        }

        $parts = array_map('trim', explode('/', $path));
        $currentDeptId = null;

        foreach ($parts as $index => $partName) {
            if ($index === 0) {
                $sql = sprintf(
                    "SELECT id FROM %s WHERE LOWER(name) = '%s' AND pid IS NULL",
                    DEPT_TABLE,
                    db_real_escape(strtolower($partName))
                );
            } else {
                $sql = sprintf(
                    "SELECT id FROM %s WHERE LOWER(name) = '%s' AND pid = %d",
                    DEPT_TABLE,
                    db_real_escape(strtolower($partName)),
                    $currentDeptId
                );
            }

            $result = db_query($sql);
            if ($result && ($row = db_fetch_array($result))) {
                $currentDeptId = (int)$row['id'];
            } else {
                return null;
            }
        }

        return $currentDeptId;
    }

    /**
     * Resolve department by name
     */
    private function resolveDepartmentByName(string $name): ?int
    {
        if (!defined('DEPT_TABLE')) {
            return null;
        }

        $sql = sprintf(
            "SELECT id FROM %s WHERE LOWER(name) = '%s' LIMIT 1",
            DEPT_TABLE,
            db_real_escape(strtolower($name))
        );
        $result = db_query($sql);

        if ($result && ($row = db_fetch_array($result))) {
            return (int)$row['id'];
        }

        return null;
    }

    /**
     * Resolve pagination limit
     *
     * Invalid values (null, negative, zero) default to 20
     * Values above 100 are capped at 100
     */
    private function resolvePaginationLimit(mixed $limit): int
    {
        if ($limit === null) {
            return 20;
        }

        $value = (int)$limit;

        // Negative or zero defaults to 20
        if ($value < 1) {
            return 20;
        }

        // Cap at 100
        return min($value, 100);
    }

    /**
     * Resolve sort field
     */
    private function resolveSortField(?string $sort): string
    {
        $allowedSorts = ['created', 'updated', 'number'];
        $sortValue = strtolower(trim($sort ?? 'created'));
        return in_array($sortValue, $allowedSorts, true) ? $sortValue : 'created';
    }

    /**
     * Load priority id => description map (small table, single query)
     *
     * @return array<int, string>
     */
    private function loadPriorityMap(): array
    {
        $map = [];
        if (!defined('PRIORITY_TABLE')) {
            return $map;
        }
        $res = db_query('SELECT priority_id, priority_desc FROM ' . PRIORITY_TABLE);
        if ($res) {
            while ($row = db_fetch_array($res)) {
                $map[(int)$row['priority_id']] = $row['priority_desc'];
            }
        }
        return $map;
    }

    /**
     * Build a search result item from a flat values() row.
     *
     * Produces the exact same shape as the previous model-based builder, but
     * without hydrating Ticket models (see searchTickets() for why).
     *
     * @param array<string, mixed> $row      Flat row from ORM values()
     * @param array<int, string>   $priorityMap  priority_id => description
     * @return array<string, mixed>
     */
    private function buildSearchResultItemFromRow(array $row, array $priorityMap): array
    {
        $priorityId = isset($row['cdata__priority']) && $row['cdata__priority'] !== null
            ? (int)$row['cdata__priority']
            : null;

        $staffId = (int)($row['staff_id'] ?? 0);
        $staff = null;
        if ($staffId > 0) {
            $staff = trim(((string)($row['staff__firstname'] ?? '')) . ' ' . ((string)($row['staff__lastname'] ?? '')));
            if ($staff === '') {
                $staff = null;
            }
        }

        $topicId = (int)($row['topic_id'] ?? 0);
        $teamId = (int)($row['team_id'] ?? 0);
        $slaId = (int)($row['sla_id'] ?? 0);

        return [
            'id' => (int)$row['ticket_id'],
            'number' => $row['number'],
            'subject' => $row['cdata__subject'],
            'statusId' => (int)$row['status_id'],
            'status' => $row['status__name'],
            'priorityId' => $priorityId,
            'priority' => $priorityId !== null ? ($priorityMap[$priorityId] ?? null) : null,
            'departmentId' => (int)($row['dept_id'] ?? 0),
            'department' => $row['dept__name'],
            'topicId' => $topicId,
            'topic' => $topicId > 0 ? ($row['topic__topic'] ?? null) : null,
            'created' => $row['created'],
            'updated' => $row['updated'],
            'dueDate' => $row['duedate'],
            'staffId' => $staffId,
            'staff' => $staff,
            'teamId' => $teamId,
            'team' => $teamId > 0 ? ($row['team__name'] ?? null) : null,
            'slaId' => $slaId,
            'sla' => $slaId > 0 ? ($row['sla__name'] ?? null) : null,
            'isOverdue' => (bool)(int)($row['isoverdue'] ?? 0),
            'isAnswered' => (bool)(int)($row['isanswered'] ?? 0),
        ];
    }

    /**
     * Get all ticket statuses
     *
     * @return array Array of status objects
     */
    public function getTicketStatuses(): array
    {
        $statuses = TicketStatus::objects()
            ->order_by('sort')
            ->all();

        $results = [];
        foreach ($statuses as $status) {
            $results[] = [
                'id' => $status->getId(),
                'name' => $status->getName(),
                'state' => $status->getState()
            ];
        }

        return $results;
    }

    // =========================================================================
    // Singleton Pattern
    // =========================================================================

    private static ?self $instance = null;

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }
}
