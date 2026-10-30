<?php
/**
 * Ticket operations: list, search, get, stats, statuses, create, update,
 * reply, assign, delete.
 *
 * Read paths use osTicket's ORM `->values()` projection (flat rows) instead of
 * hydrating full Ticket models — hydration through the auto-joined QuerySet is
 * pathologically slow on osTicket 1.18 (tens of thousands of introspection
 * queries per row). Writes go through the documented Ticket API so business
 * rules (threads, alerts, logging) still fire.
 */

declare(strict_types=1);

namespace NksOsticket;

final class TicketsHandler
{
    /** Columns pulled for every list/search row (flat, no model hydration). */
    private const LIST_FIELDS = [
        'ticket_id', 'number', 'status_id', 'dept_id', 'staff_id',
        'topic_id', 'team_id', 'sla_id',
        'created', 'updated', 'duedate', 'isoverdue', 'isanswered',
        'cdata__subject', 'cdata__priority',
        'status__name', 'dept__name', 'topic__topic',
        'staff__firstname', 'staff__lastname', 'team__name', 'sla__name',
    ];

    /**
     * Agent the API posts as. osTicket derives the poster name, the signature,
     * the from-name and the auto-claim from it; without an agent the entry is
     * stored anonymously.
     */
    private const DEFAULT_STAFF_ID = 1;

    /** @var array<int,string>|null Cached priority id => description map. */
    private ?array $priorityMap = null;

    // ---------------------------------------------------------------- reads

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function listTickets(array $params): array
    {
        $limit = $this->clampLimit($params['limit'] ?? 20);
        $offset = max(0, (int)($params['offset'] ?? 0));
        $sort = $this->resolveSort($params['sort'] ?? 'created');

        $qs = \Ticket::objects();

        $statusIds = $this->resolveStatusFilter($params['status'] ?? null);
        if ($statusIds !== null) {
            $qs = $qs->filter(['status_id__in' => $statusIds]);
        }
        $deptId = $this->resolveDeptFilter($params['department'] ?? null);
        if ($deptId !== null) {
            $qs = $qs->filter(['dept_id' => $deptId]);
        }

        return $this->paginate($qs, $sort, $limit, $offset);
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function search(array $params): array
    {
        $query = isset($params['query']) ? trim((string)$params['query']) : '';
        if ($query === '') {
            throw new ApiException('search requires a non-empty "query"', 400);
        }
        $limit = $this->clampLimit($params['limit'] ?? 20);
        $offset = max(0, (int)($params['offset'] ?? 0));
        $sort = $this->resolveSort($params['sort'] ?? 'created');

        $qs = \Ticket::objects()->filter(['cdata__subject__contains' => $query]);
        $statusIds = $this->resolveStatusFilter($params['status'] ?? null);
        if ($statusIds !== null) {
            $qs = $qs->filter(['status_id__in' => $statusIds]);
        }

        $result = $this->paginate($qs, $sort, $limit, $offset);
        $result['query'] = $query;
        return $result;
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function get(array $params): array
    {
        $ticket = $this->lookup($params);
        return $this->fullTicket($ticket);
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function stats(array $params): array
    {
        // Aggregate counts by status state; cheap COUNT/GROUP BY, no hydration.
        $ticketTable = defined('TICKET_TABLE') ? TICKET_TABLE : 'ost_ticket';
        $statusTable = defined('TICKET_STATUS_TABLE') ? TICKET_STATUS_TABLE : 'ost_ticket_status';

        $sql = sprintf(
            'SELECT s.state AS state, COUNT(*) AS cnt FROM %s t '
            . 'JOIN %s s ON s.id = t.status_id GROUP BY s.state',
            $ticketTable,
            $statusTable
        );
        $byState = ['open' => 0, 'closed' => 0];
        $total = 0;
        if ($res = \db_query($sql)) {
            while ($row = \db_fetch_array($res)) {
                $state = (string)$row['state'];
                $cnt = (int)$row['cnt'];
                $byState[$state] = ($byState[$state] ?? 0) + $cnt;
                $total += $cnt;
            }
        }

        $overdueSql = sprintf('SELECT COUNT(*) AS c FROM %s WHERE isoverdue = 1', $ticketTable);
        $overdue = 0;
        if ($res = \db_query($overdueSql)) {
            $r = \db_fetch_array($res);
            $overdue = (int)($r['c'] ?? 0);
        }

        return [
            'total' => $total,
            'open' => $byState['open'] ?? 0,
            'closed' => $byState['closed'] ?? 0,
            'overdue' => $overdue,
            'byState' => $byState,
        ];
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function statuses(array $params): array
    {
        $out = [];
        foreach (\TicketStatus::objects()->order_by('sort') as $status) {
            $out[] = [
                'id' => (int)$status->getId(),
                'name' => $status->getName(),
                'state' => $status->getState(),
            ];
        }
        return ['statuses' => $out];
    }

    // --------------------------------------------------------------- writes

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function create(array $params): array
    {
        $subject = trim((string)($params['subject'] ?? ''));
        $message = (string)($params['message'] ?? '');
        if ($subject === '' || $message === '') {
            throw new ApiException('create requires "subject" and "message"', 400);
        }

        $vars = [
            'source' => 'API',
            'name' => trim((string)($params['name'] ?? 'API')),
            'email' => trim((string)($params['email'] ?? '')),
            'subject' => $subject,
            'message' => $message,
        ];
        if (!empty($params['topicId'])) {
            $vars['topicId'] = (int)$params['topicId'];
        }
        if (!empty($params['priority'])) {
            $vars['priorityId'] = (int)$params['priority'];
        }
        if ($vars['email'] === '') {
            // osTicket requires a submitter e-mail; use a system fallback.
            $vars['email'] = 'api@' . (defined('THISPAGE') ? parse_url((string)THISPAGE, PHP_URL_HOST) : 'localhost');
        }

        $errors = [];
        $ticket = \Ticket::create($vars, $errors, 'api');
        if (!$ticket) {
            throw new ApiException('Ticket creation failed: ' . $this->flattenErrors($errors), 422);
        }

        return ['number' => $ticket->getNumber(), 'id' => (int)$ticket->getId()];
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function update(array $params): array
    {
        $ticket = $this->lookup($params);
        $changed = [];

        if (array_key_exists('status', $params) && $params['status'] !== null && $params['status'] !== '') {
            $statusId = $this->resolveSingleStatusId((string)$params['status']);
            if ($statusId === null) {
                throw new ApiException('Unknown status: ' . $params['status'], 400);
            }
            $errors = [];
            $ticket->setStatus($statusId, (string)($params['statusComment'] ?? ''), $errors);
            $changed[] = 'status';
        }

        if (!empty($params['dueDate'])) {
            $ticket->duedate = date('Y-m-d H:i:s', strtotime((string)$params['dueDate']));
            $ticket->save();
            $changed[] = 'dueDate';
        } elseif (array_key_exists('dueDate', $params) && $params['dueDate'] === null) {
            $ticket->duedate = null;
            $ticket->save();
            $changed[] = 'dueDate';
        }

        if (!empty($params['topicId'])) {
            $ticket->topic_id = (int)$params['topicId'];
            $ticket->save();
            $changed[] = 'topicId';
        }

        if (isset($params['note']) && trim((string)$params['note']) !== '') {
            $vars = [
                'note' => (string)$params['note'],
                'title' => (string)($params['noteTitle'] ?? 'API note'),
            ];
            $errors = [];
            $ticket->postNote($vars, $errors, $this->actingStaff($params), false);
            $changed[] = 'note';
        }

        if ($changed === []) {
            throw new ApiException('update: nothing to change (status/dueDate/topicId/note)', 400);
        }

        $ticket = $this->reload($ticket->getNumber());
        return ['number' => $ticket->getNumber(), 'changed' => $changed, 'ticket' => $this->summary($ticket)];
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function reply(array $params): array
    {
        $ticket = $this->lookup($params);
        $response = (string)($params['response'] ?? '');
        if (trim($response) === '') {
            throw new ApiException('reply requires a non-empty "response"', 400);
        }

        $staff = $this->actingStaff($params);

        $vars = [
            'response' => $response,
            'title' => (string)($params['title'] ?? ''),
            // Without reply-to, Ticket::getRecipients() returns null and the
            // reply is stored in the thread but never mailed to the customer.
            'reply-to' => (string)($params['replyTo'] ?? 'all'),
            'signature' => $staff ? 'mine' : 'dept',
        ];
        if (isset($params['attachments'])) {
            $vars['attachments'] = $this->decodeAttachments($params['attachments']);
        }
        $errors = [];
        $entry = $ticket->postReply($vars, $errors, (bool)($params['alert'] ?? true));
        if (!$entry) {
            throw new ApiException('Reply failed: ' . $this->flattenErrors($errors), 422);
        }

        return [
            'number' => $ticket->getNumber(),
            'posted' => true,
            'attachments' => $entry->getAttachments()->count(),
        ];
    }

    /**
     * Assigns the ticket to an agent through Ticket::assignToStaff(), so
     * osTicket logs the "assigned" event (with the acting agent), posts the
     * optional comment as an internal note and sends the assignment alert.
     * An already assigned ticket is only reassigned with `reassign: true`.
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function assign(array $params): array
    {
        $ticket = $this->lookup($params);
        $assigneeId = (int)($params['assigneeId'] ?? 0);
        if ($assigneeId <= 0) {
            throw new ApiException('assign requires "assigneeId" (staff id of the new assignee)', 400);
        }
        $assignee = \Staff::lookup($assigneeId);
        if (!$assignee) {
            throw new ApiException('Unknown assignee: ' . $assigneeId, 404);
        }
        if (!$assignee->isAvailable()) {
            throw new ApiException('Assignee ' . $assigneeId . ' is not available (inactive or on vacation)', 422);
        }

        $current = (int)$ticket->getStaffId();
        if ($current === $assigneeId) {
            return ['number' => $ticket->getNumber(), 'assigned' => false, 'reason' => 'already assigned to this agent', 'ticket' => $this->summary($ticket)];
        }
        if ($current > 0 && empty($params['reassign'])) {
            throw new ApiException('Ticket is already assigned to staff ' . $current . '; pass "reassign": true to change it', 409);
        }

        $this->actingStaff($params);
        if (!$ticket->assignToStaff($assignee, (string)($params['note'] ?? ''), (bool)($params['alert'] ?? true))) {
            throw new ApiException('Assignment failed', 500);
        }

        $ticket = $this->reload($ticket->getNumber());
        return ['number' => $ticket->getNumber(), 'assigned' => true, 'ticket' => $this->summary($ticket)];
    }

    /**
     * Turns API attachments `{name, type?, data}` (data base64) into the file
     * arrays ThreadEntry::create() stores; postReply() then mails them along
     * when "Email attachments" is enabled. The size cap is osTicket's own
     * max_file_size setting.
     *
     * @param mixed $attachments
     * @return array<int,array{name:string,type:string,data:string}>
     */
    private function decodeAttachments($attachments): array
    {
        global $cfg;

        if (!is_array($attachments) || $attachments !== array_values($attachments)) {
            throw new ApiException('"attachments" must be a list of {name, type, data}', 400);
        }
        $maxBytes = (int)$cfg->getMaxFileSize();
        $files = [];
        foreach ($attachments as $i => $att) {
            $name = is_array($att) ? basename((string)($att['name'] ?? '')) : '';
            $data = is_array($att) ? base64_decode((string)($att['data'] ?? ''), true) : false;
            if ($name === '' || $data === false || $data === '') {
                throw new ApiException("attachments[$i] needs a file name and base64 data", 400);
            }
            if ($maxBytes > 0 && strlen($data) > $maxBytes) {
                throw new ApiException("attachments[$i] is larger than osTicket's max_file_size ($maxBytes bytes)", 413);
            }
            $files[] = [
                'name' => $name,
                'type' => (string)($att['type'] ?? '') ?: 'application/octet-stream',
                'data' => $data,
            ];
        }
        return $files;
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function delete(array $params): array
    {
        $ticket = $this->lookup($params);
        $number = $ticket->getNumber();
        if (!$ticket->delete()) {
            throw new ApiException('Ticket deletion failed', 500);
        }
        return ['number' => $number, 'deleted' => true];
    }

    // --------------------------------------------------------------- helpers

    /**
     * Resolve the acting agent and publish it where osTicket looks for it -
     * core reads $thisstaff straight from the global scope.
     *
     * @param array<string,mixed> $params
     */
    private function actingStaff(array $params): ?\Staff
    {
        $id = (int)($params['staffId'] ?? self::DEFAULT_STAFF_ID);
        $staff = $id > 0 ? \Staff::lookup($id) : null;
        if ($staff) {
            $GLOBALS['thisstaff'] = $staff;
        }

        return $staff;
    }

    /**
     * Run a values() projection with sort + pagination and map to output rows.
     *
     * @return array<string,mixed>
     */
    private function paginate($qs, string $sort, int $limit, int $offset): array
    {
        $qs = $qs->values(...self::LIST_FIELDS);
        $qs = match ($sort) {
            'updated' => $qs->order_by('-updated'),
            'number' => $qs->order_by('number'),
            default => $qs->order_by('-created'),
        };
        $qs = $qs->limit($limit)->offset($offset);

        $priorityMap = $this->loadPriorityMap();
        $tickets = [];
        foreach ($qs as $row) {
            $tickets[] = $this->mapRow($row, $priorityMap);
        }

        return [
            'tickets' => $tickets,
            'count' => count($tickets),
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @param array<int,string> $priorityMap
     * @return array<string,mixed>
     */
    private function mapRow(array $row, array $priorityMap): array
    {
        $priorityId = isset($row['cdata__priority']) && $row['cdata__priority'] !== null
            ? (int)$row['cdata__priority'] : null;
        $staffId = (int)($row['staff_id'] ?? 0);
        $staff = null;
        if ($staffId > 0) {
            $staff = trim((string)($row['staff__firstname'] ?? '') . ' ' . (string)($row['staff__lastname'] ?? ''));
            $staff = $staff === '' ? null : $staff;
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
     * Full ticket detail including thread entries.
     *
     * @return array<string,mixed>
     */
    private function fullTicket(\Ticket $ticket): array
    {
        $data = $this->summary($ticket);
        $data['user'] = ['name' => (string)$ticket->getName(), 'email' => (string)$ticket->getEmail()];
        $data['source'] = $ticket->getSource();
        $data['thread'] = $this->threadEntries($ticket);
        return $data;
    }

    /**
     * @return array<string,mixed>
     */
    private function summary(\Ticket $ticket): array
    {
        return [
            'id' => (int)$ticket->getId(),
            'number' => $ticket->getNumber(),
            'subject' => $ticket->getSubject(),
            'statusId' => (int)$ticket->getStatusId(),
            'status' => (string)$ticket->getStatus(),
            'departmentId' => (int)$ticket->getDeptId(),
            'department' => $ticket->getDept() ? $ticket->getDept()->getName() : null,
            'staffId' => (int)$ticket->getStaffId(),
            'staff' => $ticket->getStaff() ? (string)$ticket->getStaff()->getName() : null,
            'created' => $ticket->getCreateDate(),
            'updated' => $ticket->getUpdateDate(),
            'dueDate' => $ticket->getDueDate(),
            'isOverdue' => (bool)$ticket->isOverdue(),
            'isAnswered' => (bool)$ticket->isAnswered(),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function threadEntries(\Ticket $ticket): array
    {
        $entries = [];
        $thread = $ticket->getThread();
        if (!$thread) {
            return $entries;
        }
        foreach ($thread->getEntries() as $entry) {
            $item = [
                'id' => (int)$entry->getId(),
                'type' => $entry->getType(),
                'poster' => $entry->getPoster(),
                'created' => $entry->getCreateDate(),
                'body' => (string)$entry->getBody(),
            ];
            if ($entry->getStaffId()) {
                $item['staffId'] = (int)$entry->getStaffId();
            }
            if ($entry->getUserId()) {
                $item['userId'] = (int)$entry->getUserId();
            }
            $attachments = $this->entryAttachments($entry);
            if ($attachments !== []) {
                $item['attachments'] = $attachments;
            }
            $entries[] = $item;
        }
        return $entries;
    }

    /**
     * Attachment metadata for a thread entry.
     *
     * Each item carries the numeric `fileId` that attachments.download needs,
     * plus name/type/size, the `inline` flag, and the content-id `cid` that
     * inline images reference in the entry body (the body contains
     * `cid:<cid>` markers). Without this the API exposed no way to discover an
     * attachment's file id, so downloads were impossible.
     *
     * @return array<int,array<string,mixed>>
     */
    private function entryAttachments(\ThreadEntry $entry): array
    {
        $out = [];
        $attachments = $entry->getAttachments();
        if (!$attachments) {
            return $out;
        }
        foreach ($attachments as $att) {
            $file = $att->file;
            if (!$file) {
                continue;
            }
            $out[] = [
                'fileId' => (int)$file->getId(),
                'name' => (string)$att->getFilename(),
                'type' => (string)$file->getType(),
                'size' => (int)$file->getSize(),
                'inline' => (bool)$att->inline,
                'cid' => (string)$file->getKey(),
            ];
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $params
     */
    private function lookup(array $params): \Ticket
    {
        $number = $params['number'] ?? null;
        $id = $params['id'] ?? null;
        if ($number === null && $id === null) {
            throw new ApiException('Provide "number" or "id"', 400);
        }
        $ticket = null;
        if ($number !== null && $number !== '') {
            $ticket = \Ticket::lookupByNumber((string)$number);
        }
        if (!$ticket && $id !== null) {
            $ticket = \Ticket::lookup((int)$id);
        }
        if (!$ticket) {
            throw new ApiException('Ticket not found', 404);
        }
        return $ticket;
    }

    private function reload(string $number): \Ticket
    {
        $ticket = \Ticket::lookupByNumber($number);
        if (!$ticket) {
            throw new ApiException('Ticket vanished after update', 500);
        }
        return $ticket;
    }

    /** @return array<int,string> */
    private function loadPriorityMap(): array
    {
        if ($this->priorityMap !== null) {
            return $this->priorityMap;
        }
        $map = [];
        if (defined('PRIORITY_TABLE')) {
            if ($res = \db_query('SELECT priority_id, priority_desc FROM ' . PRIORITY_TABLE)) {
                while ($row = \db_fetch_array($res)) {
                    $map[(int)$row['priority_id']] = $row['priority_desc'];
                }
            }
        }
        return $this->priorityMap = $map;
    }

    /**
     * Map canonical states (open/closed/resolved/archived) to matching status
     * ids. osTicket localizes `name` but keeps canonical `state`, so match on
     * `state`; one state can span several ids (closed => Resolved, Closed...).
     *
     * @return array<int,int>|null
     */
    private function resolveStatusFilter($status): ?array
    {
        if ($status === null || $status === '') {
            return null;
        }
        if (is_numeric($status)) {
            return [(int)$status];
        }
        $needle = strtolower(trim((string)$status));
        $needle = $needle === 'resolved' ? 'closed' : $needle;
        $table = defined('TICKET_STATUS_TABLE') ? TICKET_STATUS_TABLE : 'ost_ticket_status';
        $sql = sprintf(
            "SELECT id FROM %s WHERE LOWER(state) = '%s' OR LOWER(name) = '%s'",
            $table,
            \db_real_escape($needle),
            \db_real_escape($needle)
        );
        $ids = [];
        if ($res = \db_query($sql)) {
            while ($row = \db_fetch_array($res)) {
                $ids[] = (int)$row['id'];
            }
        }
        return $ids !== [] ? $ids : null;
    }

    private function resolveSingleStatusId(string $status): ?int
    {
        $ids = $this->resolveStatusFilter($status);
        return $ids[0] ?? null;
    }

    private function resolveDeptFilter($department): ?int
    {
        if ($department === null || $department === '') {
            return null;
        }
        if (is_numeric($department)) {
            return (int)$department;
        }
        $table = defined('DEPT_TABLE') ? DEPT_TABLE : 'ost_department';
        $sql = sprintf(
            "SELECT id FROM %s WHERE LOWER(name) = '%s' LIMIT 1",
            $table,
            \db_real_escape(strtolower(trim((string)$department)))
        );
        if (($res = \db_query($sql)) && ($row = \db_fetch_array($res))) {
            return (int)$row['id'];
        }
        return null;
    }

    private function resolveSort($sort): string
    {
        $sort = strtolower(trim((string)$sort));
        return in_array($sort, ['created', 'updated', 'number'], true) ? $sort : 'created';
    }

    private function clampLimit($limit): int
    {
        $value = (int)$limit;
        if ($value < 1) {
            return 20;
        }
        return min($value, 100);
    }

    /**
     * @param array<mixed> $errors
     */
    private function flattenErrors(array $errors): string
    {
        $parts = [];
        array_walk_recursive($errors, static function ($v) use (&$parts) {
            if (is_string($v) && $v !== '') {
                $parts[] = $v;
            }
        });
        return $parts === [] ? 'unknown error' : implode('; ', $parts);
    }
}
