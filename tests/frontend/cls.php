<?php

use Illuminate\Support\Facades\Http;

// Browser fixtures never contact CLS or use real cloud credentials.
Http::preventStrayRequests();
Http::fake(function ($request) {
    if ($request->header('X-TC-Action')[0] === 'DescribeTopics') {
        return Http::response(['Response' => ['Topics' => [
            ['LogsetId' => 'browser-logset', 'TopicId' => 'api', 'TopicName' => 'live-server-prod-stdout'],
            ['LogsetId' => 'browser-logset', 'TopicId' => 'worker', 'TopicName' => 'mqtt-server-prod-stdout'],
        ], 'TotalCount' => 2]]);
    }
    if ($request->header('X-TC-Action')[0] !== 'SearchLog') {
        throw new RuntimeException('Unexpected CLS fixture action');
    }
    if (str_contains($request['QueryString'], '"fixture-failure"')) {
        return Http::response(['Response' => ['Error' => ['Code' => 'UnauthorizedOperation', 'Message' => 'Private fixture cloud details'], 'RequestId' => 'browser-error']]);
    }
    $id = $request['TopicId'];
    $times = $id === 'api' ? [900, 700, 500] : [800, 600, 400];
    $rows = [];
    if (! str_contains($request['QueryString'], 'level_name:"INFO"')) {
        foreach ($times as $time) {
            $timestamp = 1793842200000 + $time;
            if ($timestamp >= $request['From'] && $timestamp < $request['To']) {
                $rows[] = ['TopicId' => $id, 'Time' => $timestamp, 'HostName' => $id.'-pod',
                    'LogJson' => json_encode(['level_name' => 'ERROR', 'message' => $id.'-'.$time.($id === 'api' ? ' <script>window.__clsXss=1</script>' : ' 业务处理失败'),
                        'context' => ['request_id' => 'native-cls-browser'], 'extra' => ['unicode' => '业务日志', 'long_value' => str_repeat('long-log-value-', 2000)]])];
            }
        }
    }
    $slice = array_slice($rows, $request['Offset'], $request['Limit']);

    return Http::response(['Response' => ['ListOver' => $request['Offset'] + count($slice) >= count($rows), 'Results' => $slice]]);
});
