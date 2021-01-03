<?php

require_once 'vendor/autoload.php';

#putenv('GOOGLE_APPLICATION_CREDENTIALS=unified-logging.json');
putenv('GOOGLE_APPLICATION_CREDENTIALS=/etc/google/unified-logging.json');

use Sirum\Logging\SirumLog;
use Sirum\Logging\AuditLog;

/*
 * This shouldn't be here.  When this moves into a standalone class,
 * we will rework it.
 */
SirumLog::getLogger('pharmacy-automation');
AuditLog::getLogger();
