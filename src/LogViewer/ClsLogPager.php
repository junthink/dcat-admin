<?php

namespace Dcat\Admin\LogViewer;

use Dcat\Admin\Admin;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/** Merge sorted topic streams without dropping the unconsumed rows of any topic. */
class ClsLogPager
{
    public function __construct(protected ClsClient $client)
    {
    }

    public function search(array $parameters, array $topics, array $filters): array
    {
        $scope = hash('sha256', serialize([
            config('admin.auth.guard'), Admin::user()?->getAuthIdentifier(),
            config('admin.cls'), $topics, $parameters,
        ]));
        $cursor = $filters['cursor'] ?? '';
        $page = $filters['page'];
        if ($cursor !== '') {
            try {
                $encrypted = Cache::get('dcat:cls:'.$scope.':'.$cursor);
            } catch (\Exception $exception) {
                throw ClsLogException::failure('pagination_cache_read_failed', ['cache_store' => config('cache.default')] + ClsLogException::exceptionContext($exception));
            }
            try {
                $state = is_string($encrypted) ? json_decode(Crypt::decryptString($encrypted), true) : null;
            } catch (DecryptException $exception) {
                $state = null;
            }
            if (! is_array($state) || $state['page'] !== $page) {
                throw ClsLogException::failure('pagination_cursor_expired_or_changed', [], 'cursor_expired');
            }
        } else {
            if ($page !== 1) {
                throw ClsLogException::failure('pagination_cursor_missing', [], 'cursor_expired');
            }
            $state = ['page' => 1, 'previous' => '', 'streams' => []];
            foreach ($topics as $id => $name) {
                $state['streams'][$id] = ['offset' => 0, 'buffer' => [], 'over' => false];
            }
        }

        $logs = [];
        $size = $parameters['Limit'];
        while (count($logs) < $size) {
            $selected = null;
            foreach ($state['streams'] as $id => &$stream) {
                if ($stream['buffer'] === [] && ! $stream['over']) {
                    // Offset is per topic; no multi-topic limit or 10,000-row Context ceiling.
                    $result = $this->client->searchTopic(array_replace($parameters, [
                        'Limit' => min($size, 100), 'Offset' => $stream['offset'],
                    ]), $id);
                    $stream['buffer'] = $result['Results'] ?? [];
                    $stream['over'] = $result['ListOver'];
                    $stream['offset'] += count($stream['buffer']);
                    if ($stream['buffer'] === [] && ! $stream['over']) {
                        throw ClsLogException::failure('empty_incomplete_search_page', ['action' => 'SearchLog', 'topic_id' => $id, 'request_id' => $result['RequestId'] ?? null]);
                    }
                    foreach ($stream['buffer'] as $log) {
                        if (! is_numeric($log['Time'] ?? null) || (isset($log['TopicId']) && $log['TopicId'] !== $id)) {
                            throw ClsLogException::failure('invalid_search_record_scope_or_time', ['action' => 'SearchLog', 'topic_id' => $id, 'request_id' => $result['RequestId'] ?? null]);
                        }
                    }
                }
                if ($stream['buffer'] !== [] && ($selected === null
                    || $stream['buffer'][0]['Time'] > $state['streams'][$selected]['buffer'][0]['Time'])) {
                    $selected = $id;
                }
            }
            unset($stream);
            if ($selected === null) {
                break;
            }
            $log = array_shift($state['streams'][$selected]['buffer']);
            $log['TopicId'] = $selected;
            $log['TopicName'] = $topics[$selected];
            $logs[] = $log;
        }

        $next = null;
        foreach ($state['streams'] as $stream) {
            if ($stream['buffer'] !== [] || ! $stream['over']) {
                $next = Str::random(64);
                break;
            }
        }
        $previous = $state['previous'];
        if ($next !== null) {
            $state['page']++;
            $state['previous'] = $cursor;
            // Opaque, user/filter/scope-bound tokens; never place log bodies in a URL.
            $ttl = max(60, min(3600, (int) config('admin.cls.pagination_ttl', 600)));
            try {
                $stored = Cache::put('dcat:cls:'.$scope.':'.$next, Crypt::encryptString(json_encode($state, JSON_THROW_ON_ERROR)), $ttl);
            } catch (\Exception $exception) {
                throw ClsLogException::failure('pagination_cache_write_failed', ['cache_store' => config('cache.default')] + ClsLogException::exceptionContext($exception));
            }
            if (! $stored) {
                throw ClsLogException::failure('pagination_cache_write_rejected', ['cache_store' => config('cache.default')]);
            }
        }

        return ['results' => $logs, 'has_more' => $next !== null, 'next_cursor' => $next, 'previous_cursor' => $previous];
    }
}
