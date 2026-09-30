<?php
namespace Delnavazan\Platform\Core\Application;

use Delnavazan\Platform\Core\Infrastructure\Repository\{CommercialAuthorityRepository,CourseRepository};
use Delnavazan\Platform\Core\Support\Identifier;

/**
 * Sellable product and region/currency price authority.
 *
 * The product is the commercial package for one standard Academy Course. It deliberately does not
 * restate the session count: the canonical Term session allocation stays with the Term authority,
 * so no second source of "12" can drift. Prices are data keyed by product and region; the region →
 * currency mapping is structural and there is no currency conversion anywhere.
 */
final class CommercialCatalogueService {
    private const CAPABILITY='dzn_manage_commercial_catalogue';
    public function __construct(private ?CommercialAuthorityRepository $repository=null){$this->repository??=new CommercialAuthorityRepository();}

    public function createProduct(array $input,string $key):array{
        CommercialSupport::requireCapability(self::CAPABILITY);
        $actor=CommercialSupport::actor();
        $courseId=CommercialSupport::positiveInt($input['course_id']??null,'Usable standard Course required');
        $evidence=CommercialSupport::evidence($input);
        $status=(string)($input['status']??'active');
        if(!in_array($status,array('active','inactive'),true))throw new \InvalidArgumentException('Controlled product state required');
        $digest=CommercialSupport::keyString($key);
        $payload=CommercialIdempotency::payload(array('course_id'=>$courseId,'status'=>$status,'name_fa'=>(string)($input['name_fa']??''),'name_en'=>(string)($input['name_en']??''),'evidence_reference_digest'=>$evidence['digest']));
        $this->repository->begin();
        try{
            if($winner=$this->repository->command($digest)){$result=$this->replayProduct($winner,$payload);$this->repository->commit();return $result;}
            $course=$this->standardCourse($courseId);
            foreach($this->repository->productsForCourse($courseId) as $existing){
                if((string)$existing->status==='active'&&$existing->archived_at===null)throw new \InvalidArgumentException('commercial_product_exists');
            }
            $now=CommercialSupport::now();
            $id=$this->repository->insertProduct(array(
                'uid'=>Identifier::uid(),'reference_code'=>null,'course_id'=>$courseId,
                'name_fa'=>self::optionalText($input['name_fa']??null),'name_en'=>self::optionalText($input['name_en']??null),
                'status'=>$status,'created_at'=>$now,'updated_at'=>$now,'created_by'=>$actor,'updated_by'=>$actor,
                'archived_at'=>null,'archived_by'=>null,
            ));
            $this->repository->insertCommand(array(
                'uid'=>Identifier::uid(),'command_domain'=>CommercialRule::DOMAIN,'operation'=>'create_product',
                'command_key_digest'=>$digest,'command_payload_digest'=>$payload,'result_state'=>'active','result_id'=>$id,
                'created_at'=>$now,'created_by'=>$actor,
            ));
            $this->repository->commit();
            return array('product_id'=>$id,'course_id'=>(int)$course->id,'status'=>$status,'created'=>true);
        }catch(\Throwable$e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replayProduct($winner,$payload);
            throw $e;
        }
    }

    /**
     * Set the authoritative region price for a product.
     *
     * A new amount supersedes the previous active price for that product/region; the superseded row
     * stays as immutable history, so a past offer can always be explained by the price row it used.
     */
    public function setPrice(array $input,string $key):array{
        CommercialSupport::requireCapability(self::CAPABILITY);
        $actor=CommercialSupport::actor();
        $productId=CommercialSupport::positiveInt($input['product_id']??null,'Commercial product required');
        $region=CommercialSupport::region((string)($input['region_code']??''));
        $amount=CommercialSupport::amount($input['amount_minor']??null,'Exact minor-unit price required');
        if($amount<1)throw new \InvalidArgumentException('Exact minor-unit price required');
        $currency=(string)($input['currency']??$region['currency']);
        if(CommercialRule::currency($currency)!==$region['currency'])throw new \InvalidArgumentException('Price currency must match the authorised region');
        $effectiveFrom=($input['effective_from']??null)===null||$input['effective_from']===''?null:CommercialSupport::utc($input['effective_from'],'Valid UTC effective time required');
        $evidence=CommercialSupport::evidence($input);
        $digest=CommercialSupport::keyString($key);
        $payload=CommercialIdempotency::payload(array('product_id'=>$productId,'region_code'=>$region['region_code'],'currency'=>$currency,'amount_minor'=>$amount,'effective_from'=>$effectiveFrom,'evidence_reference_digest'=>$evidence['digest']));
        $this->repository->begin();
        try{
            if($winner=$this->repository->command($digest)){$result=$this->replayPrice($winner,$payload);$this->repository->commit();return $result;}
            $product=$this->repository->product($productId,true);
            $this->assertProductUsable($product);
            $existing=$this->repository->priceForRegion($productId,$region['region_code'],true);
            $now=CommercialSupport::now();
            if($existing&&(int)$existing->amount_minor===$amount&&(string)$existing->currency===$currency){
                $this->repository->insertCommand(array(
                    'uid'=>Identifier::uid(),'command_domain'=>CommercialRule::DOMAIN,'operation'=>'set_price',
                    'command_key_digest'=>$digest,'command_payload_digest'=>$payload,'result_state'=>'active','result_id'=>(int)$existing->id,
                    'created_at'=>$now,'created_by'=>$actor,
                ));
                $this->repository->commit();
                return array('price_id'=>(int)$existing->id,'product_id'=>$productId,'region_code'=>$region['region_code'],'currency'=>$currency,'amount_minor'=>$amount,'created'=>false,'idempotent'=>true);
            }
            if($existing)$this->repository->setActivePriceSlot((int)$existing->id,null,array('updated_at'=>$now,'updated_by'=>$actor));
            $id=$this->repository->insertPrice(array(
                'uid'=>Identifier::uid(),'reference_code'=>null,'product_id'=>$productId,'region_code'=>$region['region_code'],
                'currency'=>$currency,'amount_minor'=>$amount,'status'=>'active','active_slot'=>1,
                'effective_from'=>$effectiveFrom,'effective_until'=>null,'created_at'=>$now,'updated_at'=>$now,
                'created_by'=>$actor,'updated_by'=>$actor,'archived_at'=>null,'archived_by'=>null,
            ));
            $this->repository->insertCommand(array(
                'uid'=>Identifier::uid(),'command_domain'=>CommercialRule::DOMAIN,'operation'=>'set_price',
                'command_key_digest'=>$digest,'command_payload_digest'=>$payload,'result_state'=>'active','result_id'=>$id,
                'created_at'=>$now,'created_by'=>$actor,
            ));
            $this->repository->commit();
            return array('price_id'=>$id,'product_id'=>$productId,'region_code'=>$region['region_code'],'currency'=>$currency,'amount_minor'=>$amount,'created'=>true);
        }catch(\Throwable$e){
            $this->repository->rollback();
            if($this->repository->duplicate($e)==='command_key_digest'&&($winner=$this->repository->command($digest)))return $this->replayPrice($winner,$payload);
            throw $e;
        }
    }

    public function product(int $productId):array{
        $product=$this->repository->product($productId);
        if(!$product)throw new \InvalidArgumentException('Commercial product required');
        return $this->productView($product);
    }
    public function priceForRegion(int $productId,string $regionCode):array{
        $region=CommercialSupport::region($regionCode);
        $price=$this->repository->priceForRegion($productId,$region['region_code']);
        if(!$price)throw new \InvalidArgumentException('commercial_price_required');
        return $this->priceView($price);
    }
    /** Authoritative price resolution with its exact immutable row identity. */
    public function resolvePrice(int $productId,string $regionCode,bool $lock=false):object{
        $region=CommercialSupport::region($regionCode);
        $price=$this->repository->priceForRegion($productId,$region['region_code'],$lock);
        if(!$price||(int)$price->amount_minor<1)throw new \InvalidArgumentException('commercial_price_required');
        if((string)$price->currency!==$region['currency'])throw new \InvalidArgumentException('commercial_price_currency_conflict');
        if(!CommercialValidator::utc((string)$price->created_at))throw new \InvalidArgumentException('commercial_price_integrity_conflict');
        return $price;
    }
    private function productView(object $product):array{
        return array('product_id'=>(int)$product->id,'course_id'=>(int)$product->course_id,'status'=>(string)$product->status,'archived_at'=>$product->archived_at===null?null:(string)$product->archived_at);
    }
    private function priceView(object $price):array{
        return array('price_id'=>(int)$price->id,'product_id'=>(int)$price->product_id,'region_code'=>(string)$price->region_code,'currency'=>(string)$price->currency,'amount_minor'=>(int)$price->amount_minor);
    }
    private function replayProduct(object $command,string $payload):array{
        if(!hash_equals((string)$command->command_payload_digest,$payload))throw new \RuntimeException('Idempotency conflict');
        if((string)$command->operation!=='create_product')throw new \RuntimeException('Contaminated commercial product command');
        $product=$this->repository->product((int)$command->result_id);
        if(!$product)throw new \RuntimeException('Contaminated commercial product result');
        return array('product_id'=>(int)$product->id,'course_id'=>(int)$product->course_id,'status'=>(string)$product->status,'created'=>false,'idempotent'=>true);
    }
    private function replayPrice(object $command,string $payload):array{
        if(!hash_equals((string)$command->command_payload_digest,$payload))throw new \RuntimeException('Idempotency conflict');
        if((string)$command->operation!=='set_price')throw new \RuntimeException('Contaminated commercial price command');
        $price=$this->repository->price((int)$command->result_id);
        if(!$price)throw new \RuntimeException('Contaminated commercial price result');
        return array('price_id'=>(int)$price->id,'product_id'=>(int)$price->product_id,'region_code'=>(string)$price->region_code,'currency'=>(string)$price->currency,'amount_minor'=>(int)$price->amount_minor,'created'=>false,'idempotent'=>true);
    }
    private function assertProductUsable(?object $product):void{
        if(!$product||$product->archived_at!==null||(string)$product->status!=='active')throw new \InvalidArgumentException('commercial_product_required');
    }
    private function standardCourse(int $courseId):object{
        $course=(new CourseRepository())->find($courseId);
        // The sellable package references the Academy Course that the canonical Enrolment is bound
        // to. In the current production path that Course identity is inherited from the accepted
        // service arrangement, so R1 validates a usable active Course and does not restate a
        // course-type policy here; selecting a distinct paid-Term Course is future authority.
        if(!$course||$course->archived_at!==null||(string)$course->status!=='active')throw new \InvalidArgumentException('Usable Academy Course required');
        return $course;
    }
    private static function optionalText(mixed $value):?string{
        if($value===null||trim((string)$value)==='')return null;
        return mb_substr(trim((string)$value),0,191);
    }
}
