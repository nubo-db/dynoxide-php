# Dynoxide for PHP

Start [Dynoxide](https://github.com/nubo-db/dynoxide), a DynamoDB-compatible
engine, from your PHP tests. The package downloads the right Dynoxide binary
for your platform, checks its hash, caches it, and runs a server on a free
port for as long as your test run needs one. No Docker or Java is involved,
and it needs nothing beyond PHP's own `zlib` and `openssl` extensions (and
`zip` on Windows).

This package is under development and hasn't been released yet.

## Install

```sh
composer require --dev nubo-db/dynoxide
```

It needs PHP 8.1 or later on Linux (x86_64 or arm64), macOS (Intel or Apple
silicon) or Windows (x86_64). On Windows it also needs `ext-zip` to unpack the
download, and says so if it's missing.

The package is versioned in step with Dynoxide: version 1.7.0 of this package
runs Dynoxide 1.7.0, and carries that release's checksums.

## With PHPUnit

Add the extension to `phpunit.xml`:

```xml
<extensions>
    <bootstrap class="Dynoxide\PHPUnit\Extension"/>
</extensions>
```

When the tests start, a server starts too, and the
[environment variables](#what-exportenv-sets) the AWS SDK reads are pointed
at it. A `DynamoDbClient` made inside a test then talks to Dynoxide with no
other configuration:

```php
use Aws\DynamoDb\DynamoDbClient;

$client = new DynamoDbClient(['version' => 'latest']);
```

This needs `aws/aws-sdk-php` 3.298.9 or later. Versions from 3.281.0 read
`AWS_ENDPOINT_URL_DYNAMODB` but still send requests to AWS. On an older SDK,
pass `'endpoint' => getenv('AWS_ENDPOINT_URL_DYNAMODB')` yourself.

The server stops when the run finishes. This works on PHPUnit 10, 11, 12 and
13, and on Pest 3 and later, which runs on PHPUnit.

The extension takes some optional parameters:

```xml
<extensions>
    <bootstrap class="Dynoxide\PHPUnit\Extension">
        <parameter name="db-path" value="var/dynoxide.db"/>
        <parameter name="schema" value="tests/schema.json"/>
        <parameter name="timeout" value="20"/>
    </bootstrap>
</extensions>
```

| Parameter | What it does |
|---|---|
| `db-path` | Keep the data in this file, so it survives from one run to the next. Without it, the data lives in memory. |
| `schema` | Create the tables in this file (DescribeTable JSON, as `aws dynamodb describe-table` prints it) before the tests start. |
| `timeout` | Seconds to wait for the server to be ready. Defaults to 10. |
| `binary` | Run this Dynoxide binary instead of the one the package downloads. See [your own binary](#your-own-binary). |
| `binary-from-path` | `true` to run the `dynoxide` on your `PATH` instead. |

Relative paths are relative to the `phpunit.xml` they're in.

The server starts when the tests do, so data providers and PHPUnit's
`bootstrap` file run before it exists and can't reach it. Create tables in
`setUp()` or `setUpBeforeClass()`, or with the `schema` parameter. It also
stops as soon as PHPUnit says the run has finished, so another extension that
wants DynamoDB at that point may find it already gone.

### When it can't start

If the server can't start (the download failed, say, or a parameter is
wrong), the run fails with a PHPUnit warning saying why. The tests still run,
but against `http://127.0.0.1:1`, where nothing answers, and with the dummy
credentials, so no test reaches real DynamoDB with credentials from your
environment by accident.

### ParaTest and Pest `--parallel`

Each worker gets its own server, so tests in different workers never see
each other's data. With `db-path` set, each worker also gets its own file,
with the worker's number on the end: `var/dynoxide.db-1`,
`var/dynoxide.db-2` and so on, and prints a line saying which one it's using
(Pest `--parallel` hides that output). Two servers can't share a database
file, so ParaTest's `--no-test-tokens`, which takes those numbers away, is
refused when `db-path` is set.

On a cold cache every worker downloads the binary at once. That's safe, just
wasteful, and `vendor/bin/dynoxide install` beforehand avoids it (see
[CI](#ci)).

## Without PHPUnit

```php
use Aws\DynamoDb\DynamoDbClient;
use Dynoxide\Dynoxide;

$server = Dynoxide::start();

$client = new DynamoDbClient($server->clientConfig());
// ...

$server->stop();
```

`start()` takes options as an array:

```php
$server = Dynoxide::start([
    'dbPath' => '/tmp/test.db',
    'schema' => __DIR__ . '/schema.json',
    'startTimeout' => 20.0,
]);
```

The others are `binary`, `binaryFromPath`, and `log`: a closure that gets
the line printed once per start (which binary ran and where it's listening),
instead of it going to stderr. Give each server its own `dbPath`.

A `Server` has `endpoint`, `host`, `port`, `region`, `accessKeyId` and
`secretAccessKey`, and `clientConfig()` returns an array you can hand
straight to `DynamoDbClient`.

`stop()` is optional. A server left running stops when PHP exits, and the
`Server` object going out of scope doesn't stop it.

### What `exportEnv()` sets

`$server->exportEnv()` points AWS SDKs in this process, and processes it
starts, at the server. It sets these through `putenv()`, `$_ENV` and
`$_SERVER`:

| Variable | Value |
|---|---|
| `AWS_ENDPOINT_URL_DYNAMODB` | the server's endpoint |
| `DYNAMODB_ENDPOINT` | the server's endpoint, for Laravel's DynamoDB cache and session drivers |
| `AWS_ACCESS_KEY_ID` | `dynoxide` |
| `AWS_SECRET_ACCESS_KEY` | `dynoxide` |
| `AWS_REGION` and `AWS_DEFAULT_REGION` | `us-east-1` |
| `AWS_IGNORE_CONFIGURED_ENDPOINT_URLS` | `false` |

`AWS_IGNORE_CONFIGURED_ENDPOINT_URLS` is set because `true`, in the
environment or as `ignore_configured_endpoint_urls` in `~/.aws/config`, makes
the SDKs skip `AWS_ENDPOINT_URL_DYNAMODB` and call AWS instead. Setting it to
`false` overrides the config file too. It also means endpoints you've
configured for other services are used again during the run.

It also removes `AWS_SESSION_TOKEN`, so a real session token from CI isn't
sent along with the dummy credentials. It leaves `AWS_ENDPOINT_URL` alone,
since that would send every AWS service to Dynoxide.

`start()` doesn't call it for you, because the credentials apply to every
AWS client that reads the environment, not only DynamoDB ones. The PHPUnit
extension does call it.

## When a run is killed

The server watches its stdin, which only the PHP process that started it
holds. When that process goes, however it goes, the server stops too:

- **Ctrl-C, or PHPUnit killed.** The server is gone within a fraction of a
  second.
- **ParaTest killed.** Each worker finishes the test it's running, then
  exits, and its server goes with it. So a server can outlive ParaTest by the
  length of one test.
- **A fatal error.** The server stops, but PHP skips the clean-up after a
  fatal error, so its log directory (`dynoxide-*` in the system temp
  directory) stays until the next start on Linux or macOS deletes it.
- **Forking after start.** A process forked with `pcntl_fork()` after the
  server started holds its stdin too, so the server keeps running until that
  child exits as well. `stop()` in the child does nothing; only the process
  that started a server stops it.

This needs Dynoxide 1.7.0 or later. An older binary, from
[`binary` or `DYNOXIDE_BINARY`](#your-own-binary), stops on `stop()` or when
PHP exits normally, but keeps running after Ctrl-C, a killed run or a fatal
error. On Linux and macOS the next start stops one left by a process that has
gone, if it's still the same process.

## CI

`vendor/bin/dynoxide install` downloads and verifies the binary ahead of
time and prints its path. Run it after `composer install` to warm the cache,
or to get a machine ready to run tests offline. A second run does nothing,
and `--force` downloads it again.

On success the path is the only thing it writes to stdout, so
`$(vendor/bin/dynoxide install)` is the binary. Messages go to stderr, and
any failure exits 1. If `DYNOXIDE_BINARY` is set, it says so, since the tests
will run that binary instead (unless they set the `binary` option).

The cache is `DYNOXIDE_CACHE_DIR` if that's set, otherwise
`$XDG_CACHE_HOME/dynoxide` if that's set (on Windows too), otherwise
`~/.cache/dynoxide`, or `%LOCALAPPDATA%\dynoxide` on Windows. Dynoxide's other launchers share it, so a binary one of them
downloaded is reused here.

To keep it between GitHub Actions runs, key the cache on the Dynoxide
version and the runner's platform. The version comes from the installed
package, so this goes after `composer install`:

```yaml
- id: dynoxide
  shell: bash
  run: |
    version=$(php -r 'require "vendor/autoload.php"; echo Dynoxide\Release::VERSION;')
    echo "version=$version" >> "$GITHUB_OUTPUT"

- uses: actions/cache@v4
  with:
    path: ${{ runner.temp }}/dynoxide-cache
    key: dynoxide-${{ steps.dynoxide.outputs.version }}-${{ runner.os }}-${{ runner.arch }}

- run: vendor/bin/dynoxide install
  env:
    DYNOXIDE_CACHE_DIR: ${{ runner.temp }}/dynoxide-cache

- run: vendor/bin/phpunit
  env:
    DYNOXIDE_CACHE_DIR: ${{ runner.temp }}/dynoxide-cache
```

### Proxies and mirrors

Behind a proxy, set `https_proxy` (or `HTTPS_PROXY`), and `no_proxy` for
hosts to reach directly. Unlike Composer, the download doesn't fall back to
`http_proxy`.

To download from somewhere other than GitHub, set
`DYNOXIDE_DOWNLOAD_BASE_URL` to a mirror laid out like the GitHub releases:
`<base>/v<version>/dynoxide-<target>.tar.gz` (`.zip` on Windows) and
`<base>/v<version>/sha256sums.txt`, where `<version>` is the Dynoxide version
this package runs. It has to be https, unless it's on this machine. A mirror
is trusted to serve the files and nothing more. What it serves has to match the checksums inside this package,
so it can't swap in a different binary.

## Your own binary

Set `DYNOXIDE_BINARY`, or the `binary` option, to run a Dynoxide binary you
already have, such as one built from source. It isn't checksum-verified: the
package runs it with `--version` and refuses anything older than 1.6.0.

Dynoxide 1.6.x works, with two differences. The package finds out it's ready
by polling it rather than from the line 1.7.0 prints, and it doesn't stop when
the run is killed (see [above](#when-a-run-is-killed)).

## Licence

Dual-licensed under either of [MIT](LICENSE-MIT) or
[Apache 2.0](LICENSE-APACHE), at your option, the same as Dynoxide.
