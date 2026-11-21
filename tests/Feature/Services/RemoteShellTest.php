<?php

use App\Models\Filesystem;
use App\Models\SshCredential;
use App\Services\RemoteShell;
use phpseclib3\Crypt\Common\PrivateKey;
use phpseclib3\Crypt\EC;
use phpseclib3\Net\SFTP;

/*
 * RemoteShell: the SSH/SFTP connection to the server behind a (scoped) sftp
 * filesystem. The phpseclib connection is replaced by a mock resolved from
 * the container, so no server is needed.
 */

beforeEach(function () {
    $this->sftp = Mockery::mock(SFTP::class);
    $this->sftpConnections = [];
    app()->bind(SFTP::class, function ($app, array $parameters) {
        $this->sftpConnections[] = $parameters;

        return $this->sftp;
    });
});

/**
 * @param  array<string, mixed>  $credential
 */
function createRemoteShellServer(array $credential = [], array $attributes = []): Filesystem
{
    $sshCredential = SshCredential::query()->create([
        'name' => 'Backup server login',
        'username' => 'molmedb',
        'type' => SshCredential::AUTH_TYPE_PASSWORD,
        'password' => 'ssh-password',
        ...$credential,
    ]);

    return Filesystem::query()->create([
        'type' => Filesystem::TYPE_BACKUPS,
        'name' => 'Remote server',
        'driver' => Filesystem::DRIVER_SFTP,
        'host' => 'backup.example.org',
        'port' => 2222,
        'root_path' => '/srv/molmedb/',
        'ssh_credential_id' => $sshCredential->id,
        ...$attributes,
    ]);
}

function createRemoteShellScope(Filesystem $parent, string $rootPath): Filesystem
{
    return Filesystem::query()->create([
        'type' => Filesystem::TYPE_DB_FULL_BACKUP,
        'name' => 'Scoped '.$rootPath,
        'scope_id' => $parent->id,
        'root_path' => $rootPath,
    ]);
}

function connectedRemoteShell(): RemoteShell
{
    test()->sftp->shouldReceive('login')->andReturnTrue();

    return RemoteShell::forFilesystem(createRemoteShellServer());
}

test('a scoped filesystem connects to its server with the password of the server credential', function () {
    $server = createRemoteShellServer();
    $scope = createRemoteShellScope(createRemoteShellScope($server, '/db/'), 'full');
    $this->sftp->shouldReceive('login')->once()->with('molmedb', 'ssh-password')->andReturnTrue();

    $shell = RemoteShell::forFilesystem($scope);

    expect($shell)->toBeInstanceOf(RemoteShell::class)
        ->and($this->sftpConnections)->toBe([['host' => 'backup.example.org', 'port' => 2222]]);
});

test('a key credential logs in with the decrypted private key', function () {
    $key = EC::createKey('Ed25519');
    $server = createRemoteShellServer([
        'type' => SshCredential::AUTH_TYPE_KEY,
        'password' => null,
        'private_key' => $key->withPassword('key-passphrase')->toString('OpenSSH'),
        'passphrase' => 'key-passphrase',
    ], ['port' => null]);

    $this->sftp->shouldReceive('login')->once()->with('molmedb', Mockery::on(
        fn (mixed $secret): bool => $secret instanceof PrivateKey
            && $secret->getPublicKey()->toString('OpenSSH') === $key->getPublicKey()->toString('OpenSSH'),
    ))->andReturnTrue();

    RemoteShell::forFilesystem($server);

    expect($this->sftpConnections)->toBe([['host' => 'backup.example.org', 'port' => 22]]);
});

test('connecting fails without a credential, for a non-sftp server and on a rejected login', function () {
    $withoutCredential = createRemoteShellServer();
    $withoutCredential->update(['ssh_credential_id' => null]);
    $localServer = createRemoteShellServer([], ['driver' => Filesystem::DRIVER_LOCAL, 'name' => 'Local disk']);
    $this->sftp->shouldReceive('login')->once()->andReturnFalse();

    expect(fn () => RemoteShell::forFilesystem(createRemoteShellScope($withoutCredential, 'db')))
        ->toThrow(RuntimeException::class, 'Filesystem [Remote server] has no SSH credential.')
        ->and(fn () => RemoteShell::forFilesystem(createRemoteShellScope($localServer, 'db')))
        ->toThrow(RuntimeException::class, 'Filesystem [Local disk] is not an sftp filesystem.')
        ->and(fn () => RemoteShell::forFilesystem(createRemoteShellServer()))
        ->toThrow(RuntimeException::class, 'SSH login to [backup.example.org] failed.');
});

test('the absolute root joins the server root with the scope prefixes', function () {
    $server = createRemoteShellServer();
    $scope = createRemoteShellScope($server, '/db/');

    expect(RemoteShell::absoluteRoot($server))->toBe('/srv/molmedb')
        ->and(RemoteShell::absoluteRoot($scope))->toBe('/srv/molmedb/db')
        ->and(RemoteShell::absoluteRoot(createRemoteShellScope($scope, 'full/')))->toBe('/srv/molmedb/db/full')
        ->and(RemoteShell::absoluteRoot(createRemoteShellScope($scope, '')))->toBe('/srv/molmedb/db');
});

test('the same server is recognised through scopes', function () {
    $this->sftp->shouldReceive('login')->andReturnTrue();
    $server = createRemoteShellServer();
    $shell = RemoteShell::forFilesystem(createRemoteShellScope($server, 'db'));

    expect($shell->isSameServer($server))->toBeTrue()
        ->and($shell->isSameServer(createRemoteShellScope($server, 'predictions')))->toBeTrue()
        ->and($shell->isSameServer(createRemoteShellServer()))->toBeFalse();
});

test('uploads create the parent directory and fail loudly', function () {
    $shell = connectedRemoteShell();
    $this->sftp->shouldReceive('mkdir')->with('/srv/exports', -1, true)->twice();
    $this->sftp->shouldReceive('mkdir')->with('/srv/broken', -1, true)->twice();
    $this->sftp->shouldReceive('put')->once()->with('/srv/exports/list.txt', 'contents')->andReturnTrue();
    $this->sftp->shouldReceive('put')->once()->with('/srv/exports/export.zip', '/tmp/export.zip', SFTP::SOURCE_LOCAL_FILE)->andReturnTrue();
    $this->sftp->shouldReceive('put')->with('/srv/broken/list.txt', 'contents')->andReturnFalse();
    $this->sftp->shouldReceive('put')->with('/srv/broken/export.zip', '/tmp/export.zip', SFTP::SOURCE_LOCAL_FILE)->andReturnFalse();

    $shell->put('/srv/exports/list.txt', 'contents');
    $shell->putFile('/srv/exports/export.zip', '/tmp/export.zip');

    expect(fn () => $shell->put('/srv/broken/list.txt', 'contents'))
        ->toThrow(RuntimeException::class, 'Unable to upload [/srv/broken/list.txt].')
        ->and(fn () => $shell->putFile('/srv/broken/export.zip', '/tmp/export.zip'))
        ->toThrow(RuntimeException::class, 'Unable to upload [/tmp/export.zip] to [/srv/broken/export.zip].');
});

test('downloads return the contents, null for a missing file and fail loudly into a local file', function () {
    $shell = connectedRemoteShell();
    $this->sftp->shouldReceive('get')->with('/srv/manifest.json')->andReturn('{"files":1}');
    $this->sftp->shouldReceive('get')->with('/srv/missing.json')->andReturnFalse();
    $this->sftp->shouldReceive('get')->once()->with('/srv/export.zip', '/tmp/export.zip')->andReturnTrue();
    $this->sftp->shouldReceive('get')->with('/srv/missing.zip', '/tmp/missing.zip')->andReturnFalse();

    expect($shell->get('/srv/manifest.json'))->toBe('{"files":1}')
        ->and($shell->get('/srv/missing.json'))->toBeNull();

    $shell->getFile('/srv/export.zip', '/tmp/export.zip');

    expect(fn () => $shell->getFile('/srv/missing.zip', '/tmp/missing.zip'))
        ->toThrow(RuntimeException::class, 'Unable to download [/srv/missing.zip].');
});

test('a directory listing holds the file names only and is empty for a missing directory', function () {
    $shell = connectedRemoteShell();
    $this->sftp->shouldReceive('nlist')->with('/srv/exports')->andReturn(['.', 'a.zip', '..', 'b.zip']);
    $this->sftp->shouldReceive('nlist')->with('/srv/missing')->andReturnFalse();

    expect($shell->list('/srv/exports'))->toBe(['a.zip', 'b.zip'])
        ->and($shell->list('/srv/missing'))->toBe([]);
});

test('a command runs with stderr merged and returns its output and exit code', function () {
    $shell = connectedRemoteShell();
    $this->sftp->shouldReceive('setTimeout')->once()->with(600);
    $this->sftp->shouldReceive('exec')->once()->with('zip -r export.zip . 2>&1')->andReturn("zip warning: name not matched\n");
    $this->sftp->shouldReceive('getExitStatus')->andReturn(12);

    expect($shell->run('zip -r export.zip .', 600))->toBe(['output' => "zip warning: name not matched\n", 'exit' => 12]);
});

test('a command without an exit status does not count as successful', function () {
    $shell = connectedRemoteShell();
    $this->sftp->shouldReceive('setTimeout')->with(0);
    // phpseclib returns false for the output and the exit status when the command times out or the connection drops.
    $this->sftp->shouldReceive('exec')->with('sleep 3600 2>&1')->andReturnFalse();
    $this->sftp->shouldReceive('getExitStatus')->andReturnFalse();

    expect($shell->run('sleep 3600'))->toBe(['output' => '', 'exit' => -1]);
});
