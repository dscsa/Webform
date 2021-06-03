<?php

require_once 'vendor/autoload.php';

use \PagerDuty\TriggerEvent;
use \PagerDuty\Exceptions\PagerDutyException;

const ADAM_API_KEY    = 'e3dc75a25b1b4d289a83a067a93f543e';
const PA_HIGH_API_KEY = 'b5d70a71999d499f8a801aa08ce96fda';
const PA_LOW_API_KEY  = '62696f7ac2854a8284231a0c655d6503';
const GUARDIAN_API_KEY = '34ee14abee6345fea5dd390fa062b526';

/**
 * Send an alert to just Adam
 * @param  string $message The message to display.
 * @param  string $id      A human readable id.  This id will be used to group
 *      messages, so it should unique for individual alerts.
 * @param  array  $data    Optional A small batch of additional details to
 *      be attached to the event.
 * @param  int    $level   Optional One of the leves available in the
 *      TriggerEvent class.
 * @return boolean  True if the message was succesfully sent
 */
function pd_alert_adam(
    string $message,
    string $id,
    ?array $data = [],
    ?int $level = TriggerEvent::ERROR
) : bool {
    $event = get_pd_event(
        $message,
        $id,
        ADAM_API_KEY,
        $data,
        $level
    );

    return pd_alert($event);
}

/**
 * Send an alert to the low urgency message service
 * @param  string $message The message to display.
 * @param  string $id      A human readable id.  This id will be used to group
 *      messages, so it should unique for individual alerts.
 * @param  array  $data    Optional. A small batch of additional details to
 *      be attached to the event.
 * @return boolean  True if the message was succesfully sent.
 */
function pd_low_priority(string $message, string $id, ?array $data = []) : bool
{

    $event = get_pd_event(
        $message,
        $id,
        PA_LOW_API_KEY,
        $data
    );

    return pd_alert($event);
}

/**
 * Send an alert to the high urgency message service
 * @param  string $message The message to display.
 * @param  string $id      A human readable id.  This id will be used to group
 *      messages, so it should unique for individual alerts.
 * @param  array  $data    Optional A small batch of additional details to
 *      be attached to the event.
 * @return boolean  True if the message was succesfully sent.
 */
function pd_high_priority(string $message, string $id, ?array $data = []) : bool
{
    $event = get_pd_event(
        $message,
        $id,
        PA_HIGH_API_KEY,
        $data
    );

    return pd_alert($event);
}

/**
 * Send an alert to the high urgency message service
 * @param  string $message The message to display.
 * @param  string $id      A human readable id.  This id will be used to group
 *      messages, so it should unique for individual alerts.
 * @param  array  $data    Optional. A small batch of additional details to
 *      be attached to the event.
 * @return boolean  True if the message was succesfully sent
 */
function pd_guardian(string $message, string $id, ?array $data = []) : bool
{
    $event = get_pd_event(
        $message,
        $id,
        GUARDIAN_API_KEY,
        $data,
        $id
    );

    return pd_alert($event);
}

/**
 * Send an alert to the high urgency message service
 * @param  string $message The message to display.
 * @param  string $id      A human readable id.  This id will be used to group
 *      messages, so it should unique for individual alerts.
 * @param  string $pdKey   The Pagerduty key for a specific service.
 * @param  array  $data    Optional. A small batch of additional details to
 *      be attached to the event.
 * @param  string $deDup   Optional. A key used to identify this event so
 *      duplicates will not trigger multiple alarms.
 * @param  int    $level   Optional. One of the levels specified on the TriggerEvent Class
 * @return TriggerEvent  A populated trigger event.
 */
function get_pd_event(
    string $message,
    string $id,
    string $pdKey,
    ?array $data = [],
    ?string $deDup = null,
    ?int $level = TriggerEvent::ERROR
) : TriggerEvent {

    $event = new TriggerEvent(
        $pdKey,
        $message,
        $id,
        $level,
        false
    );

    if (!is_null($deDup)) {
        $event->setDeDupKey(md5($deDup));
    } else {
        // Set the dedup key to repeat every 3 hours
        $event->setDeDupKey("md5-" . md5($message . date('z') . '-' . floor(date('G')/3)));
    }

    if (!empty($data)) {
        $event->setPayloadCustomDetails($data);
    }

    // Lets set a URL if we have one

    return $event;
}

/**
 * Send an alert to pagerduty
 * @param  TriggerEvent $event The pagerduty event to send.
 * @return boolean  True if the message was succesfully sent
 */
function pd_alert(TriggerEvent $event) : bool
{

    if (ENVIRONMENT != 'PRODUCTION') {
        return true;
    }

    try {
        $responseCode = $event->send();
        return ($responseCode == 200);
    } catch (PagerDutyException $exception) {
        return false;
    }
}
