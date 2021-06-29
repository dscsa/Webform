<?php

use GoodPill\Models\GpOrder;

interface DaysMessageInterface {
    /**
     * Determines if we should not transfer out items
     * @return bool
     */
    public function isNoTransfer() : bool;

    /**
     * Determine if the item was manually added to an order
     * @return bool
     */
    public function isAddedManually() : bool;

    /**
     * Determine if this came from a webform source (erx, transfer, refill)
     * @return bool
     */
    public function isWebform() : bool;

    /**
     * Determine by the stock level if the item is offered or not
     * @return bool
     */
    public function isNotOffered() : bool;

    /**
     * Checks if this item happens to already be in an order under same name
     * Better named `isInOrderByDrugname`
     *
     * @param GpOrder $order
     * @return bool
     */
    public function isRefill(GpOrder $order) : bool;

    /**
     * Determine if this is a refill that we should try to fill
     * @return bool
     */
    public function isRefillOnly() : bool;

    /**
     * Was the rx properly parsed
     * @return bool
     */
    public function isNotRxParsed() : bool;
    //public function isDuplicateGsn() : bool;

    /**
     * @return mixed
     */
    public function getDaysLeftBeforeExpiration();

    /**
     * @return mixed
     */
    public function getDaysLeftInRefills();

    /**
     * @return mixed
     */
    public function getDaysLeftInStock();

    /**
     * @return mixed
     */
    public function getDaysDefault();

}