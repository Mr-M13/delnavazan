<?php
namespace Delnavazan\Platform\Integrations\Notifications;

/** A validation refusal carrying every human-readable reason, never a partial save. */
final class NotificationTemplateValidationException extends \InvalidArgumentException {
    /** @var string[] */
    private array $reasons;
    /** @param string[] $reasons */
    public function __construct(array $reasons){
        $this->reasons=array_values($reasons);
        parent::__construct(implode(' ', $this->reasons));
    }
    /** @return string[] */
    public function reasons():array{return $this->reasons;}
}
