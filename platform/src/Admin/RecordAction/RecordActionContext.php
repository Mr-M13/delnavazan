<?php
namespace Delnavazan\Platform\Admin\RecordAction;

/** Resolved server-side record context for one focused action. */
final class RecordActionContext {
    public function __construct(
        private string $actionKey,
        private string $recordType,
        private int $recordId,
        private string $page,
        private string $idempotencyKey,
        private object $record,
    ) {
    }

    public function actionKey(): string { return $this->actionKey; }
    public function recordType(): string { return $this->recordType; }
    public function recordId(): int { return $this->recordId; }
    public function page(): string { return $this->page; }
    public function idempotencyKey(): string { return $this->idempotencyKey; }
    public function record(): object { return $this->record; }

    public function redirectUrl(array $extra = array()): string {
        return admin_url('admin.php?' . http_build_query(array_merge(array(
            'page' => $this->page,
            'id' => $this->recordId,
        ), $extra)));
    }
}
