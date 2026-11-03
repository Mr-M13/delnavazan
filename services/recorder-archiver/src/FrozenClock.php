<?php
declare(strict_types=1);

namespace Delnavazan\Recorder;

final class FrozenClock implements Clock {
    public function __construct(private int $at) {}
    public function now(): int { return $this->at; }
    public function advance(int $seconds): void { $this->at += $seconds; }
}
