{literal}
<script type="text/javascript">
    Backend.Product.Editor.prototype.links = {};
    Backend.Product.Editor.prototype.links.countTabsItems = '{/literal}{link controller=backend.product action=countTabsItems}{literal}';
</script>
{/literal}

<div>

<fieldset class="container">
	<ul class="menu">
		<li>
			<a href="#" onclick="Backend.Product.showAddForm(this.parentNode.parentNode.parentNode.parentNode, {$categoryID}); return false;">
				Add New Product
			</a>
			<span class="progressIndicator" style="display: none;"></span>
		</li>
	</ul>  
</fieldset>

<fieldset class="container" style="vertical-align: middle;">
    
    {form action="controller=backend.product action=processMass id=$categoryID" handle=$massForm style="vertical-align: middle;" onsubmit="return false;"}
    
    <input type="hidden" name="filters" value="" />
    <input type="hidden" name="selectedIDs" value="" />
    <input type="hidden" name="isInverse" value="" />
            
    <span id="productCount_{$categoryID}" style="margin-top: 15px; float: right;">
		<span class="rangeCount">Listing products %from - %to of %count</span>
		<span class="notFound">No products found</span>
	</span>
    
    <span style="float: left; text-align: right;" id="productMass_{$categoryID}">
        With selected: 
        <select name="act" class="select" style="width: auto;">
    
            <option value="enable_isEnabled">Enable</option>
            <option value="disable_isEnabled">Disable</option>
            <option value="delete">Delete</option>
                
            <option value="manufacturer">Set manufacturer</option>
            <option value="set_keywords">Set keywords</option>
            <option value="set_URL">Set website address</option>
                
            <optgroup label="Inventory & Pricing">
                <option value="inc_price">Increase price (percent)</option>
                <option value="inc_stock">Increase stock (count)</option>
    
                <option value="price">Set price ({$currency})</option>
                <option value="set_stockCount">Set stock</option>
            </optgroup>
                        
            <optgroup label="Shipping Options">
                <option value="set_minimumQuantity">Set minimum order quantity</option>
                <option value="set_shippingSurchargeAmount">Set shipping surcharge</option>
                <option value="enable_isFreeShipping">Enable free shipping</option>
                <option value="disable_isFreeShipping">Disable free shipping</option>
                <option value="enable_isBackOrderable">Enable back-ordering</option>
                <option value="disable_isBackOrderable">Disable back-ordering</option>
            </optgroup>
            
            <optgroup label="Set Attribute Value">
            
            </optgroup>
                            
            <optgroup label="Clear Attribute Value">
            
            </optgroup>
            
        </select>
        
        <span class="bulkValues" style="display: none;">
            {textfield class="text number" name="inc_price"}
            {textfield class="text number" name="inc_stock"}
            {textfield class="text number" name="set_stockCount"}
            {textfield class="text number" name="price"}  
   			{textfield name="manufacturer" class="text" autocomplete="controller=backend.manufacturer field=manufacturer" id="set_manufacturer_`$categoryID`"}
			{textfield name="set_keywords" class="text" id="set_keywords_`$categoryID`" autocomplete="controller=backend.product field=keywords"}
			{textfield name="set_URL" class="text" id="set_url_`$categoryID`" autocomplete="controller=backend.product field=URL"}
        </span>
        
        <input type="submit" value="{tn _process}" class="submit" />
        <span class="progressIndicator" style="display: none;"></span>
        
    </span>
    
    {/form}
    
</fieldset>

<div style="width: 100%; position: relative;">
<div style="display: none;" class="activeGrid_loadIndicator" id="productLoadIndicator_{$categoryID}">
	<div>
		{t Loading data...}<span class="progressIndicator"></span>
	</div>
</div>
<table class="productHead" id="products_{$categoryID}_header">
	<tr class="headRow">
		<th class="cell_cb"><input type="checkbox" class="checkbox" /></th>
		<th class="first cell_sku">
			<span class="fieldName">Product.sku</span>
			<input type="text" class="text" id="filter_Product.sku_{$categoryID}" value="{tn SKU}" />
		</th>
		<th class="cell_name">
            <span class="fieldName">Product.name</span>
    		<input type="text" class="text" id="filter_Product.name_{$categoryID}" value="{tn Name}" />                    
        </th>	
		<th class="cell_manuf">
            <span class="fieldName">Manufacturer.name</span>
    		<input type="text" class="text" id="filter_Manufacturer.name_{$categoryID}" value="{tn Manufacturer}" />  
        </th>	
		<th class="cell_price">
            <span class="fieldName">ProductPrice.price</span>
    		<input type="text" class="text" id="filter_ProductPrice.price_{$currency}" value="{tn Price} ({$currency})" />  			
        </th>
		<th class="cell_stock">
            <span class="fieldName">Product.stockCount</span>
    		<input type="text" class="text" id="filter_Product.stockCount_{$categoryID}" value="{tn In stock}" />   
        </th>	
		<th class="cell_enabled">
            <span class="fieldName">Product.isEnabled</span>
    		<select style="width: auto;" id="filter_Product.isEnabled_{$categoryID}">
				<option value="">{tn Enabled}</option>
				<option value="1">{tn _yes}</option>
				<option value="0">{tn _no}</option>
			</select>
        </th>	
	</tr>
</table>
</div>

<div style="width: 100%;">
<table class="activeGrid productList" id="products_{$categoryID}">
	<tbody>
		{include file="backend/product/productList.tpl"}
	</tbody>
</table>
</div>

</div>

{literal}
<script type="text/javascript">
    window.openProduct = function(id, e) 
    {
		if (window.opener && window.opener.selectProductPopup)
		{
			window.opener.selectProductPopup.getSelectedProduct(id);	
		}
		else
		{
			Backend.Product.Editor.prototype.setCurrentProductId(id); 
	        $('productIndicator_' + id).style.display = '';
			TabControl.prototype.getInstance('productManagerContainer', Backend.Product.Editor.prototype.craftProductUrl, Backend.Product.Editor.prototype.craftProductId, {
                afterClick: function()
                {
                    if(Backend.RelatedProduct.SelectProductPopup.prototype.popup) {
                        Backend.RelatedProduct.SelectProductPopup.prototype.popup.opener.focus();    
                        Backend.RelatedProduct.SelectProductPopup.prototype.popup.close();
                    }
                }
            }); 
	        if(Backend.Product.Editor.prototype.hasInstance(id)) 
			{
				Backend.Product.Editor.prototype.getInstance(id);			
			}			
		}
//        Event.stop(e);
    }

	var grid = new ActiveGrid($('products_{/literal}{$categoryID}'), '{link controller=backend.product action=lists}', {$totalCount});
	grid.setLoadIndicator($("productLoadIndicator_{$categoryID}"));
	
    new ActiveGridFilter($('filter_Product.sku_{$categoryID}'), grid);
    new ActiveGridFilter($('filter_Product.name_{$categoryID}'), grid);
    new ActiveGridFilter($('filter_Manufacturer.name_{$categoryID}'), grid);
    new ActiveGridFilter($('filter_ProductPrice.price_{$currency}'), grid);
    new ActiveGridFilter($('filter_Product.stockCount_{$categoryID}'), grid);
    
	new ActiveGridFilter($('filter_Product.isEnabled_{$categoryID}'), grid);
		    
    var massHandler = new Backend.Product.massActionHandler($('productMass_{$categoryID}'), grid);
    massHandler.deleteConfirmMessage = '{t _delete_conf|addslashes}' ;
</script>