<?php

namespace MaFordham\AwsS3;

if (!function_exists('mb_convert_encoding')) {
    throw new Exception('mb_convert_encoding function not found. Please install the mbstring PHP extension.');
    exit(1);
}

class AwsS3
{

    protected $timeout;
    protected $dateStamp;
    protected $awsAccessKeyId;
    protected $awsSecretAccessKey;
    protected $awsRegion;

    public function __construct(string $awsAccessKeyId, string $awsSecretAccessKey, string $region = "us-east-1", int $timeout = 30)
    {
        $this->timeout = $timeout;
        $this->dateStamp = gmdate('r', time());
        $this->awsAccessKeyId = mb_convert_encoding($awsAccessKeyId, 'UTF-8');
        $this->awsSecretAccessKey = mb_convert_encoding($awsSecretAccessKey, 'UTF-8');
        $this->awsRegion = $region;
    }

    public function objectUrl(string $bucket, string $path, int $expire = 86400)
    {
        $path = '/' . ltrim($path, '/');

        $now = time();
        $date = gmdate('Ymd', $now);
        $time = gmdate('Ymd\THis\Z', $now);

        $canonicalString = "GET\n"
            . "{$path}\n"
            . "X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential={$this->awsAccessKeyId}%2F{$date}%2F{$this->awsRegion}%2Fs3%2Faws4_request&X-Amz-Date={$time}&X-Amz-Expires={$expire}&X-Amz-SignedHeaders=host\n"
            . "host:{$bucket}.s3.{$this->awsRegion}.amazonaws.com\n"
            . "\n"
            . "host\n"
            . "UNSIGNED-PAYLOAD";

        $stringToSign = "AWS4-HMAC-SHA256\n"
            . "{$time}\n"
            . "{$date}/{$this->awsRegion}/s3/aws4_request\n"
            . hash('sha256', $canonicalString);

        $signingKey = hash_hmac('sha256', "aws4_request", hash_hmac('sha256', "s3", hash_hmac('sha256', $this->awsRegion, hash_hmac('sha256', $date, "AWS4{$this->awsSecretAccessKey}", TRUE), TRUE), TRUE), TRUE);

        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        return ("https://{$bucket}.s3.{$this->awsRegion}.amazonaws.com{$path}?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential={$this->awsAccessKeyId}%2F{$date}%2F{$this->awsRegion}%2Fs3%2Faws4_request&X-Amz-Date={$time}&X-Amz-Expires={$expire}&X-Amz-SignedHeaders=host&X-Amz-Signature={$signature}");
    }

    public function objectGet(string $bucket, string $path, array $headers = [])
    {
        return $this->requestV4('GET', $bucket, $path, '', '', $headers);
    }

    public function objectPut(string $bucket, string $path, string $contentType, string|null $object, array $headers = [])
    {
        return $this->requestV4('PUT', $bucket, $path, $contentType, $object ?? '', $headers);
    }

    public function objectDelete(string $bucket, string $path)
    {
        return $this->requestV4('DELETE', $bucket, $path);
    }

    public function objectsList(string $bucket, string $path = '/', array $params = [])
    {
        $qs = (!empty($params) ? '?' . http_build_query($params) : '');
        return $this->requestV4('GET', $bucket, $path, '', '', [], $qs);
    }

    public function bucketsList()
    {
        // bucketsList uses the generic s3 host, not a bucket-specific one
        $host = "s3.{$this->awsRegion}.amazonaws.com";
        $now = time();
        $date = gmdate('Ymd', $now);
        $amzDate = gmdate('Ymd\THis\Z', $now);
        $payloadHash = hash('sha256', '');

        $headerMap = [
            'host' => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $amzDate,
        ];
        ksort($headerMap);

        $signedHeaders = implode(';', array_keys($headerMap));
        $canonicalHeaders = '';
        foreach ($headerMap as $k => $v) {
            $canonicalHeaders .= "{$k}:{$v}\n";
        }

        $canonicalRequest = "GET\n/\n\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";
        $scope = "{$date}/{$this->awsRegion}/s3/aws4_request";
        $stringToSign = "AWS4-HMAC-SHA256\n{$amzDate}\n{$scope}\n" . hash('sha256', $canonicalRequest);

        $signingKey = hash_hmac('sha256', 'aws4_request',
            hash_hmac('sha256', 's3',
                hash_hmac('sha256', $this->awsRegion,
                    hash_hmac('sha256', $date, "AWS4{$this->awsSecretAccessKey}", true),
                true), true), true);

        $signature = hash_hmac('sha256', $stringToSign, $signingKey);
        $auth = "AWS4-HMAC-SHA256 Credential={$this->awsAccessKeyId}/{$scope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

        $httpHeaders = "Authorization: {$auth}\r\nx-amz-content-sha256: {$payloadHash}\r\nx-amz-date: {$amzDate}";
        $context = $this->createStreamContext('GET', $httpHeaders);
        return $this->snagContents("https://{$host}/", $context);
    }

    private function requestV4(string $method, string $bucket, string $path, string $contentType = '', string $body = '', array $headers = [], string $queryString = '')
    {
        $host = "{$bucket}.s3.{$this->awsRegion}.amazonaws.com";
        $now = time();
        $date = gmdate('Ymd', $now);
        $amzDate = gmdate('Ymd\THis\Z', $now);
        $payloadHash = hash('sha256', $body);

        // Build header map
        $headerMap = [
            'host' => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $amzDate,
        ];
        if ($contentType) $headerMap['content-type'] = $contentType;
        foreach ($headers as $k => $v) {
            $headerMap[strtolower(trim($k))] = trim($v);
        }
        ksort($headerMap);

        $signedHeaders = implode(';', array_keys($headerMap));
        $canonicalHeaders = '';
        foreach ($headerMap as $k => $v) {
            $canonicalHeaders .= "{$k}:{$v}\n";
        }

        $canonicalRequest = "{$method}\n{$path}\n" . ltrim($queryString, '?') . "\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";

        $scope = "{$date}/{$this->awsRegion}/s3/aws4_request";
        $stringToSign = "AWS4-HMAC-SHA256\n{$amzDate}\n{$scope}\n" . hash('sha256', $canonicalRequest);

        $signingKey = hash_hmac('sha256', 'aws4_request',
            hash_hmac('sha256', 's3',
                hash_hmac('sha256', $this->awsRegion,
                    hash_hmac('sha256', $date, "AWS4{$this->awsSecretAccessKey}", true),
                true), true), true);

        $signature = hash_hmac('sha256', $stringToSign, $signingKey);
        $auth = "AWS4-HMAC-SHA256 Credential={$this->awsAccessKeyId}/{$scope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

        // Build HTTP headers string
        $httpHeaders = "Authorization: {$auth}";
        foreach ($headerMap as $k => $v) {
            if ($k === 'host') continue;
            $httpHeaders .= "\r\n{$k}: {$v}";
        }
        if ($body !== '') {
            $httpHeaders .= "\r\nContent-Length: " . strlen($body);
        }

        $context = $this->createStreamContext($method, $httpHeaders, $body);
        $url = "https://{$host}{$path}{$queryString}";
        return $this->snagContents($url, $context);
    }

    private function createStreamContext(string $method, string $header, string $content = '')
    {
        return (stream_context_create(array('http' => array(
            'method' => $method,
            'header' => $header,
            'content' => $content,
            'timeout' => $this->timeout,
            'ignore_errors' => TRUE,
            'ssl' => array(
                'verify_peer' => FALSE,
                'verify_peer_name' => FALSE,
                'verify_depth' => 0,
                'disable_compression' => TRUE,
                'capture_peer_cert' => FALSE,
                'capture_peer_cert_chain' => FALSE
            ),
            'socket' => array(
                'tcp_nodelay' => FALSE
            )
        ))));
    }

    private function snagContents(string $url, &$context, int $retries = 5)
    {
        for ($attempt = 1; $attempt <= $retries; $attempt++) {
            if (($buf = @file_get_contents($url, FALSE, ((is_resource($context)) ? $context : NULL))) !== FALSE) {
                $headers = [];
                foreach (($http_response_header ?? FALSE) as $header) {
                    if (($p = strpos($header, ':')) !== FALSE) {
                        $headers[substr($header, 0, $p)] = trim(substr($header, $p + 1));
                    } else {
                        $headers[] = $header;
                        if (preg_match('/^HTTP\/\S*\s(\d{3})/', $header, $match)) {
                            $headers['Response-Code'] = $match[1];
                        }
                    }
                }

                // Check if we got a 5xx server error - retry if so
                $statusCode = (int)($headers['Response-Code'] ?? 0);
                if ($statusCode >= 500 && $statusCode < 600 && $attempt < $retries) {
                    usleep(pow(2, $attempt - 1) * 1000000); // 1s, 2s, 4s, 8s...
                    continue; // Retry on server errors
                }

                return ([$buf, $headers]);
            }
            if ($attempt < $retries) {
                usleep(pow(2, $attempt - 1) * 1000000); // 1s, 2s, 4s, 8s...
            }
        }
        return (FALSE);
    }

}
