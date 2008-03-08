{defun name="categoryTree" node=false filters=false}
	{if $node}
		{foreach from=$node item=category}{if $category.ID == $currentId}<li class="current"><span class="currentName">{$category.name_lang}</span>{else}<li><a href="{categoryUrl data=$category filters=$category.filters}">{$category.name_lang}</a>{/if}{if 'DISPLAY_NUM_CAT'|config}<span class="count">({$category.count})</span>{/if}{if $category.subCategories}{fun name="categoryTree" node=$category.subCategories}{/if}</li>{/foreach}
	{/if}	
{/defun}

<div class="box categories">
	<div class="title">
		<div>{t _categories}</div>
	</div>

	<div class="content">
		{fun name="categoryTree" node=$categories}
	</div>
</div>