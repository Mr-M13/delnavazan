<?php
namespace Delnavazan\Platform\Core\Application\Port;

/** Fail-closed port for machine-learning dataset eligibility and manifest operations. */
interface ConsentDatasetPort {
    public function evaluate(array $source):array;
    public function ingest(array $source,string $datasetKey,string $datasetVersion,string $storageReference,int $actor,string $lineage=''):array;
    public function assertEligible(array $source,string $datasetKey,string $datasetVersion,string $storageReference,int $actor,string $lineage=''):array;
    public function reEvaluateItem(int $itemId,int $actor=0):array;
    public function reEvaluateForSubject(string $subjectType,int $subjectId,int $actor=0):array;
    public function markExcluded(int $itemId,string $reason,int $actor=0):void;
    public function exportManifest(string $datasetKey):array;
    public function counts():array;
}
