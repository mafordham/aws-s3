# AwsS3

A lightweight PHP class to interact directly with **Amazon S3**.
No AWS SDK, no AWS CLI — just PHP communicating directly with the AWS REST API.

This class supports common S3 operations like uploading, downloading, listing, and deleting objects. It also supports Composer autoloading for easy integration into modern PHP projects.

## Features

- Direct communication with AWS S3 REST API
- No external libraries required (but supports Composer)
- Simple, easy-to-use methods
- Secure AWS request signing

## Installation

You can include the class manually:

```php
require_once 'src/AwsS3.php';

Or use Composer autoloading by adding the src/ directory to your composer.json:

{
  "autoload": {
    "psr-4": {
      "MaFordham\\AwsS3\\": "src/"
    }
  }
}
```

Then run:

```bash
composer dump-autoload
```

and load the class using PSR-4 autoloading.

# Configuration

Create a new instance of AwsS3 with your AWS credentials and region:

```php
use YourNamespace\\AwsS3\\AwsS3;

$s3 = new AwsS3([
    'access_key' => 'YOUR_AWS_ACCESS_KEY',
    'secret_key' => 'YOUR_AWS_SECRET_KEY',
    'region'     => 'us-east-1',
]);
```

* ⚠️ Keep your AWS credentials secure and never commit them to source control.

# Usage

## List Buckets

```php
$buckets = $s3->listBuckets();
print_r($buckets);
```

## Upload a File

```php
$s3->uploadFile('my-bucket', 'remote/path/file.txt', '/local/path/file.txt');
```

## Download a File

```php
$s3->downloadFile('my-bucket', 'remote/path/file.txt', '/local/path/file.txt');
```

## Delete an Object

```php
$s3->deleteObject('my-bucket', 'remote/path/file.txt');
```

## Security

* Always store your AWS credentials securely
* Consider using environment variables or a .env file
* Do not commit secrets into the repository

# Contributing

Pull requests and issues are welcome. Please follow the standard PR workflow.

# License

This project is licensed under the MIT License.
