<?php
/**
 * webChamploo form handler. The same file on every site.
 *
 * A form's own PHP file is three lines: require this, call wc_form_handle()
 * with that form's fields. This file does the rest:
 *
 *   1. drops bots that fill the hidden field, and floods from one address;
 *   2. checks what was typed;
 *   3. saves the submission to disk BEFORE anything else is tried;
 *   4. hands it, signed, to the webChamploo app, which emails the client;
 *   5. if the app cannot be reached, emails a plain copy itself and keeps
 *      the saved one for the app to collect later.
 *
 * It holds no mail password. The only secret is the signing key in the
 * config file, which lives outside the web root where it can.
 *
 * It never emails the visitor. An auto-reply to whatever address a stranger
 * types is how a mailbox gets suspended.
 *
 * PHP 7.4+. No dependencies.
 */

const WC_FORM_VERSION = '1.1';
const WC_FORM_MAX_BODY = 65536;
const WC_FORM_MAX_VALUE = 5000;
const WC_FORM_CLOCK_WINDOW = 600;

/**
 * Handle one form post. Options:
 *   name      what this form is called, e.g. 'Contact form'
 *   fields    [post key => label], in the order the email should list them
 *   required  post keys that must be filled in
 *   roles     which post keys are the visitor's name/email/phone/message
 *             (default: keys of those names)
 *   honeypot  post key of the hidden field a person leaves empty
 *   captcha   post key carrying the reCAPTCHA token, if the page has one
 *   success   what to tell the visitor
 *   failure   what to tell them if it could neither be handed over, emailed
 *             nor saved (a place for the phone number)
 *   respond   'json' ({ok, success, message, error}) or 'text' ("OK" / the error)
 */
function wc_form_handle(array $form): void
{
    header('X-Robots-Tag: noindex, nofollow', true);
    header('Cache-Control: no-store', true);

    $config = wc_form_config();
    $raw = wc_form_raw_body();

    if (!empty($_SERVER['HTTP_X_WC_CONTROL'])) {
        wc_form_control($config, $raw);
        return;
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        wc_form_respond($form, false, 'Method not allowed.', 405);
        return;
    }
    if (strlen($raw) > WC_FORM_MAX_BODY) {
        wc_form_respond($form, false, 'Your message is too long. Please shorten it and try again.', 413);
        return;
    }
    if ($config === null) {
        error_log('[wc-form] config file missing or incomplete');
        wc_form_respond($form, false, 'This form is not set up yet. Please call or email us instead.', 500);
        return;
    }

    $input = wc_form_input($raw);

    // A person never sees this field. Anything in it is a bot, which is told
    // it worked so it does not try another way in.
    $honeypot = (string) ($form['honeypot'] ?? '');
    if ($honeypot !== '' && trim((string) ($input[$honeypot] ?? '')) !== '') {
        wc_form_respond($form, true, (string) ($form['success'] ?? 'Thank you.'));
        return;
    }

    $dir = wc_form_data_dir();
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if ($dir !== null && !wc_form_rate_ok($dir, $ip)) {
        wc_form_respond($form, false, 'Too many messages from this connection. Please try again in a few minutes.', 429);
        return;
    }

    // ---- what was typed
    $labels = (array) ($form['fields'] ?? []);
    $fields = [];
    $values = [];
    foreach ($labels as $key => $label) {
        $value = wc_form_clean($input[$key] ?? '', WC_FORM_MAX_VALUE);
        $values[$key] = $value;
        if ($value !== '') {
            $fields[] = ['key' => (string) $key, 'label' => (string) $label, 'value' => $value];
        }
    }
    foreach ((array) ($form['required'] ?? []) as $key) {
        if (($values[$key] ?? '') === '') {
            wc_form_respond($form, false, 'Please fill out all required fields.', 400);
            return;
        }
    }
    $roles = (array) ($form['roles'] ?? []) + ['name' => 'name', 'email' => 'email', 'phone' => 'phone', 'message' => 'message'];
    $who = [];
    foreach (['name', 'email', 'phone', 'message'] as $role) {
        $who[$role] = (string) ($values[$roles[$role]] ?? '');
    }
    // A line break in a name or address is someone trying to add headers.
    foreach (['name', 'email', 'phone'] as $role) {
        $who[$role] = trim(preg_replace('/\s+/', ' ', $who[$role]));
    }
    if ($who['email'] !== '' && !filter_var($who['email'], FILTER_VALIDATE_EMAIL)) {
        wc_form_respond($form, false, 'Please enter a valid email address.', 400);
        return;
    }

    $payload = [
        'uid' => wc_form_uid(),
        'form' => (string) ($form['name'] ?? 'Website form'),
        'submitted_at' => time(),
        'name' => $who['name'],
        'email' => $who['email'],
        'phone' => $who['phone'],
        'message' => $who['message'],
        'fields' => $fields,
        'page_url' => wc_form_clean($_SERVER['HTTP_REFERER'] ?? '', 500),
        'ip' => $ip,
        'user_agent' => wc_form_clean($_SERVER['HTTP_USER_AGENT'] ?? '', 300),
        'handler_version' => WC_FORM_VERSION,
    ];

    // ---- save first, then try to hand it over
    $saved = $dir !== null && wc_form_queue_write($dir, $payload);

    $captchaKey = (string) ($form['captcha'] ?? '');
    $token = $captchaKey !== '' ? wc_form_clean($input[$captchaKey] ?? '', 4000) : '';
    $answer = wc_form_forward($config, $payload + ['captcha_token' => $token]);

    if ($answer['ok']) {
        if ($saved) {
            wc_form_queue_delete($dir, $payload['uid']);
            wc_form_flush($config, $dir);
        }
        wc_form_respond($form, true, (string) ($form['success'] ?? 'Thank you. We will be in touch soon.'));
        return;
    }

    // The app did not take it. The client still has to hear about it now.
    error_log('[wc-form] app unreachable (' . $answer['why'] . '); using fallback for ' . $payload['uid']);
    $mailed = wc_form_fallback_mail($config, $payload);
    if ($saved) {
        $payload['delivered_by_site'] = $mailed;
        wc_form_queue_write($dir, $payload);
    }
    if ($mailed || $saved) {
        wc_form_respond($form, true, (string) ($form['success'] ?? 'Thank you. We will be in touch soon.'));
        return;
    }
    wc_form_respond($form, false, (string) ($form['failure'] ?? 'We could not send your message. Please call or email us instead.'), 500);
}

// ─── Config and storage ──────────────────────────────────────────────────────

/** The site's config, or null. Looked for outside the web root first. */
function wc_form_config(): ?array
{
    $root = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');
    $places = [];
    if ($root !== '') {
        $places[] = dirname($root) . '/wc-form.config.php';
    }
    $places[] = __DIR__ . '/config.php';
    foreach ($places as $file) {
        if (is_file($file)) {
            $config = require $file;
            if (is_array($config) && !empty($config['endpoint']) && !empty($config['secret'])) {
                return $config;
            }
        }
    }
    return null;
}

/** Where submissions wait. Outside the web root when that is writable. */
function wc_form_data_dir(): ?string
{
    $root = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');
    $places = [];
    if ($root !== '') {
        $places[] = dirname($root) . '/wc-form-data';
    }
    $places[] = __DIR__ . '/data';
    foreach ($places as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            // Belt and braces for the in-root case: nothing here is served.
            if (!is_file($dir . '/.htaccess')) {
                @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
            }
            if (!is_file($dir . '/index.php')) {
                @file_put_contents($dir . '/index.php', "<?php http_response_code(404);\n");
            }
            return $dir;
        }
    }
    return null;
}

function wc_form_queue_file(string $dir, string $uid): string
{
    return $dir . '/q-' . preg_replace('/[^a-f0-9]/', '', $uid) . '.json.php';
}

function wc_form_queue_write(string $dir, array $payload): bool
{
    // The first line makes the file answer nothing if it is ever requested.
    $body = "<?php http_response_code(404); exit; ?>\n" . json_encode($payload);
    $file = wc_form_queue_file($dir, $payload['uid']);
    $tmp = $file . '.tmp';
    if (@file_put_contents($tmp, $body, LOCK_EX) === false) {
        return false;
    }
    return @rename($tmp, $file);
}

function wc_form_queue_delete(string $dir, string $uid): void
{
    @unlink(wc_form_queue_file($dir, $uid));
}

/** Everything still waiting, oldest first. */
function wc_form_queue_list(string $dir, int $limit = 100): array
{
    $files = glob($dir . '/q-*.json.php') ?: [];
    usort($files, function ($a, $b) {
        return filemtime($a) <=> filemtime($b);
    });
    $out = [];
    foreach (array_slice($files, 0, $limit) as $file) {
        $text = (string) @file_get_contents($file);
        $json = substr($text, (int) strpos($text, "\n") + 1);
        $payload = json_decode($json, true);
        if (is_array($payload) && !empty($payload['uid'])) {
            $out[] = $payload;
        }
    }
    return $out;
}

/** No more than 8 posts from one address in ten minutes. */
function wc_form_rate_ok(string $dir, string $ip): bool
{
    if ($ip === '') {
        return true;
    }
    $file = $dir . '/r-' . substr(hash('sha256', $ip), 0, 24) . '.txt';
    $now = time();
    $recent = [];
    foreach (explode("\n", (string) @file_get_contents($file)) as $line) {
        $at = (int) $line;
        if ($at > $now - 600) {
            $recent[] = $at;
        }
    }
    if (count($recent) >= 8) {
        return false;
    }
    $recent[] = $now;
    @file_put_contents($file, implode("\n", $recent), LOCK_EX);
    // Tidy now and then: old counters are of no use to anyone.
    if (mt_rand(1, 50) === 1) {
        foreach (glob($dir . '/r-*.txt') ?: [] as $old) {
            if (filemtime($old) < $now - 3600) {
                @unlink($old);
            }
        }
    }
    return true;
}

// ─── Reading the request ─────────────────────────────────────────────────────

function wc_form_raw_body(): string
{
    static $raw = null;
    if ($raw === null) {
        $raw = (string) file_get_contents('php://input', false, null, 0, WC_FORM_MAX_BODY + 1);
    }
    return $raw;
}

/** The posted values, whether the page sent JSON or an ordinary form. */
function wc_form_input(string $raw): array
{
    if (stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== false) {
        $decoded = json_decode($raw !== '' ? $raw : '[]', true);
        return is_array($decoded) ? $decoded : [];
    }
    return $_POST;
}

/** Text as typed: no control characters, one kind of line break, capped. */
function wc_form_clean($value, int $limit): string
{
    if (is_array($value)) {
        $value = implode(', ', array_map('strval', array_filter($value, 'is_scalar')));
    }
    $value = str_replace(["\r\n", "\r"], "\n", (string) $value);
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? '';
    $value = trim($value);
    return function_exists('mb_substr') ? mb_substr($value, 0, $limit) : substr($value, 0, $limit);
}

function wc_form_uid(): string
{
    return bin2hex(random_bytes(16));
}

// ─── Talking to the app ──────────────────────────────────────────────────────

function wc_form_sign(string $secret, int $timestamp, string $body): string
{
    return hash_hmac('sha256', $timestamp . '.' . $body, $secret);
}

/** Hand one submission to the app. ['ok' => bool, 'why' => string]. */
function wc_form_forward(array $config, array $payload): array
{
    $body = (string) json_encode($payload);
    $stamp = time();
    $headers = [
        'Content-Type: application/json',
        'X-WC-Timestamp: ' . $stamp,
        'X-WC-Signature: ' . wc_form_sign((string) $config['secret'], $stamp, $body),
        'User-Agent: wc-form/' . WC_FORM_VERSION,
    ];
    $status = 0;
    $text = '';
    if (function_exists('curl_init')) {
        $handle = curl_init((string) $config['endpoint']);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 12,
        ]);
        $text = (string) curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if ($status === 0) {
            return ['ok' => false, 'why' => 'no answer: ' . $error];
        }
    } else {
        $context = stream_context_create(['http' => [
            'method' => 'POST', 'timeout' => 12, 'ignore_errors' => true,
            'header' => implode("\r\n", $headers) . "\r\n", 'content' => $body,
        ]]);
        $text = (string) @file_get_contents((string) $config['endpoint'], false, $context);
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $status = (int) $m[1];
        }
        if ($status === 0) {
            return ['ok' => false, 'why' => 'no answer'];
        }
    }
    $data = json_decode($text, true);
    if ($status >= 200 && $status < 300 && is_array($data) && !empty($data['ok'])) {
        return ['ok' => true, 'why' => ''];
    }
    return ['ok' => false, 'why' => 'answered ' . $status];
}

/** While the app is answering, hand over a few it missed earlier. */
function wc_form_flush(array $config, string $dir): void
{
    foreach (wc_form_queue_list($dir, 3) as $payload) {
        $answer = wc_form_forward($config, $payload);
        if (!$answer['ok']) {
            return;
        }
        wc_form_queue_delete($dir, $payload['uid']);
    }
}

/** The plain copy the site sends itself when the app cannot be reached. */
function wc_form_fallback_mail(array $config, array $payload): bool
{
    $to = array_filter(array_map('trim', (array) ($config['fallback_to'] ?? [])));
    if (!$to) {
        return false;
    }
    $host = preg_replace('/^www\./i', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $host = preg_replace('/[^a-z0-9.-]/i', '', $host);
    $site = trim(preg_replace('/[\r\n"<>]+/', ' ', (string) ($config['site_name'] ?? $host)));
    $from = (string) ($config['fallback_from'] ?? ('no-reply@' . $host));

    $lines = ['New inquiry from your website (' . $payload['form'] . ')', ''];
    foreach ($payload['fields'] as $field) {
        $lines[] = $field['label'] . ': ' . $field['value'];
    }
    $lines[] = '';
    $lines[] = 'Sent ' . gmdate('D, d M Y H:i', (int) $payload['submitted_at']) . ' UTC';
    if ($payload['page_url'] !== '') {
        $lines[] = 'From: ' . $payload['page_url'];
    }

    $subject = 'New inquiry from ' . ($payload['name'] !== '' ? $payload['name'] : 'your website');
    $subject = trim(preg_replace('/[\r\n]+/', ' ', $subject));
    $headers = [
        'From: ' . $site . ' website <' . $from . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Auto-Submitted: auto-generated',
        'X-Auto-Response-Suppress: All',
    ];
    if ($payload['email'] !== '' && filter_var($payload['email'], FILTER_VALIDATE_EMAIL)) {
        $headers[] = 'Reply-To: ' . $payload['email'];
    }
    return @mail(implode(', ', $to), $subject, implode("\n", $lines), implode("\r\n", $headers));
}

// ─── Requests from the app ───────────────────────────────────────────────────

/**
 * The app asking this site something: what are you holding (drain), you may
 * drop these (ack), send a test through (selftest). Signed with the same
 * key, so nobody else can ask.
 */
function wc_form_control(?array $config, string $raw): void
{
    header('Content-Type: application/json; charset=utf-8');
    $deny = function (int $status, string $message): void {
        http_response_code($status);
        echo json_encode(['ok' => false, 'message' => $message]);
    };
    if ($config === null) {
        $deny(500, 'Not configured.');
        return;
    }
    $stamp = (int) ($_SERVER['HTTP_X_WC_TIMESTAMP'] ?? 0);
    $given = (string) ($_SERVER['HTTP_X_WC_SIGNATURE'] ?? '');
    if (abs(time() - $stamp) > WC_FORM_CLOCK_WINDOW
        || !hash_equals(wc_form_sign((string) $config['secret'], $stamp, $raw), $given)) {
        $deny(401, 'Bad signature.');
        return;
    }
    $ask = json_decode($raw, true);
    $action = is_array($ask) ? (string) ($ask['action'] ?? '') : '';
    $dir = wc_form_data_dir();

    if ($action === 'drain') {
        $queued = $dir !== null ? wc_form_queue_list($dir, 100) : [];
        $total = $dir !== null ? count(glob($dir . '/q-*.json.php') ?: []) : 0;
        echo json_encode([
            'ok' => true, 'version' => WC_FORM_VERSION, 'php' => PHP_VERSION,
            'storage' => $dir !== null, 'queued' => $queued, 'still_queued' => max(0, $total - count($queued)),
        ]);
        return;
    }
    if ($action === 'ack') {
        $dropped = 0;
        if ($dir !== null) {
            foreach ((array) ($ask['uids'] ?? []) as $uid) {
                $file = wc_form_queue_file($dir, (string) $uid);
                if (is_file($file) && @unlink($file)) {
                    $dropped++;
                }
            }
        }
        echo json_encode(['ok' => true, 'dropped' => $dropped]);
        return;
    }
    if ($action === 'selftest') {
        $payload = [
            'uid' => wc_form_uid(), 'form' => 'Handler test', 'submitted_at' => time(), 'test' => true,
            'name' => 'webChamploo test', 'email' => '', 'phone' => '',
            'message' => 'A test sent by the webChamploo app to check this form handler end to end.',
            'fields' => [], 'page_url' => '', 'ip' => '', 'user_agent' => 'wc-form selftest',
            'handler_version' => WC_FORM_VERSION,
        ];
        $saved = $dir !== null && wc_form_queue_write($dir, $payload);
        $answer = wc_form_forward($config, $payload);
        if ($saved) {
            wc_form_queue_delete($dir, $payload['uid']);
        }
        echo json_encode([
            'ok' => $answer['ok'], 'uid' => $payload['uid'], 'storage' => $saved,
            'message' => $answer['ok'] ? '' : 'The handler could not reach the app: ' . $answer['why'],
        ]);
        return;
    }
    $deny(400, 'Unknown action.');
}

// ─── Answering the visitor ───────────────────────────────────────────────────

function wc_form_respond(array $form, bool $ok, string $message, int $status = 200): void
{
    if (($form['respond'] ?? 'json') === 'text') {
        // The template form script many sites use reads the body, not the
        // status: the plain word OK is success, anything else is shown to
        // the visitor as the error. A non-200 would show a status line.
        http_response_code(200);
        header('Content-Type: text/plain; charset=utf-8');
        echo $ok ? 'OK' : $message;
        return;
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    // Pages were written against different handlers: some read `ok` and
    // `message`, some `success` and `error`. Answering with both means the
    // page, and the tracking it fires on success, needs no change.
    $answer = ['ok' => $ok, 'success' => $ok, 'message' => $message];
    if (!$ok) {
        $answer['error'] = $message;
    }
    echo json_encode($answer);
}
