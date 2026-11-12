<?php
/** Configuration module actions */
const INTEGRATION_FIELDS = [
    'mpesa' => ['creds' => ['consumer_key' => 'Consumer key', 'consumer_secret' => 'Consumer secret', 'passkey' => 'Lipa na M-Pesa passkey'], 'cfg' => ['shortcode' => 'Paybill / shortcode', 'callback_base' => 'Public base URL for callbacks (https://…)']],
    'coop' => ['creds' => ['consumer_key' => 'Consumer key', 'consumer_secret' => 'Consumer secret'], 'cfg' => ['account_number' => 'Collection account number']],
    'stripe' => ['creds' => ['secret_key' => 'Secret key (sk_…)', 'webhook_secret' => 'Webhook signing secret (whsec_…)'], 'cfg' => ['publishable_key' => 'Publishable key (pk_…)', 'currency' => 'Currency (kes, usd…)']],
    'smtp' => ['creds' => ['password' => 'SMTP password'], 'cfg' => ['host' => 'Host', 'port' => 'Port', 'encryption' => 'Encryption (tls / ssl / none)', 'username' => 'Username', 'from_email' => 'From email', 'from_name' => 'From name']],
    'africastalking' => ['creds' => ['api_key' => 'API key'], 'cfg' => ['username' => 'Username', 'sender_id' => 'Sender ID']],
    'anthropic' => ['creds' => ['api_key' => 'API key'], 'cfg' => ['model' => 'Model']],
];
function _can_integration(array $i): bool { return can('config.integrations.manage') || ($i['scope'] === 'ecommerce' && can('ecommerce.integrations')); }
return [
    'save-settings' => ['config.settings.manage', function () {
        $n = 0;
        foreach (all("SELECT setting_key, value_type, setting_value FROM cfg_settings") as $s) {
            if ($s['value_type'] === 'bool') { if (!array_key_exists('s_' . $s['setting_key'] . '__present', $_POST)) continue; $v = !empty($_POST['s_' . $s['setting_key']]) ? '1' : '0'; }
            elseif (!array_key_exists('s_' . $s['setting_key'], $_POST)) continue;
            else $v = trim((string)$_POST['s_' . $s['setting_key']]);
            if (in_array($s['value_type'], ['int', 'decimal'], true) && $v !== '' && !is_numeric($v)) throw new ValidationError(['s_' . $s['setting_key'] => 'Enter a number.']);
            if ((string)$s['setting_value'] !== $v) { set_setting($s['setting_key'], $v); $n++; audit('config', 'update', 'setting', null, $s['setting_key'] . ' changed', ['value' => $s['setting_value']], ['value' => $v]); }
        }
        json_ok([], $n ? "$n setting(s) saved." : 'No changes.');
    }],
    'save-integration' => [null, function () {
        $i = one("SELECT * FROM api_integrations WHERE code = ?", [(string)input('code')]); if (!$i) json_fail('Unknown integration', 404);
        if (!_can_integration($i)) json_fail('You cannot manage this integration.', 403);
        $fields = INTEGRATION_FIELDS[$i['provider']] ?? ['creds' => [], 'cfg' => []];
        $creds = json_decode((string)$i['credentials'], true) ?: []; $cfg = json_decode((string)$i['settings'], true) ?: [];
        foreach ($fields['creds'] as $k => $_) { $v = (string)($_POST['creds'][$k] ?? ''); if ($v !== '' && $v !== '••••••••') $creds[$k] = vk_encrypt($v); if (!empty($_POST['clear'][$k])) unset($creds[$k]); }
        foreach ($fields['cfg'] as $k => $_) if (isset($_POST['cfg'][$k])) $cfg[$k] = trim((string)$_POST['cfg'][$k]);
        update('api_integrations', ['credentials' => json_encode((object)$creds), 'settings' => json_encode((object)$cfg), 'environment' => input('environment', 'sandbox') === 'live' ? 'live' : 'sandbox', 'simulate' => (int)!!input('simulate'), 'is_active' => (int)!!input('is_active')], 'code = :c', ['c' => $i['code']]);
        audit('config', 'update', 'integration', $i['id'], 'Updated integration ' . $i['code'] . ' (secrets not logged)', ['simulate' => $i['simulate'], 'environment' => $i['environment']], ['simulate' => (int)!!input('simulate'), 'environment' => input('environment')]);
        json_ok([], 'Integration saved. Secrets are stored encrypted.');
    }],
    'test-integration' => [null, function () {
        $i = integration((string)input('code')); if (!$i) json_fail('Unknown', 404);
        if (!_can_integration($i)) json_fail('Forbidden', 403);
        if ($i['simulate']) json_ok(['ok' => true], 'Simulate mode is on — turn it off to test the live connection.');
        try {
            switch ($i['provider']) {
                case 'mpesa': mpesa_token($i['code']); $m = 'Safaricom Daraja: authenticated.'; break;
                case 'coop': coop_token(); $m = 'Co-op Bank: authenticated.'; break;
                case 'stripe': $b = stripe_api('GET', 'balance', [], $i['code']); $m = 'Stripe: connected (' . count($b['available'] ?? []) . ' balance currencies).'; break;
                case 'smtp': $to = (string)(staff()['email'] ?? ''); mailer_send($to, 'Vikao SMTP test', email_wrap('SMTP works', '<p>This is a test email from Vikao PMS.</p>')); $m = "Test email sent to $to."; break;
                case 'africastalking': $ph = (string)(staff()['phone'] ?? ''); if (!$ph) throw new RuntimeException('Add a phone number to your profile first.'); sms_send($ph, 'Vikao SMS test OK'); $m = "Test SMS sent to $ph."; break;
                case 'anthropic': $r = anthropic_insights(['test' => 1], []); $m = 'Anthropic: ' . (empty($r['insights']) ? 'reachable.' : 'responded with insights.'); break;
                default: $m = 'No test available.';
            }
            integration_mark($i['code'], true); json_ok(['ok' => true], $m);
        } catch (Throwable $e) { integration_mark($i['code'], false, $e->getMessage()); json_fail($e->getMessage()); }
    }],
];
