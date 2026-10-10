<?php
/**
 * Pinksterpark online application – server endpoint.
 *
 * Installed on pinksterpark.co.za as a Code Snippets PHP snippet ("Pinksterpark application endpoint").
 * This file is the source of truth; paste its contents (without the opening <?php line) into the snippet.
 *
 * What it does
 *  - REST routes under /wp-json/pinksterpark/v1 used by application.html (served from GitHub Pages
 *    and embedded on /application/):
 *      POST   /draft                       create or update a draft (JSON: token?, email?, payload, notify?)
 *      GET    /draft/{token}               read a draft (payload + file list) to resume later
 *      POST   /draft/{token}/file          upload ONE file (multipart: key, file) into the draft
 *      DELETE /draft/{token}/file/{id}     remove an uploaded file
 *      POST   /draft/{token}/submit        finalise: store under applications/, email the office + applicant
 *  - Files live in wp-content/uploads/pinksterpark-applications/ (blocked from direct download by .htaccess).
 *  - Admin menu "Applications" lists submissions with ZIP download; drafts older than 60 days are purged daily.
 *  - The office address is the option pp_application_admin_email (default admin@pinksterpark.co.za).
 */

if (!defined('ABSPATH')) { exit; }

define('PPAPP_NS', 'pinksterpark/v1');
define('PPAPP_OPT_ADMIN_EMAIL', 'pp_application_admin_email');
define('PPAPP_DEFAULT_ADMIN_EMAIL', 'admin@pinksterpark.co.za');
define('PPAPP_RESUME_URL', 'https://pinksterpark.co.za/application/');
define('PPAPP_MAX_FILE_BYTES', 10 * 1024 * 1024);
define('PPAPP_MAX_FILES_PER_DRAFT', 60);
define('PPAPP_MAX_DRAFT_BYTES', 200 * 1024 * 1024);
define('PPAPP_ATTACH_LIMIT_BYTES', 20 * 1024 * 1024);
define('PPAPP_DRAFT_TTL_DAYS', 60);
define('PPAPP_MAX_PAYLOAD_BYTES', 512 * 1024);

function ppapp_allowed_origins() {
    return array('https://groenspoor-bot.github.io', 'https://pinksterpark.co.za', 'https://www.pinksterpark.co.za');
}
function ppapp_allowed_ext() {
    return array('pdf', 'jpg', 'jpeg', 'png', 'heic', 'heif', 'webp', 'doc', 'docx');
}
function ppapp_allowed_mimes() {
    return array('application/pdf', 'image/jpeg', 'image/png', 'image/heic', 'image/heif', 'image/webp',
        'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/octet-stream', 'application/zip');
}

/* ---------- Storage helpers ---------- */
function ppapp_base_dir() {
    $u = wp_upload_dir();
    $d = trailingslashit($u['basedir']) . 'pinksterpark-applications';
    if (!is_dir($d)) {
        wp_mkdir_p($d);
        file_put_contents($d . '/.htaccess', "# Pinksterpark application files – no direct access\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        file_put_contents($d . '/index.html', '');
    }
    return $d;
}
function ppapp_draft_dir($token) { return ppapp_base_dir() . '/drafts/' . $token; }
function ppapp_app_dir($ref) { return ppapp_base_dir() . '/applications/' . $ref; }
function ppapp_read_json($file) { return file_exists($file) ? json_decode(file_get_contents($file), true) : null; }
function ppapp_write_json($file, $data) {
    wp_mkdir_p(dirname($file));
    file_put_contents($file, wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}
function ppapp_rrmdir($dir) {
    if (!is_dir($dir)) { return; }
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') { continue; }
        $p = $dir . '/' . $f;
        is_dir($p) ? ppapp_rrmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}
function ppapp_error($code, $message, $status) { return new WP_Error($code, $message, array('status' => $status)); }
function ppapp_rate_limit($bucket, $limit, $window) {
    $ip  = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0';
    $key = 'ppapp_rl_' . md5($bucket . '|' . $ip);
    $n   = (int) get_transient($key);
    if ($n >= $limit) { return ppapp_error('pp_rate_limited', 'Too many requests. Please wait a few minutes and try again.', 429); }
    set_transient($key, $n + 1, $window);
    return true;
}
function ppapp_public_file($f) {
    return array('id' => $f['id'], 'key' => $f['key'], 'name' => $f['name'], 'size' => (int) $f['size'], 'type' => $f['type']);
}
function ppapp_load_draft($token) {
    $dir = ppapp_draft_dir($token);
    if (!is_dir($dir)) { return null; }
    $meta = ppapp_read_json($dir . '/draft.json');
    if (!is_array($meta)) { return null; }
    if (!isset($meta['files']) || !is_array($meta['files'])) { $meta['files'] = array(); }
    return $meta;
}

/* ---------- CORS (the form runs on GitHub Pages) ---------- */
function ppapp_send_cors() {
    $origin = get_http_origin();
    if ($origin && in_array($origin, ppapp_allowed_origins(), true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Max-Age: 600');
        header('Vary: Origin');
    }
}
add_action('init', function () {
    if (isset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS'
        && strpos($_SERVER['REQUEST_URI'], '/wp-json/' . PPAPP_NS . '/') !== false) {
        ppapp_send_cors();
        status_header(204);
        exit;
    }
}, 0);
add_filter('rest_pre_serve_request', function ($served, $result, $request) {
    if (strpos($request->get_route(), '/' . PPAPP_NS . '/') === 0) { ppapp_send_cors(); }
    return $served;
}, 10, 3);

/* ---------- Routes ---------- */
add_action('rest_api_init', function () {
    register_setting('general', PPAPP_OPT_ADMIN_EMAIL, array('type' => 'string', 'show_in_rest' => true,
        'default' => PPAPP_DEFAULT_ADMIN_EMAIL, 'sanitize_callback' => 'sanitize_email'));
    $pub = '__return_true';
    register_rest_route(PPAPP_NS, '/draft', array('methods' => 'POST', 'callback' => 'ppapp_route_save_draft', 'permission_callback' => $pub));
    register_rest_route(PPAPP_NS, '/draft/(?P<token>[a-f0-9]{32})', array('methods' => 'GET', 'callback' => 'ppapp_route_get_draft', 'permission_callback' => $pub));
    register_rest_route(PPAPP_NS, '/draft/(?P<token>[a-f0-9]{32})/file', array('methods' => 'POST', 'callback' => 'ppapp_route_upload', 'permission_callback' => $pub));
    register_rest_route(PPAPP_NS, '/draft/(?P<token>[a-f0-9]{32})/file/(?P<id>[a-f0-9]{16})', array('methods' => 'DELETE', 'callback' => 'ppapp_route_delete_file', 'permission_callback' => $pub));
    register_rest_route(PPAPP_NS, '/draft/(?P<token>[a-f0-9]{32})/submit', array('methods' => 'POST', 'callback' => 'ppapp_route_submit', 'permission_callback' => $pub));
});
add_action('admin_init', function () {
    register_setting('general', PPAPP_OPT_ADMIN_EMAIL, array('type' => 'string', 'show_in_rest' => true,
        'default' => PPAPP_DEFAULT_ADMIN_EMAIL, 'sanitize_callback' => 'sanitize_email'));
});

function ppapp_route_save_draft(WP_REST_Request $r) {
    $rl = ppapp_rate_limit('draft', 120, 10 * MINUTE_IN_SECONDS);
    if (is_wp_error($rl)) { return $rl; }
    if (strlen($r->get_body()) > PPAPP_MAX_PAYLOAD_BYTES) { return ppapp_error('pp_too_large', 'Draft too large.', 413); }
    $p = $r->get_json_params();
    if (!is_array($p)) { return ppapp_error('pp_bad_request', 'Invalid request.', 400); }

    $token = isset($p['token']) ? (string) $p['token'] : '';
    if ($token !== '' && !preg_match('/^[a-f0-9]{32}$/', $token)) { return ppapp_error('pp_bad_token', 'Invalid draft reference.', 400); }
    $meta = $token !== '' ? ppapp_load_draft($token) : null;
    $is_new = false;
    if (!$meta) {
        $token  = bin2hex(random_bytes(16));
        $meta   = array('token' => $token, 'created' => current_time('mysql'), 'files' => array(), 'payload' => array(), 'email' => '');
        $is_new = true;
    }
    if (isset($p['email'])) { $meta['email'] = sanitize_email((string) $p['email']); }
    if (isset($p['payload']) && is_array($p['payload'])) { $meta['payload'] = $p['payload']; }
    $meta['updated'] = current_time('mysql');
    ppapp_write_json(ppapp_draft_dir($token) . '/draft.json', $meta);

    $resume_url = PPAPP_RESUME_URL . '?resume=' . $token;
    $sent = false;
    if (!empty($p['notify']) && is_email($meta['email'])) {
        $sent = ppapp_send_resume_email($meta['email'], $resume_url, $meta['payload']);
    }
    return array('token' => $token, 'resumeUrl' => $resume_url, 'files' => array_values(array_map('ppapp_public_file', $meta['files'])),
        'emailSent' => (bool) $sent, 'isNew' => $is_new, 'updated' => $meta['updated']);
}

function ppapp_route_get_draft(WP_REST_Request $r) {
    $rl = ppapp_rate_limit('get', 60, 10 * MINUTE_IN_SECONDS);
    if (is_wp_error($rl)) { return $rl; }
    $meta = ppapp_load_draft($r['token']);
    if (!$meta) { return ppapp_error('pp_not_found', 'This saved application could not be found. It may have expired or already been submitted.', 404); }
    return array('token' => $meta['token'], 'email' => $meta['email'], 'payload' => $meta['payload'], 'updated' => $meta['updated'],
        'files' => array_values(array_map('ppapp_public_file', $meta['files'])));
}

function ppapp_route_upload(WP_REST_Request $r) {
    $rl = ppapp_rate_limit('upload', 300, 10 * MINUTE_IN_SECONDS);
    if (is_wp_error($rl)) { return $rl; }
    $token = $r['token'];
    $meta  = ppapp_load_draft($token);
    if (!$meta) { return ppapp_error('pp_not_found', 'Draft not found or expired. Please reload the page.', 404); }
    $key = sanitize_key((string) $r->get_param('key'));
    if ($key === '' || strlen($key) > 60) { return ppapp_error('pp_bad_request', 'Missing document key.', 400); }

    $files = $r->get_file_params();
    $f = isset($files['file']) ? $files['file'] : null;
    if (!$f || !isset($f['error'])) { return ppapp_error('pp_no_file', 'No file received.', 400); }
    if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) { return ppapp_error('pp_too_large', 'That file is too large for the server.', 413); }
    if ($f['error'] !== UPLOAD_ERR_OK) { return ppapp_error('pp_upload_failed', 'The upload failed. Please try again.', 400); }
    if ((int) $f['size'] > PPAPP_MAX_FILE_BYTES) { return ppapp_error('pp_too_large', 'Files may be at most ' . (PPAPP_MAX_FILE_BYTES / 1048576) . ' MB each.', 413); }
    if (count($meta['files']) >= PPAPP_MAX_FILES_PER_DRAFT) { return ppapp_error('pp_too_many', 'Too many files in this application.', 400); }
    $total = 0;
    foreach ($meta['files'] as $e) { $total += (int) $e['size']; }
    if ($total + (int) $f['size'] > PPAPP_MAX_DRAFT_BYTES) { return ppapp_error('pp_too_large', 'The total size of your uploads is too large.', 413); }

    $orig = sanitize_file_name($f['name']);
    $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (!in_array($ext, ppapp_allowed_ext(), true)) { return ppapp_error('pp_bad_type', 'File type not allowed. Please upload PDF, JPG, PNG, HEIC, WEBP, DOC or DOCX.', 415); }
    $mime = function_exists('mime_content_type') ? (string) @mime_content_type($f['tmp_name']) : '';
    if ($mime !== '' && !in_array($mime, ppapp_allowed_mimes(), true)) { return ppapp_error('pp_bad_type', 'That file does not appear to be a valid document or image.', 415); }
    if (in_array($ext, array('jpg', 'jpeg', 'png', 'webp'), true) && @getimagesize($f['tmp_name']) === false) { return ppapp_error('pp_bad_type', 'That image file could not be read.', 415); }

    $id     = bin2hex(random_bytes(8));
    $stored = $id . '-' . $orig;
    $dir    = ppapp_draft_dir($token);
    if (!@move_uploaded_file($f['tmp_name'], $dir . '/' . $stored)) { return ppapp_error('pp_store_failed', 'Could not store the file. Please try again.', 500); }
    @chmod($dir . '/' . $stored, 0640);
    $entry = array('id' => $id, 'key' => $key, 'name' => $orig, 'size' => (int) $f['size'], 'type' => $mime ?: 'application/octet-stream', 'stored' => $stored, 'uploaded' => current_time('mysql'));
    $meta['files'][$id] = $entry;
    $meta['updated'] = current_time('mysql');
    ppapp_write_json($dir . '/draft.json', $meta);
    return ppapp_public_file($entry);
}

function ppapp_route_delete_file(WP_REST_Request $r) {
    $meta = ppapp_load_draft($r['token']);
    if (!$meta) { return ppapp_error('pp_not_found', 'Draft not found.', 404); }
    $id = $r['id'];
    if (isset($meta['files'][$id])) {
        @unlink(ppapp_draft_dir($r['token']) . '/' . $meta['files'][$id]['stored']);
        unset($meta['files'][$id]);
        $meta['updated'] = current_time('mysql');
        ppapp_write_json(ppapp_draft_dir($r['token']) . '/draft.json', $meta);
    }
    return array('deleted' => $id);
}

function ppapp_route_submit(WP_REST_Request $r) {
    $rl = ppapp_rate_limit('submit', 10, 10 * MINUTE_IN_SECONDS);
    if (is_wp_error($rl)) { return $rl; }
    if (strlen($r->get_body()) > PPAPP_MAX_PAYLOAD_BYTES) { return ppapp_error('pp_too_large', 'Application too large.', 413); }
    $token = $r['token'];
    $meta  = ppapp_load_draft($token);
    if (!$meta) { return ppapp_error('pp_not_found', 'Your saved application could not be found. Please reload the page and try again.', 404); }
    $p = $r->get_json_params();
    $payload = (is_array($p) && isset($p['payload']) && is_array($p['payload'])) ? $p['payload'] : $meta['payload'];

    do { $ref = 'PP-' . current_time('Ymd') . '-' . strtoupper(substr(str_replace(array('0', 'O', 'I', '1'), array('2', '3', '4', '5'), bin2hex(random_bytes(3))), 0, 4)); } while (is_dir(ppapp_app_dir($ref)));
    $adir = ppapp_app_dir($ref);
    wp_mkdir_p(dirname($adir));
    if (!@rename(ppapp_draft_dir($token), $adir)) { return ppapp_error('pp_store_failed', 'Could not finalise the application. Please try again.', 500); }
    @unlink($adir . '/draft.json');

    $applicant_email = isset($payload['applicant.email']) ? sanitize_email($payload['applicant.email']) : $meta['email'];
    $record = array('reference' => $ref, 'submitted' => current_time('mysql'), 'ip' => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '',
        'applicantEmail' => $applicant_email, 'payload' => $payload, 'files' => array_values($meta['files']));
    ppapp_write_json($adir . '/application.json', $record);

    $summary_html = ppapp_render_summary($record);
    file_put_contents($adir . '/summary.html', $summary_html);

    $admin_ok = ppapp_send_admin_email($record, $adir, $summary_html);
    $appl_ok  = is_email($applicant_email) ? ppapp_send_applicant_email($record) : false;
    $record['emails'] = array('admin' => (bool) $admin_ok, 'applicant' => (bool) $appl_ok);
    ppapp_write_json($adir . '/application.json', $record);

    return array('reference' => $ref, 'emailSent' => (bool) $appl_ok,
        'message' => $appl_ok ? 'A confirmation has been emailed to ' . $applicant_email . '.' : 'Your application has been received by the office.');
}

/* ---------- Rendering & email ---------- */
function ppapp_val($payload, $k, $d = '') { return isset($payload[$k]) && $payload[$k] !== '' ? $payload[$k] : $d; }
function ppapp_render_summary($record) {
    $p = $record['payload'];
    $name = esc_html(ppapp_val($p, 'applicant.fullName', 'Applicant'));
    $type = ppapp_val($p, 'applicationType') === 'rental' ? 'Rental' : 'Cession';
    $h  = '<div style="font-family:Segoe UI,Arial,sans-serif;color:#222;max-width:760px">';
    $h .= '<h2 style="color:#004274;margin:0 0 4px">Pinksterpark application ' . esc_html($record['reference']) . '</h2>';
    $h .= '<p style="margin:0 0 16px;color:#555">' . $name . ' &middot; ' . $type . ' &middot; submitted ' . esc_html($record['submitted']) . '</p>';
    $sections = isset($p['summary']) && is_array($p['summary']) ? $p['summary'] : array();
    foreach ($sections as $s) {
        $h .= '<h3 style="color:#2f5e99;border-bottom:1px solid #e3e7ee;padding-bottom:4px;margin:18px 0 8px">' . esc_html(isset($s['title']) ? $s['title'] : '') . '</h3>';
        if (!empty($s['rows']) && is_array($s['rows'])) {
            $h .= '<table cellpadding="4" style="border-collapse:collapse;font-size:14px">';
            foreach ($s['rows'] as $row) {
                $h .= '<tr><td style="color:#666;vertical-align:top;padding-right:14px;white-space:nowrap">' . esc_html(isset($row[0]) ? $row[0] : '') . '</td><td>' . nl2br(esc_html(isset($row[1]) ? $row[1] : '')) . '</td></tr>';
            }
            $h .= '</table>';
        }
        if (!empty($s['items']) && is_array($s['items'])) {
            $h .= '<ul style="font-size:14px;margin:4px 0 0 18px">';
            foreach ($s['items'] as $it) { $h .= '<li>' . esc_html($it) . '</li>'; }
            $h .= '</ul>';
        }
        if (!empty($s['text'])) { $h .= '<p style="font-size:14px;white-space:pre-wrap">' . esc_html($s['text']) . '</p>'; }
    }
    $labels = isset($p['fileLabels']) && is_array($p['fileLabels']) ? $p['fileLabels'] : array();
    $h .= '<h3 style="color:#2f5e99;border-bottom:1px solid #e3e7ee;padding-bottom:4px;margin:18px 0 8px">Documents uploaded (' . count($record['files']) . ')</h3><ul style="font-size:14px;margin:4px 0 0 18px">';
    foreach ($record['files'] as $f) {
        $label = isset($labels[$f['key']]) ? $labels[$f['key']] : $f['key'];
        $h .= '<li><b>' . esc_html($label) . '</b>: ' . esc_html($f['name']) . ' (' . esc_html(size_format($f['size'])) . ')</li>';
    }
    $h .= '</ul>';
    $missing = isset($p['outstandingDocuments']) && is_array($p['outstandingDocuments']) ? $p['outstandingDocuments'] : array();
    if ($missing) {
        $h .= '<h3 style="color:#b7791f;border-bottom:1px solid #e3e7ee;padding-bottom:4px;margin:18px 0 8px">Outstanding required documents (' . count($missing) . ')</h3><ul style="font-size:14px;margin:4px 0 0 18px">';
        foreach ($missing as $m) { $h .= '<li>' . esc_html($m) . '</li>'; }
        $h .= '</ul>';
    }
    $h .= '</div>';
    return $h;
}
function ppapp_mail($to, $subject, $html, $attachments = array(), $extra_headers = array()) {
    $headers = array_merge(array('Content-Type: text/html; charset=UTF-8'), $extra_headers);
    return wp_mail($to, $subject, $html, $headers, $attachments);
}
function ppapp_send_admin_email($record, $adir, $summary_html) {
    $p    = $record['payload'];
    $to   = get_option(PPAPP_OPT_ADMIN_EMAIL, PPAPP_DEFAULT_ADMIN_EMAIL);
    if (!is_email($to)) { $to = PPAPP_DEFAULT_ADMIN_EMAIL; }
    $name = ppapp_val($p, 'applicant.fullName', 'Applicant');
    $type = ppapp_val($p, 'applicationType') === 'rental' ? 'Rental' : 'Cession';
    $subject = 'Pinksterpark application ' . $record['reference'] . ' - ' . $name . ' (' . $type . ')';
    $total = 0;
    foreach ($record['files'] as $f) { $total += (int) $f['size']; }
    $attach = array($adir . '/summary.html');
    $note   = '';
    if ($total <= PPAPP_ATTACH_LIMIT_BYTES) {
        foreach ($record['files'] as $f) { $attach[] = $adir . '/' . $f['stored']; }
        $note = '<p>All ' . count($record['files']) . ' uploaded documents are attached to this email.</p>';
    } else {
        $note = '<p><b>The uploaded documents (' . esc_html(size_format($total)) . ') are too large to attach.</b> Download them as a ZIP from the link below (you need to be logged in to the website).</p>';
    }
    $zip = admin_url('admin-post.php?action=pp_application_zip&ref=' . rawurlencode($record['reference']));
    $list = admin_url('admin.php?page=pp-applications');
    $html = '<div style="font-family:Segoe UI,Arial,sans-serif;color:#222"><p>A new application was submitted on the Pinksterpark website.</p>' . $note
        . '<p><a href="' . esc_url($zip) . '" style="display:inline-block;background:#2f5e99;color:#fff;padding:10px 16px;border-radius:6px;text-decoration:none;font-weight:bold">Download all files (ZIP)</a> &nbsp; <a href="' . esc_url($list) . '">All applications</a></p><hr style="border:0;border-top:1px solid #e3e7ee;margin:18px 0">' . $summary_html . '</div>';
    $headers = array();
    if (is_email($record['applicantEmail'])) { $headers[] = 'Reply-To: ' . $name . ' <' . $record['applicantEmail'] . '>'; }
    return ppapp_mail($to, $subject, $html, $attach, $headers);
}
function ppapp_send_applicant_email($record) {
    $p = $record['payload'];
    $name = ppapp_val($p, 'applicant.fullName', 'Applicant');
    $first = trim(explode(' ', trim($name))[0]);
    $surname = trim(substr(strrchr(' ' . trim($name), ' '), 1));
    $unit = ppapp_val($p, 'unitNr', 'UnitNr');
    $missing = isset($p['outstandingDocuments']) && is_array($p['outstandingDocuments']) ? $p['outstandingDocuments'] : array();
    $html = '<div style="font-family:Segoe UI,Arial,sans-serif;color:#222;max-width:640px">'
        . '<h2 style="color:#004274">Thank you, ' . esc_html($first) . '</h2>'
        . '<p>We have received your application to live at Pinksterpark. Your reference number is</p>'
        . '<p style="font-size:20px;font-weight:bold;color:#004274;background:#eaf0f8;border:1px solid #c9d7ea;display:inline-block;padding:8px 16px;border-radius:6px">' . esc_html($record['reference']) . '</p>'
        . '<p>Please quote it in all correspondence.</p>'
        . '<h3 style="color:#2f5e99">Next steps</h3><ol>'
        . '<li>If you have not already paid the non-refundable <b>R350</b> administration and credit check fee, please pay it by EFT: FNB, account 53751120263, branch code 250655, reference <b>' . esc_html($unit . '_' . $surname) . '</b>, and email the proof of payment to wilma@agspaarl.co.za.</li>'
        . ($missing ? '<li>Email the following outstanding documents to <a href="mailto:admin@pinksterpark.co.za">admin@pinksterpark.co.za</a>, quoting your reference:<ul>' . implode('', array_map(function ($m) { return '<li>' . esc_html($m) . '</li>'; }, $missing)) . '</ul></li>' : '')
        . '<li>Once all documents have been received and the criteria confirmed, Park Management will contact you to schedule an interview.</li></ol>'
        . '<p style="color:#666;font-size:13px">AFM of SA Pinkster Park Trust &middot; C/O N2 Highway &amp; Mondeor Road, Helderberg Rural, Cape Town, 7135 &middot; +27 60 989 1144</p></div>';
    return ppapp_mail($record['applicantEmail'], 'Your Pinksterpark application ' . $record['reference'], $html);
}
function ppapp_send_resume_email($email, $url, $payload) {
    $name = ppapp_val($payload, 'applicant.fullName', '');
    $first = $name ? trim(explode(' ', trim($name))[0]) : '';
    $html = '<div style="font-family:Segoe UI,Arial,sans-serif;color:#222;max-width:640px">'
        . '<h2 style="color:#004274">Continue your Pinksterpark application</h2>'
        . '<p>' . ($first ? 'Hello ' . esc_html($first) . ', y' : 'Y') . 'our application has been saved, including any documents you have already uploaded. Use the button below to pick up where you left off, on this or any other device.</p>'
        . '<p><a href="' . esc_url($url) . '" style="display:inline-block;background:#2f5e99;color:#fff;padding:12px 20px;border-radius:6px;text-decoration:none;font-weight:bold">Continue my application</a></p>'
        . '<p style="font-size:13px;color:#666">Or copy this link: ' . esc_html($url) . '</p>'
        . '<p style="font-size:13px;color:#666">This link is private to you. Please do not share it. Saved applications are kept for ' . PPAPP_DRAFT_TTL_DAYS . ' days.</p></div>';
    return ppapp_mail($email, 'Continue your Pinksterpark application', $html);
}

/* ---------- Admin: list, summary, zip ---------- */
add_action('admin_menu', function () {
    add_menu_page('Pinksterpark Applications', 'Applications', 'manage_options', 'pp-applications', 'ppapp_admin_page', 'dashicons-clipboard', 26);
});
function ppapp_admin_page() {
    $apps = array();
    foreach (glob(ppapp_base_dir() . '/applications/*', GLOB_ONLYDIR) ?: array() as $d) {
        $rec = ppapp_read_json($d . '/application.json');
        if ($rec) { $apps[] = $rec; }
    }
    usort($apps, function ($a, $b) { return strcmp($b['submitted'], $a['submitted']); });
    $drafts = count(glob(ppapp_base_dir() . '/drafts/*', GLOB_ONLYDIR) ?: array());
    echo '<div class="wrap"><h1>Pinksterpark Applications</h1>';
    echo '<p>Submissions from the online application form. Emails go to <b>' . esc_html(get_option(PPAPP_OPT_ADMIN_EMAIL, PPAPP_DEFAULT_ADMIN_EMAIL)) . '</b>. ' . intval($drafts) . ' unsubmitted draft(s) in progress.</p>';
    echo '<table class="widefat striped"><thead><tr><th>Reference</th><th>Submitted</th><th>Applicant</th><th>Type</th><th>Email</th><th>Files</th><th>Outstanding</th><th></th></tr></thead><tbody>';
    if (!$apps) { echo '<tr><td colspan="8">No applications yet.</td></tr>'; }
    foreach ($apps as $a) {
        $p = $a['payload'];
        $zip = admin_url('admin-post.php?action=pp_application_zip&ref=' . rawurlencode($a['reference']));
        $sum = admin_url('admin-post.php?action=pp_application_summary&ref=' . rawurlencode($a['reference']));
        echo '<tr><td><b>' . esc_html($a['reference']) . '</b></td><td>' . esc_html($a['submitted']) . '</td><td>' . esc_html(ppapp_val($p, 'applicant.fullName')) . '</td><td>' . esc_html(ucfirst(ppapp_val($p, 'applicationType'))) . '</td><td>' . esc_html($a['applicantEmail']) . '</td><td>' . count($a['files']) . '</td><td>' . (isset($p['outstandingDocuments']) ? count((array) $p['outstandingDocuments']) : 0) . '</td>'
            . '<td><a class="button" href="' . esc_url($sum) . '" target="_blank">Summary</a> <a class="button button-primary" href="' . esc_url($zip) . '">ZIP</a></td></tr>';
    }
    echo '</tbody></table></div>';
}
function ppapp_admin_ref() {
    if (!current_user_can('manage_options')) { wp_die('Not allowed.'); }
    $ref = isset($_GET['ref']) ? sanitize_text_field(wp_unslash($_GET['ref'])) : '';
    if (!preg_match('/^PP-\d{8}-[A-Z0-9]{4}$/', $ref) || !is_dir(ppapp_app_dir($ref))) { wp_die('Application not found.'); }
    return $ref;
}
add_action('admin_post_pp_application_summary', function () {
    $ref = ppapp_admin_ref();
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . esc_html($ref) . '</title></head><body style="padding:24px">';
    readfile(ppapp_app_dir($ref) . '/summary.html');
    echo '<p><a href="' . esc_url(admin_url('admin-post.php?action=pp_application_zip&ref=' . rawurlencode($ref))) . '">Download all files (ZIP)</a></p></body></html>';
    exit;
});
add_action('admin_post_pp_application_zip', function () {
    $ref = ppapp_admin_ref();
    $dir = ppapp_app_dir($ref);
    if (!class_exists('ZipArchive')) { wp_die('ZipArchive is not available on this server. The files are in ' . esc_html($dir)); }
    $tmp = wp_tempnam($ref . '.zip');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    foreach (glob($dir . '/*') as $f) { if (is_file($f)) { $zip->addFile($f, basename($f)); } }
    $zip->close();
    nocache_headers();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $ref . '.zip"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
    exit;
});

/* ---------- Housekeeping ---------- */
add_action('init', function () {
    if (!wp_next_scheduled('pp_application_cleanup')) { wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'pp_application_cleanup'); }
});
add_action('pp_application_cleanup', function () {
    foreach (glob(ppapp_base_dir() . '/drafts/*', GLOB_ONLYDIR) ?: array() as $d) {
        $meta = ppapp_read_json($d . '/draft.json');
        $updated = $meta && isset($meta['updated']) ? strtotime($meta['updated']) : filemtime($d);
        if ($updated < time() - PPAPP_DRAFT_TTL_DAYS * DAY_IN_SECONDS) { ppapp_rrmdir($d); }
    }
});
