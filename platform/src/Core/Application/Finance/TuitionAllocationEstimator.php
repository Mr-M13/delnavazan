<?php
namespace Delnavazan\Platform\Core\Application\Finance;

/**
 * Illustrative tuition allocation only. This is not an accounting ledger and
 * must never be used to create payments, invoices, settlements or tax facts.
 */
final class TuitionAllocationEstimator {
    /**
     * Central basis-point configuration of the approved illustrative distribution.
     *
     * The former unallocated 1% is now part of Delnavazan's 3% share; the other
     * percentages are unchanged. Every category is a share of tuition revenue,
     * never a claim that the amount was actually spent or paid as tax.
     */
    private const CATEGORIES = array(
        array('key'=>'teacher','label_fa'=>'پرداختی به اساتید','basis_points'=>2800,'color'=>'#176b62'),
        array('key'=>'tax','label_fa'=>'مالیات','basis_points'=>2800,'color'=>'#315c86'),
        array('key'=>'advertisement','label_fa'=>'هزینه تبلیغات','basis_points'=>2000,'color'=>'#9a6a18'),
        array('key'=>'gst','label_fa'=>'مالیات کالا و خدمات (GST – Goods and Services Tax)','basis_points'=>1000,'color'=>'#7b4e9e'),
        array('key'=>'transaction','label_fa'=>'کارمزد تراکنش‌های اینترنتی و بانکی','basis_points'=>320,'color'=>'#8c4b45'),
        array('key'=>'staff_admin','label_fa'=>'پرداختی به کارکنان و مدیریت','basis_points'=>200,'color'=>'#4f6f52'),
        array('key'=>'server_app','label_fa'=>'هزینه سرور، اپلیکیشن و وب‌سایت','basis_points'=>200,'color'=>'#536b8f'),
        array('key'=>'messaging','label_fa'=>'هزینه پیامک و واتساپ','basis_points'=>100,'color'=>'#7a667e'),
        array('key'=>'project','label_fa'=>'پروژه‌ها (نام موقت)','basis_points'=>280,'color'=>'#8a7038'),
        array('key'=>'delnavazan','label_fa'=>'سهم دلنوازان — ۳٪','basis_points'=>300,'color'=>'#2f7771'),
    );

    /** Percentage-only categories for the shared explanatory component. */
    public static function categories():array{
        $items=array();
        foreach(self::CATEGORIES as$category){
            $items[]=$category+array(
                'percentage'=>(int)$category['basis_points']/100,
                'percentage_label'=>self::percentageLabel((int)$category['basis_points']),
            );
        }
        return $items;
    }

    /** @return array{amount_minor:int,total_minor:int,total_basis_points:int,items:list<array<string,mixed>>} */
    public static function estimate(int $amountMinor): array {
        if ($amountMinor < 0) throw new \InvalidArgumentException('allocation_amount_invalid');
        $categories=self::categories();
        $totalBasisPoints=array_sum(array_column($categories,'basis_points'));
        if ($totalBasisPoints!==10000) throw new \LogicException('allocation_percentages_must_total_100_percent');

        $items=[];$allocated=0;
        foreach($categories as$index=>$category){
            $numerator=$amountMinor*(int)$category['basis_points'];
            $minor=intdiv($numerator,10000);
            $allocated+=$minor;
            $items[]=$category+array(
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

    private static function percentageLabel(int $basisPoints):string{
        return rtrim(rtrim(number_format($basisPoints/100,2,'.',''),'0'),'.');
    }
}
