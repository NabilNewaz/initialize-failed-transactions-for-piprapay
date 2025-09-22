<?php

header("Content-Type: application/json");

$plugin_slug = 'initialize-failed-transactions';

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

// Database connection
if (!isset($conn)) {
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    if ($conn->connect_error) {
        die(json_encode(["status" => "error", "message" => "Database connection failed"]));
    }
    $conn->query("SET NAMES utf8");
    if (!empty($db_prefix)) {
        $conn->query("SET sql_mode = ''");
    }
}

// Get request parameters
$request_method = $_SERVER['REQUEST_METHOD'];

if ($request_method === 'POST') {
    $input = json_decode(file_get_contents("php://input"), true);
    $action = $input['action'] ?? '';
    $auth_id = $input['auth_id'] ?? '';
} else {
    $action = $_GET['action'] ?? '';
    $auth_id = $_GET['auth_id'] ?? '';
}

// Validate auth_id
$sql = "SELECT plugin_array FROM {$db_prefix}plugins WHERE plugin_slug = '{$plugin_slug}'";
$result = $conn->query($sql);

if (!$result || !($row = $result->fetch_assoc())) {
    echo json_encode(["status" => "error", "message" => "Plugin not found"]);
    exit;
}

$plugin_array = json_decode($row['plugin_array'], true);
if (!$plugin_array || !isset($plugin_array['auth_id']) || $plugin_array['auth_id'] !== $auth_id) {
    echo json_encode(["status" => "error", "message" => "Invalid auth_id"]);
    exit;
}

// Helper functions
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
    if (!$result) {
        return false;
    }

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
    if (!$result) {
        return 0;
    }

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
    if (!$result) {
        return false;
    }

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
    if (!$result) {
        return 0;
    }

    $row = $result->fetch_assoc();
    return $row['total'];
}

// Handle different actions
if ($action === 'get_transactions') {
    $table_type = $_GET['table_type'] ?? 'failed';
    $page = max(1, intval($_GET['page'] ?? 1));
    $items_per_page = 6;
    $offset = ($page - 1) * $items_per_page;
    $search = $_GET['search'] ?? '';

    if ($table_type === 'done') {
        $transactions = getDoneTransaction($items_per_page, $offset, $search);
        $total_count = getDoneTransactionCount($search);
    } else {
        $transactions = getTransactions($table_type, $items_per_page, $offset, $search);
        $total_count = getTransactionCount($table_type, $search);
    }

    if ($transactions === false) {
        echo json_encode(["status" => "error", "message" => "Database query failed"]);
        exit;
    }

    $total_pages = ceil($total_count / $items_per_page);

    echo json_encode([
        "status" => "success",
        "data" => [
            "transactions" => $transactions,
            "pagination" => [
                "current_page" => $page,
                "total_pages" => $total_pages,
                "total_count" => $total_count,
                "items_per_page" => $items_per_page,
                "start_item" => $offset + 1,
                "end_item" => min($offset + $items_per_page, $total_count)
            ]
        ]
    ]);
} elseif ($action === 'mark_done') {
    // Get JSON input for POST request
    $input = json_decode(file_get_contents("php://input"), true);
    $transaction_id = $input['transaction_id'] ?? $_POST['transaction_id'] ?? 0;

    if (!$transaction_id) {
        echo json_encode(["status" => "error", "message" => "Transaction ID is required"]);
        exit;
    }

    $transaction_id = intval($transaction_id);
    $query = "SELECT transaction_metadata FROM " . $db_prefix . "transaction WHERE id = " . $transaction_id;
    $result = $conn->query($query);

    if (!$result) {
        echo json_encode(["status" => "error", "message" => "Database query failed"]);
        exit;
    }

    if ($row = $result->fetch_assoc()) {
        $metadata = json_decode($row['transaction_metadata'], true) ?: array();
        $metadata['is_ifa_done'] = 'true';
        $updated_metadata = json_encode($metadata);
        $update_query = "UPDATE " . $db_prefix . "transaction SET transaction_metadata = '" . $conn->real_escape_string($updated_metadata) . "' WHERE id = " . $transaction_id;

        if ($conn->query($update_query)) {
            echo json_encode(["status" => "success", "message" => "Transaction marked as done"]);
        } else {
            echo json_encode(["status" => "error", "message" => "Failed to update transaction"]);
        }
    } else {
        echo json_encode(["status" => "error", "message" => "Transaction not found"]);
    }
} elseif ($action === 'get_counts') {
    $failed_count = getTransactionCount('failed');
    $initialize_count = getTransactionCount('initialize');
    $done_count = getDoneTransactionCount();

    echo json_encode([
        "status" => "success",
        "data" => [
            "failed_count" => $failed_count,
            "initialize_count" => $initialize_count,
            "done_count" => $done_count
        ]
    ]);
} else {
    echo json_encode(["status" => "error", "message" => "Invalid action"]);
}

if (isset($conn)) {
    $conn->close();
}
