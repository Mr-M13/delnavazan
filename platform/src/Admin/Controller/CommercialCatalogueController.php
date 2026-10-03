<?php
namespace Delnavazan\Platform\Admin\Controller;
use Delnavazan\Platform\Core\Application\{CommercialCatalogueService,CommercialRule};
use Delnavazan\Platform\Core\Infrastructure\Repository\{CommercialAuthorityRepository,CourseRepository};
final class CommercialCatalogueController{
 const CAP='dzn_manage_commercial_catalogue';
 public static function register():void{add_action('admin_menu',[self::class,'menu']);}
 public static function menu():void{$h=add_submenu_page('dzn-platform','Commercial catalogue','Commercial catalogue',self::CAP,'dzn-commercial-catalogue',[self::class,'screen']);if($h)add_action('load-'.$h,[self::class,'post']);}
 public static function post():void{
  if($_SERVER['REQUEST_METHOD']!=='POST')return;if(!current_user_can(self::CAP))wp_die('Forbidden',403);check_admin_referer('dzn_commercial_catalogue');
  try{$op=sanitize_key((string)($_POST['catalogue_operation']??''));$svc=new CommercialCatalogueService();$base=['evidence_channel'=>sanitize_text_field((string)($_POST['evidence_channel']??'')),'evidence_reference'=>sanitize_text_field((string)($_POST['evidence_reference']??'')),'evidence_at'=>sanitize_text_field((string)($_POST['evidence_at']??''))];$key=sanitize_text_field((string)($_POST['operator_key']??''));
   if($op==='create_product')$svc->createProduct($base+['course_id'=>(string)($_POST['course_id']??''),'name_fa'=>sanitize_text_field((string)($_POST['name_fa']??'')),'name_en'=>sanitize_text_field((string)($_POST['name_en']??'')),'status'=>'active'],$key);
   elseif($op==='set_price'){$region=strtoupper(sanitize_text_field((string)($_POST['region_code']??'')));$svc->setPrice($base+['product_id'=>(string)($_POST['product_id']??''),'region_code'=>$region,'currency'=>CommercialRule::regionCurrency($region),'amount_minor'=>(string)($_POST['amount_minor']??'')],$key);}else throw new \InvalidArgumentException('Unknown catalogue operation');
   self::back('saved');
  }catch(\Throwable){self::back('error');}
 }
 public static function screen():void{
  if(!current_user_can(self::CAP))wp_die('Forbidden',403);$repo=new CommercialAuthorityRepository();$products=$repo->products();$courses=(new CourseRepository())->recent(100);$s=sanitize_key((string)($_GET['catalogue_status']??''));
  echo '<div class="wrap"><h1>Commercial catalogue</h1>';if($s==='saved')echo '<div class="notice notice-success"><p>Catalogue command recorded.</p></div>';elseif($s==='error')echo '<div class="notice notice-error"><p>Catalogue command failed. Review the authoritative fields and retry with the same idempotency key only for the same command.</p></div>';
  echo '<h2>Create sellable Course product</h2><form method="post">';wp_nonce_field('dzn_commercial_catalogue');echo '<input type="hidden" name="catalogue_operation" value="create_product"><select name="course_id" required><option value="">Course</option>';foreach($courses as$c)if($c->archived_at===null&&(string)$c->status==='active')echo '<option value="'.(int)$c->id.'">#'.(int)$c->id.' '.esc_html((string)($c->name??$c->title??$c->reference_code??'Course')).'</option>';echo '</select> <input name="name_fa" placeholder="Persian name"> <input name="name_en" placeholder="English name">';self::evidence();echo '<button class="button button-primary">Create product</button></form>';
  echo '<h2>Active catalogue</h2><table class="widefat striped"><thead><tr><th>Product</th><th>Course</th><th>Prices</th><th>Set regional price</th></tr></thead><tbody>';
  foreach($products as$p){$prices=$repo->pricesForProduct((int)$p->id);echo '<tr><td>#'.(int)$p->id.' '.esc_html((string)($p->name_en??$p->name_fa??'')).'</td><td>#'.(int)$p->course_id.'</td><td>';foreach($prices as$price)if((int)($price->active_slot??0)===1)echo esc_html((string)$price->region_code).' '.esc_html((string)$price->currency).' '.(int)$price->amount_minor.'<br>';echo '</td><td><form method="post">';wp_nonce_field('dzn_commercial_catalogue');echo '<input type="hidden" name="catalogue_operation" value="set_price"><input type="hidden" name="product_id" value="'.(int)$p->id.'"><select name="region_code">';foreach(CommercialRule::REGIONS as$r=>$currency)echo '<option value="'.esc_attr($r).'">'.esc_html($r.' / '.$currency).'</option>';echo '</select> <input required inputmode="numeric" name="amount_minor" placeholder="Minor units">';self::evidence();echo '<button class="button">Set price</button></form></td></tr>';}echo '</tbody></table></div>';
 }
 private static function evidence():void{echo ' <select name="evidence_channel"><option value="staff_record">staff_record</option><option value="authenticated_platform">authenticated_platform</option><option value="document_reference">document_reference</option></select> <input required name="evidence_reference" placeholder="Evidence reference"> <input required name="evidence_at" placeholder="UTC YYYY-MM-DD HH:MM:SS"> <input required minlength="24" name="operator_key" placeholder="Idempotency key"> ';}
 private static function back(string $s):never{wp_safe_redirect(add_query_arg(['page'=>'dzn-commercial-catalogue','catalogue_status'=>$s],admin_url('admin.php')));exit;}
}
