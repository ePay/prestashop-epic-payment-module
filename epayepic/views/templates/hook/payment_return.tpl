{if isset($payment)}
    <section class="box">
        <p>
            {l s='Your payment was registered with transaction ID:' mod='epayepic'}
            <strong>{$payment.transaction_id|escape:'html':'UTF-8'}</strong>
        </p>
    </section>
{/if}
