# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2026-09-29

### Added
- **Detailed VPS Information & Date of Creation**:
  - Added `Date of Creation` display to Step 1: Detailed VPS Inspection and `--inspect` CLI command, showing formatted timestamp (`Y-m-d H:i:s`) alongside human-readable relative age (e.g. `2 years, 3 months ago`).
  - Added support for multiple database creation date columns (`time`, `created`, `created_at`, `time_added`, `date_created`) with graceful fallback when unrecorded.
  - Enhanced VPS inspection table with live VPS status badge (`Active / Running (1)` vs `Offline / Stopped (0)`), Virtualization architecture (`virt`: KVM, LXC, OpenVZ, Xen), Swap/burst memory, and Bandwidth limit.
  - Extended `Vps` model with `getTime()`, `getCreatedAt()`, `getCreationDateFormatted()`, `getTimeAgo()`, `getVirt()`, `getSwap()`, `getBandwidth()`, and `getRawData()`.

## [1.0.0] - 2026-09-28

### Added
- **Interactive Terminal Interface**: Full ANSI terminal UI featuring styled banners, status boards, box alerts, responsive tables, and interactive pagination.
- **7-Step Guarded Cleanup Workflow**:
  - Step 1: Detailed inspection of VPS, disks, assigned IPs, servers, and pending tasks.
  - Step 2: Safety validation preventing deletion of VPS with active tasks, warning on recycled server IDs/IPs, and verifying infrastructure retirement.
  - Step 3: Transparent dry-run preview displaying exact target tables, IDs, planned SQL, and an explicit list of untouched resources.
  - Step 4: Mandatory pre-deletion consistent database backup using `mysqldump` with MyISAM-compatible table locking, strict validation (exit code check, size > 0, header inspection), and `0600` permissions.
  - Step 5: Strict confirmation phrase requiring explicit typing of `DELETE <vpsid> <vps_name>`.
  - Step 6: Guarded cleanup with table locks (`LOCK TABLES`), immediate pre-modification revalidation, targeted queries, and automatic `UNLOCK TABLES` in a `finally` block.
  - Step 7: Post-deletion verification confirming the record is removed from `vps`, unlinking disk metadata, and preserving IP reservation.
- **Critical Safety Guardrails**:
  - Zero filesystem disk operations: Physical QCOW2, RAW, LVM, and ZFS files are never touched or unlinked.
  - Server records in `servers` table are preserved intact.
  - IP addresses are unlinked from the VPS (`vpsid = 0`) but retained in the IP pool and kept locked/reserved (`locked = 1`).
  - Mass or bulk deletion is strictly prohibited by default.
  - Automatic table unlocking on interruption or error.
- **Database & Engine Architecture**:
  - Automatic detection and parsing of Virtualizor's `/usr/local/virtualizor/universal.php`.
  - Dynamic schema inspection supporting Virtualizor 2.x, 3.x, and 4.x schemas.
  - MyISAM engine detector alerting the administrator that transactional rollback is unsupported.
  - Zero credential exposure in exception messages, logs, or terminal output.
- **Command-Line Interface**:
  - Non-destructive CLI flags: `--list`, `--page`, `--limit`, `--server`, `--search`, `--inspect`, `--schema`, `--backups`, `--history`.
  - Guarded flags: `--dry-run --delete <id>`, `--delete <id>`.
  - `--config <path>` for custom configuration files.
  - `--no-ansi` for plain text outputs in scripts and non-interactive environments.
- **Audit Logging**:
  - Dual structured logging: human-readable `vps-cleaner.log` and machine-readable `vps-cleaner.audit.jsonl`.
  - Automatic credential redaction.
- **Quality Assurance**:
  - Standalone zero-dependency test suite running across 39 automated tests covering configuration, database connection, schema inspection, relational hydration, dry-run accuracy, backup validation, cancellation, and MyISAM partial failure simulation.
