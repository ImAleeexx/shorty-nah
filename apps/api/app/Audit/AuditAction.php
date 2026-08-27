<?php

declare(strict_types=1);

namespace App\Audit;

/**
 * The events worth keeping a record of.
 *
 * An enumeration rather than free strings, so a filter in the interface is
 * exhaustive by construction and a typo cannot invent a category nobody can
 * search for.
 */
enum AuditAction: string
{
    case SignInSucceeded = 'auth.sign_in.succeeded';
    case SignInFailed = 'auth.sign_in.failed';
    case SignedOut = 'auth.signed_out';
    case PasswordChanged = 'auth.password.changed';

    case RoleChanged = 'user.role.changed';
    case UserRemoved = 'user.removed';

    case InvitationIssued = 'invitation.issued';
    case InvitationRedeemed = 'invitation.redeemed';
    case InvitationRevoked = 'invitation.revoked';

    case TokenCreated = 'token.created';
    case TokenRevoked = 'token.revoked';

    case TwoFactorEnrolled = 'two_factor.enrolled';
    case TwoFactorRemoved = 'two_factor.removed';

    case DomainAdded = 'domain.added';
    case DomainRemoved = 'domain.removed';

    case SettingsChanged = 'settings.changed';
    case BrandingChanged = 'branding.changed';

    case LinkPasswordChanged = 'link.password.changed';

    // A routing rule decides where traffic goes without changing the link's own
    // destination, so a link can be repointed without its destination column ever
    // differing. That is precisely what an audit log is for.
    case LinkRulesChanged = 'link.rules.changed';

    case AnalyticsExported = 'analytics.exported';

    case InstallationCompleted = 'instance.installed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
