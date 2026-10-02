<?php

namespace App\Services;

use App\Exceptions\UniversityEmailException;
use Illuminate\Support\Facades\Http;

/** Single-attempt transport. Never retain/propagate Mailcow payloads or exceptions. */
final class MailcowProvisioningClient
{
    public function enabled(): bool
    {
        return config('mailcow.provisioning_enabled') && config('mailcow.contract_verified')
            && config('mailcow.base_url') === 'https://mail.alrowaduni.edu.sy'
            && (string) config('mailcow.write_api_key') !== '';
    }

    public function requireEnabled(): void
    {
        if (! $this->enabled()) $this->fail('university_email_provisioning_disabled', 503);
    }

    private function request(string $method, string $path, #[\SensitiveParameter] array $body = []): mixed
    {
        if ($method === 'POST') $this->requireEnabled();
        if (\Illuminate\Support\Facades\DB::transactionLevel() !== 0) $this->fail('university_email_transport_transaction', 503);
        $base = (string) config('mailcow.base_url');
        $key = (string) config('mailcow.write_api_key');
        if ($base !== 'https://mail.alrowaduni.edu.sy' || $key === '') $this->fail('university_email_configuration_invalid', 503);
        try {
            $response = Http::withHeaders(['X-API-Key' => $key])->acceptJson()
                ->withOptions(['verify' => true, 'allow_redirects' => false])->connectTimeout(5)->timeout(15)
                ->send($method, $base.'/api/v1/'.$path, $method === 'POST' ? ['json' => $body] : []);
            $status = $response->status();
            $data = $response->json();
        } catch (\Throwable) {
            $this->fail('university_email_remote_uncertain', 502);
        }
        if (in_array($status, [401, 403], true)) $this->fail('university_email_remote_auth_failed', 502);
        if ($status === 429) $this->fail('university_email_remote_rate_limited', 502);
        if ($status !== 200 || ! is_array($data)) $this->fail('university_email_remote_invalid', 502);
        return $data;
    }

    public function mailbox(string $address): ?array
    {
        // The domain list has an explicit empty-list contract. A nonexistent
        // individual lookup may otherwise resemble denied access on some versions.
        $rows = $this->request('GET', 'get/mailbox/all/alrowaduni.edu.sy');
        if (! array_is_list($rows)) $this->fail('university_email_remote_invalid', 502);
        $matches = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['username'] ?? null)) $this->fail('university_email_remote_invalid', 502);
            if (strtolower($row['username']) === $address) $matches[] = $row;
        }
        if ($matches === []) return null;
        if (count($matches) !== 1) $this->fail('university_email_remote_invalid', 502);
        $data = $matches[0];
        if (! isset($data['username'], $data['quota'], $data['domain'], $data['active_int'])
            || strtolower($data['username']) !== $address || ! is_numeric($data['quota'])
            || ! is_array($data['tags'] ?? null) || ! is_array($data['attributes'] ?? null)) $this->fail('university_email_remote_invalid', 502);
        if (! in_array($data['active_int'], [0, 1, '0', '1'], true) || (int) $data['quota'] < 0
            || (isset($data['quota_used']) && (! is_numeric($data['quota_used']) || (int) $data['quota_used'] < 0))) $this->fail('university_email_remote_invalid', 502);
        return ['address' => strtolower($data['username']), 'domain' => $data['domain'], 'quota_bytes' => (int) $data['quota'],
            'used_bytes' => isset($data['quota_used']) ? (int) $data['quota_used'] : null,
            'active' => (int) $data['active_int'] === 1, 'force_password_change' => (int) ($data['attributes']['force_pw_update'] ?? 0) === 1,
            'tags' => array_values(array_filter($data['tags'], 'is_string'))];
    }

    public function aliasExists(string $address): bool
    {
        $aliases = $this->request('GET', 'get/alias/all');
        if (! array_is_list($aliases)) $this->fail('university_email_remote_invalid', 502);
        foreach ($aliases as $alias) {
            if (! is_array($alias) || ! is_string($alias['address'] ?? null)) $this->fail('university_email_remote_invalid', 502);
            // Block inactive aliases too: do not adopt or reset someone else's identity.
            if (in_array(strtolower($alias['address']), [$address, '@alrowaduni.edu.sy'], true)) return true;
        }
        return false;
    }

    public function create(string $address, string $marker, #[\SensitiveParameter] string $password, string $studentName): void
    {
        $data = $this->request('POST', 'add/mailbox', ['local_part' => explode('@', $address)[0], 'domain' => 'alrowaduni.edu.sy',
            'name' => $studentName, 'active' => '1', 'authsource' => 'mailcow', 'quota' => '50',
            'password' => $password, 'password2' => $password, 'force_pw_update' => '1', 'sogo_access' => '1', 'tags' => [$marker]]);
        $this->success($data, 'mailbox_added', $address);
    }

    public function reset(string $address, array $tags, #[\SensitiveParameter] string $password): void
    {
        $data = $this->request('POST', 'edit/mailbox', ['items' => [$address], 'attr' => ['password' => $password,
            'password2' => $password, 'force_pw_update' => '1', 'tags' => array_values(array_unique($tags))]]);
        $this->success($data, 'mailbox_modified', $address);
    }

    public function setActive(string $address, bool $active): void
    {
        $this->success($this->request('POST', 'edit/mailbox', ['items' => [$address], 'attr' => ['active' => $active ? '1' : '0']]), 'mailbox_modified', $address);
    }

    public function link(string $address, array $tags): void
    {
        $this->success($this->request('POST', 'edit/mailbox', ['items' => [$address], 'attr' => ['tags' => array_values(array_unique($tags))]]), 'mailbox_modified', $address);
    }

    public function deleteMailbox(string $address): void
    {
        // Mailcow removal uses POST. One address, one attempt, no transport retries.
        $this->success($this->request('POST', 'delete/mailbox', [$address]), 'mailbox_removed', $address);
    }

    private function success(array $data, string $code, string $address): void
    {
        if (! array_is_list($data) || count($data) !== 1 || ($data[0]['type'] ?? null) !== 'success'
            || ! is_array($data[0]['msg'] ?? null) || ! in_array($code, $data[0]['msg'], true)
            || ! in_array($address, $data[0]['msg'], true)) $this->fail('university_email_remote_uncertain', 502);
    }

    private function fail(string $code, int $status): never
    {
        throw new UniversityEmailException($code, 'تعذر تأكيد عملية البريد بأمان. راجع الحالة الرسمية؛ لا تُعد إرسال الكتابة تلقائيًا.', $status);
    }
}
