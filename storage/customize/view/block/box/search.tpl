       <div id="searchContainer">
	<div class="wrapper">
{capture assign="searchUrl"}{categoryUrl data=$category}{/capture}
		{form action="controller=category" class="quickSearch" handle=$form}
			{selectfield name="id" options=$categories}
			{textfield class="text searchQuery" name="q"}
			<input type="submit" class="submit" value="Search" />
			<input type="hidden" name="cathandle" value="search" />
		{/form}

	</div>
</div>