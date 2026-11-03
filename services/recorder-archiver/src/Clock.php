<?php
declare(strict_types=1);

namespace Delnavazan\Recorder;

interface Clock { public function now(): int; }
