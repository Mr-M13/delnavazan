<?php
declare(strict_types=1);

namespace Delnavazan\Recorder;

final class SystemClock implements Clock {
    public function now(): int { return time(); }
}
