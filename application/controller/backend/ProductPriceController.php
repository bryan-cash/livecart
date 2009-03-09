<?php

ClassLoader::import("application.controller.backend.abstract.StoreManagementController");
ClassLoader::import("application.model.category.Category");
ClassLoader::import("application.model.product.Product");

/**
 * Controller for handling product based actions performed by store administrators
 *
 * @package application.controller.backend
 * @role product
 */
class ProductPriceController extends StoreManagementController
{
	public function index()
	{
		$this->locale->translationManager()->loadFile('backend/Product');
		$product = Product::getInstanceByID($this->request->get('id'), ActiveRecord::LOAD_DATA, ActiveRecord::LOAD_REFERENCES);

		$pricingForm = $this->buildPricingForm($product);

		$f = new ARSelectFilter(new NotEqualsCond(new ARFieldHandle('Currency', 'isDefault'), true));
		$f->setOrder(new ARFieldHandle('Currency', 'position'));
		$otherCurrencies = array();
		foreach (ActiveRecordModel::getRecordSetArray('Currency', $f) as $row)
		{
			$otherCurrencies[] = $row['ID'];
		}

		$response = new ActionResponse();
		$response->set("product", $product->toFlatArray());
		$response->set("otherCurrencies", $otherCurrencies);
		$response->set("baseCurrency", $this->application->getDefaultCurrency()->getID());
		$response->set("pricingForm", $pricingForm);

		// get user groups
		$f = new ARSelectFilter();
		$f->setOrder(new ARFieldHandle('UserGroup', 'name'));
		$groups[0] = $this->translate('_all_customers');
		foreach (ActiveRecordModel::getRecordSetArray('UserGroup', $f) as $group)
		{
			$groups[$group['ID']] = $group['name'];
		}
		$groups[''] = '';
		$response->set('userGroups', $groups);

		// all product prices in a separate array
		$prices = array();
		foreach ($product->getRelatedRecordSetArray('ProductPrice', new ARSelectFilter()) as $price)
		{
			$prices[$price['currencyID']] = $price;
		}

		$response->set('prices', $prices);

		return $response;
	}

	/**
	 * @role update
	 */
	public function save()
	{
		$product = Product::getInstanceByID((int)$this->request->get('id'), Product::LOAD_DATA, Product::LOAD_REFERENCES);

		$validator = $this->buildPricingFormValidator($product);
		if ($validator->isValid())
		{
			$product->loadSpecification();
			$product->loadPricing();

			if ($quantities = $this->request->get('quantityPricing'))
			{
				foreach ($product->getRelatedRecordSet('ProductPrice', new ARSelectFilter()) as $price)
				{
					$id = $price->currency->get()->getID();
					$prices = array();
					if (!empty($quantities[$id]))
					{
						$values = json_decode($quantities[$id], true);
						$prices = array();

						// no group selected - set all customers
						if ('' == $values['group'][0])
						{
							$values['group'][0] = 0;
						}

						$quantCount = count($values['quant']);
						foreach ($values['group'] as $groupIndex => $group)
						{
							foreach ($values['quant'] as $quantIndex => $quant)
							{
								$pr = $values['price'][($groupIndex * $quantCount) + $quantIndex];
								if (strlen($pr) != 0)
								{
									$prices[$quant][$group] = (float)$pr;
								}
							}
						}
					}

					ksort($prices);
					$price->serializedRules->set(serialize($prices));
					$price->save();
				}
			}

			$product->loadRequestData($this->request);
			$product->save();

			return new JSONResponse(array('prices' => $product->getPricesFields()), 'success', $this->translate('_product_prices_were_successfully_updated'));
		}
		else
		{
			return new JSONResponse(array('errors' => $validator->getErrorList()), 'failure', $this->translate('_product_prices_could_not_be_updated'));
		}
	}

	public function addShippingValidator(RequestValidator $validator)
	{
		// shipping related numeric field validations
		$validator->addCheck('shippingSurchargeAmount', new IsNumericCheck($this->translate('_err_surcharge_not_numeric')));
		$validator->addFilter('shippingSurchargeAmount', new NumericFilter());

		$validator->addCheck('minimumQuantity', new IsNumericCheck($this->translate('_err_quantity_not_numeric')));
		$validator->addCheck('minimumQuantity', new MinValueCheck($this->translate('_err_quantity_negative'), 0));
		$validator->addFilter('minimumQuantity', new NumericFilter());

		$validator->addFilter('shippingHiUnit', new NumericFilter());
		$validator->addCheck('shippingHiUnit', new IsNumericCheck($this->translate('_err_weight_not_numeric')));
		$validator->addCheck('shippingHiUnit', new MinValueCheck($this->translate('_err_weight_negative'), 0));

		$validator->addFilter('shippingLoUnit', new NumericFilter());
		$validator->addCheck('shippingLoUnit', new IsNumericCheck($this->translate('_err_weight_not_numeric')));
		$validator->addCheck('shippingLoUnit', new MinValueCheck($this->translate('_err_weight_negative'), 0));

		return $validator;
	}

	public function addPricesValidator(RequestValidator $validator)
	{
		// price in base currency
		$baseCurrency = $this->getApplication()->getDefaultCurrency()->getID();
		$validator->addCheck('price_' . $baseCurrency, new IsNotEmptyCheck($this->translate('_err_price_empty')));

		$currencies = $this->getApplication()->getCurrencyArray();
		foreach ($currencies as $currency)
		{
			$validator->addCheck('price_' . $currency, new IsNumericCheck($this->translate('_err_price_invalid')));
			$validator->addCheck('price_' . $currency, new MinValueCheck($this->translate('_err_price_negative'), 0));
			$validator->addCheck('listPrice_' . $currency, new MinValueCheck($this->translate('_err_price_negative'), 0));
			$validator->addFilter('price_' . $currency, new NumericFilter());
			$validator->addFilter('listPrice_' . $currency, new NumericFilter());
		}

		return $validator;
	}

	public function addInventoryValidator(RequestValidator $validator)
	{
		if ($this->config->get('INVENTORY_TRACKING') != 'DISABLE')
		{
			$validator->addCheck('stockCount', new IsNotEmptyCheck($this->translate('_err_stock_required')));
			$validator->addCheck('stockCount', new IsNumericCheck($this->translate('_err_stock_not_numeric')));
			$validator->addCheck('stockCount', new MinValueCheck($this->translate('_err_stock_negative'), 0));
		}

		$validator->addFilter('stockCount', new NumericFilter());

		return $validator;
	}

	private function buildPricingForm(Product $product)
	{
		if(!$product->isLoaded()) $product->load(ActiveRecord::LOAD_REFERENCES);

		$product->loadPricing();
		$pricing = $product->getPricingHandler();
		$form = new Form($this->buildPricingFormValidator());

		$pricesData = $product->toArray();
		$listPrices = $pricing->toArray(ProductPricing::DEFINED, ProductPricing::LIST_PRICE);
		$pricesData['shippingHiUnit'] = (int)$pricesData['shippingWeight'];
		$pricesData['shippingLoUnit'] = ($pricesData['shippingWeight'] - $pricesData['shippingHiUnit']) * 1000;

		foreach ($pricesData['calculated'] as $currency => $price)
		{
			$pricesData['price_' . $currency] = isset($pricesData['defined'][$currency]) ? $pricesData['defined'][$currency] : '';
		}

		foreach ($listPrices as $currency => $price)
		{
			$pricesData['listPrice_' . $currency] = $price;
		}

		$form->setData($pricesData);

		return $form;
	}

	private function buildPricingFormValidator()
	{
		$validator = $this->getValidator("pricingFormValidator", $this->request);

		self::addPricesValidator($validator);
		self::addShippingValidator($validator);
		self::addInventoryValidator($validator);

		if ($this->config->get('INVENTORY_TRACKING') != 'DISABLE')
		{
			$validator->addCheck('stockCount', new IsNotEmptyCheck($this->translate('_err_stock_required')));
		}

		return $validator;
	}

}
?>