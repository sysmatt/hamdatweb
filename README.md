> [!WARNING]
> **This project is highly experimental and a work in progress.**
> It has had very limited testing, may contain bugs, and the interface, configuration, and
> deployment details are subject to change without notice. Use at your own risk. Not recommended
> for any production or public-facing deployment in its current state.

---

# hamdatweb

A PHP web front-end for the [hamdat](../hamdat/) FCC Amateur Radio license database CLI tool.
Exposes all hamdat search options through a browser UI, renders results as an HTML table,
and allows downloading query results in CSV, JSON, or HTML format. Every query displays
the equivalent `hamdat` CLI command so users can reproduce or adapt results locally.

Protected by [simplewebauth](../simplewebauth/) session authentication.

---

## How it works

`hamdatweb` is a single PHP file (`index.php`) that:

1. Authenticates the user via `simplewebauth` before rendering anything.
2. Presents a search form exposing all hamdat query options.
3. Assembles a `hamdat` CLI invocation from the submitted form fields, sanitizing all inputs
   with `escapeshellarg()`.
4. Executes hamdat as a subprocess and captures its output.
5. Displays the assembled CLI command prominently after every query — useful for learning
   hamdat syntax or reproducing results on a local installation.
6. For multi-record searches, renders results as a scrollable HTML table.
7. For single-callsign lookups (`--call`), renders the formatted profile output in a `<pre>` block.
8. Allows downloading the same query result in CSV, JSON, or HTML by re-running the query
   with the appropriate hamdat output flag.

The web server never accesses the SQLite database directly — all data access goes through
the `hamdat` binary.

---

## Repository layout

```
hamdatweb/                        ← this repo, cloned into docroot
├── index.php                     ← the entire application
├── hamdatweb-config.php.example  ← config template; copy to docroot parent
└── .gitignore
```

The live configuration file lives **outside** the repository, in the docroot parent directory,
so that shallow clones of this repo never overwrite it:

```
/var/www/html/                    ← docroot
├── hamdatweb/                    ← shallow clone of this repo
│   ├── index.php
│   └── hamdatweb-config.php.example
├── hamdatweb-config.php          ← live config  (you create this; NOT in the repo)
├── simplewebauth/                ← simplewebauth clone/install
└── ...
```

---

## Prerequisites

| Requirement | Notes |
|---|---|
| PHP 8.0+ | With `exec()` enabled (not disabled in `php.ini`) |
| Apache or Nginx | With PHP-FPM or `mod_php` |
| [hamdat](../hamdat/) | Installed and accessible to the web server process |
| [simplewebauth](../simplewebauth/) | Deployed as a sibling directory in the docroot |
| hamdat database | Built with `hamdat --pull`; readable by the web server process |

---

## Installation

### 1. Clone the repository

Clone into a subdirectory of your web root named `hamdatweb`:

```bash
cd /var/www/html
git clone --depth 1 <repo-url> hamdatweb
```

### 2. Create the configuration file

Copy the example template to the docroot (one level above the clone):

```bash
cp /var/www/html/hamdatweb/hamdatweb-config.php.example \
   /var/www/html/hamdatweb-config.php
```

Edit `/var/www/html/hamdatweb-config.php` and set the correct paths:

```php
<?php
define('HAMDAT_BIN', '/usr/local/bin/hamdat');         // path to hamdat binary
define('HAMDAT_DB',  '/home/ubuntu/.hamdat/hamdat.db'); // path to hamdat database
// define('HAMDAT_TEMP_DIR', '/tmp');                  // optional; defaults to sys_get_temp_dir()
```

> **Why the config lives in the parent directory:** placing it outside the repo means
> `git pull` or a fresh shallow clone will never overwrite your server-specific settings.

### 3. Ensure simplewebauth is deployed

`simplewebauth` must be present as a sibling directory of `hamdatweb` in the docroot:

```
/var/www/html/simplewebauth/
/var/www/html/hamdatweb/
```

See the [simplewebauth documentation](../simplewebauth/README.md) for setup instructions,
including adding users with `authctl add <username>`.

### 4. Set permissions

The web server process (e.g. `www-data`) must be able to:

- **Execute** the `hamdat` binary
- **Read** the hamdat SQLite database file (`hamdat.db`) and its parent directory
- **Write** to the temp directory defined by `HAMDAT_TEMP_DIR` (used briefly during downloads)

```bash
# Example: allow www-data to read the hamdat database
chown ubuntu:www-data /home/ubuntu/.hamdat/hamdat.db
chmod 640 /home/ubuntu/.hamdat/hamdat.db
```

### 5. Configure your web server

#### Apache

Ensure `AllowOverride All` is set for the docroot (required by simplewebauth's `.htaccess`).
No additional Apache configuration is needed for `hamdatweb` itself.

#### Nginx

Follow the Nginx configuration instructions in the simplewebauth repo. `hamdatweb` itself
requires no additional Nginx directives beyond standard PHP-FPM handling.

### 6. Verify

Navigate to `https://your-server/hamdatweb/` in a browser. You should be redirected to
the simplewebauth login page. After logging in, the search form will be displayed.

---

## Configuration reference

All configuration is in `hamdatweb-config.php` (located in the docroot, not in this repo).

| Constant | Required | Default | Description |
|---|---|---|---|
| `HAMDAT_BIN` | No | `/usr/local/bin/hamdat` | Full path to the `hamdat` executable |
| `HAMDAT_DB` | No | `$HOME/.hamdat/hamdat.db` | Full path to the hamdat SQLite database |
| `HAMDAT_TEMP_DIR` | No | `sys_get_temp_dir()` | Writable temp directory for download file generation |

If `hamdatweb-config.php` is missing, the app will display an error with instructions
rather than a blank page or PHP warning.

---

## Search options

### Single callsign lookup

Looks up one specific callsign and returns its full FCC profile.

| UI field | hamdat flag | Notes |
|---|---|---|
| Callsign | `--call` | Exact match, case-insensitive |
| Prior licensees (compact) | `--history` | Appends a compact table of all past holders of the callsign |
| Prior licensees (full) | `--full-history` | Appends full formatted profiles for every prior licensee |

Results are displayed as preformatted text, preserving hamdat's profile layout.

> **Single callsign vs. search:** if the Callsign field contains a value, it takes priority
> and all multi-record search fields are ignored.

### Multi-record search

Returns a table of all active licensees matching the given filters. All filters AND together;
multiple License Class selections OR within themselves.

| UI field | hamdat flag | Notes |
|---|---|---|
| Callsign contains | `--callsearch` | Substring or regex match against callsign |
| Name contains | `--name` | Substring or regex match against licensee name |
| Address contains | `--address` | Substring or regex match against full mailing address |
| Regular expressions | `--regex` | Treats the three text fields above as Python regex patterns |
| License Class | `--class` | Check any combination: T G E A N P |
| Entity Type | `--type` | `individual`, `club`, `races`, `military`, `government` |
| Grant Date | `--grant-date` | See date format reference below |
| Change Date | `--change-date` | See date format reference below |
| ZIP Code | `--zip` | 5-digit US ZIP code |
| Radius Miles | `--radius-miles` | Search radius around ZIP; `0` = exact ZIP only |

#### Date format reference

| Format | Meaning |
|---|---|
| `-30` | Last 30 days |
| `+7` | Next 7 days |
| `2025-06-01` | Exact date |
| `2025-01-01:2025-12-31` | Inclusive date range |
| `since:2025-01-01` | On or after (date included) |
| `after:2025-01-01` | Strictly after (date excluded) |
| `thru:2025-12-31` | On or before (date included) |
| `before:2025-12-31` | Strictly before (date excluded) |

### License class codes

| Code | Class |
|---|---|
| T | Technician |
| G | General |
| E | Extra |
| A | Advanced |
| N | Novice |
| P | Technician Plus |

---

## Downloading results

After a multi-record search renders an HTML table, three download buttons appear in the
results card header:

- **CSV** — comma-separated values; opens in Excel, LibreOffice, etc.
- **JSON** — array of objects; one object per result row
- **HTML** — standalone HTML file with a styled table; same format hamdat produces natively

Clicking a download button re-runs the identical query against hamdat with the corresponding
output flag (`--csv`, `--json`, or `--html`). The assembled CLI command displayed for the
download includes the format flag, so users can see exactly what was run.

Downloads are streamed directly to the browser and the temp file is deleted immediately
after transfer.

---

## The CLI command display

After every query — whether a table view, a callsign profile, or a download — the assembled
`hamdat` command is shown in a dark terminal-style card:

```
hamdat --name "Smith" --class T G --grant-date -30
```

This command is **portable**: it omits server-specific details (`--db` path, temp `--file`
path) and can be pasted directly into a terminal on any machine that has `hamdat` installed
and a local database built. For downloads, the appropriate format flag is included
(e.g. `--csv`).

This feature makes hamdatweb useful as a query builder, not just a search tool.

---

## Updating

Because the config lives outside the repo, updates are safe to pull at any time:

```bash
cd /var/www/html/hamdatweb
git pull
```

Your `hamdatweb-config.php` in the docroot parent will not be touched.

---

## Security notes

- All user-supplied input passed to the shell is sanitized with `escapeshellarg()`.
- Date strings are validated against a strict regex before being used.
- License class and entity type values are validated against a hardcoded whitelist.
- ZIP codes are validated as exactly five digits.
- The web server runs hamdat as a subprocess and never opens the SQLite database directly.
- Session authentication, CSRF protection, and secure cookie flags are handled by
  `simplewebauth`. See that project for details.
- `exec()` must be available in your PHP environment. If your host disables it in
  `php.ini`, this application will not function.

---

## Troubleshooting

**"Missing config" error on first load**
: `hamdatweb-config.php` does not exist in the docroot parent directory. Copy
  `hamdatweb-config.php.example` to `../hamdatweb-config.php` relative to the repo and
  fill in the correct paths.

**Redirected to login page immediately**
: `simplewebauth` is working correctly. Log in with a configured user account. If you
  have no accounts yet, add one with `authctl add <username>` on the server.

**"hamdat error" after submitting a search**
: Check that `HAMDAT_BIN` points to the correct executable, that the `HAMDAT_DB` path
  exists and is readable by the web server process, and that `exec()` is not disabled
  in `php.ini`. Running the displayed CLI command manually as the web server user is a
  useful diagnostic step.

**Download button produces an error**
: The web server process must be able to write to `HAMDAT_TEMP_DIR`. Check directory
  permissions. The default (`sys_get_temp_dir()`, usually `/tmp`) is writable by most
  processes; if you overrode it, verify the path exists and has correct ownership.

**Results appear but the table is empty**
: The query ran successfully but returned zero records. Relax your search criteria.
  If you used `--zip` without `--radius-miles`, only exact ZIP matches are returned.

---

## Related projects

- **[hamdat](../hamdat/)** — the FCC Amateur Radio license database CLI tool that this web UI wraps
- **[simplewebauth](../simplewebauth/)** — the session authentication layer protecting this app
