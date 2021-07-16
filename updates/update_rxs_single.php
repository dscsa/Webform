<?php
require_once 'helpers/helper_parse_sig.php';
require_once 'helpers/helper_imports.php';
require_once 'helpers/helper_identifiers.php';
require_once 'exports/export_cp_rxs.php';
require_once 'exports/export_gd_transfer_fax.php'; //is_will_transfer()
require_once 'dbs/mysql_wc.php';

use GoodPill\Logging\GPLog;
use GoodPill\Logging\AuditLog;
use GoodPill\Logging\CliLog;

use GoodPill\Utilities\Timer;
use GoodPill\Models\GpRxsSingle;
use GoodPill\Utilities\SigParser;

function update_rxs_single($changes)
{
    // Make sure we have some data
    $change_counts = [];
    foreach (array_keys($changes) as $change_type) {
        $change_counts[$change_type] = count($changes[$change_type]);
    }

    if (array_sum($change_counts) == 0) {
        return;
    }

    unset($patient_id_cp);

    GPLog::info(
        "update_rxs_single: changes",
        $change_counts
    );

    GPLog::notice('data-update-rxs-single', $changes);

    $mysql = new Mysql_Wc();
    $mssql = new Mssql_Cp();

    /*
     * Created Loop #1 First loop accross new items. Run this before rx_grouped query to make
     * sure all sig_qty_per_days are properly set before we group by them
     */
    $loop_timer = microtime(true);
    if (isset($changes['created'])) {
        foreach ($changes['created'] as $created) {
            $patient_id_cp = $created['patient_id_cp'];

            GPLog::$subroutine_id = "rxs-single-created1-".sha1(serialize($created));

            // Put the created into a log so if we need to we can reconstruct the process
            GPLog::info('data-rxs-single-created', ['created' => $created]);


            $patient = getPatientByRx($created['rx_number']);

            AuditLog::log(
                sprintf(
                    "New Rx# %s for %s created via carepoint",
                    $created['rx_number'],
                    $created['drug_name']
                ),
                $patient
            );

            GPLog::debug(
                "update_rxs_single: rx created1. Set denormalized data needed for the rxs_grouped table",
                [
                      'created' => $created,
                      'source'  => 'CarePoint',
                      'type'    => 'rxs-single',
                      'event'   => 'created1'
                  ]
            );

            $rx_single = GpRxsSingle::where('rx_number', $created['rx_number'])->first();
            $rx_single->setGpChanges($created);

            if ($rx_single->needsGsnUpdate() && $rx_single->isInFormulary()) {
                if (!$rx_single->updateDrugGsns()) {
                    GPLog::notice(
                        "update_rxs_single: rx created but drug_gsns is empty",
                        [ 'created'  => $created]
                    );
                }
            } else {
                $created_date = "Created:".date('Y-m-d H:i:s');

                if ($created['rx_gsn']) {
                    $subject = "NEW {$created['rx_number']} still needs GSN {$created['rx_gsn']} added to V2";
                    $body    = "{$created['drug_name']} for $subject";
                    $assign  = ".Inventory Issue";
                    GPLog::warning($body, $created);
                } else {
                    $subject = "NEW {$created['rx_number']} still needs to be switched to a drug with a GSN";
                    $body    = "{$created['drug_name']} for $subject in CarePoint";
                    $assign  = ".Inventory Issue";
                    GPLog::notice($body, $created);
                }

                $salesforce = [
                  "subject"   => $subject,
                  "body"      => "$body $created_date",
                  "contact"   => "{$patient['first_name']} {$patient['last_name']} {$patient['birth_date']}",
                  "assign_to" => $assign,
                  "due_date"  => date('Y-m-d')
                ];

                $event_title  = @$created['rx_number']." Missing GSN: {$salesforce['contact']} $created_date";

                $message_as_string = implode('_', $salesforce);
                $notification = new \GoodPill\Notifications\Salesforce(sha1($message_as_string), $message_as_string);

                if (!$notification->isSent()) {
                    GPLog::debug(
                        $subject,
                        [
                            'created' => $created,
                            'body'    => $body
                        ]
                    );

                    create_event($event_title, [$salesforce]);
                } else {
                    GPLog::warning(
                        "DUPLICATE Saleforce Message".$subject,
                        [
                            'created' => $created,
                            'body'    => $body
                        ]
                    );
                }

                $notification->increment();
            }

            // Get the signature
            $parsed = get_parsed_sig($created['sig_actual'], $created['drug_name']);
            $parser = new SigParser("/tmp/aws-ch-responses.json");
            $exp_parsed = $parser->parse($created['sig_actual'], $created['drug_name']);

            // Old Parser
            $sig_qty     = $exp_parsed['sig_qty'];
            $sig_days    = ($exp_parsed['sig_days']) ? : null;
            $sig_qty_per_day = ($exp_parsed['sig_days']) ? ($sig_qty/$sig_days) : null;

            $sig_log_data =  [
                'rx_number' => $rx_single->rx_number,
                'sig' => $created['sig_actual'],
                'drug' => $created['drug_name'],
                'parsed' => $parsed,
                'exp_parsed' => $exp_parsed,
                'sig_qty' => $sig_qty,
                "sig_days" => $sig_days,
                "sig_qty_per_day" => $sig_qty_per_day
            ];

            if ($parsed['sig_qty'] != $exp_parsed['sig_qty']) {
                GPLog::warning(
                    'BETA: Sig Parsing Test - Quantity does not match',
                    $sig_log_data
                );
            } else {
                GPLog::info(
                    'BETA: Sig Parsing Test',
                    $sig_log_data
                );
            }

            // If we have more than 8 a day, lets have a human verify the signature
            if ($sig_qty_per_day > MAX_QTY_PER_DAY) {
                $created_date = "Created:".date('Y-m-d H:i:s');
                $salesforce   = [
                    "subject"   => "Verify qty pended for $created[drug_name] for Rx #$created[rx_number]",
                    "body"      => "For Rx #$created[rx_number], $created[drug_name] with sig '$created[sig_actual]' was parsed as $parsed[qty_per_day] qty per day, which is very high. $created_date",
                    "contact"   => "$patient[first_name] $patient[last_name] $patient[birth_date]",
                    "assign_to" => ".DDx/Sig Issue",
                    "due_date"  => date('Y-m-d')
                ];

                $event_title = "$item[invoice_number] Sig Parsing Error: $salesforce[contact] $created_date";

                create_event($event_title, [$salesforce]);

                GPLog::warning(
                    $salesforce['body'],
                    [
                      'salesforce' => $salesforce,
                      'created' => $created,
                      'parsed' => $parsed
                    ]
                );
            }

            if (!$sig_qty_per_day) {
                $created_date = "Created:".date('Y-m-d H:i:s');
                $salesforce   = [
                    "subject"   => "Error: 0 or null dosage for {$created['drug_name']} in "
                                   . "Order {$item['invoice_number']}. Verify qty pended "
                                   . "for Rx #{$created['rx_number']}",
                    "body"      => "For Rx #{$created['rx_number']}, {$created['drug_name']} with "
                                    . "sig '{$created['sig_actual']}' was parsed as 0 or NULL quantity."
                                    . "  This will result in zero items pended. $created_date",
                    "contact"   => "$patient[first_name] $patient[last_name] $patient[birth_date]",
                    "assign_to" => ".Manually Add Drug To Order",
                    "due_date"  => date('Y-m-d')
                ];

                $event_title = "{$item['invoice_number']} 0 or null dosage Sig Parsing Error: {$salesforce['contact']} {$created_date}";

                create_event($event_title, [$salesforce]);

                GPLog::warning(
                    $salesforce['body'],
                    [
                      'salesforce' => $salesforce,
                      'created' => $created,
                      'parsed' => $parsed
                    ]
                );
            }

            // save all the parsed sig information
            $rx_single->sig_qty                    = $sig_qty;
            $rx_single->sig_days                   = $sig_days;
            $rx_single->sig_qty_per_day_default    = $sig_qty_per_day;

            // Old Sig parsing details
            $rx_single->sig_v1_qty                 = $parsed['sig_qty'];
            $rx_single->sig_v1_days                = $parsed['sig_days'];
            $rx_single->sig_v1_qty_per_day         = $parsed['qty_per_day'];
            $rx_single->sig_initial                = $parsed['sig_actual'];
            $rx_single->sig_clean                  = $parsed['sig_clean'];
            $rx_single->sig_durations              = ',' .implode(',', $parsed['durations']).',';
            $rx_single->sig_qtys_per_time          = ',' .implode(',', $parsed['qtys_per_time']).',';
            $rx_single->sig_frequencies            = ',' .implode(',', $parsed['frequencies']).',';
            $rx_single->sig_frequency_numerators   = ',' .implode(',', $parsed['frequency_numerators']).',';
            $rx_single->sig_frequency_denominators = ',' .implode(',', $parsed['frequency_denominators']). ',';

            // New Sig parsing details
            $rx_single->sig_v2_qty         = $exp_parsed['sig_qty'];
            $rx_single->sig_v2_days        = $exp_parsed['sig_days'];
            $rx_single->sig_v2_qty_per_day = $exp_parsed['sig_qty']/$exp_parsed['sig_days'];
            $rx_single->sig_v2_unit        = $exp_parsed['sig_unit'];
            $rx_single->sig_v2_conf_score  = $exp_parsed['sig_conf_score'];
            $rx_single->sig_v2_dosages     = $exp_parsed['dosages'];
            $rx_single->sig_v2_scores      = $exp_parsed['scores'];
            $rx_single->sig_v2_frequencies = $exp_parsed['frequencies'];
            $rx_single->sig_v2_durations   = $exp_parsed['durations'];

            $rx_single->save();

            GPLog::debug(
                "RX Single updated with new sig details",
                array_filter(
                    $rx_single->toArray(),
                    fn ($key) => strpos($key, 'sig_') !== false,
                    ARRAY_FILTER_USE_KEY
                )
            );
        }
    }

    /* Finish Loop Created Loop  #1 */


    $loop_timer = microtime(true);

    foreach ($changes['updated'] as $updated) {
        $patient_id_cp = $updated['patient_id_cp'];
        GPLog::$subroutine_id = "rxs-single-updated1-".sha1(serialize($updated));

        // Put the created into a log so if we need to we can reconstruct the process
        GPLog::info('data-rxs-single-updated', ['updated' => $updated]);

        $changed = changed_fields($updated);

        $patient = getPatientByRx($updated['rx_number']);

        GPLog::debug(
            "update_rxs_single1: rx updated $updated[drug_name] $updated[rx_number]",
            [
                'updated' => $updated,
                'changed' => $changed,
                'patient' => $patient,
                'source'  => 'CarePoint',
                'type'    => 'rxs-single',
                'event'   => 'updated1'
            ]
        );

        if ($updated['refill_date_first'] and ! $updated['rx_gsn']) {
            GPLog::warning("RX is missing GSN but refill RXs cannot be changed", $updated);
        }

        $rx_single = GpRxsSingle::where('rx_number', $updated['rx_number'])->first();
        $rx_single->setGpChanges($updated);

        if (
            $rx_single->needsGsnUpdate()
        ) {
            GPLog::warning(
                "update_rxs_single1 rx_gsn updated (before rxs_grouped)",
                [
                    'updated' => $updated,
                    'changed' => $changed
                ]
            );

            if ($rx_single->isInFormulary()) {
                $rx_single->updateDrugGsns();
            } else {
                $created_date = "Created:".date('Y-m-d H:i:s');

                if (!empty($rx_single)) {
                    $subject = "UPDATED {$rx_single->rx_number} still needs GSN {$rx_single->rx_gsn} added to V2";
                    $body    = "{$updated['drug_name']} for $subject";
                    $assign  = ".Inventory Issue";
                    GPLog::warning($body, $updated);
                } else {
                    $subject = "UPDATED {$updated['rx_number']} still needs to be switched to a drug with a GSN";
                    $body    = "{$updated['drug_name']} for $subject in CarePoint";
                    $assign  = ".Inventory Issue";
                    GPLog::notice($body, $updated);
                }

                $salesforce = [
                  "subject"   => $subject,
                  "body"      => "$body $created_date",
                  "contact"   => "{$patient['first_name']} {$patient['last_name']} {$patient['birth_date']}",
                  "assign_to" => $assign,
                  "due_date"  => date('Y-m-d')
                ];

                $event_title = @$updated['rx_number']." Missing GSN: {$salesforce['contact']} $created_date";

                $message_as_string = implode('_', $salesforce);
                $notification = new \GoodPill\Notifications\Salesforce(sha1($message_as_string), $message_as_string);

                if (!$notification->isSent()) {
                    GPLog::debug(
                        $subject,
                        [
                            'updated' => $updated,
                            'body'    => $body
                        ]
                    );

                    create_event($event_title, [$salesforce]);
                } else {
                    GPLog::warning(
                        "DUPLICATE Saleforce Message".$subject,
                        [
                            'updated' => $updated,
                            'body'    => $body
                        ]
                    );
                }

                $notification->increment();
            }
        }
    }

    if (isset($changes['deleted'])) {
        foreach ($changes['deleted'] as $deleted) {
            // Find order items that are in an non dispense_date order
            // Remove the order item
        }
    }

    /*
     * This work is to create the perscription groups.
     *
     * This is an expensive (~30 seconds) group query.
     * TODO We should update rxs in this table individually on changes (
     * TODO AK: ABOVE CHANGE WOULD ENABLE US TO HAVE AUTOINCREMENT IDS FOR RX_GROUPED - WHICH WE SAVE BACK INTO RXS_SINGLE or a LOOKUP TABLE - THIS WILL HELP QUERY SPEED BY REPLACE LIKE %% AND FIND_IN_SET)
     * TODO AND/OR We should add indexed drug info fields to the gp_rxs_single above on
     *      created/updated so we don't need the wildcard join which is the slowest part
     */

    //TODO if we make this incremental updates, we need to think about the fields with NOW(), this doesn't easily translate
    //into an created/update/deleted type of update.

    //NOTE This Group By Clause must be kept consistent with the grouping with the export_cp_set_rx_message query
    $sql = "
    INSERT INTO gp_rxs_grouped
    SELECT
  	  patient_id_cp,
      COALESCE(drug_generic, drug_name) as drug_generic,
      MAX(drug_brand) as drug_brand,
      MAX(drug_name) as drug_name,
      COALESCE(sig_qty_per_day_actual, sig_qty_per_day_default) as sig_qty_per_day,
      GROUP_CONCAT(DISTINCT rx_message_key) as rx_message_keys,

      MAX(rx_gsn) as max_gsn, -- this is guardian-supplied rx field
      MAX(drug_gsns) as drug_gsns, -- this is a v2-supplied comma-delimited list of gsns for a drug

      SUM(CASE
        WHEN rx_date_expired > NOW() -- expiring does not trigger an update in the rxs_single page current so we have to watch the field here
        THEN refills_left
        ELSE 0
      END) as refills_total,

      SUM(CASE
        WHEN rx_date_expired > NOW() -- expiring does not trigger an update in the rxs_single page current so we have to watch the field here
        THEN qty_left
        ELSE 0
      END) as qty_total,

      MIN(rx_autofill) as rx_autofill, -- if one is taken off, then a new script will have it turned on but we need to go with the old one

      MIN(refill_date_first) as refill_date_first,
      MAX(refill_date_last) as refill_date_last,

      MIN(CASE
          WHEN refill_date_manual > NOW() -- expiring does not trigger an update in the rxs_single page current so we have to watch the field here
          THEN refill_date_manual
          WHEN refill_date_default > NOW() AND rx_autofill > 0
          THEN refill_date_default
          ELSE NULL
      END) as refill_date_next,

      COALESCE(
        MIN(
            CASE -- Max/Min here shouldn't make a difference since they should all be the same
                WHEN refill_date_manual > NOW() -- expiring does not trigger an update in the rxs_single page current so we have to watch the field here
                THEN refill_date_manual
                ELSE NULL
            END
        ),
        MAX(CASE WHEN refill_date_default > NOW() AND rx_autofill > 0 THEN refill_date_default ELSE NULL END)
      ) as refill_date_manual,

      MAX(refill_date_default) as refill_date_default,

      COALESCE(
        MIN(CASE WHEN qty_left >= ".DAYS_MIN." AND rx_date_expired >= NOW() + INTERVAL ".DAYS_MIN." DAY THEN rx_number ELSE NULL END),
        MIN(CASE WHEN qty_left > 0 AND rx_date_expired >= NOW() THEN rx_number ELSE NULL END),
    	  MAX(rx_number)
      ) as best_rx_number,

      CONCAT(',', GROUP_CONCAT(rx_number), ',') as rx_numbers,

      GROUP_CONCAT(DISTINCT rx_source) as rx_sources,

      MAX(rx_date_changed) as rx_date_changed,
      MAX(rx_date_expired) as rx_date_expired,
      NULLIF(MIN(COALESCE(rx_date_transferred, '0')), '0') as rx_date_transferred -- Only mark as transferred if ALL Rxs are transferred out

  	FROM gp_rxs_single ";

    if (isset($patient_id_cp)) {
        $sql .= " WHERE patient_id_cp = {$patient_id_cp}";
    }

    $sql .= " GROUP BY
                  patient_id_cp,
                  COALESCE(drug_generic, drug_name),
                  COALESCE(sig_qty_per_day_actual, sig_qty_per_day_default)
    ";

    $delete_sql = "DELETE FROM gp_rxs_grouped";

    if (isset($patient_id_cp)) {
        $delete_sql .= " WHERE patient_id_cp = {$patient_id_cp}";
    }

    $test_sql = "SELECT * FROM gp_rxs_grouped";
    if (isset($patient_id_cp)) {
        $test_sql .= " WHERE patient_id_cp = {$patient_id_cp}";
    }

    if (isset($patient_id_cp)) {
        GPLog::debug(
            'Using patient_id_cp in where clause',
            [
                'group' => $sql,
                'delete' => $delete_sql,
                'test'   => $test_sql
            ]
        );
    }

    $mysql->transaction();
    $mysql->run($delete_sql);

    $group_timer = microtime(true);
    $mysql->run($sql);
    log_timer('rx-singles-grouped', $group_timer, 1);

    // QUESTION Do we need to get everthing or would a LIMIT 1 be fine?
    if ($mysql->run($test_sql)[0]) {
        $mysql->commit();
    } else {
        $mysql->rollback();
    }

    //TODO should we put a UNIQUE contstaint on the rxs_grouped table for bestrx_number and rx_numbers, so that it fails hard
    $duplicate_gsns = $mysql->run("
      SELECT
        best_rx_number,
        GROUP_CONCAT(drug_generic),
        GROUP_CONCAT(drug_gsns),
        COUNT(rx_numbers)
      FROM `gp_rxs_grouped`
      GROUP BY best_rx_number
      HAVING COUNT(rx_numbers) > 1
    ")[0];

    if (isset($duplicate_gsns[0][0])) {
        GPLog::critical(
            "update_rxs_single: duplicate gsns detected",
            ['duplicate_gsns' => $duplicate_gsns]
        );
    }

    /*
     * Created Loop #2 We are now assigning the rx group to the new patients
     * from created list.  We ae allso removing any drug refils.
     *
     * QUESTION Do new users have drug refils?
     *
     * Run this After so that Rx_grouped is set when doing get_full_patient
     */

    $rxs_created2 = [];

    $loop_timer = microtime(true);
    if (isset($changes['created'])) {
        foreach ($changes['created'] as $created) {
            GPLog::$subroutine_id = "rxs-single-created2-".sha1(serialize($created));

            $item = load_full_item($created, $mysql);

            GPLog::debug(
                "update_rxs_single: rx created2",
                [
                    'created' => $created,
                    'item'    => $item,
                    'source'  => 'CarePoint',
                    'type'    => 'rxs-single',
                    'event'   => 'created2'
                ]
            );

            //TODO rather hackily editing calendar events, probably better to just delete and then recreate them
            if ($item) {
                remove_drugs_from_refill_reminders(
                    $item['first_name'],
                    $item['last_name'],
                    $item['birth_date'],
                    [$created['drug_name']]
                );
            }

            //  Check to see if newly created item already has item_date_added
            //  If it's already set, update payment

            if ($item && $item['item_date_added'] && $item['invoice_number']) {
                $reason = "rxs-single-created2: Item created with date_added {$item['drug_name']}";
                $order = get_full_order($mysql, $item['invoice_number']);

                GPLog::debug(
                    $reason,
                    [
                        'invoice_number'  => $item['invoice_number'],
                        'item'    => $item,
                        'order'    => $order
                    ]
                );

                helper_update_payment($order, $reason, $mysql);
            }

            //Added from Fax/Call so order was not automatically created which is what would normally trigger a needs form notice
            //but since order:created subroutine will not be called we need to send out the needs form notice here instead
            //group by unique patient so that we don't create/delete lots of needs_form_notices for each Rx that was created
            if ($item && ! $item['invoice_number'] && ! $item['pharmacy_name']) {

                $unique_patient_id = "{$item['first_name']} {$item['last_name']} {$item['birth_date']}";
                $rxs_created2[$unique_patient_id] = $item;
            }
        }
    }

    if ($rxs_created2) {
        foreach ($rxs_created2 as $unique_patient_id => $item) {
            $patient = load_full_patient($created, $mysql);
            $groups = group_drugs($order, $mysql);

            GPLog::warning('Needs Form Notice for Rx Created without Order', [
                'unique_patient_id' => $unique_patient_id,
                'patient' => $patient,
                'groups' => $groups,
                'item' => $item
            ]);

            needs_form_notice($groups);
        }
    }
    /* Finish Created Loop #2 */

    $sf_cache = [];
    $orders_updated = [];
    /*
     * Updated Loop
     */
    //Run this after rx_grouped query to ensure get_full_patient retrieves an accurate order profile
    $loop_timer = microtime(true);

    foreach ($changes['updated'] as $updated) {
        GPLog::$subroutine_id = "rxs-single-updated2-".sha1(serialize($updated));

        $changed = changed_fields($updated);
        $patient = getPatientByRx($updated['rx_number']);
        AuditLog::log(
            sprintf(
                "Rx# %s for %s updated: %s",
                $updated['rx_number'],
                $updated['drug_name'],
                implode(
                    ', ',
                    array_map(
                        function ($v, $k) {
                            return sprintf("%s='%s'", $k, $v);
                        },
                        $changed,
                        array_keys($changed)
                    )
                )
            ),
            $patient
        );

        GPLog::debug(
            "update_rxs_single2: rx updated $updated[drug_name] $updated[rx_number]",
            [
                'updated' => $updated,
                'changed' => $changed,
                'source'  => 'CarePoint',
                'type'    => 'rxs-single',
                'event'   => 'updated2'
            ]
        );

        if ($updated['rx_autofill'] != $updated['old_rx_autofill']) {
            $item = load_full_item($updated, $mysql, true);

            if (! $item['refills_used'] and $updated['rx_autofill']) {
                continue; //Don't log when a patient first registers
            }

            GPLog::debug(
                "update_rxs_single2: about to call export_cp_rx_autofill()",
                [
                    'item'    => $item,
                    'updated' => $updated,
                    'source'  => 'CarePoint',
                    'type'    => 'rxs-single',
                    'event'   => 'updated'
                ]
            );

            //We need this because even if we change all rxs at the same time, pharmacists
            //Wmight just switch one Rx in CP so we need this to maintain consistency
            export_cp_rx_autofill($item, $mssql);

            $status  = $updated['rx_autofill'] ? 'ON' : 'OFF';
            $body    = "$item[drug_name] autofill turned $status for $updated[rx_number]"; //Used as cache key
            $created = "Created:".date('Y-m-d H:i:s');

            AuditLog::log(
                sprintf(
                    "Autofill for #%s for %s changed to %s.  Updating all Rx's with
                     same GSN to be on/off Autofill.",
                    $updated['rx_number'],
                    $updated['drug_name'],
                    $updated['rx_autofill']
                ),
                $patient
            );

            GPLog::notice(
                "update_rxs_single2 rx_autofill changed.  Updating all Rx's with
                 same GSN to be on/off Autofill. Confirm correct updated rx_messages",
                [
                    'cache_key' => $body,
                    'sf_cache' => $sf_cache,
                    'updated' => $updated,
                    'changed' => $changed,
                    'item' => $item
                ]
            );

            if (! @$sf_cache[$body]) {
                $sf_cache[$body] = true; //This caches it by body and for this one run (currently 10mins)

                $salesforce = [
                  "subject"   => "Autofill turned $status for $updated[drug_name]",
                  "body"      => "$body $created",
                  "contact"   => "$item[first_name] $item[last_name] $item[birth_date]",
                  "assign_to" => null //required for Zapier to process
                ];

                $event_title = @$item['drug_name']." Autofill $status $salesforce[contact] $created";

                create_event($event_title, [$salesforce]);
            }
        }

        if ($updated['rx_gsn'] != $updated['old_rx_gsn']) {

            /*
                Missing GSNs are not included in Order Created Message (unless its a refill)
                so we need to send patient an update if/when the Rx is changed to have a GSN.
                There are also quite a few instances where the GSN wasn't missing but was wrong.
                Not sure what to do on these so leaving them out for now
            */
            //get full patient so we can send an order_update notice if needed
            $item = load_full_item($updated, $mysql, true);

            $invoice_number = $item['invoice_number'];

            if ($updated['old_rx_gsn'] == 0 and $invoice_number) {
                if (! isset($orders_updated[$invoice_number])) {
                    $orders_updated[$invoice_number] = [];
                }

                $orders_updated[$invoice_number][] = $item;
            }


            GPLog::warning(
                "update_rxs_single2 rx_gsn updated (after rxs_grouped)",
                [
                    'item'           => $item,
                    'orders_updated' => $orders_updated,
                    'updated'        => $updated,
                    'changed'        => $changed
                ]
            );

            //compliment method, update_rxs_single_drug, was already called before rx_grouped
            update_order_item_drug($mysql, $updated['rx_number']);
        }

        if ($updated['rx_transfer'] and ! $updated['old_rx_transfer']) {
            $item = load_full_item($updated, $mysql, true);
            $is_will_transfer = is_will_transfer($item);
            $was_transferred  = was_transferred($item);

            AuditLog::log(
                sprintf(
                    "Rx# %s for %s was marked to be transfered.  It %s be transfered because %s",
                    $updated['rx_number'],
                    $updated['drug_name'],
                    $was_transferred ? 'was' : ($is_will_transfer ? 'will' : 'will NOT'),
                    $item['rx_message_key']
                ),
                $patient
            );

            GPLog::warning(
                "update_rxs_single2 rx was transferred out.  Confirm correct is_will_transfer and was_transferred
                updated rxs_single.rx_message_key. rxs_grouped.rx_message_keys
                will be updated on next pass",
                [
                    'is_will_transfer' => $is_will_transfer,
                    'was_transferred'  => $was_transferred,
                    'item'             => $item,
                    'updated'          => $updated,
                    'changed'          => $changed
                ]
            );

            transfer_out_notice($item);
        }
    }

    if ($orders_updated) {
        foreach ($orders_updated as $invoice_number => $updates) {
            $order  = load_full_order(['invoice_number' => $invoice_number], $mysql);
            $groups = group_drugs($order, $mysql);

            foreach ($updates as $item) {
                $add_item_names[] = $item['drug'];
            }

            send_updated_order_communications($groups, $add_item_names, []);
        }
    }

    /**
     * All RX should have a rx_message set.  We are going to query the database
     * and look for any with a NULL rx_message_key.  If we find one, load_full_item()
     * should retry updating the message?
     *
     *  NOTE Using an INNER JOIN to exclude Rxs associated with patients that are inactive or deceased
     */

    /*
    $loop_timer = microtime(true);
    $rx_singles = $mysql->run("
      SELECT *
      FROM
        gp_rxs_single
      JOIN gp_patients ON
        gp_patients.patient_id_cp = gp_rxs_single.patient_id_cp
      WHERE
        rx_message_key IS NULL"
    )[0];

    foreach ($rx_singles as $rx_single) {

        GPLog::$subroutine_id = "rxs-single-null-message-".sha1(serialize($rx_single));

        //These should have been given an rx_message upon creation.  Why was it missing?
        GPLog::error(
            "rx had an empty message, so just set it.  Why was it missing?",
            [
              "patient_id_cp" => $rx_single['patient_id_cp'],
              "patient_id_wc" => $rx_single['patient_id_wc'],
              "rx_single"     => $rx_single,
              "source"        => "CarePoint",
              "type"          => "rxs-single",
              "event"         => "null-message"
            ]
        );

        //This will retry setting the rx_messages
        $item = load_full_item($rx_single, $mysql);
    }
    log_timer('rx-singles-empty-messages', $loop_timer, count($rx_singles));
    */

    GPLog::resetSubroutineId();

    //TODO if new Rx arrives and there is an active order where that Rx is not included because of "ACTION NO REFILLS" or "ACTION RX EXPIRED" or the like, then we should rerun the helper_days_and_message on the order_item

  //TODO Implement rx_status logic that was in MSSQL Query and Save in Database

  //TODO Maybe? Update Salesforce Objects using REST API or a MYSQL Zapier Integration

  //TODO THIS NEED TO BE UPDATED TO MYSQL AND TO INCREMENTAL BASED ON CHANGES

  //TODO Add Group by "Qty per Day" so its GROUP BY Pat Id, Drug Name,

  //TODO GCN Updates Here Should Update Any Order Item That is has not GCN match
}
