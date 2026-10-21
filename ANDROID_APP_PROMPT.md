# Android Mobile App Development Prompt (Kotlin & Jetpack Compose)

Use the detailed prompt below to build the native Android application for the **AccuTrack Enterprise Multi-Entity & Multi-Currency Accounting System**.

---

### **System & API Specifications Overview**
- **Base Server URL:** `http://<your-server-ip-or-domain>`
- **Authentication Mechanism:** HTTP Bearer Token (JSON response includes token and user details).
- **Supported Currencies:** `INR` (Indian Rupee) and `USDT` (Tether Crypto).

---

### **Prompt for Android Developer / LLM Prompting**

```markdown
# Role & Objective
You are an expert Senior Android Developer specializing in Kotlin, Jetpack Compose, Clean Architecture, and Coroutines/Flow. Your task is to build a modern, secure, and production-ready native Android mobile application for an enterprise **Multi-Entity Multi-Currency Accounting System** powered by a PHP/MySQL REST API back-end.

---

### 1. Technology Stack & Architecture
- **Language:** Kotlin 1.9+
- **UI Framework:** Jetpack Compose (Material3 Design) with Dynamic Color & Responsive Layouts
- **Architecture:** Clean Architecture (UI Layer, Domain Layer, Data Layer) + MVVM / Unidirectional Data Flow (MVI)
- **Networking:** Retrofit 2 + OkHttp 4 (with Auth Interceptor for Bearer Tokens) + Kotlinx Serialization / Gson
- **Local Storage / Session:** Jetpack DataStore (Preferences) for secure Bearer Token and User Profile caching
- **Async & Concurrency:** Kotlin Coroutines & StateFlow / SharedFlow
- **Dependency Injection:** Hilt / Koin

---

### 2. Branding & Visual Design Guidelines
- **Fiat Theme (INR Entities):** Emerald Green (`#059669`), Mint Accents (`#d1fae5`), Slate Dark (`#1e293b`).
- **Crypto Theme (USDT Wings):** Crypto Teal (`#0d9488`), Gold/Amber Accents (`#d97706`), Dark Navy (`#0f172a`).
- **Components:** TopAppBar with active entity badge, Navigation Drawer / Bottom Navigation Bar, Material3 Cards with currency-coded visual banners, animated transaction submission feed, pull-to-refresh list state.

---

### 3. REST API Specification & Integration

#### **1) Authentication & Session Management**
- **Endpoint:** `POST /api/login.php`
- **Request Body:**
  ```json
  {
    "email": "staff@company.com",
    "password": "staff_password"
  }
  ```
- **Response:**
  ```json
  {
    "success": true,
    "token": "64_character_bearer_token",
    "expires_at": "2026-11-20 03:00:00",
    "user": {
      "id": 2,
      "full_name": "Staff User",
      "email": "staff@company.com",
      "role": "staff"
    }
  }
  ```
- **Requirements:**
  - Store `token` securely in DataStore.
  - Automatically append header `Authorization: Bearer <token>` for all subsequent requests via OkHttp Interceptor.
  - Handle `401 Unauthorized` responses by clearing token and navigating to Login Screen.

#### **2) Active Entities & Currency Auto-Locking**
- **Endpoint:** `GET /api/entities.php`
- **Response:**
  ```json
  {
    "success": true,
    "entities": [
      { "id": 1, "name": "Consultation & GST Services", "default_currency": "INR", "status": "active" },
      { "id": 2, "name": "Burfee Cart", "default_currency": "INR", "status": "active" },
      { "id": 3, "name": "Optimus Infinity", "default_currency": "USDT", "status": "active" },
      { "id": 4, "name": "Rayon Global Impact", "default_currency": "USDT", "status": "active" }
    ]
  }
  ```
- **UI Logic:**
  - Entity dropdown selector for transaction entry.
  - Selecting an entity MUST automatically update and lock the transaction currency field (`INR` or `USDT`) and switch the UI color scheme dynamically.

#### **3) Multi-Currency Double-Entry Transaction Creation**
- **Endpoint:** `POST /api/create_transaction.php` (or `/api/transactions/create.php`)
- **Request Body:**
  ```json
  {
    "entity_id": 1,
    "tx_type": "income",
    "entry_date": "2026-10-21",
    "reference_number": "GST-2026-001",
    "description": "Consultation Fee Received",
    "amount": 15000.00,
    "payment_account_id": 2,
    "category_account_id": 7
  }
  ```
- **Supported `tx_type` values:**
  - `income`: Receive Revenue (Debit Payment Asset, Credit Revenue Account)
  - `expense`: Pay Operational Expense (Debit Expense Account, Credit Payment Asset)
  - `mlm_payout`: MLM Leader Commission Payout
  - `leader_transfer`: Leader Transfer / Capital Allocation
- **Response:**
  ```json
  {
    "success": true,
    "message": "Transaction posted successfully",
    "journal_entry_id": 105,
    "currency": "INR",
    "amount": 15000
  }
  ```

#### **4) Filtered & Paginated Transactions List**
- **Endpoint:** `GET /api/transactions/list.php?entity_id={X}&currency={INR|USDT}&page={P}&limit=20`
- **Response:**
  ```json
  {
    "success": true,
    "pagination": { "total_records": 42, "page": 1, "limit": 20, "total_pages": 3 },
    "transactions": [
      {
        "id": "105",
        "entry_date": "2026-10-21",
        "currency": "INR",
        "reference_number": "GST-2026-001",
        "description": "Consultation Fee Received",
        "created_at": "2026-10-21 03:00:00",
        "entity_name": "Consultation & GST Services",
        "created_by_name": "Staff User",
        "total_amount": "15000.0000"
      }
    ]
  }
  ```

#### **5) Financial Reports & Dashboard Summary API**
- **Endpoint:** `GET /api/reports/summary.php?currency={INR|USDT}`
- **Response:**
  ```json
  {
    "success": true,
    "currency": "USDT",
    "summary": {
      "total_income": 5000,
      "total_expense": 1200,
      "net_profit": 3800
    },
    "entity_breakdown": [
      { "id": 3, "name": "Optimus Infinity", "income": 5000, "expense": 1200, "net_profit": 3800 },
      { "id": 4, "name": "Rayon Global Impact", "income": 0, "expense": 0, "net_profit": 0 }
    ]
  }
  ```

---

### 4. App Features & Screen Flows

#### **Screen 1: Login Screen**
- Clean brand logo and title.
- Email and password input fields with error handling.
- "Sign In" button with progress indicator.
- Automatically redirects based on saved Bearer Token.

#### **Screen 2: Multi-Currency Executive Dashboard (Tabbed View)**
- **Tab 1: Total INR Metrics (Fiat Wing)**
  - Summary cards for Total INR Revenue, Expenses, Net Profit/Loss.
  - Entity-level breakdown cards for Consultation & GST Services vs Burfee Cart.
- **Tab 2: Total USDT Metrics (Crypto Wing)**
  - Summary cards for Total USDT Revenue, Expenses, Net Profit/Loss.
  - Entity-level breakdown cards for Optimus Infinity vs Rayon Global Impact.

#### **Screen 3: Transaction Entry Screen (Staff & Admin)**
- Entity Selector dropdown (Fetches active entities from `/api/entities.php`).
- Currency Badge showing locked currency based on selected entity.
- Transaction Type segmented selector (Receive Income / Pay Expense / MLM Payout / Leader Transfer).
- Date Picker, Reference Number, Amount, and Description fields.
- Payment Account & Category Account dropdowns filtered by currency type.
- Floating Action Button or Submit Button to post transaction.

#### **Screen 4: Transaction Logs / History Screen**
- Filter bar with Entity filter, Currency filter, and Search query.
- Paginated list of transactions with infinite scrolling / LazyColumn.
- Currency badge indicators on each transaction card.
```
