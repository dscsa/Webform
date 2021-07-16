<?php

namespace GoodPill\Events;

use GoodPill\Events\SalesforceComm;
use GoodPill\Events\EmailComm;
use GoodPill\Events\SmsComm;

/**
 * Events are things that happen in the system.  They are most frequently related to patients
 * doing things or order status chances
 */
abstract class Event
{

    /**
     * The type of event to publish
     * @var string
     */
    public $type;

    /**
     * The invoice number to associate to the event
     * @var int
     */
    public $invoice_number = '';

    /**
     * The Patient Label to associate with the event
     * @var string
     */
    public $patient_label  = '';

    /**
     * How many hours to wait before sending the messages
     * @var float
     */
    public $hours_to_wait = 0;

    /**
     * The hour of the day to send the messages
     * @var int
     */
    public $hour_of_day = null;

    /**
     * Create a comm calendar entry then post it to the calendar
     * @return void
     */
    public function publishEvent()
    {
        // Make sure we can create a title.
        $title = $this->getTitle();

        $comm_array = [];

        if ($email = $this->getEmail()) {
            $comm_array[] = $email->delivery();
        }

        if ($sms = $this->getSms()) {
            $comm_array[] = $sms->delivery();
        }

        if ($salesforce = $this->getSalesforce()) {
            $comm_array[] = $salesforce->delivery();
        }

        // TODO Replace this with a new object based Event
        print_r($comm_array);
        //create_event($title, $comm_array, $this->hours_to_wait, $this->hour_of_day);
    }

    /**
     * Get the title for the event.
     * @return string
     */
    public function getTitle()
    {
        if (!isset($this->type) && !isset($this->patient_label)) {
            throw new \Exception('You have to provide a title or a type and patient label');
        }

        return sprintf(
            '%s %s: %s. Created: %s',
            $this->invoice_number,
            $this->type,
            $this->patient_label,
            date('Y-m-d H:i:s')
        );
    }

    /**
     * This should be implemented on the specific event types. It will be called to start
     * the publish property.  It could be as simple as a wrapper for the publishEvent method
     */
    abstract public function publish() : void;

    /**
     * Is used to format and retrieve the SmsComm
     */
    abstract public function getSms() : ?SmsComm;

    /**
     * Is used to format and retrieve the EmailComm
     */
    abstract public function getEmail() : ?EmailComm;

    /**
     * Is used to format and retrieve the SalesforceComm
     */
    abstract public function getSalesforce() : ?SalesforceComm;
}
