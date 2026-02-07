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
        list($requestHeaders, $amzHeaders) = $this->parseHeaders($headers);

        $awsSignature = $this->createSignature('GET', $bucket, $path, $amzHeaders);

        $context = $this->createStreamContext('GET', "Date: {$this->dateStamp}{$requestHeaders}"
            . "\r\nAuthorization: AWS {$this->awsAccessKeyId}:{$awsSignature}");

        return ($this->snagContents("https://{$bucket}.s3.{$this->awsRegion}.amazonaws.com{$path}", $context));
    }

    public function objectPut(string $bucket, string $path, string $contentType, string|null &$object, array $headers = [])
    {
        $contentMD5 = base64_encode(md5($object, TRUE));

        list($requestHeaders, $amzHeaders) = $this->parseHeaders($headers);

        $awsSignature = $this->createSignature('PUT', $bucket, $path, $amzHeaders, $contentType, $contentMD5);

        $context = $this->createStreamContext(
            'PUT',
            "Date: {$this->dateStamp}{$requestHeaders}"
                . "\r\nContent-Type: {$contentType}"
                . "\r\nContent-MD5: {$contentMD5}"
                . "\r\nContent-Length: " . strlen($object)
                . "\r\nAuthorization: AWS {$this->awsAccessKeyId}:{$awsSignature}",
            $object
        );

        return ($this->snagContents("https://{$bucket}.s3.{$this->awsRegion}.amazonaws.com{$path}", $context));
    }

    public function objectDelete(string $bucket, string $path)
    {
        $awsSignature = $this->createSignature('DELETE', $bucket, $path);

        $context = $this->createStreamContext('DELETE', "Date: {$this->dateStamp}"
            . "\r\nAuthorization: AWS {$this->awsAccessKeyId}:{$awsSignature}");

        return ($this->snagContents("https://{$bucket}.s3.{$this->awsRegion}.amazonaws.com{$path}", $context));
    }

    public function objectsList(string $bucket, string $path = '/', array $params = [])
    {
        $qs = ((!empty($params) && is_array($params)) ? "?" . http_build_query($params) : "");

        $awsSignature = $this->createSignature('GET', $bucket, $path);

        $context = $this->createStreamContext('GET', "Date: {$this->dateStamp}"
            . "\r\nAuthorization: AWS {$this->awsAccessKeyId}:{$awsSignature}");

        return ($this->snagContents("https://{$bucket}.s3.{$this->awsRegion}.amazonaws.com{$path}{$qs}", $context));
    }

    public function bucketsList()
    {
        $awsSignature = $this->createSignature('GET');

        $context = $this->createStreamContext('GET', "Date: {$this->dateStamp}"
            . "\r\nAuthorization: AWS {$this->awsAccessKeyId}:{$awsSignature}");

        return ($this->snagContents("https://s3.{$this->awsRegion}.amazonaws.com/", $context));
    }


    private function parseHeaders(array $headers = [])
    {
        $ret = ['', ''];
        if (!empty($headers) && is_array($headers)) {
            $arr = [];
            foreach ($headers as $k => $v) {
                $ret[0] .= "\r\n" . trim($k) . ": " . trim($v);
                $h = trim(strtolower($k));
                if (str_starts_with($h, 'x-amz-')) {
                    if (isset($arr[$h])) {
                        $arr[$h] .= ',' . trim($v);
                    } else {
                        $arr[$h] = trim($v);
                    }
                }
            }
            ksort($arr);
            foreach ($arr as $k => $v) {
                $ret[1] .= "{$k}:{$v}\n";
            }
        }
        return ($ret);
    }

    private function createSignature(string $method, string $bucket = '', string $path = '', string $amzHeaders = '', string $contentType = '', string $contentMD5 = '')
    {
        return (base64_encode(hash_hmac('sha1', mb_convert_encoding(
            "{$method}\n"
                . "{$contentMD5}\n"
                . "{$contentType}\n"
                . "{$this->dateStamp}\n"
                . "{$amzHeaders}/{$bucket}{$path}",
            'UTF-8'
        ), $this->awsSecretAccessKey, TRUE)));
    }

    private function createStreamContext(string $method, string $header, string|null &$object = NULL)
    {
        return (stream_context_create(array('http' => array(
            'method' => $method,
            'header' => $header,
            'content' => $object ?? '',
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
                return ([$buf, $headers]);
            }
            if ($attempt < $retries) {
                usleep(pow(2, $attempt - 1) * 1000000); // 1s, 2s, 4s, 8s...
            }
        }
        return (FALSE);
    }

}
