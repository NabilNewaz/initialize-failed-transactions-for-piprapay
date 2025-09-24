<?php
if (!defined('pp_allowed_access')) {
    die('Direct access not allowed');
}

global $conn, $setting, $auth_id;
global $db_host, $db_user, $db_pass, $db_name, $db_prefix, $mode;

$plugin_slug = 'initialize-failed-transactions';

// Get the plugin directory URL
$plugin_dir = dirname(__DIR__);
$plugin_url = str_replace($_SERVER['DOCUMENT_ROOT'], '', $plugin_dir);

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

if (isset($conn) && !$conn->connect_error) {
    // First, check if auth_id already exists
    $check_sql = "SELECT plugin_array FROM {$db_prefix}plugins WHERE plugin_slug = '{$plugin_slug}'";
    $check_result = $conn->query($check_sql);

    $auth_id = null;
    if ($check_result && $check_result->num_rows > 0) {
        $row = $check_result->fetch_assoc();
        if (!empty($row['plugin_array'])) {
            $plugin_data = json_decode($row['plugin_array'], true);
            if (isset($plugin_data['auth_id']) && !empty($plugin_data['auth_id'])) {
                $auth_id = $plugin_data['auth_id'];
            }
        }
    }

    // Generate new auth_id only if it doesn't exist
    if (empty($auth_id)) {
        $auth_id = uniqid();
        $sql = "UPDATE {$db_prefix}plugins SET plugin_array = '{\"auth_id\":\"$auth_id\"}' WHERE plugin_slug = '{$plugin_slug}'";
        $result = $conn->query($sql);
    }
}

// Fallback: ensure we always have an auth_id
if (empty($auth_id)) {
    $auth_id = 'fallback_' . uniqid();
}
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
      <div class="d-grid gap-3 gap-lg-4">
        <!-- table  -->
        <div class="card">
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
                    <!-- Search -->
                    <div class="input-group input-group-merge input-group-flush">
                      <div class="input-group-prepend input-group-text">
                        <i class="bi-search"></i>
                      </div>
                      <input id="datatableSearch" type="search" class="form-control" placeholder="Search"
                        aria-label="Search">
                    </div>
                    <!-- End Search -->
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
                <tr>
                  <td colspan="6" class="text-center">
                    <div class="spinner-border spinner-border-sm" role="status"><span
                        class="visually-hidden">Loading...</span></div> Loading...
                  </td>
                </tr>
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
                  <div class="tom-select-custom" id="showing-result">0-0</div>
                  <span class="text-secondary me-2" style="margin-left:8px;">of</span>
                  <span id="total-result">0</span>
                </div>
              </div>
              <!-- End Col -->

              <div class="col-sm-auto">
                <div class="d-flex justify-content-center justify-content-sm-end">
                  <nav id="datatablePagination" aria-label="Activity pagination">
                    <div class="dataTables_paginate" id="datatable_paginate">
                      <ul id="datatable_pagination" class="pagination datatable-custom-pagination">
                        <!-- Pagination will be populated by JavaScript -->
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
        <div class="card">
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
                    <!-- Search -->
                    <div class="input-group input-group-merge input-group-flush">
                      <div class="input-group-prepend input-group-text">
                        <i class="bi-search"></i>
                      </div>
                      <input id="initializeSearch" type="search" class="form-control" placeholder="Search"
                        aria-label="Search">
                    </div>
                    <!-- End Search -->
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
                <tr>
                  <td colspan="6" class="text-center">
                    <div class="spinner-border spinner-border-sm" role="status"><span
                        class="visually-hidden">Loading...</span></div> Loading...
                  </td>
                </tr>
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
                  <div class="tom-select-custom" id="showing-result-initialize">0-0</div>
                  <span class="text-secondary me-2" style="margin-left:8px;">of</span>
                  <span id="total-result-initialize">0</span>
                </div>
              </div>
              <!-- End Col -->

              <div class="col-sm-auto">
                <div class="d-flex justify-content-center justify-content-sm-end">
                  <nav id="initializePagination" aria-label="Initialize pagination">
                    <div class="dataTables_paginate" id="initialize_paginate">
                      <ul id="initialize_pagination" class="pagination datatable-custom-pagination">
                        <!-- Pagination will be populated by JavaScript -->
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
        <div class="card">
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
                    <!-- Search -->
                    <div class="input-group input-group-merge input-group-flush">
                      <div class="input-group-prepend input-group-text">
                        <i class="bi-search"></i>
                      </div>
                      <input id="doneSearch" type="search" class="form-control" placeholder="Search"
                        aria-label="Search">
                    </div>
                    <!-- End Search -->
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
                <tr>
                  <td colspan="6" class="text-center">
                    <div class="spinner-border spinner-border-sm" role="status"><span
                        class="visually-hidden">Loading...</span></div> Loading...
                  </td>
                </tr>
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
                  <div class="tom-select-custom" id="showing-result-done">0-0</div>
                  <span class="text-secondary me-2" style="margin-left:8px;">of</span>
                  <span id="total-result-done">0</span>
                </div>
              </div>
              <!-- End Col -->

              <div class="col-sm-auto">
                <div class="d-flex justify-content-center justify-content-sm-end">
                  <nav id="donePagination" aria-label="Done pagination">
                    <div class="dataTables_paginate" id="done_paginate">
                      <ul id="done_pagination" class="pagination datatable-custom-pagination">
                        <!-- Pagination will be populated by JavaScript -->
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
  // Initialize script

  // Track current state for each table
  window.tableStates = {
    failed: {
      page: 1,
      search: ''
    },
    initialize: {
      page: 1,
      search: ''
    },
    done: {
      page: 1,
      search: ''
    }
  };

  function initializeApp() {
    console.log('Initializing app...');

    // Clear any existing timeouts
    if (window.initTimeout) {
      clearTimeout(window.initTimeout);
    }

    // Load all transaction tables with current state
    const failedState = window.tableStates?.failed || {
      page: 1,
      search: ''
    };
    const initializeState = window.tableStates?.initialize || {
      page: 1,
      search: ''
    };
    const doneState = window.tableStates?.done || {
      page: 1,
      search: ''
    };

    loadTransactionTable('failed', 'datatable', failedState.page, failedState.search);
    loadTransactionTable('initialize', 'initializeTableBody', initializeState.page, initializeState.search);
    loadTransactionTable('done', 'doneTableBody', doneState.page, doneState.search);

    // Setup search functionality
    setupSearchListeners();
  }

  // Initialize when DOM is ready
  document.addEventListener('DOMContentLoaded', function() {
    initializeApp();
  });

  // Also try immediate if already loaded
  if (document.readyState !== 'loading') {
    initializeApp();
  }

  // Handle browser back/forward navigation
  window.addEventListener('pageshow', function(event) {
    // This event fires when navigating back to the page
    if (event.persisted || performance.navigation.type === 2) {
      // Page was loaded from cache or user navigated back
      console.log('Page restored from cache or back navigation detected');
      initializeApp();
    }
  });

  // Handle page visibility changes (when tab becomes active again)
  document.addEventListener('visibilitychange', function() {
    if (!document.hidden) {
      // Page became visible, check if we need to reload data
      console.log('Page became visible');
      // Only reload if tables are showing loading state
      const failedTable = document.getElementById('datatable');
      const initializeTable = document.getElementById('initializeTableBody');
      const doneTable = document.getElementById('doneTableBody');

      const isShowingLoading = (table) => {
        return table && table.innerHTML.includes('Loading...');
      };

      if (isShowingLoading(failedTable) || isShowingLoading(initializeTable) || isShowingLoading(doneTable)) {
        console.log('Tables showing loading state, reinitializing...');
        initializeApp();
      }
    }
  });

  // Handle popstate (browser back/forward buttons)
  window.addEventListener('popstate', function(event) {
    console.log('Popstate event detected');
    // Small delay to ensure page is ready
    setTimeout(function() {
      initializeApp();
    }, 100);
  });

  // Additional fallback: Check if tables are stuck in loading state after 2 seconds
  window.addEventListener('load', function() {
    setTimeout(function() {
      const failedTable = document.getElementById('datatable');
      const initializeTable = document.getElementById('initializeTableBody');
      const doneTable = document.getElementById('doneTableBody');

      const isStuckLoading = (table) => {
        return table && table.innerHTML.includes('Loading...') && table.innerHTML.includes('spinner-border');
      };

      if (isStuckLoading(failedTable) || isStuckLoading(initializeTable) || isStuckLoading(doneTable)) {
        console.log('Tables stuck in loading state, forcing reload...');
        initializeApp();
      }
    }, 2000);
  });

  // Handle dynamic content loading (for AJAX-based navigation systems)
  // This creates a MutationObserver to detect when the plugin content is loaded
  if (typeof window.pluginContentObserver === 'undefined') {
    window.pluginContentObserver = new MutationObserver(function(mutations) {
      mutations.forEach(function(mutation) {
        // Check if nodes were added that contain our plugin content
        if (mutation.type === 'childList' && mutation.addedNodes.length > 0) {
          for (let node of mutation.addedNodes) {
            if (node.nodeType === Node.ELEMENT_NODE) {
              // Check if the added content contains our tables
              const hasFailedTable = node.querySelector('#datatable') || node.getElementById?.('datatable');
              const hasInitTable = node.querySelector('#initializeTableBody') || node.getElementById?.(
                'initializeTableBody');
              const hasDoneTable = node.querySelector('#doneTableBody') || node.getElementById?.(
                'doneTableBody');

              if (hasFailedTable || hasInitTable || hasDoneTable) {
                console.log('Plugin content detected via MutationObserver, initializing...');
                // Small delay to ensure DOM is fully rendered
                setTimeout(function() {
                  initializeApp();
                }, 50);
                break;
              }
            }
          }
        }
      });
    });

    // Start observing changes to the document body
    window.pluginContentObserver.observe(document.body, {
      childList: true,
      subtree: true
    });
  }

  function loadTransactionTable(tableType, tableBodyId, page = 1, search = '') {
    // Update state tracking
    window.tableStates[tableType] = {
      page: page,
      search: search
    };

    const params = new URLSearchParams({
      action: 'get_transactions',
      table_type: tableType,
      page: page.toString(),
      search: search,
      auth_id: "<?php echo $auth_id; ?>"
    });

    const apiUrl = "<?php echo $plugin_url; ?>/views/fi_api.php?" + params
      .toString();

    return fetch(apiUrl)
      .then(res => res.json())
      .then(response => {
        if (response.status === 'success') {
          const {
            transactions,
            pagination
          } = response.data;

          // Check if current page is empty but there are other pages available
          if ((!transactions || transactions.length === 0) && pagination.total_count > 0 && pagination.current_page >
            1) {
            // Redirect to the last available page
            const lastPage = Math.max(1, pagination.total_pages);
            if (pagination.current_page > lastPage) {
              // Current page is beyond available pages, go to last page
              return loadTransactionTable(tableType, tableBodyId, lastPage, window.tableStates[tableType].search);
            }
          }

          renderTransactionTable(tableBodyId, transactions, tableType);
          renderPagination(tableType, pagination);
          updatePaginationInfo(tableType, pagination);
        } else {
          document.getElementById(tableBodyId).innerHTML =
            `<tr><td colspan="6" class="text-center text-danger">Error: ${response.message}</td></tr>`;
        }
      })
      .catch(error => {
        document.getElementById(tableBodyId).innerHTML =
          `<tr><td colspan="6" class="text-center text-danger">Error loading data</td></tr>`;
      });
  }

  function renderTransactionTable(tableBodyId, transactions, tableType) {
    const tableBody = document.getElementById(tableBodyId);

    if (!transactions || transactions.length === 0) {
      tableBody.innerHTML = '<tr><td colspan="6" class="text-center">No transactions found.</td></tr>';
      return;
    }

    tableBody.innerHTML = transactions.map(transaction => {
      const statusClass = getStatusClass(transaction.transaction_status);
      const actions = generateActionButtons(transaction, tableType);

      return `
            <tr>
                <td>${escapeHtml(transaction.c_name)}</td>
                <td>${escapeHtml(transaction.c_email_mobile)}</td>
                <td>${escapeHtml(transaction.transaction_amount)} ${escapeHtml(transaction.transaction_currency)}</td>
                <td>${escapeHtml(transaction.created_at)}</td>
                <td><span class="badge ${statusClass}">${escapeHtml(transaction.transaction_status)}</span></td>
                <td>${actions}</td>
            </tr>
        `;
    }).join('');
  }

  function renderPagination(tableType, pagination) {
    const paginationId = getPaginationId(tableType);
    const paginationContainer = document.getElementById(paginationId);

    if (!paginationContainer) {
      return;
    }

    const {
      current_page,
      total_pages
    } = pagination;
    let paginationHtml = '';

    if (total_pages <= 1) {
      paginationContainer.innerHTML = '';
      return;
    }

    // Previous button
    const prevDisabled = current_page <= 1;
    const prevPage = Math.max(1, current_page - 1);
    if (prevDisabled) {
      paginationHtml += `<li class="paginate_item page-item disabled">
            <span class="page-link"><span aria-hidden="true">Prev</span></span>
        </li>`;
    } else {
      paginationHtml += `<li class="paginate_item page-item">
            <a class="paginate_button previous page-link pagination-link" data-table="${tableType}" data-page="${prevPage}" href="#">
                <span aria-hidden="true">Prev</span>
            </a>
        </li>`;
    }

    // Page numbers - Mobile responsive
    const isMobile = window.innerWidth < 768;

    if (isMobile) {
      // Mobile: Show current-2, current-1, current, current+1, current+2 (5 pages total)
      const startPage = Math.max(1, current_page - 2);
      const endPage = Math.min(total_pages, current_page + 2);

      // Show pages around current page
      for (let i = startPage; i <= endPage; i++) {
        const activeClass = i === current_page ? 'active' : '';
        paginationHtml += `<li class="paginate_item page-item ${activeClass}">
                <a class="paginate_button page-link pagination-link" data-table="${tableType}" data-page="${i}" href="#">${i}</a>
            </li>`;
      }

    } else {
      // Desktop or simple mobile pagination
      const pageRange = isMobile ? 1 : 2;
      const startPage = Math.max(1, current_page - pageRange);
      const endPage = Math.min(total_pages, current_page + pageRange);

      // Show first page and ellipsis for desktop
      if (startPage > 1 && !isMobile) {
        paginationHtml += `<li class="paginate_item page-item">
                <a class="paginate_button page-link pagination-link" data-table="${tableType}" data-page="1" href="#">1</a>
            </li>`;
        if (startPage > 2) {
          paginationHtml += '<li class="paginate_item page-item disabled"><span class="page-link">...</span></li>';
        }
      }

      // Show page numbers
      for (let i = startPage; i <= endPage; i++) {
        const activeClass = i === current_page ? 'active' : '';
        paginationHtml += `<li class="paginate_item page-item ${activeClass}">
                <a class="paginate_button page-link pagination-link" data-table="${tableType}" data-page="${i}" href="#">${i}</a>
            </li>`;
      }

      // Show last page and ellipsis for desktop
      if (endPage < total_pages && !isMobile) {
        if (endPage < total_pages - 1) {
          paginationHtml += '<li class="paginate_item page-item disabled"><span class="page-link">...</span></li>';
        }
        paginationHtml += `<li class="paginate_item page-item">
                <a class="paginate_button page-link pagination-link" data-table="${tableType}" data-page="${total_pages}" href="#">${total_pages}</a>
            </li>`;
      }
    }

    // Next button
    const nextDisabled = current_page >= total_pages;
    const nextPage = Math.min(total_pages, current_page + 1);
    if (nextDisabled) {
      paginationHtml += `<li class="paginate_item page-item disabled">
            <span class="page-link"><span aria-hidden="true">Next</span></span>
        </li>`;
    } else {
      paginationHtml += `<li class="paginate_item page-item">
            <a class="paginate_button next page-link pagination-link" data-table="${tableType}" data-page="${nextPage}" href="#">
                <span aria-hidden="true">Next</span>
            </a>
        </li>`;
    }

    paginationContainer.innerHTML = paginationHtml;
  }

  function getPaginationId(tableType) {
    switch (tableType) {
      case 'failed':
        return 'datatable_pagination';
      case 'initialize':
        return 'initialize_pagination';
      case 'done':
        return 'done_pagination';
      default:
        return '';
    }
  }

  function generateActionButtons(transaction, tableType) {
    let buttons = `
        <div class="btn-group" role="group">
            <a class="btn btn-white btn-sm metadata-btn" data-metadata="${escapeHtml(transaction.transaction_metadata)}" href="#" role="button">
                <i class="bi bi-bar-chart-steps"></i> Metadata
            </a>
                  </div>
    `;

    if (tableType === 'failed' || tableType === 'done') {
      buttons += `
            <div onclick="load_content('View Transaction','view-transaction?ref=${transaction.id}','nav-btn-transaction')" class="btn-group" role="group">
                <a class="btn btn-white btn-sm">
                    <i class="bi-eye me-1"></i> View
                </a>
              </div>
        `;
    }

    if (tableType === 'failed' || tableType === 'initialize') {
      buttons += `
            <div class="btn-group" role="group">
                <a class="btn btn-white btn-sm done-btn" data-transaction-id="${transaction.id}" href="#" role="button">
                    <i class="bi bi-check-all"></i> Done
                </a>
            </div>
        `;
    }

    return buttons;
  }

  function updatePaginationInfo(tableType, pagination) {
    const showingId = getShowingResultId(tableType);
    const totalId = getTotalResultId(tableType);

    const showingElement = document.getElementById(showingId);
    const totalElement = document.getElementById(totalId);

    if (showingElement) showingElement.textContent = `${pagination.start_item}-${pagination.end_item}`;
    if (totalElement) totalElement.textContent = pagination.total_count;
  }

  function getShowingResultId(tableType) {
    switch (tableType) {
      case 'failed':
        return 'showing-result';
      case 'initialize':
        return 'showing-result-initialize';
      case 'done':
        return 'showing-result-done';
      default:
        return '';
    }
  }

  function getTotalResultId(tableType) {
    switch (tableType) {
      case 'failed':
        return 'total-result';
      case 'initialize':
        return 'total-result-initialize';
      case 'done':
        return 'total-result-done';
      default:
        return '';
    }
  }

  function getStatusClass(status) {
    switch (status) {
      case 'failed':
        return 'bg-danger';
      case 'initialize':
        return 'bg-info';
      case 'success':
        return 'bg-success';
      default:
        return 'bg-secondary';
    }
  }

  function escapeHtml(text) {
    const map = {
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#039;'
    };
    return String(text || '').replace(/[&<>"']/g, (m) => map[m]);
  }

  function escapeForJs(text) {
    return String(text || '')
      .replace(/\\/g, '\\\\') // Escape backslashes first
      .replace(/'/g, "\\'") // Escape single quotes
      .replace(/"/g, '\\"') // Escape double quotes
      .replace(/\n/g, '\\n') // Escape newlines
      .replace(/\r/g, '\\r') // Escape carriage returns
      .replace(/\t/g, '\\t') // Escape tabs
      .replace(/\f/g, '\\f') // Escape form feeds
      .replace(/\v/g, '\\v') // Escape vertical tabs
      .replace(/\0/g, '\\0') // Escape null characters
      .replace(/=/g, '\\x3D') // Escape equals signs
      .replace(/</g, '\\x3C') // Escape less than
      .replace(/>/g, '\\x3E'); // Escape greater than
  }

  // Mark transaction as done
  function markTransactionDone(transactionId) {
    const requestData = {
      action: 'mark_done',
      transaction_id: transactionId,
      auth_id: "<?php echo $auth_id; ?>"
    };

    fetch("<?php echo $plugin_url; ?>/views/fi_api.php", {
        method: "POST",
        headers: {
          "Content-Type": "application/json"
        },
        body: JSON.stringify(requestData)
      })
      .then(res => res.json())
      .then(response => {
        if (response.status === 'success') {
          // Reload all tables maintaining current page and search state
          const failedState = window.tableStates.failed;
          const initializeState = window.tableStates.initialize;
          const doneState = window.tableStates.done;

          loadTransactionTable('failed', 'datatable', failedState.page, failedState.search);
          loadTransactionTable('initialize', 'initializeTableBody', initializeState.page, initializeState.search);
          loadTransactionTable('done', 'doneTableBody', doneState.page, doneState.search);
        } else {
          alert('Error: ' + response.message);
        }
      })
      .catch(error => {
        alert('Error marking transaction as done');
      });
  }

  // Global done confirmation function
  window.showDoneConfirmation = function(transactionId) {
    window.currentTransactionId = transactionId;

    const modalElement = document.getElementById('confirmDoneModal');
    if (!modalElement) return;

    try {
      if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        const modal = new bootstrap.Modal(modalElement);
        modal.show();
      } else if (typeof $ !== 'undefined') {
        $('#confirmDoneModal').modal('show');
      } else {
        modalElement.style.display = 'block';
        modalElement.classList.add('show');
        document.body.classList.add('modal-open');
      }
    } catch (e) {
      // Silent fallback
    }
  };

  // Override handleConfirmClick
  window.handleConfirmClick = function(event) {
    if (event) {
      event.preventDefault();
      event.stopPropagation();
    }

    if (window.currentTransactionId) {
      const modalElement = document.getElementById('confirmDoneModal');
      const modal = bootstrap.Modal.getInstance(modalElement);
      if (modal) {
        modal.hide();
      } else {
        modalElement.style.display = 'none';
        modalElement.classList.remove('show');
        document.body.classList.remove('modal-open');
      }

      markTransactionDone(window.currentTransactionId);
      window.currentTransactionId = null;
    }
  };

  // Global metadata modal function
  window.showMetadataModal = function(metadata) {
    const tableBody = document.getElementById('metadataTableBody');
    if (!tableBody) return;

    tableBody.innerHTML = ''; // Clear existing content

    // Parse the metadata if it's a string
    let metadataObj;
    try {
      metadataObj = typeof metadata === 'string' ? JSON.parse(metadata) : metadata;
      console.log('Parsed metadata:', metadataObj);
    } catch (e) {
      console.error('Error parsing metadata:', e);
      metadataObj = {
        error: 'Invalid metadata format',
        raw: metadata
      };
    }

    // Create rows for each metadata key-value pair
    for (const [key, value] of Object.entries(metadataObj)) {
      const row = document.createElement('tr');

      // Key cell
      const keyCell = document.createElement('td');
      keyCell.textContent = key;
      row.appendChild(keyCell);

      // Value cell
      const valueCell = document.createElement('td');
      if (typeof value === 'object' && value !== null) {
        valueCell.textContent = JSON.stringify(value);
      } else {
        valueCell.textContent = value;
      }
      row.appendChild(valueCell);

      // Action cell
      const actionCell = document.createElement('td');
      const buttonGroup = document.createElement('div');
      buttonGroup.className = 'btn-group';

      // Copy button
      const copyButton = document.createElement('button');
      copyButton.className = 'btn btn-soft-primary btn-xs';
      copyButton.innerHTML = '<i class="bi bi-clipboard"></i>';
      copyButton.onclick = () => copyToClipboard(value);
      buttonGroup.appendChild(copyButton);

      // Add WhatsApp button if key contains phone/cell/number and value is a valid number
      const keyLower = key.toLowerCase();
      const valueStr = String(value).replace(/[^0-9]/g, ''); // Remove non-numeric characters
      if ((keyLower.includes('phone') || keyLower.includes('cell') || keyLower.includes('number')) && valueStr
        .length >= 10) {
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
    const modalElement = document.getElementById('metadataModal');
    if (!modalElement) {
      console.error('metadataModal element not found');
      return;
    }

    console.log('Showing modal...');

    // Try different methods to show modal
    if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
      try {
        const modal = new bootstrap.Modal(modalElement);
        modal.show();
        console.log('Modal shown with Bootstrap');
      } catch (e) {
        console.error('Bootstrap modal error:', e);
        // Fallback to jQuery
        if (typeof $ !== 'undefined') {
          $('#metadataModal').modal('show');
          console.log('Modal shown with jQuery');
        } else {
          // Manual fallback
          modalElement.style.display = 'block';
          modalElement.classList.add('show');
          document.body.classList.add('modal-open');
          console.log('Modal shown manually');
        }
      }
    } else if (typeof $ !== 'undefined') {
      $('#metadataModal').modal('show');
      console.log('Modal shown with jQuery');
    } else {
      // Manual fallback
      modalElement.style.display = 'block';
      modalElement.classList.add('show');
      document.body.classList.add('modal-open');
      console.log('Modal shown manually');
    }
  };

  // Global copy function
  window.copyToClipboard = function(text) {
    const textToCopy = typeof text === 'object' ? JSON.stringify(text) : text.toString();
    if (navigator.clipboard) {
      navigator.clipboard.writeText(textToCopy).catch(err => {
        // Fallback
        const textArea = document.createElement('textarea');
        textArea.value = textToCopy;
        document.body.appendChild(textArea);
        textArea.select();
        document.execCommand('copy');
        document.body.removeChild(textArea);
      });
    } else {
      // Fallback for older browsers
      const textArea = document.createElement('textarea');
      textArea.value = textToCopy;
      document.body.appendChild(textArea);
      textArea.select();
      document.execCommand('copy');
      document.body.removeChild(textArea);
    }
  };

  // Add pagination and metadata click handler
  document.addEventListener('click', function(e) {

    // Handle pagination clicks
    if (e.target.matches('.pagination-link') || e.target.closest('.pagination-link')) {
      e.preventDefault();

      // Get the actual link element (in case we clicked on a span inside)
      const linkElement = e.target.matches('.pagination-link') ? e.target : e.target.closest('.pagination-link');

      const tableType = linkElement.getAttribute('data-table');
      const page = parseInt(linkElement.getAttribute('data-page'));

      if (tableType && page && !isNaN(page)) {
        const tableBodyId = getTableBodyId(tableType);
        loadTransactionTable(tableType, tableBodyId, page);
      }
    }

    // Handle metadata button clicks
    if (e.target.matches('.metadata-btn') || e.target.closest('.metadata-btn')) {
      e.preventDefault();

      // Get the actual metadata button element
      const metadataBtn = e.target.matches('.metadata-btn') ? e.target : e.target.closest('.metadata-btn');
      const metadata = metadataBtn.getAttribute('data-metadata');

      if (metadata) {
        window.showMetadataModal(metadata);
      }
    }

    // Handle done button clicks
    if (e.target.matches('.done-btn') || e.target.closest('.done-btn')) {
      e.preventDefault();

      // Get the actual done button element
      const doneBtn = e.target.matches('.done-btn') ? e.target : e.target.closest('.done-btn');
      const transactionId = doneBtn.getAttribute('data-transaction-id');

      if (transactionId) {
        window.showDoneConfirmation(transactionId);
      }
    }

    // Handle confirm done button clicks
    if (e.target.matches('#confirmDoneBtn')) {
      e.preventDefault();
      e.stopPropagation();
      window.handleConfirmClick(e);
    }

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

  });

  // Search functionality with debouncing
  let searchTimeouts = {};

  function setupSearchListeners() {
    const searchInputs = [{
        id: 'datatableSearch',
        tableType: 'failed',
        tableBodyId: 'datatable'
      },
      {
        id: 'initializeSearch',
        tableType: 'initialize',
        tableBodyId: 'initializeTableBody'
      },
      {
        id: 'doneSearch',
        tableType: 'done',
        tableBodyId: 'doneTableBody'
      }
    ];

    searchInputs.forEach(({
      id,
      tableType,
      tableBodyId
    }) => {
      const searchInput = document.getElementById(id);
      if (searchInput) {
        searchInput.addEventListener('input', function(e) {
          const searchTerm = e.target.value.trim();

          // Clear existing timeout
          if (searchTimeouts[tableType]) {
            clearTimeout(searchTimeouts[tableType]);
          }

          // Set new timeout for debouncing (300ms delay)
          searchTimeouts[tableType] = setTimeout(() => {
            // Show loading state
            const tableBody = document.getElementById(tableBodyId);
            if (tableBody) {
              tableBody.innerHTML =
                '<tr><td colspan="6" class="text-center"><div class="spinner-border spinner-border-sm" role="status"><span class="visually-hidden">Searching...</span></div> Searching...</td></tr>';
            }

            // Reset to page 1 when searching
            loadTransactionTable(tableType, tableBodyId, 1, searchTerm);
          }, 300);
        });
      }
    });
  }


  function getTableBodyId(tableType) {
    switch (tableType) {
      case 'failed':
        return 'datatable';
      case 'initialize':
        return 'initializeTableBody';
      case 'done':
        return 'doneTableBody';
      default:
        return '';
    }
  }
</script>

<style>
  /* Ensure pagination container doesn't overflow */
  .dataTables_paginate {
    overflow-x: auto;
    white-space: nowrap;
  }

  .pagination {
    margin-bottom: 0 !important;
    flex-wrap: nowrap !important;
  }
</style>

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
        <button type="button" class="btn btn-primary" id="confirmDoneBtn">Confirm</button>
      </div>
    </div>
  </div>
</div>

<script>
  // Modal functions (keep existing functionality)
  function showMetadataModal(metadata) {
    console.log('showMetadataModal called with:', metadata);

    if (event) event.stopPropagation(); // Prevent the row click event
    const tableBody = document.getElementById('metadataTableBody');

    if (!tableBody) {
      console.error('metadataTableBody not found');
      return;
    }

    tableBody.innerHTML = ''; // Clear existing content

    // Parse the metadata if it's a string
    let metadataObj;
    try {
      metadataObj = typeof metadata === 'string' ? JSON.parse(metadata) : metadata;
      console.log('Parsed metadata:', metadataObj);
    } catch (e) {
      console.error('Error parsing metadata:', e);
      metadataObj = {
        error: 'Invalid metadata format',
        raw: metadata
      };
    }

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
    const modalElement = document.getElementById('metadataModal');
    if (!modalElement) {
      console.error('metadataModal element not found');
      return;
    }

    console.log('Showing modal...');

    // Check if Bootstrap is available
    if (typeof bootstrap === 'undefined') {
      console.error('Bootstrap is not loaded');
      // Fallback: try to show modal with jQuery if available
      if (typeof $ !== 'undefined') {
        $('#metadataModal').modal('show');
      } else {
        console.error('Neither Bootstrap nor jQuery is available');
        alert('Modal system not available. Metadata: ' + JSON.stringify(metadataObj, null, 2));
      }
      return;
    }

    try {
      const modal = new bootstrap.Modal(modalElement);
      modal.show();
      console.log('Modal shown successfully');
    } catch (e) {
      console.error('Error showing modal:', e);
      // Fallback
      modalElement.style.display = 'block';
      modalElement.classList.add('show');
    }
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
</script>