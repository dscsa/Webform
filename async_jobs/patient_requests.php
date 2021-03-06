<?php

ini_set('memory_limit', '512M');
ini_set('include_path', '/goodpill/webform');
date_default_timezone_set('America/New_York');

require_once 'vendor/autoload.php';

use GoodPill\AWS\SQS\{
    PharmacyPatientQueue,
    PharmacySyncRequest
};

use GoodPill\Logging\{
    GPLog,
    AuditLog,
    CliLog
};

require_once 'keys.php';

require_once 'dbs/mysql_wc.php';
require_once 'dbs/mssql_cp.php';

require_once 'helpers/helper_logger.php';
require_once 'helpers/helper_log.php';
require_once 'helpers/helper_laravel.php';
require_once 'helpers/helper_appsscripts.php';
require_once 'helpers/helper_constants.php';
require_once 'helpers/helper_cp_test.php';
require_once 'helpers/helper_changes.php';
require_once 'helpers/helper_error_handler.php';


// TODO Remove this once we have mssql duplicating the Database
if (ENVIRONMENT == 'PRODUCTION') {
    CliLog::debug("Testing Carepoint DB connection");
    if (!cp_test()) {
        $message = '** Could not connect to Carepoint **';
        echo "{$message}\n";
        GPLog::alert($message);
        CliLog::alert($message);
        GPLog::getLogger()->flush();
        exit;
    }
}

/* Logic to give us a way to figure out if we should quit working */

/*
$stopRequested = false;
pcntl_signal(
    SIGTERM,
    function ($signo, $signinfo) {
        global $stopRequested, $log;
        $stopRequested = true;
        CliLog::warning("SIGTERM caught");
    }
);
*/

/*
  Export Functions - used to push aggregate data out and to notify
  users of interactions
 */
 /*
   Import Functions - Used to pull data into summary tables
   with normalized formatting
  */
 require_once 'imports/import_v2_drugs.php';
 require_once 'imports/import_v2_stock_by_month.php';
 require_once 'imports/import_cp_rxs_single.php';
 require_once 'imports/import_wc_patients.php';
 require_once 'imports/import_cp_patients.php';
 require_once 'imports/import_cp_order_items.php';
 require_once 'imports/import_wc_orders.php';
 require_once 'imports/import_cp_orders.php';


 /*
   Change Functions - Used to compared new tables with current tables
   return an [created, updated, deleted] and set current tables to be same as new tables
  */
  require_once 'changes/changes_to_drugs.php';
  require_once 'changes/changes_to_stock_by_month.php';
  require_once 'changes/changes_to_rxs_single.php';
  require_once 'changes/changes_to_patients_wc.php';
  require_once 'changes/changes_to_patients_cp.php';
  require_once 'changes/changes_to_order_items.php';
  require_once 'changes/changes_to_orders_wc.php';
  require_once 'changes/changes_to_orders_cp.php';

 /*
   Export Functions - used to push aggregate data out and to notify
   users of interactions
  */
 require_once 'updates/update_drugs.php';
 require_once 'updates/update_stock_by_month.php';
 require_once 'updates/update_rxs_single.php';
 require_once 'updates/update_patients_wc.php';
 require_once 'updates/update_patients_cp.php';
 require_once 'updates/update_order_items.php';
 require_once 'updates/update_orders_wc.php';
 require_once 'updates/update_orders_cp.php';

// Grab and item out of the queue
$patientQueue = new PharmacyPatientQueue();

// TODO up this execution count so we aren't restarting the thread so often
$executions = (ENVIRONMENT == 'PRODUCTION') ? 40 : 2;

// Only loop so many times before we restart the script
for ($l = 0; $l < $executions; $l++) {
    CliLog::debug("All includes imported waiting for message");

    if (file_exists('/tmp/block-patient-queue.txt')) {
        sleep(30);
        CliLog::error('Patient Sync Job blocked by /tmp/block-patient-queue.txt');
        continue;
    }

    // TODO Change this number to 10 when we start havnig multiple groups
    $results  = $patientQueue->receive(
        [
            'MaxNumberOfMessages' => 2,
            'AttributeNames' => [
                'MessageGroupId',
                'SequenceNumber'
            ]
        ]
    );
    $messages = $results->get('Messages');
    $complete = [];

    // An array of messages that have
    // been proccessed and can be deleted
    // If we've got something to work with, go for it
    if (is_array($messages) && count($messages) >= 1) {
        // This object is only here to make sure the Mysql connection hasnt' died while we
        // were waiting for a sqs message
        $mysql       = new Mysql_Wc();
        $log_message = sprintf(
            "Processing %s messages\n",
            count($messages)
        );

        GPLog::debug($log_message);
        CliLog::debug($log_message);
        foreach ($messages as $message) {

            GPLog::debug(
                'SQS Message Id',
                [
                    'Id'            => $message['MessageId'],
                    'ReceiptHandle' => $message['ReceiptHandle']
                ]
            );
            $request = new PharmacySyncRequest($message);
            $changes = $request->changes;

            $log_message = sprintf(
                "Processing changes %s to %s in group %s item number %s",
                $request->changes_to,
                $request->patient_id,
                $request->getGroupId(),
                $request->getSequenceNumber()
            );

            if (isset($request->execution_id)) {
                GPLog::$exec_id = $request->execution_id;
            }

            if (isset($request->subroutine_id)) {
                GPLog::$subroutine_id = $request->subroutine_id;
            }

            GPLog::debug($log_message, $changes);
            CliLog::notice($log_message, $changes);

            try {
                switch ($request->changes_to) {
                    case 'patients_cp':
                        update_patients_cp($changes);
                        break;
                    case 'patients_wc':
                        update_patients_wc($changes);
                        break;
                    case 'rxs_single':
                        update_rxs_single($changes);
                        break;
                    case 'orders_cp':
                        update_orders_cp($changes);
                        break;
                    case 'orders_wc':
                        update_orders_wc($changes);
                        break;
                    case 'order_items':
                        update_order_items($changes);
                        break;
                }

                /* Check to see if we've requeted to stop */
                /*
                pcntl_signal_dispatch();

                if ($stopRequested) {
                    CLiLog::warning('Finishing current Message then terminating');
                    break;
                }
                */
            } catch (\Exception $e) {
                // Log the error
                $message = "PATIENT SYNC JOB - ERROR ";
                $message .= $e->getCode() . " " . $e->getMessage() ." ";
                $message .= $e->getFile() . ":" . $e->getLine() . "\n";
                $message .= $e->getTraceAsString();

                GPLog::alert(
                    $message . "
                    Remove /tmp/block-patient-queue.txt and restart supervisord to restart the process"
                );

                // Create the block file
                file_put_contents('/tmp/block-patient-queue.txt', $message);

                break;
            }

            $complete[] = $request;
        }
    }

    // Delete any complet messages
    if (!empty($complete)) {
        $log_message = sprintf(
            "Deleting %s messages",
            count($complete)
        );

        GPLog::debug($log_message);
        CliLog::notice($log_message);

        if (!$patientQueue->deleteBatch($complete)) {
            GPLog::emergency(
                "A Patient Message failed to delete.  This could result in stuck syncs.
                 Remove /tmp/block-patient-queue.txt and restart supervisord to restart the process"
            );

            // Create the block file
            file_put_contents('/tmp/block-patient-queue.txt', date('c'));
        }
    }

    unset($changes);
    unset($response);
    unset($messages);
    unset($complete);

    /*
    if ($stopRequested) {
        CLiLog::warning('Terminating execution from SIGTERM request');
        exit;
    }
    */
}
