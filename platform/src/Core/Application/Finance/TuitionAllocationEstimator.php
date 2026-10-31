<?php
namespace Delnavazan\Platform\Core\Application\Finance;

/**
 * Illustrative tuition allocation only. This is not an accounting ledger and
 * must never be used to create payments, invoices, settlements or tax facts.
 */
final class TuitionAllocationEstimator {
    /** Central basis-point configuration; the explicit unallocated row closes 100%. */
    private const CATEGORIES = array(
        array('key'=>'teacher','label_fa'=>'مدرس','basis_points'=>2800,'color'=>'#176b62'),
        array('key'=>'tax','label_fa'=>'مالیات','basis_points'=>2800,'color'=>'#315c86'),
        array('key'=>'advertisement','label_fa'=>'تبلیغات','basis_points'=>2000,'color'=>'#9a6a18'),
        array('key'=>'gst','label_fa'=>'GST','basis_points'=>1000,'color'=>'#7b4e9e'),
        array('key'=>'transaction','label_fa'=>'کارمزد تراکنش','basis_points'=>320,'color'=>'#8c4b45'),
        array('key'=>'staff_admin','label_fa'=>'کارکنان و مدیریت','basis_points'=>200,'color'=>'#4f6f52'),
        array('key'=>'server_app','label_fa'=>'سرور و اپلیکیشن','basis_points'=>200,'color'=>'#536b8f'),
        array('key'=>'messaging','label_fa'=>'پیام‌رسانی و اعلان‌ها','basis_points'=>100,'color'=>'#7a667e'),
        array('key'=>'project','label_fa'=>'پروژه','basis_points'=>280,'color'=>'#8a7038'),
        array('key'=>'delnavazan','label_fa'=>'دلنوازان','basis_points'=>200,'color'=>'#2f7771'),
        array('key'=>'unallocated','label_fa'=>'تخصیص‌نیافته','basis_points'=>100,'color'=>'#6f7478'),
    );

    /** @return array{amount_minor:int,total_minor:int,total_basis_points:int,items:list<array<string,mixed>>} */
    public static function estimate(int $amountMinor): array {
        if ($amountMinor < 0) throw new \InvalidArgumentException('allocation_amount_invalid');
        $totalBasisPoints=array_sum(array_column(self::CATEGORIES,'basis_points'));
        if ($totalBasisPoints!==10000) throw new \LogicException('allocation_percentages_must_total_100_percent');

        $items=[];$allocated=0;
        foreach(self::CATEGORIES as$index=>$category){
            $numerator=$amountMinor*(int)$category['basis_points'];
            $minor=intdiv($numerator,10000);
            $allocated+=$minor;
            $items[]=$category+array(
                'percentage'=>(int)$category['basis_points']/100,
                'percentage_label'=>number_format((int)$category['basis_points']/100,1,'.',''),
                'amount_minor'=>$minor,
                '_remainder'=>$numerator%10000,
                '_order'=>$index,
            );
        }

        // Largest-remainder rounding makes the displayed rows reconcile exactly
        // with the paid term amount in the currency's existing minor unit.
        $remaining=$amountMinor-$allocated;
        $order=array_keys($items);
        usort($order,static fn(int$a,int$b):int=>[$items[$b]['_remainder'],-$items[$b]['_order']]<=>[$items[$a]['_remainder'],-$items[$a]['_order']]);
        for($i=0;$i<$remaining;$i++)$items[$order[$i%count($order)]]['amount_minor']++;
        foreach($items as&$item){unset($item['_remainder'],$item['_order']);}unset($item);

        return array('amount_minor'=>$amountMinor,'total_minor'=>array_sum(array_column($items,'amount_minor')),'total_basis_points'=>$totalBasisPoints,'items'=>$items);
    }
}
