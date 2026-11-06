<?php
namespace Delnavazan\Platform\Core\Application\PaymentExecution;

/**
 * The single Stripe test/live mode contract shared by configuration, the vault, Checkout, webhook
 * translation, reconciliation and readiness.
 *
 * Live mode is never inferred from a credential prefix. It is a durable, operator-recorded activation
 * that is only written after the live readiness checklist passes. The test path is unchanged and stays
 * independently activatable, so deactivating live restores the existing test behaviour without
 * rewriting any historical payment record.
 */
final class StripeProviderMode {
    public const TEST='test';
    public const LIVE='live';
    public const MODES=array(self::TEST,self::LIVE);

    /** The stable, non-secret webhook selector of each dashboard-managed account. */
    public const TEST_ACCOUNT_REFERENCE='stripe_test';
    public const LIVE_ACCOUNT_REFERENCE='stripe_live';

    public const TEST_PUBLISHABLE_OPTION='dzn_stripe_test_publishable_key';
    public const LIVE_PUBLISHABLE_OPTION='dzn_stripe_live_publishable_key';
    public const LIVE_ACTIVATION_OPTION='dzn_stripe_live_activation';
    public const LIVE_ACTIVATION_HISTORY_OPTION='dzn_stripe_live_activation_history';
    public const LIVE_CONNECTION_OPTION='dzn_stripe_live_connection_result';
    public const LIVE_WEBHOOK_VERIFICATION_OPTION='dzn_stripe_live_webhook_verification';
    public const LIVE_ACCOUNT_IDENTIFIER_OPTION='dzn_stripe_live_account_identifier';

    public const ACTIVATION_INACTIVE='inactive';
    public const ACTIVATION_ACTIVATING='activating';
    public const ACTIVATION_ACTIVE='active';
    public const ACTIVATION_BLOCKED='blocked';

    /** Snapshot-event versions whose field contract has been reviewed with this translator. */
    public const SUPPORTED_WEBHOOK_API_VERSIONS=array('2026-10-28.endive');

    /** Live credentials may only be configured in a production environment. */
    public static function liveEnvironmentAllowed():bool{
        return function_exists('wp_get_environment_type')&&(string)wp_get_environment_type()==='production';
    }

    /**
     * The recorded activation, always a bounded array with a controlled status.
     *
     * @return array{status:string,account_id:int,account_reference:string,activated_at:?string,deactivated_at:?string,actor:int,readiness_digest:?string}
     */
    public static function activation():array{
        $default=array('status'=>self::ACTIVATION_INACTIVE,'account_id'=>0,'account_reference'=>'',
            'activated_at'=>null,'deactivated_at'=>null,'actor'=>0,'readiness_digest'=>null);
        if(!function_exists('get_option'))return $default;
        $raw=get_option(self::LIVE_ACTIVATION_OPTION,array());
        if(!is_array($raw))return $default;
        $status=in_array((string)($raw['status']??''),array(self::ACTIVATION_INACTIVE,self::ACTIVATION_ACTIVATING,self::ACTIVATION_ACTIVE,self::ACTIVATION_BLOCKED),true)
            ?(string)$raw['status']:self::ACTIVATION_INACTIVE;
        return array(
            'status'=>$status,
            'account_id'=>max(0,(int)($raw['account_id']??0)),
            'account_reference'=>trim((string)($raw['account_reference']??'')),
            'activated_at'=>self::nullableString($raw['activated_at']??null),
            'deactivated_at'=>self::nullableString($raw['deactivated_at']??null),
            'actor'=>max(0,(int)($raw['actor']??0)),
            'readiness_digest'=>self::nullableString($raw['readiness_digest']??null),
        );
    }

    /** True while an activation is being recorded, or once it has completed. */
    public static function liveAuthorising():bool{
        $activation=self::activation();
        return in_array($activation['status'],array(self::ACTIVATION_ACTIVATING,self::ACTIVATION_ACTIVE),true)
            &&$activation['account_id']>0
            &&$activation['account_reference']===self::LIVE_ACCOUNT_REFERENCE;
    }

    /** True only once activation has completed. Outbound live charging requires this. */
    public static function liveActive():bool{
        $activation=self::activation();
        return $activation['status']===self::ACTIVATION_ACTIVE
            &&$activation['account_id']>0
            &&$activation['account_reference']===self::LIVE_ACCOUNT_REFERENCE
            &&$activation['activated_at']!==null;
    }

    /** Live secrets are readable in production for verification even before activation. */
    public static function liveSecretReadable():bool{
        return self::liveEnvironmentAllowed();
    }

    public static function apiKeyPattern(string $mode):string{
        return $mode===self::LIVE?'/^sk_live_[A-Za-z0-9]{16,}$/D':'/^sk_test_[A-Za-z0-9]{16,}$/D';
    }
    public static function publishablePattern(string $mode):string{
        return $mode===self::LIVE?'/^pk_live_[A-Za-z0-9]{16,}$/D':'/^pk_test_[A-Za-z0-9]{16,}$/D';
    }
    public static function webhookSecretPattern():string{return '/^whsec_[A-Za-z0-9]{16,}$/D';}
    public static function supportedWebhookApiVersion(string $version):bool{
        return in_array(trim($version),self::SUPPORTED_WEBHOOK_API_VERSIONS,true);
    }
    public static function accountReference(string $mode):string{
        return $mode===self::LIVE?self::LIVE_ACCOUNT_REFERENCE:self::TEST_ACCOUNT_REFERENCE;
    }
    public static function publishableOption(string $mode):string{
        return $mode===self::LIVE?self::LIVE_PUBLISHABLE_OPTION:self::TEST_PUBLISHABLE_OPTION;
    }
    /** Stripe Checkout Session identities are mode-specific: cs_test_… and cs_live_…. */
    public static function checkoutReference(string $providerReference,string $mode):bool{
        $prefix=$mode===self::LIVE?'cs_live_':'cs_test_';
        return preg_match('/^'.preg_quote($prefix,'/').'[A-Za-z0-9]+$/D',$providerReference)===1;
    }
    public static function modeFromCheckoutReference(string $providerReference):?string{
        if(str_starts_with($providerReference,'cs_live_'))return self::LIVE;
        if(str_starts_with($providerReference,'cs_test_'))return self::TEST;
        return null;
    }
    public static function member(string $mode):bool{return in_array($mode,self::MODES,true);}

    private static function nullableString(mixed $value):?string{
        if(!is_string($value))return null;
        $value=trim($value);
        return $value===''?null:$value;
    }
}
