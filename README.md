# Formular-Speicher (CF7 → SQLite)

A lightweight WordPress plugin that stores Contact Form 7 submissions in a local SQLite database — one clean table per form. No external database, no bloat.

## Features

- **Automatic capture** — saves every CF7 submission automatically; disable per form in the CF7 editor
- **Per-form tabs** — each form gets its own tab with a submission count and "new" badge
- **Server-side filtering & search** — full-text search across all fields, dropdown filters per column, persistent across pagination
- **Status management** — built-in statuses (Neu, Bestätigt, In Arbeit, Erledigt, Storniert) with color-coded badges; change inline per row
- **Column manager** — show/hide columns and reorder via drag & drop; settings are saved globally per form in the database
- **CSV export** — exports the current view (respects active filters)
- **Server-side pagination** — 100 rows per page
- **Column sorting** — click any header to sort ascending/descending within the current page
- **Access control** — custom `cf7_orders_viewer` role + `fs_view_submissions` capability; administrators get access automatically
- **Clean uninstall** — removes the SQLite file, custom role, and capability on plugin deletion

## Requirements

- WordPress 5.0+
- Contact Form 7
- PHP 8.0+ with the `sqlite3` extension enabled

## Installation

1. Download or clone this repository into `wp-content/plugins/formular-speicher`
2. Activate the plugin in **Plugins → Installed Plugins**
3. Done — submissions are captured immediately for all active CF7 forms

The SQLite database is created automatically at `wp-content/uploads/formular-speicher/submissions.sqlite` and is protected from direct web access via `.htaccess`.

## Usage

### Viewing submissions

Go to **Formular-Daten** in the WordPress admin menu. Each form appears as a tab.

### Disabling capture for a specific form

Open the form in the CF7 editor and uncheck **Submissions speichern** in the **SQLite-Speicher** panel.

### Managing columns

Click **☰ Spalten** in the toolbar to open the column manager:
- Drag the **⠿** handle to reorder columns
- Click **👁** to show or hide a column
- Hidden columns appear as pills below the toolbar — click to restore

Settings are saved globally (all users see the same layout).

### Exporting

Click **⬇ CSV** to download a CSV of the current view. Active filters are applied to the export.

## Data storage

```
wp-content/uploads/formular-speicher/
├── submissions.sqlite   # all form data
├── .htaccess            # blocks direct web access
└── index.php            # silence
```

Each CF7 form gets its own table named after the form title (e.g. `kontaktformular`). A `_forms` meta table maps form IDs to table names, and a `_settings` table persists column configuration.

## Running tests

The test suite runs standalone — no WordPress installation required:

```bash
php tests/run.php
```

## License

GPL-2.0 — see [LICENSE](LICENSE).
