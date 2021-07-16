<?php

namespace GoodPill\Events\Order;

use GoodPill\Events\Event;
use GoodPill\Events\SalesforceComm;
use GoodPill\Events\EmailComm;
use GoodPill\Events\SmsComm;
use GoodPill\Models\GpOrder;

abstract class OrderEvent extends Event
{
    /**
     * Hold the order for this event
     * @var GpOrder
     */
    protected $order;

    /**
     * Hold the order data that is used to render the messages
     * @var array
     */
    protected $order_data;

    /**
     * The type of event.  This is used for the event title
     * @var string
     */
    public $type;

    /**
     * Should be defined by Child class
     * @var string
     */
    protected $template_path;

    /**
     * Make it so
     * @param GpOrder|null $order (Optional)  Will preset the order if passed
     */

    public function __construct(?GpOrder $order = null)
    {
        if (!is_null($order)) {
            $this->setOrder($order);
        }
    }

    /**
     * Set the order for this event
     * @param GpOrder $order The order
     */
    public function setOrder(GpOrder $order)
    {
        $this->order = $order;
    }

    /**
     * Create the json object needed for rendering the templates
     * Sample:
     *    {
     *         "first_name": "Ben",
     *         "last_name": "Brown",
     *         "invoice_number": 12345,
     *         "tracking_number": "1234123124123",
     *         "tracking_link": "http://www.gmail.com",
     *         "invoice_link": "http://www.gmail.com",
     *         "count_filled": 1,
     *         "multiple_filled": true,
     *         "filled": {
     *             "rxs": [
     *                 {"name": "drug 1"},
     *                 {"name": "drug 2"}
     *             ]
     *         },
     *         "no_fill": {
     *             "rxs": [
     *                 {"name": "no drug 1"},
     *                 {"name": "no drug 2"}
     *             ]
     *         }
     *    }
     * @return array
     */
    public function getOrderData(): array
    {
        if (!isset($this->order_data)) {
            $order = $this->order;

            $data = [
                "first_name"         => $order->patient->first_name,
                "last_name"          => $order->patient->last_name,
                "birth_date"         => $order->patient->birth_date,
                "invoice_number"     => $order->invoice_number,
                "count_filled"       => $order->count_filled,
                "multiple_filled"    => (int) ($order->count_filled > 1),
                "tracking_number"    => $order->tracking_number,
                "tracking_url"       => $order->getTrackingUrl(),
                "short_tracking_url" => $order->getTrackingUrl(true),
                "invoice_url"        => $order->getInvoiceUrl(),
                "short_invoice_url"  => $order->getInvoiceUrl(true)
            ];

            if ($order->count_filled > 0) {
                $rxs = [];

                $filled_items = $this->order->getFilledItems();

                $filled_items->each(
                    function ($order_item) use (&$rxs) {
                        $rxs[] = ["name" => $order_item->getDrugName()];
                    }
                );

                $data['filled'] = ['rxs' => $rxs];
            }

            if ($order->count_nofill) {
                $rxs = [];

                $nofill_items = $this->order->getFilledItems(false);

                $nofill_items->each(
                    function ($order_item) use (&$rxs) {
                        $rxs[] = ["name" => $order_item->getDrugName()];
                    }
                );

                $data['no_fill'] = ['rxs' => $rxs];
            }

            /*************** Refill Reminder Data ******************/
            $no_refills = $this->order->getItemsWithNoRefills();
            $no_autofill = $this->order->getItemsWithNoAutofills();

            if ($no_autofill->count() > 0) {
                $rxs = [];

                $no_autofill->each(function($item) use (&$rxs) {
                    $rxs[] = [
                        'name' => $item->getDrugName(),
                        'patient_message_text' => $item->getPatientMessageText(),
                        'rx_number' => $item->rx_number,

                    ];
                });

               $data['no_autofill'] = ['rxs' => $rxs];
            }

            if ($no_refills->count() > 0) {
                $rxs = [];

                $no_refills->each(function($item) use (&$rxs) {
                    $rxs[] = [
                        'name' => $item->getDrugName(),
                        'patient_message_text' => $item->getPatientMessageText(),
                        'rx_number' => $item->rx_number,
                    ];
                });

                $data['no_refill'] = ['rxs' => $rxs];
            }

            /********************* Order Created Data *****************/
            $rxs = [];

            if ($this->order->items->count() > 0)
            {
                $this->order->items->each(function($item) use (&$rxs) {
                    $rxs[] = [
                        "name" => $item->getDrugName().' - '.$item->rxs->rx_message_text,
                        "price_dispensed" => $item->price_dispensed,
                        "days_dispensed" => $item->days_dispensed,
                    ];
                });
                $this->order_data['ordered_items'] = $rxs;
            }

            $this->order_data = $data;
        }

        return $this->order_data;
    }

    /**
     * Get the email portion of a communication
     * @return EmailComm
     */
    public function getEmail() : ?EmailComm
    {
        // if we don't have an email address we can't send an email
        if (empty($this->order->patient->email)) {
            return null;
        }
        $email              = new EmailComm();
        $email->subject     = $this->render('email_subject');
        $email->message     = $this->render('email');
        $email->attachments = [$this->order->invoice_doc_id];
        $email->email       = DEBUG_EMAIL;
        //$email->email   = $this->order->patient->email;

        return $email;
    }

    /**
     * Generate the SMS part of the communication Event
     * @return SmsComm
     */
    public function getSms() : ?SmsComm
    {
        // if we don't have an sms numbers
        if (empty($this->order->patient->getPhonesAsString())) {
            return null;
        }

        $patient = $this->order->patient;
        $sms          = new SmsComm();
        // $sms->sms     = $patient->getPhonesAsString();
        $sms->sms     = DEBUG_PHONE;
        $sms->message = $this->render('sms');
        return $sms;
    }

    /**
     * Generate the Salesforce portion of the Communication event
     * @return SalesforceComm
     */
    public function getSalesforce() : ?SalesforceComm
    {
        $patient             = $this->order->patient;
        $salesforce          = new SalesforceComm();
        $salesforce->contact = $patient->getPatientLabel();
        $salesforce->subject = "Auto Email/Text " . $this->render('email_subject');
        $salesforce->body    = $this->render('sms');
        return $salesforce;
    }

    /**
     * Get the title for the event.
     * @return string
     */
    public function getTitle()
    {
        return sprintf(
            '%s %s: %s. Created: %s',
            $this->order->invoice_number,
            $this->type,
            $this->order->patient->getPatientLabel(),
            date('Y-m-d H:i:s')
        );
    }

    /**
     * Render a template using the order data
     * @param  string $template The template name to render
     * @return string
     */
    protected function render($template)
    {
        $m = new \Mustache_Engine(array('entity_flags' => ENT_QUOTES));

        $template_path = TEMPLATE_DIR . "{$this->template_path}/{$template}.mustache";
        return $m->render(
            file_get_contents($template_path),
            $this->getOrderData()
        );
    }
}
