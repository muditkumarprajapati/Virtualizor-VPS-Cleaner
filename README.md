# Virtualizor VPS Cleanup Manager

[![PHP Version](https://img.shields.io/badge/PHP-7.4%2B-777bb4.svg)](https://www.php.net/)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![Tests: 39 Passing](https://img.shields.io/badge/Tests-39%20Passing-brightgreen.svg)](tests/)
[![Virtualizor: 2.x%20--%204.x](https://img.shields.io/badge/Virtualizor-2.x%20--%204.x-blue.svg)](https://www.virtualizor.com/)

A production-grade, interactive command-line utility built for Linux systems administrators, hosting operators, and database administrators to safely inspect and purge orphaned VPS database records on Virtualizor master servers when slave nodes have been permanently retired, wiped, or reinstalled.

```
╔══════════════════════════════════════════════════════════════════════════════╗
║                       VIRTUALIZOR VPS CLEANUP MANAGER                        ║
║       Safe Orphaned Database Record Cleanup for Retired Infrastructure       ║
╚══════════════════════════════════════════════════════════════════════════════╝
```

---

## Table of Contents

1. [The Problem](#the-problem)
2. [Core Safety Guarantees](#core-safety-guarantees)
3. [The 7-Step Guarded Cleanup Workflow](#the-7-step-guarded-cleanup-workflow)
4. [Architecture & MyISAM Caveats](#architecture--myisam-caveats)
5. [System Requirements](#system-requirements)
6. [Installation & Execution](#installation--execution)
7. [Configuration](#configuration)
8. [Interactive Terminal Mode](#interactive-terminal-mode)
9. [Command-Line Mode & Flags](#command-line-mode--flags)
10. [Step-by-Step Disaster Recovery](#step-by-step-disaster-recovery)
11. [Audit Logging & Backup Retention](#audit-logging--backup-retention)
12. [Automated Testing & Quality Assurance](#automated-testing--quality-assurance)
13. [Project Directory Layout](#project-directory-layout)
14. [Contributing & License](#contributing--license)

---

## The Problem

On Virtualizor master servers, when a dedicated slave hypervisor node is decommissioned, terminated by an upstream provider, or reinstalled from scratch, the slave agent daemon is no longer accessible over port `4083`. 

Attempting to delete a VPS hosted on that retired node via the Virtualizor Admin Panel fails with errors such as:
> *"Could not connect to the slave server."* or *"Slave server is offline."*

Because the GUI expects the remote slave agent to unlink disks and networking before pruning the master database, administrators are left with ghost/orphaned VPS entries. Manually executing `DELETE FROM vps WHERE ...` in phpMyAdmin or the MySQL shell is fraught with danger:
- Virtualizor tables frequently use the **MyISAM** storage engine, which **does not support ACID transactions (`ROLLBACK` is impossible)**.
- Unlinked disk metadata in `disks` and orphaned IP references in `ips` lead to foreign key anomalies and ghost resource allocation.
- Inexperienced operators risk accidentally truncating live customer records or destroying physical storage volumes.

**Virtualizor VPS Cleanup Manager** provides a surgical, fully validated, and guarded terminal interface specifically designed to solve this problem without endangering customer data.

---

## Core Safety Guarantees

| Rule | Enforcement Mechanism |
| :--- | :--- |
| **NEVER touch physical disk files** | The tool exclusively performs database metadata operations. Storage paths (`/var/virtualizor/...`, `.qcow2`, `.raw`, LVM, ZFS volumes, Google Drive, and rclone mounts) are **never unlinked or deleted**. |
| **NEVER delete server records** | Entries in the `servers` table are strictly read-only and preserved intact. |
| **NEVER lose IP pool addresses** | Assigned IPs are disassociated (`vpsid = 0`) while remaining in the IP pool, and are kept explicitly locked/reserved (`locked = 1`) to prevent premature reallocation. |
| **NEVER delete without verified backup** | Prior to any write operation, a timestamped consistent full database backup is generated using `mysqldump`, verified for non-zero byte size and valid SQL headers, and locked with `0600` permissions. |
| **Strict Table Locking** | Because MyISAM lacks transactions, tables are locked (`LOCK TABLES vps WRITE, disks WRITE, ips WRITE, servers READ, tasks READ`) during modification and guaranteed unlocked in a `finally` block even upon unhandled errors. |
| **Revalidation under Lock** | Records are re-checked immediately after acquiring locks to guarantee zero race conditions between inspection and execution. |
| **Explicit Confirmation Phrase** | Destructive execution requires typing the exact case-sensitive confirmation phrase: `DELETE <vpsid> <vps_name>`. |
| **No Bulk Deletion** | Mass or bulk deletion is strictly forbidden by default; each VPS requires individual review and confirmation. |
| **Zero Credential Exposure** | Database passwords and tokens are never shown on screen, logged to files, or leaked in exception stack traces. |

---

## The 7-Step Guarded Cleanup Workflow

Every deletion request strictly traverses seven sequential checkpoints:

```
[ STEP 1: INSPECTION ]
   └─ Displays VPS details, UUID, hostname, disks, assigned IPs, node status, and active tasks.
[ STEP 2: SAFETY VALIDATION ]
   └─ Validates node retirement, checks for recycled IP/server ID hazards, blocks on active tasks.
[ STEP 3: DRY RUN PREVIEW ]
   └─ Shows exact SQL commands, target rows, and an explicit list of untouched resources.
[ STEP 4: DATABASE BACKUP ]
   └─ Executes mysqldump with MyISAM locks; validates non-empty file; verifies 0600 permissions.
[ STEP 5: EXPLICIT CONFIRMATION ]
   └─ User must type exact phrase: "DELETE <vpsid> <vps_name>". Anything else aborts.
[ STEP 6: GUARDED CLEANUP ]
   └─ Acquires LOCK TABLES, revalidates record UUID, executes targeted queries, unlocks in finally.
[ STEP 7: RESULTS & VERIFICATION ]
   └─ Queries DB to verify record is gone; displays backup path, unlinked counts, and logs audit trail.
```

---

## Architecture & MyISAM Caveats

Most legacy and current Virtualizor master databases run on MySQL/MariaDB with **MyISAM** tables. 

### Why Transactions Do Not Protect You on MyISAM
If you issue `START TRANSACTION; DELETE FROM ...;` on a MyISAM table:
1. MySQL silently ignores the transaction directive.
2. Every `DELETE` or `UPDATE` commits immediately and permanently to disk.
3. If an error or crash occurs halfway through, **`ROLLBACK` has no effect**.

### How This Tool Mitigates MyISAM Risks
1. **Explicit Table Locking (`LOCK TABLES ... WRITE / READ`)**: Prevents any concurrent background Virtualizor cron or admin action from modifying records during cleanup.
2. **Pre-Flight Schema Inspection**: Dynamically introspects column names across Virtualizor versions (2.x through 4.x) to ensure compatible SQL.
3. **Mandatory Consistent Pre-Deletion Backups**: Generates a verified `.sql` backup using `--quick --lock-tables` before a single byte is changed.
4. **Guaranteed Unlock**: Implements PHP `finally` blocks and POSIX signal handlers (`SIGINT`, `SIGTERM`) to release table locks if interrupted.

---

## System Requirements

- **Operating System**: Linux (CentOS, AlmaLinux, Rocky Linux, Ubuntu, Debian)
- **Control Panel**: Virtualizor Master Server
- **PHP Version**: PHP 7.4 or newer (Compatible with PHP 8.0, 8.1, 8.2, 8.3, 8.4+)
- **Virtualizor PHP Binary**: Uses `/usr/local/emps/bin/php` or standard system `/usr/bin/php`
- **Required PHP Extensions**: `pdo`, `pdo_mysql` (or `pdo_sqlite` for tests), `json`, `mbstring`
- **Database Tools**: `mysqldump` or `mariadb-dump` installed and in PATH (with automatic PDO fallback)

---

## Installation & Execution

### Option 1: Quick One-Liner (No Git Required)
If `git` is not installed on your server, you can download and extract directly using `curl` and `tar`:

```bash
cd /root
curl -sSL https://github.com/muditkumarprajapati/virtualizor-vps-cleaner/archive/refs/heads/main.tar.gz | tar -xz
cd virtualizor-vps-cleaner-main
chmod +x virtualizor-vps-cleaner.php
```

### Option 2: Clone with Git
If `git` is installed:

```bash
cd /root
git clone https://github.com/muditkumarprajapati/virtualizor-vps-cleaner.git
cd virtualizor-vps-cleaner
chmod +x virtualizor-vps-cleaner.php
```

*(If you get `-bash: git: command not found`, install it via `yum install -y git` on RHEL/CentOS/AlmaLinux or `apt update && apt install -y git` on Ubuntu/Debian).*

### 2. Zero-Dependency Runtime
The utility has **zero external package requirements** to run. It includes a built-in PSR-4 autoloader and seamlessly works with Virtualizor's internal EMPS PHP environment:

```bash
# Launch interactive mode using Virtualizor's internal PHP
/usr/local/emps/bin/php virtualizor-vps-cleaner.php

# Or using system PHP
php virtualizor-vps-cleaner.php
```

---

## Configuration

By default, the utility automatically detects and loads the database credentials from Virtualizor's universal configuration file:
```
/usr/local/virtualizor/universal.php
```

### Custom Configuration Path
If your installation uses a non-standard path, pass `--config`:
```bash
php virtualizor-vps-cleaner.php --config /path/to/custom/universal.php
```

### Environment Variable Overrides
You can also override connection parameters via environment variables:
```bash
export VIRTUALIZOR_DB_HOST="127.0.0.1"
export VIRTUALIZOR_DB_PORT="3306"
export VIRTUALIZOR_DB_USER="virtualizor"
export VIRTUALIZOR_DB_PASS="your_secure_password"
export VIRTUALIZOR_DB_NAME="virtualizor"
export VIRTUALIZOR_BACKUP_DIR="/var/backups/virtualizor-vps-cleaner"
export VIRTUALIZOR_LOG_DIR="/var/log/virtualizor-vps-cleaner"
```

---

## Interactive Terminal Mode

Running the tool without arguments launches the interactive ANSI menu:

```
╔══════════════════════════════════════════════════════════════════════════════╗
║                       VIRTUALIZOR VPS CLEANUP MANAGER                        ║
║       Safe Orphaned Database Record Cleanup for Retired Infrastructure       ║
╚══════════════════════════════════════════════════════════════════════════════╝

 Database: virtualizor  [MyISAM Detected - Strict Locking Enabled]
 Registered VPS: 142 | Server Nodes: 6 | Bound IPs: 156
──────────────────────────────────────────────────────────────────────────────

─── MAIN MENU ───
  [1] List all VPS instances (paginated table)
  [2] Search VPS by ID, name or IP
  [3] View detailed VPS information
  [4] Remove a selected VPS (7-Step Guarded Cleanup)
  [5] View database backup history
  [6] View cleanup audit history
  [7] Refresh database and schema information
  [0] Exit

 ? Select an option [1]: 
```

### Paginated Listing
Displays clean tables formatted for standard 80-column and widescreen SSH terminals:

```
┌──────┬──────────┬────────────────────────┬──────────────────────────┬───────────┬──────────────────────┬──────────────────────┬────────────┐
│ ID   │ VPS Name │ Hostname               │ IP Address(es)           │ Server ID │ Server Name          │ Disk Summary         │ Status     │
├──────┼──────────┼────────────────────────┼──────────────────────────┼───────────┼──────────────────────┼──────────────────────┼────────────┤
│ 101  │ v1001    │ old-vm1.example.com    │ 198.51.100.101           │ 10        │ Retired-Dedicated-A  │ 2 disk(s) [qcow2]    │ Offline/Unk│
│ 102  │ v1002    │ live-client.example.com│ 198.51.100.102           │ 20        │ Active-Customer-B    │ 1 disk(s) [qcow2]    │ Active     │
│ 103  │ v1003    │ bare-vm.example.com    │ None                     │ 10        │ Retired-Dedicated-A  │ 0 disks              │ Offline/Unk│
└──────┴──────────┴────────────────────────┴──────────────────────────┴───────────┴──────────────────────┴──────────────────────┴────────────┘
```

---

## Command-Line Mode & Flags

The application can also be operated non-interactively using command-line arguments.

> **Guarantee:** Commands with listing or inspection arguments (`--list`, `--search`, `--inspect`, `--schema`) are **guaranteed read-only** and will never modify database records.

### 1. Listing VPS Instances
```bash
# List first page (default 20 per page)
php virtualizor-vps-cleaner.php --list

# Custom page and limit
php virtualizor-vps-cleaner.php --list --page 2 --limit 10

# Filter listing by Virtualizor Server ID (e.g. node 10)
php virtualizor-vps-cleaner.php --list --server 10
```

### 2. Searching
Search across VPS database ID, VPS name, hostname, or IP address:
```bash
php virtualizor-vps-cleaner.php --search 198.51.100.101
php virtualizor-vps-cleaner.php --search old-vm1
```

### 3. Detailed Inspection (Read-Only)
Inspect full metadata, disk records, IP reservations, server node reachability, and task history:
```bash
php virtualizor-vps-cleaner.php --inspect 101
```

### 4. Dry-Run Simulation
Simulate the cleanup workflow without modifying the database or creating a backup:
```bash
php virtualizor-vps-cleaner.php --dry-run --delete 101
```

### 5. Guarded Cleanup Execution
Trigger the full 7-step guarded deletion workflow for a specific VPS:
```bash
php virtualizor-vps-cleaner.php --delete 101
```

### 6. View Backup and Audit History
```bash
# View all pre-clean backups
php virtualizor-vps-cleaner.php --backups

# View structured audit trail
php virtualizor-vps-cleaner.php --history

# Check database tables and MyISAM status
php virtualizor-vps-cleaner.php --schema
```

### 7. Scripting & Automation Flags
```bash
# Disable ANSI color escape codes (ideal for piping or cron)
php virtualizor-vps-cleaner.php --list --no-ansi
```

---

## Step-by-Step Disaster Recovery

Every cleanup operation creates a verified pre-deletion backup. The exact absolute path is printed on screen during execution and logged to the audit log.

### To Restore from a Backup:

```bash
# Locate your pre-clean backup file:
ls -lah /var/backups/virtualizor-vps-cleaner/

# Restore using standard MySQL/MariaDB client:
mysql -h localhost -u root -p virtualizor < /var/backups/virtualizor-vps-cleaner/virtualizor_preclean_vps101_20260928_143000.sql
```

---

## Audit Logging & Backup Retention

### Log Locations
- **Human-Readable Log**: `/var/log/virtualizor-vps-cleaner/vps-cleaner.log`
- **Machine-Readable JSONL Audit**: `/var/log/virtualizor-vps-cleaner/vps-cleaner.audit.jsonl`

### Sample JSON Audit Entry
```json
{
  "timestamp": "2026-09-28 23:15:00",
  "action": "VPS_DELETED",
  "operator": "root",
  "hostname": "master.virtualizor.local",
  "vpsid": 101,
  "vps_name": "v1001",
  "uuid": "abcdef-1234-5678-90ab",
  "serid": 10,
  "unlinked_ips": 1,
  "unlinked_disks": 2,
  "verified_deleted": true,
  "backup_file": "/var/backups/virtualizor-vps-cleaner/virtualizor_preclean_vps101_20260928_231458.sql"
}
```

### Backup Retention Policy
Backups are created with permissions `0600` (readable and writable only by the owner). **The utility will NEVER delete or purge backups automatically without explicit administrator consent.**

---

## Automated Testing & Quality Assurance

The codebase includes a comprehensive, zero-dependency automated test suite that executes using disposable in-memory and temporary SQLite databases.

### Running Tests (No Composer Required)
```bash
php tests/run_tests.php
```

### Running Tests with Composer / PHPUnit
```bash
composer test
# or
./vendor/bin/phpunit
```

### Tested Scenarios
- [x] Virtualizor `universal.php` parsing with `$globals` and top-level variables.
- [x] Database connection ping, PDO options, and strict credential masking.
- [x] Table locking (`LOCK TABLES`) and unlocking in `finally` blocks.
- [x] Schema inspection and dynamic column resolution.
- [x] MyISAM storage engine detection and warnings.
- [x] VPS listing, pagination, and multi-field searching.
- [x] Relational hydration of disks, assigned IPs, servers, and background tasks.
- [x] Safety validation blocking deletion when active tasks exist.
- [x] Dry-run preview accuracy ensuring zero database mutations.
- [x] Race condition detection when record is altered between inspection and deletion.
- [x] Guarded cleanup verifying target removal, disk metadata cleanup, and IP unlinking.
- [x] Partial MyISAM failure simulation ensuring table locks release cleanly.
- [x] Backup creation, zero-byte validation, and permissions enforcement.
- [x] Structured audit logging with password and API token redaction.
- [x] ANSI terminal escape codes, visual length calculation, and table formatting.
- [x] CLI flag dispatching and non-destructive flag safety guarantees.

---

## Project Directory Layout

```
virtualizor-vps-cleaner/
├── virtualizor-vps-cleaner.php    # Main executable entry point
├── src/
│   ├── Autoloader.php             # Zero-dependency PSR-4 autoloader
│   ├── Config/
│   │   └── VirtualizorConfig.php  # universal.php parser & credential protector
│   ├── Database/
│   │   ├── Connection.php         # PDO connection & MyISAM table lock manager
│   │   └── SchemaInspector.php    # Dynamic schema & MyISAM detector
│   ├── Models/
│   │   ├── Vps.php                # VPS entity model with relational links
│   │   ├── Disk.php               # Disk metadata model
│   │   ├── IpAddress.php          # IP reservation model
│   │   ├── Server.php             # Node status model
│   │   └── Task.php               # Task lock model
│   ├── Services/
│   │   ├── VpsService.php         # Listing, search, and pagination
│   │   ├── CleanupService.php     # 7-step guarded cleanup & dry-run engine
│   │   ├── BackupService.php      # Consistent mysqldump & verification
│   │   └── VirtualizorApiService.php # API & slave reachability inspector
│   ├── Logging/
│   │   └── Logger.php             # Dual structured audit logging
│   ├── Terminal/
│   │   ├── Ansi.php               # ANSI styling, colors, and TTY detection
│   │   ├── Table.php              # Formatted ASCII/Unicode responsive tables
│   │   └── Prompt.php             # Menus, confirmation phrases, and alert dialogs
│   └── Cli/
│       └── App.php                # CLI dispatcher and interactive menu loop
├── tests/
│   ├── TestCase.php               # Base test case with disposable DB fixtures
│   ├── ConfigTest.php             # Configuration test suite
│   ├── DatabaseTest.php           # Database & lock test suite
│   ├── SchemaInspectorTest.php    # Schema inspector test suite
│   ├── VpsServiceTest.php         # Query & search test suite
│   ├── CleanupServiceTest.php     # Guarded cleanup & safety test suite
│   ├── BackupServiceTest.php      # Backup & validation test suite
│   ├── LoggerTest.php             # Audit logging test suite
│   ├── TerminalTest.php           # Terminal & table test suite
│   ├── CliTest.php                # CLI arguments test suite
│   └── run_tests.php              # Standalone zero-dependency test runner
├── composer.json                  # Composer manifest & scripts
├── phpunit.xml                    # PHPUnit configuration
├── CHANGELOG.md                   # Semantic version changelog
├── LICENSE                        # MIT License
└── README.md                      # Complete documentation
```

---

## Contributing & License

Contributions, issues, and feature requests are welcome.

This project is open-source software licensed under the [MIT License](LICENSE).

**Author**: Mudit Kumar Prajapati  
**Repository**: [https://github.com/muditkumarprajapati/virtualizor-vps-cleaner](https://github.com/muditkumarprajapati/virtualizor-vps-cleaner)
