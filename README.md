# XcelSync — High-Performance Excel & CSV Database Importer

<p align="center">
  <img src="https://img.shields.io/badge/PHP-8.1%2B-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP Version" />
  <img src="https://img.shields.io/badge/OpenSpout-v4-blue?style=for-the-badge" alt="OpenSpout" />
  <img src="https://img.shields.io/badge/Databases-MySQL%20%7C%20SQLite-4479A1?style=for-the-badge&logo=mysql&logoColor=white" alt="Databases" />
  <img src="https://img.shields.io/badge/Design-Glassmorphic%20Dark%20UI-6366f1?style=for-the-badge" alt="UI" />
  <img src="https://img.shields.io/badge/License-MIT-green?style=for-the-badge" alt="License" />
</p>

A lightning-fast, memory-efficient data ingestion engine that streams **millions of rows** from Excel (`.xlsx`), CSV (`.csv`), and OpenDocument Spreadsheet (`.ods`) files directly into **MySQL** or **SQLite** databases with real-time validation, dynamic schema generation, and live progress observability.

---

## 🌟 Key Features

- **⚡ O(1) Constant Memory Streaming**: Powered by `openspout/openspout`, reading files row-by-row without inflating RAM, easily processing datasets exceeding server memory limits.
- **🚀 Turbocharged Batch Transactions**: Performs multi-row chunked inserts (1,000+ rows per batch) wrapped in dedicated database transactions for maximum I/O throughput.
- **🗄️ Multi-Database Support**:
  - **SQLite**: Automatic zero-config setup with Write-Ahead Logging (`WAL`), in-memory temp stores, and optimized PRAGMA caches.
  - **MySQL**: Native support for InnoDB engines, bulk inserts, UTF-8 MB4 charset, and database auto-creation.
- **🧠 Automatic Schema & Type Detection**: Analyzes spreadsheet headers and samples records to automatically suggest column data types (`INTEGER`, `REAL`, `DATE`, `TEXT`).
- **🛡️ Strict Data Validation & Normalization**:
  - **Name Cleaning**: Trims whitespace and collapses multiple spaces.
  - **Mobile Number Validation**: Strips non-digits, normalizes `+91` country codes, validates 10-digit length and starting digits (`6-9`).
  - **Date of Birth Parser**: Automatically resolves Excel serial dates (with 1900 leap-year offset correction), standardizes string date formats (`YYYY-MM-DD`, `DD/MM/YYYY`, etc.) to ISO `YYYY-MM-DD`.
- **⏱️ Asynchronous Background Worker**: Offloads heavy processing to a background PHP CLI process (`ImportWorker.php`), keeping web servers responsive and timeout-free.
- **📊 Real-Time Progress Monitoring**: Polled dashboard reporting live rows processed, rows imported, invalid rows skipped, ingestion speed (`rows/sec`), and estimated time remaining (ETA).
- **🛑 Graceful Cancellation**: Allows cancelling imports midway with automatic database rollback.
- **📝 Streaming Error Export**: Invalid rows and specific rejection reasons stream directly to downloadable CSV reports for instant auditing.
- **🎨 Modern Dark Glassmorphic UI**: Built with Google Font Outfit, CSS glassmorphism, responsive drag-and-drop file upload, data preview, and interactive 4-step wizard.

---

## 🏗️ Architecture

```
┌────────────────────────────────────────────────────────┐
│               Modern Glassmorphic Web UI               │
│  (Upload Wizard -> DB Config -> Mapping -> Dashboard)   │
└───────────────▲───────────────────────▲────────────────┘
                │ HTTP POST             │ Status Polling (JSON)
                │                       │
┌───────────────▼───────────────────────┴────────────────┐
│                   PHP Web Backend                      │
│     upload.php  •  start_import.php  •  status.php     │
└───────────────────────────┬────────────────────────────┘
                            │ Spawns Asynchronous CLI Process
                            ▼
┌────────────────────────────────────────────────────────┐
│              Background Import Worker                  │
│                (src/ImportWorker.php)                  │
├───────────────────────────┬────────────────────────────┤
│  OpenSpout Stream Reader  │  Data Validation Pipeline  │
│  (src/ExcelReader.php)    │  (src/DataValidator.php)   │
└─────────────┬─────────────┴─────────────┬──────────────┘
              │ Chunked Batch Inserts     │ Invalid Records
              ▼                           ▼
┌───────────────────────────┐   ┌────────────────────────┐
│     SQLite / MySQL DB     │   │   CSV Error Reports    │
│    (src/Database.php)     │   │   (data/errors_*.csv)  │
└───────────────────────────┘   └────────────────────────┘
```

---

## 📁 Project Structure

```text
Excel-DB-Importer/
├── composer.json               # Dependencies and PSR-4 Autoloading
├── composer.lock               # Locked dependency tree
├── .gitignore                  # Git ignore rules for runtime/vendor files
├── README.md                   # Project documentation
├── src/                        # Core Application Engine
│   ├── Database.php            # PDO wrapper for SQLite and MySQL with batching
│   ├── DataValidator.php       # Cleaning & validation (Name, Phone, Dates)
│   ├── ExcelReader.php         # OpenSpout streaming parser & type detection
│   └── ImportWorker.php        # CLI background worker engine
├── public/                     # Web Interface & API Endpoints
│   ├── index.html              # 4-step modern glassmorphic importer wizard
│   ├── style.css               # Vanilla CSS design system & animations
│   ├── app.js                  # Frontend state management & live polling
│   ├── upload.php              # File upload & schema profiling endpoint
│   ├── start_import.php        # Triggers the background worker
│   ├── status.php              # Real-time progress monitoring endpoint
│   └── cancel.php              # Job cancellation trigger
├── data/                       # Working directory for logs & local DBs
│   ├── uploads/                # Uploaded spreadsheet files
│   └── .gitkeep
└── scratch/                    # Benchmarks & CLI testing scripts
    ├── generate_mock.php       # Mock Excel dataset generator
    ├── test_import_cli.php     # CLI import test runner
    └── test_2million_scale.php # Scalability & memory check
```

---

## 🚀 Getting Started

### Prerequisites

- **PHP 8.1** or newer (with `pdo`, `pdo_sqlite`, `pdo_mysql`, `zip`, `xml` extensions enabled)
- **Composer**
- Web server (**XAMPP**, Apache, Nginx, or PHP built-in server)
- (Optional) **MySQL 8.0+** or MariaDB (SQLite works out of the box with zero setup)

### Installation

1. **Clone the repository**:
   ```bash
   git clone https://github.com/khxnhasnain/Database_Importer.git
   cd Database_Importer
   ```

2. **Install PHP dependencies**:
   ```bash
   composer install
   ```

3. **Ensure directory permissions** for the storage folder:
   ```bash
   chmod -R 775 data/
   ```

4. **Launch the application**:
   - If using **XAMPP / WAMP**: Place the directory inside `htdocs` or `www` and navigate to:
     ```
     http://localhost/Excel-DB-Importer/public/
     ```
   - Or run with PHP's built-in development server:
     ```bash
     php -S localhost:8000 -t public
     ```
     Then open `http://localhost:8000` in your web browser.

---

## 📖 How It Works

### Step 1: Upload File
Drag & drop your `.xlsx`, `.csv`, or `.ods` spreadsheet. The engine streams the first few rows to read column headers and infer data types without reading the whole file.

### Step 2: Database Configuration
Choose your target database:
- **SQLite**: Stored locally in `data/database.sqlite` (zero credentials required).
- **MySQL**: Input Host, Port, Database name, Username, and Password. If the database does not exist, XcelSync automatically creates it.

### Step 3: Column Mapping & Schema Customization
Review detected columns and map key fields:
- **Name**: Cleans unnecessary spaces and validates non-empty values.
- **Mobile Number**: Applies strict telephone format checks (10 digits starting with 6, 7, 8, or 9).
- **Date of Birth**: Formats strings or serial dates into standard `YYYY-MM-DD`.
- Set or adjust data types for each column before table creation.

### Step 4: Real-time Ingestion & Error Logs
Watch the ingestion progress live. If any records fail validation:
- The exact row number and failure cause are written to a downloadable CSV log.
- Valid records continue inserting without interruption.

---

## 🧪 Testing with Mock Data

Generate a realistic test dataset of 20,000+ rows containing valid and edge-case data:

```bash
php scratch/generate_mock.php
```

To run a headless import test via CLI:

```bash
php scratch/test_import_cli.php
```

---

## 🛡️ License

This project is licensed under the [MIT License](LICENSE).
