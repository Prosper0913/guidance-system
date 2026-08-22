<?php
// ============================================================
//  guidance/_receive_common.php
//  Shared bootstrap for Guidance's receive-from-CMS endpoints.
// ============================================================

require_once __DIR__ . '/config/cms_sync.php';

 $DB_HOST = CMS_RECEIVE_DB_HOST;
 $DB_NAME = CMS_RECEIVE_DB_NAME;
 $DB_USER = CMS_RECEIVE_DB_USER;
 $DB_PASS = CMS_RECEIVE_DB_PASS;

 $SYNC_RECEIVE_KEY = CMS_SYNC_KEY;


mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
 $conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
 $conn->set_charset('utf8mb4');

function authenticate_request() {
    global $SYNC_RECEIVE_KEY;
    $sent = $_SERVER['HTTP_X_SYNC_KEY'] ?? '';
    if ($sent === '' || !hash_equals($SYNC_RECEIVE_KEY, $sent)) {
        json_fail(401, 'Invalid or missing X-Sync-Key header.');
    }
}

function json_out($payload, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_fail($code, $message, $extra = []) {
    json_out(array_merge(['success' => false, 'error' => $message], $extra), $code);
}

function read_json_body() {
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) {
        json_fail(400, 'Empty request body.');
    }
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        json_fail(400, 'Request body must be valid JSON.');
    }
    return $body;
}