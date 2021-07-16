<?php

require_once 'helpers/helper_calendar.php';

use GoodPill\Logging\{
    GPLog,
    AuditLog,
    CliLog
};

use GoodPill\Models\GpOrder;
use GoodPill\Events\Order\Shipped;
use GoodPill\Events\Order\RefillReminder;

//Internal communication warning an order was shipped but not dispensed.  Gets erased when/if order is shipped
function order_dispensed_notice($groups)
{
    $days_ago = 2;

    $salesforce = [
        "subject" => 'Warning Order #'.$groups['ALL'][0]['invoice_number'].' dispensed but not shipped',
        "body" => "If shipped, please add tracking number to Guardian Order.  If not shipped, check comm-calendar and see if we need to inform patient that order was delayed or cancelled.",
        "contact" => $groups['ALL'][0]['first_name'].' '.$groups['ALL'][0]['last_name'].' '.$groups['ALL'][0]['birth_date'],
        "assign_to" => ".Expedite Order",
        "due_date" => date('Y-m-d')
    ];

    order_dispensed_event($groups['ALL'], $salesforce, $days_ago*24);
}

//We are coording patient communication via sms, calls, emails, & faxes
//by building commication arrays based on github.com/dscsa/communication-calendar
function order_shipped_notice($groups)
{

    // Create the event and send it as a test case
    $gpOrder = GpOrder::where('invoice_number', $groups['ALL'][0]['invoice_number'])->first();

    if ($gpOrder) {
        // $shipping_event = new Shipped($gpOrder);
        // $shipping_event->publishEvent();
    }

    $subject   = 'Good Pill shipped order '.($groups['ALL'][0]['count_filled'] ? 'of '.$groups['ALL'][0]['count_filled'].' items ' : '');
    $message   = '';

    $message .= '<br><u>These Rxs are on the way:</u><br>'.implode(';<br>', $groups['FILLED']).';';

    $email = [ "email" => $groups['ALL'][0]['email'] ];
    $text  = [ "sms"   => get_phones($groups['ALL']) ];

    $links = short_links(
        [
            'invoice'       => 'https://docs.google.com/document/d/'.$groups['ALL'][0]['invoice_doc_id'].'/pub?embedded=true',
            'tracking_url'  => tracking_url($groups['ALL'][0]['tracking_number'])
        ]
    );

    $text['message'] =
    $subject.
    $message.
    ' View it at '.$links['invoice'].'. '.
    'Track it at '.$links['tracking_url'].'. ';

    $email['subject'] = $subject;
    $email['message'] = implode(
        '<br>',
        [
            'Hello,',
            '',
            'Thanks for choosing Good Pill Pharmacy. '.$subject,
            '',
            'Your receipt for Order #'.$groups['ALL'][0]['invoice_number'].' is attached. Your tracking number is '.tracking_link($groups['ALL'][0]['tracking_number']), //<strong>#'.$groups['ALL'][0]['invoice_number'].'</strong> .$links['tracking_link'].'.',
            'Use this link to request delivery notifications and/or provide the courier specific delivery instructions.',
            $message,
            '',
            'Thanks!',
            'The Good Pill Team',
            '',
            ! count($groups['NOFILL_ACTION']) ? '' : '<br><u>We cannot fill these Rxs without your help:</u><br>'.implode(';<br>', $groups['NOFILL_ACTION']).';',
            ''
        ]
    );

    if ($groups['ALL'][0]['invoice_doc_id']) {
        $email['attachments'] = [$groups['ALL'][0]['invoice_doc_id']];
    }

    GPLog::info('order_shipped_notice', get_defined_vars());

    order_shipped_event($groups['ALL'], $email, $text);
}

function refill_reminder_notice($groups)
{
    $gpOrder = GpOrder::where('invoice_number', $groups['ALL'][0]['invoice_number'])->first();

    if ($gpOrder) {
        $days = $gpOrder->getDaysBeforeOutOfRefills();

        GPLog::notice("Comparing MIN_DAYS to getDaysBeforeOutOfRefills", [
            'groups' => $groups,
            'MIN_DAYS' => $groups['MIN_DAYS'],
            'days' => $days,
        ]);
        //  @TODO - Make live
        // $event = new RefillReminder($gpOrder, $groups['MIN_DAYS']*24, '11:00');
        // $event->publishEvent();
    }

    if ($groups['MIN_DAYS'] == 366 or (! count($groups['NO_REFILLS']) and ! count($groups['NO_AUTOFILL']))) {
        GPLog::notice("Not making a refill_reminder_notice", $groups);
        return;
    }

    $subject  = 'Good Pill cannot refill these Rxs without your help.';
    $message  = '';

    if (count($groups['NO_REFILLS'])) {
        $message .= '<br><u>We need a new Rx for the following:</u><br>'.implode(';<br>', $groups['NO_REFILLS']).';';
    }

    if (count($groups['NO_AUTOFILL'])) {
        $message .= '<br><br><u>These Rxs will NOT be filled automatically and must be requested 2 weeks in advance:</u><br>'.implode(';<br>', $groups['NO_AUTOFILL']).';';
    }

    $email = [ "email" => $groups['ALL'][0]['email'] ];
    $text  = [ "sms" => get_phones($groups['ALL']), "message" => $subject.$message ];

    $email['subject'] = $subject;
    $email['message'] = implode(
        '<br>',
        [
            'Hello,',
            '',
            'A friendly reminder that '.ucfirst($subject),
            $message,
            '',
            'Thanks!',
            'The Good Pill Team',
            '',
            ''
        ]
    );

    GPLog::warning("refill_reminder_notice is this right?", [$groups, $email]);
    refill_reminder_event($groups['ALL'], $email, $text, $groups['MIN_DAYS']*24, '11:00');
}

//Called from Webform so that we didn't have to repeat conditional logic
function autopay_reminder_notice($groups)
{
    $subject  = "Autopay Reminder.";
    $message  = "Because you are enrolled in autopay, Good Pill Pharmacy will be be billing your card ".implode(' <Pause />', str_split($groups['ALL'][0]['payment_card_last4'])).' for $'.$groups['ALL'][0]['payment_fee_default'].".00. Please let us right away if your card has recently changed. Again we will be billing your card for $".$groups['ALL'][0]['payment_fee_default'].".00 for last month's Order #".$groups['ALL'][0]['invoice_number']." of ".$groups['ALL'][0]['count_filled']." items";

    $email = [ "email" => $groups['ALL'][0]['email'] ];
    $text  = [ "sms" => get_phones($groups['ALL']), "message" => $subject.' '.$message ];

    $text['message'] = $subject.' '.$message;

    $email['subject'] = $subject;
    $email['message'] = implode(
        '<br>',
        [
            'Hello,',
            '',
            "Quick reminder that we are billing your card this week for last month's order.",
            $message,
            '',
            'Thanks!',
            'The Good Pill Team',
            '',
            ''
        ]
    );

    $next_month = strtotime('first day of +1 months');
    $time_wait  = $next_month - time();

    autopay_reminder_event($groups['ALL'], $email, $text, $time_wait/60/60, '14:00');
}

//We are coording patient communication via sms, calls, emails, & faxes
//by building commication arrays based on github.com/dscsa/communication-calendar
function order_created_notice($groups)
{
    $count     = count($groups['FILLED']) + count($groups['ADDED']);
    $subject   = "Good Pill is starting to prepare $count items for Order #{$groups['ALL'][0]['invoice_number']}.";
    $message   = 'If your address has recently changed please let us know right away.';
    $drug_list = '<br><br><u>These Rxs will be included once we confirm their availability:</u><br>';

    if (! $groups['ALL'][0]['refills_used']) {
        $message   .= ' Your first order will only be $6 total for all of your medications.';
        $drug_list .= implode(';<br>', array_merge($groups['FILLED_ACTION'], $groups['FILLED_NOACTION'], $groups['ADDED_NOACTION'])).';';
    } else {
        $drug_list .= implode(';<br>', array_merge($groups['FILLED_WITH_PRICES'], $groups['ADDED_WITH_PRICES'])).';';
    }

    $suffix = implode(
        '<br><br>',
        [
            "Note: if this is correct, there is no need to do anything.
            If you want to change or delay this order, please let us know as soon as
            possible. If delaying, please specify the date on which you want it filled,
            otherwise if you don't, we will delay it 3 weeks by default."
        ]
    );

    $email = [ "email" => $groups['ALL'][0]['email'] ];
    $text  = [ "sms" => get_phones($groups['ALL']), "message" => $subject.' '.$message.$drug_list ];

    $email['subject'] = $subject;
    $email['message'] = implode(
        '<br>',
        [
            'Hello,',
            '',
            $subject.' We will notify you again once it ships. '.$message.$drug_list,
            '',
            'Thanks for choosing Good Pill!',
            'The Good Pill Team',
            '',
            $suffix
        ]
    );

    //Remove Refill Reminders for new Rxs we just received Order #14512
    remove_drugs_from_refill_reminders($groups['ALL'][0]['first_name'], $groups['ALL'][0]['last_name'], $groups['ALL'][0]['birth_date'], $groups['FILLED']);

    //Wait 15 minutes to hopefully batch staggered surescripts and manual rx entry and cindy updates
    order_created_event($groups, $email, $text, DEFAULT_COM_WAIT);
}

function transfer_out_notice($item)
{
    $subject = "Good Pill transferred out your prescription.";
    $message = "{$item['drug']} was transferred to your backup pharmacy, {$item['pharmacy_name']} at {$item['pharmacy_address']}";

    $email = [ "email" => $item['email']];
    $text  = [ "sms" => get_phones([$item]), "message" => $subject.' '.$message ];

    $email['subject'] = $subject;
    $email['message'] = implode('<br>', [
        'Hello,',
        '',
        $subject,
        '',
        $message,
        '',
        'Thanks!',
        'The Good Pill Team'
    ]);

    //Wait 15 minutes to hopefully batch staggered surescripts and manual rx entry and cindy updates
    transfer_out_event([$item], $email, $text, DEFAULT_COM_WAIT);
}

function no_transfer_out_notice($item)
{
    $subject = "Good Pill cannot fill one of your Rxs at this time";

    if (patient_no_transfer($item)) {

        $message = "Unfortunately, {$item['drug_name']} is not offered at this time. Your account is set to NOT have your Rx(s) transferred out automatically. If you’d like to transfer your prescription(s) to your local pharmacy, please have them give us a call at (888) 987-5187, M-F 10am-6pm. Please note your local pharmacy will charge its usual price for this prescription, not the Good Pill price";

    } else if (is_not_offered($item)) { //High Drug price that is not offered (and patient has backup pharmacy) NOTE Not sure if this will ever trigger because currently drug needs to be Ordered to have a price

        $message = "Unfortunately, {$item['drug_name']} is not offered at this time. We didn't transfer the prescription automatically because of its high cost. If you’d like to transfer your prescription to your local pharmacy, please let us know (M-F 10am-6pm), and we will be happy to transfer them. Please note your local pharmacy will charge its usual price for this prescription, not the Good Pill price";

    } else { //High Drug price that is offered but is Out of Stock or Refill Only (and patient has backup pharmacy)

        $message = "Because we rely on donated medicine, we’re not able to onboard patients for {$item['drug_name']} at this time. We didn't transfer the prescription automatically because of its high cost. Let us know if you would like to be added to our waitlist! Being on our waitlist means that we may reach out in the future if the medication becomes available.  If you’d like to transfer your prescription to your local pharmacy, please let us know (M-F 10am-6pm) and we will be happy to transfer them. Please note your local pharmacy will charge its usual price for this prescription, not the Good Pill price";

    }


    $email = [ "email" => $item['email']];
    $text  = [ "sms" => get_phones([$item]), "message" => $message ];

    $email['subject'] = $subject;
    $email['message'] = implode('<br>', [
        'Hello,',
        '',
        $subject,
        '',
        $message,
        '',
        'Thanks!',
        'The Good Pill Team'
    ]);

    $created = "Created:".date('Y-m-d H:i:s');

    /*
        $salesforce = [
            "subject"   => "$item[drug_name] will not be transferred automatically because it is >=20/month or backup pharmacy is listed as GoodPill",
            "body"      => "Call patient to ask whether they want drug $item[drug_name] to be transferred out or not $created",
            "contact"   => "$item[first_name] $item[last_name] $item[birth_date]",
            "assign_to" => "Claire",
            "due_date"  => date('Y-m-d')
        ];
    */

    //Wait 15 minutes to hopefully batch staggered surescripts and manual rx entry and cindy updates
    no_transfer_out_event([$item], $email, $text, DEFAULT_COM_WAIT);
}

function transfer_requested_notice($groups)
{
    $subject = 'Good Pill received your transfer request for Order #'.$groups['ALL'][0]['invoice_number'].'.';
    $message = 'We will notify you once we have contacted your pharmacy, '.$groups['ALL'][0]['pharmacy_name'].' '.$groups['ALL'][0]['pharmacy_address'].', and let you know whether the transfer was successful or not;';

    $email = [ "email" => $groups['ALL'][0]['email'] ];
    $text  = [ "sms" => get_phones($groups['ALL']), "message" => $subject.' '.$message ];

    $email['subject'] = $subject;
    $email['message'] = implode('<br>', [
    'Hello,',
    '',
    $subject,
    '',
    $message,
    '',
    'Thanks!',
    'The Good Pill Team'
  ]);

    //Wait 15 minutes to hopefully batch staggered surescripts and manual rx entry and cindy updates
    transfer_requested_event($groups['ALL'], $email, $text, DEFAULT_COM_WAIT);
}

/* Not Currently Used! What could we use order_hold_notice for?
    - Automatically generated X days out for a no Rx order?
    - If an Order has count_fill == 0 and at least one drug has a missing GSN
    - Something about us still trying to get refills from the patient's doctor
*/
function order_hold_notice($groups)
{

    GPLog::critical('order_hold_notice: called', get_defined_vars());

    /*

    if ($groups['ALL'][0]['count_filled'] == 0 and $groups['ALL'][0]['count_nofill'] == 0) {
        //If patients have no Rxs on their profile then this will be empty.
        $subject = 'Good Pill has not yet gotten your prescriptions.';
        $message = 'We are still waiting on your doctor or pharmacy to send us your prescriptions';
    } else {
        $subject = 'Good Pill is NOT filling your '.$groups['ALL'][0]['count_nofill'].' items for Order #'.$groups['ALL'][0]['invoice_number'].'.';
        $message = '<u>We are NOT filling these Rxs:</u><br>'.implode(';<br>', array_merge($groups['NOFILL_NOACTION'], $groups['NOFILL_ACTION'])).';';
    }

    //[NULL, 'Webform Complete', 'Webform eRx', 'Webform Transfer', 'Auto Refill', '0 Refills', 'Webform Refill', 'eRx /w Note', 'Transfer /w Note', 'Refill w/ Note']
    //Unsure but "Null" seems to come from SureScript Orders OR Hand Entered Orders (Fax, Pharmacy Transfer, Hard Copy, Phone)
    //SELECT rx_source, MAX(gp_orders.invoice_number), COUNT(*) FROM `gp_orders` JOIN gp_order_items ON gp_order_items.invoice_number = gp_orders.invoice_number JOIN gp_rxs_single ON gp_rxs_single.rx_number = gp_order_items.rx_number WHERE order_source IS NULL GROUP BY rx_source ORDER BY `gp_orders`.`order_source` DESC
    $trigger = '';

    //Empty Rx Profile
    if (! $groups['ALL'][0]['count_nofill']) {
        $trigger = 'We got your Order but';
    }
    //AUTOREFILL
    elseif (is_auto_refill($groups['ALL'][0])) {
        $trigger = 'Your Rx is due for a refill but';
        GPLog::warning('order_hold_notice: Not filling Auto Refill?', $groups);
    }
    //TRANSFERS
    elseif (is_webform_transfer($groups['ALL'][0])) {
        $trigger = 'We received your transfer request but';
    } elseif ($groups['ALL'][0]['order_source'] == null and $groups['ALL'][0]['rx_source'] == 'Pharmacy') {
        $trigger = 'We received your transfer but';
    }

    //WEBFORM
    elseif (is_webform_erx($groups['ALL'][0])) {
        $trigger = 'You successfully registered but';
    } elseif (is_webform_refill($groups['ALL'][0])) {
        $trigger = 'We received your refill request but';
    }

    //DOCTOR
    elseif ($groups['ALL'][0]['order_source'] == null and in_array($groups['ALL'][0]['rx_source'], ["SureScripts", "Fax", "Phone"])) {
        $trigger = 'We got Rxs from your doctor via '.$groups['ALL'][0]['rx_source'].' but';
    }

    //HARDCOPY RX
    elseif ($groups['ALL'][0]['order_source'] == null and $groups['ALL'][0]['rx_source'] == 'Prescription') {
        $trigger = 'We got your Rx in the mail '.$groups['ALL'][0]['rx_source'].' but';
    } else {
        GPLog::warning('order_hold_notice: unknown order/rx_source', $groups);
    }

    $email = [ "email" => $groups['ALL'][0]['email'] ];
    $text  = [ "sms" => get_phones($groups['ALL']), "message" => $trigger.' '.$subject.' '.$message ];

    $email['subject'] = $subject;
    $email['message'] = implode('<br>', [
        'Hello,',
        '',
        $trigger.' '.$subject,
        '',
        $message,
        '',
        'Apologies for any inconvenience,',
        'The Good Pill Team',
        '',
        '',
        "Note: if this is correct, there is no need to do anything. If you think there is a mistake, please let us know as soon as possible."
    ]);

    $salesforce = true //! $missing_gsn
    ? ''
    : [
      "subject" => "Order #".$groups['ALL'][0]['invoice_number']." ON HOLD because of missing GSN",
      "body" => "Please change drug(s) ".implode(', ', $groups['FILLED_NOACTION']+$groups['NOFILL_NOACTION'])." in Order #".$groups['ALL'][0]['invoice_number']. " to be ones that have a GSN number and/or add those GSNs to V2",
      "contact" => $groups['ALL'][0]['first_name'].' '.$groups['ALL'][0]['last_name'].' '.$groups['ALL'][0]['birth_date'],
      "assign_to" => ".Add/Remove Drug - RPh",
      "due_date" => date('Y-m-d')
    ];


    GPLog::critical('order_hold_notice: unknown reason', get_defined_vars());

    //Wait 15 minutes to hopefully batch staggered surescripts and manual rx entry and cindy updates
    order_hold_event($groups['ALL'], $email, $text, $salesforce, DEFAULT_COM_WAIT);
    */
}

//We are coording patient communication via sms, calls, emails, & faxes
//by building commication arrays based on github.com/dscsa/communication-calendar
function order_updated_notice($groups, $patient_updates)
{
    $subject = 'Good Pill update for Order #'.$groups['ALL'][0]['invoice_number'];
    $updates = implode(' ', $patient_updates);

    if ($groups['ALL'][0]['count_filled'] and ! $groups['ALL'][0]['refills_used']) {
        $message = '<br><br><u>Your new order will be:</u><br>'.implode(';<br>', array_merge($groups['FILLED_ACTION'], $groups['FILLED_NOACTION'])).';';
    } elseif ($groups['ALL'][0]['count_filled']) {
        $message = '<br><br><u>Your new order will be:</u><br>'.implode(';<br>', $groups['FILLED_WITH_PRICES']).';';
    } else {
        GPLog::warning("order_updated_notice called but order_cancelled_notice should have been called instead", [$groups, $patient_updates]);
        return order_cancelled_notice($groups['ALL'][0], $groups);
    }

    $message .= '<br><br>We will notify you again once it ships.';

    $suffix = implode('<br><br>', [
        "Note: if this is correct, there is no need to do anything. If you want to change or delay this order, please let us know as soon as possible. If delaying, please specify the date on which you want it filled, otherwise if you don't, we will delay it 3 weeks by default."
    ]);

    $email = [ "email" => $groups['ALL'][0]['email']]; //$groups['ALL'][0]['email'] ];
    $text  = [ "sms"   => get_phones($groups['ALL']), "message" => "$subject: $updates $message"]; //get_phones($groups['ALL'])

    $email['subject'] = $subject;
    $email['message'] = implode('<br>', [
    'Hello,',
    '',
    "$subject: $updates",
    $message,
    '',
    ($groups['ALL'][0]['count_filled'] >= $groups['ALL'][0]['count_nofill']) ? 'Thanks for choosing Good Pill!' : 'Apologies for any inconvenience,',
    'The Good Pill Team',
    '',
    $suffix
  ]);

    //Wait 15 minutes to hopefully batch staggered surescripts and manual rx entry and cindy updates
    order_updated_event($groups, $email, $text, DEFAULT_COM_WAIT);
}

function needs_form_notice($groups)
{

    $eligible_state = in_array($groups['ALL'][0]['patient_state'], ['TN', 'FL', 'NC', 'SC', 'AL', 'GA', NULL, '']);

    $salesforce = '';

    if ($groups['NOFILL_ACTION']) {
        $subject = 'Welcome to Good Pill!  We are excited to fill your prescriptions.';
        $message = 'Your first order, #'.$groups['ALL'][0]['invoice_number'].", will cost $6, paid after you receive your medications. Please take 5mins to register so that we can fill the Rxs we got from your doctor as soon as possible. Once you register it will take 5-7 business days before you receive your order. You can register online at www.goodpill.org or by calling us at (888) 987-5187.<br><br><u>The drugs in your first order will be:</u><br>".implode(';<br>', $groups['NOFILL_ACTION']).';';

        if ($eligible_state)
            $salesforce = [
              "subject"   => "Call to Register Patient",
              "body"      => "Call to Register Patient
              -If pt does not have a backup pharmacy in Salesforce, call them to register.
              -Attempt 2 calls/voicemails over 2 days. Even if the phone number is invalid, attempt a 2nd call.
              -After 2 failed attempts, reassign this task to .Flag Clinic/Provider Issue - Admin.
              -Provider info: {$groups['ALL'][0]['provider_first_name']} {$groups['ALL'][0]['provider_last_name']}, {$groups['ALL'][0]['provider_clinic']}, {$groups['ALL'][0]['provider_phone']}
              *** Once pt has registered, make sure an order has been created ***",
              "assign_to" => ".Missing Contact Info"
            ];

        $email = [ "email" => $groups['ALL'][0]['email'] ];
        $text  = [ "sms"   => get_phones($groups['ALL']), "message" => $subject.' '.$message ];

        $email['subject'] = $subject;
        $email['message'] = implode('<br>', [
            'Hello,',
            '',
            $subject.' '.$message,
            '',
            'Thanks!',
            'The Good Pill Team',
            '',
            ''
        ]);
        //log_error("NEEDS FORM NOTICE DOES NOT HAVE DRUGS LISTED", [$groups, $message, $subject]);
    } else {
        //log_error('NEEDS_FORM HOLD.  IS THIS EVER CALLED OR DOES IT GOTO ORDER_HOLD TEMPLATE', $groups);
        $email = null;
        $text = null;

        if ($eligible_state)
            $salesforce = [
              "subject"   => "Can't complete your 1st Order",
              "body"      => "Can't complete your 1st Order
              New Rx(s) of ".implode(';<br>', $groups['NOFILL_NOACTION'])." sent for unregistered patient. Please see if we these items are on our clinical formulary and if so, fill order and purchase meds if necessary.  If not, create task to call patient to inform them that we are unable to fill these drugs and ask if they would like us to transfer their Rx(s) their local pharmacy. Because we rely on donated medicine, we can only fill medications that are listed on www.goodpill.org\n\nPlease inform the patient that since drug pricing differs by pharmacy, they will be charged their local pharmacy's price if the drug is transferred.",
              "assign_to" => ".Inventory Issue"
            ];
    }

    //By basing on added at, we remove uncertainty of when script was run relative to the order being added
    $hour_added = substr($groups['ALL'][0]['order_date_added'], 11, 2); //get hours

    if ($hour_added < 10) {
      //A if before 10am, the first one is at 10am, the next one is 5pm, then 10am tomorrow, then 5pm tomorrow
      $hours_to_wait = [0, 0, 24, 24, 24*7, 24*14];
      $hour_of_day   = ['11:00', '17:00', '11:00', '17:00', '17:00', '17:00'];
    } elseif ($hour_added < 17) {
      //A if before 5pm, the first one is 10mins from now, the next one is 5pm, then 10am tomorrow, then 5pm tomorrow
      $hours_to_wait = [10/60, 0, 24, 24, 24*7, 24*14];
      $hour_of_day   = [0, '17:00', '11:00', '17:00', '17:00', '17:00'];
    } else {
      //B if after 5pm, the first one is 10am tomorrow, 5pm tomorrow, 10am the day after tomorrow, 5pm day after tomorrow.
      $hours_to_wait = [24, 24, 48, 48, 24*7, 24*14];
      $hour_of_day   = ['11:00', '17:00', '11:00', '17:00', '17:00', '17:00'];
    }

    $date = "Created:".date('Y-m-d H:i:s');

    if ($salesforce) {
        $salesforce["body"]    .= "\n\n$date";
        $salesforce["contact"]  = "{$groups['ALL'][0]['first_name']} {$groups['ALL'][0]['last_name']} {$groups['ALL'][0]['birth_date']}";
        $salesforce["due_date"] = substr(get_start_time($hours_to_wait[3], $hour_of_day[3]), 0, 10);
    }

    GPLog::notice("needs_form_notice is this right?", [$groups, $email, $salesforce]);

    $cancel = cancel_events_by_person($groups['ALL'][0]['first_name'], $groups['ALL'][0]['last_name'], $groups['ALL'][0]['birth_date'], 'needs_form_event', ['Needs Form']);

    needs_form_event($groups['ALL'], $email, $text, $salesforce, $hours_to_wait[0], $hour_of_day[0]);

    if (! $groups['NOFILL_ACTION']) {
        return;
    } //Don't hassle folks if we aren't filling anything

    needs_form_event($groups['ALL'], $email, $text, null, $hours_to_wait[1], $hour_of_day[1]);
    needs_form_event($groups['ALL'], $email, $text, null, $hours_to_wait[2], $hour_of_day[2]);
    needs_form_event($groups['ALL'], $email, $text, null, $hours_to_wait[3], $hour_of_day[3]);
}

//We are coording patient communication via sms, calls, emails, & faxes
//by building commication arrays based on github.com/dscsa/communication-calendar
function no_rx_notice($partial, $groups)
{
    $subject = "Good Pill received Order #$partial[invoice_number] but is waiting for your prescriptions";
    $message  = is_webform_transfer($partial)
    ? "We will attempt to transfer the Rxs you requested from your pharmacy."
    : "We haven't gotten any Rxs from your doctor yet but will notify you as soon as we do.";

    $email = [ "email" => DEBUG_EMAIL]; //$groups['ALL'][0]['email'] ];
    $text  = [ "sms"   => DEBUG_PHONE, "message" => $subject.'. '.$message ]; //get_phones($groups['ALL'])

    $email['subject'] = $subject;
    $email['message']  = implode('<br>', [
    'Hello,',
    '',
    $subject.'. '.$message,
    '',
    'Thanks,',
    'The Good Pill Team',
    ''
  ]);

    //Wait 15 minutes to hopefully batch staggered surescripts and manual rx entry and cindy updates
    no_rx_event($partial, $groups['ALL'], $email, $text, DEFAULT_COM_WAIT);
}

function order_cancelled_notice($partial, $groups)
{

    if (empty($groups)) {
        return GPLog::notice(
            'order_cancelled_notice: There were not groups, so there is nobody to notify',
            get_defined_vars()
        );
    }

    if ( ! $groups['ALL'][0]['pharmacy_name']) {
        return GPLog::critical(
            'order_cancelled_notice: not sending because needs_form_notice '
            . 'should be sent instead (was already sent?)',
            get_defined_vars()
        );
    }

    if ( ! $groups['ALL'][0]['count_nofill']) { //can be passed a patient
        return GPLog::critical(
            'order_cancelled_notice: not sending because no_rx_notice should '
            . 'be sent instead (was already sent?)',
            get_defined_vars()
        );
    }

    // If we find anything that has autofill enabled, we should send a reschedule
    // instead of a cancel notification
    if (count($groups['AUTOFILL_ON']) > 0) {
        //TODO Add a return after these are approved
        order_rescheduled_notice($partial, $groups);
    }

    $subject = "Order #{$partial['invoice_number']} has been cancelled";

    if (is_webform_transfer($partial)) {
        $message = "Good Pill attempted to transfer prescriptions from {$groups['ALL'][0]['pharmacy_name']} but they did not have an Rx for the requested drugs with refills remaining.  Could you please let us know your doctor's name and phone number so that we can reach out to them to get new prescription(s)";
    } else {
        $drug_list = implode(';<br>', array_merge($groups['NOFILL_NOACTION'], $groups['NOFILL_ACTION']));
        $drug_list = str_replace('is being', 'was', $drug_list); //hacky way to change verb tense
        $message   = "<u>Good Pill is NOT filling:</u><br>$drug_list;";
        $message  .= "<br><br>If you believe this cancellation was in error, call us (888) 987-5187";
    }

    $email = [ "email" => $groups['ALL'][0]['email']]; //$groups['ALL'][0]['email'] ];
    $text  = [ "sms"   => get_phones($groups['ALL']), "message" => $subject.'. '.$message ]; //get_phones($groups['ALL'])

    $email['subject'] = $subject;
    $email['message'] = implode('<br>', [
        'Hello,',
        '',
        $subject.'. '.$message,
        '',
        'Thanks!',
        'The Good Pill Team',
        '',
        ''
    ]);

    GPLog::notice(
        'order_cancelled_notice: how to improve this message',
        get_defined_vars()
    );

    order_cancelled_event($partial, $groups['ALL'], $email, $text, DEFAULT_COM_WAIT);
}

function order_rescheduled_notice($partial, $groups)
{

    $cancelled_string   = implode(";<br>\n", $groups['AUTOFILL_OFF']);
    $rescheduled_string = implode(";<br>\n", $groups['AUTOFILL_ON']);
    $subject            = "Order #{$partial['invoice_number']} has been ";
    $message            = "";

    if ($rescheduled_string) {
        $subject       .= "rescheduled";
        $message       .= "<u>The Rxs below were rescheduled to:</u><br>\n{$rescheduled_string}";
    } else {
        $subject       .= "cancelled";
    }

    if ($cancelled_string) {
        $message       .= "<u>Good Pill is NOT filling:</u><br>\n{$cancelled_string};<br>\n";
    }

    $message           .= "<br><br>\n\nIf you believe this change was in error, call us (888) 987-5187";
    $email = [ "email" => DEBUG_EMAIL]; //$groups['ALL'][0]['email'] ];
    $text  = [ "sms"   => DEBUG_PHONE, "message" => $subject.'. '.$message ]; //get_phones($groups['ALL'])

    $email['subject'] = $subject;
    $email['message'] = implode("<br>\n", [
        'Hello,',
        '',
        $subject.'. '.$message,
        '',
        'Thanks!',
        'The Good Pill Team',
        '',
        ''
    ]);

    order_rescheduled_event($partial, $groups['ALL'], $email, $text, DEFAULT_COM_WAIT);
}

function confirm_shipment_notice($groups)
{
    $days_ago = 7;

    //Existing customer just tell them it was delivered
    $comms = confirm_shipment_external($groups);

    //New customer tell them it was delivered and followup with a call
    $salesforce = confirm_shipment_internal($groups, $days_ago+1);

    confirm_shipment_event(
        $groups['ALL'],
        $comms['email'],
        $comms['text'],
        $salesforce,
        $days_ago*24,
        '11:30'
    );
}

function confirm_shipment_internal($groups, $days_ago)
{

  //TODO is this is a double check to see if past orders is 100% correlated with refills_used,
    //if not, we need to understand root cause of discrepancy and which one we want to use going foward
    //and to be consistent, remove the other property so that its not mis-used.
    $mysql = GoodPill\Storage\Goodpill::getConnection();
    $pdo   = $mysql->prepare(
        "SELECT count(*) as past_order_count
        	     FROM gp_orders o
        	        JOIN gp_orders p ON o.patient_id_cp = p.patient_id_cp
                        AND p.invoice_number = :invoice_number
        	     WHERE o.invoice_number != :invoice_number
        		       AND (
                            o.order_stage_cp = 'Shipped'
                            or o.order_stage_cp = 'Dispensed'
                        );"
    );

    $pdo->bindParam(':invoice_number', $groups['ALL'][0]['invoice_number'], \PDO::PARAM_INT);
    $pdo->execute();

    $results = $pdo->fetch();

    if (((float) $groups['ALL'][0]['refills_used'] > 0) != ((float) $results['past_order_count'] > 0)) {
        GPLog::critical(
            'Past Orders <> Refills Used',
            [
                'past_order_count' => $results['past_order_count'],
                'groups'           => $groups,
                'refills_used'     => $groups['ALL'][0]['refills_used'],
                'invoice_number'   => $groups['ALL'][0]['invoice_number'],
                'todo'             => "TODO is this is a double check to see if past orders is 100% correlated with refills_used,
                 if not, we need to understand root cause of discrepancy and which one we want to use going foward
                 and to be consistent, remove the other property so that its not mis-used."
             ]
        );
    }

    if ((float) $groups['ALL'][0]['refills_used'] > 0) {
        return [];
    }

    ///It's depressing to get updates if nothing is being filled
    $subject  = "Follow up on new patient's first order";

    $salesforce = [
    "contact" => $groups['ALL'][0]['first_name'].' '.$groups['ALL'][0]['last_name'].' '.$groups['ALL'][0]['birth_date'],
    //"assign_to" => ".Patient Call",
    //"due_date" => substr(get_start_time($days_ago*24), 0, 10),
    "subject" => $subject,
    "body" =>  implode('<br>', [
      'Hello,',
      '',
      $groups['ALL'][0]['first_name'].' '.$groups['ALL'][0]['last_name'].' '.$groups['ALL'][0]['birth_date'].' is a new patient.  They were shipped Order #'.$groups['ALL'][0]['invoice_number'].' with '.$groups['ALL'][0]['count_filled'].' items '.$days_ago.' days ago.',
      '',
      'Please call them at '.$groups['ALL'][0]['phone1'].', '.$groups['ALL'][0]['phone2'].' and check on the following:',
      '- Order with tracking number '.tracking_link($groups['ALL'][0]['tracking_number']).' was delivered and that they received it',
      '',
      '- Make sure they got all '.$groups['ALL'][0]['count_filled'].' of their medications, that we filled the correct number of pills, and answer any questions the patient has',
      $groups['ALL'][0]['count_nofill'] ? '<br>- Explain why we did NOT fill:<br>'.implode(';<br>', array_merge($groups['NOFILL_NOACTION'], $groups['NOFILL_ACTION'])).'<br>' : '',
      '- Let them know they are currently set to pay via '.$groups['ALL'][0]['payment_method'].' and the cost of the '.$groups['ALL'][0]['count_filled'].' items was $'.$groups['ALL'][0]['payment_fee_default'].' this time, but next time it will be $'.$groups['ALL'][0]['payment_total_default'],
      '',
      '- Review their current medication list and remind them which prescriptions we will be filling automatically and which ones they need to request 2 weeks in advance',
      '',
      'Thanks!',
      'The Good Pill Team',
      '',
      ''
    ])
  ];

    GPLog::debug(
        'Attaching Newpatient Com Event',
        [
         "initial_rx_group" => $groups['ALL'][0]
      ]
    );

    return $salesforce;
}

function confirm_shipment_external($groups)
{
    $email = [ "email" => $groups['ALL'][0]['email'] ];
    $text  = [ "sms"   => get_phones($groups['ALL']) ];
    $call  = [ "call"  => $text['sms']];

    $subject = "Order #".$groups['ALL'][0]['invoice_number']." was shipped last week and should have arrived";

    $text['message'] = $subject;

    $call['message'] =  call_wrapper(format_call("$subject."));

    $email['subject'] = $subject;
    $email['message'] = implode('<br>', [
    'Hello,',
    '',
    "$subject.",
    '',
    "If you have NOT received your order, please reply back to this email or call us at 888-987-5187 so we can help.",
    '',
    'Thanks!',
    'The Good Pill Team',
    '',
    ''
  ]);

    $text['fallbacks'] = [$call];

    return ['email' => $email, 'text' => $text];
}
