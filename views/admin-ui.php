<?php
if (!defined('pp_allowed_access')) {
    die('Direct access not allowed');
}

global $conn, $setting;
global $db_host, $db_user, $db_pass, $db_name, $db_prefix, $mode;

$plugin_slug = 'initialize-failed-transactions';
$settings = pp_get_plugin_setting($plugin_slug);
$setting = pp_get_settings();

// Function to dynamically find pp-config.php
function find_pp_config(): ?string
{
    $start = __DIR__;
    for ($i = 0; $i < 8; $i++) {
        $root = dirname($start, $i + 1);
        $cfg = $root . '/pp-config.php';
        if (is_file($cfg) && is_readable($cfg)) {
            return realpath($cfg);
        }
    }
    return null;
}

// Find and include the configuration file
$config_path = find_pp_config();
if ($config_path === null) {
    die('Could not find pp-config.php file');
}

require_once $config_path;

// Try updating database
if (!isset($conn)) {
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    if ($conn->connect_error) {
        $error = "Connection failed: " . $conn->connect_error;
    }
    if (!$conn->query("SET NAMES utf8")) {
        $error = "Set names failed: " . $conn->error;
    }
    if (!empty($db_prefix)) {
        if (!$conn->query("SET sql_mode = ''")) {
            $error = "Set sql_mode failed: " . $conn->error;
        }
    }
}

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Pagination settings
$items_per_page = 6; // Changed from 20 to 4 rows per page

// Failed table pagination
$failed_current_page = isset($_GET['failed_page']) ? max(1, intval($_GET['failed_page'])) : 1;
$failed_offset = isset($_GET['failed_offset']) ? max(0, intval($_GET['failed_offset'])) : ($failed_current_page - 1) * $items_per_page;

// Initialize table pagination
$initialize_current_page = isset($_GET['initialize_page']) ? max(1, intval($_GET['initialize_page'])) : 1;
$initialize_offset = isset($_GET['initialize_offset']) ? max(0, intval($_GET['initialize_offset'])) : ($initialize_current_page - 1) * $items_per_page;

// Ensure offsets and pages are in sync
if (!isset($_GET['failed_offset'])) {
    $_GET['failed_offset'] = $failed_offset;
}
if (!isset($_GET['failed_page'])) {
    $_GET['failed_page'] = $failed_current_page;
}
if (!isset($_GET['initialize_offset'])) {
    $_GET['initialize_offset'] = $initialize_offset;
}
if (!isset($_GET['initialize_page'])) {
    $_GET['initialize_page'] = $initialize_current_page;
}

// Store previous query in session
if (!empty($_GET)) {
    $_SESSION['previous_query'] = $_GET;
}

function getTransactions($status, $limit = null, $offset = 0, $search = '')
{
    global $conn, $db_prefix;

    $where_conditions = [
        "transaction_status = '" . $conn->real_escape_string($status) . "'",
        "(JSON_EXTRACT(transaction_metadata, '$.is_ifa_done') IS NULL OR JSON_EXTRACT(transaction_metadata, '$.is_ifa_done') = 'false')"
    ];

    // Add search condition if provided
    if (!empty($search)) {
        $search_escaped = $conn->real_escape_string($search);
        $where_conditions[] = "(
            c_name LIKE '%$search_escaped%' OR 
            c_email_mobile LIKE '%$search_escaped%' OR 
            transaction_amount LIKE '%$search_escaped%' OR 
            transaction_currency LIKE '%$search_escaped%' OR 
            DATE(created_at) LIKE '%$search_escaped%'
        )";
    }

    $where_clause = implode(' AND ', $where_conditions);
    $query = "SELECT * FROM " . $db_prefix . "transaction WHERE $where_clause ORDER BY id DESC";

    // Add limit and offset if provided
    if ($limit !== null) {
        $query .= " LIMIT " . intval($limit) . " OFFSET " . intval($offset);
    }

    $result = $conn->query($query);
    $transactions = [];
    while ($row = $result->fetch_assoc()) {
        $transactions[] = $row;
    }
    return $transactions;
}

function getTransactionCount($status, $search = '')
{
    global $conn, $db_prefix;

    $where_conditions = [
        "transaction_status = '" . $conn->real_escape_string($status) . "'",
        "(JSON_EXTRACT(transaction_metadata, '$.is_ifa_done') IS NULL OR JSON_EXTRACT(transaction_metadata, '$.is_ifa_done') = 'false')"
    ];

    // Add search condition if provided
    if (!empty($search)) {
        $search_escaped = $conn->real_escape_string($search);
        $where_conditions[] = "(
            c_name LIKE '%$search_escaped%' OR 
            c_email_mobile LIKE '%$search_escaped%' OR 
            transaction_amount LIKE '%$search_escaped%' OR 
            transaction_currency LIKE '%$search_escaped%' OR 
            DATE(created_at) LIKE '%$search_escaped%'
        )";
    }

    $where_clause = implode(' AND ', $where_conditions);
    $query = "SELECT COUNT(*) as total FROM " . $db_prefix . "transaction WHERE $where_clause";

    $result = $conn->query($query);
    $row = $result->fetch_assoc();
    return $row['total'];
}

function getDoneTransaction($limit = null, $offset = 0, $search = '')
{
    global $conn, $db_prefix;

    $where_conditions = [
        "(JSON_EXTRACT(transaction_metadata, '$.is_ifa_done') = 'true')"
    ];

    // Add search condition if provided
    if (!empty($search)) {
        $search_escaped = $conn->real_escape_string($search);
        $where_conditions[] = "(
            c_name LIKE '%$search_escaped%' OR 
            c_email_mobile LIKE '%$search_escaped%' OR 
            transaction_amount LIKE '%$search_escaped%' OR 
            transaction_currency LIKE '%$search_escaped%' OR 
            DATE(created_at) LIKE '%$search_escaped%'
        )";
    }

    $where_clause = implode(' AND ', $where_conditions);
    $query = "SELECT * FROM " . $db_prefix . "transaction WHERE $where_clause ORDER BY id DESC";

    // Add limit and offset if provided
    if ($limit !== null) {
        $query .= " LIMIT " . intval($limit) . " OFFSET " . intval($offset);
    }

    $result = $conn->query($query);
    $transactions = [];
    while ($row = $result->fetch_assoc()) {
        $transactions[] = $row;
    }
    return $transactions;
}

function getDoneTransactionCount($search = '')
{
    global $conn, $db_prefix;

    $where_conditions = [
        "(JSON_EXTRACT(transaction_metadata, '$.is_ifa_done') = 'true')"
    ];

    // Add search condition if provided
    if (!empty($search)) {
        $search_escaped = $conn->real_escape_string($search);
        $where_conditions[] = "(
            c_name LIKE '%$search_escaped%' OR 
            c_email_mobile LIKE '%$search_escaped%' OR 
            transaction_amount LIKE '%$search_escaped%' OR 
            transaction_currency LIKE '%$search_escaped%' OR 
            DATE(created_at) LIKE '%$search_escaped%'
        )";
    }

    $where_clause = implode(' AND ', $where_conditions);
    $query = "SELECT COUNT(*) as total FROM " . $db_prefix . "transaction WHERE $where_clause";

    $result = $conn->query($query);
    $row = $result->fetch_assoc();
    return $row['total'];
}

// Get search terms from URL parameters
$failed_search_term = (isset($_GET['failed_search']) && trim($_GET['failed_search']) !== '') ? trim($_GET['failed_search']) : '';
$initialize_search_term = (isset($_GET['initialize_search']) && trim($_GET['initialize_search']) !== '') ? trim($_GET['initialize_search']) : '';
$done_search_term = (isset($_GET['done_search']) && trim($_GET['done_search']) !== '') ? trim($_GET['done_search']) : '';

// Done table pagination
$done_current_page = isset($_GET['done_page']) ? max(1, intval($_GET['done_page'])) : 1;
$done_offset = isset($_GET['done_offset']) ? max(0, intval($_GET['done_offset'])) : ($done_current_page - 1) * $items_per_page;

// Ensure done offset and page are in sync
if (!isset($_GET['done_offset'])) {
    $_GET['done_offset'] = $done_offset;
}
if (!isset($_GET['done_page'])) {
    $_GET['done_page'] = $done_current_page;
}

// Handle update_ifa parameter if present
if (isset($_GET['update_ifa'])) {
    $transaction_id = intval($_GET['update_ifa']);
    $query = "SELECT transaction_metadata FROM " . $db_prefix . "transaction WHERE id = " . $transaction_id;
    $result = $conn->query($query);
    if ($row = $result->fetch_assoc()) {
        $metadata = json_decode($row['transaction_metadata'], true) ?: array();
        $metadata['is_ifa_done'] = 'true';
        $updated_metadata = json_encode($metadata);
        $update_query = "UPDATE " . $db_prefix . "transaction SET transaction_metadata = '" . $conn->real_escape_string($updated_metadata) . "' WHERE id = " . $transaction_id;
        $conn->query($update_query);
    }
}

// Get paginated transactions for failed status
$failed_transactions = getTransactions('failed', $items_per_page, $failed_offset, $failed_search_term);
$total_failed_transactions = getTransactionCount('failed', $failed_search_term);
$total_failed_pages = ceil($total_failed_transactions / $items_per_page);

// Get paginated transactions for initialize status
$initialize_transactions = getTransactions('initialize', $items_per_page, $initialize_offset, $initialize_search_term);
$total_initialize_transactions = getTransactionCount('initialize', $initialize_search_term);
$total_initialize_pages = ceil($total_initialize_transactions / $items_per_page);

// Get paginated done transactions
$done_transactions = getDoneTransaction($items_per_page, $done_offset, $done_search_term);
$total_done_transactions = getDoneTransactionCount($done_search_term);
$total_done_pages = ceil($total_done_transactions / $items_per_page);

// Get the main page parameter
$current_page = isset($_GET['page']) ? $_GET['page'] : 'modules--initialize-failed-analytics';

?>

<div class="d-flex flex-column gap-4">
  <!-- Page Header -->
  <div class="page-header">
    <div class="row align-items-end">
      <div class="col-sm mb-2 mb-sm-0 d-flex align-items-center gap-2">
        <h1 class="page-header-title" style="margin-bottom: 4px;">Initialize & Failed Transactions</h1>
      </div>
      <div class="col-sm-auto">
        <button type="button" class="btn btn-outline-danger me-2" id="clearAllFilters" style="display: none;">
          <i class="bi bi-filter"></i> Clear Filters
        </button>
        <button type="button" class="btn btn-primary" id="refreshData">
          <i class="bi bi-arrow-clockwise"></i> Refresh
        </button>
      </div>
    </div>
  </div>

  <div class="row justify-content-center">
    <div>
      <div class="d-grid">
        <!-- table  -->
        <div class="card mb-lg-5">
          <div class="card-header">
            <div class="row justify-content-between align-items-center flex-grow-1">
              <div class="col-md">
                <div class="d-flex justify-content-between align-items-center">
                  <h4 class="card-header-title">Failed Transaction</h4>
                </div>
              </div>
              <!-- End Col -->

              <div class="col-auto">
                <!-- Filter -->
                <div class="row align-items-sm-center">
                  <div class="col-md">
                    <form method="GET" action="">
                      <!-- Search -->
                      <div class="input-group input-group-merge input-group-flush">
                        <div class="input-group-prepend input-group-text">
                          <i class="bi-search"></i>
                        </div>
                        <input id="datatableSearch" type="search" class="form-control" placeholder="Search"
                          aria-label="Search" name="failed_search"
                          value="<?php echo htmlspecialchars($failed_search_term); ?>">
                        <?php if (isset($_GET['page'])): ?>
                        <input type="hidden" name="page"
                          value="<?php echo htmlspecialchars($_GET['page']); ?>">
                        <?php endif; ?>
                        <input type="hidden" name="offset" value="0">
                        <?php if (isset($_GET['page'])): ?>
                        <input type="hidden" name="page"
                          value="<?php echo htmlspecialchars($_GET['page']); ?>">
                        <?php endif; ?>
                        <?php if (isset($_GET['initialize_search']) && $_GET['initialize_search'] !== ''): ?>
                        <input type="hidden" name="initialize_search"
                          value="<?php echo htmlspecialchars($_GET['initialize_search']); ?>">
                        <?php endif; ?>
                        <button type="submit" class="btn btn-primary">Search</button>
                      </div>
                      <!-- End Search -->
                    </form>
                  </div>
                  <!-- End Col -->
                </div>
                <!-- End Filter -->
              </div>
              <!-- End Col -->
            </div>
            <!-- End Row -->
          </div>
          <!-- End Header -->

          <!-- Table -->
          <div class="table-responsive datatable-custom">
            <table class="table table-borderless table-thead-bordered table-nowrap table-align-middle card-table">
              <thead class="thead-light">
                <tr>
                  <th>Customer</th>
                  <th>Email/Phone</th>
                  <th>Amount</th>
                  <th>Date</th>
                  <th>Status</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody id="datatable">
                <?php
                if (empty($failed_transactions)) {
                    echo '<tr><td colspan="5" class="text-center">No transactions found.</td></tr>';
                } else {
                    foreach ($failed_transactions as $transaction) {
                        ?>
                <tr>
                  <td>
                    <?php echo htmlspecialchars($transaction['c_name']); ?>
                  </td>
                  <td>
                    <?php echo htmlspecialchars($transaction['c_email_mobile']); ?>
                  </td>
                  <td>
                    <?php echo htmlspecialchars($transaction['transaction_amount']); ?>
                    <?php echo htmlspecialchars($transaction['transaction_currency']); ?>
                  </td>
                  <td>
                    <?php echo htmlspecialchars($transaction['created_at']); ?>
                  </td>
                  <td>
                    <span
                      class="badge bg-danger"><?php echo htmlspecialchars($transaction['transaction_status']); ?></span>
                  </td>
                  <td>
                    <div class="btn-group" role="group">
                      <a class="btn btn-white btn-sm"
                        onclick="showMetadataModal(<?php echo htmlspecialchars(json_encode($transaction['transaction_metadata']), ENT_QUOTES, 'UTF-8'); ?>)">
                        <i class="bi bi-bar-chart-steps"></i> Metadata
                      </a>
                    </div>
                    <div
                      onclick="load_content('View Transaction','view-transaction?ref=<?php echo $transaction['id']; ?>','nav-btn-transaction')"
                      class="btn-group" role="group">
                      <a class="btn btn-white btn-sm">
                        <i class="bi-eye me-1"></i> View
                      </a>
                    </div>
                    <div class="btn-group" role="group">
                      <a class="btn btn-white btn-sm"
                        onclick="showDoneConfirmation(<?php echo $transaction['id']; ?>)">
                        <i class="bi bi-check-all"></i> Done
                      </a>
                    </div>
                  </td>
                </tr>
                <?php
                    }
                }
?>
              </tbody>
            </table>
          </div>
          <!-- End Table -->

          <!-- Footer -->
          <div class="card-footer">
            <div class="row justify-content-center justify-content-sm-between align-items-sm-center">
              <div class="col-sm mb-2 mb-sm-0">
                <div class="d-flex justify-content-center justify-content-sm-start align-items-center">
                  <span class="me-2">Showing:</span>
                  <div class="tom-select-custom" id="showing-result">
                    <?php
                        $start_item = ($failed_current_page - 1) * $items_per_page + 1;
$end_item = min($failed_current_page * $items_per_page, $total_failed_transactions);
echo $start_item . '-' . $end_item;
?>
                  </div>
                  <span class="text-secondary me-2" style="margin-left:8px;">of</span>
                  <span
                    id="total-result"><?php echo $total_failed_transactions; ?></span>
                </div>
              </div>
              <!-- End Col -->

              <div class="col-sm-auto">
                <div class="d-flex justify-content-center justify-content-sm-end">
                  <nav id="datatablePagination" aria-label="Activity pagination">
                    <div class="dataTables_paginate" id="datatable_paginate">
                      <ul id="datatable_pagination" class="pagination datatable-custom-pagination">
                        <!-- Previous button -->
                        <li
                          class="paginate_item page-item <?php echo ($failed_offset <= 0) ? 'disabled' : ''; ?>"
                          id="prev-page">
                          <a class="paginate_button previous page-link" href="<?php
                                  $url = '?page=' . urlencode($_GET['page']); // Always keep page first
$url .= '&failed_offset=' . max(0, $failed_offset - $items_per_page);
$url .= '&failed_page=' . max(1, $failed_current_page - 1);
if ($failed_search_term) {
    $url .= '&failed_search=' . urlencode($failed_search_term);
}
// Preserve initialize table state
$url .= '&initialize_offset=' . $initialize_offset;
$url .= '&initialize_page=' . $initialize_current_page;
if ($initialize_search_term) {
    $url .= '&initialize_search=' . urlencode($initialize_search_term);
}
echo ($failed_offset > 0) ? $url : 'javascript:void(0)';
?>">
                            <span aria-hidden="true">Prev</span>
                          </a>
                        </li>

                        <!-- Page numbers -->
                        <span id="page-numbers" style="display: flex;">
                          <?php
      $start_page = max(1, $failed_current_page - 2);
$end_page = min($total_failed_pages, $failed_current_page + 2);

// Show first page if not in range
if ($start_page > 1) {
    $url = '?page=' . urlencode($_GET['page']); // Always keep page first
    $url .= '&failed_offset=0&failed_page=1';
    // Preserve initialize table state
    $url .= '&initialize_offset=' . $initialize_offset;
    $url .= '&initialize_page=' . $initialize_current_page;
    if ($failed_search_term) {
        $url .= '&failed_search=' . urlencode($failed_search_term);
    }
    if ($initialize_search_term) {
        $url .= '&initialize_search=' . urlencode($initialize_search_term);
    }
    echo '<li class="paginate_item page-item">';
    echo '<a class="paginate_button page-link" href="' . $url . '">1</a>';
    echo '</li>';
    if ($start_page > 2) {
        echo '<li class="paginate_item page-item disabled"><span class="page-link">...</span></li>';
    }
}

// Show page numbers
for ($i = $start_page; $i <= $end_page; $i++) {
    $page_offset = ($i - 1) * $items_per_page;
    $active_class = ($failed_current_page == $i) ? 'active' : '';
    $url = '?page=' . urlencode($current_page) . '&failed_offset=' . $page_offset . '&failed_page=' . $i;
    // Preserve initialize table state
    $url .= '&initialize_offset=' . $initialize_offset;
    $url .= '&initialize_page=' . $initialize_current_page;
    if ($failed_search_term) {
        $url .= '&failed_search=' . urlencode($failed_search_term);
    }
    if ($initialize_search_term) {
        $url .= '&initialize_search=' . urlencode($initialize_search_term);
    }
    echo '<li class="paginate_item page-item ' . $active_class . '">';
    echo '<a class="paginate_button page-link" href="' . $url . '">' . $i . '</a>';
    echo '</li>';
}

// Show last page if not in range
if ($end_page < $total_failed_pages) {
    if ($end_page < $total_failed_pages - 1) {
        echo '<li class="paginate_item page-item disabled"><span class="page-link">...</span></li>';
    }
    $last_page_offset = ($total_failed_pages - 1) * $items_per_page;
    $url = '?failed_offset=' . $last_page_offset . '&failed_page=' . $total_failed_pages;
    // Preserve initialize table state
    $url .= '&initialize_offset=' . $initialize_offset;
    $url .= '&initialize_page=' . $initialize_current_page;
    if ($failed_search_term) {
        $url .= '&failed_search=' . urlencode($failed_search_term);
    }
    if ($initialize_search_term) {
        $url .= '&initialize_search=' . urlencode($initialize_search_term);
    }
    echo '<li class="paginate_item page-item">';
    echo '<a class="paginate_button page-link" href="' . $url . '">' . $total_failed_pages . '</a>';
    echo '</li>';
}
?>
                        </span>

                        <!-- Next button -->
                        <li
                          class="paginate_item page-item <?php echo ($failed_offset + $items_per_page >= $total_failed_transactions) ? 'disabled' : ''; ?>"
                          id="next-page">
                          <a class="paginate_button next page-link" href="<?php
                        $url = '?failed_offset=' . ($failed_offset + $items_per_page);
$url .= '&failed_page=' . ($failed_current_page + 1);
// Preserve initialize table state
$url .= '&initialize_offset=' . $initialize_offset;
$url .= '&initialize_page=' . $initialize_current_page;
if ($failed_search_term) {
    $url .= '&failed_search=' . urlencode($failed_search_term);
}
if ($initialize_search_term) {
    $url .= '&initialize_search=' . urlencode($initialize_search_term);
}
echo ($failed_offset + $items_per_page < $total_failed_transactions) ? $url : 'javascript:void(0)';
?>">
                            <span aria-hidden="true">Next</span>
                          </a>
                        </li>
                      </ul>
                    </div>
                  </nav>
                </div>
              </div>
              <!-- End Col -->
            </div>
            <!-- End Row -->
          </div>
          <!-- End Footer -->
        </div>

        <!-- Initialize Transaction Table -->
        <div class="card mb-lg-5">
          <div class="card-header">
            <div class="row justify-content-between align-items-center flex-grow-1">
              <div class="col-md">
                <div class="d-flex justify-content-between align-items-center">
                  <h4 class="card-header-title">Initialize Transaction</h4>
                </div>
              </div>
              <!-- End Col -->

              <div class="col-auto">
                <!-- Filter -->
                <div class="row align-items-sm-center">
                  <div class="col-md">
                    <form method="GET" action="">
                      <!-- Search -->
                      <div class="input-group input-group-merge input-group-flush">
                        <div class="input-group-prepend input-group-text">
                          <i class="bi-search"></i>
                        </div>
                        <input id="initializeSearch" type="search" class="form-control" placeholder="Search"
                          aria-label="Search" name="initialize_search"
                          value="<?php echo htmlspecialchars($initialize_search_term); ?>">
                        <?php if (isset($_GET['page'])): ?>
                        <input type="hidden" name="page"
                          value="<?php echo htmlspecialchars($_GET['page']); ?>">
                        <?php endif; ?>
                        <input type="hidden" name="offset" value="0">
                        <?php if (isset($_GET['failed_search']) && $_GET['failed_search'] !== ''): ?>
                        <input type="hidden" name="failed_search"
                          value="<?php echo htmlspecialchars($_GET['failed_search']); ?>">
                        <?php endif; ?>
                        <button type="submit" class="btn btn-primary">Search</button>
                      </div>
                      <!-- End Search -->
                    </form>
                  </div>
                  <!-- End Col -->
                </div>
                <!-- End Filter -->
              </div>
              <!-- End Col -->
            </div>
            <!-- End Row -->
          </div>
          <!-- End Header -->

          <!-- Table -->
          <div class="table-responsive datatable-custom">
            <table class="table table-borderless table-thead-bordered table-nowrap table-align-middle card-table">
              <thead class="thead-light">
                <tr>
                  <th>Customer</th>
                  <th>Email/Phone</th>
                  <th>Amount</th>
                  <th>Date</th>
                  <th>Status</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody id="initializeTableBody">
                <?php
                if (empty($initialize_transactions)) {
                    echo '<tr><td colspan="5" class="text-center">No transactions found.</td></tr>';
                } else {
                    foreach ($initialize_transactions as $transaction) {
                        ?>
                <tr>
                  <td>
                    <?php echo htmlspecialchars($transaction['c_name']); ?>
                  </td>
                  <td>
                    <?php echo htmlspecialchars($transaction['c_email_mobile']); ?>
                  </td>
                  <td>
                    <?php echo htmlspecialchars($transaction['transaction_amount']); ?>
                    <?php echo htmlspecialchars($transaction['transaction_currency']); ?>
                  </td>
                  <td>
                    <?php echo htmlspecialchars($transaction['created_at']); ?>
                  </td>
                  <td>
                    <span
                      class="badge bg-warning"><?php echo htmlspecialchars($transaction['transaction_status']); ?></span>
                  </td>
                  <td>
                    <div class="btn-group" role="group">
                      <a class="btn btn-white btn-sm"
                        onclick="showMetadataModal(<?php echo htmlspecialchars(json_encode($transaction['transaction_metadata']), ENT_QUOTES, 'UTF-8'); ?>)">
                        <i class="bi bi-bar-chart-steps"></i> Metadata
                      </a>
                    </div>
          </div>
          <div class="btn-group" role="group">
            <a class="btn btn-white btn-sm"
              onclick="showDoneConfirmation(<?php echo $transaction['id']; ?>)">
              <i class="bi bi-check-all"></i> Done
            </a>
          </div>
          </td>
          </tr>
          <?php
                    }
                }
?>
          </tbody>
          </table>
        </div>
        <!-- End Table -->

        <!-- Footer -->
        <div class="card-footer">
          <div class="row justify-content-center justify-content-sm-between align-items-sm-center">
            <div class="col-sm mb-2 mb-sm-0">
              <div class="d-flex justify-content-center justify-content-sm-start align-items-center">
                <span class="me-2">Showing:</span>
                <div class="tom-select-custom" id="showing-result-initialize">
                  <?php
                        $start_item = ($initialize_current_page - 1) * $items_per_page + 1;
$end_item = min($initialize_current_page * $items_per_page, $total_initialize_transactions);
echo $start_item . '-' . $end_item;
?>
                </div>
                <span class="text-secondary me-2" style="margin-left:8px;">of</span>
                <span
                  id="total-result-initialize"><?php echo $total_initialize_transactions; ?></span>
              </div>
            </div>
            <!-- End Col -->

            <div class="col-sm-auto">
              <div class="d-flex justify-content-center justify-content-sm-end">
                <nav id="initializePagination" aria-label="Initialize pagination">
                  <div class="dataTables_paginate" id="initialize_paginate">
                    <ul id="initialize_pagination" class="pagination datatable-custom-pagination">
                      <!-- Previous button -->
                      <li
                        class="paginate_item page-item <?php echo ($offset <= 0) ? 'disabled' : ''; ?>"
                        id="prev-page-initialize">
                        <a class="paginate_button previous page-link" href="<?php
          $url = '?offset=' . max(0, $offset - $items_per_page);
if (isset($_GET['page'])) {
    $url .= '&page=' . urlencode($_GET['page']);
}
if ($failed_search_term) {
    $url .= '&failed_search=' . urlencode($failed_search_term);
}
if ($initialize_search_term) {
    $url .= '&initialize_search=' . urlencode($initialize_search_term);
}
echo ($offset > 0) ? $url : 'javascript:void(0)';
?>">
                          <span aria-hidden="true">Prev</span>
                        </a>
                      </li>

                      <!-- Page numbers -->
                      <span id="page-numbers-initialize" style="display: flex;">
                        <?php
                          $start_page = max(1, $initialize_current_page - 2);
$end_page = min($total_initialize_pages, $initialize_current_page + 2);

// Show first page if not in range
if ($start_page > 1) {
    $url = '?page=' . urlencode($_GET['page']); // Always keep page first
    $url .= '&initialize_offset=0&initialize_page=1';
    // Preserve failed table state
    $url .= '&failed_offset=' . $failed_offset;
    $url .= '&failed_page=' . $failed_current_page;
    if ($failed_search_term) {
        $url .= '&failed_search=' . urlencode($failed_search_term);
    }
    if ($initialize_search_term) {
        $url .= '&initialize_search=' . urlencode($initialize_search_term);
    }
    echo '<li class="paginate_item page-item">';
    echo '<a class="paginate_button page-link" href="' . $url . '">1</a>';
    echo '</li>';
    if ($start_page > 2) {
        echo '<li class="paginate_item page-item disabled"><span class="page-link">...</span></li>';
    }
}

// Show page numbers
for ($i = $start_page; $i <= $end_page; $i++) {
    $page_offset = ($i - 1) * $items_per_page;
    $active_class = ($initialize_current_page == $i) ? 'active' : '';
    $url = '?page=' . urlencode($current_page) . '&initialize_offset=' . $page_offset . '&initialize_page=' . $i;
    // Preserve failed table state
    $url .= '&failed_offset=' . $failed_offset;
    $url .= '&failed_page=' . $failed_current_page;
    if ($failed_search_term) {
        $url .= '&failed_search=' . urlencode($failed_search_term);
    }
    if ($initialize_search_term) {
        $url .= '&initialize_search=' . urlencode($initialize_search_term);
    }
    echo '<li class="paginate_item page-item ' . $active_class . '">';
    echo '<a class="paginate_button page-link" href="' . $url . '">' . $i . '</a>';
    echo '</li>';
}

// Show last page if not in range
if ($end_page < $total_initialize_pages) {
    if ($end_page < $total_initialize_pages - 1) {
        echo '<li class="paginate_item page-item disabled"><span class="page-link">...</span></li>';
    }
    $last_page_offset = ($total_initialize_pages - 1) * $items_per_page;
    $url = '?initialize_offset=' . $last_page_offset . '&initialize_page=' . $total_initialize_pages;
    // Preserve failed table state
    $url .= '&failed_offset=' . $failed_offset;
    $url .= '&failed_page=' . $failed_current_page;
    if ($failed_search_term) {
        $url .= '&failed_search=' . urlencode($failed_search_term);
    }
    if ($initialize_search_term) {
        $url .= '&initialize_search=' . urlencode($initialize_search_term);
    }
    echo '<li class="paginate_item page-item">';
    echo '<a class="paginate_button page-link" href="' . $url . '">' . $total_initialize_pages . '</a>';
    echo '</li>';
}
?>
                      </span>

                      <!-- Next button -->
                      <li
                        class="paginate_item page-item <?php echo ($initialize_offset + $items_per_page >= $total_initialize_transactions) ? 'disabled' : ''; ?>"
                        id="next-page-initialize">
                        <a class="paginate_button next page-link" href="<?php
    $url = '?page=' . urlencode($_GET['page']); // Always keep page first
$url .= '&initialize_offset=' . ($initialize_offset + $items_per_page);
$url .= '&initialize_page=' . ($initialize_current_page + 1);
// Preserve failed table state
$url .= '&failed_offset=' . $failed_offset;
$url .= '&failed_page=' . $failed_current_page;
if ($failed_search_term) {
    $url .= '&failed_search=' . urlencode($failed_search_term);
}
if ($initialize_search_term) {
    $url .= '&initialize_search=' . urlencode($initialize_search_term);
}
echo ($initialize_offset + $items_per_page < $total_initialize_transactions) ? $url : 'javascript:void(0)';
?>">
                          <span aria-hidden="true">Next</span>
                        </a>
                      </li>
                    </ul>
                  </div>
                </nav>
              </div>
            </div>
            <!-- End Col -->
          </div>
          <!-- End Row -->
        </div>
        <!-- End Footer -->
      </div>

      <!-- Done Transaction Table -->
      <div class="card mb-lg-5">
        <div class="card-header">
          <div class="row justify-content-between align-items-center flex-grow-1">
            <div class="col-md">
              <div class="d-flex justify-content-between align-items-center">
                <h4 class="card-header-title">Done Transactions</h4>
              </div>
            </div>
            <div class="col-auto">
              <!-- Filter -->
              <div class="row align-items-sm-center">
                <div class="col-md">
                  <form method="GET" action="">
                    <!-- Search -->
                    <div class="input-group input-group-merge input-group-flush">
                      <div class="input-group-prepend input-group-text">
                        <i class="bi-search"></i>
                      </div>
                      <input id="doneSearch" type="search" class="form-control" placeholder="Search" aria-label="Search"
                        name="done_search"
                        value="<?php echo htmlspecialchars($done_search_term); ?>">
                      <?php if (isset($_GET['page'])): ?>
                      <input type="hidden" name="page"
                        value="<?php echo htmlspecialchars($_GET['page']); ?>">
                      <?php endif; ?>
                      <input type="hidden" name="offset" value="0">
                      <?php if (isset($_GET['failed_search']) && $_GET['failed_search'] !== ''): ?>
                      <input type="hidden" name="failed_search"
                        value="<?php echo htmlspecialchars($_GET['failed_search']); ?>">
                      <?php endif; ?>
                      <?php if (isset($_GET['initialize_search']) && $_GET['initialize_search'] !== ''): ?>
                      <input type="hidden" name="initialize_search"
                        value="<?php echo htmlspecialchars($_GET['initialize_search']); ?>">
                      <?php endif; ?>
                      <button type="submit" class="btn btn-primary">Search</button>
                    </div>
                    <!-- End Search -->
                  </form>
                </div>
                <!-- End Col -->
              </div>
              <!-- End Filter -->
            </div>
          </div>
        </div>

        <!-- Table -->
        <div class="table-responsive datatable-custom">
          <table class="table table-borderless table-thead-bordered table-nowrap table-align-middle card-table">
            <thead class="thead-light">
              <tr>
                <th>Customer</th>
                <th>Email/Phone</th>
                <th>Amount</th>
                <th>Date</th>
                <th>Status</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody id="doneTableBody">
              <?php
                if (empty($done_transactions)) {
                    echo '<tr><td colspan="6" class="text-center">No transactions found.</td></tr>';
                } else {
                    foreach ($done_transactions as $transaction) {
                        $status_class = $transaction['transaction_status'] === 'failed' ? 'bg-danger' : 'bg-warning';
                        ?>
              <tr>
                <td>
                  <?php echo htmlspecialchars($transaction['c_name']); ?>
                </td>
                <td>
                  <?php echo htmlspecialchars($transaction['c_email_mobile']); ?>
                </td>
                <td>
                  <?php echo htmlspecialchars($transaction['transaction_amount']); ?>
                  <?php echo htmlspecialchars($transaction['transaction_currency']); ?>
                </td>
                <td>
                  <?php echo htmlspecialchars($transaction['created_at']); ?>
                </td>
                <td>
                  <span
                    class="badge <?php echo $status_class; ?>"><?php echo htmlspecialchars($transaction['transaction_status']); ?></span>
                </td>
                <td>
                  <div class="btn-group" role="group">
                    <a class="btn btn-white btn-sm"
                      onclick="showMetadataModal(<?php echo htmlspecialchars(json_encode($transaction['transaction_metadata']), ENT_QUOTES, 'UTF-8'); ?>)">
                      <i class="bi bi-bar-chart-steps"></i> Metadata
                    </a>
                  </div>
                  <div
                    onclick="load_content('View Transaction','view-transaction?ref=<?php echo $transaction['id']; ?>','nav-btn-transaction')"
                    class="btn-group" role="group">
                    <a class="btn btn-white btn-sm">
                      <i class="bi-eye me-1"></i> View
                    </a>
                  </div>
                </td>
              </tr>
              <?php
                    }
                }
?>
            </tbody>
          </table>
        </div>
        <!-- End Table -->

        <!-- Footer -->
        <div class="card-footer">
          <div class="row justify-content-center justify-content-sm-between align-items-sm-center">
            <div class="col-sm mb-2 mb-sm-0">
              <div class="d-flex justify-content-center justify-content-sm-start align-items-center">
                <span class="me-2">Showing:</span>
                <div class="tom-select-custom" id="showing-result-done">
                  <?php
                  $start_item = ($done_current_page - 1) * $items_per_page + 1;
$end_item = min($done_current_page * $items_per_page, $total_done_transactions);
echo $start_item . '-' . $end_item;
?>
                </div>
                <span class="text-secondary me-2" style="margin-left:8px;">of</span>
                <span
                  id="total-result-done"><?php echo $total_done_transactions; ?></span>
              </div>
            </div>
            <!-- End Col -->

            <div class="col-sm-auto">
              <div class="d-flex justify-content-center justify-content-sm-end">
                <nav id="donePagination" aria-label="Done pagination">
                  <div class="dataTables_paginate" id="done_paginate">
                    <ul id="done_pagination" class="pagination datatable-custom-pagination">
                      <!-- Previous button -->
                      <li
                        class="paginate_item page-item <?php echo ($done_offset <= 0) ? 'disabled' : ''; ?>"
                        id="prev-page-done">
                        <a class="paginate_button previous page-link" href="<?php
        $url = '?page=' . urlencode($current_page);
$url .= '&done_offset=' . max(0, $done_offset - $items_per_page);
$url .= '&done_page=' . max(1, $done_current_page - 1);
// Preserve other table states
$url .= '&failed_offset=' . $failed_offset;
$url .= '&failed_page=' . $failed_current_page;
$url .= '&initialize_offset=' . $initialize_offset;
$url .= '&initialize_page=' . $initialize_current_page;
if ($failed_search_term) {
    $url .= '&failed_search=' . urlencode($failed_search_term);
}
if ($initialize_search_term) {
    $url .= '&initialize_search=' . urlencode($initialize_search_term);
}
if ($done_search_term) {
    $url .= '&done_search=' . urlencode($done_search_term);
}
echo ($done_offset > 0) ? $url : 'javascript:void(0)';
?>">
                          <span aria-hidden="true">Prev</span>
                        </a>
                      </li>

                      <!-- Page numbers -->
                      <span id="page-numbers-done" style="display: flex;">
                        <?php
$start_page = max(1, $done_current_page - 2);
$end_page = min($total_done_pages, $done_current_page + 2);

// Show first page if not in range
if ($start_page > 1) {
    $url = '?page=' . urlencode($current_page);
    $url .= '&done_offset=0&done_page=1';
    // Preserve other table states
    $url .= '&failed_offset=' . $failed_offset;
    $url .= '&failed_page=' . $failed_current_page;
    $url .= '&initialize_offset=' . $initialize_offset;
    $url .= '&initialize_page=' . $initialize_current_page;
    if ($failed_search_term) {
        $url .= '&failed_search=' . urlencode($failed_search_term);
    }
    if ($initialize_search_term) {
        $url .= '&initialize_search=' . urlencode($initialize_search_term);
    }
    if ($done_search_term) {
        $url .= '&done_search=' . urlencode($done_search_term);
    }
    echo '<li class="paginate_item page-item">';
    echo '<a class="paginate_button page-link" href="' . $url . '">1</a>';
    echo '</li>';
    if ($start_page > 2) {
        echo '<li class="paginate_item page-item disabled"><span class="page-link">...</span></li>';
    }
}

// Show page numbers
for ($i = $start_page; $i <= $end_page; $i++) {
    $page_offset = ($i - 1) * $items_per_page;
    $active_class = ($done_current_page == $i) ? 'active' : '';
    $url = '?page=' . urlencode($current_page);
    $url .= '&done_offset=' . $page_offset . '&done_page=' . $i;
    // Preserve other table states
    $url .= '&failed_offset=' . $failed_offset;
    $url .= '&failed_page=' . $failed_current_page;
    $url .= '&initialize_offset=' . $initialize_offset;
    $url .= '&initialize_page=' . $initialize_current_page;
    if ($failed_search_term) {
        $url .= '&failed_search=' . urlencode($failed_search_term);
    }
    if ($initialize_search_term) {
        $url .= '&initialize_search=' . urlencode($initialize_search_term);
    }
    if ($done_search_term) {
        $url .= '&done_search=' . urlencode($done_search_term);
    }
    echo '<li class="paginate_item page-item ' . $active_class . '">';
    echo '<a class="paginate_button page-link" href="' . $url . '">' . $i . '</a>';
    echo '</li>';
}

// Show last page if not in range
if ($end_page < $total_done_pages) {
    if ($end_page < $total_done_pages - 1) {
        echo '<li class="paginate_item page-item disabled"><span class="page-link">...</span></li>';
    }
    $last_page_offset = ($total_done_pages - 1) * $items_per_page;
    $url = '?page=' . urlencode($current_page);
    $url .= '&done_offset=' . $last_page_offset . '&done_page=' . $total_done_pages;
    // Preserve other table states
    $url .= '&failed_offset=' . $failed_offset;
    $url .= '&failed_page=' . $failed_current_page;
    $url .= '&initialize_offset=' . $initialize_offset;
    $url .= '&initialize_page=' . $initialize_current_page;
    if ($failed_search_term) {
        $url .= '&failed_search=' . urlencode($failed_search_term);
    }
    if ($initialize_search_term) {
        $url .= '&initialize_search=' . urlencode($initialize_search_term);
    }
    if ($done_search_term) {
        $url .= '&done_search=' . urlencode($done_search_term);
    }
    echo '<li class="paginate_item page-item">';
    echo '<a class="paginate_button page-link" href="' . $url . '">' . $total_done_pages . '</a>';
    echo '</li>';
}
?>
                      </span>

                      <!-- Next button -->
                      <li
                        class="paginate_item page-item <?php echo ($done_offset + $items_per_page >= $total_done_transactions) ? 'disabled' : ''; ?>"
                        id="next-page-done">
                        <a class="paginate_button next page-link" href="<?php
  $url = '?page=' . urlencode($current_page);
$url .= '&done_offset=' . ($done_offset + $items_per_page);
$url .= '&done_page=' . ($done_current_page + 1);
// Preserve other table states
$url .= '&failed_offset=' . $failed_offset;
$url .= '&failed_page=' . $failed_current_page;
$url .= '&initialize_offset=' . $initialize_offset;
$url .= '&initialize_page=' . $initialize_current_page;
if ($failed_search_term) {
    $url .= '&failed_search=' . urlencode($failed_search_term);
}
if ($initialize_search_term) {
    $url .= '&initialize_search=' . urlencode($initialize_search_term);
}
if ($done_search_term) {
    $url .= '&done_search=' . urlencode($done_search_term);
}
echo ($done_offset + $items_per_page < $total_done_transactions) ? $url : 'javascript:void(0)';
?>">
                          <span aria-hidden="true">Next</span>
                        </a>
                      </li>
                    </ul>
                  </div>
                </nav>
              </div>
            </div>
            <!-- End Col -->
          </div>
          <!-- End Row -->
        </div>
        <!-- End Footer -->
      </div>
    </div>
  </div>
</div>
</div>
</div>

<script>
  // Add JavaScript for dynamic search functionality
  document.addEventListener('DOMContentLoaded', function() {
    // Function to setup search functionality
    function setupSearch(searchInputId) {
      const searchInput = document.getElementById(searchInputId);
      if (!searchInput) return;

      const searchForm = searchInput.closest('form');

      // Auto-submit form when search input changes (with debounce)
      let searchTimeout;
      searchInput.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function() {
          // Show clear filters button if there's a search value
          const clearFiltersBtn = document.getElementById('clearAllFilters');
          clearFiltersBtn.style.display = (hasActiveFilters() || searchInput.value) ? 'inline-block' :
            'none';

          // If search value is empty, remove the corresponding parameter
          if (!searchInput.value) {
            const urlParams = new URLSearchParams(window.location.search);
            const paramName = searchInput.name; // 'failed_search' or 'initialize_search'
            urlParams.delete(paramName);

            // Always preserve page parameter
            const page = urlParams.get('page');
            let url = window.location.pathname;
            if (urlParams.toString()) {
              url += '?' + urlParams.toString();
            } else if (page) {
              url += '?page=' + page;
            }
            window.location.href = url;
            return;
          }

          searchForm.submit();
        }, 500);
      });

      // Clear search functionality
      if (searchInput.value) {
        const clearButton = document.createElement('button');
        clearButton.type = 'button';
        clearButton.className = 'btn btn-outline-secondary btn-sm ms-2';
        clearButton.innerHTML = '<i class="bi bi-x"></i> Clear';
        clearButton.onclick = function() {
          searchInput.value = '';
          const urlParams = new URLSearchParams(window.location.search);

          // Remove the corresponding search parameter
          const paramName = searchInput.name; // 'failed_search' or 'initialize_search'
          urlParams.delete(paramName);
          urlParams.set('offset', '0');

          // Create URL with remaining parameters
          let url = window.location.pathname;
          if (urlParams.toString()) {
            url += '?' + urlParams.toString();
          }
          window.location.href = url;
        };
        searchInput.parentNode.appendChild(clearButton);
      }
    }

    // Setup search for all tables
    setupSearch('datatableSearch');
    setupSearch('initializeSearch');
    setupSearch('doneSearch');
  });
</script>

<!-- Metadata Modal -->
<div class="modal fade" id="metadataModal" tabindex="-1" aria-labelledby="metadataModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="metadataModalLabel">Transaction Metadata</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="metadataContent" class="table-responsive">
          <table class="table">
            <thead>
              <tr>
                <th>Key</th>
                <th>Value</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody id="metadataTableBody">
            </tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- Confirm Done Modal -->
<div class="modal fade" id="confirmDoneModal" tabindex="-1" aria-labelledby="confirmDoneModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="confirmDoneModalLabel">Confirm Action</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        Are you sure you want to mark this transaction as done?
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="confirmDoneBtn"
          onclick="handleConfirmClick()">Confirm</button>
      </div>
    </div>
  </div>
</div>

<script>
  // Function to check if any search filters are applied
  function hasActiveFilters() {
    const urlParams = new URLSearchParams(window.location.search);
    return (urlParams.has('failed_search') && urlParams.get('failed_search').trim() !== '') ||
      (urlParams.has('initialize_search') && urlParams.get('initialize_search').trim() !== '');
  }

  // Function to update clear filters button visibility
  function updateClearFiltersButton() {
    const clearFiltersBtn = document.getElementById('clearAllFilters');
    clearFiltersBtn.style.display = hasActiveFilters() ? 'inline-block' : 'none';
  }

  // Initial update of clear filters button visibility
  updateClearFiltersButton();

  // Handle clear all filters button click
  document.getElementById('clearAllFilters').addEventListener('click', function() {
    // Add loading state to the button
    const button = this;
    const originalContent = button.innerHTML;
    button.innerHTML = '<i class="bi bi-x-circle"></i> Clearing...';
    button.disabled = true;

    // Clear search parameters but preserve page parameter
    const urlParams = new URLSearchParams(window.location.search);
    const page = urlParams.get('page') || 'modules--initialize-failed-analytics';
    let url = window.location.pathname + '?page=' + encodeURIComponent(page);
    // Reset both table offsets
    url += '&failed_offset=0&initialize_offset=0';
    window.location.href = url;
  });

  // Handle refresh button click
  document.getElementById('refreshData').addEventListener('click', function() {
    // Add loading state
    const button = this;
    const originalContent = button.innerHTML;
    button.innerHTML = '<i class="bi bi-arrow-clockwise"></i> Loading...';
    button.disabled = true;

    // Reload the page
    window.location.reload();
  });

  function showMetadataModal(metadata) {
    event.stopPropagation(); // Prevent the row click event
    const tableBody = document.getElementById('metadataTableBody');
    tableBody.innerHTML = ''; // Clear existing content

    // Parse the metadata if it's a string
    const metadataObj = typeof metadata === 'string' ? JSON.parse(metadata) : metadata;

    // Create rows for each metadata key-value pair
    for (const [key, value] of Object.entries(metadataObj)) {
      const row = document.createElement('tr');

      // Key cell
      const keyCell = document.createElement('td');
      keyCell.textContent = key;
      row.appendChild(keyCell);

      // Value cell
      const valueCell = document.createElement('td');
      valueCell.textContent = typeof value === 'object' ? JSON.stringify(value) : value;
      row.appendChild(valueCell);

      // Action cell
      const actionCell = document.createElement('td');
      const buttonGroup = document.createElement('div');
      buttonGroup.className = 'd-flex gap-1';

      // Copy button
      const copyButton = document.createElement('button');
      copyButton.className = 'btn btn-soft-primary btn-xs';
      copyButton.innerHTML = '<i class="bi bi-clipboard"></i>';
      copyButton.onclick = () => copyToClipboard(value);
      buttonGroup.appendChild(copyButton);

      // Add WhatsApp button if key contains phone/cell/number and value is a valid number
      const keyLower = key.toLowerCase();
      const valueStr = String(value).replace(/[^0-9]/g, ''); // Remove non-numeric characters
      if ((keyLower.includes('phone') || keyLower.includes('cell') || keyLower.includes('number')) && valueStr.length >=
        10) {
        const whatsappButton = document.createElement('a');
        whatsappButton.className = 'btn btn-soft-success btn-xs';
        whatsappButton.href = `https://wa.me/${valueStr}`;
        whatsappButton.target = '_blank';
        whatsappButton.innerHTML = '<i class="bi bi-whatsapp"></i>';
        buttonGroup.appendChild(whatsappButton);
      }

      actionCell.appendChild(buttonGroup);
      row.appendChild(actionCell);

      tableBody.appendChild(row);
    }

    // Show the modal
    const modal = new bootstrap.Modal(document.getElementById('metadataModal'));
    modal.show();
  }

  function copyToClipboard(text) {
    const textToCopy = typeof text === 'object' ? JSON.stringify(text) : text.toString();
    navigator.clipboard.writeText(textToCopy).then(() => {
      // Show a toast or some feedback
      const toast = document.createElement('div');
      toast.className = 'position-fixed bottom-0 end-0 p-3';
      toast.style.zIndex = '5000';
      toast.innerHTML = `
            <div class="toast show" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="toast-header">
                    <strong class="me-auto">Success</strong>
                    <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
                <div class="toast-body">
                    Value copied to clipboard!
                </div>
            </div>
        `;
      document.body.appendChild(toast);

      // Remove the toast after 2 seconds
      setTimeout(() => {
        toast.remove();
      }, 2000);
    }).catch(err => {
      console.error('Failed to copy text: ', err);
    });
  }

  let currentTransactionId = null;

  function showDoneConfirmation(transactionId) {
    event.stopPropagation(); // Prevent the row click event
    currentTransactionId = transactionId;
    const modal = new bootstrap.Modal(document.getElementById('confirmDoneModal'));
    modal.show();
  }

  // Add event listener for the confirm button
  // Handle confirm button click
  function handleConfirmClick() {
    console.log("Confirm clicked", currentTransactionId);
    if (currentTransactionId) {
      // Get current URL parameters
      const urlParams = new URLSearchParams(window.location.search);

      // Add update_ifa parameter
      urlParams.set('update_ifa', currentTransactionId);

      // Create new URL with parameters
      const newUrl = window.location.pathname + '?' + urlParams.toString();

      // Redirect to the new URL
      window.location.href = newUrl;
    }
  }

  // Function to remove update_ifa parameter and preserve other parameters
  function removeUpdateIfaParam() {
    console.log('Checking for update_ifa parameter...');
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('update_ifa')) {
      console.log('Found update_ifa, removing it...');
      // Store all other parameters
      const params = {};
      urlParams.forEach((value, key) => {
        if (key !== 'update_ifa') {
          params[key] = value;
        }
      });

      // Create new URL with preserved parameters
      const newUrl = window.location.pathname + (Object.keys(params).length > 0 ? '?' + new URLSearchParams(params)
        .toString() : '');
      console.log('New URL:', newUrl);

      // Update URL without reloading
      window.history.replaceState({}, '', newUrl);
      console.log('URL updated');
    }
  }

  // Try to run both immediately and after DOM content loaded
  removeUpdateIfaParam();

  document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM loaded, running parameter cleanup again...');
    removeUpdateIfaParam();
  });

  // Also try running after a short delay
  setTimeout(removeUpdateIfaParam, 100);
</script>