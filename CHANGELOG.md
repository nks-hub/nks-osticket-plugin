# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/)
and this project adheres to [Semantic Versioning](https://semver.org/).

## [1.4.0] - 2026-10-30

### Added
- `tickets.assign` assigns a ticket to an agent (`assigneeId`) via
  `Ticket::assignToStaff()`, so the `assigned` event, the optional internal
  note (`note`) and the assignment alert (`alert`) work as in the staff panel.
  Inactive or vacationing assignees return `422`; an assigned ticket returns
  `409` unless `reassign` is `true`. Needs the new `can_assign_tickets`
  permission column, created on first use like the others.
- API key addresses accept `*` (any), wildcards (`172.16.4.*`), IPv4 CIDR
  (`172.16.0.0/16`) and `regex:/.../` besides an exact IP.

### Fixed
- `Kernel::VERSION` (reported by `meta.ping`) matches the plugin version again.

## [1.3.0] - 2026-10-28

### Added
- `tickets.reply` accepts `attachments`, a list of `{name, type, data}` with
  base64 data. The files are stored on the reply entry and mailed with it.
  Each file is limited by osTicket's `max_file_size`; a larger one returns
  `413`, a missing name or invalid base64 returns `400`.
- The `tickets.reply` response includes `attachments`, the number of files
  stored on the new entry.

## [1.2.1] - 2026-10-23

### Fixed
- Every action except `meta.ping` returned `403 API key lacks permission` on a
  stock osTicket install. The permission columns (`can_read_tickets`,
  `can_search_tickets`, …) were never created, because stock `ost_api_key`
  has only `can_create_tickets` and `can_exec_cron`. They are now added on the
  first authenticated request, defaulting to `0`.

### Added
- A `403` response carries `error.hint` with the `UPDATE` statement that grants
  the missing permission, and the README documents how to grant permissions.

## [1.2.0] - 2026-08-18

### Fixed
- `tickets.reply` now actually emails the customer. Without `reply-to` in the
  vars, `Ticket::getRecipients()` returns null and osTicket stores the reply in
  the thread but never sends it. Recipients default to `all`, overridable with
  the new `replyTo` parameter (`all` / `user` / `collabs`).

### Added
- Writes act as a real agent instead of posting anonymously. `tickets.reply`
  and the note in `tickets.update` resolve a `Staff` (new optional `staffId`
  parameter, default `1`) and publish it as `$thisstaff`, so osTicket fills in
  the poster name, the agent signature, the reply from-name and the auto-claim.

## [1.1.0] - 2026-07-07

### Added
- Thread entries in `tickets.get` now carry an `attachments` array with
  `fileId`, `name`, `type`, `size`, `inline` and `cid`. `fileId` is the id
  `attachments.download` expects and `cid` matches the `cid:<...>` markers
  inline images use in the entry body — attachments were previously
  undiscoverable, so downloads were impossible without it.

## [1.0.0] - 2026-07-05

### Added
- Single JSON-RPC endpoint (`POST /api/nks-osticket.php`) exposing the full
  ticket surface through one deploy file.
- Actions: `tickets.list`, `tickets.search`, `tickets.get`, `tickets.stats`,
  `tickets.statuses`, `tickets.create`, `tickets.update`, `tickets.reply`,
  `tickets.delete`, `subtickets.link`, `subtickets.unlink`,
  `subtickets.children`, `subtickets.parent`, `attachments.download`,
  `meta.ping`.
- API-key authentication (osTicket API keys) with source-IP binding and
  granular per-action permissions.
- Fast read paths using ORM `->values()` projections (no full model
  hydration) — safe on large ticket tables.
- Native parent/child subtickets via `ticket_pid` (no third-party plugin).
