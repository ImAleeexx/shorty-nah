<?php

declare(strict_types=1);

namespace App\Clicks;

use DeviceDetector\DeviceDetector;
use DeviceDetector\Parser\Device\AbstractDeviceParser;
use Throwable;

/**
 * Device, operating system, browser and whether the client is automated.
 *
 * Backed by a maintained detection library rather than a hand-written regex list.
 * Bot signatures change weekly; a list I maintain myself would be stale within a
 * month, and stale bot detection silently inflates every figure.
 */
final class UserAgentParser
{
    /**
     * Client types a person could plausibly be behind. Everything else — a
     * library, a feed reader, a mail client, a media player — is a machine.
     *
     * @var list<string>
     */
    private const HUMAN_CLIENT_TYPES = ['browser', 'mobile app'];

    public function __construct()
    {
        // Full version strings are not stored, so there is nothing to gain from
        // parsing them and it is measurably slower.
        AbstractDeviceParser::setVersionTruncation(AbstractDeviceParser::VERSION_TRUNCATION_MAJOR);
    }

    public function parse(?string $userAgent): ClientProfile
    {
        if ($userAgent === null || trim($userAgent) === '') {
            // No user agent at all is itself a strong automation signal: every
            // real browser sends one.
            return new ClientProfile(isBot: true, botName: 'missing-user-agent');
        }

        try {
            $detector = new DeviceDetector($userAgent);
            $detector->parse();
        } catch (Throwable) {
            return new ClientProfile;
        }

        if ($detector->isBot()) {
            $bot = $detector->getBot();

            return new ClientProfile(
                isBot: true,
                botName: is_array($bot) && is_string($bot['name'] ?? null) ? $bot['name'] : 'unknown-bot',
            );
        }

        $client = $detector->getClient();
        $os = $detector->getOs();

        $type = is_array($client) && is_string($client['type'] ?? null) ? $client['type'] : '';
        $name = is_array($client) && is_string($client['name'] ?? null) ? $client['name'] : '';

        // Bot detection alone is not enough. curl, wget and the HTTP libraries
        // are classified as `library` rather than `bot`, and a mail client
        // fetching a link is a preview rather than a person. Only a browser or an
        // app is treated as somebody arriving.
        if ($type !== '' && ! in_array($type, self::HUMAN_CLIENT_TYPES, true)) {
            return new ClientProfile(
                isBot: true,
                botName: $type.($name === '' ? '' : ':'.$name),
            );
        }

        return new ClientProfile(
            deviceType: (string) ($detector->getDeviceName() ?: ''),
            operatingSystem: is_array($os) && is_string($os['name'] ?? null) ? $os['name'] : '',
            browser: $name,
        );
    }
}
