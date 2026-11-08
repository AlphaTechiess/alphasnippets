# Alpha Snippets

<p align="center">
  <img src="assets/images/logo.png" alt="Alpha Snippets Logo" width="80" height="80">
</p>

<p align="center">
  <strong>Lightweight, high-performance PHP, HTML, CSS, and JavaScript snippet manager for WordPress.</strong><br>
  <em>A clean, bloat-free, completely open-source alternative to WPCode with zero paywalls.</em>
</p>

<p align="center">
  <a href="#features">Features</a> •
  <a href="#installation">Installation</a> •
  <a href="#usage-guide">Usage Guide</a> •
  <a href="#safe-mode">Safe Mode</a> •
  <a href="#architecture--performance">Architecture</a> •
  <a href="#license">License</a>
</p>

---

## Overview

**Alpha Snippets** allows you to execute custom PHP functions, inject HTML + PHP content blocks, add custom CSS stylesheets, and run site-wide or admin-specific JavaScript without ever touching your theme's `functions.php` file or installing bloated code plugins.

Built with an ultra-lightweight architecture (the entire plugin is under 80 KB), Alpha Snippets uses an **atomic file-based storage engine with opcode caching (OPcache)** rather than dangerous runtime `eval()` statements or heavy database queries.

---

## Features

- **Zero Paywalls & No Bloat:** Every feature is available out of the box with unlimited snippets, no telemetry, no upsells, and no tracking.
- **Multi-Language Support:**
  - **PHP Functions:** Run custom WordPress hooks, filters, and business logic.
  - **PHP + HTML Content:** Output dynamic templated HTML anywhere via hooks or shortcodes.
  - **CSS Styles:** Inject global, frontend, or admin custom styles.
  - **JavaScript:** Run scripts in the header or footer of your frontend and/or admin dashboard.
- **CodeMirror Editor with Virtualized Fallback:**
  - Full syntax highlighting, line numbering, auto-indentation, and bracket matching via WordPress core CodeMirror.
  - DOM-virtualized rendering guarantees smooth 60 FPS scrolling with zero window freezing, even when pasting 50,000+ lines of code.
- **Sticky Publish Controls & Keyboard Shortcuts:**
  - Sticky header keeps the **Publish/Update** button and **Active/Inactive** status toggle visible at all times.
  - Use `Ctrl + S` (Windows/Linux) or `Cmd + S` (macOS) to save snippets instantly without losing focus.
  - Secondary save button conveniently placed in the settings sidebar.
- **Pre-Save PHP Syntax Linting:**
  - Runs safe token-based syntax validation before writing PHP to disk, preventing syntax errors from crashing your website.
- **Safe Mode Disaster Recovery:**
  - Recover instantly from runtime PHP errors via the `?alphasnippets_safe_mode=1` URL parameter or `ALPHA_SNIPPETS_SAFE_MODE` constant.
- **OPcache Cache Invalidation:**
  - Automatically invalidates PHP OPcache bytecode upon saving, toggling, or deleting snippets, ensuring instant execution without stale caching.
- **Infinite Recursion Protection:**
  - Recursion guard prevents memory exhaustion if a content snippet or shortcode references itself.
- **1-Click Snippet Duplication:**
  - Easily clone existing snippets into inactive drafts for testing and refinement.
- **Real-Time Client-Side Search:**
  - Filter and locate snippets instantly in the dashboard with zero page reloads.
- **1-Click Shortcode Copy:**
  - Copy shortcode tags (`[alpha_snippet id="..."]`) directly to your clipboard with visual confirmation.
- **Multisite Security:**
  - Enforces Super Administrator authorization on WordPress multisite networks.

---

## Requirements

| Requirement | Minimum Version | Recommended |
| :--- | :--- | :--- |
| **WordPress** | 6.0 or higher | Latest stable |
| **PHP** | 7.4 or higher | 8.1 / 8.2 / 8.3+ |
| **Web Server** | Apache, Nginx, LiteSpeed, or IIS | Any |

---

## Installation

### Manual Installation via ZIP
1. Download or archive the `alphasnippets` folder into a `.zip` archive.
2. In your WordPress admin dashboard, navigate to **Plugins → Add New → Upload Plugin**.
3. Choose the zip file and click **Install Now**.
4. Click **Activate Plugin**.

### Git / Direct Upload
1. Clone or copy the `alphasnippets` directory into your WordPress plugins folder:
   ```bash
   cd wp-content/plugins/
   git clone https://github.com/AlphaTechiess/alphasnippets.git alphasnippets
   ```
2. Navigate to **Plugins → Installed Plugins** in your WordPress dashboard and activate **Alpha Snippets**.

---

## Usage Guide

### 1. Creating a Snippet
1. In your WordPress admin menu, click on **Alpha Snippets → New Snippet**.
2. Select your snippet type tab:
   - **Functions (PHP)**
   - **Content (PHP + HTML)**
   - **Styles (CSS)**
   - **Scripts (JS)**
3. Give your snippet a descriptive name.
4. Enter your code in the code panel.
5. Set the execution priority (default is `10`; lower numbers run earlier).
6. Choose the insertion location in the **Where to run?** dropdown.
7. Toggle the switch to **Active** when ready, and click **Publish** (or press `Ctrl + S` / `Cmd + S`).

### 2. Execution Locations

#### PHP Functions & CSS:
- **Run Everywhere:** Executes on both frontend and the WordPress admin dashboard (`/wp-admin/`).
- **Frontend Only:** Executes only on public-facing site requests.
- **Admin Only:** Executes only within the WordPress admin dashboard.

#### PHP + HTML Content:
- **Shortcode:** Only renders when inserted into a post, page, or widget using `[alpha_snippet id="YOUR-SNIPPET-ID"]`.
- **Site Wide Header:** Injected directly into the document `<head>` via `wp_head`.
- **Site Wide Body Open:** Injected immediately after the opening `<body>` tag via `wp_body_open`.
- **Site Wide Footer:** Injected before the closing `</body>` tag via `wp_footer`.
- **Before Content:** Automatically prepended to single post/page content.
- **After Content:** Automatically appended to single post/page content.

#### JavaScript:
- **Site Wide Header:** Enqueued in the `<head>` of all frontend pages.
- **Site Wide Footer:** Enqueued before the closing `</body>` of all frontend pages.
- **Admin Area Header:** Enqueued in `<head>` within `/wp-admin/`.
- **Admin Area Footer:** Enqueued before closing `</body>` within `/wp-admin/`.

---

## Safe Mode

If you accidentally activate a PHP snippet that triggers a fatal runtime error, Alpha Snippets includes an emergency **Safe Mode** that pauses all snippet execution without requiring database access or FTP file deletion.

### How to Activate Safe Mode:
1. Append `?alphasnippets_safe_mode=1` to your WordPress admin URL:
   ```text
   https://example.com/wp-admin/admin.php?page=alphasnippets&alphasnippets_safe_mode=1
   ```
2. Alternatively, add this line to your `wp-config.php`:
   ```php
   define( 'ALPHA_SNIPPETS_SAFE_MODE', true );
   ```
3. While Safe Mode is active, all snippets are paused. A warning banner will appear in your dashboard allowing you to safely edit, deactivate, or delete the offending snippet.
4. To resume normal execution, remove the parameter from your URL or remove the constant from `wp-config.php`.

---

## Architecture & Performance

Alpha Snippets is engineered specifically to avoid the performance bottlenecks and security vulnerabilities common in other snippet plugins:

- **No `eval()`:** Alpha Snippets writes snippets to isolated PHP files in `wp-content/uploads/alphasnippets/` and includes them via `include_once`.
- **Full OPcache Acceleration:** Because snippets exist as standalone PHP files, the PHP engine compiles them into bytecode once and caches them in shared memory. Subsequent requests run at native speed.
- **Fast Status Toggling:** Enabling or disabling a snippet modifies only the metadata manifest array. The actual code file on disk is never needlessly rewritten, avoiding unnecessary disk I/O.
- **Directory Security:** The snippet directory is automatically protected with `.htaccess`, `web.config`, `index.html`, and `index.php` files to deny direct web access.

---

## Project Structure

```text
alphasnippets/
├── alphasnippets.php       # Main plugin bootstrap file
├── uninstall.php           # Cleanup handler on plugin deletion
├── README.md               # Documentation and usage guide
├── assets/
│   ├── admin.min.css       # Production minified stylesheet
│   ├── admin.min.js        # Production minified JavaScript engine
│   └── images/
│       ├── logo.png        # Plugin logo
│       ├── bin.svg         # Trash icon
│       ├── pencil.svg      # Edit icon
│       └── copy.svg        # Duplicate / copy icon
└── includes/
    └── core.php            # Core execution engine, admin UI & REST/AJAX endpoints
```

---

## License

This project is licensed under the **GNU General Public License v2.0 or later (GPL-2.0-or-later)**.

See the [GNU General Public License](https://www.gnu.org/licenses/gpl-2.0.html) for full details.

---

## Author & Support

Developed by **[Alpha Techies](https://github.com/AlphaTechiess)**.  
If you find this plugin helpful, consider giving it a ⭐ on [GitHub](https://github.com/AlphaTechiess/alphasnippets)!
