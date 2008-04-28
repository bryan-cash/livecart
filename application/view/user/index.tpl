<div class="userIndex">

{include file="user/layout.tpl"}

<div id="content" class="left right">

	<h1>{t _your_account} ({$user.fullName})</h1>

	{if $userConfirm}
	<div class="confirmationMsg">
		<div>{$userConfirm}</div>
	</div>
	{/if}

	{include file="user/userMenu.tpl" current="homeMenu"}

	<div id="userContent">

	<fieldset class="container" style="float: left; width: 100%;">

		{if $notes}
			<h2>{t _unread_msg}</h2>
			<ul class="notes">
				{foreach from=$notes item=note}
				   <a href="{link controller=user action=viewOrder id=`$note.orderID`}#msg">{t _order} #{$note.orderID}</a>
				   {include file="user/orderNote.tpl" note=$note}
				{/foreach}
			</ul>
		{/if}

		{if $files}
			<h2>{t _download_recent}</h2>

			{foreach from=$files item="item"}
				<h3>
					<a href="{link controller=user action=item id=$item.ID}">{$item.Product.name_lang}</a>
				</h3>
				{include file="user/fileList.tpl" item=$item}
				<div class="clear"></div>
			{/foreach}
		{/if}

		{if $orders}
			<h2>{t _recent_orders}</h2>
			{foreach from=$orders item="order"}
				{include file="user/orderEntry.tpl" order=$order}
			{/foreach}
		{else}
			<p>
				{t _no_orders_placed}
			</p>
		{/if}

	</fieldset>

	<div class="clear"></div>

	</div>

</div>

{include file="layout/frontend/footer.tpl"}

</div>