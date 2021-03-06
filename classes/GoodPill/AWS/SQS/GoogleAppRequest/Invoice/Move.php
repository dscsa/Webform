<?php

namespace  GoodPill\AWS\SQS\GoogleAppRequest\Invoice;

use GoodPill\AWS\SQS\GoogleAppRequest\HelperRequest;

/**
 * Base level class for all Google Doc requests
 */
class Move extends HelperRequest
{
    protected $properties = [
        'type',
        'method',
        'fileId',
        'folderId'
    ];

    protected $required = [
        'method',
        'fileId',
        'folderId'
    ];

    /**
     * Set the method to the default value for a delete request
     * @param string $requests  (Optional) The initial data
     */
    public function __construct($request = null)
    {
        $this->method = 'v2/moveFile';
        parent::__construct($request);
    }
}
