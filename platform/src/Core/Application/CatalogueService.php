<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\{CommercialAuthorityRepository,CourseRepository,InstrumentRepository};

final class CatalogueService {
    public function instrument(array $d,bool $withinTransaction=false):int{if(!current_user_can('dzn_manage_courses'))throw new \RuntimeException('Unauthorized');$d['slug']=sanitize_title($d['slug']??'');$d['name_fa']=Normalizer::text($d['name_fa']??null);$d['name_en']=Normalizer::text($d['name_en']??null);if(!$d['slug']||(!$d['name_fa']&&!$d['name_en']))throw new \InvalidArgumentException('Slug and Instrument name required');$d['status']=Normalizer::one($d['status']??'active',['active','inactive'],'instrument state');return Creator::create(new InstrumentRepository,$d,'DZN-INS-',$withinTransaction);}
    public function course(array $d,bool $withinTransaction=false):int{if(!current_user_can('dzn_manage_courses'))throw new \RuntimeException('Unauthorized');$d['instrument_id']=Normalizer::id($d['instrument_id']??null);if(!(new InstrumentRepository)->usable($d['instrument_id']))throw new \InvalidArgumentException('Usable Instrument required');$d['name_fa']=Normalizer::text($d['name_fa']??'',191,true);$d['name_en']=Normalizer::text($d['name_en']??'',191,true);$d['course_type']=Normalizer::one($d['course_type']??'',['standard','introductory'],'course type');$d['status']=Normalizer::one($d['status']??'active',['active','inactive'],'course state');$d['default_duration_minutes']=Normalizer::count($d['default_duration_minutes']??0,5,480);$d['default_buffer_minutes']=Normalizer::count($d['default_buffer_minutes']??0,0,240);return Creator::create(new CourseRepository,$d,'DZN-CRS-',$withinTransaction);}

    /** @return array{instrument_id:int,introductory_course_id:int,standard_course_id:int,product_id:int} */
    public function setupInstrument(array $input):array{
        $this->requireSetupCapabilities();
        $name=Normalizer::text($input['name']??'',191,true);$duration=Normalizer::count($input['standard_duration_minutes']??0,5,480);$buffer=Normalizer::count($input['gap_between_lessons_minutes']??0,0,240);
        $billing=Normalizer::one($input['introductory_lesson_billing']??'free',['free','paid'],'introductory lesson billing');
        // The public booking and continuation authorities only support a free introductory lesson.
        if($billing!=='free')throw new \InvalidArgumentException('paid_introductory_lesson_unsupported');
        $slug=sanitize_title($name);if($slug==='')$slug='instrument-'.substr(hash('sha256',$name),0,16);
        $lock=$this->acquireSetupLock($slug);$instruments=new InstrumentRepository();
        $transaction=false;
        try{
            $instruments->begin();$transaction=true;
            $instrument=$instruments->bySlug($slug);
            if(!$instrument){$instrumentId=$this->instrument(array('slug'=>$slug,'name_fa'=>$name,'name_en'=>$name,'status'=>'active'),true);$instrument=$instruments->find($instrumentId);}
            if(!$instrument||$instrument->archived_at!==null||(string)$instrument->status!=='active')throw new \InvalidArgumentException('instrument_setup_inactive_instrument');
            if((string)$instrument->name_fa!==$name&&(string)$instrument->name_en!==$name)throw new \InvalidArgumentException('instrument_setup_name_conflict');
            $instrumentId=(int)$instrument->id;$courses=new CourseRepository();
            // A normal setup supplies one lesson shape. Introductory booking occupancy must use
            // that same duration and gap; specialised courses remain explicit catalogue work.
            $intro=$this->reconcileCourse($courses,$instrumentId,'introductory',$name,$duration,$buffer);$standard=$this->reconcileCourse($courses,$instrumentId,'standard',$name,$duration,$buffer);
            $commercial=new CommercialAuthorityRepository();$products=array_values(array_filter($commercial->productsForCourse((int)$standard->id),static fn(object $product):bool=>$product->archived_at===null&&(string)$product->status==='active'));
            if(count($products)>1)throw new \InvalidArgumentException('instrument_setup_ambiguous_standard_product');
            if(!$products){$created=(new CommercialCatalogueService($commercial))->createProduct(array('course_id'=>(int)$standard->id,'name_fa'=>$name,'name_en'=>$name,'status'=>'active','evidence_channel'=>'authenticated_platform','evidence_reference'=>'instrument-setup:'.$instrumentId.':standard-product','evidence_at'=>gmdate('Y-m-d H:i:s')),'instrument-setup:'.$instrumentId.':standard-product',true);$productId=(int)$created['product_id'];}else $productId=(int)$products[0]->id;
            $defaults=new InstrumentIntroCourseDefaultService();$default=$defaults->resolve($instrumentId);if($default&&(int)$default->course_id!==(int)$intro->id)throw new \InvalidArgumentException('instrument_setup_introductory_default_conflict');if(!$default)$defaults->set(array('instrument_id'=>$instrumentId,'course_id'=>(int)$intro->id,'status'=>'active','reason_code'=>'instrument_setup'),true);
            $instruments->commit();$transaction=false;return array('instrument_id'=>$instrumentId,'introductory_course_id'=>(int)$intro->id,'standard_course_id'=>(int)$standard->id,'product_id'=>$productId);
        }catch(\Throwable $error){if($transaction)$instruments->rollback();throw $error;}finally{$this->releaseSetupLock($lock);}
    }
    private function reconcileCourse(CourseRepository $courses,int $instrumentId,string $type,string $name,int $duration,int $buffer):object{$existing=$courses->activeForInstrumentAndType($instrumentId,$type);if(count($existing)>1)throw new \InvalidArgumentException('instrument_setup_ambiguous_'.$type.'_course');if(!$existing){$id=$this->course(array('instrument_id'=>$instrumentId,'name_fa'=>$name,'name_en'=>$name,'course_type'=>$type,'status'=>'active','default_duration_minutes'=>$duration,'default_buffer_minutes'=>$buffer),true);$course=$courses->find($id);if(!$course)throw new \RuntimeException('instrument_setup_course_persistence_failed');return $course;}$course=$existing[0];if((int)$course->default_duration_minutes!==$duration||(int)$course->default_buffer_minutes!==$buffer)throw new \InvalidArgumentException('instrument_setup_'.$type.'_course_configuration_conflict');return $course;}
    private function requireSetupCapabilities():void{foreach(array('dzn_manage_courses','dzn_manage_teaching_eligibility','dzn_manage_commercial_catalogue') as $capability)if(!current_user_can($capability))throw new \RuntimeException('Unauthorized');}
    private function acquireSetupLock(string $slug):string{global $wpdb;$lock='dzn_instrument_setup_'.hash('sha256',$slug);if((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)',$lock))!==1)throw new \RuntimeException('instrument_setup_busy');return $lock;}
    private function releaseSetupLock(string $lock):void{global $wpdb;$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));}
}
