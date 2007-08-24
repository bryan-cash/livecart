{defun name="categoryNode" node=null}

	{if $node.ParentNode}
	
		{fun name="categoryNode" node=$node.ParentNode} 
		{if $node.ParentNode.ID > 1}&gt;{/if} 
		<a href="{categoryUrl data=$node}">{$node.name_lang}</a>
	
	{/if}

{/defun}

<div class="resultStats">{t Found categories}</div>
<ul class="foundCategories">
	{foreach from=$foundCategories item=category}
	
		<li>{fun name="categoryNode" node=$category}</li>
	
	{/foreach}
</ul>