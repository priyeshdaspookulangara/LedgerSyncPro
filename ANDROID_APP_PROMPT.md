# Android Mobile App Development Prompt (Kotlin & Jetpack Compose)

Use the updated detailed prompt below to build or upgrade your native Android application with **Multi-Entity & Multi-Currency Accounting, Double-Entry Validation, and Full Transaction Entry Edit & Delete capabilities**.

---

### **Prompt for AI Agent / Android Developer**

```markdown
# Role & Task Summary
You are an expert Senior Android Developer specializing in Kotlin, Jetpack Compose, Clean Architecture, and Retrofit/Coroutines. Your task is to implement or upgrade the native Android application for the **AccuTrack Enterprise Multi-Entity & Multi-Currency Accounting System**, integrating the newly added **Transaction Entry Editing and Deletion Features** via REST API.

---

### 1. Technology Stack & Architecture
- **Language:** Kotlin 1.9+
- **UI Framework:** Jetpack Compose (Material3)
- **Architecture:** MVVM / Unidirectional Data Flow (Clean Architecture: Data, Domain, Presentation)
- **Networking:** Retrofit 2 + OkHttp 4 (with Bearer Token Auth Interceptor)
- **Local Cache & Storage:** DataStore (Preferences) for Bearer token & user profile
- **Async & Concurrency:** Kotlin Coroutines & StateFlow

---

### 2. REST API Integration Specifications

#### **1) Update Existing Journal Entry**
- **Endpoint:** `POST /api/transactions/update.php` (or `PUT /api/transactions/update.php`)
- **Headers:** `Authorization: Bearer <token>`, `Content-Type: application/json`
- **Request Body:**
  ```json
  {
    "entry_id": 105,
    "entity_id": 1,
    "tx_type": "income",
    "entry_date": "2026-10-21",
    "reference_number": "GST-2026-001-REV1",
    "description": "Updated Consultation Fee Amount",
    "amount": 18500.00,
    "payment_account_id": 2,
    "category_account_id": 7
  }
  ```
- **Response Success (200 OK):**
  ```json
  {
    "success": true,
    "message": "Transaction updated successfully",
    "entry_id": 105,
    "currency": "INR",
    "amount": 18500
  }
  ```
- **Error Responses:**
  - `400 Bad Request`: Validation failure (e.g. invalid accounts, zero amount).
  - `403 Forbidden`: User attempts to edit an entry created by another staff member (unless user role is `admin`).
  - `404 Not Found`: Entry ID does not exist.

#### **2) Delete Journal Entry**
- **Endpoint:** `POST /api/transactions/delete.php` (or `DELETE /api/transactions/delete.php`)
- **Headers:** `Authorization: Bearer <token>`, `Content-Type: application/json`
- **Request Body:**
  ```json
  {
    "entry_id": 105
  }
  ```
- **Response Success (200 OK):**
  ```json
  {
    "success": true,
    "message": "Journal Entry #105 deleted successfully",
    "entry_id": 105
  }
  ```
- **Error Responses:**
  - `403 Forbidden`: Staff member attempts to delete another user's entry.
  - `404 Not Found`: Invalid entry ID.

---

### 3. Mobile UI & Interaction Requirements

#### **Feature A: Transaction History Card Actions (Edit & Delete)**
1. **Swipe Actions / Action Row:**
   - On each transaction card in the LazyColumn feed, render an **Edit** button (pencil icon) and a **Delete** button (trash icon).
   - Alternatively, support **Swipe-to-Reveal** or **Context Menu Options** (3-dots menu).
2. **Delete Confirmation Dialog:**
   - When the user taps **Delete**, trigger an AlertDialog:
     > *"Are you sure you want to permanently delete Journal Entry #105 (INR 18,500.00)?"*
   - Buttons: **Cancel** (Dismiss) and **Delete** (Destructive red theme).
3. **State Management & List Updates:**
   - Upon successful API deletion response, remove the entry smoothly from the `StateFlow<List<Transaction>>` list with a Toast notification (*"Journal Entry #105 deleted"*).

#### **Feature B: Edit Transaction Screen / BottomSheet**
1. **Pre-Populated Form State:**
   - Tapping **Edit** navigates to `EditTransactionScreen(entryId: Int)` or opens an `EditTransactionBottomSheet`.
   - Fetch or load current entry details and pre-fill:
     - Selected Entity
     - Date Picker value
     - Reference / TxID
     - Description
     - Amount
     - Payment Account & Category Account dropdowns
2. **Currency Auto-Locking:**
   - Changing the Entity selection automatically locks currency (`INR` vs `USDT`) and filters valid ledger accounts accordingly.
3. **Submission & Refresh:**
   - On tapping **"Update Entry"**, invoke `POST /api/transactions/update.php`.
   - On success, navigate back to the list and trigger an automatic list refresh.

---

### 4. Sample Retrofit API Interface (Kotlin)

```kotlin
interface AccountingApiService {

    @POST("api/login.php")
    suspend fun login(@Body request: LoginRequest): Response<LoginResponse>

    @GET("api/entities.php")
    suspend fun getEntities(): Response<EntitiesResponse>

    @POST("api/create_transaction.php")
    suspend fun createTransaction(@Body request: TransactionRequest): Response<TransactionResponse>

    @POST("api/transactions/update.php")
    suspend fun updateTransaction(@Body request: UpdateTransactionRequest): Response<TransactionResponse>

    @POST("api/transactions/delete.php")
    suspend fun deleteTransaction(@Body request: DeleteTransactionRequest): Response<DeleteTransactionResponse>

    @GET("api/transactions/list.php")
    suspend fun getTransactions(
        @Query("entity_id") entityId: Int? = null,
        @Query("currency") currency: String? = null,
        @Query("page") page: Int = 1,
        @Query("limit") limit: Int = 20
    ): Response<TransactionListResponse>

    @GET("api/reports/summary.php")
    suspend fun getSummaryReport(
        @Query("currency") currency: String
    ): Response<SummaryReportResponse>
}
```

---

### 5. Implementation Steps for AI Agent
1. **Data Layer:** Add `updateTransaction()` and `deleteTransaction()` to `AccountingRepository` and Retrofit interface.
2. **Domain Layer / UseCases:** Implement `UpdateTransactionUseCase` and `DeleteTransactionUseCase`.
3. **ViewModel:** Expose `editTransaction()` and `deleteTransaction(id: Int)` methods emitting `UiState` (Loading, Success, Error).
4. **UI Layer:** Update transaction card items in Compose with Edit/Delete action buttons, AlertDialog, and pre-filled edit form screen.
```
