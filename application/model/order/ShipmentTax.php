<?php

ClassLoader::import("application.model.order.Shipment");
ClassLoader::import("application.model.tax.TaxRate");

/**
 * Tax amount for a particular shipment. One shipment can have multiple taxes, depending on
 * how they are set up for a particular system.
 *
 * @package application.model.order
 * @author Integry Systems <http://integry.com>
 */
class ShipmentTax extends ActiveRecordModel
{
	const TYPE_SUBTOTAL = 1;

	const TYPE_SHIPPING = 2;

	public static function defineSchema($className = __CLASS__)
	{
		$schema = self::getSchemaInstance($className);
		$schema->setName(__class__);

		$schema->registerField(new ARPrimaryKeyField("ID", ARInteger::instance()));
		$schema->registerField(new ARForeignKeyField("taxRateID", "TaxRate", "ID", "TaxRate", ARInteger::instance()));
		$schema->registerField(new ARForeignKeyField("shipmentID", "Shipment", "ID", "Shipment", ARInteger::instance()));
		$schema->registerField(new ARField("type", ARInteger::instance(2)));
		$schema->registerField(new ARField("amount", ARFloat::instance()));
	}

	/**
	 * Create a new instance
	 *
	 * @return ShipmentTax
	 */
	public static function getNewInstance(TaxRate $taxRate, Shipment $shipment, $type)
	{
	  	$instance = ActiveRecordModel::getNewInstance(__CLASS__);
	  	$instance->taxRate->set($taxRate);
	  	$instance->shipment->set($shipment);
	  	$instance->recalculateAmount(null, $type);
	  	$instance->type->set($type);
	  	return $instance;
	}

	/**
	 * Recalculate tax amount
	 */
	public function recalculateAmount($recalculateShipping = true)
	{
		if (!$this->taxRate->get())
		{
			return $this->amount->get();
		}

		if ($recalculateShipping)
		{
			$this->shipment->get()->recalculateAmounts(false);
		}

		$this->shipment->get()->load();

		if (!$this->type->get())
		{
			$totalAmount = $this->shipment->get()->getTotalWithoutTax();
		}
		else if (self::TYPE_SUBTOTAL == $this->type->get())
		{
			$totalAmount = $this->shipment->get()->getSubTotalBeforeTax();
		}
		else if (self::TYPE_SHIPPING == $this->type->get())
		{
			$totalAmount = $this->shipment->get()->getShippingTotalBeforeTax();
		}

		$taxAmount = $totalAmount * ($this->taxRate->get()->rate->get() / 100);
		$this->amount->set($taxAmount);
	}

	public function getAmountByCurrency(Currency $currency)
	{
		$amountCurrency = $this->shipment->get()->amountCurrency->get();
		return $currency->convertAmount($amountCurrency, $this->amount->get());
	}

	public function isItemTax()
	{
		return self::TYPE_SUBTOTAL == $this->type->get();
	}

	public function toArray()
	{
		$array = parent::toArray();
		$array['formattedAmount'] = array();

		$amountCurrency = $this->shipment->get()->amountCurrency->get();
		$currencies = self::getApplication()->getCurrencySet();

		// get and format prices
		foreach ($currencies as $id => $currency)
		{
			$array['formattedAmount'][$id] = $currency->getFormattedPrice($this->getAmountByCurrency($currency));
		}

		return $array;
	}
}

?>