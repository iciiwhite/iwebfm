<div align="center">

# iWebFM

**A single-file, self-hosted web file manager with pattern-gesture unlock.**

[![Version](https://img.shields.io/badge/version-1.0-blue.svg)](#)
[![PHP](https://img.shields.io/badge/php-%3E%3D7.4-8892BF.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![Size](https://img.shields.io/badge/size-one%20file-orange.svg)](#)
[![PRs Welcome](https://img.shields.io/badge/PRs-welcome-brightgreen.svg)](#contributing)

[Features](#features) · [Installation](#installation) · [Usage](#usage) · [Security](#security) · [Configuration](#configuration) · [Roadmap](#roadmap) · [Contact](#contact)

</div>

---

## Overview

**iWebFM** is a modern, self-contained web file manager distributed as a **single PHP file**. Drop it on any PHP-capable host, draw a pattern to set your unlock gesture, and you immediately get a full-featured file browser with built-in viewers, editors, and utilities — no database, no Composer, no npm, no build step.

It is designed to feel native on both desktop and mobile, with a responsive interface that scales from a phone screen to a wide monitor. Every feature — from the hex editor to the archive browser — is written to run entirely in the browser with a thin PHP backend.

> **Author:** Icii · **Contact:** mail.iciiwhite@gmail.com · **Repository:** [github.com/iciiwhite/iwebfm](https://github.com/iciiwhite/iwebfm)

---

## Features

### 🔐 Authentication
- **Pattern gesture unlock** — a 3×3 dot grid where you draw a shape. The digit sequence is hashed with **Argon2id** and verified server-side.
- No usernames, no passwords, no passcodes. Just a pattern.
- Session-based access with `session_regenerate_id()` on login.
- Timing-safe verification with a deliberate delay on failure.

### 📁 File Management
- Browse, upload, download, rename, delete, copy, move files and folders.
- **List and grid layouts** with instant switching.
- **Sort by** name (A–Z / Z–A), date (newest / oldest), or size.
- **Filter chips** for Documents, Images, Videos, Audio, and Others.
- **Search** across the entire tree with live results.
- **Multi-select** via long-press, with a bulk action sheet: Archive, Download, Copy, Move, Delete, Hide, Bulk rename.
- **Hide** any file by prefixing it with a dot; a "Show hidden files" toggle reveals them.
- Create empty files, new folders, or upload in bulk.

### 🎨 Themes
- **Light** (default), **Dark**, and **Eye Care** (warm sepia) — switch instantly from Settings.
- Persisted to `localStorage`.
- All viewers, dialogs, and modals respect the active theme.

### 🖼️ Viewers & Editors

| Viewer / Editor | Handles | Notes |
|---|---|---|
| **Image viewer** | jpg, png, gif, webp, bmp, svg, avif, heic… | Fitted to viewport, single **Flip** button |
| **Video player** | mp4, webm, mov, mkv… | Custom controls, seek bar, volume, fullscreen |
| **Audio player** | mp3, wav, ogg, flac, m4a, opus… | Custom controls with live frequency visualizer |
| **Code editor** | 200+ text and code extensions | Ace Editor, GitHub Dark theme, automatic syntax mode, live autocompletion, snippets |
| **Hex editor** | Any file up to 8 MB | Paginated, byte-selection, search (Offset / Hash / Ascii), fill selection, copy block |
| **Table editor** | xlsx, csv, tsv | In-browser grid with add row/column, saves back to original format |
| **Archive browser** | zip | Lists contents, previews any inner file, supports password-protected archives |
| **PDF viewer** | pdf | Inline iframe preview |
| **Download fallback** | Any binary | Clean download prompt with file size |

### 🧠 Smart Text Detection
- **Encoding detection** for UTF-8, UTF-16LE/BE, Windows-1252, ISO-8859-1, Shift-JIS, EUC-JP, GB18030, Big5, KOI8-R.
- Files with known text extensions (`.html`, `.js`, `.xml`, `.php`, `.md`, `.json`, `.yml`, …) always open in the code editor, even if they contain unusual bytes.
- Extension-less files are inspected: text opens in the editor, binary offers a download.

### 📊 Storage Analysis
- Home dashboard shows **used**, **free**, and **total quota** with a segmented usage bar per category.
- **Configurable quota** (defaults to 5 GB) via Settings.
- Category cards show file count and aggregate size per type.
- Two top-level cards only: storage summary and category breakdown.

### ⚡ Pretty URLs
- `.htaccess` is auto-generated on first unlock with `# BEGIN FMAccess` / `# END FMAccess` markers so your existing rules are preserved.
- Access folders as `yoursite.com/fm/Documents/2024` instead of `?path=Documents/2024`.
- Hash-based routing for non-folder pages: `#files`, `#images`, `#videos`, `#audio`, `#archives`, `#documents`, `#settings`, `#about`.
- The browser **Back** button works correctly across all navigation.

### 📱 Responsive
- Fully usable from a 320px phone to a 4K desktop.
- Touch-first hex editor with adaptive column counts (8 on mobile, 16 on desktop).
- Long-press multi-select on touch; right-click on desktop.

---

## Installation

### Requirements

- **PHP 7.4 or newer** (8.x recommended)
- Extensions: `mbstring`, `json` (bundled), and `zip` (for archive browsing and xlsx support)
- Any web server — Apache, Nginx, LiteSpeed, or PHP's built-in server
- Write permission on the directory where you place `iwebfm.php`

### Quick Start

1. Download `iwebfm.php`.
2. Upload it to any directory on your web host, e.g. `public_html/fm/iwebfm.php`.
3. Visit `https://yoursite.com/fm/iwebfm.php`.
4. Draw a pattern (minimum 4 dots), then draw it again to confirm.
5. You're in. The app will create a `.vault.php` file next to itself to store the Argon2id hash — **keep this file backed up**; losing it means resetting your pattern.

### First-run files (auto-created)

| File | Purpose |
|---|---|
| `.vault.php` | Argon2id hash of your pattern |
| `.settings.php` | Quota and other user preferences |
| `.htaccess` | Pretty URL rules (only if Apache is detected) |

All three are hidden from listings and blocked from direct access.

### Nginx note

If you use Nginx, add an equivalent `location` block to strip `.php` from URLs and route requests. Alternatively, disable pretty URLs and use hash routes (`#files`) directly.

---

## Usage

### The Pattern

- Draw a pattern on the 3×3 grid. **Minimum 4 dots.**
- Move your finger or cursor across dots to connect them; the sequence is captured automatically.
- Release to submit. A wrong pattern shakes and clears after a brief delay.
- The pattern is **not** stored — only its Argon2id hash.

### The Home Page

Two cards only:

1. **Storage** — free space, used space, quota, per-category color bar. Tap to open the file list.
2. **Categories & Utilities** — Images, Videos, Audio, Archives, Documents, then Settings and About.

### The File List

Opened from the storage card. Shows folders first (A–Z), then files (A–Z by default). A sticky header gives you search, sort, back, and lock. The floating **+** button offers New folder, Empty file, and Upload.

### Bulk Actions

Long-press any item to enter selection mode. The search bar becomes a blue pill showing the selection count. Tap the **ellipsis** (where the lock icon was) to open:

- Archive — pack the selection into a `.zip` in the current folder
- Download — stream the selection as a `.zip`
- Copy / Move — send the selection to any folder by relative path
- Delete — permanent removal
- Hide — prefix with `.` so it's excluded from listings
- Bulk rename — apply `pattern_1`, `pattern_2`, … to the selection

### The Code Editor

Opens read-only with a GitHub Dark theme. The **Edit** button in the header unlocks typing (no page reload). Once you make a change, **Save** appears. Syntax highlighting is automatic based on file extension; autocompletion and snippets are enabled.

### The Hex Editor

Opens with a page of 64 KB. Search (top-right) offers three modes:

- **Offset** — jump to a hexadecimal or decimal address
- **Hash** — find a byte sequence (e.g. `48 65 6C 6C 6F`)
- **Ascii** — find a text string

Select bytes by tapping and dragging. Right-click (or long-press on mobile) for:

- **Fill Selection** — overwrite the range with a 2-hex-digit value
- **Copy Block** — copy the selected bytes as a spaced hex string

Save writes raw bytes back to disk.

### The Table Editor

Opens `.xlsx`, `.csv`, and `.tsv`. Edit cells inline, add rows or columns, and save. CSV round-trips losslessly. XLSX is regenerated on each save.

---

## Security

- **Argon2id** password hashing (falls back to the platform default if Argon2id is unavailable).
- Deliberate **450 ms delay** on failed unlock attempts.
- `session_regenerate_id(true)` on successful authentication.
- Path traversal is blocked at multiple layers: `safeRel()`, `joinRoot()`, and `realpath()` containment checks.
- The app file itself, `.vault.php`, `.settings.php`, and any dot-prefixed file are **excluded from listings and from the raw download endpoint**.
- HTML, SVG, XML, PHP, and other dangerous extensions are served as `application/octet-stream` with `Content-Disposition: attachment` — never as live content.
- `X-Content-Type-Options: nosniff` is set on JSON responses.

### Reporting a Vulnerability

Please **do not** file public issues for security problems. Email **mail.iciiwhite@gmail.com** with a description and, if possible, a minimal reproduction.

---

## Configuration

### Quota

Settings → **Disk quota**. Enter a value in GB. Stored in `.settings.php`. Default: 5 GB.

### Changing the Pattern

Settings → **Change pattern**. This deletes `.vault.php` and logs you out; on the next page load the app asks you to draw a new pattern.

### Hiding the App File

Rename `iwebfm.php` to something less guessable (e.g. `cpanel-8f3a.php`). The `.htaccess` block and self-detection update automatically on the next unlock.

---

## Tech Stack

- **Backend:** vanilla PHP (no framework, no Composer)
- **Frontend:** vanilla JavaScript, Tailwind CSS (CDN), Material Symbols (CDN)
- **Editor:** Ace Editor 1.32.6 (CDN), GitHub Dark theme
- **Storage:** filesystem only; a single JSON file for settings
- **Database:** none

Everything else — the hex viewer, the table grid, the media players, the image viewer, the archive browser — is hand-written and dependency-free.

---

## Roadmap

- [ ] Drag-and-drop upload
- [ ] Shared folder links with expiring tokens
- [ ] Text-diff viewer for versioned files
- [ ] Built-in image resizer
- [ ] Multi-user mode with per-user roots
- [ ] WebDAV endpoint
- [ ] S3 / Backblaze B2 backends

Vote or suggest features via [GitHub Issues](https://github.com/iciiwhite/iwebfm/issues).

---

## Contributing

Pull requests are welcome. For major changes, please open an issue first to discuss what you would like to change.

1. Fork the repo.
2. Create a branch: `git checkout -b feature/your-feature`.
3. Keep the single-file constraint intact.
4. Test on mobile and desktop, in all three themes.
5. Submit a PR with a clear description and screenshots.

---

## License

Released under the **MIT License**. See `LICENSE` for details.

---

## Contact

- **Author:** Icii
- **Email:** [mail.iciiwhite@gmail.com](mailto:mail.iciiwhite@gmail.com)
- **Repository:** [github.com/iciiwhite/iwebfm](https://github.com/iciiwhite/iwebfm)

---

<div align="center">

**iWebFM** · v1.0 · Released Tomorrow

*Built with care, one file at a time.*

</div>