<?php

declare(strict_types=1);

namespace App\Setup;

use App\Settings\SettingsRegistry;
use App\Settings\SettingsStore;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

/**
 * The proof-of-host-access credential that guards the setup flow.
 *
 * An instance is reachable the moment DNS resolves and its certificate reaches
 * the transparency logs, so the wizard cannot be allowed to hand ownership to
 * whoever arrives first. Only the digest is stored: the plaintext exists in the
 * container log and in the host-mounted file, which is what makes it
 * recoverable from the host without database access.
 */
final class SetupToken
{
    public function __construct(
        private readonly SettingsStore $settings,
        private readonly Filesystem $files,
        private readonly string $path,
    ) {}

    public function issued(): bool
    {
        return $this->settings->string(SettingsRegistry::SETUP_TOKEN_HASH) !== null;
    }

    /**
     * Mint the token unless one is already outstanding. Returns the plaintext
     * only when it generates one; a restart before installation must keep the
     * token the operator already has rather than mint a second.
     */
    public function ensure(): ?string
    {
        if ($this->settings->installed() || $this->issued()) {
            return null;
        }

        $token = Str::random(48);

        $this->settings->set(SettingsRegistry::SETUP_TOKEN_HASH, $this->digest($token));
        $this->write($token);

        return $token;
    }

    public function verify(string $candidate): bool
    {
        $stored = $this->settings->string(SettingsRegistry::SETUP_TOKEN_HASH);

        if ($stored === null || $this->settings->installed()) {
            return false;
        }

        return hash_equals($stored, $this->digest($candidate));
    }

    /**
     * Installation is complete: the credential stops existing rather than merely
     * stopping being accepted.
     */
    public function invalidate(): void
    {
        $this->settings->forget(SettingsRegistry::SETUP_TOKEN_HASH);

        if ($this->files->exists($this->path)) {
            $this->files->delete($this->path);
        }
    }

    public function path(): string
    {
        return $this->path;
    }

    private function write(string $token): void
    {
        $this->files->ensureDirectoryExists(dirname($this->path));
        $this->files->put($this->path, $token.PHP_EOL);
        $this->files->chmod($this->path, 0600);
    }

    private function digest(string $token): string
    {
        return hash('sha256', $token);
    }
}
