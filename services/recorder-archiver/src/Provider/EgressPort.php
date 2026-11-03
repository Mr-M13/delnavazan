<?php
declare(strict_types=1);

namespace Delnavazan\Recorder\Provider;

interface EgressPort {
    /** @return array{egress_id:string,status:string,output_path:string} */
    public function start(array $job): array;
    /** @return array{egress_id:string,status:string,output_path:string,error:string} */
    public function status(string $egressId): array;
    /** @return array{egress_id:string,status:string,output_path:string} */
    public function stop(string $egressId): array;
}
