<?php
namespace Delnavazan\Platform\Integrations;

use Delnavazan\Platform\Core\Application\IntegrationSettingsService;

/** Applies operator email settings to WordPress mail. Host mail remains the default transport. */
final class WordPressMailSettings {
    public static function register():void{add_action('phpmailer_init',array(__CLASS__,'configure'));add_filter('wp_mail_from',array(__CLASS__,'fromAddress'));add_filter('wp_mail_from_name',array(__CLASS__,'fromName'));add_filter('dzn_teacher_invitation_email_transport_configured',array(__CLASS__,'transportConfigured'));}
    /** Invitation delivery uses the same configured WordPress SMTP path as platform mail. */
    public static function transportConfigured(bool $configured):bool{if($configured)return true;$s=new IntegrationSettingsService();return $s->value('email','mode')==='smtp'&&$s->ready('email');}
    public static function fromAddress(string $current):string{$value=(new IntegrationSettingsService())->value('email','from_email');return is_email($value)?$value:$current;}
    public static function fromName(string $current):string{$value=(new IntegrationSettingsService())->value('email','from_name');return $value!==''?$value:$current;}
    public static function configure(object $mailer):void{
        $s=new IntegrationSettingsService();if($s->value('email','mode')!=='smtp'||!$s->ready('email'))return;
        $mailer->isSMTP();$mailer->Host=$s->value('email','host');$mailer->Port=max(1,(int)$s->value('email','port'));
        $mailer->SMTPAuth=true;$mailer->Username=$s->value('email','username');$mailer->Password=$s->value('email','password');
        $encryption=$s->value('email','encryption');$mailer->SMTPSecure=$encryption==='none'?'':$encryption;
        $mailer->SMTPAutoTLS=$encryption==='tls';
    }
}
