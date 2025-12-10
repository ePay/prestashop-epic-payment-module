{extends file='page.tpl'}

{block name='page_content'}
    <section class="epayepic-error">
        <p>{$paymentError|escape:'html':'UTF-8'}</p>
        <a class="btn btn-primary" href="{$link->getPageLink('order', true, null, 'step=1')|escape:'html':'UTF-8'}">
            {l s='Return to checkout' mod='epayepic'}
        </a>
    </section>
{/block}
