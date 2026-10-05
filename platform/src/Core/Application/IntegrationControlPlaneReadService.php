<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Application\NotificationTransportPort;
use Delnavazan\Platform\Integrations\GoogleOAuthController;
use Delnavazan\Platform\Integrations\Notifications\NotificationRuntimeFactory;

/** Secret-free, read-only status projection for the operator integrations control plane. */
final class IntegrationControlPlaneReadService {
    public const READ_CAPABILITY='dzn_view_diagnostics';

    /** @return array<string,mixed> */
    public function report():array{
        if(!current_user_can(self::READ_CAPABILITY))throw new \RuntimeException('Unauthorized');
        $bindings=apply_filters('dzn_notification_transport_bindings',array());
        $configuredChannels=array();
        if(is_array($bindings))foreach($bindings as $binding){
            if(!is_array($binding)||empty($binding['configured'])||!isset($binding['channel'],$binding['port']))continue;
            $port=$binding['port'];$channel=(string)$binding['channel'];
            $valid=in_array($channel,array('email','whatsapp','sms'),true)&&$port instanceof NotificationTransportPort;
            if($valid)$configuredChannels[$channel]=true;
        }
        $copy=apply_filters('dzn_notification_copy_definitions',array());
        $notificationRuntime=NotificationRuntimeFactory::configured();
        $googleConfigured=GoogleOAuthController::configured();
        $invitationTransportConfigured=(bool)apply_filters('dzn_teacher_invitation_email_transport_configured',false);

        global $wpdb;
        // Platform tables live beneath the dzn_ namespace. Omitting it makes
        // WordPress query a non-existent wp_platform_outbox table, which turns
        // this read-only diagnostics screen into a blank fatal page.
        $outboxTable=$wpdb->prefix.'dzn_platform_outbox';
        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT status,COUNT(*) AS total FROM {$outboxTable} WHERE event_type=%s GROUP BY status",
            'teacher_invitation.delivery_requested'
        ));
        if(!is_array($rows))throw new \RuntimeException('integration_control_plane_read_unavailable');
        $invitationDelivery=array('pending'=>0,'prepared'=>0,'delivered'=>0,'failed'=>0);
        foreach((array)$rows as $row){$state=(string)$row->status;if(array_key_exists($state,$invitationDelivery))$invitationDelivery[$state]=(int)$row->total;}

        $notificationStates=$this->countStates($wpdb->prefix.'dzn_notifications','state');
        $deliveryStates=$this->countStates($wpdb->prefix.'dzn_notification_deliveries','delivery_state');
        return array(
            'email'=>array(
                'invitation_transport'=>function_exists('wp_mail')&&$invitationTransportConfigured?'configured_unverified':'not_configured',
                'invitation_delivery_verified'=>false,
                'notification_binding'=>isset($configuredChannels['email'])?'configured':'not_configured',
                'invitation_outbox'=>$invitationDelivery,
            ),
            'notifications'=>array(
                'runtime'=>$notificationRuntime?'configured':'not_configured',
                'states'=>$notificationStates,
                'delivery_states'=>$deliveryStates,
                'copy_definitions'=>is_array($copy)&&$copy!==array()?'configured':'not_configured',
                'channels'=>array(
                    'email'=>isset($configuredChannels['email'])?'configured':'not_configured',
                    'whatsapp'=>isset($configuredChannels['whatsapp'])?'configured':'not_configured',
                    'sms'=>isset($configuredChannels['sms'])?'configured':'not_configured',
                ),
            ),
            'google_calendar'=>array(
                'capability'=>'teacher Google Calendar event projection and Google Meet join links',
                'oauth_client'=> $googleConfigured?'configured':'not_configured',
                'configuration_source'=>'DZN_GOOGLE_OAUTH_CLIENT_ID and DZN_GOOGLE_OAUTH_CLIENT_SECRET in deployment configuration',
                'test_connection'=>'not_run',
            ),
            'stripe'=>array(
                'status'=>'see Payment Execution readiness',
                'production_activation'=>'disabled by this control plane',
            ),
            'generated_at'=>current_time('mysql',true),
        );
    }
    /** Safe aggregate only: no recipients, content, provider reference, or credential values. */
    private function countStates(string $table,string $column):array{global $wpdb;$rows=$wpdb->get_results("SELECT {$column} AS state,COUNT(*) AS total FROM {$table} GROUP BY {$column}");if(!is_array($rows))throw new \RuntimeException('integration_control_plane_read_unavailable');$out=array();foreach($rows as $row)$out[(string)$row->state]=(int)$row->total;return $out;}
}
