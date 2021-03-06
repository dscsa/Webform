<?php

ini_set('memory_limit', '512M');
ini_set('include_path', '/goodpill/webform');
date_default_timezone_set('America/New_York');

require_once 'vendor/autoload.php';
require_once 'helpers/helper_laravel.php';
require_once 'keys.php';
require_once 'helpers/helper_calendar.php';

use Carbon\Carbon;
use GoodPill\Models\GpOrder;
use GoodPill\Logging\GPLog;
use GoodPill\Logging\CliLog;

//  @TODO - Confirm salesforce format for message

$three_days_ago = Carbon::now()->subDays(3)->toDateTimeString();
$thirty_days_ago = Carbon::now()->subDays(30)->toDateTimeString();

$shipped_orders = GpOrder::where('order_status', 'Shipped')
    ->whereNull('tracking_number')
    ->where('order_date_shipped', '<=', $three_days_ago)->get();

$dispensed_orders = GpOrder::where('order_status', 'Dispensed')
    ->where('order_date_dispensed', '<=', $three_days_ago)
    ->where('order_date_dispensed', '>', $thirty_days_ago)
    ->get(['invoice_number']);

CliLog::notice('Running check_order_status cron');
echo "Running check_order_status cron \n";

$count_shipped_orders = $shipped_orders->count();
$count_dispensed_orders = $dispensed_orders->count();

CliLog::notice("There are $count_shipped_orders orders that need tracking numbers");
CliLog::notice("There are $count_dispensed_orders orders that are dispensed but not shipped");

if ($shipped_orders->count() > 0) {
    $shipped_subject = 'Orders Shipped Missing Tracking Numbers';
    $shipped_body = 'The following orders are to be shipped but are missing tracking numbers after 3 days<br>';
    foreach ($shipped_orders as $order) {
        $shipped_body .= $order['invoice_number'].'<br>';
    }

    $salesforce = [
        "subject"   => $shipped_subject,
        "body"      => $shipped_body,
        "assign_to" => '.Testing',
    ];

    $message_as_string = implode('_', $salesforce);
    $notification = new \GoodPill\Notifications\Salesforce(sha1($message_as_string), $message_as_string);
    CliLog::notice("Send notification for shipped orders waiting tracking number");


    if (!$notification->isSent()) {
        GPLog::debug($shipped_subject, ['body' => $shipped_body]);

        create_event($shipped_subject, [$salesforce]);
    } else {
        GPLog::warning("DUPLICATE Saleforce Message".$shipped_subject, ['body' => $shipped_body]);
    }

    $notification->increment();
}

//  Handle Dispensed orders
if ($dispensed_orders->count() > 0) {
    $dispensed_subject = 'Orders Need To Be Dispensed';
    $dispensed_body = 'The following orders are dispensed but have not been shipped after 3 days<br>';
    foreach ($dispensed_orders as $order) {
        $dispensed_body .= $order['invoice_number'].'<br>';
    }

    $salesforce = [
        "subject"   => $dispensed_subject,
        "body"      => $dispensed_body,
        "assign_to" => '.Testing',
    ];

    $message_as_string = implode('_', $salesforce);
    $notification = new \GoodPill\Notifications\Salesforce(sha1($message_as_string), $message_as_string);
    CliLog::notice("Send notification for dispensed orders waiting shipment");

    if (!$notification->isSent()) {
        GPLog::debug($dispensed_subject, ['body' => $dispensed_body]);

        create_event($dispensed_subject, [$salesforce]);
    } else {
        GPLog::warning("DUPLICATE Saleforce Message".$dispensed_subject, ['body' => $dispensed_body]);
    }

    $notification->increment();
}
CliLog::notice("Finished check_order_status cron");