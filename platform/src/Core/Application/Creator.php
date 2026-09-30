<?php
namespace Delnavazan\Platform\Core\Application;
use Delnavazan\Platform\Core\Infrastructure\Repository\BaseRepository; use Delnavazan\Platform\Core\Support\Identifier;
final class Creator {
 public static function create(BaseRepository $repo,array $data,string $prefix):int{for($i=0;$i<3;$i++){ $repo->begin();try{$data['uid']=Identifier::uid();$data['reference_code']=null;$data=array_replace($data,$repo->creationAuditValues(gmdate('Y-m-d H:i:s'),get_current_user_id()?:null));$id=$repo->insert($data);$repo->assignReference($id,Identifier::reference($prefix,$id));$repo->commit();return $id;}catch(\Throwable $e){$repo->rollback();if(!$repo->isUidCollision($e))throw $e;}}throw new \RuntimeException('UID collision retry limit reached');}
}
