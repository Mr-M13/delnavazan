<?php
namespace Delnavazan\Platform\Core\Infrastructure\Repository;
abstract class BaseRepository {
 protected string $table;
 private ?array $columns = null;
 public function usable(int $id): ?object { global $wpdb; return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE id=%d AND archived_at IS NULL AND status <> 'archived'",$id)); }
 public function find(int $id): ?object { global $wpdb; return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table} WHERE id=%d",$id)); }
 public function recent(int $limit=50): array { global $wpdb; $limit=max(1,min($limit,100)); return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->table} ORDER BY id DESC LIMIT %d",$limit)); }
 public function creationAuditValues(string $now,?int $actor):array{$values=['created_at'=>$now,'updated_at'=>$now,'created_by'=>$actor,'updated_by'=>$actor];return array_filter($values,fn($value,$column)=>$this->hasColumn($column),ARRAY_FILTER_USE_BOTH);}
 public function insert(array $data): int { global $wpdb; if(false===$wpdb->insert($this->table,$data)) throw new PersistenceException((int)$wpdb->last_errno,(string)$wpdb->last_error,'insert'); return (int)$wpdb->insert_id; }
 public function assignReference(int $id,string $reference): void { global $wpdb; if(false===$wpdb->update($this->table,['reference_code'=>$reference],['id'=>$id])) throw new \RuntimeException($wpdb->last_error); }
 public function begin():void{global $wpdb;if(false===$wpdb->query('START TRANSACTION'))throw new \RuntimeException('Transaction start failed');} public function commit():void{global $wpdb;if(false===$wpdb->query('COMMIT'))throw new \RuntimeException('Transaction commit failed');} public function rollback():void{global $wpdb;$wpdb->query('ROLLBACK');}
 public function isUidCollision(\Throwable $e):bool{return $e instanceof PersistenceException&&$e->uidCollision();}
 public function archive(int $id,string $now,?int $actor):void{global $wpdb;$data=['status'=>'archived','archived_at'=>$now,'archived_by'=>$actor];if($this->hasColumn('updated_at'))$data['updated_at']=$now;if($this->hasColumn('updated_by'))$data['updated_by']=$actor;$r=$wpdb->update($this->table,$data,['id'=>$id,'archived_at'=>null]);if(false===$r)throw new \RuntimeException($wpdb->last_error);if($r!==1)throw new \InvalidArgumentException('Record is not archivable');}
 public function restore(int $id,string $status,string $now,?int $actor):void{global $wpdb;$data=['status'=>$status,'archived_at'=>null,'archived_by'=>null];if($this->hasColumn('updated_at'))$data['updated_at']=$now;if($this->hasColumn('updated_by'))$data['updated_by']=$actor;$r=$wpdb->update($this->table,$data,['id'=>$id,'status'=>'archived']);if(false===$r)throw new \RuntimeException($wpdb->last_error);if($r!==1)throw new \InvalidArgumentException('Record is not archived');}
 private function hasColumn(string $column):bool{global $wpdb;if($this->columns===null){$columns=$wpdb->get_col("DESCRIBE {$this->table}");if(!is_array($columns)||$columns===[])throw new \RuntimeException('Unable to inspect persistence schema');$this->columns=array_fill_keys($columns,true);}return isset($this->columns[$column]);}
}
