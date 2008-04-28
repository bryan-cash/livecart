{loadJs form=true}

{include file="checkout/layout.tpl"}

<div id="content" class="left right orderIndex">

	<div class="checkoutHeader">
		<h1>{t _your_basket}</h1>

		{if $cart.cartItems}
			{include file="checkout/checkoutProgress.tpl" progress="progressCart" order=cart}
		{/if}
	</div>

	<div class="clear"></div>

	{*
	<p id="cartStats">
		{maketext text=_item_count params=$cart.basketCount}
	</p>
	*}

	{if !$cart.cartItems && !$cart.wishListItems}
		{t _empty_basket}. <a href="{link route=$return}">{t _continue_shopping}</a>.
	{else}

	{if $cart.cartItems}
		{include file="order/cartItems.tpl"}
	{/if}

	{if $cart.wishListItems && 'ENABLE_WISHLISTS'|config}
		{include file="order/wishList.tpl"}
	{/if}

	{/if}

	<div class="clear"></div>

</div>

<script type="text/javascript">
	new Order.OptionLoader($('cart'));
</script>

{include file="layout/frontend/footer.tpl"}