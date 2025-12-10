{if isset($payment)}
<div class="card mt-3">
    <div class="card-header">
        <h3 class="card-header-title">
            {l s='ePay Epic payment' mod='epayepic'}
        </h3>
    </div>
    <div class="card-body">
        {if isset($epayepic_error) && $epayepic_error}
            <div class="alert alert-danger">{$epayepic_error|escape:'html':'UTF-8'}</div>
        {/if}
        {if isset($epayepic_message) && $epayepic_message}
            <div class="alert alert-success">{$epayepic_message|escape:'html':'UTF-8'}</div>
        {/if}

        <dl class="row">
            <dt class="col-sm-4">{l s='Reference' mod='epayepic'}</dt>
            <dd class="col-sm-8">{$payment.reference|escape:'html':'UTF-8'}</dd>

            <dt class="col-sm-4">{l s='Transaction ID' mod='epayepic'}</dt>
            <dd class="col-sm-8">
                {if $payment.transaction_id}
                    {$payment.transaction_id|escape:'html':'UTF-8'}
                {else}
                    {l s='Pending' mod='epayepic'}
                {/if}
            </dd>

            <dt class="col-sm-4">{l s='State' mod='epayepic'}</dt>
            <dd class="col-sm-8">{$payment.state|escape:'html':'UTF-8'}</dd>
        </dl>

        {if isset($transactionDetails)}
            <hr />
            <div class="row">
                <div class="col-sm-6">
                    <p>
                        {l s='Authorized:' mod='epayepic'} {displayPrice price=$amounts.authorized currency=$currency->id}
                    </p>
                    <p>
                        {l s='Captured:' mod='epayepic'} {displayPrice price=$amounts.captured currency=$currency->id}
                    </p>
                    <p>
                        {l s='Refunded:' mod='epayepic'} {displayPrice price=$amounts.refunded currency=$currency->id}
                    </p>
                </div>
                <div class="col-sm-6">
                    {if $amounts.capturable > 0}
                        <form method="post" action="{$manage_url|escape:'html':'UTF-8'}" class="mb-2">
                            <input type="hidden" name="id_order" value="{$order_id|intval}">
                            <input type="hidden" name="return_url" value="{$return_url|escape:'html':'UTF-8'}">
                            <input type="hidden" name="epay_epic_action" value="capture">
                            <div class="form-group">
                                <label>{l s='Capture amount' mod='epayepic'}</label>
                                <input type="number" step="0.01" min="0.01" max="{$amounts.capturable|escape:'html':'UTF-8'}" name="amount" class="form-control" value="{$amounts.capturable|escape:'html':'UTF-8'}">
                            </div>
                            <button class="btn btn-primary" type="submit">
                                {l s='Capture' mod='epayepic'}
                            </button>
                        </form>
                    {/if}

                    {if $amounts.refundable > 0}
                        <form method="post" action="{$manage_url|escape:'html':'UTF-8'}" class="mb-2">
                            <input type="hidden" name="id_order" value="{$order_id|intval}">
                            <input type="hidden" name="return_url" value="{$return_url|escape:'html':'UTF-8'}">
                            <input type="hidden" name="epay_epic_action" value="refund">
                            <div class="form-group">
                                <label>{l s='Refund amount' mod='epayepic'}</label>
                                <input type="number" step="0.01" min="0.01" max="{$amounts.refundable|escape:'html':'UTF-8'}" name="amount" class="form-control" value="{$amounts.refundable|escape:'html':'UTF-8'}">
                            </div>
                            <button class="btn btn-warning" type="submit">
                                {l s='Refund' mod='epayepic'}
                            </button>
                        </form>
                    {/if}

                    {if $payment.state == 'pending'}
                        <form method="post" action="{$manage_url|escape:'html':'UTF-8'}">
                            <input type="hidden" name="id_order" value="{$order_id|intval}">
                            <input type="hidden" name="return_url" value="{$return_url|escape:'html':'UTF-8'}">
                            <input type="hidden" name="epay_epic_action" value="void">
                            <button class="btn btn-outline-danger" type="submit">
                                {l s='Void authorization' mod='epayepic'}
                            </button>
                        </form>
                    {/if}
                </div>
            </div>
        {/if}
    </div>
</div>
{/if}
