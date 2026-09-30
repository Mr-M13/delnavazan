<?php
namespace Delnavazan\Platform\Portals;

final class PortalRateLimiter {
    /** The owner supplies the budget; absent/invalid budget and cache failures fail open. */
    public function allow(string $surface,string $fingerprint):bool {
        try {$budget=apply_filters('dzn_portal_rate_limit_budget',null,$surface);if(!is_array($budget)||!isset($budget['limit'],$budget['window'])||(int)$budget['limit']<1||(int)$budget['window']<1)return true;$key='dzn_portal_rate_'.hash('sha256',$surface.'|'.$fingerprint);$state=get_transient($key);$count=is_array($state)&&(isset($state['count']))?(int)$state['count']:0;if($count>=(int)$budget['limit'])return false;if(!set_transient($key,array('count'=>$count+1),(int)$budget['window']))return true;return true;}catch(\Throwable){return true;}
    }
}
