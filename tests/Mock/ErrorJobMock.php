<?php

declare(strict_types=1);

/**
 * TaskScheduler
 *
 * @author      Raffael Sahli <sahli@gyselroth.net>
 * @copyright   Copryright (c) 2017-2018 gyselroth GmbH (https://gyselroth.com)
 * @license     MIT https://opensource.org/licenses/MIT
 */

namespace TaskScheduler\Testsuite\Mock;

use Exception;
use TaskScheduler\AbstractJob;

class ErrorJobMock extends AbstractJob
{
    public function start(): bool
    {
        throw new Exception('fail');
    }
}
