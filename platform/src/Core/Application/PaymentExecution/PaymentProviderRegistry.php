<?php
namespace Delnavazan\Platform\Core\Application\PaymentExecution;

/**
 * Controlled `provider_key` to adapter resolution (contract §5.3, T-D1).
 *
 * The registry resolves a provider by `provider_key` through `PROVIDERS` only; an unregistered key
 * fails closed with `unsupported_payment_provider` and never falls back to a default adapter. A test or
 * a local rehearsal may register a network-free fake adapter for an existing vocabulary member.
 */
final class PaymentProviderRegistry {
    /** @var array<string,PaymentExecutionPort> */
    private static array $executionPorts=array();
    /** @var array<string,ProviderEventTranslator> */
    private static array $translators=array();

    public static function registerExecutionPort(PaymentExecutionPort $port):void{
        $key=$port->key();
        if(PaymentExecutionRule::provider($key)===null)throw new \InvalidArgumentException('unsupported_payment_provider');
        self::$executionPorts[$key]=$port;
    }
    public static function registerTranslator(ProviderEventTranslator $translator):void{
        $key=$translator->key();
        if(PaymentExecutionRule::provider($key)===null)throw new \InvalidArgumentException('unsupported_payment_provider');
        self::$translators[$key]=$translator;
    }
    public static function executionPort(string $providerKey):PaymentExecutionPort{
        $key=PaymentExecutionRule::provider($providerKey);
        if($key===null||!isset(self::$executionPorts[$key]))throw new \InvalidArgumentException('unsupported_payment_provider');
        return self::$executionPorts[$key];
    }
    public static function translator(string $providerKey):ProviderEventTranslator{
        $key=PaymentExecutionRule::provider($providerKey);
        if($key===null||!isset(self::$translators[$key]))throw new \InvalidArgumentException('unsupported_payment_provider');
        return self::$translators[$key];
    }
    public static function hasExecutionPort(string $providerKey):bool{
        $key=PaymentExecutionRule::provider($providerKey);
        return $key!==null&&isset(self::$executionPorts[$key]);
    }
}
