<?php
/**
 * 7 Boys® — Notification System
 * WhatsApp (UltraMsg) + Email notifications, templates, and history
 */
require_once __DIR__ . '/config.php';
if (!is_logged_in()) { header('Location: login.php'); exit; }

define('NOTIFICATIONS_LOG', __DIR__ . '/data/notifications_log.json');
define('NOTIFY_FILE', __DIR__ . '/data/notify.json');

$msg = '';
$errors = [];

$default_templates = [
    'new_order' => [
        'name' => 'New Order',
        'subject' => 'New Order #{order_id} - 7 Boys',
        'message' => "New order received from {customer_name}.\n\nOrder ID: #{order_id}\nTotal: {order_total}\nItems: {item_count}\n\nPlease process this order promptly.",
        'email_subject' => 'New Order #{order_id} - 7 Boys®',
        'email_body' => "Hello Team,\n\nA new order has been placed.\n\nOrder ID: #{order_id}\nCustomer: {customer_name}\nEmail: {customer_email}\nPhone: {customer_phone}\nTotal: {order_total} JOD\nItems: {item_count}\n\nPlease review and process.\n\n— 7 Boys Admin",
    ],
    'order_status_update' => [
        'name' => 'Order Status Update',
        'subject' => 'Order #{order_id} Status Update',
        'message' => "Dear {customer_name},\n\nYour order #{order_id} status has been updated to: {status}\n\nTrack your order: {order_link}\n\nThank you for choosing 7 Boys!",
        'email_subject' => 'Order #{order_id} Status Update - 7 Boys®',
        'email_body' => "Dear {customer_name},\n\nYour order #{order_id} status has been updated.\n\nNew Status: {status}\nOrder Date: {order_date}\n\nIf you have questions, contact us:\n📞 +962 79 5816444\n✉️ wael@7boys.com.jo\n\nBest regards,\n7 Boys Team",
    ],
    'low_stock' => [
        'name' => 'Low Stock Alert',
        'subject' => 'Low Stock Alert - {product_name}',
        'message' => "⚠️ LOW STOCK ALERT\n\nProduct: {product_name}\nSKU: {sku}\nCurrent Stock: {stock}\nMinimum Threshold: {threshold}\n\nPlease reorder soon to avoid stockouts.",
        'email_subject' => 'Low Stock Alert - {product_name} - 7 Boys',
        'email_body' => "Low Stock Alert\n\nThe following product is running low:\n\nProduct: {product_name}\nSKU: {sku}\nCurrent Stock: {stock}\nMinimum Threshold: {threshold}\n\nPlease reorder soon to avoid stockouts.\n\n— 7 Boys Inventory System",
    ],
    'new_customer' => [
        'name' => 'New Customer Registration',
        'subject' => 'New Customer: {customer_name}',
        'message' => "New customer registered!\n\nName: {customer_name}\nEmail: {customer_email}\nPhone: {customer_phone}\nCompany: {company}\n\nWelcome them and follow up!",
        'email_subject' => 'Welcome to 7 Boys® - {customer_name}',
        'email_body' => "Dear {customer_name},\n\nWelcome to 7 Boys | Rubu Al Quds!\n\nThank you for registering with us. Our team will reach out shortly to discuss your requirements.\n\nFor immediate assistance:\n📞 +962 79 5816444\n✉️ wael@7boys.com.jo\n\nBest regards,\n7 Boys Team",
    ],
];

function load_notifications_log() {
    if (!file_exists(NOTIFICATIONS_LOG)) return [];
    $d = json_decode(file_get_contents(NOTIFICATIONS_LOG), true);
    return is_array($d) ? $d : [];
}

function save_notifications_log($data) {
    if (!is_dir(dirname(NOTIFICATIONS_LOG))) mkdir(dirname(NOTIFICATIONS_LOG), 0755, true);
    file_put_contents(NOTIFICATIONS_LOG, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function send_whatsapp_message($phone, $message) {
    $cfg = notify_cfg();
    $inst = trim($cfg['um']['instance'] ?? '');
    $tok = trim($cfg['um']['token'] ?? '');
    
    if ($inst === '' || $tok === '') {
        return ['success' => false, 'error' => 'UltraMsg API not configured. Please set instance and token in settings.'];
    }
    
    $phone = preg_replace('/[^0-9+]/', '', $phone);
    if (empty($phone)) {
        return ['success' => false, 'error' => 'Invalid phone number.'];
    }
    
    $ch = curl_init('https://api.ultramsg.com/' . urlencode($inst) . '/messages/chat');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_POSTFIELDS => http_build_query([
            'token' => $tok,
            'to' => $phone,
            'body' => $message,
        ]),
    ]);
    
    $response = @curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    @curl_close($ch);
    
    if ($curl_error) {
        return ['success' => false, 'error' => 'Connection error: ' . $curl_error];
    }
    
    if ($http_code !== 200) {
        return ['success' => false, 'error' => 'API returned HTTP ' . $http_code];
    }
    
    if ($response && strpos($response, '"sent"') !== false) {
        return ['success' => true, 'response' => $response];
    }
    
    return ['success' => false, 'error' => 'Unknown API response: ' . substr($response, 0, 200)];
}

function send_email_notification($to, $subject, $body) {
    $to = trim($to);
    if (empty($to) || strpos($to, '@') === false) {
        return ['success' => false, 'error' => 'Invalid email address.'];
    }
    
    $subject = trim(preg_replace('/[\r\n]+/', ' ', $subject));
    $headers = "From: notify@7boysjo.com\r\nContent-Type: text/plain; charset=UTF-8";
    
    $result = @mail($to, $subject, $body, $headers);
    
    if ($result) {
        return ['success' => true];
    }
    
    return ['success' => false, 'error' => 'mail() function returned false. Check server mail configuration.'];
}

function log_notification($type, $channel, $recipient, $subject, $status, $error = '') {
    $log = load_notifications_log();
    $log[] = [
        'id' => uniqid('notif_', true),
        'type' => $type,
        'channel' => $channel,
        'recipient' => $recipient,
        'subject' => $subject,
        'status' => $status,
        'error' => $error,
        'timestamp' => date('Y-m-d H:i:s'),
        'user' => current_admin(),
    ];
    
    // Keep last 500 entries
    if (count($log) > 500) {
        $log = array_slice($log, -500);
    }
    
    save_notifications_log($log);
    return $log;
}

function render_template($template, $vars) {
    $message = $template;
    foreach ($vars as $key => $value) {
        $message = str_replace('{' . $key . '}', $value, $message);
    }
    return $message;
}

$log = load_notifications_log();
$notify_cfg = notify_cfg();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!empty($_POST['csrf']) && !csrf_check($_POST['csrf'])) {
        $errors[] = 'Invalid security token.';
    }

    // Send WhatsApp message
    if (isset($_POST['send_whatsapp']) && empty($errors)) {
        $phone = trim($_POST['wa_phone'] ?? '');
        $message = trim($_POST['wa_message'] ?? '');
        $template = $_POST['wa_template'] ?? 'custom';
        
        if (empty($phone)) {
            $errors[] = 'Phone number is required.';
        } elseif (empty($message)) {
            $errors[] = 'Message is required.';
        } else {
            $result = send_whatsapp_message($phone, $message);
            
            if ($result['success']) {
                log_notification('whatsapp_' . $template, 'whatsapp', $phone, substr($message, 0, 50), 'sent');
                $msg = 'WhatsApp message sent successfully to ' . $phone;
            } else {
                log_notification('whatsapp_' . $template, 'whatsapp', $phone, substr($message, 0, 50), 'failed', $result['error']);
                $errors[] = 'Failed to send WhatsApp: ' . $result['error'];
            }
        }
    }

    // Send email notification
    if (isset($_POST['send_email']) && empty($errors)) {
        $email_to = trim($_POST['email_to'] ?? '');
        $email_subject = trim($_POST['email_subject'] ?? '');
        $email_body = trim($_POST['email_body'] ?? '');
        $template = $_POST['email_template'] ?? 'custom';
        
        if (empty($email_to)) {
            $errors[] = 'Email address is required.';
        } elseif (empty($email_subject)) {
            $errors[] = 'Subject is required.';
        } elseif (empty($email_body)) {
            $errors[] = 'Message body is required.';
        } else {
            $result = send_email_notification($email_to, $email_subject, $email_body);
            
            if ($result['success']) {
                log_notification('email_' . $template, 'email', $email_to, $email_subject, 'sent');
                $msg = 'Email sent successfully to ' . $email_to;
            } else {
                log_notification('email_' . $template, 'email', $email_to, $email_subject, 'failed', $result['error']);
                $errors[] = 'Failed to send email: ' . $result['error'];
            }
        }
    }

    // Test notification
    if (isset($_POST['test_notification']) && empty($errors)) {
        $test_phone = trim($_POST['test_phone'] ?? '');
        $test_email = trim($_POST['test_email'] ?? '');
        
        if (!empty($test_phone)) {
            $result = send_whatsapp_message($test_phone, "🔔 Test notification from 7 Boys Admin\n\nThis is a test message to verify WhatsApp notifications are working.\n\nTime: " . date('Y-m-d H:i:s'));
            if ($result['success']) {
                log_notification('test', 'whatsapp', $test_phone, 'Test notification', 'sent');
                $msg = 'Test WhatsApp sent to ' . $test_phone;
            } else {
                log_notification('test', 'whatsapp', $test_phone, 'Test notification', 'failed', $result['error']);
                $errors[] = 'WhatsApp test failed: ' . $result['error'];
            }
        }
        
        if (!empty($test_email)) {
            $result = send_email_notification($test_email, '7 Boys - Test Notification', "This is a test email notification from 7 Boys Admin.\n\nTime: " . date('Y-m-d H:i:s') . "\n\nIf you received this, email notifications are working correctly.");
            if ($result['success']) {
                log_notification('test', 'email', $test_email, 'Test notification', 'sent');
                $msg = (!empty($msg) ? $msg . ' | ' : '') . 'Test email sent to ' . $test_email;
            } else {
                log_notification('test', 'email', $test_email, 'Test notification', 'failed', $result['error']);
                $errors[] = (!empty($errors) ? ' | ' : '') . 'Email test failed: ' . $result['error'];
            }
        }
        
        if (empty($test_phone) && empty($test_email)) {
            $errors[] = 'Please provide a phone number or email address for testing.';
        }
    }

    // Save UltraMsg config
    if (isset($_POST['save_ultramsg']) && empty($errors)) {
        $notify_cfg['um']['instance'] = trim($_POST['um_instance'] ?? '');
        $notify_cfg['um']['token'] = trim($_POST['um_token'] ?? '');
        save_notify_cfg($notify_cfg);
        $msg = 'UltraMsg configuration saved.';
    }

    // Reload config after changes
    $notify_cfg = notify_cfg();
}

// Pagination for log
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;
$total_notifications = count($log);
$total_pages = max(1, ceil($total_notifications / $per_page));
$offset = ($page - 1) * $per_page;
$log_paginated = array_slice(array_reverse($log), $offset, $per_page);

$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
<title>Notifications — 7 Boys® Admin</title>
<style>
.notif-manager{max-width:1400px;margin:0 auto;padding:28px 20px 60px}
.notif-head{display:flex;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:22px}
.notif-head h1{font-family:var(--display);font-size:28px;font-weight:800;letter-spacing:-.02em}
.notif-head p{color:var(--muted);font-size:14px}
.notif-grid{display:grid;grid-template-columns:1fr 1fr;gap:24px}
@media(max-width:1000px){.notif-grid{grid-template-columns:1fr}}
.panel{background:var(--card);border:1px solid var(--card-line);border-radius:16px;padding:20px;box-shadow:var(--shadow-sm);margin-bottom:20px}
.panel h2{font-size:16px;font-weight:700;margin-bottom:14px;display:flex;align-items:center;gap:8px}
.form-row{margin-bottom:12px}
.form-row label{display:block;font-size:13px;font-weight:600;color:var(--ink);margin-bottom:4px}
.form-row input[type=text],.form-row textarea,.form-row select{width:100%;padding:9px 12px;border:1px solid var(--line);border-radius:8px;font-size:13px;background:#fff;box-sizing:border-box}
.form-row textarea{min-height:80px;resize:vertical}
.btn-notif{background:var(--green);color:#fff;border:none;padding:11px 22px;border-radius:10px;font-weight:600;cursor:pointer;font-size:14px}
.btn-notif:hover{opacity:.9}
.btn-notif.secondary{background:var(--card);color:var(--green);border:1px solid var(--green)}
.actions{display:flex;gap:10px;margin-top:16px;flex-wrap:wrap}
.log-table{width:100%;border-collapse:collapse;font-size:13px}
.log-table th,.log-table td{padding:10px 12px;text-align:left;border-bottom:1px solid var(--line)}
.log-table th{background:var(--bg-soft);font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.03em}
.log-table tr:hover{background:var(--bg-soft)}
.badge-status{padding:4px 10px;border-radius:999px;font-size:11px;font-weight:700;text-transform:uppercase}
.badge-sent{background:#dcfce7;color:#166534}
.badge-failed{background:#fee2e2;color:#991b1b}
.badge-pending{background:#fef3c7;color:#92400e}
.channel-badge{padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;text-transform:uppercase}
.channel-wa{background:#dcfce7;color:#166534}
.channel-email{background:#dbeafe;color:#1e40af}
.pagination{display:flex;gap:6px;justify-content:center;margin-top:16px;flex-wrap:wrap}
.pagination a,.pagination span{padding:6px 12px;border:1px solid var(--line);border-radius:6px;text-decoration:none;font-size:13px}
.pagination span.active{background:var(--green);color:#fff;border-color:var(--green)}
.pagination a:hover{background:var(--bg-soft)}
.stats-row{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:20px}
.stat-card{background:var(--card);border:1px solid var(--card-line);border-radius:12px;padding:16px;text-align:center}
.stat-card .num{font-size:24px;font-weight:800}
.stat-card .lbl{font-size:12px;color:var(--muted);margin-top:4px}
.stat-card.green .num{color:#16a34a}
.stat-card.red .num{color:#dc2626}
.stat-card.blue .num{color:#2563eb}
.config-status{display:flex;align-items:center;gap:8px;padding:10px 14px;border-radius:10px;font-size:13px;font-weight:600;margin-bottom:12px}
.config-ok{background:#dcfce7;color:#166534}
.config-missing{background:#fee2e2;color:#991b1b}
</style>
</head>
<body style="background:var(--bg)">
<div class="notif-manager">
    <a href="/admin/" style="display:inline-flex;align-items:center;gap:6px;font-weight:700;color:var(--green);margin-bottom:14px">← Back to Admin</a>
    <div class="notif-head">
        <div>
            <h1>🔔 Notification Center</h1>
            <p>Send WhatsApp & email notifications, manage templates, and view history</p>
        </div>
    </div>

    <?php if ($msg): ?><div class="msg" style="margin:16px 0;padding:12px 16px;background:#dcfce7;color:#166534;border-radius:10px;font-weight:600;"><?= esc($msg) ?></div><?php endif; ?>
    <?php foreach ($errors as $e): ?><div class="msg" style="margin:8px 0;padding:12px 16px;background:#fee2e2;color:#991b1b;border-radius:10px;font-weight:600;"><?= esc($e) ?></div><?php endif; ?>

    <!-- Stats Row -->
    <div class="stats-row">
        <div class="stat-card green">
            <div class="num"><?= count(array_filter($log, fn($x) => $x['status'] === 'sent')) ?></div>
            <div class="lbl">Sent Successfully</div>
        </div>
        <div class="stat-card red">
            <div class="num"><?= count(array_filter($log, fn($x) => $x['status'] === 'failed')) ?></div>
            <div class="lbl">Failed</div>
        </div>
        <div class="stat-card blue">
            <div class="num"><?= count($log) ?></div>
            <div class="lbl">Total Notifications</div>
        </div>
    </div>

    <div class="notif-grid">
        <!-- Left Column: Send Forms -->
        <div>
            <!-- UltraMsg Configuration -->
            <div class="panel">
                <h2>⚙️ UltraMsg API Configuration</h2>
                <?php $um_configured = !empty($notify_cfg['um']['instance']) && !empty($notify_cfg['um']['token']); ?>
                <div class="config-status <?= $um_configured ? 'config-ok' : 'config-missing' ?>">
                    <?= $um_configured ? '✅ UltraMsg API configured' : '⚠️ UltraMsg API not configured' ?>
                </div>
                <form method="POST">
                    <input type="hidden" name="csrf" value="<?= esc($csrf) ?>">
                    <div class="form-row">
                        <label>Instance ID</label>
                        <input type="text" name="um_instance" value="<?= esc($notify_cfg['um']['instance'] ?? '') ?>" placeholder="e.g. instance12345">
                    </div>
                    <div class="form-row">
                        <label>Access Token</label>
                        <input type="text" name="um_token" value="<?= esc($notify_cfg['um']['token'] ?? '') ?>" placeholder="Your UltraMsg API token">
                    </div>
                    <div class="actions">
                        <button type="submit" name="save_ultramsg" class="btn-notif">💾 Save Config</button>
                    </div>
                </form>
            </div>

            <!-- Send WhatsApp -->
            <div class="panel">
                <h2>📱 Send WhatsApp Message</h2>
                <form method="POST">
                    <input type="hidden" name="csrf" value="<?= esc($csrf) ?>">
                    <div class="form-row">
                        <label>Template</label>
                        <select name="wa_template" id="wa_template" onchange="applyWaTemplate()">
                            <option value="custom">Custom Message</option>
                            <?php foreach ($default_templates as $key => $tmpl): ?>
                            <option value="<?= $key ?>"><?= esc($tmpl['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-row">
                        <label>Phone Number (with country code)</label>
                        <input type="text" name="wa_phone" placeholder="+962 79 1234567" required>
                    </div>
                    <div class="form-row">
                        <label>Message</label>
                        <textarea name="wa_message" id="wa_message" placeholder="Type your WhatsApp message..." required></textarea>
                    </div>
                    <div class="actions">
                        <button type="submit" name="send_whatsapp" class="btn-notif">📤 Send WhatsApp</button>
                    </div>
                </form>
            </div>

            <!-- Send Email -->
            <div class="panel">
                <h2>✉️ Send Email Notification</h2>
                <form method="POST">
                    <input type="hidden" name="csrf" value="<?= esc($csrf) ?>">
                    <div class="form-row">
                        <label>Template</label>
                        <select name="email_template" id="email_template" onchange="applyEmailTemplate()">
                            <option value="custom">Custom Email</option>
                            <?php foreach ($default_templates as $key => $tmpl): ?>
                            <option value="<?= $key ?>"><?= esc($tmpl['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-row">
                        <label>To Email</label>
                        <input type="text" name="email_to" placeholder="recipient@example.com" required>
                    </div>
                    <div class="form-row">
                        <label>Subject</label>
                        <input type="text" name="email_subject" id="email_subject" placeholder="Email subject" required>
                    </div>
                    <div class="form-row">
                        <label>Message Body</label>
                        <textarea name="email_body" id="email_body" placeholder="Email body..." style="min-height:120px" required></textarea>
                    </div>
                    <div class="actions">
                        <button type="submit" name="send_email" class="btn-notif">📤 Send Email</button>
                    </div>
                </form>
            </div>

            <!-- Test Notification -->
            <div class="panel">
                <h2>🧪 Test Notifications</h2>
                <form method="POST">
                    <input type="hidden" name="csrf" value="<?= esc($csrf) ?>">
                    <div class="form-row">
                        <label>Test Phone Number (WhatsApp)</label>
                        <input type="text" name="test_phone" placeholder="+962 79 1234567">
                    </div>
                    <div class="form-row">
                        <label>Test Email Address</label>
                        <input type="text" name="test_email" placeholder="test@example.com">
                    </div>
                    <div class="actions">
                        <button type="submit" name="test_notification" class="btn-notif secondary">🧪 Send Test</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Right Column: Log & Templates -->
        <div>
            <!-- Notification Log -->
            <div class="panel">
                <h2>📋 Notification History</h2>
                <?php if (empty($log_paginated)): ?>
                <p style="text-align:center;color:var(--muted);padding:20px">No notifications sent yet.</p>
                <?php else: ?>
                <table class="log-table">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Type</th>
                            <th>Channel</th>
                            <th>Recipient</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($log_paginated as $entry): ?>
                        <tr>
                            <td style="font-size:12px;color:var(--muted)"><?= date('M j, H:i', strtotime($entry['timestamp'])) ?></td>
                            <td>
                                <span class="channel-badge channel-<?= $entry['channel'] === 'whatsapp' ? 'wa' : 'email' ?>">
                                    <?= $entry['channel'] === 'whatsapp' ? 'WA' : 'Email' ?>
                                </span>
                            </td>
                            <td><?= esc(ucfirst($entry['type'] ?? 'unknown')) ?></td>
                            <td><?= esc(substr($entry['recipient'] ?? '', 0, 25)) ?></td>
                            <td>
                                <span class="badge-status badge-<?= esc($entry['status']) ?>">
                                    <?= esc($entry['status']) ?>
                                </span>
                            </td>
                        </tr>
                        <?php if (!empty($entry['error'])): ?>
                        <tr>
                            <td colspan="5" style="font-size:11px;color:#dc2626;padding:4px 12px 12px"><?= esc($entry['error']) ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                    <a href="?page=<?= $page - 1 ?>">← Prev</a>
                    <?php endif; ?>
                    <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                    <?php if ($i === $page): ?>
                    <span class="active"><?= $i ?></span>
                    <?php else: ?>
                    <a href="?page=<?= $i ?>"><?= $i ?></a>
                    <?php endif; ?>
                    <?php endfor; ?>
                    <?php if ($page < $total_pages): ?>
                    <a href="?page=<?= $page + 1 ?>">Next →</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- Templates Reference -->
            <div class="panel">
                <h2>📝 Notification Templates</h2>
                <div style="font-size:13px;color:var(--muted);margin-bottom:12px">
                    Use these templates as starting points. Variables like <code>{customer_name}</code> will be replaced at send time.
                </div>
                <?php foreach ($default_templates as $key => $tmpl): ?>
                <div style="border:1px solid var(--line);border-radius:8px;padding:12px;margin-bottom:10px">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
                        <span style="font-weight:700;font-size:13px"><?= esc($tmpl['name']) ?></span>
                        <button type="button" class="channel-badge channel-wa" style="cursor:pointer;border:none" onclick="useWaTemplate('<?= $key ?>')" title="Use in WhatsApp">WA</button>
                        <button type="button" class="channel-badge channel-email" style="cursor:pointer;border:none" onclick="useEmailTemplate('<?= $key ?>')" title="Use in Email">Email</button>
                    </div>
                    <div style="font-size:12px;color:var(--muted);white-space:pre-wrap;max-height:60px;overflow:hidden"><?= esc(substr($tmpl['message'], 0, 120)) ?>...</div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Placeholder Variables Help -->
            <div class="panel">
                <h2>🔧 Template Variables</h2>
                <table class="log-table" style="font-size:12px">
                    <thead><tr><th>Variable</th><th>Description</th></tr></thead>
                    <tbody>
                        <tr><td><code>{customer_name}</code></td><td>Customer's full name</td></tr>
                        <tr><td><code>{customer_email}</code></td><td>Customer's email address</td></tr>
                        <tr><td><code>{customer_phone}</code></td><td>Customer's phone number</td></tr>
                        <tr><td><code>{order_id}</code></td><td>Order reference number</td></tr>
                        <tr><td><code>{order_total}</code></td><td>Order total amount</td></tr>
                        <tr><td><code>{item_count}</code></td><td>Number of items in order</td></tr>
                        <tr><td><code>{status}</code></td><td>Order status</td></tr>
                        <tr><td><code>{product_name}</code></td><td>Product name</td></tr>
                        <tr><td><code>{sku}</code></td><td>Product SKU</td></tr>
                        <tr><td><code>{stock}</code></td><td>Current stock level</td></tr>
                        <tr><td><code>{threshold}</code></td><td>Low stock threshold</td></tr>
                        <tr><td><code>{company}</code></td><td>Customer's company</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
var waTemplates = <?= json_encode(array_map(fn($t) => $t['message'], $default_templates), JSON_UNESCAPED_UNICODE) ?>;
var emailTemplates = <?= json_encode(array_map(fn($t) => ['subject' => $t['email_subject'], 'body' => $t['email_body']], $default_templates), JSON_UNESCAPED_UNICODE) ?>;

function applyWaTemplate() {
    var key = document.getElementById('wa_template').value;
    if (key !== 'custom' && waTemplates[key]) {
        document.getElementById('wa_message').value = waTemplates[key];
    }
}

function applyEmailTemplate() {
    var key = document.getElementById('email_template').value;
    if (key !== 'custom' && emailTemplates[key]) {
        document.getElementById('email_subject').value = emailTemplates[key].subject;
        document.getElementById('email_body').value = emailTemplates[key].body;
    }
}

function useWaTemplate(key) {
    document.getElementById('wa_template').value = key;
    document.getElementById('wa_message').value = waTemplates[key];
    window.scrollTo({top: 0, behavior: 'smooth'});
}

function useEmailTemplate(key) {
    document.getElementById('email_template').value = key;
    document.getElementById('email_subject').value = emailTemplates[key].subject;
    document.getElementById('email_body').value = emailTemplates[key].body;
    window.scrollTo({top: 0, behavior: 'smooth'});
}
</script>
</body>
</html>
