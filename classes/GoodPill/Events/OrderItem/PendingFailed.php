<?php

namespace GoodPill\Events\OrderItem;

use GoodPill\Events\Event;
use GoodPill\Events\SalesforceComm;
use GoodPill\Events\EmailComm;
use GoodPill\Events\SmsComm;
use GoodPill\Models\GpOrderItem;
use GoodPill\Notifications\Salesforce;

/**
 * A Class to define the event when a CarepointPatientLabel has changed
 */
class PendingFailed extends Event
{
    /**
     * Hold the order_item for this event
     * @var \GoodPill\Models\GpOrderItem
     */
    protected $order_item;

    /**
     * Hold the patient for this event
     * @var \GoodPill\Models\GpPatient
     */
    protected $patient;

    /**
     * More data that will be added to the base patient data
     * @var array
     */
    protected $additional_data = [];

    /**
     * The type of event.  This is used for the event title
     * @var string
     */
    public $type;

    /**
     * Should be defined by Child class
     * @var string
     */
    protected $template_path = 'OrderItem/PendingFailed';

    /**
     * Make it so
     * @param GpOrderItem|null $order_item Optional. Will preset the order_item if passed.
     */
    public function __construct(?GpOrderItem $order_item = null)
    {
        $this->type = 'Pending Failed';

        if (!is_null($order_item)) {
            $this->setOrderItem($order_item);
        }
    }

    /**
     * Set the patient for this event
     * @param GpOrderItem $patient The patient.
     * @return void
     */
    public function setOrderItem(GpOrderItem $order_item) : void
    {
        $this->order_item = $order_item;
        $this->patient = $order_item->patient;
    }

    /**
     * An array of data to add to the data available to the templates
     * @param array $data Anything you would like to add.
     * @return void
     */
    public function setAdditionalData(array $data) : void
    {
        $this->additional_data = $data;
    }

    /**
     * The the data about a order_item so the template can be rendered
     * @return string
     */
    public function getDataArray() : array
    {
        $order_item = $this->$order_item;
        $data       = $order_item->toArray();

        if (!empty($this->additional_data)) {
            $data['additional'] = $this->additional_data;
        }

        return $data;
    }

    /**
     * Get the email portion of a communication
     * @return EmailComm
     */
    public function getEmail() : ?EmailComm
    {
        return null;
    }

    /**
     * Generate the SMS part of the communication Event
     * @return SmsComm
     */
    public function getSms() : ?SmsComm
    {
        return null;
    }

    /**
     * Generate the Salesforce portion of the Communication event
     * @return SalesforceComm
     */
    public function getSalesforce() : ?SalesforceComm
    {
        if (! $this->order_item instanceof GpOrderItem) {
            return null;
        }

        $salesforce            = new SalesforceComm();
        $salesforce->contact   = $this->patient->getPatientLabel();
        $salesforce->subject   = "Order Item failed to properly pend";
        $salesforce->body      = $this->render('salesforce');
        $salesforce->assign_to = '.Testing';

        $notification = new Salesforce(
            sha1(implode(',', $salesforce)),
            implode(',', $salesforce)
        );

        if ($notification->isSent()) {
            $notification->increment();
            return null;
        }

        $notification->increment();
        return $salesforce;
    }

    /**
     * Get the title for the event.
     * @return string
     */
    public function getTitle() : string
    {
        return sprintf(
            '%s: %s. Created: %s',
            $this->type,
            $this->patient->getPatientLabel(),
            date('Y-m-d H:i:s')
        );
    }

    /**
     * Render a template using the patient data
     * @param  string $template The template name to render.
     * @return string
     */
    protected function render(string $template) : string
    {
        $m = new \Mustache_Engine(array('entity_flags' => ENT_QUOTES));

        $template_path = TEMPLATE_DIR . "{$this->template_path}/{$template}.mustache";

        return $m->render(
            file_get_contents($template_path),
            $this->getDataArray()
        );
    }

    /**
     * Publish the events
     * Cancel the any events that are not longer needed and push this event to the com calendar
     */
    public function publish() : void
    {
        // Can't send notifications if the patient doesn't exist
        if (!$this->patient) {
             return;
        }

        $patient = $this->patient;
        $patient->createEvent($this);
    }
}
