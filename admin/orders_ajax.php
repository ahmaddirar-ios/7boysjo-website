<?php
/**
 * AJAX Handlers for Order Management
 * POST: action, csrf
 *
 * Actions:
 *   update_status   — {order_id, new_status}
 *   delete          — {order_id}
 *   get_details     — {order_id} (returns JSON)
 *   bulk_delete     — {order_ids[]}
 *   bulk_status     — {order_ids[], new_status}
 *   send_whatsapp   — {order_id, phone, message}
 */

// Security guard
if (!defined('SITE_DIR')) { http_response_code(403); exit('Forbidden'); }

if (!function_exists('is_logged_in') || !is_logged_in()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
    exit;
}

$QUOTES_FILE = SITE_DIR . '/admin/data/quotes.json';
$CSRF_NAME = 'orders_csrf';

// ---- CSRF ----
function orders_ajax_csrf_check($token) {
    global $CSRF_NAME;
    if (session_status() === PHP_SESSION_NONE) @session_start();
    return !empty($token) && !empty($_SESSION[$CSRF_NAME]) && hash_equals($_SESSION[$CSRF_NAME], $token);
}

// ---- Status labels (matches orders.php) ----
function status_label_info($s) {
    $map = [
        'new'        => ['label' => 'New'],
        'pending'    => ['label' => 'Pending'],
        'processing' => ['label' => 'Processing'],
        'completed'  => ['label' => 'Completed'],
        'cancelled'  => ['label' => 'Cancelled'],
        'viewed'     => ['label' => 'Pending'],
        'confirmed'  => ['label' => 'Confirmed'],
    ];
    return $map[$s] ?? ['label' => ucfirst($s)];
}

// ---- Load / Save ----
function load_quotes_data() {
    global $QUOTES_FILE;
    $d = @file_exists($QUOTES_FILE) ? json_decode(@file_get_contents($QUOTES_FILE), true) : [];
    return is_array($d) ? $d : [];
}

function save_quotes_data($data) {
    global $QUOTES_FILE;
    // Acquire lock for atomic write
    $lock = @fopen(SITE_DIR . '/admin/data/.lock_admin', 'c');
    if ($lock) flock($lock, LOCK_EX);
    $r = @file_put_contents($QUOTES_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    if ($lock) flock($lock, LOCK_UN);
    return $r !== false;
}

function current_admin_user() {
    return function_exists('current_admin') ? current_admin() : 'admin';
}

// ---- Input ----
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// Only accept POST for state changes; GET for read-only get_details
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'POST') {
    $input = $_POST;
} elseif ($method === 'GET') {
    $input = $_GET;
} else {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$action = trim($input['action'] ?? '');

// Validate CSRF for all actions
if (!orders_ajax_csrf_check($input['csrf'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid security token. Refresh the page.']);
    exit;
}

// Sanitize order index
function sanitize_order_id($id) {
    $idx = (int)$id;
    if ($idx < 0) return -1;
    return $idx;
}

// ---- Route ----
$order_id = isset($input['order_id']) ? sanitize_order_id($input['order_id']) : -1;

switch ($action) {

    // ---- Get Order Details (GET or POST) ----
    case 'get_details':
        if ($order_id < 0) {
            echo json_encode(['ok' => false, 'error' => 'Invalid order ID']);
            exit;
        }
        $quotes = load_quotes_data();
        if (!isset($quotes[$order_id]) || !is_array($quotes[$order_id])) {
            echo json_encode(['ok' => false, 'error' => 'Order not found']);
            exit;
        }
        $q = $quotes[$order_id];
        $status = strtolower(trim($q['status'] ?? 'new'));
        $st_info = status_label_info($status);

        // Normalize items
        $items = [];
        if (is_array($q['items'] ?? null)) {
            foreach ($q['items'] as $it) {
                if (!is_array($it)) continue;
                $items[] = [
                    'slug' => (string)($it['slug'] ?? ''),
                    'name' => (string)($it['name'] ?? $it['slug'] ?? ''),
                    'qty'  => (int)($it['qty'] ?? 1),
                    'price' => (string)($it['price'] ?? ''),
                ];
            }
        }

        echo json_encode([
            'ok' => true,
            'data' => [
                'id'         => (string)($q['id'] ?? ''),
                'name'       => (string)($q['name'] ?? ''),
                'email'      => (string)($q['email'] ?? ''),
                'phone'      => (string)($q['phone'] ?? ''),
                'company'    => (string)($q['company'] ?? ''),
                'note'       => (string)($q['note'] ?? ''),
                'status'     => $status,
                'status_label' => $st_info['label'],
                'date'       => $q['date'] ?? $q['at'] ?? '',
                'items'      => $items,
                'item_count' => count($items),
                'total'      => $q['total'] ?? null,
                'token'      => (string)($q['token'] ?? ''),
            ]
        ]);
        exit;

    // ---- Update Order Status ----
    case 'update_status':
        if ($order_id < 0) {
            echo json_encode(['ok' => false, 'error' => 'Invalid order ID']);
            exit;
        }
        $new_status = strtolower(trim($input['new_status'] ?? ''));
        $allowed = ['new', 'pending', 'processing', 'completed', 'cancelled', 'viewed', 'confirmed'];
        if (!in_array($new_status, $allowed, true)) {
            echo json_encode(['ok' => false, 'error' => 'Invalid status value']);
            exit;
        }

        $quotes = load_quotes_data();
        if (!isset($quotes[$order_id]) || !is_array($quotes[$order_id])) {
            echo json_encode(['ok' => false, 'error' => 'Order not found']);
            exit;
        }

        $old_status = $quotes[$order_id]['status'] ?? 'new';
        $quotes[$order_id]['status'] = $new_status;
        $quotes[$order_id]['logs'][] = [
            'at'     => date('Y-m-d H:i:s'),
            'user'   => current_admin_user(),
            'action' => 'status changed: ' . $old_status . ' → ' . $new_status
        ];
        $quotes[$order_id]['handler'] = current_admin_user();

        if (!save_quotes_data($quotes)) {
            echo json_encode(['ok' => false, 'error' => 'Failed to save changes']);
            exit;
        }

        $label_info = status_label_info($new_status);
        echo json_encode(['ok' => true, 'data' => ['new_status' => $new_status, 'new_label' => $label_info['label']]]);
        exit;

    // ---- Delete Single Order ----
    case 'delete':
        if ($order_id < 0) {
            echo json_encode(['ok' => false, 'error' => 'Invalid order ID']);
            exit;
        }
        $quotes = load_quotes_data();
        if (!isset($quotes[$order_id]) || !is_array($quotes[$order_id])) {
            echo json_encode(['ok' => false, 'error' => 'Order not found']);
            exit;
        }

        array_splice($quotes, $order_id, 1);

        if (!save_quotes_data($quotes)) {
            echo json_encode(['ok' => false, 'error' => 'Failed to delete order']);
            exit;
        }

        echo json_encode(['ok' => true, 'data' => ['deleted_id' => $order_id]]);
        exit;

    // ---- Bulk Delete ----
    case 'bulk_delete':
        $ids = [];
        if (isset($input['order_ids']) && is_array($input['order_ids'])) {
            foreach ($input['order_ids'] as $id) {
                $i = (int)$id;
                if ($i >= 0) $ids[] = $i;
            }
        }
        if (empty($ids)) {
            echo json_encode(['ok' => false, 'error' => 'No orders selected']);
            exit;
        }

        $quotes = load_quotes_data();
        $deleted = 0;
        rsort($ids); // remove from end to avoid index shift
        foreach ($ids as $i) {
            if (isset($quotes[$i]) && is_array($quotes[$i])) {
                array_splice($quotes, $i, 1);
                $deleted++;
            }
        }

        if (!save_quotes_data($quotes)) {
            echo json_encode(['ok' => false, 'error' => 'Failed to save changes']);
            exit;
        }

        echo json_encode(['ok' => true, 'data' => ['deleted' => $deleted]]);
        exit;

    // ---- Bulk Status Change ----
    case 'bulk_status':
        $ids = [];
        if (isset($input['order_ids']) && is_array($input['order_ids'])) {
            foreach ($input['order_ids'] as $id) {
                $i = (int)$id;
                if ($i >= 0) $ids[] = $i;
            }
        }
        if (empty($ids)) {
            echo json_encode(['ok' => false, 'error' => 'No orders selected']);
            exit;
        }
        $new_status = strtolower(trim($input['new_status'] ?? ''));
        $allowed = ['new', 'pending', 'processing', 'completed', 'cancelled'];
        if (!in_array($new_status, $allowed, true)) {
            echo json_encode(['ok' => false, 'error' => 'Invalid status value']);
            exit;
        }

        $quotes = load_quotes_data();
        $updated = 0;
        foreach ($ids as $i) {
            if (!isset($quotes[$i]) || !is_array($quotes[$i])) continue;
            $old = $quotes[$i]['status'] ?? 'new';
            $quotes[$i]['status'] = $new_status;
            $quotes[$i]['logs'][] = [
                'at'     => date('Y-m-d H:i:s'),
                'user'   => current_admin_user(),
                'action' => 'bulk status: ' . $old . ' → ' . $new_status
            ];
            $quotes[$i]['handler'] = current_admin_user();
            $updated++;
        }

        if (!save_quotes_data($quotes)) {
            echo json_encode(['ok' => false, 'error' => 'Failed to save changes']);
            exit;
        }

        echo json_encode(['ok' => true, 'data' => ['updated' => $updated]]);
        exit;

    // ---- Send WhatsApp Notification ----
    case 'send_whatsapp':
        $phone = preg_replace('/[^0-9]/', '', (string)($input['phone'] ?? ''));
        $message = trim($input['message'] ?? '');
        if ($phone === '') {
            echo json_encode(['ok' => false, 'error' => 'No phone number provided']);
            exit;
        }
        if ($message === '') {
            echo json_encode(['ok' => false, 'error' => 'Message is empty']);
            exit;
        }

        // Build WhatsApp URL (wa.me format — opens web/app chat)
        $encoded = urlencode($message);
        $wa_url = 'https://wa.me/' . $phone . '?text=' . $encoded;

        // Log to order if applicable
        if ($order_id >= 0) {
            $quotes = load_quotes_data();
            if (isset($quotes[$order_id]) && is_array($quotes[$order_id])) {
                $quotes[$order_id]['logs'][] = [
                    'at'     => date('Y-m-d H:i:s'),
                    'user'   => current_admin_user(),
                    'action' => 'WhatsApp notification sent to +' . $phone
                ];
                save_quotes_data($quotes);
            }
        }

        echo json_encode(['ok' => true, 'data' => ['wa_url' => $wa_url, 'phone' => $phone]]);
        exit;

    default:
        echo json_encode(['ok' => false, 'error' => 'Unknown action: ' . $action]);
        exit;
}
