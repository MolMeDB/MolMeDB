<?php

namespace App\Services;

use App\Models\Filesystem;
use App\Models\SshCredential;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;
use RuntimeException;

/**
 * Shell and SFTP access to the server behind a (possibly scoped) sftp Filesystem,
 * using the same host and SshCredential as its Laravel disk.
 */
class RemoteShell
{
    private function __construct(
        private readonly SFTP $connection,
        private readonly Filesystem $server,
    ) {}

    public static function forFilesystem(Filesystem $filesystem): self
    {
        $server = self::serverFilesystem($filesystem);
        $credential = $server->sshCredential;

        if (! $credential) {
            throw new RuntimeException("Filesystem [{$server->name}] has no SSH credential.");
        }

        $connection = new SFTP($server->host, (int) ($server->port ?: 22));
        $secret = $credential->type === SshCredential::AUTH_TYPE_KEY
            ? PublicKeyLoader::load($credential->private_key, $credential->passphrase ?: false)
            : $credential->password;

        if (! $connection->login($credential->username, $secret)) {
            throw new RuntimeException("SSH login to [{$server->host}] failed.");
        }

        return new self($connection, $server);
    }

    /**
     * Absolute path of the filesystem root on the server: the server root followed by the scope prefixes.
     */
    public static function absoluteRoot(Filesystem $filesystem): string
    {
        $prefixes = [];

        while ($filesystem->scope) {
            array_unshift($prefixes, trim((string) $filesystem->root_path, '/'));
            $filesystem = $filesystem->scope;
        }

        return rtrim((string) $filesystem->root_path, '/').($prefixes ? '/'.implode('/', array_filter($prefixes)) : '');
    }

    public function isSameServer(Filesystem $filesystem): bool
    {
        return self::serverFilesystem($filesystem)->is($this->server);
    }

    public function put(string $remotePath, string $contents): void
    {
        $this->connection->mkdir(dirname($remotePath), -1, true);

        if (! $this->connection->put($remotePath, $contents)) {
            throw new RuntimeException("Unable to upload [{$remotePath}].");
        }
    }

    public function putFile(string $remotePath, string $localPath): void
    {
        $this->connection->mkdir(dirname($remotePath), -1, true);

        if (! $this->connection->put($remotePath, $localPath, SFTP::SOURCE_LOCAL_FILE)) {
            throw new RuntimeException("Unable to upload [{$localPath}] to [{$remotePath}].");
        }
    }

    public function get(string $remotePath): ?string
    {
        $contents = $this->connection->get($remotePath);

        return is_string($contents) ? $contents : null;
    }

    /**
     * @return array<int, string> file names in the directory, empty when it does not exist
     */
    public function list(string $remoteDirectory): array
    {
        $names = $this->connection->nlist($remoteDirectory);

        return is_array($names) ? array_values(array_diff($names, ['.', '..'])) : [];
    }

    /**
     * Run a command, stderr is merged into the output.
     *
     * @return array{output: string, exit: int}
     */
    public function run(string $command, int $timeoutSeconds = 0): array
    {
        $this->connection->setTimeout($timeoutSeconds);
        $output = $this->connection->exec($command.' 2>&1');

        return [
            'output' => is_string($output) ? $output : '',
            'exit' => (int) ($this->connection->getExitStatus() ?? -1),
        ];
    }

    private static function serverFilesystem(Filesystem $filesystem): Filesystem
    {
        while ($filesystem->scope) {
            $filesystem = $filesystem->scope;
        }

        if ($filesystem->driver !== Filesystem::DRIVER_SFTP) {
            throw new RuntimeException("Filesystem [{$filesystem->name}] is not an sftp filesystem.");
        }

        return $filesystem;
    }
}
