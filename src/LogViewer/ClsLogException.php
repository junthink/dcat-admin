<?php

namespace Dcat\Admin\LogViewer;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ClsLogException extends RuntimeException
{
    public static function failure(string $reason, array $context = [], string $message = 'unavailable'): self
    {
        $id = (string) Str::uuid();
        $details = array_merge([
            'region' => config('admin.cls.region'),
            'logset_id' => config('admin.cls.logset_id'),
            'logset_name' => config('admin.cls.logset_name'),
        ], $context, ['reason' => $reason, 'diagnostic_id' => $id]);
        Log::warning('CLS log query failed', static::redact($details));

        return new self(trans('admin::cls.'.$message).' '.trans('admin::cls.diagnostic_reference', ['id' => $id]));
    }

    /** Classify exceptions without logging messages that may contain signed headers or passwords. */
    public static function exceptionContext(Throwable $exception): array
    {
        $message = $exception->getMessage();
        $cause = 'unknown';
        foreach ([
            'timeout' => '/timed?\s*out|timeout/i',
            'dns_resolution_failed' => '/resolve host|name resolution/i',
            'connection_refused' => '/connection refused|couldn.t connect|failed to connect/i',
            'authentication_failed' => '/NOAUTH|WRONGPASS|authentication|invalid password/i',
            'permission_denied' => '/permission denied|access denied/i',
            'tls_error' => '/SSL|TLS|certificate/i',
            'invalid_encryption_key' => '/cipher|key length|encryption key/i',
        ] as $name => $pattern) {
            if (preg_match($pattern, $message)) {
                $cause = $name;
                break;
            }
        }
        preg_match('/cURL error (\d+):/i', $message, $matches);

        return ['exception_class' => get_class($exception), 'cause' => $cause, 'curl_errno' => isset($matches[1]) ? (int) $matches[1] : null];
    }

    protected static function redact(array $details): array
    {
        $secrets = array_filter([
            config('admin.cls.secret_id'), config('admin.cls.secret_key'), config('admin.cls.token'),
        ], fn ($value) => is_string($value) && $value !== '');
        foreach ($details as $key => $value) {
            if (preg_match('/secret|authorization|token|signature|password|credential/i', $key)) {
                $details[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $details[$key] = static::redact($value);
            } elseif (is_string($value)) {
                $value = str_replace($secrets, '[REDACTED]', $value);
                $value = preg_replace('/TC3-HMAC-SHA256[^\r\n]*|Signature\s*[:=]\s*[a-zA-Z0-9+\/=_-]+/i', '[REDACTED]', $value);
                $details[$key] = mb_substr($value, 0, 2000);
            }
        }

        return $details;
    }
}
