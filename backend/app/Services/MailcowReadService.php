<?php

namespace App\Services;

use App\Exceptions\UniversityEmailException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** Fixed-path, read-only adapter. Never returns upstream bodies or exceptions. */
final class MailcowReadService
{
    public function checkDomain(): array
    {
        $base = rtrim((string) config('mailcow.base_url'), '/');
        $key = (string) config('mailcow.api_key');
        $domain = (string) config('mailcow.student_domain');
        $url = parse_url($base);
        if ($key === '' || ! is_array($url) || ($url['scheme'] ?? '') !== 'https' || empty($url['host'])
            || isset($url['user']) || isset($url['pass'])
            || isset($url['query']) || isset($url['fragment']) || ! empty($url['path'])
            || ! preg_match('/\A[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\z/D', $domain)) {
            throw new UniversityEmailException('mailcow_configuration_missing', 'إعدادات اتصال البريد غير مكتملة أو غير صالحة.', 503);
        }
        try {
            $response = Http::acceptJson()->withHeaders(['X-API-Key' => $key])
                ->connectTimeout(5)->timeout(15)
                ->withOptions(['allow_redirects' => false, 'verify' => true])
                ->get($base.'/api/v1/get/domain/'.rawurlencode($domain));
        } catch (ConnectionException) {
            throw new UniversityEmailException('mailcow_connection_failed', 'تعذر الاتصال بخادم البريد أو انتهت المهلة.', 503);
        } catch (\Exception) {
            // Do not retain a previous exception: it may include request headers.
            throw new UniversityEmailException('mailcow_connection_failed', 'تعذر الاتصال بخادم البريد.', 503);
        }
        if (in_array($response->status(), [401, 403], true)) {
            throw new UniversityEmailException('mailcow_authentication_failed', 'رفض خادم البريد صلاحية القراءة.', 502);
        }
        if ($response->status() === 404) $this->missingDomain();
        if (! $response->successful()) $this->invalidResponse();
        try { $data = json_decode($response->body(), true, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { $this->invalidResponse(); }
        if ($data === [] || $data === null || $data === false) $this->missingDomain();
        if (! is_array($data) || ($data['domain_name'] ?? null) !== $domain) $this->invalidResponse();
        $active = $data['active_int'] ?? $data['active'] ?? null;
        $count = $data['mboxes_in_domain'] ?? null;
        $limit = $data['max_num_mboxes_for_domain'] ?? null;
        if (! in_array($active, [0, 1, '0', '1', false, true], true)
            || ! $this->nonnegativeInteger($count) || ! $this->nonnegativeInteger($limit)) $this->invalidResponse();
        return ['domain' => $domain, 'active' => (bool) $active, 'mailbox_count' => (int) $count,
            'mailbox_limit' => (int) $limit, 'remaining_mailboxes' => max(0, (int) $limit - (int) $count),
            'checked_at' => now()->utc()->toIso8601String()];
    }

    private function nonnegativeInteger(mixed $value): bool
    {
        return (is_int($value) || (is_string($value) && preg_match('/\A[0-9]+\z/D', $value)))
            && filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) !== false;
    }
    private function missingDomain(): never { throw new UniversityEmailException('mailcow_domain_missing', 'نطاق بريد الطلاب غير موجود على الخادم.', 502); }
    private function invalidResponse(): never { throw new UniversityEmailException('mailcow_invalid_response', 'أعاد خادم البريد استجابة غير صالحة؛ لم يتم تأكيد الاتصال.', 502); }
}
