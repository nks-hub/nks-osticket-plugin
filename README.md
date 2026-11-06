# NKS osTicket API

[![Version](https://img.shields.io/badge/version-1.3.0-orange.svg)](CHANGELOG.md)
[![PHP](https://img.shields.io/badge/PHP-8.0+-777BB4.svg?logo=php&logoColor=white)](#requirements)
[![osTicket](https://img.shields.io/badge/osTicket-1.18-0E6EB8.svg)](https://osticket.com/)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

> A single-endpoint JSON API for [osTicket](https://osticket.com/) that integrations and AI agents call with osTicket's own API keys.

It covers tickets (list, search, get, create, update, reply, delete), statistics, statuses, parent/child subtickets and attachment download. The companion [NKS osTicket MCP](https://github.com/nks-hub/nks-osticket-mcp) server makes it available to AI agents over the Model Context Protocol.

---

## Features

- 🎫 **Tickets** - List, search, get, create, update, reply and delete tickets through one endpoint
- ⚡ **Flat reads** - Lists and searches use ORM `->values()` projections instead of hydrating full `Ticket` models
- 🔑 **Native API keys** - Authenticates against osTicket's own API keys with source-IP binding and granular per-action permissions
- 👤 **Writes as a real agent** - Replies and notes are posted as an osTicket agent (`staffId`), so the poster name, signature, from-name and auto-claim are filled in
- 📧 **Emailed replies** - `tickets.reply` sends the reply to the customer, with selectable recipients (`replyTo`)
- 🌳 **Subtickets** - Native parent/child tickets via `ticket_pid`, no third-party plugin
- 📎 **Attachments** - Replies can carry files, thread entries list their attachments (`fileId`, `cid`) and `attachments.download` fetches them
- 🧩 **No core changes** - A single web entry boots osTicket and dispatches a flat `action` string to a handler

## Requirements

- osTicket (developed against osTicket 1.18)
- PHP 8.0 or later
- Shell access to the osTicket web root and bash for `deploy.sh`
- An osTicket API key (Admin Panel → Manage → API Keys) with the permissions your integration needs

## Installation

```bash
git clone https://github.com/nks-hub/nks-osticket-plugin.git
cd nks-osticket-plugin
OSTICKET_WEB=/path/to/osticket/upload ./deploy.sh
# then chown the copied files to your web user
```

`OSTICKET_WEB` must point at the osTicket web root (the directory that contains `main.inc.php` and the `api/` folder). This installs:

- `include/plugins/nks-osticket-api/`: plugin code
- `api/nks-osticket.php`: the single web entry
- a bypass rule in `api/.htaccess` so osTicket's dispatcher doesn't intercept it

Registering/activating the plugin in the admin panel is optional; the API works as soon as the files are in place.

## Usage

### Endpoint

```
POST /api/nks-osticket.php
Header: X-Api-Key: <your osTicket API key>
Body:   { "action": "tickets.search", "params": { "query": "invoice", "limit": 10 } }
```

Read-only actions also accept `GET` with query parameters:

```
GET /api/nks-osticket.php?action=tickets.list&status=open&limit=5
```

### Response envelope

```json
{ "ok": true,  "data": { ... } }
{ "ok": false, "error": { "code": 404, "message": "Ticket not found" } }
```

### Actions

| Action | Params | Permission (OR) |
|---|---|---|
| `meta.ping` | – | (valid key) |
| `tickets.list` | `status?`, `department?`, `limit?`, `offset?`, `sort?` | search / read |
| `tickets.search` | `query`, `status?`, `limit?`, `offset?`, `sort?` | search / read |
| `tickets.get` | `number` \| `id` | read |
| `tickets.stats` | – | read stats / read |
| `tickets.statuses` | – | read stats / read |
| `tickets.create` | `subject`, `message`, `name?`, `email?`, `topicId?`, `priority?` | create |
| `tickets.update` | `number`, `status?`, `dueDate?`, `topicId?`, `note?`, `staffId?` | update |
| `tickets.reply` | `number`, `response`, `title?`, `alert?`, `staffId?`, `replyTo?`, `attachments?` | reply |
| `tickets.assign` | `number`, `assigneeId`, `note?`, `alert?`, `staffId?`, `reassign?` | assign |
| `tickets.delete` | `number` | delete |
| `subtickets.link` | `parent`, `child` | manage subtickets |
| `subtickets.unlink` | `child` | manage subtickets |
| `subtickets.children` | `number` | manage / read |
| `subtickets.parent` | `number` | manage / read |
| `attachments.download` | `fileId` | read |

`sort` is one of `created` (default, newest first), `updated`, `number`. `status` accepts canonical states (`open`, `closed`, `resolved`, `archived`) or a numeric status id. `staffId` is the agent a reply or note is posted as (default `1`). `replyTo` is `all` (default), `user` or `collabs`.

`tickets.assign` assigns the ticket to the agent `assigneeId` through osTicket's own `assignToStaff()`: it logs the `assigned` event with the acting agent (`staffId`), posts `note` as an internal note and sends the assignment alert (`alert`, default `true`). An assignee who is inactive or on vacation is rejected with `422`. A ticket that already has an assignee returns `409` unless `reassign` is `true`; assigning to the current assignee changes nothing.

`attachments` on `tickets.reply` is a list of `{ "name": "screenshot.jpg", "type": "image/jpeg", "data": "<base64>" }`. The files are stored on the reply and sent with its email when **Admin Panel → Settings → Emails → Attachments** is on. Each file is capped by osTicket's maximum file size setting; a larger one is rejected with `413`. The response reports how many attachments were stored.

`tickets.get` returns an `attachments` array on each thread entry (`fileId`, `name`, `type`, `size`, `inline`, `cid`). `fileId` is the id `attachments.download` expects, and `cid` matches the `cid:<...>` markers inline images use in the entry body.

## Authentication

Uses osTicket's native API keys (**Admin Panel → Manage → API Keys**). Each key is bound to a source address (an exact IP, `*` for any, a wildcard like `172.16.4.*`, IPv4 CIDR like `172.16.0.0/16`, or `regex:/.../`) and carries granular permission columns (`can_read_tickets`, `can_search_tickets`, `can_create_tickets`, `can_update_tickets`, `can_reply_tickets`, `can_delete_tickets`, `can_read_stats`, `can_manage_subtickets`, `can_assign_tickets`). On an IP mismatch the API returns the IP it observed, so the key can be bound to it.

### Granting permissions

Stock osTicket has only `can_create_tickets` and `can_exec_cron`. The API adds the other columns to `ost_api_key` on the first authenticated request, all set to `0`. The admin panel does not show them, so grant them in the database:

```sql
UPDATE ost_api_key
SET can_read_tickets = 1, can_search_tickets = 1, can_read_stats = 1
WHERE id = 2;  -- the key id, also returned by meta.ping as keyId
```

A `403` response names the missing permission and includes the matching `UPDATE` statement in `error.hint`. If the database user cannot alter the table, the API answers `500` with the `ALTER TABLE` statement to run by hand.

## How it works

A single web entry boots osTicket, authenticates against osTicket's own API keys and dispatches a flat `action` string to a handler. osTicket core files are not modified.

Read paths use the ORM's `->values()` projection instead of hydrating full `Ticket` models. On osTicket 1.18, hydration through the auto-joined QuerySet runs tens of thousands of introspection queries per row. Writes go through the documented Ticket API, so threads, alerts and logging still run.

## Development

The repository has no automated test suite. After a change, deploy to a test osTicket instance with `deploy.sh` and check the endpoint, for example:

```bash
curl -H "X-Api-Key: <your osTicket API key>" \
  "https://support.example.com/api/nks-osticket.php?action=meta.ping"
```

Changes are listed in [CHANGELOG.md](CHANGELOG.md).

## Contributing

Contributions are welcome! For major changes, please open an issue first.

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'feat: description'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## Support

- 📧 **Email:** dev@nks-hub.cz
- 🐛 **Bug reports:** [GitHub Issues](https://github.com/nks-hub/nks-osticket-plugin/issues)

## License

MIT License — see [LICENSE](LICENSE) for details.

---

<p align="center">
  Made with ❤️ by <a href="https://github.com/nks-hub">NKS Hub</a>
</p>
